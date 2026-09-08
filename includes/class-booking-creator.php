<?php
/**
 * Booking creation from an enquiry.
 *
 * The one path that turns a won enquiry into a real WP Booking System booking
 * (Requirement 14). It takes the target calendar and the date range the team
 * agreed, both chosen by a human, and does five things in a fixed order: inserts
 * the booking, blocks its days on that calendar, records the booking identifier
 * against the enquiry, appends a `booking_linked` history entry and transitions
 * the enquiry to `converted`.
 *
 * The booked range is not required to be one of the enquiry's candidate ranges.
 * The candidate ranges are what the enquirer offered, and the hub's own date
 * control offers them as the choices; but an agreement reached on the telephone
 * routinely lands on a range nobody typed into the form — a day either side for
 * setting up, a week moved to suit another booking — and refusing that would
 * leave the team creating the booking in WPBS by hand, outside the enquiry it
 * belongs to. So the dates are checked for being dates, and for ending no
 * earlier than they start, and the range that is booked is the range the history
 * entry records.
 *
 * Three decisions shape the class:
 *
 * - **Guards run before any side effect, in a fixed order.** WPBS available
 *   (503), enquiry exists (404), enquiry not closed (409), no existing
 *   `booking_id` (409), calendar known (400), dates parse and run forwards
 *   (400). Every one of them returns before `wpbs_insert_booking()` is reached,
 *   so a rejected request creates no booking, blocks no date and leaves the
 *   enquiry status exactly as it was (Requirements 14.8, 14.9, 14.10). The order
 *   matters: an enquiry that does not exist cannot be tested for a `booking_id`,
 *   and a calendar that does not exist has no dates worth parsing.
 * - **No WPBS form flow is touched.** The booking is inserted through
 *   `wpbs_insert_booking()` and the day blocked through `wpbs_insert_event()`
 *   against the calendar's `booked` legend item, the approach already proven in
 *   the conversion path and shared through `SourceWpbs`. Nothing here submits a
 *   WPBS form, so no confirmation email, payment request, price calculation or
 *   inventory adjustment is triggered (Requirement 14.11).
 * - **A created booking is never thrown away.** WPBS exposes no delete through
 *   the helpers used here, and a booking that exists is a fact whatever happens
 *   next. So once the insert succeeds, a failure to block the day or to
 *   transition the enquiry is reported as a warning alongside the booking
 *   identifier rather than as an error that would leave the caller believing no
 *   booking exists. The `booking_id` write and the history entry happen
 *   regardless, which is what keeps the enquiry pointing at the booking that was
 *   made.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BookingCreator
 */
class BookingCreator {

	/**
	 * The status a created booking holds.
	 *
	 * `accepted` and read: the team has already agreed the date with the guest,
	 * so the booking is confirmed rather than awaiting the WPBS pending flow.
	 */
	const BOOKING_STATUS = 'accepted';

	/**
	 * The status the converted enquiry moves to (Requirement 14.6).
	 */
	const TARGET_STATUS = 'converted';

	/**
	 * The status no enquiry may be converted from.
	 */
	const CLOSED_STATUS = 'closed';

	/**
	 * The history entry type a created booking appends (Requirement 14.7).
	 */
	const HISTORY_TYPE = 'booking_linked';

	/**
	 * The booking meta key holding the originating enquiry identifier.
	 */
	const ENQUIRY_META_KEY = 'meh_enquiry_id';

	/**
	 * Whether a booking can be created at all.
	 *
	 * `SourceWpbs::available()` answers for the read side; `wpbs_insert_booking()`
	 * is the one function this class cannot do without. The filter is the
	 * operational kill switch and the seam a test uses to present WPBS as
	 * inactive, since the answer otherwise depends on which plugins are loaded.
	 *
	 * @return bool
	 */
	public static function available() {
		$available = SourceWpbs::available() && function_exists( 'wpbs_insert_booking' );

		if ( ! function_exists( 'apply_filters' ) ) {
			return (bool) $available;
		}

		/**
		 * Filter whether WP Booking System is usable for booking creation.
		 *
		 * @param bool $available Defaults to WPBS being loaded and insertable.
		 */
		return (bool) apply_filters( 'meh_wpbs_available', $available );
	}

	/**
	 * Create a booking from one enquiry.
	 *
	 * @param int    $enquiry_id  Enquiry to convert.
	 * @param int    $calendar_id Target WPBS calendar.
	 * @param string $start       First day of the booking, `Y-m-d`.
	 * @param string $end         Last day of the booking, `Y-m-d`. Omitted or
	 *                            blank means a single-day booking on `$start`.
	 * @return array{booking_id:int,edit_url:string,calendar_id:int,start_date:string,end_date:string,blocked:int,warnings:string[]}|\WP_Error
	 */
	public static function create_from_enquiry( $enquiry_id, $calendar_id, $start, $end = null ) {
		$enquiry_id  = (int) $enquiry_id;
		$calendar_id = (int) $calendar_id;

		// Requirement 14.9: WPBS inactive, so nothing is created and the enquiry
		// status is left alone.
		if ( ! self::available() ) {
			return self::error( 'meh_booking_wpbs_unavailable', 'WP Booking System is not available.', 503 );
		}

		if ( $enquiry_id <= 0 ) {
			return self::error( 'meh_booking_invalid_id', 'An enquiry identifier is required.', 400 );
		}

		$enquiry = EnquiryStore::find( $enquiry_id );

		if ( null === $enquiry ) {
			return self::error( 'meh_booking_enquiry_not_found', 'The enquiry does not exist.', 404 );
		}

		if ( self::CLOSED_STATUS === (string) $enquiry['status'] ) {
			return self::error(
				'meh_booking_enquiry_closed',
				'A closed enquiry cannot be converted to a booking.',
				409,
				array( 'status_held' => self::CLOSED_STATUS )
			);
		}

		// Requirement 14.10: one enquiry, one booking. A second request is a
		// mistake rather than a request for a second booking.
		$existing = (int) $enquiry['booking_id'];

		if ( $existing > 0 ) {
			return self::error(
				'meh_booking_already_linked',
				sprintf( 'The enquiry is already linked to booking %d.', $existing ),
				409,
				array( 'booking_id' => $existing )
			);
		}

		// Requirement 14.8: the calendar has to be one WPBS knows.
		$calendars = SourceWpbs::calendar_names();

		if ( ! isset( $calendars[ $calendar_id ] ) ) {
			return self::error( 'meh_booking_unknown_calendar', 'The target calendar does not exist.', 400 );
		}

		// Requirement 14.1: the booking runs from the first day the team agreed
		// to the last. An omitted end is the single-day case written the short
		// way, not a missing value: the one date given is both bounds.
		$first = self::to_date( $start );
		$last  = ( null === $end || '' === trim( (string) $end ) ) ? $first : self::to_date( $end );

		if ( null === $first || null === $last ) {
			return self::error(
				'meh_booking_invalid_date',
				'The booking dates are not calendar dates.',
				400,
				array(
					'start_date' => is_scalar( $start ) ? (string) $start : '',
					'end_date'   => is_scalar( $end ) ? (string) $end : '',
				)
			);
		}

		// A range ending before it starts names no days at all, so there is
		// nothing to book and nothing to block. String comparison is exact at
		// `Y-m-d` and needs no date arithmetic.
		if ( $last < $first ) {
			return self::error(
				'meh_booking_invalid_range',
				'The booking end date falls before its start date.',
				400,
				array(
					'start_date' => $first,
					'end_date'   => $last,
				)
			);
		}

		$now = Clock::mysql();

		// Requirements 14.2, 14.3: the agreed range, guest name and email from
		// the enquiry, the name test-prefixed on the staging copy
		// (Requirement 17.3).
		$guest_name = StagingMarker::apply_name( self::guest_name( $enquiry ) );
		$booking_id = (int) wpbs_insert_booking(
			array(
				'calendar_id'   => $calendar_id,
				// No WPBS form is involved, so no form flow — email,
				// payment, pricing, inventory — can run (Requirement 14.11).
				'form_id'       => 0,
				'start_date'    => $first,
				'end_date'      => $last,
				// Array is JSON-encoded by the WPBS DB layer.
				'fields'        => self::booking_fields( $guest_name, (string) $enquiry['email'] ),
				'status'        => self::BOOKING_STATUS,
				'is_read'       => 1,
				'date_created'  => $now,
				'date_modified' => $now,
				'invoice_hash'  => function_exists( 'wpbs_generate_hash' ) ? wpbs_generate_hash() : wp_generate_uuid4(),
			)
		);

		if ( $booking_id <= 0 ) {
			Log::write(
				'booking: insert failed',
				array(
					'enquiry_id'  => $enquiry_id,
					'calendar_id' => $calendar_id,
					'start_date'  => $first,
					'end_date'    => $last,
				)
			);

			return self::error( 'meh_booking_insert_failed', 'The booking could not be created.', 500 );
		}

		if ( function_exists( 'wpbs_add_booking_meta' ) ) {
			wpbs_add_booking_meta( $booking_id, self::ENQUIRY_META_KEY, $enquiry_id );
		}

		$warnings = array();

		// Requirement 14.4: block every day of the range with the calendar's own
		// `booked` legend item. A calendar with no such item cannot be blocked,
		// which is a configuration fault worth reporting, not a reason to discard
		// a booking that already exists.
		$blocked = SourceWpbs::block_dates( $calendar_id, $booking_id, $first, $last );

		if ( $blocked < 1 ) {
			$warnings[] = 'meh_booking_date_not_blocked';

			Log::write(
				'booking: date not blocked',
				array(
					'enquiry_id'  => $enquiry_id,
					'booking_id'  => $booking_id,
					'calendar_id' => $calendar_id,
					'start_date'  => $first,
					'end_date'    => $last,
				)
			);
		}

		if ( class_exists( __NAMESPACE__ . '\CalendarReader' ) ) {
			CalendarReader::clear_cache( $first, $last );
		}

		// Requirement 14.5.
		$recorded = EnquiryStore::update_fields( $enquiry_id, array( 'booking_id' => $booking_id ) );

		if ( is_wp_error( $recorded ) ) {
			$warnings[] = 'meh_booking_id_not_recorded';

			Log::write(
				'booking: booking id not recorded',
				array(
					'enquiry_id' => $enquiry_id,
					'booking_id' => $booking_id,
					'error'      => $recorded->get_error_message(),
				)
			);
		}

		// Requirement 14.7.
		HistoryRecorder::record(
			$enquiry_id,
			self::HISTORY_TYPE,
			sprintf(
				'Booking %d created on %s for %s.',
				$booking_id,
				$calendars[ $calendar_id ],
				self::span( $first, $last )
			),
			array(
				'booking_id'  => $booking_id,
				'calendar_id' => $calendar_id,
				'calendar'    => (string) $calendars[ $calendar_id ],
				'start_date'  => $first,
				'end_date'    => $last,
			)
		);

		// Requirement 14.6. The Lifecycle owns the transition table, so an
		// enquiry that has already settled as `lost` is refused here rather than
		// being moved by a second opinion. The booking stands and the refusal is
		// reported, because the booking was made whatever the enquiry says.
		$transition = Lifecycle::transition( $enquiry_id, self::TARGET_STATUS );

		if ( is_wp_error( $transition ) ) {
			$warnings[] = 'meh_booking_status_not_changed';

			Log::write(
				'booking: status not changed',
				array(
					'enquiry_id' => $enquiry_id,
					'booking_id' => $booking_id,
					'from'       => (string) $enquiry['status'],
					'error'      => $transition->get_error_message(),
				)
			);
		}

		return array(
			'booking_id'  => $booking_id,
			'edit_url'    => self::edit_url( $calendar_id, $booking_id ),
			'calendar_id' => $calendar_id,
			'start_date'  => $first,
			'end_date'    => $last,
			'blocked'     => (int) $blocked,
			'warnings'    => $warnings,
		);
	}

	/**
	 * A booked range as one readable phrase.
	 *
	 * A single-day booking reads as the day rather than as the same date written
	 * twice, which is how the history entry for one ends up saying what happened
	 * instead of stating it as a range of no length.
	 *
	 * @param string $first First day, `Y-m-d`.
	 * @param string $last  Last day, `Y-m-d`.
	 * @return string
	 */
	protected static function span( $first, $last ) {
		if ( $first === $last ) {
			return (string) $first;
		}

		return sprintf( '%s to %s', $first, $last );
	}

	/**
	 * The guest name a booking carries (Requirement 14.3).
	 *
	 * @param array $enquiry Hydrated enquiry.
	 * @return string
	 */
	protected static function guest_name( array $enquiry ) {
		$first = isset( $enquiry['first_name'] ) ? trim( (string) $enquiry['first_name'] ) : '';
		$last  = isset( $enquiry['last_name'] ) ? trim( (string) $enquiry['last_name'] ) : '';

		return trim( $first . ' ' . $last );
	}

	/**
	 * The booking field set: guest name and guest email, nothing else.
	 *
	 * The shape matches what `SourceWpbs::normalize()` reads back, so a booking
	 * created here shows the guest in the bookings list exactly as one created
	 * through a WPBS form does. Enquiry workflow values — status, notes, history
	 * — are deliberately absent: the enquiry remains the record of those.
	 *
	 * @param string $guest_name Guest name, already test-prefixed where relevant.
	 * @param string $email      Guest email.
	 * @return array<int,array<string,string>>
	 */
	protected static function booking_fields( $guest_name, $email ) {
		return array(
			array(
				'id'         => 'meh-guest-name',
				'type'       => 'text',
				'label'      => 'Name',
				'user_value' => (string) $guest_name,
			),
			array(
				'id'         => 'meh-guest-email',
				'type'       => 'email',
				'label'      => 'Email',
				'user_value' => (string) $email,
			),
		);
	}

	/**
	 * The WPBS admin URL of one booking.
	 *
	 * @param int $calendar_id Calendar the booking sits on.
	 * @param int $booking_id  Booking identifier.
	 * @return string
	 */
	protected static function edit_url( $calendar_id, $booking_id ) {
		if ( ! function_exists( 'add_query_arg' ) || ! function_exists( 'admin_url' ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'page'        => 'wpbs-calendars',
				'subpage'     => 'edit-calendar',
				'calendar_id' => (int) $calendar_id,
				'booking_id'  => (int) $booking_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Read a submitted value as a calendar date at day precision.
	 *
	 * A leading `Y-m-d` covers both a plain date and a full `DATETIME`, and
	 * `checkdate()` rejects the impossible ones — `2025-02-30` — that a general
	 * parser would otherwise roll forward into the following month. Anything
	 * else is parsed and formatted without converting between timezones, so the
	 * day cannot shift.
	 *
	 * @param mixed $value Submitted value.
	 * @return string|null `Y-m-d`, or null when it is not a date.
	 */
	protected static function to_date( $value ) {
		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'Y-m-d' );
		}

		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $parts ) ) {
			$year  = (int) $parts[1];
			$month = (int) $parts[2];
			$day   = (int) $parts[3];

			if ( ! checkdate( $month, $day, $year ) ) {
				return null;
			}

			return sprintf( '%04d-%02d-%02d', $year, $month, $day );
		}

		try {
			$parsed = new \DateTimeImmutable( $value );
		} catch ( \Exception $e ) {
			return null;
		}

		return $parsed->format( 'Y-m-d' );
	}

	/**
	 * Build a failure carrying an HTTP status, matching the rest of the plugin.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @param array  $detail  Extra error data.
	 * @return \WP_Error
	 */
	protected static function error( $code, $message, $status, array $detail = array() ) {
		return new \WP_Error( $code, $message, array_merge( $detail, array( 'status' => (int) $status ) ) );
	}
}
