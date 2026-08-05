<?php
/**
 * REST: Bookings.
 *
 * Mirrors the WP Booking System "Booking Manager" list view.
 *
 *   GET /marthrown-enquiry-hub/v1/bookings
 *       ?status=all|pending|accepted|trash & s= & from= & to= & hide_past=
 *       & orderby= & order= & page= & per_page=
 *
 * Bookings are read live from WPBS (see SourceWpbs). Response includes status
 * counts (for the All/Pending/Accepted/Trash tabs) and pagination headers.
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
					'status'    => array(
						'default'           => 'all',
						'sanitize_callback' => 'sanitize_key',
					),
					's'         => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'from'      => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'to'        => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'hide_past' => array( 'sanitize_callback' => 'rest_sanitize_boolean' ),
					'orderby'   => array( 'sanitize_callback' => 'sanitize_key' ),
					'order'     => array( 'sanitize_callback' => 'sanitize_key' ),
					'page'      => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'  => array(
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
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
		if ( ! SourceWpbs::available() ) {
			$response = new \WP_REST_Response(
				array(
					'items'     => array(),
					'counts'    => array(),
					'available' => false,
				),
				200
			);
			$response->header( 'X-WP-Total', 0 );
			return $response;
		}

		$result = SourceWpbs::get_bookings(
			array(
				'status'    => $request->get_param( 'status' ),
				'search'    => (string) $request->get_param( 's' ),
				'from'      => (string) $request->get_param( 'from' ),
				'to'        => (string) $request->get_param( 'to' ),
				'hide_past' => (bool) $request->get_param( 'hide_past' ),
				'orderby'   => (string) $request->get_param( 'orderby' ),
				'order'     => (string) $request->get_param( 'order' ),
				'page'      => (int) $request->get_param( 'page' ),
				'per_page'  => (int) $request->get_param( 'per_page' ),
			)
		);

		$per_page = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$response = new \WP_REST_Response(
			array(
				'items'     => $result['items'],
				'counts'    => $result['counts'],
				'available' => true,
			),
			200
		);
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / $per_page ) );
		return $response;
	}
}
