<?php
/**
 * Source: WP Booking System (read layer).
 *
 * Reads bookings live via WPBS's own API (wpbs_get_bookings), mirroring the
 * WP Booking System "Booking Manager" list view: native statuses
 * (pending/accepted/trash), start/end dates, stay length, calendar name.
 *
 * Bookings are never copied into FluentCRM. Nothing here writes to WPBS tables.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SourceWpbs
 */
class SourceWpbs {

	const STATUSES  = array( 'pending', 'accepted', 'trash' );
	const FETCH_CAP = 500;

	/**
	 * Whether WPBS is available.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wpbs_get_bookings' ) && function_exists( 'wpbs_get_calendars' );
	}

	/**
	 * Get bookings for the list view.
	 *
	 * @param array $args {
	 *     @type string $status    all|pending|accepted|trash.
	 *     @type string $search    Free-text search.
	 *     @type string $from      Y-m-d start-date lower bound.
	 *     @type string $to        Y-m-d end-date upper bound.
	 *     @type bool   $hide_past  Exclude bookings whose end date has passed.
	 *     @type string $orderby   id|start_date|end_date|calendar|date_created.
	 *     @type string $order     asc|desc.
	 *     @type int    $page      1-based.
	 *     @type int    $per_page  Page size.
	 * }
	 * @return array{items:array,total:int,counts:array}
	 */
	public static function get_bookings( array $args = array() ) {
		if ( ! self::available() ) {
			return array(
				'items'  => array(),
				'total'  => 0,
				'counts' => self::empty_counts(),
			);
		}

		$status    = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'all';
		$search    = isset( $args['search'] ) ? strtolower( trim( (string) $args['search'] ) ) : '';
		$from      = isset( $args['from'] ) ? (string) $args['from'] : '';
		$to        = isset( $args['to'] ) ? (string) $args['to'] : '';
		$hide_past = ! empty( $args['hide_past'] );
		$orderby   = isset( $args['orderby'] ) ? sanitize_key( $args['orderby'] ) : 'start_date';
		$order     = ( isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ) ? 'asc' : 'desc';
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page  = min( 200, max( 1, (int) ( $args['per_page'] ?? 50 ) ) );

		// Restrict to calendars not explicitly excluded (mirrors WPBS BM).
		$names        = self::calendar_names();
		$calendar_ids = array_keys( $names );
		if ( empty( $calendar_ids ) ) {
			return array(
				'items'  => array(),
				'total'  => 0,
				'counts' => self::empty_counts(),
			);
		}

		$query = array(
			'number'       => self::FETCH_CAP,
			'offset'       => 0,
			'orderby'      => in_array( $orderby, array( 'id', 'start_date', 'end_date', 'date_created' ), true ) ? $orderby : 'start_date',
			'order'        => strtoupper( $order ),
			'custom_query' => ' AND calendar_id IN (' . implode( ',', array_map( 'intval', $calendar_ids ) ) . ')',
		);

		$raw = wpbs_get_bookings( $query );

		// Normalize and compute status counts across the (unfiltered-by-status) set.
		$normalized = array();
		$counts     = self::empty_counts();
		$today      = current_time( 'Y-m-d' );

		$filters    = compact( 'status', 'search', 'from', 'to', 'hide_past', 'today' );
		$colors     = self::calendar_colors( $calendar_ids );
		$enquiry_id = self::enquiry_calendar_id();

		foreach ( (array) $raw as $booking ) {
			$row = self::normalize( $booking, $names );

			++$counts['all'];
			$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;

			if ( ! self::passes_filters( $row, $filters ) ) {
				continue;
			}

			$row['color']      = $colors[ $row['calendar_id'] ] ?? '';
			$row['is_enquiry'] = ( $row['calendar_id'] === $enquiry_id );

			unset( $row['haystack'] );
			$normalized[] = $row;
		}

		$total  = count( $normalized );
		$offset = ( $page - 1 ) * $per_page;
		$items  = array_slice( $normalized, $offset, $per_page );

		return array(
			'items'  => $items,
			'total'  => $total,
			'counts' => $counts,
		);
	}

	/**
	 * Site-wide calendar overview for a single month.
	 *
	 * Returns every calendar with the pending/accepted bookings overlapping the
	 * month. Bounded to one month and cached briefly so the (potentially large)
	 * overview stays cheap and never blocks the rest of the UI.
	 *
	 * @param string $month Month as 'Y-m' (defaults to current month).
	 * @return array{month:string,days:int,calendars:array}
	 */
	public static function get_calendar_month( $month = '' ) {
		if ( ! self::available() ) {
			return array(
				'month'     => $month,
				'days'      => 0,
				'calendars' => array(),
			);
		}

		// Normalize to YYYY-MM.
		$ts       = $month && preg_match( '/^\d{4}-\d{2}$/', $month ) ? strtotime( $month . '-01' ) : current_time( 'timestamp' );
		$ym       = gmdate( 'Ym', $ts );
		$label    = gmdate( 'Y-m', $ts );
		$days     = (int) gmdate( 't', $ts );
		$year     = (int) gmdate( 'Y', $ts );
		$month_no = (int) gmdate( 'n', $ts );

		$cache_key = 'meh_calendar_' . $ym;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$names      = self::calendar_names();
		$enquiry_id = self::enquiry_calendar_id();
		$calendars  = array();

		foreach ( $names as $cal_id => $name ) {
			$legend_map = self::legend_items_map( $cal_id );
			$color      = self::default_color( $legend_map );

			$bookings = wpbs_get_bookings(
				array(
					'calendar_id'  => $cal_id,
					'status'       => array( 'pending', 'accepted' ),
					// Bookings overlapping the month (matches WPBS calendar view).
					'custom_query' => ' AND ' . $ym . ' BETWEEN EXTRACT(YEAR_MONTH FROM start_date) AND EXTRACT(YEAR_MONTH FROM end_date)',
				)
			);

			$rows = array();
			foreach ( (array) $bookings as $booking ) {
				$row = self::normalize( $booking, $names );
				unset( $row['haystack'] );
				$row['color'] = $color;
				$rows[]       = $row;
			}

			$calendars[] = array(
				'id'           => $cal_id,
				'name'         => $name,
				'color'        => $color,
				'is_enquiry'   => ( $cal_id === $enquiry_id ),
				'bookings'     => $rows,
				'placeholders' => self::get_placeholders( $cal_id, $year, $month_no, $legend_map ),
			);
		}

		$result = array(
			'month'     => $label,
			'days'      => $days,
			'calendars' => $calendars,
		);

		/**
		 * Filter the calendar-overview cache lifetime (seconds).
		 *
		 * @param int $ttl Default 5 minutes.
		 */
		$ttl = (int) apply_filters( 'meh_calendar_cache_ttl', 5 * MINUTE_IN_SECONDS );
		set_transient( $cache_key, $result, max( 0, $ttl ) );

		return $result;
	}

	/**
	 * Map of allowed calendar id => name (excluding filtered-out calendars).
	 *
	 * @return array
	 */
	protected static function calendar_names() {
		$excluded = (array) apply_filters( 'wpbs_booking_manager_excluded_calendar_ids', array() );
		$names    = array();
		foreach ( wpbs_get_calendars() as $calendar ) {
			$id = (int) $calendar->get( 'id' );
			if ( in_array( $id, $excluded, true ) ) {
				continue;
			}
			$names[ $id ] = method_exists( $calendar, 'get_name' ) ? $calendar->get_name() : $calendar->get( 'name' );
		}
		return $names;
	}

	/**
	 * Whether a normalized row passes the active filters.
	 *
	 * @param array $row     Normalized booking row (with 'haystack').
	 * @param array $filters status/search/from/to/hide_past/today.
	 * @return bool
	 */
	protected static function passes_filters( $row, $filters ) {
		if ( 'all' !== $filters['status'] && $row['status'] !== $filters['status'] ) {
			return false;
		}
		if ( $filters['hide_past'] && $row['end_date'] && $row['end_date'] < $filters['today'] ) {
			return false;
		}
		if ( $filters['from'] && $row['start_date'] && $row['start_date'] < $filters['from'] ) {
			return false;
		}
		if ( $filters['to'] && $row['end_date'] && $row['end_date'] > $filters['to'] ) {
			return false;
		}
		if ( $filters['search'] && false === strpos( strtolower( $row['haystack'] ), $filters['search'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Normalize a WPBS booking object into a flat row.
	 *
	 * @param object $booking Booking object.
	 * @param array  $names   Calendar id => name map.
	 * @return array
	 */
	protected static function normalize( $booking, $names ) {
		$id         = (int) $booking->get( 'id' );
		$cal_id     = (int) $booking->get( 'calendar_id' );
		$start      = (string) $booking->get( 'start_date' );
		$end        = (string) $booking->get( 'end_date' );
		$status     = (string) $booking->get( 'status' );
		$created    = (string) $booking->get( 'date_created' );

		$start_date = $start ? gmdate( 'Y-m-d', strtotime( $start ) ) : '';
		$end_date   = $end ? gmdate( 'Y-m-d', strtotime( $end ) ) : '';

		$guest = self::guest_from_fields( (array) $booking->get( 'fields' ) );

		$haystack = trim( $guest['name'] . ' ' . $guest['email'] . ' #' . $id . ' ' . $start_date . ' ' . $end_date );

		return array(
			'id'           => $id,
			'calendar'     => isset( $names[ $cal_id ] ) ? $names[ $cal_id ] : ( '#' . $cal_id ),
			'calendar_id'  => $cal_id,
			'guest'        => $guest['name'],
			'email'        => $guest['email'],
			'start_date'   => $start_date,
			'end_date'     => $end_date,
			'stay_length'  => self::stay_length( $start_date, $end_date ),
			'status'       => in_array( $status, self::STATUSES, true ) ? $status : 'pending',
			'date_created' => $created ? gmdate( 'Y-m-d', strtotime( $created ) ) : '',
			'view_url'     => add_query_arg(
				array(
					'page'        => 'wpbs-calendars',
					'subpage'     => 'edit-calendar',
					'calendar_id' => $cal_id,
					'booking_id'  => $id,
				),
				admin_url( 'admin.php' )
			),
			'haystack'     => $haystack,
		);
	}

	/**
	 * Guest name/email from booking form fields.
	 *
	 * Prefers the field label/id configured on the settings screen
	 * (meh_guest_name_field / meh_guest_email_field); falls back to heuristics.
	 *
	 * @param array $fields Booking fields.
	 * @return array{name:string,email:string}
	 */
	protected static function guest_from_fields( $fields ) {
		$cfg   = self::guest_field_config();
		$name  = '';
		$email = '';

		foreach ( $fields as $field ) {
			$value = self::field_value( $field );
			if ( '' === $value ) {
				continue;
			}
			$label = strtolower( (string) ( $field['label'] ?? '' ) );
			$id    = (string) ( $field['id'] ?? '' );

			if ( '' === $name && self::field_is( 'name', $field, $cfg, $label, $id, $value ) ) {
				$name = $value;
			} elseif ( '' === $email && self::field_is( 'email', $field, $cfg, $label, $id, $value ) ) {
				$email = $value;
			}
		}

		return array(
			'name'  => $name,
			'email' => $email,
		);
	}

	/**
	 * Whether a field represents the given target (name|email).
	 *
	 * @param string $target 'name' or 'email'.
	 * @param array  $field  Field.
	 * @param array  $cfg    Configured mappings.
	 * @param string $label  Lower-cased label.
	 * @param string $id     Field id.
	 * @param string $value  Field value.
	 * @return bool
	 */
	protected static function field_is( $target, $field, $cfg, $label, $id, $value ) {
		// Configured mapping wins.
		if ( '' !== $cfg[ $target ] ) {
			return $label === $cfg[ $target ] || $id === $cfg[ $target ];
		}
		// Heuristic fallback.
		if ( 'email' === $target ) {
			$type = strtolower( (string) ( $field['type'] ?? '' ) );
			return 'email' === $type || false !== strpos( $label, 'email' ) || is_email( $value );
		}
		return false !== strpos( $label, 'name' );
	}

	/**
	 * Normalize a field's user value to a trimmed string.
	 *
	 * @param array $field Field.
	 * @return string
	 */
	protected static function field_value( $field ) {
		$value = $field['user_value'] ?? '';
		if ( is_array( $value ) ) {
			$value = implode( ' ', $value );
		}
		return trim( (string) $value );
	}

	/**
	 * Configured guest field label/id mappings (lower-cased).
	 *
	 * @return array{name:string,email:string}
	 */
	protected static function guest_field_config() {
		static $cfg = null;
		if ( null === $cfg ) {
			$cfg = array(
				'name'  => strtolower( trim( (string) get_option( 'meh_guest_name_field', '' ) ) ),
				'email' => strtolower( trim( (string) get_option( 'meh_guest_email_field', '' ) ) ),
			);
		}
		return $cfg;
	}

	/**
	 * Legend items for a calendar, keyed by id.
	 *
	 * @param int $calendar_id Calendar ID.
	 * @return array id => array{title,color,is_default,auto_pending}
	 */
	protected static function legend_items_map( $calendar_id ) {
		$map = array();
		if ( ! function_exists( 'wpbs_get_legend_items' ) ) {
			return $map;
		}
		foreach ( (array) wpbs_get_legend_items( array( 'calendar_id' => $calendar_id ) ) as $item ) {
			$c                       = $item->get( 'color' );
			$map[ (int) $item->get( 'id' ) ] = array(
				'title'        => (string) $item->get( 'title' ),
				'color'        => ( is_array( $c ) && ! empty( $c[0] ) ) ? $c[0] : '',
				'is_default'   => 1 === (int) $item->get( 'is_default' ),
				'auto_pending' => (string) $item->get( 'auto_pending' ),
			);
		}
		return $map;
	}

	/**
	 * Default legend colour for a calendar (from its legend map).
	 *
	 * @param array $legend_map Legend items map.
	 * @return string
	 */
	protected static function default_color( $legend_map ) {
		foreach ( $legend_map as $li ) {
			if ( $li['is_default'] ) {
				return $li['color'];
			}
		}
		return '';
	}

	/**
	 * Default legend colour per calendar.
	 *
	 * @param array $calendar_ids Calendar IDs.
	 * @return array id => hex colour string.
	 */
	protected static function calendar_colors( $calendar_ids ) {
		$colors = array();
		foreach ( $calendar_ids as $id ) {
			$colors[ $id ] = self::default_color( self::legend_items_map( $id ) );
		}
		return $colors;
	}

	/**
	 * Manual placeholder spans for a calendar in a month.
	 *
	 * Placeholders are day-level events with booking_id = 0 (set manually, not
	 * from a booking) carrying a non-default legend item. Consecutive days with
	 * the same legend item are grouped into a span.
	 *
	 * @param int   $calendar_id Calendar ID.
	 * @param int   $year        Year.
	 * @param int   $month       Month (1-12).
	 * @param array $legend_map  Legend items map.
	 * @return array
	 */
	protected static function get_placeholders( $calendar_id, $year, $month, $legend_map ) {
		if ( ! function_exists( 'wpbs_get_events' ) ) {
			return array();
		}

		$events = wpbs_get_events(
			array(
				'calendar_id' => $calendar_id,
				'date_year'   => array( $year ),
				'date_month'  => array( $month ),
			)
		);

		// Collect manual, non-default legend days grouped by legend item.
		$by_legend = array();
		foreach ( (array) $events as $event ) {
			if ( 0 !== (int) $event->get( 'booking_id' ) ) {
				continue; // Booking-derived, not a manual placeholder.
			}
			$li = (int) $event->get( 'legend_item_id' );
			if ( 0 === $li || empty( $legend_map[ $li ] ) || $legend_map[ $li ]['is_default'] ) {
				continue;
			}
			$day  = (int) $event->get( 'date_day' );
			$note = trim( (string) ( $event->get( 'tooltip' ) ? $event->get( 'tooltip' ) : $event->get( 'description' ) ) );

			$by_legend[ $li ][ $day ] = $note;
		}

		// Group consecutive days per legend item into spans.
		$spans = array();
		foreach ( $by_legend as $li => $days ) {
			foreach ( self::group_consecutive( $days ) as $range ) {
				$spans[] = self::placeholder_span( $legend_map[ $li ], $range['start'], $range['end'], $range['note'] );
			}
		}

		return $spans;
	}

	/**
	 * Group a day => note map into consecutive-day ranges.
	 *
	 * @param array $days day (int) => note (string).
	 * @return array List of { start, end, note }.
	 */
	protected static function group_consecutive( $days ) {
		ksort( $days );
		$ranges = array();
		$start  = null;
		$prev   = null;
		$note   = '';

		foreach ( $days as $day => $day_note ) {
			if ( null !== $start && $day !== $prev + 1 ) {
				$ranges[] = array(
					'start' => $start,
					'end'   => $prev,
					'note'  => $note,
				);
				$start    = null;
			}
			if ( null === $start ) {
				$start = $day;
				$note  = $day_note;
			}
			$prev = $day;
			$note = $note ? $note : $day_note;
		}

		if ( null !== $start ) {
			$ranges[] = array(
				'start' => $start,
				'end'   => $prev,
				'note'  => $note,
			);
		}

		return $ranges;
	}

	/**
	 * Build a placeholder span entry.
	 *
	 * @param array  $legend Legend item.
	 * @param int    $start  Start day.
	 * @param int    $end    End day.
	 * @param string $note   Optional note.
	 * @return array
	 */
	protected static function placeholder_span( $legend, $start, $end, $note ) {
		return array(
			'title'      => $legend['title'],
			'color'      => $legend['color'],
			'start_day'  => $start,
			'end_day'    => $end,
			'note'       => $note,
			'is_placeholder' => true,
		);
	}

	/**
	 * The configured "Event Enquiry" calendar id (0 if unset).
	 *
	 * @return int
	 */
	public static function enquiry_calendar_id() {
		return (int) get_option( 'meh_event_enquiry_calendar', 0 );
	}

	/**
	 * Convert a source (enquiry) booking into a real booking on a target
	 * calendar, copying dates and form fields and blocking availability.
	 *
	 * Side effects that WPBS's form flow would run (emails, payments, pricing,
	 * inventory) are deliberately NOT triggered — this is a booking record with
	 * blocked dates for the management team.
	 *
	 * @param int    $source_id          Source booking id.
	 * @param int    $target_calendar_id Target calendar id.
	 * @param string $status             pending|accepted|trash. Default pending.
	 * @return array|\WP_Error { id, edit_url }
	 */
	public static function convert_booking( $source_id, $target_calendar_id, $status = 'pending' ) {
		if ( ! self::available() || ! function_exists( 'wpbs_insert_booking' ) || ! function_exists( 'wpbs_get_booking' ) ) {
			return new \WP_Error( 'meh_unavailable', __( 'WP Booking System is not available.', 'marthrown-enquiry-hub' ), array( 'status' => 503 ) );
		}

		$source = wpbs_get_booking( (int) $source_id );
		if ( ! $source ) {
			return new \WP_Error( 'meh_not_found', __( 'Source booking not found.', 'marthrown-enquiry-hub' ), array( 'status' => 404 ) );
		}

		$target = (int) $target_calendar_id;
		$names  = self::calendar_names();
		if ( ! isset( $names[ $target ] ) ) {
			return new \WP_Error( 'meh_bad_target', __( 'Invalid target calendar.', 'marthrown-enquiry-hub' ), array( 'status' => 400 ) );
		}

		$status = in_array( $status, self::STATUSES, true ) ? $status : 'pending';
		$start  = (string) $source->get( 'start_date' );
		$end    = (string) $source->get( 'end_date' );
		$now    = current_time( 'Y-m-d H:i:s' );

		$new_id = wpbs_insert_booking(
			array(
				'calendar_id'  => $target,
				'form_id'      => (int) $source->get( 'form_id' ),
				'start_date'   => $start,
				'end_date'     => $end,
				// Array is JSON-encoded by the DB layer.
				'fields'       => $source->get( 'fields' ),
				'status'       => $status,
				'is_read'      => 1,
				'date_created' => $now,
				'date_modified' => $now,
				'invoice_hash' => function_exists( 'wpbs_generate_hash' ) ? wpbs_generate_hash() : wp_generate_uuid4(),
			)
		);

		if ( ! $new_id ) {
			return new \WP_Error( 'meh_insert_failed', __( 'Could not create the booking.', 'marthrown-enquiry-hub' ), array( 'status' => 500 ) );
		}

		// Traceability.
		if ( function_exists( 'wpbs_add_booking_meta' ) ) {
			wpbs_add_booking_meta( $new_id, 'meh_converted_from', (int) $source_id );
			wpbs_add_booking_meta( (int) $source_id, 'meh_converted_to', (int) $new_id );
		}

		// Block the dates on the target calendar (best-effort).
		self::block_booking_dates( $target, (int) $new_id, $start, $end );

		// Invalidate cached month overviews spanning the booking.
		self::clear_calendar_cache( $start, $end );

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
	protected static function block_booking_dates( $calendar_id, $booking_id, $start, $end ) {
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
		foreach ( self::legend_items_map( $calendar_id ) as $id => $li ) {
			if ( 'booked' === $li['auto_pending'] ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Clear cached month overviews covering a date range.
	 *
	 * @param string $start Start datetime.
	 * @param string $end   End datetime.
	 */
	protected static function clear_calendar_cache( $start, $end ) {
		$s = strtotime( $start );
		$e = strtotime( $end );
		if ( ! $s || ! $e ) {
			return;
		}
		$cursor = strtotime( gmdate( 'Y-m-01', $s ) );
		$last   = strtotime( gmdate( 'Y-m-01', $e ) );
		while ( $cursor <= $last ) {
			delete_transient( 'meh_calendar_' . gmdate( 'Ym', $cursor ) );
			$cursor = strtotime( '+1 month', $cursor );
		}
	}

	/**
	 * List calendars for pickers (new booking / convert targets).
	 *
	 * @return array
	 */
	public static function list_calendars() {
		if ( ! self::available() ) {
			return array();
		}
		$names      = self::calendar_names();
		$colors     = self::calendar_colors( array_keys( $names ) );
		$enquiry_id = self::enquiry_calendar_id();

		$out = array();
		foreach ( $names as $id => $name ) {
			$out[] = array(
				'id'         => $id,
				'name'       => $name,
				'color'      => $colors[ $id ] ?? '',
				'is_enquiry' => ( $id === $enquiry_id ),
				'add_url'    => add_query_arg(
					array(
						'page'        => 'wpbs-calendars',
						'subpage'     => 'edit-calendar',
						'calendar_id' => $id,
						'add_booking' => '',
					),
					admin_url( 'admin.php' )
				),
			);
		}
		return $out;
	}

	/**
	 * Compute a stay-length label (days / nights).
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @return string
	 */
	protected static function stay_length( $start_date, $end_date ) {
		if ( ! $start_date || ! $end_date ) {
			return '';
		}
		$nights = (int) round( ( strtotime( $end_date ) - strtotime( $start_date ) ) / DAY_IN_SECONDS );
		if ( $nights <= 0 ) {
			return __( '1 day', 'marthrown-enquiry-hub' );
		}
		$days = $nights + 1;
		return sprintf(
			/* translators: 1: day count, 2: night count */
			__( '%1$d days / %2$d nights', 'marthrown-enquiry-hub' ),
			$days,
			$nights
		);
	}

	/**
	 * Empty status counts scaffold.
	 *
	 * @return array
	 */
	protected static function empty_counts() {
		return array(
			'all'      => 0,
			'pending'  => 0,
			'accepted' => 0,
			'trash'    => 0,
		);
	}
}
