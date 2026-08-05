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

		$filters = compact( 'status', 'search', 'from', 'to', 'hide_past', 'today' );

		foreach ( (array) $raw as $booking ) {
			$row = self::normalize( $booking, $names );

			++$counts['all'];
			$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;

			if ( ! self::passes_filters( $row, $filters ) ) {
				continue;
			}

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
	 * Best-effort guest name/email from booking form fields.
	 *
	 * @param array $fields Booking fields.
	 * @return array{name:string,email:string}
	 */
	protected static function guest_from_fields( $fields ) {
		$name  = '';
		$email = '';

		foreach ( $fields as $field ) {
			$label = strtolower( (string) ( $field['label'] ?? '' ) );
			$type  = strtolower( (string) ( $field['type'] ?? '' ) );
			$value = $field['user_value'] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( ' ', $value );
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			if ( ! $email && ( 'email' === $type || false !== strpos( $label, 'email' ) || is_email( $value ) ) ) {
				$email = $value;
				continue;
			}
			if ( ! $name && ( false !== strpos( $label, 'name' ) ) ) {
				$name = $value;
			}
		}

		return array(
			'name'  => $name,
			'email' => $email,
		);
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
