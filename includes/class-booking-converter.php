<?php
/**
 * Booking converter.
 *
 * Turns an enquiry booking (on the Event Enquiry calendar) into a real booking
 * on a target calendar: copies dates + form fields via wpbs_insert_booking and
 * blocks the dates via the target calendar's "booked" legend using
 * wpbs_insert_event.
 *
 * WPBS form-flow side effects (emails, payments, pricing, inventory) are
 * intentionally NOT triggered.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BookingConverter
 */
class BookingConverter {

	/**
	 * Convert a source (enquiry) booking into a real booking.
	 *
	 * @param int    $source_id          Source booking id.
	 * @param int    $target_calendar_id Target calendar id.
	 * @param string $status             pending|accepted|trash. Default accepted.
	 * @return array|\WP_Error { id, edit_url }
	 */
	public static function convert( $source_id, $target_calendar_id, $status = 'accepted' ) {
		if ( ! SourceWpbs::available() || ! function_exists( 'wpbs_insert_booking' ) || ! function_exists( 'wpbs_get_booking' ) ) {
			return new \WP_Error( 'meh_unavailable', __( 'WP Booking System is not available.', 'marthrown-enquiry-hub' ), array( 'status' => 503 ) );
		}

		$source = wpbs_get_booking( (int) $source_id );
		if ( ! $source ) {
			return new \WP_Error( 'meh_not_found', __( 'Source booking not found.', 'marthrown-enquiry-hub' ), array( 'status' => 404 ) );
		}

		$target = (int) $target_calendar_id;
		$names  = SourceWpbs::calendar_names();
		if ( ! isset( $names[ $target ] ) ) {
			return new \WP_Error( 'meh_bad_target', __( 'Invalid target calendar.', 'marthrown-enquiry-hub' ), array( 'status' => 400 ) );
		}

		$status = in_array( $status, SourceWpbs::STATUSES, true ) ? $status : 'accepted';
		$start  = (string) $source->get( 'start_date' );
		$end    = (string) $source->get( 'end_date' );
		$now    = current_time( 'Y-m-d H:i:s' );

		$new_id = wpbs_insert_booking(
			array(
				'calendar_id'   => $target,
				'form_id'       => (int) $source->get( 'form_id' ),
				'start_date'    => $start,
				'end_date'      => $end,
				// Array is JSON-encoded by the WPBS DB layer.
				'fields'        => $source->get( 'fields' ),
				'status'        => $status,
				'is_read'       => 1,
				'date_created'  => $now,
				'date_modified' => $now,
				'invoice_hash'  => function_exists( 'wpbs_generate_hash' ) ? wpbs_generate_hash() : wp_generate_uuid4(),
			)
		);

		if ( ! $new_id ) {
			return new \WP_Error( 'meh_insert_failed', __( 'Could not create the booking.', 'marthrown-enquiry-hub' ), array( 'status' => 500 ) );
		}

		if ( function_exists( 'wpbs_add_booking_meta' ) ) {
			wpbs_add_booking_meta( $new_id, 'meh_converted_from', (int) $source_id );
			wpbs_add_booking_meta( (int) $source_id, 'meh_converted_to', (int) $new_id );
		}

		self::block_dates( $target, (int) $new_id, $start, $end );

		if ( class_exists( __NAMESPACE__ . '\\CalendarReader' ) ) {
			CalendarReader::clear_cache( $start, $end );
		}

		/**
		 * Fires after an enquiry booking is converted to a real booking.
		 *
		 * @param int $new_id    New booking id.
		 * @param int $source_id Source enquiry booking id.
		 * @param int $target    Target calendar id.
		 */
		do_action( 'meh_booking_converted', (int) $new_id, (int) $source_id, $target );

		return array(
			'id'       => (int) $new_id,
			'edit_url' => add_query_arg(
				array(
					'page'        => 'wpbs-calendars',
					'subpage'     => 'edit-calendar',
					'calendar_id' => $target,
					'booking_id'  => (int) $new_id,
				),
				admin_url( 'admin.php' )
			),
		);
	}

	/**
	 * Insert availability events for a booking's date range using the target
	 * calendar's "booked" legend item.
	 *
	 * @param int    $calendar_id Calendar id.
	 * @param int    $booking_id  Booking id.
	 * @param string $start       Start datetime.
	 * @param string $end         End datetime.
	 */
	protected static function block_dates( $calendar_id, $booking_id, $start, $end ) {
		if ( ! function_exists( 'wpbs_insert_event' ) ) {
			return;
		}
		$legend_id = self::booked_legend_item( $calendar_id );
		if ( ! $legend_id ) {
			return;
		}
		$s = strtotime( gmdate( 'Y-m-d', strtotime( $start ) ) );
		$e = strtotime( gmdate( 'Y-m-d', strtotime( $end ) ) );
		if ( ! $s || ! $e || $e < $s ) {
			return;
		}
		for ( $t = $s; $t <= $e; $t += DAY_IN_SECONDS ) {
			wpbs_insert_event(
				array(
					'date_year'      => (int) gmdate( 'Y', $t ),
					'date_month'     => (int) gmdate( 'n', $t ),
					'date_day'       => (int) gmdate( 'j', $t ),
					'calendar_id'    => (int) $calendar_id,
					'booking_id'     => (int) $booking_id,
					'legend_item_id' => (int) $legend_id,
				)
			);
		}
	}

	/**
	 * Find the calendar's "booked" (full-day block) legend item id.
	 *
	 * @param int $calendar_id Calendar id.
	 * @return int 0 when none.
	 */
	protected static function booked_legend_item( $calendar_id ) {
		foreach ( SourceWpbs::legend_items_map( $calendar_id ) as $id => $li ) {
			if ( 'booked' === $li['auto_pending'] ) {
				return (int) $id;
			}
		}
		return 0;
	}
}
