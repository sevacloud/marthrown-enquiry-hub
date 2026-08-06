<?php
/**
 * Bookings CSV export.
 *
 * Streams a CSV of bookings (respecting the current list filters) via
 * admin-post.php so large exports don't have to be buffered in the browser.
 * Nonce- and role-protected.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ExportBookings
 */
class ExportBookings {

	const ACTION = 'meh_export_bookings';
	const NONCE  = 'meh_export_bookings';

	/**
	 * Register the admin-post handlers (logged-in only).
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Build the export URL for the given filters.
	 *
	 * @param array $filters status/s/from/to/hide_past.
	 * @return string
	 */
	public static function url( array $filters = array() ) {
		$args = array_merge(
			array(
				'action'    => self::ACTION,
				'_wpnonce'  => wp_create_nonce( self::NONCE ),
			),
			array_filter(
				array(
					'status'    => isset( $filters['status'] ) ? $filters['status'] : '',
					'period'    => isset( $filters['period'] ) ? $filters['period'] : '',
					's'         => isset( $filters['s'] ) ? $filters['s'] : '',
					'from'      => isset( $filters['from'] ) ? $filters['from'] : '',
					'to'        => isset( $filters['to'] ) ? $filters['to'] : '',
					'hide_past' => ! empty( $filters['hide_past'] ) ? '1' : '',
				)
			)
		);
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Handle the export request: validate, then stream the CSV.
	 */
	public static function handle() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE ) ) {
			wp_die( esc_html__( 'Invalid or expired export link.', 'marthrown-enquiry-hub' ), 403 );
		}
		if ( ! Auth::current_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to export bookings.', 'marthrown-enquiry-hub' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$args = array(
			'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all',
			'period'    => isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : 'all',
			'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'from'      => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'        => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'hide_past' => ! empty( $_GET['hide_past'] ),
			'page'      => 1,
			'per_page'  => 100000,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = SourceWpbs::get_bookings( $args );
		$items  = $result['items'];

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=marthrown-bookings-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				__( 'ID', 'marthrown-enquiry-hub' ),
				__( 'Calendar', 'marthrown-enquiry-hub' ),
				__( 'Guest', 'marthrown-enquiry-hub' ),
				__( 'Email', 'marthrown-enquiry-hub' ),
				__( 'Start date', 'marthrown-enquiry-hub' ),
				__( 'End date', 'marthrown-enquiry-hub' ),
				__( 'Stay length', 'marthrown-enquiry-hub' ),
				__( 'Status', 'marthrown-enquiry-hub' ),
				__( 'Date created', 'marthrown-enquiry-hub' ),
			)
		);

		foreach ( $items as $b ) {
			fputcsv(
				$out,
				array(
					$b['id'],
					$b['calendar'],
					$b['guest'],
					$b['email'],
					$b['start_date'],
					$b['end_date'],
					$b['stay_length'],
					$b['status'],
					$b['date_created'],
				)
			);
		}

		fclose( $out );
		exit;
	}
}
