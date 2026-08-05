<?php
/**
 * REST: Bookings.
 *
 * Routes under `marthrown-enquiry-hub/v1`:
 *   GET  /bookings?bucket=new|upcoming|current|past  — paginated
 *   POST /bookings/{id}/acknowledge                  — mark a booking acknowledged
 *
 * Bookings are read directly from WPBS at request time (see SourceWpbs); the
 * acknowledgment state lives in this plugin's own table.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestBookings
 */
class RestBookings {

	const NAMESPACE = 'marthrown-enquiry-hub/v1';
	const BUCKETS   = array( 'new', 'upcoming', 'current', 'past' );

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
			'/bookings',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_bookings' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => array(
					'bucket'   => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $value ) {
							return in_array( $value, self::BUCKETS, true );
						},
					),
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
			'/bookings/(?P<id>\d+)/acknowledge',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'acknowledge' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => array(
					'id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/**
	 * GET /bookings handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_bookings( $request ) {
		$bucket   = $request->get_param( 'bucket' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$result = SourceWpbs::get_bookings( $bucket, $page, $per_page );

		$items = array_map(
			function ( $b ) use ( $bucket ) {
				$b['bucket'] = $bucket;
				$b['label']  = self::compute_label( $b, $bucket );
				return $b;
			},
			$result['items']
		);

		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * POST /bookings/{id}/acknowledge handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function acknowledge( $request ) {
		$id = absint( $request->get_param( 'id' ) );
		SourceWpbs::acknowledge( $id, get_current_user_id() );

		return new \WP_REST_Response(
			array(
				'id'           => $id,
				'acknowledged' => true,
			),
			200
		);
	}

	/**
	 * Compute a human label appropriate to the bucket.
	 *
	 * @param array  $booking Booking.
	 * @param string $bucket  Bucket.
	 * @return string
	 */
	protected static function compute_label( $booking, $bucket ) {
		$today = current_time( 'Y-m-d' );

		if ( 'past' === $bucket && ! empty( $booking['check_out'] ) ) {
			$days = self::day_diff( $booking['check_out'], $today );
			return sprintf(
				/* translators: %d: number of days */
				_n( 'checked out %d day ago', 'checked out %d days ago', $days, 'marthrown-enquiry-hub' ),
				$days
			);
		}

		if ( 'current' === $bucket && ! empty( $booking['check_out'] ) ) {
			$nights = max( 0, self::day_diff( $today, $booking['check_out'] ) );
			return sprintf(
				/* translators: %d: number of nights */
				_n( '%d night remaining', '%d nights remaining', $nights, 'marthrown-enquiry-hub' ),
				$nights
			);
		}

		if ( in_array( $bucket, array( 'new', 'upcoming' ), true ) && ! empty( $booking['check_in'] ) ) {
			$days = self::day_diff( $today, $booking['check_in'] );
			if ( $days < 0 ) {
				return sprintf(
					/* translators: %d: number of days */
					_n( 'check-in was %d day ago', 'check-in was %d days ago', abs( $days ), 'marthrown-enquiry-hub' ),
					abs( $days )
				);
			}
			return sprintf(
				/* translators: %d: number of days */
				_n( 'checks in in %d day', 'checks in in %d days', $days, 'marthrown-enquiry-hub' ),
				$days
			);
		}

		return '';
	}

	/**
	 * Whole-day difference between two Y-m-d dates ($to - $from).
	 *
	 * @param string $from From date.
	 * @param string $to   To date.
	 * @return int
	 */
	protected static function day_diff( $from, $to ) {
		$f = strtotime( $from . ' 00:00:00' );
		$t = strtotime( $to . ' 00:00:00' );
		if ( ! $f || ! $t ) {
			return 0;
		}
		return (int) round( ( $t - $f ) / DAY_IN_SECONDS );
	}
}
