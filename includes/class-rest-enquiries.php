<?php
/**
 * REST: Enquiries.
 *
 * Routes under `marthrown-enquiry-hub/v1`:
 *   GET  /enquiries                 — paginated, filterable by source/status/date
 *   POST /enquiries/{id}/status     — set the enquiry status custom field
 *
 * Enquiries are FluentCRM subscribers carrying a `source-*` tag. Status is a
 * subscriber custom field written via SubscriberMeta.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestEnquiries
 */
class RestEnquiries {

	const NAMESPACE   = 'marthrown-enquiry-hub/v1';
	const STATUS_KEY  = 'meh_enquiry_status';
	const STATUSES    = array( 'new', 'replied', 'resolved' );

	/**
	 * Register routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/enquiries',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_enquiries' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => array(
					'source'   => array( 'sanitize_callback' => 'sanitize_key' ),
					'status'   => array( 'sanitize_callback' => 'sanitize_key' ),
					'from'     => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'to'       => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/enquiries/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_status' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => array(
					'id'     => array( 'sanitize_callback' => 'absint' ),
					'status' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $value ) {
							return in_array( $value, self::STATUSES, true );
						},
					),
				),
			)
		);
	}

	/**
	 * GET /enquiries handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_enquiries( $request ) {
		if ( ! function_exists( 'FluentCrmApi' ) || ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
			return new \WP_Error( 'meh_no_fluentcrm', __( 'FluentCRM is not available.', 'marthrown-enquiry-hub' ), array( 'status' => 503 ) );
		}

		$source   = $request->get_param( 'source' );
		$status   = $request->get_param( 'status' );
		$from     = $request->get_param( 'from' );
		$to       = $request->get_param( 'to' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$tag_ids = self::source_tag_ids( $source );
		if ( empty( $tag_ids ) ) {
			return self::collection( array(), 0, $page, $per_page );
		}

		$query = \FluentCrm\App\Models\Subscriber::query()
			->whereHas(
				'tags',
				function ( $q ) use ( $tag_ids ) {
					$q->whereIn( 'fc_tags.id', $tag_ids );
				}
			);

		if ( $from ) {
			$query->where( 'created_at', '>=', $from . ' 00:00:00' );
		}
		if ( $to ) {
			$query->where( 'created_at', '<=', $to . ' 23:59:59' );
		}

		$total       = ( clone $query )->count();
		$subscribers = $query->orderBy( 'created_at', 'desc' )
			->offset( ( $page - 1 ) * $per_page )
			->limit( $per_page )
			->get();

		$items = array();
		foreach ( $subscribers as $subscriber ) {
			$row = self::format_subscriber( $subscriber );

			// Status filter is applied post-hoc (status lives in meta).
			if ( $status && $row['status'] !== $status ) {
				continue;
			}
			$items[] = $row;
		}

		return self::collection( $items, $total, $page, $per_page );
	}

	/**
	 * POST /enquiries/{id}/status handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_status( $request ) {
		if ( ! class_exists( '\FluentCrm\App\Models\SubscriberMeta' ) ) {
			return new \WP_Error( 'meh_no_fluentcrm', __( 'FluentCRM is not available.', 'marthrown-enquiry-hub' ), array( 'status' => 503 ) );
		}

		$id     = absint( $request->get_param( 'id' ) );
		$status = sanitize_key( $request->get_param( 'status' ) );

		\FluentCrm\App\Models\SubscriberMeta::updateOrCreate(
			array(
				'subscriber_id' => $id,
				'object_type'   => 'custom_field',
				'key'           => self::STATUS_KEY,
			),
			array(
				'value' => $status,
			)
		);

		return new \WP_REST_Response(
			array(
				'id'     => $id,
				'status' => $status,
			),
			200
		);
	}

	/**
	 * Format a subscriber into an enquiry row.
	 *
	 * @param object $subscriber Subscriber model.
	 * @return array
	 */
	protected static function format_subscriber( $subscriber ) {
		$note = class_exists( __NAMESPACE__ . '\\FluentCrmWriter' )
			? FluentCrmWriter::get_latest_note( $subscriber->id )
			: null;

		return array(
			'id'      => (int) $subscriber->id,
			'name'    => trim( $subscriber->first_name . ' ' . $subscriber->last_name ),
			'email'   => $subscriber->email,
			'sources' => self::subscriber_sources( $subscriber ),
			'note'    => $note && ! empty( $note['message'] ) ? $note['message'] : '',
			'status'  => self::get_status( $subscriber->id ),
			'date'    => $subscriber->created_at,
		);
	}

	/**
	 * Read a subscriber's enquiry status meta.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return string
	 */
	protected static function get_status( $subscriber_id ) {
		if ( ! class_exists( '\FluentCrm\App\Models\SubscriberMeta' ) ) {
			return 'new';
		}
		$meta = \FluentCrm\App\Models\SubscriberMeta::where( 'subscriber_id', (int) $subscriber_id )
			->where( 'object_type', 'custom_field' )
			->where( 'key', self::STATUS_KEY )
			->first();

		return ( $meta && $meta->value ) ? (string) $meta->value : 'new';
	}

	/**
	 * Resolve source tag ids, optionally restricted to a single source.
	 *
	 * @param string $source Optional source slug (without prefix).
	 * @return int[]
	 */
	protected static function source_tag_ids( $source ) {
		if ( ! class_exists( '\FluentCrm\App\Models\Tag' ) ) {
			return array();
		}
		$tags    = \FluentCrm\App\Models\Tag::where( 'slug', 'like', 'source-%' )->get();
		$tag_ids = array();
		foreach ( $tags as $tag ) {
			$slug = preg_replace( '/^source-/', '', $tag->slug );
			if ( $source && $slug !== $source ) {
				continue;
			}
			$tag_ids[] = $tag->id;
		}
		return $tag_ids;
	}

	/**
	 * List a subscriber's source slugs.
	 *
	 * @param object $subscriber Subscriber model.
	 * @return string[]
	 */
	protected static function subscriber_sources( $subscriber ) {
		$sources = array();
		if ( isset( $subscriber->tags ) ) {
			foreach ( $subscriber->tags as $tag ) {
				if ( 0 === strpos( $tag->slug, 'source-' ) ) {
					$sources[] = preg_replace( '/^source-/', '', $tag->slug );
				}
			}
		}
		return $sources;
	}

	/**
	 * Build a paginated collection response with headers.
	 *
	 * @param array $items    Items.
	 * @param int   $total    Total matched.
	 * @param int   $page     Page.
	 * @param int   $per_page Page size.
	 * @return \WP_REST_Response
	 */
	protected static function collection( $items, $total, $page, $per_page ) {
		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (int) $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / max( 1, $per_page ) ) );
		return $response;
	}
}
