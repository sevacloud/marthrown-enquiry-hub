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

		fputcsv( $out, self::columns() );

		foreach ( $items as $enquiry ) {
			fputcsv( $out, self::row( $enquiry ) );
		}

		fclose( $out );
		exit;
	}

	/**
	 * The header row.
	 *
	 * Every field the enquiry record holds bar the payload: an export that left
	 * some of them out was an export someone had to go back to the hub for.
	 * `Message` sits last because it is the one field long enough to be worth
	 * scrolling past rather than through.
	 *
	 * @return string[]
	 */
	public static function columns() {
		return array(
			__( 'ID', 'marthrown-enquiry-hub' ),
			__( 'First name', 'marthrown-enquiry-hub' ),
			__( 'Last name', 'marthrown-enquiry-hub' ),
			__( 'Email', 'marthrown-enquiry-hub' ),
			__( 'Phone', 'marthrown-enquiry-hub' ),
			__( 'Total guests', 'marthrown-enquiry-hub' ),
			__( 'Event type', 'marthrown-enquiry-hub' ),
			__( 'Site exclusivity', 'marthrown-enquiry-hub' ),
			__( 'Candidate date ranges', 'marthrown-enquiry-hub' ),
			__( 'Status', 'marthrown-enquiry-hub' ),
			__( 'Source', 'marthrown-enquiry-hub' ),
			__( 'Received', 'marthrown-enquiry-hub' ),
			__( 'Message', 'marthrown-enquiry-hub' ),
		);
	}

	/**
	 * One enquiry as a row, in the order columns() names.
	 *
	 * An unsupplied `total_guests` is the empty cell rather than `0`: the store
	 * holds it as null precisely because no number was given, and a zero would
	 * read as a party of none. The term sets and the candidate date ranges are
	 * joined with a comma, which `fputcsv()` quotes for us.
	 *
	 * @param array $enquiry Enquiry as the store hydrates it.
	 * @return array
	 */
	public static function row( array $enquiry ) {
		return array(
			self::cell( $enquiry, 'id' ),
			self::cell( $enquiry, 'first_name' ),
			self::cell( $enquiry, 'last_name' ),
			self::cell( $enquiry, 'email' ),
			self::cell( $enquiry, 'phone' ),
			isset( $enquiry['total_guests'] ) ? $enquiry['total_guests'] : '',
			self::joined( $enquiry, 'event_type' ),
			self::joined( $enquiry, 'site_exclusivity' ),
			self::ranges( $enquiry ),
			self::cell( $enquiry, 'status' ),
			self::cell( $enquiry, 'source' ),
			self::cell( $enquiry, 'created_at' ),
			self::cell( $enquiry, 'message' ),
		);
	}

	/**
	 * One scalar field, or the empty cell where the row does not carry it.
	 *
	 * @param array  $enquiry Enquiry as the store hydrates it.
	 * @param string $field   Field name.
	 * @return string
	 */
	protected static function cell( array $enquiry, $field ) {
		return isset( $enquiry[ $field ] ) && is_scalar( $enquiry[ $field ] )
			? (string) $enquiry[ $field ]
			: '';
	}

	/**
	 * One list field as a comma-separated cell.
	 *
	 * @param array  $enquiry Enquiry as the store hydrates it.
	 * @param string $field   Field name.
	 * @return string
	 */
	protected static function joined( array $enquiry, $field ) {
		if ( ! isset( $enquiry[ $field ] ) || ! is_array( $enquiry[ $field ] ) ) {
			return '';
		}

		return implode( ', ', $enquiry[ $field ] );
	}

	/**
	 * The candidate date ranges as one cell, the ideal range first.
	 *
	 * Rank order is preserved rather than sorted chronologically, because the
	 * first range is the one the enquirer would rather have and that is the fact
	 * the reader of a spreadsheet is looking for. A range covering one day is
	 * written as that day alone, so a single-date enquiry does not read as
	 * `2026-05-01 to 2026-05-01`. The bound separator is the word `to` rather
	 * than a dash, which spreadsheets are apt to read as arithmetic.
	 *
	 * @param array $enquiry Enquiry as the store hydrates it.
	 * @return string
	 */
	protected static function ranges( array $enquiry ) {
		if ( ! isset( $enquiry['date_ranges'] ) || ! is_array( $enquiry['date_ranges'] ) ) {
			return '';
		}

		$cells = array();

		foreach ( $enquiry['date_ranges'] as $range ) {
			if ( ! is_array( $range ) || ! isset( $range['start'], $range['end'] ) ) {
				continue;
			}

			$start = (string) $range['start'];
			$end   = (string) $range['end'];

			$cells[] = $start === $end
				? $start
				/* translators: 1: range start date, 2: range end date. */
				: sprintf( __( '%1$s to %2$s', 'marthrown-enquiry-hub' ), $start, $end );
		}

		return implode( ', ', $cells );
	}
}
