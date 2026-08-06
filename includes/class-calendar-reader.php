<?php
/**
 * Calendar overview reader.
 *
 * Builds the site-wide month overview (all calendars) plus manual placeholder
 * spans, reusing the shared WPBS helpers on SourceWpbs. Bounded to one month
 * and cached briefly so it never blocks the UI.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalendarReader
 */
class CalendarReader {

	const CACHE_PREFIX = 'meh_calendar_';

	/**
	 * Site-wide calendar overview for a single month.
	 *
	 * @param string $month Month as 'Y-m' (defaults to current month).
	 * @return array{month:string,days:int,calendars:array}
	 */
	public static function get_month( $month = '' ) {
		if ( ! SourceWpbs::available() ) {
			return array(
				'month'     => $month,
				'days'      => 0,
				'calendars' => array(),
			);
		}

		$ts       = $month && preg_match( '/^\d{4}-\d{2}$/', $month ) ? strtotime( $month . '-01' ) : current_time( 'timestamp' );
		$ym       = gmdate( 'Ym', $ts );
		$label    = gmdate( 'Y-m', $ts );
		$days     = (int) gmdate( 't', $ts );
		$year     = (int) gmdate( 'Y', $ts );
		$month_no = (int) gmdate( 'n', $ts );

		$cache_key = self::CACHE_PREFIX . $ym;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$names      = SourceWpbs::calendar_names();
		$enquiry_id = SourceWpbs::enquiry_calendar_id();
		$calendars  = array();

		foreach ( $names as $cal_id => $name ) {
			$legend_map = SourceWpbs::legend_items_map( $cal_id );
			$color      = SourceWpbs::default_color( $legend_map );

			$bookings = wpbs_get_bookings(
				array(
					'calendar_id'  => $cal_id,
					'status'       => array( 'pending', 'accepted' ),
					'custom_query' => ' AND ' . $ym . ' BETWEEN EXTRACT(YEAR_MONTH FROM start_date) AND EXTRACT(YEAR_MONTH FROM end_date)',
				)
			);

			$rows = array();
			foreach ( (array) $bookings as $booking ) {
				$row = SourceWpbs::normalize( $booking, $names );
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
	 * Invalidate cached month overviews covering a date range.
	 *
	 * @param string $start Start datetime.
	 * @param string $end   End datetime.
	 */
	public static function clear_cache( $start, $end ) {
		$s = strtotime( $start );
		$e = strtotime( $end );
		if ( ! $s || ! $e ) {
			return;
		}
		$cursor = strtotime( gmdate( 'Y-m-01', $s ) );
		$last   = strtotime( gmdate( 'Y-m-01', $e ) );
		while ( $cursor <= $last ) {
			delete_transient( self::CACHE_PREFIX . gmdate( 'Ym', $cursor ) );
			$cursor = strtotime( '+1 month', $cursor );
		}
	}

	/**
	 * Manual placeholder spans for a calendar in a month.
	 *
	 * Placeholders are day-level events with booking_id = 0 (set manually, not
	 * from a booking) carrying a non-default legend item.
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

		$spans = array();
		foreach ( $by_legend as $li => $days ) {
			foreach ( self::group_consecutive( $days ) as $range ) {
				$spans[] = array(
					'title'          => $legend_map[ $li ]['title'],
					'color'          => $legend_map[ $li ]['color'],
					'start_day'      => $range['start'],
					'end_day'        => $range['end'],
					'note'           => $range['note'],
					'is_placeholder' => true,
				);
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
}
