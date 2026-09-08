<?php
/**
 * Duplicate detection and intake rate limiting.
 *
 * The two guards that stand in front of the intake path (Requirement 4). Both
 * are asked exactly once per Intake Webhook Request, by `IntakeHandler`, and
 * neither is reachable from the manual creation route: a manual submission
 * comes from an authenticated staff member rather than an unattended form, so
 * neither guard applies to it (Requirement 18.12).
 *
 * Three decisions shape this class:
 *
 * - **Duplicate identity is email plus the exact candidate range set.** Set
 *   equality, not overlap: a submission whose ranges are a subset or a superset
 *   of an existing enquiry's, or which name the same first day but a different
 *   last one, is a different enquiry about different days and is stored
 *   (Requirement 4.2). Outside the window, the same email and the same ranges
 *   are a genuine second enquiry (Requirement 4.5).
 * - **Rate limiting keys on the submitted email address, not the client IP.**
 *   The request is a server-to-server webhook, so the client address is the
 *   site's own on every request and an IP-keyed counter would either throttle
 *   the whole form or nothing at all. The address is hashed before it becomes
 *   part of a transient key, so no enquirer's email sits in the options table
 *   under a readable name (Requirements 4.6, 4.7).
 * - **One time horizon.** The rate window defaults to the same 900 seconds as
 *   the duplicate window, so a burst of resubmissions is answered by whichever
 *   guard fires first and the two can never disagree about "recently".
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DuplicateDetector
 */
class DuplicateDetector {

	/**
	 * Filter exposing the duplicate detection window, in seconds.
	 */
	const WINDOW_FILTER = 'meh_duplicate_window';

	/**
	 * Filter exposing the per-email rate limit as `[ max, seconds ]`.
	 */
	const RATE_EMAIL_FILTER = 'meh_rate_limit_per_email';

	/**
	 * Duplicate window default, in seconds (Requirement 4.4).
	 */
	const DEFAULT_WINDOW = 900;

	/**
	 * Requests permitted per email address per rate window (Requirement 4.7).
	 */
	const DEFAULT_RATE_MAX = 6;

	/**
	 * Rate window default, in seconds — deliberately the duplicate window.
	 */
	const DEFAULT_RATE_WINDOW = 900;

	/**
	 * Prefix of the transient holding one email address's request count.
	 */
	const TRANSIENT_PREFIX = 'meh_rl_';

	/**
	 * The duplicate detection window in seconds (Requirement 4.4).
	 *
	 * A filter returning a non-numeric value falls back to the default; a filter
	 * returning zero or less switches duplicate detection off, which is the only
	 * sensible reading of "no window at all".
	 *
	 * @return int Seconds, never negative.
	 */
	public static function window() {
		$window = self::filtered( self::WINDOW_FILTER, self::DEFAULT_WINDOW );

		if ( ! is_numeric( $window ) ) {
			Log::write( 'duplicate: non-numeric window filtered, using the default' );

			return self::DEFAULT_WINDOW;
		}

		$window = (int) $window;

		return $window > 0 ? $window : 0;
	}

	/**
	 * The per-email rate limit (Requirement 4.7).
	 *
	 * Accepts `[ max, seconds ]` positionally or as `[ 'max' => …, 'seconds' =>
	 * … ]`, and falls back to the default for either half that is missing or is
	 * not a positive whole number, so a mistaken filter cannot leave the intake
	 * path with a zero allowance that rejects every submission.
	 *
	 * @return array{max:int,seconds:int}
	 */
	public static function rate_limit() {
		$filtered = self::filtered(
			self::RATE_EMAIL_FILTER,
			array( self::DEFAULT_RATE_MAX, self::DEFAULT_RATE_WINDOW )
		);

		if ( ! is_array( $filtered ) ) {
			Log::write( 'duplicate: non-array rate limit filtered, using the default' );

			$filtered = array();
		}

		$max     = self::pick( $filtered, 'max', 0 );
		$seconds = self::pick( $filtered, 'seconds', 1 );

		$max     = is_numeric( $max ) ? (int) $max : 0;
		$seconds = is_numeric( $seconds ) ? (int) $seconds : 0;

		return array(
			'max'     => $max > 0 ? $max : self::DEFAULT_RATE_MAX,
			'seconds' => $seconds > 0 ? $seconds : self::DEFAULT_RATE_WINDOW,
		);
	}

	/**
	 * The identifier of the enquiry a submission duplicates, or 0 (Requirement 4.2).
	 *
	 * A match needs all three of: the same `email`, a candidate date range list
	 * equal to the existing enquiry's as a set, and a creation time strictly
	 * inside the window. Elapsed time equal to the window is outside it, matching
	 * Requirement 4.5's "created more than 15 minutes earlier" being a new
	 * enquiry rather than a duplicate.
	 *
	 * Compared as a set, so the ranges' order is not part of the identity: what
	 * this guard is for is the same form arriving twice, and the same three
	 * fortnights listed in a different order is the same submission however the
	 * sender happened to serialise it. Reordering them deliberately is an edit,
	 * which goes through `update()` rather than through here.
	 *
	 * The most recently created match is returned, since that is the enquiry the
	 * rejection row should point a reader at.
	 *
	 * @param string $email  Submitted email address.
	 * @param array  $ranges Submitted candidate date ranges, in any order, each
	 *                       `array{start,end}` or a bare date.
	 * @return int Existing enquiry identifier, or 0 when the submission is not a
	 *             duplicate.
	 */
	public static function find_duplicate( $email, array $ranges ) {
		global $wpdb;

		$email = trim( (string) $email );

		if ( '' === $email ) {
			return 0;
		}

		$keys = self::normalise_ranges( $ranges );

		if ( ! $keys ) {
			// No candidate ranges means no set to be equal to, so the submission
			// cannot satisfy the duplicate identity at all.
			return 0;
		}

		$window = self::window();

		if ( $window <= 0 ) {
			return 0;
		}

		$enquiries    = Schema::table( 'enquiries' );
		$dates_table  = Schema::table( 'dates' );
		$total        = count( $keys );
		$placeholders = implode( ', ', array_fill( 0, $total, '%s' ) );

		// Set equality in one pass: the candidate holds exactly as many distinct
		// ranges as the submission, and every one of them is in the submitted set.
		// Both counts equalling the submitted size makes subset and superset
		// matches impossible. A range is keyed by both its bounds, so the 1st to
		// the 3rd and the 1st to the 10th are two ranges rather than one.
		$key = "CONCAT( d.start_date, '/', d.end_date )";

		$sql = "SELECT e.id FROM {$enquiries} e"
			. " INNER JOIN {$dates_table} d ON d.enquiry_id = e.id"
			. ' WHERE e.email = %s AND e.created_at > %s'
			. ' GROUP BY e.id'
			. " HAVING COUNT( DISTINCT {$key} ) = %d"
			. " AND COUNT( DISTINCT CASE WHEN {$key} IN ( {$placeholders} ) THEN {$key} END ) = %d"
			. ' ORDER BY e.created_at DESC, e.id DESC LIMIT 1';

		$bindings = array_merge(
			array( $email, Clock::mysql( Clock::offset( -$window ) ), $total ),
			$keys,
			array( $total )
		);

		$id = $wpdb->get_var( $wpdb->prepare( $sql, $bindings ) ); // phpcs:ignore WordPress.DB

		return null === $id ? 0 : (int) $id;
	}

	/**
	 * Count this request against its email address and report the verdict
	 * (Requirement 4.6).
	 *
	 * Counting and deciding are one call deliberately: the guard is asked once
	 * per Intake Webhook Request, and splitting them would let a caller decide
	 * without counting, or count twice. The request being asked about is
	 * included in the count, so with the default limit of 6 the first five
	 * requests holding an address report false and the sixth and each subsequent
	 * request inside the window report true.
	 *
	 * The count for one address is held under its own key, so requests holding
	 * any other address never affect it, and the window is measured from the
	 * first request of the run rather than the last, so a steady trickle resets
	 * once the window has elapsed instead of extending indefinitely.
	 *
	 * Fails open when the transient API is unavailable: an unguarded intake
	 * loses no enquiry, where a guard that rejects everything would.
	 *
	 * @param string $email Submitted email address.
	 * @return bool Whether this request exceeds the limit.
	 */
	public static function is_rate_limited( $email ) {
		if ( '' === self::normalise_email( $email ) ) {
			// No address means no bucket to count against. An empty email fails
			// validation moments later, and bucketing every such request together
			// would throttle unrelated submissions.
			return false;
		}

		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			Log::write( 'duplicate: rate limit skipped, transient API unavailable' );

			return false;
		}

		$limit  = self::rate_limit();
		$bucket = self::bucket( $email, $limit['seconds'] );
		$count  = $bucket['count'] + 1;
		$now    = Clock::timestamp();

		// Expire with the window the run started in, not with a fresh one.
		$remaining = $limit['seconds'] - ( $now - $bucket['started'] );

		set_transient(
			self::transient_key( $email ),
			array(
				'count'   => $count,
				'started' => $bucket['started'],
			),
			max( 1, $remaining )
		);

		// Requirement 4.6 refuses the request that brings the count up to the
		// limit, not the one after it: "6 or more requests … create no Enquiry
		// for the sixth and each subsequent request". So the comparison is
		// inclusive, and with the default of 6 five requests get through.
		return $count >= $limit['max'];
	}

	/**
	 * Requests already counted against an email address in the current window.
	 *
	 * A read, unlike self::is_rate_limited(): it counts nothing.
	 *
	 * @param string $email Submitted email address.
	 * @return int
	 */
	public static function hits( $email ) {
		if ( '' === self::normalise_email( $email ) || ! function_exists( 'get_transient' ) ) {
			return 0;
		}

		$bucket = self::bucket( $email, self::rate_limit()['seconds'] );

		return $bucket['count'];
	}

	/**
	 * Forget the request count held for an email address.
	 *
	 * @param string $email Submitted email address.
	 * @return void
	 */
	public static function reset( $email ) {
		if ( '' === self::normalise_email( $email ) || ! function_exists( 'delete_transient' ) ) {
			return;
		}

		delete_transient( self::transient_key( $email ) );
	}

	/**
	 * The transient key holding one email address's request count.
	 *
	 * The address is hashed, so the options table carries no readable enquirer
	 * email, and normalised first, so `Ada@Example.com` and `ada@example.com`
	 * share one bucket exactly as they share one duplicate match under MySQL's
	 * case-insensitive comparison.
	 *
	 * @param string $email Submitted email address.
	 * @return string
	 */
	public static function transient_key( $email ) {
		return self::TRANSIENT_PREFIX . hash( 'sha256', self::normalise_email( $email ) );
	}

	/**
	 * The current counting bucket for an email address.
	 *
	 * A stored bucket whose window has elapsed, or whose start sits in the
	 * future because the clock moved back, is treated as absent and a fresh
	 * window begins.
	 *
	 * @param string $email   Submitted email address.
	 * @param int    $seconds Rate window in seconds.
	 * @return array{count:int,started:int}
	 */
	protected static function bucket( $email, $seconds ) {
		$now    = Clock::timestamp();
		$stored = get_transient( self::transient_key( $email ) );

		if ( is_array( $stored ) && isset( $stored['count'], $stored['started'] ) ) {
			$started = (int) $stored['started'];
			$elapsed = $now - $started;

			if ( $started > 0 && $elapsed >= 0 && $elapsed < (int) $seconds ) {
				return array(
					'count'   => max( 0, (int) $stored['count'] ),
					'started' => $started,
				);
			}
		}

		return array(
			'count'   => 0,
			'started' => $now,
		);
	}

	/**
	 * Reduce submitted candidate date ranges to a sorted set of `start/end` keys.
	 *
	 * The key shape is the one the comparison SQL builds from the stored columns,
	 * so what is bound and what is selected are the same string for the same
	 * range. Duplicates collapse and order is discarded, matching the set
	 * comparison. An unreadable value is dropped rather than failing the call —
	 * the Validator, not this guard, is what reports a malformed date.
	 *
	 * A bare date is read as the single-day range it stands for, so a sender that
	 * names one day and a sender that names the same day twice over describe the
	 * same enquiry to this guard.
	 *
	 * @param array $ranges Submitted candidate date ranges.
	 * @return string[]
	 */
	protected static function normalise_ranges( array $ranges ) {
		$normalised = array();

		foreach ( $ranges as $entry ) {
			if ( is_array( $entry ) ) {
				$start = self::to_date( isset( $entry['start'] ) ? $entry['start'] : null );
				$end   = self::to_date( isset( $entry['end'] ) ? $entry['end'] : null );
			} else {
				$start = self::to_date( $entry );
				$end   = $start;
			}

			if ( null === $start || null === $end ) {
				continue;
			}

			$key = $start . '/' . $end;

			if ( in_array( $key, $normalised, true ) ) {
				continue;
			}

			$normalised[] = $key;
		}

		sort( $normalised );

		return $normalised;
	}

	/**
	 * Read one value as a calendar date at day precision.
	 *
	 * @param mixed $value Submitted value.
	 * @return string|null `Y-m-d`, or null when it is not a date.
	 */
	protected static function to_date( $value ) {
		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'Y-m-d' );
		}

		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		// A leading `Y-m-d` covers a plain date and a full DATETIME alike, and
		// checkdate() rejects the impossible ones a general parser would roll
		// forward into the next month.
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $parts ) ) {
			$year  = (int) $parts[1];
			$month = (int) $parts[2];
			$day   = (int) $parts[3];

			if ( ! checkdate( $month, $day, $year ) ) {
				return null;
			}

			return sprintf( '%04d-%02d-%02d', $year, $month, $day );
		}

		try {
			$parsed = new \DateTimeImmutable( $value );
		} catch ( \Exception $e ) {
			return null;
		}

		return $parsed->format( 'Y-m-d' );
	}

	/**
	 * The keying form of an email address: trimmed and lower-cased.
	 *
	 * @param mixed $email Submitted email address.
	 * @return string
	 */
	protected static function normalise_email( $email ) {
		if ( ! is_scalar( $email ) ) {
			return '';
		}

		return strtolower( trim( (string) $email ) );
	}

	/**
	 * Read one half of a filtered rate limit, positionally or by name.
	 *
	 * @param array      $value    Filtered value.
	 * @param string     $name     Associative key.
	 * @param int|string $position Positional key.
	 * @return mixed Null when neither key is present.
	 */
	protected static function pick( array $value, $name, $position ) {
		if ( array_key_exists( $name, $value ) ) {
			return $value[ $name ];
		}

		return array_key_exists( $position, $value ) ? $value[ $position ] : null;
	}

	/**
	 * Apply a filter where WordPress is loaded, and pass the value through where
	 * it is not, so the class stays usable from the `pure` suite.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Default value.
	 * @return mixed
	 */
	protected static function filtered( $hook, $value ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $value;
		}

		return apply_filters( $hook, $value );
	}
}
