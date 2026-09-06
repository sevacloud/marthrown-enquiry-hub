<?php
/**
 * Staging marker.
 *
 * One reader of the plugin's existing staging detection, so every part of the
 * enquiry layer that has to behave differently on the staging copy asks the
 * same question in the same way: the Intake_Handler setting `is_test` on a
 * created enquiry (Requirements 17.1, 17.2), the Contact_Linker prefixing the
 * contact first name, and the Booking_Creator prefixing a booking guest name
 * (Requirement 17.3).
 *
 * Detection itself is not reimplemented here. `meh_is_staging()` in the plugin
 * bootstrap owns the MEH_ENVIRONMENT / wp_get_environment_type() / `-staging`
 * directory order and the `meh_is_staging` filter that overrides it; this class
 * only wraps that helper and adds the naming concern.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StagingMarker
 */
class StagingMarker {

	/**
	 * Prefix used when the MEH_TEST_PREFIX constant is not defined.
	 *
	 * The constant is defined in the plugin bootstrap and is overridable from
	 * wp-config; this mirrors its default so the class is also usable from the
	 * `pure` test suite, where the bootstrap has not run.
	 */
	const DEFAULT_PREFIX = 'TEST_';

	/**
	 * Whether the plugin is running as the staging copy.
	 *
	 * Wraps the `meh_is_staging()` bootstrap helper, guarded by an existence
	 * check so a context in which the bootstrap has not loaded reports
	 * production rather than fatalling. Production is the safe answer: a
	 * mistaken `false` marks a record live, which is visible and correctable,
	 * where a mistaken `true` would quietly mark live records as test data that
	 * the delete-test-records route would then remove.
	 *
	 * @return bool True in staging mode, false in production mode.
	 */
	public static function is_staging() {
		if ( ! function_exists( 'meh_is_staging' ) ) {
			return false;
		}

		return (bool) meh_is_staging();
	}

	/**
	 * The configured test prefix.
	 *
	 * @return string
	 */
	public static function prefix() {
		if ( defined( 'MEH_TEST_PREFIX' ) ) {
			return (string) MEH_TEST_PREFIX;
		}

		return self::DEFAULT_PREFIX;
	}

	/**
	 * Apply the test prefix to a name when running in staging mode.
	 *
	 * Returns the name unchanged in production mode, when the name is empty,
	 * and when the prefix is already present, so a value passed through more
	 * than once — a contact re-linked after an edit, say — never accumulates
	 * repeated prefixes.
	 *
	 * @param string $name Name to mark.
	 * @return string
	 */
	public static function apply_name( $name ) {
		$name = (string) $name;

		if ( '' === $name || ! self::is_staging() ) {
			return $name;
		}

		$prefix = self::prefix();

		if ( '' === $prefix || 0 === strpos( $name, $prefix ) ) {
			return $name;
		}

		return $prefix . $name;
	}
}
