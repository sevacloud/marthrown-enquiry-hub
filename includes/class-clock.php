<?php
/**
 * Injectable clock.
 *
 * Every timestamp the enquiry layer writes comes from here rather than from
 * `time()`, `date()` or `current_time()` directly, so that timestamp, duplicate
 * window and auto-closure interval behaviour can be exercised deterministically
 * in tests by freezing the clock.
 *
 * All times are expressed in the site timezone and carry whole-second
 * precision, matching what the `DATETIME` columns of the Enquiry Store can
 * hold (Requirements 1.6, 2.2).
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Clock
 */
class Clock {

	/**
	 * MySQL `DATETIME` format.
	 */
	const MYSQL_FORMAT = 'Y-m-d H:i:s';

	/**
	 * The frozen instant, or null when the clock runs freely.
	 *
	 * Only ever set through self::freeze(), which is inert outside a test run.
	 *
	 * @var \DateTimeImmutable|null
	 */
	protected static $frozen = null;

	/**
	 * The site timezone.
	 *
	 * Falls back to the PHP default timezone, then to UTC, so the class is also
	 * usable from the `pure` test suite where WordPress is not loaded.
	 *
	 * @return \DateTimeZone
	 */
	public static function timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		try {
			return new \DateTimeZone( date_default_timezone_get() );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/**
	 * The current instant in the site timezone, to the nearest second.
	 *
	 * @return \DateTimeImmutable
	 */
	public static function now() {
		if ( self::$frozen instanceof \DateTimeImmutable ) {
			return self::$frozen;
		}

		// '@timestamp' drops microseconds, so equality comparisons in tests and
		// round trips through a DATETIME column agree to the second.
		$now = new \DateTimeImmutable( '@' . time() );

		return $now->setTimezone( self::timezone() );
	}

	/**
	 * The current Unix timestamp, honouring a frozen clock.
	 *
	 * @return int
	 */
	public static function timestamp() {
		return (int) self::now()->format( 'U' );
	}

	/**
	 * A MySQL `DATETIME` string in the site timezone.
	 *
	 * @param \DateTimeInterface|int|string|null $when Instant to format. A
	 *        `DateTimeInterface` is converted to the site timezone, an integer
	 *        is treated as a Unix timestamp, a string is parsed in the site
	 *        timezone, and null means "now".
	 * @return string
	 */
	public static function mysql( $when = null ) {
		return self::at( $when )->format( self::MYSQL_FORMAT );
	}

	/**
	 * Coerce a value to a site-timezone instant.
	 *
	 * An unparseable string falls back to the current instant rather than
	 * throwing, so a bad value can never leave a row without a timestamp.
	 *
	 * @param \DateTimeInterface|int|string|null $when Instant to coerce.
	 * @return \DateTimeImmutable
	 */
	public static function at( $when = null ) {
		if ( null === $when || '' === $when ) {
			return self::now();
		}

		if ( $when instanceof \DateTimeImmutable ) {
			return $when->setTimezone( self::timezone() );
		}

		if ( $when instanceof \DateTimeInterface ) {
			return ( new \DateTimeImmutable( '@' . $when->format( 'U' ) ) )
				->setTimezone( self::timezone() );
		}

		if ( is_int( $when ) || ( is_string( $when ) && ctype_digit( $when ) ) ) {
			return ( new \DateTimeImmutable( '@' . (int) $when ) )->setTimezone( self::timezone() );
		}

		try {
			return new \DateTimeImmutable( (string) $when, self::timezone() );
		} catch ( \Exception $e ) {
			return self::now();
		}
	}

	/**
	 * Add or subtract whole seconds from an instant, in the site timezone.
	 *
	 * @param int                               $seconds Seconds to add; negative subtracts.
	 * @param \DateTimeInterface|int|string|null $when    Instant to offset from. Null means "now".
	 * @return \DateTimeImmutable
	 */
	public static function offset( $seconds, $when = null ) {
		$base = self::at( $when );

		return ( new \DateTimeImmutable( '@' . ( (int) $base->format( 'U' ) + (int) $seconds ) ) )
			->setTimezone( self::timezone() );
	}

	/**
	 * Freeze the clock at a given instant. Test-only.
	 *
	 * Does nothing and reports false outside a test run, so production code can
	 * never pin the clock even if this is reached by mistake.
	 *
	 * @param \DateTimeInterface|int|string|null $when Instant to freeze at. Null freezes at "now".
	 * @return bool Whether the clock was frozen.
	 */
	public static function freeze( $when = null ) {
		if ( ! self::is_test_context() ) {
			return false;
		}

		self::$frozen = self::at( $when );

		return true;
	}

	/**
	 * Release a frozen clock. Test-only, and safe to call when not frozen.
	 *
	 * @return void
	 */
	public static function unfreeze() {
		self::$frozen = null;
	}

	/**
	 * Whether the clock is currently frozen.
	 *
	 * @return bool
	 */
	public static function is_frozen() {
		return self::$frozen instanceof \DateTimeImmutable;
	}

	/**
	 * Whether the code is running under a test harness.
	 *
	 * @return bool
	 */
	protected static function is_test_context() {
		if ( defined( 'MEH_TESTING' ) && MEH_TESTING ) {
			return true;
		}

		if ( defined( 'WP_TESTS_DOMAIN' ) ) {
			return true;
		}

		if ( getenv( 'MEH_TESTING' ) ) {
			return true;
		}

		// Loaded only when PHPUnit is running; `false` avoids triggering an autoload.
		return class_exists( '\PHPUnit\Framework\TestCase', false );
	}
}
