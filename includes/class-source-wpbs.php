<?php
/**
 * Source: WP Booking System (read layer + acknowledgment state).
 *
 * Bookings are NOT duplicated into FluentCRM. They are read directly from the
 * `wpbs_` tables at request time and bucketed by date math plus an
 * acknowledgment table this plugin owns (so WPBS updates never clobber it).
 *
 * Buckets:
 *   - new      : no acknowledgment row (any dates) — the only manual bucket.
 *   - upcoming : acknowledged and check-in is in the future.
 *   - current  : acknowledged and today is between check-in and check-out.
 *   - past     : acknowledged and check-out within the last 30 days.
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

	const PAST_WINDOW_DAYS = 30;

	/**
	 * Acknowledgment table (without prefix).
	 *
	 * @return string
	 */
	public static function ack_table() {
		global $wpdb;
		return $wpdb->prefix . 'marthrown_booking_ack';
	}

	/**
	 * Create the acknowledgment table. Called on activation.
	 */
	public static function create_ack_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::ack_table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			booking_id BIGINT(20) UNSIGNED NOT NULL,
			acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			acknowledged_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (booking_id),
			KEY acknowledged_at (acknowledged_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Whether a booking has been acknowledged.
	 *
	 * @param int $booking_id Booking ID.
	 * @return bool
	 */
	public static function is_acknowledged( $booking_id ) {
		global $wpdb;
		$table = self::ack_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT booking_id FROM {$table} WHERE booking_id = %d", absint( $booking_id ) ) );
		return null !== $found;
	}

	/**
	 * Record an acknowledgment.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id    Acknowledging user.
	 * @return bool
	 */
	public static function acknowledge( $booking_id, $user_id ) {
		global $wpdb;
		$table = self::ack_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"INSERT INTO {$table} (booking_id, acknowledged_at, acknowledged_by) VALUES (%d, %s, %d)
				 ON DUPLICATE KEY UPDATE acknowledged_at = VALUES(acknowledged_at), acknowledged_by = VALUES(acknowledged_by)",
				absint( $booking_id ),
				current_time( 'mysql' ),
				absint( $user_id )
			)
		);

		return false !== $result;
	}

	/**
	 * Get bookings for a bucket.
	 *
	 * @param string $bucket   new|upcoming|current|past.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Page size.
	 * @return array{items:array,total:int}
	 */
	public static function get_bookings( $bucket, $page = 1, $per_page = 20 ) {
		$all = self::read_all_bookings();

		$today   = current_time( 'Y-m-d' );
		$cutoff  = gmdate( 'Y-m-d', strtotime( $today . ' -' . self::PAST_WINDOW_DAYS . ' days' ) );
		$matched = array();

		foreach ( $all as $booking ) {
			$acked    = self::is_acknowledged( $booking['id'] );
			$check_in = $booking['check_in'];
			$check_out = $booking['check_out'];

			$in_bucket = false;
			switch ( $bucket ) {
				case 'new':
					$in_bucket = ! $acked;
					break;
				case 'upcoming':
					$in_bucket = $acked && $check_in && $check_in > $today;
					break;
				case 'current':
					$in_bucket = $acked && $check_in && $check_out && $check_in <= $today && $check_out >= $today;
					break;
				case 'past':
					$in_bucket = $acked && $check_out && $check_out < $today && $check_out >= $cutoff;
					break;
			}

			if ( $in_bucket ) {
				$booking['acknowledged'] = $acked;
				$matched[]               = $booking;
			}
		}

		// New: most urgent (earliest check-in) first. Others: sensible defaults.
		usort(
			$matched,
			function ( $a, $b ) use ( $bucket ) {
				if ( 'past' === $bucket ) {
					return strcmp( (string) $b['check_out'], (string) $a['check_out'] );
				}
				return strcmp( (string) $a['check_in'], (string) $b['check_in'] );
			}
		);

		$total  = count( $matched );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$items  = array_slice( $matched, $offset, $per_page );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Read and normalize all bookings from the WPBS tables.
	 *
	 * NOTE: WPBS schema varies by version. Adjust column/meta names here (or via
	 * the `meh_wpbs_bookings` filter) to match the installed schema.
	 *
	 * @return array List of normalized bookings.
	 */
	protected static function read_all_bookings() {
		global $wpdb;

		$table = $wpdb->prefix . 'wpbs_bookings';
		if ( ! self::table_exists( $table ) ) {
			return (array) apply_filters( 'meh_wpbs_bookings', array() );
		}

		// Pull a bounded, recent-ish set to keep this cheap on shared hosting.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 500", ARRAY_A );

		$bookings = array();
		foreach ( (array) $rows as $row ) {
			$meta = self::get_booking_meta( (int) $row['id'] );

			$bookings[] = array(
				'id'        => (int) $row['id'],
				'check_in'  => self::pick( $row, $meta, array( 'check_in', 'checkin', 'start_date', 'from_date' ) ),
				'check_out' => self::pick( $row, $meta, array( 'check_out', 'checkout', 'end_date', 'to_date' ) ),
				'guest'     => self::guest_name( $row, $meta ),
				'email'     => self::pick( $row, $meta, array( 'email', 'customer_email', 'booking_email' ) ),
				'source'    => self::pick( $row, $meta, array( 'source', 'booking_source' ) ),
				'created'   => self::pick( $row, $meta, array( 'date_created', 'created_at', 'created' ) ),
			);
		}

		/**
		 * Filter the normalized WPBS bookings list.
		 *
		 * @param array $bookings Normalized bookings.
		 */
		return (array) apply_filters( 'meh_wpbs_bookings', $bookings );
	}

	/**
	 * Fetch booking meta for a booking id.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array
	 */
	protected static function get_booking_meta( $booking_id ) {
		global $wpdb;
		$meta_table = $wpdb->prefix . 'wpbs_bookings_meta';
		if ( ! self::table_exists( $meta_table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$meta_table} WHERE booking_id = %d", $booking_id ), ARRAY_A );

		$meta = array();
		foreach ( (array) $rows as $r ) {
			$meta[ $r['meta_key'] ] = $r['meta_value'];
		}
		return $meta;
	}

	/**
	 * Pick the first non-empty value from a row/meta by candidate keys.
	 *
	 * @param array $row        Row.
	 * @param array $meta       Meta.
	 * @param array $candidates Candidate keys.
	 * @return string
	 */
	protected static function pick( $row, $meta, array $candidates ) {
		foreach ( $candidates as $key ) {
			if ( ! empty( $row[ $key ] ) ) {
				return (string) $row[ $key ];
			}
			if ( ! empty( $meta[ $key ] ) ) {
				return (string) $meta[ $key ];
			}
		}
		return '';
	}

	/**
	 * Derive a guest name.
	 *
	 * @param array $row  Row.
	 * @param array $meta Meta.
	 * @return string
	 */
	protected static function guest_name( $row, $meta ) {
		$name = self::pick( $row, $meta, array( 'name', 'full_name', 'customer_name', 'guest' ) );
		if ( $name ) {
			return $name;
		}
		$first = isset( $meta['first_name'] ) ? $meta['first_name'] : '';
		$last  = isset( $meta['last_name'] ) ? $meta['last_name'] : '';
		return trim( $first . ' ' . $last );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	protected static function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
