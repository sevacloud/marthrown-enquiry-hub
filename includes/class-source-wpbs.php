<?php
/**
 * Source: WP Booking System (list-view read layer + shared WPBS helpers).
 *
 * Reads bookings live via WPBS's own API (wpbs_get_bookings), mirroring the
 * WP Booking System "Booking Manager" list view: native statuses
 * (pending/accepted/trash), start/end dates, stay length, calendar name.
 *
 * Calendar-overview/placeholder reading lives in CalendarReader; booking
 * creation/conversion lives in BookingConverter. Both reuse the public helpers
 * here so WPBS field/normalize/legend logic has a single home.
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
	 * @param array $args status/search/from/to/hide_past/orderby/order/page/per_page.
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
	 * Map of allowed calendar id => name (excluding filtered-out calendars).
	 *
	 * @return array
	 */
	public static function calendar_names() {
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
	public static function normalize( $booking, $names ) {
		$id      = (int) $booking->get( 'id' );
		$cal_id  = (int) $booking->get( 'calendar_id' );
		$start   = (string) $booking->get( 'start_date' );
		$end     = (string) $booking->get( 'end_date' );
		$status  = (string) $booking->get( 'status' );
		$created = (string) $booking->get( 'date_created' );

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
	 * Guest name/email from booking form fields (configured mapping first).
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
		if ( '' !== $cfg[ $target ] ) {
			return $label === $cfg[ $target ] || $id === $cfg[ $target ];
		}
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
	public static function legend_items_map( $calendar_id ) {
		$map = array();
		if ( ! function_exists( 'wpbs_get_legend_items' ) ) {
			return $map;
		}
		foreach ( (array) wpbs_get_legend_items( array( 'calendar_id' => $calendar_id ) ) as $item ) {
			$c = $item->get( 'color' );
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
	public static function default_color( $legend_map ) {
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
	public static function calendar_colors( $calendar_ids ) {
		$colors = array();
		foreach ( $calendar_ids as $id ) {
			$colors[ $id ] = self::default_color( self::legend_items_map( $id ) );
		}
		return $colors;
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
						'add_booking' => '1',
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
