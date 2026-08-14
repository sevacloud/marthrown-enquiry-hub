<?php
/**
 * REST: Event Enquiries (FluentCRM).
 *
 * FluentCRM is the source of record for enquiries. A website form inserts the
 * contact into the configured list ("Event Enquiries") and applies the
 * configured tag ("Event Enquiry"); this layer reads those contacts and lets
 * staff move them through a workflow status.
 *
 * Routes under `marthrown-enquiry-hub/v1`:
 *   GET  /enquiries              — paginated, filterable by status/date/search
 *   POST /enquiries/{id}/status  — set the workflow status
 *
 * Workflow status is a subscriber custom field, so it never conflicts with
 * FluentCRM's own subscribed/unsubscribed status.
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

	const NAMESPACE  = 'marthrown-enquiry-hub/v1';
	const STATUS_KEY = 'meh_enquiry_status';
	const STATUSES   = array( 'new', 'replied', 'quoted', 'converted', 'closed' );
	const FETCH_CAP  = 500;

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
					'status'   => array(
						'default'           => 'all',
						'sanitize_callback' => 'sanitize_key',
					),
					's'        => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'from'     => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'to'       => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 25,
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
	 * Whether FluentCRM is usable.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'FluentCrmApi' ) && class_exists( '\FluentCrm\App\Models\Subscriber' );
	}

	/**
	 * GET /enquiries handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_enquiries( $request ) {
		if ( ! self::available() ) {
			$response = new \WP_REST_Response(
				array(
					'items'     => array(),
					'counts'    => self::empty_counts(),
					'available' => false,
				),
				200
			);
			$response->header( 'X-WP-Total', 0 );
			return $response;
		}

		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$search   = strtolower( trim( (string) $request->get_param( 's' ) ) );
		$from     = (string) $request->get_param( 'from' );
		$to       = (string) $request->get_param( 'to' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$subscribers = self::query_subscribers();

		$rows   = array();
		$counts = self::empty_counts();

		foreach ( $subscribers as $subscriber ) {
			$row = self::format( $subscriber );

			++$counts['all'];
			$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;

			if ( ! self::passes( $row, $status, $search, $from, $to ) ) {
				continue;
			}
			$rows[] = $row;
		}

		$total  = count( $rows );
		$offset = ( $page - 1 ) * $per_page;
		$items  = array_slice( $rows, $offset, $per_page );

		$response = new \WP_REST_Response(
			array(
				'items'     => $items,
				'counts'    => $counts,
				'available' => true,
				'source'    => array(
					'list' => Settings::enquiry_list_id(),
					'tag'  => Settings::enquiry_tag_id(),
				),
			),
			200
		);
		$response->header( 'X-WP-Total', (int) $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );
		return $response;
	}

	/**
	 * Fetch subscribers in the configured Event Enquiries list / tag.
	 *
	 * If neither is configured we return nothing rather than the whole CRM.
	 *
	 * @return array
	 */
	protected static function query_subscribers() {
		$list_id = Settings::enquiry_list_id();
		$tag_id  = Settings::enquiry_tag_id();

		if ( ! $list_id && ! $tag_id ) {
			return array();
		}

		$query = \FluentCrm\App\Models\Subscriber::query();

		if ( $list_id ) {
			$query->whereHas(
				'lists',
				function ( $q ) use ( $list_id ) {
					$q->where( 'fc_lists.id', $list_id );
				}
			);
		}
		if ( $tag_id ) {
			$query->whereHas(
				'tags',
				function ( $q ) use ( $tag_id ) {
					$q->where( 'fc_tags.id', $tag_id );
				}
			);
		}

		return $query->orderBy( 'created_at', 'desc' )
			->limit( self::FETCH_CAP )
			->get();
	}

	/**
	 * Whether a formatted row passes the active filters.
	 *
	 * @param array  $row    Row.
	 * @param string $status Status filter.
	 * @param string $search Lower-cased search term.
	 * @param string $from   From date (Y-m-d).
	 * @param string $to     To date (Y-m-d).
	 * @return bool
	 */
	protected static function passes( $row, $status, $search, $from, $to ) {
		if ( $status && 'all' !== $status && $row['status'] !== $status ) {
			return false;
		}
		$day = substr( (string) $row['date'], 0, 10 );
		if ( $from && $day && $day < $from ) {
			return false;
		}
		if ( $to && $day && $day > $to ) {
			return false;
		}
		if ( $search && false === strpos( strtolower( $row['haystack'] ), $search ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Format a subscriber as an enquiry row.
	 *
	 * @param object $subscriber Subscriber model.
	 * @return array
	 */
	protected static function format( $subscriber ) {
		$id    = (int) $subscriber->id;
		$name  = trim( (string) $subscriber->first_name . ' ' . (string) $subscriber->last_name );
		$email = (string) $subscriber->email;
		$phone = (string) ( $subscriber->phone ?? '' );
		$note  = self::latest_note( $id );

		return array(
			'id'          => $id,
			'name'        => $name,
			'email'       => $email,
			'phone'       => $phone,
			'note'        => $note,
			'status'      => self::get_status( $id ),
			'crm_status'  => (string) $subscriber->status,
			'date'        => (string) $subscriber->created_at,
			'tags'        => self::tag_titles( $subscriber ),
			'crm_url'     => admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . $id ),
			'haystack'    => strtolower( $name . ' ' . $email . ' ' . $phone . ' ' . $note ),
		);
	}

	/**
	 * The most recent note/activity body for a subscriber.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return string
	 */
	protected static function latest_note( $subscriber_id ) {
		if ( ! class_exists( '\FluentCrm\App\Models\SubscriberNote' ) ) {
			return '';
		}

		$note = \FluentCrm\App\Models\SubscriberNote::where( 'subscriber_id', (int) $subscriber_id )
			->orderBy( 'id', 'desc' )
			->first();

		if ( ! $note ) {
			return '';
		}

		$body = trim( wp_strip_all_tags( (string) $note->description ) );
		return $body ? $body : trim( (string) $note->title );
	}

	/**
	 * Tag titles for a subscriber.
	 *
	 * @param object $subscriber Subscriber model.
	 * @return array
	 */
	protected static function tag_titles( $subscriber ) {
		$titles = array();
		if ( isset( $subscriber->tags ) ) {
			foreach ( $subscriber->tags as $tag ) {
				$titles[] = (string) $tag->title;
			}
		}
		return $titles;
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

		/**
		 * Fires after an enquiry's workflow status changes.
		 *
		 * @param int    $id     Subscriber ID.
		 * @param string $status New status.
		 */
		do_action( 'meh_enquiry_status_changed', $id, $status );

		return new \WP_REST_Response(
			array(
				'id'     => $id,
				'status' => $status,
			),
			200
		);
	}

	/**
	 * Read a subscriber's workflow status.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return string
	 */
	public static function get_status( $subscriber_id ) {
		if ( ! class_exists( '\FluentCrm\App\Models\SubscriberMeta' ) ) {
			return 'new';
		}
		$meta = \FluentCrm\App\Models\SubscriberMeta::where( 'subscriber_id', (int) $subscriber_id )
			->where( 'object_type', 'custom_field' )
			->where( 'key', self::STATUS_KEY )
			->first();

		$value = $meta ? (string) $meta->value : '';
		return in_array( $value, self::STATUSES, true ) ? $value : 'new';
	}

	/**
	 * Empty status counts scaffold.
	 *
	 * @return array
	 */
	protected static function empty_counts() {
		$counts = array( 'all' => 0 );
		foreach ( self::STATUSES as $status ) {
			$counts[ $status ] = 0;
		}
		return $counts;
	}
}
