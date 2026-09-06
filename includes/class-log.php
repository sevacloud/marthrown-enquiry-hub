<?php
/**
 * Diagnostic logging.
 *
 * A single wrapper around `error_log()` so every line the plugin writes to the
 * WordPress error log carries the `[MEH]` prefix and is gated in one place.
 * Writing is off unless `WP_DEBUG` is on, which keeps a production log clean,
 * and the `meh_log_enabled` filter can force it on for the resilience logging
 * Requirement 5.6 asks for when `WP_DEBUG` is off.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Log
 */
class Log {

	/**
	 * Prefix applied to every logged line.
	 */
	const PREFIX = '[MEH]';

	/**
	 * Whether logging is currently enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;

		if ( ! function_exists( 'apply_filters' ) ) {
			return (bool) $enabled;
		}

		/**
		 * Filter whether the plugin writes to the WordPress error log.
		 *
		 * @param bool $enabled Defaults to the value of WP_DEBUG.
		 */
		return (bool) apply_filters( 'meh_log_enabled', $enabled );
	}

	/**
	 * Write one line to the WordPress error log.
	 *
	 * @param string $message Message, without the prefix.
	 * @param array  $context Optional structured detail, appended as JSON.
	 * @return bool Whether anything was written.
	 */
	public static function write( $message, array $context = array() ) {
		if ( ! self::enabled() ) {
			return false;
		}

		$line = self::PREFIX . ' ' . trim( (string) $message );

		if ( $context ) {
			$line .= ' ' . self::encode( $context );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );

		return true;
	}

	/**
	 * Encode structured context for the log line.
	 *
	 * Falls back to `print_r()` output when the value will not encode, so a
	 * payload holding invalid UTF-8 still leaves a usable trace.
	 *
	 * @param array $context Context to encode.
	 * @return string
	 */
	protected static function encode( array $context ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$json  = function_exists( 'wp_json_encode' )
			? wp_json_encode( $context, $flags )
			: json_encode( $context, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		if ( ! is_string( $json ) || '' === $json ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure
			$json = print_r( $context, true );
		}

		return (string) $json;
	}
}
