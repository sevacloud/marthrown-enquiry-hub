<?php
/**
 * Source: WP Booking System.
 *
 * Polls the plugin's `wpbs_` tables for new bookings/enquiries created since a
 * stored timestamp, and logs each new row into FluentCRM via
 * FluentCrmWriter::log_enquiry() with source 'wpbs'.
 *
 * Booking *management* remains inside the native WP Booking System plugin; this
 * class only reads new rows and mirrors them as enquiries.
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

	const SOURCE       = 'wpbs';
	const OPT_LAST_SYNC = 'meh_wpbs_last_sync';

	/**
	 * Poll for new bookings since the last stored timestamp and log them.
	 *
	 * @return int|\WP_Error Number of rows processed, or WP_Error.
	 */
	public static function poll() {
		global $wpdb;

		$table = self::bookings_table();
		if ( ! self::table_exists( $table ) ) {
			return new \WP_Error( 'meh_wpbs_missing', __( 'WP Booking System tables were not found.', 'marthrown-enquiry-hub' ) );
		}

		// Stored high-water mark. Default to the epoch so the first run picks
		// up everything, or use a recent window if you prefer.
		$last_sync = get_option( self::OPT_LAST_SYNC, '1970-01-01 00:00:00' );

		$rows = self::get_new_bookings( $last_sync );
		if ( empty( $rows ) ) {
			return 0;
		}

		$processed = 0;
		$high_water = $last_sync;

		foreach ( $rows as $row ) {
			$email = self::extract_email( $row );
			if ( ! $email ) {
				continue;
			}

			$name    = self::extract_name( $row );
			$message = self::build_message( $row );

			$result = FluentCrmWriter::log_enquiry( $email, $name, $message, self::SOURCE );
			if ( ! is_wp_error( $result ) ) {
				$processed++;
			}

			// Track the newest row timestamp we have seen.
			if ( ! empty( $row['date_created'] ) && $row['date_created'] > $high_water ) {
				$high_water = $row['date_created'];
			}
		}

		// Advance the high-water mark so we never reprocess the same rows.
		update_option( self::OPT_LAST_SYNC, $high_water, false );

		return $processed;
	}

	/**
	 * Query bookings created after the given timestamp.
	 *
	 * NOTE: WPBS schema varies by version. This selects from wpbs_bookings and
	 * relies on a `date_created` column. Adjust the column names to match the
	 * installed schema if required.
	 *
	 * @param string $since MySQL datetime string.
	 * @return array Rows as associative arrays.
	 */
	protected static function get_new_bookings( $since ) {
		global $wpdb;

		$table = self::bookings_table();

		$sql = "SELECT * FROM {$table} WHERE date_created > %s ORDER BY date_created ASC LIMIT 200";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal, value is prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $since ), ARRAY_A );

		if ( empty( $rows ) ) {
			return array();
		}

		// Attach any booking meta (form fields) keyed by booking id.
		foreach ( $rows as &$row ) {
			$row['meta'] = self::get_booking_meta( (int) $row['id'] );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Fetch booking meta (submitted form fields) for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array key => value pairs.
	 */
	protected static function get_booking_meta( $booking_id ) {
		global $wpdb;

		$meta_table = self::booking_meta_table();
		if ( ! self::table_exists( $meta_table ) ) {
			return array();
		}

		$sql = "SELECT meta_key, meta_value FROM {$meta_table} WHERE booking_id = %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is internal, value is prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $booking_id ), ARRAY_A );

		$meta = array();
		foreach ( (array) $rows as $r ) {
			$meta[ $r['meta_key'] ] = $r['meta_value'];
		}
		return $meta;
	}

	/**
	 * Extract an email address from a booking row.
	 *
	 * @param array $row Booking row (with 'meta').
	 * @return string
	 */
	protected static function extract_email( array $row ) {
		$candidates = array( 'email', 'customer_email', 'booking_email' );
		foreach ( $candidates as $key ) {
			if ( ! empty( $row[ $key ] ) && is_email( $row[ $key ] ) ) {
				return $row[ $key ];
			}
			if ( ! empty( $row['meta'][ $key ] ) && is_email( $row['meta'][ $key ] ) ) {
				return $row['meta'][ $key ];
			}
		}

		// Scan meta for anything that looks like an email.
		foreach ( (array) ( isset( $row['meta'] ) ? $row['meta'] : array() ) as $value ) {
			if ( is_string( $value ) && is_email( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Extract a full name from a booking row.
	 *
	 * @param array $row Booking row (with 'meta').
	 * @return string
	 */
	protected static function extract_name( array $row ) {
		$meta = isset( $row['meta'] ) ? $row['meta'] : array();

		foreach ( array( 'name', 'full_name', 'customer_name' ) as $key ) {
			if ( ! empty( $row[ $key ] ) ) {
				return (string) $row[ $key ];
			}
			if ( ! empty( $meta[ $key ] ) ) {
				return (string) $meta[ $key ];
			}
		}

		$first = isset( $meta['first_name'] ) ? $meta['first_name'] : '';
		$last  = isset( $meta['last_name'] ) ? $meta['last_name'] : '';
		return trim( $first . ' ' . $last );
	}

	/**
	 * Build a human-readable enquiry message from a booking row.
	 *
	 * @param array $row Booking row.
	 * @return string
	 */
	protected static function build_message( array $row ) {
		$id     = isset( $row['id'] ) ? $row['id'] : '?';
		$status = isset( $row['status'] ) ? $row['status'] : '';

		$message = sprintf(
			/* translators: 1: booking id, 2: status */
			__( 'WP Booking System booking #%1$s (status: %2$s).', 'marthrown-enquiry-hub' ),
			$id,
			$status ? $status : __( 'unknown', 'marthrown-enquiry-hub' )
		);

		// Append any free-text message field from the form meta.
		$meta = isset( $row['meta'] ) ? $row['meta'] : array();
		foreach ( array( 'message', 'comments', 'notes', 'enquiry' ) as $key ) {
			if ( ! empty( $meta[ $key ] ) ) {
				$message .= "\n\n" . wp_strip_all_tags( (string) $meta[ $key ] );
				break;
			}
		}

		return $message;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Table helpers.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * @return string Bookings table name.
	 */
	protected static function bookings_table() {
		global $wpdb;
		return $wpdb->prefix . 'wpbs_bookings';
	}

	/**
	 * @return string Booking meta table name.
	 */
	protected static function booking_meta_table() {
		global $wpdb;
		return $wpdb->prefix . 'wpbs_bookings_meta';
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table Fully-qualified table name.
	 * @return bool
	 */
	protected static function table_exists( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}
}
