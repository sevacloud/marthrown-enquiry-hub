<?php
/**
 * Central Eris example count for the property tests.
 *
 * Every property test names the number of examples it wants through this helper
 * rather than as a literal:
 *
 *     $this->limitTo( Iterations::count( 100 ) )
 *
 * With no environment set the helper answers with the count the caller asked
 * for, so each test keeps the example count it was written with. Setting
 * `MEH_PBT_ITERATIONS` to a positive integer overrides every count at once,
 * which is how a whole run is scaled down for a fast feedback loop:
 *
 *     MEH_PBT_ITERATIONS=10 npm run test:php:pure
 *
 * The per-test default stays in the call, so the intent behind a test that
 * deliberately asks for more or fewer examples than its neighbours is not lost
 * to a single global number.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub\Tests;

/**
 * Class Iterations
 */
class Iterations {

	/** Environment variable that overrides every per-test example count. */
	const ENV_VAR = 'MEH_PBT_ITERATIONS';

	/** Example count assumed when a caller names none. */
	const DEFAULT_COUNT = 100;

	/**
	 * The number of Eris examples a property test should generate.
	 *
	 * @param int $default Count the calling test was written with.
	 * @return int Positive example count.
	 */
	public static function count( $default = self::DEFAULT_COUNT ) {
		$override = self::override();

		if ( null !== $override ) {
			return $override;
		}

		$default = (int) $default;

		return $default > 0 ? $default : self::DEFAULT_COUNT;
	}

	/**
	 * The environment override, when it names a positive integer.
	 *
	 * Anything else — unset, empty, non-numeric, zero or negative — is no
	 * override at all, so a malformed value leaves the per-test counts standing
	 * rather than quietly reducing the suite to nothing.
	 *
	 * @return int|null Positive example count, or null when unset or unusable.
	 */
	protected static function override() {
		$raw = getenv( self::ENV_VAR );

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$raw = trim( $raw );

		if ( '' === $raw || ! preg_match( '/^[0-9]+$/', $raw ) ) {
			return null;
		}

		$count = (int) $raw;

		return $count > 0 ? $count : null;
	}
}
