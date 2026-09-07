<?php
/**
 * Enquiries CSV export.
 *
 * The bookings table has had this since the hub UI shipped
 * (`ExportBookings`); the enquiries table had no equivalent. Same shape: a
 * plain link the toolbar renders, streamed via admin-post.php so a large export
 * isn't buffered in the browser, nonce- and role-protected, honouring the
 * current list filters so what downloads is what is on screen.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ExportEnquiries
 */
class ExportEnquiries {

	const ACTION = 'meh_export_enquiries';
	const NONCE  = 'meh_export_enquiries';

	/**
	 * Register the admin-post handler (logged-in only).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Build the export URL for the given filters.
	 *
	 * Every filter the enquiry list accepts, so a client can hand back the exact
	 * object `getEnquiries()` was called with rather than a subset chosen here.
	 *
	 * @param array $filters status/s/from/to/date_from/date_to/hide_test.
	 * @return string
	 */
	public static function url( array $filters = array() ) {
		$args = array_merge(
			array(
				'action'   => self::ACTION,
				'_wpnonce' => wp_create_nonce( self::NONCE ),
			),
			array_filter(
				array(
					'status'    => isset( $filters['status'] ) ? $filters['status'] : '',
					's'         => isset( $filters['s'] ) ? $filters['s'] : '',
					'from'      => isset( $filters['from'] ) ? $filters['from'] : '',
					'to'        => isset( $filters['to'] ) ? $filters['to'] : '',
					'date_from' => isset( $filters['date_from'] ) ? $filters['date_from'] : '',
					'date_to'   => isset( $filters['date_to'] ) ? $filters['date_to'] : '',
					'hide_test' => ! empty( $filters['hide_test'] ) ? '1' : '',
				)
			)
		);

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Handle the export request: validate, then stream the CSV.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE ) ) {
			wp_die( esc_html__( 'Invalid or expired export link.', 'marthrown-enquiry-hub' ), 403 );
		}

		if ( ! Auth::current_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to export enquiries.', 'marthrown-enquiry-hub' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$args = array(
			'status'    => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all',
			's'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'from'      => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'        => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'hide_test' => ! empty( $_GET['hide_test'] ),
			'page'      => 1,
			'per_page'  => 100000,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = EnquiryStore::query( $args );
		$items  = $result['items'];

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=marthrown-enquiries-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				__( 'ID', 'marthrown-enquiry-hub' ),
				__( 'First name', 'marthrown-enquiry-hub' ),
				__( 'Last name', 'marthrown-enquiry-hub' ),
				__( 'Email', 'marthrown-enquiry-hub' ),
				__( 'Phone', 'marthrown-enquiry-hub' ),
				__( 'Candidate dates', 'marthrown-enquiry-hub' ),
				__( 'Status', 'marthrown-enquiry-hub' ),
				__( 'Source', 'marthrown-enquiry-hub' ),
				__( 'Received', 'marthrown-enquiry-hub' ),
			)
		);

		foreach ( $items as $enquiry ) {
			fputcsv(
				$out,
				array(
					$enquiry['id'],
					$enquiry['first_name'],
					$enquiry['last_name'],
					$enquiry['email'],
					$enquiry['phone'],
					implode( ', ', (array) $enquiry['selected_dates'] ),
					$enquiry['status'],
					$enquiry['source'],
					$enquiry['created_at'],
				)
			);
		}

		fclose( $out );
		exit;
	}
}
