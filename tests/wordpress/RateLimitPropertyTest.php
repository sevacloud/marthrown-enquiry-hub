<?php
/**
 * Property 15: Rate limiting keys on the submitted email address.
 *
 * Feature: enquiry-data-layer, Property 15: For any set of submitted email
 * addresses, any sequence of intake webhook requests over those addresses with
 * any inter-arrival gaps, and any configured rate limit `[ max, seconds ]`
 * including the default of 6 per 900 seconds, the requests numbered 1 to
 * `max - 1` holding a given email address within a `seconds` window create
 * enquiries, the `max`-th and each subsequent request holding that same email
 * address within that window create no enquiry and record a rejected intake
 * attempt with reason `rate_limited`, the count for one email address is
 * unaffected by requests holding any other email address, and the count for an
 * address resets once the window has elapsed.
 *
 * **Validates: Requirements 4.6, 4.7**
 *
 * How the property is instantiated, and why:
 *
 * - **The quantifier is a sequence, so the generator produces one.** A single
 *   request cannot express a rate limit; the claim is about the `n`-th request
 *   holding an address. Each iteration draws three addresses and a run of 1 to 10
 *   requests, every request naming which address it carries and how long after
 *   its predecessor it arrives. That is the shape of the thing being claimed, so
 *   it is the shape of the input.
 * - **The oracle is an independent counter, written as the requirement reads.**
 *   One count per address, restarted when the window measured from the run's
 *   first request has elapsed, incremented by the request being decided, and
 *   compared against `max`. It shares no code with the transient the detector
 *   keeps, so a counter that expired with the wrong window, or that bucketed two
 *   addresses together, disagrees with it rather than agreeing with itself.
 * - **The gaps are drawn relative to the window in force**, not as free
 *   integers: same instant, a few seconds, one second inside the window, exactly
 *   on it, one second past it, and anywhere in twice its span. The boundary is
 *   where "within the window" is decided, and independent random gaps would
 *   almost never land on it.
 * - **Independence is asserted after every request, over every address**, not
 *   just over the one the request carried. That is the difference between "the
 *   other address was not limited" and "the other address's count was not
 *   touched", and only the second is what Requirement 4.6 says.
 * - **The address is presented in more than one form.** Plain, padded with
 *   surrounding whitespace, upper-cased and capitalised are the same address
 *   arriving from a form that trims nothing, so they share one count. The oracle
 *   keys on the trimmed, lower-cased address for the same reason.
 * - **Requests it counts are asked about the limit, and nothing else.** The row
 *   counts of every Enquiry Store table, including a seeded enquiry and a seeded
 *   rejection row, are unchanged across the whole run: the guard answers a
 *   question and writes no row.
 *
 * The two consequences the property names beyond the verdict — that a permitted
 * request creates an enquiry, and that a refused one creates none and records a
 * rejection row with reason `rate_limited` — belong to `IntakeHandler`, which
 * asks this guard once per request and acts on the answer. They are asserted by
 * Property 13's test on the intake path. What is asserted here is the verdict
 * that decides them, over sequences no example-based test could cover.
 *
 * The clock is frozen and advanced by hand, so a window elapsing is a matter of
 * arithmetic rather than of waiting: `DuplicateDetector` reads every instant
 * through `Clock`, and the counting bucket carries the instant its window
 * started rather than relying on the transient's own expiry.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix: the WordPress test case rewrites `CREATE TABLE` into its
 * `TEMPORARY` form, and `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see a temporary table.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class RateLimitPropertyTest
 */
class RateLimitPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehrl_';

	/**
	 * The instant every iteration's first request arrives at.
	 */
	const NOW = '2025-06-01 12:00:00';

	/**
	 * How many addresses one iteration draws.
	 *
	 * Three is enough for the independence claim to have somewhere to fail: a run
	 * can interleave two addresses while a third is never presented at all.
	 */
	const ADDRESSES = 3;

	/**
	 * Longest request run one iteration produces.
	 *
	 * Comfortably past the default allowance of 6, so a run can exhaust it, cross
	 * a window boundary and start counting again.
	 */
	const REQUESTS_MAX = 10;

	/**
	 * Forms one address can arrive in, all of them the same address.
	 *
	 * @var string[]
	 */
	const FORMS = array( 'plain', 'padded', 'upper', 'capitalised' );

	/**
	 * Where a request sits relative to the window in force.
	 *
	 * @var string[]
	 */
	const GAP_RELATIONS = array( 'same_instant', 'moments', 'just_inside', 'on_the_boundary', 'just_outside', 'anywhere' );

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Addresses whose counters need clearing afterwards.
	 *
	 * @var string[]
	 */
	private $counted = array();

	/**
	 * Load the classes under test.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-clock.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-query.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-store.php';
		require_once MEH_INCLUDES_DIR . 'class-duplicate-detector.php';
	}

	public function set_up() {
		parent::set_up();

		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		Clock::freeze( self::NOW );

		$this->seed_existing_rows();
	}

	public function tear_down() {
		global $wpdb;

		$this->forget( $this->counted );

		remove_all_filters( DuplicateDetector::RATE_EMAIL_FILTER );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 15: Rate limiting keys on the
	 * submitted email address.
	 *
	 * **Validates: Requirements 4.6, 4.7**
	 *
	 * @eris-shrink 10
	 */
	public function test_rate_limiting_keys_on_the_submitted_email_address() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::scenario() )
			->then(
				function ( array $case ) {
					$limit     = $this->apply_limit( $case['limit'] );
					$addresses = $this->counted( $case['addresses'] );

					$before  = $this->row_counts();
					$buckets = array();
					$elapsed = 0;

					foreach ( array_values( $case['requests'] ) as $index => $request ) {
						$elapsed += self::gap( $request['gap'], $limit, (int) $request['jitter'] );

						Clock::freeze( Clock::offset( $elapsed, self::NOW ) );

						$address   = $addresses[ (int) $request['who'] % count( $addresses ) ];
						$presented = self::presented( $address, $request['form'] );
						$key       = self::bucket_key( $presented );

						// The oracle: one counter per address, restarted once the
						// window it began in has elapsed, counting the request being
						// decided.
						if ( ! isset( $buckets[ $key ] ) || $elapsed - $buckets[ $key ]['started'] >= $limit['seconds'] ) {
							$buckets[ $key ] = array(
								'count'   => 0,
								'started' => $elapsed,
							);
						}

						++$buckets[ $key ]['count'];

						$label = self::label( $limit, $index, $address, $request['form'], $elapsed );

						$this->assertSame(
							$buckets[ $key ]['count'] >= $limit['max'],
							DuplicateDetector::is_rate_limited( $presented ),
							'Verdict. ' . $label
						);

						// Every address, not only the one just presented: a request
						// must leave the others' counters exactly as they were.
						foreach ( $addresses as $other ) {
							$this->assertSame(
								self::expected_hits( $buckets, self::bucket_key( $other ), $elapsed, $limit['seconds'] ),
								DuplicateDetector::hits( $other ),
								'Counter held for ' . $other . '. ' . $label
							);
						}
					}

					$this->assertSame(
						$before,
						$this->row_counts(),
						'Counting requests should write no Enquiry Store row.'
					);
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: the addresses in play, the limit in force, and the run of
	 * requests.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'addresses' => \Eris\Generators::vector( self::ADDRESSES, Generators::email() ),
				'limit'     => self::limit(),
				'requests'  => self::requests(),
			)
		);
	}

	/**
	 * A rate limit as `[ max, seconds ]`.
	 *
	 * `null` leaves the filter off altogether, which is how the default of 6 per
	 * 900 seconds is exercised as a default rather than as a filtered value
	 * (Requirement 4.7). Both halves are positive, so no fallback is in play and
	 * the limit in force is the one drawn.
	 *
	 * @return \Eris\Generator
	 */
	protected static function limit() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( null ),
			\Eris\Generators::tuple(
				\Eris\Generators::choose( 1, 8 ),
				\Eris\Generators::elements( array( 30, 60, 120, 900, 3600 ) )
			)
		);
	}

	/**
	 * A run of 1 to self::REQUESTS_MAX requests.
	 *
	 * @return \Eris\Generator
	 */
	protected static function requests() {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 1, self::REQUESTS_MAX ),
			static function ( $count ) {
				return \Eris\Generators::vector( (int) $count, self::request() );
			}
		);
	}

	/**
	 * One request: which address it carries, in what form, and how long after its
	 * predecessor it arrives.
	 *
	 * @return \Eris\Generator
	 */
	protected static function request() {
		return \Eris\Generators::associative(
			array(
				'who'    => \Eris\Generators::choose( 0, self::ADDRESSES - 1 ),
				'form'   => \Eris\Generators::elements( self::FORMS ),
				'gap'    => \Eris\Generators::elements( self::GAP_RELATIONS ),
				'jitter' => \Eris\Generators::choose( 0, 7200 ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * The case, resolved
	 * ------------------------------------------------------------------ */

	/**
	 * Install the case's rate limit and report the values in force.
	 *
	 * @param array|null $limit Filtered `[ max, seconds ]`, or null to leave the
	 *                          filter off and take the default.
	 * @return array{max:int,seconds:int}
	 */
	private function apply_limit( $limit ) {
		remove_all_filters( DuplicateDetector::RATE_EMAIL_FILTER );

		if ( null === $limit ) {
			return array(
				'max'     => DuplicateDetector::DEFAULT_RATE_MAX,
				'seconds' => DuplicateDetector::DEFAULT_RATE_WINDOW,
			);
		}

		$pair = array( (int) $limit[0], (int) $limit[1] );

		add_filter(
			DuplicateDetector::RATE_EMAIL_FILTER,
			static function () use ( $pair ) {
				return $pair;
			}
		);

		return array(
			'max'     => $pair[0],
			'seconds' => $pair[1],
		);
	}

	/**
	 * How long after its predecessor a request arrives.
	 *
	 * @param string $relation One of self::GAP_RELATIONS.
	 * @param array  $limit    Limit in force.
	 * @param int    $jitter   Generated spread.
	 * @return int Seconds, never negative.
	 */
	private static function gap( $relation, array $limit, $jitter ) {
		$seconds = (int) $limit['seconds'];

		switch ( $relation ) {
			case 'moments':
				return $jitter % 6;

			case 'just_inside':
				return max( 0, $seconds - 1 );

			case 'on_the_boundary':
				return $seconds;

			case 'just_outside':
				return $seconds + 1;

			case 'anywhere':
				return $jitter % ( ( 2 * $seconds ) + 2 );

			default:
				return 0;
		}
	}

	/**
	 * The form the address arrives in. Every one of them is the same address.
	 *
	 * @param string $email Address drawn for this iteration.
	 * @param string $form  One of self::FORMS.
	 * @return string
	 */
	private static function presented( $email, $form ) {
		switch ( $form ) {
			case 'padded':
				return "  \t" . $email . " \n";

			case 'upper':
				return strtoupper( $email );

			case 'capitalised':
				return ucfirst( $email );

			default:
				return $email;
		}
	}

	/**
	 * The counting identity of a submitted address, as the requirement reads it:
	 * the same address however the sending form spaced or cased it.
	 *
	 * @param string $email Submitted address.
	 * @return string
	 */
	private static function bucket_key( $email ) {
		return strtolower( trim( (string) $email ) );
	}

	/**
	 * The count an address should be carrying, from the oracle's buckets.
	 *
	 * A bucket whose window has elapsed reads as empty, which is the reset half of
	 * the property.
	 *
	 * @param array  $buckets Oracle buckets, keyed by counting identity.
	 * @param string $key     Counting identity to read.
	 * @param int    $now     Seconds since the run began.
	 * @param int    $seconds Rate window in force.
	 * @return int
	 */
	private static function expected_hits( array $buckets, $key, $now, $seconds ) {
		if ( ! isset( $buckets[ $key ] ) ) {
			return 0;
		}

		if ( $now - $buckets[ $key ]['started'] >= (int) $seconds ) {
			return 0;
		}

		return (int) $buckets[ $key ]['count'];
	}

	/**
	 * A one-line description of the request, for failure messages.
	 *
	 * @param array  $limit   Limit in force.
	 * @param int    $index   Zero-based position in the run.
	 * @param string $address Address the request carried.
	 * @param string $form    Form it arrived in.
	 * @param int    $elapsed Seconds since the run began.
	 * @return string
	 */
	private static function label( array $limit, $index, $address, $form, $elapsed ) {
		return sprintf(
			'[request %d, %s as %s, at +%ds, limit %d per %ds]',
			$index + 1,
			$address,
			$form,
			$elapsed,
			$limit['max'],
			$limit['seconds']
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Start the iteration with empty counters for these addresses, and register
	 * them for cleanup.
	 *
	 * @param array $addresses Addresses drawn for this iteration.
	 * @return string[]
	 */
	private function counted( array $addresses ) {
		$addresses = array_values( array_map( 'strval', $addresses ) );

		$this->counted = array_values( array_unique( array_merge( $this->counted, $addresses ) ) );

		$this->forget( $addresses );

		return $addresses;
	}

	/**
	 * Discard the request counts held for a set of addresses.
	 *
	 * @param array $addresses Addresses to forget.
	 * @return void
	 */
	private function forget( array $addresses ) {
		foreach ( $addresses as $address ) {
			DuplicateDetector::reset( $address );
		}
	}

	/**
	 * One enquiry and one rejection row, so the row counts this property watches
	 * are non-zero and a stray write shows up as a change rather than as a zero
	 * matching a zero.
	 *
	 * @return void
	 */
	private function seed_existing_rows() {
		$now = Clock::mysql();

		$id = EnquiryStore::create(
			array(
				'first_name'        => 'Ada',
				'last_name'         => 'Lovelace',
				'email'             => 'seeded@example.com',
				'status'            => 'new',
				'created_at'        => $now,
				'updated_at'        => $now,
				'status_changed_at' => $now,
				'source'            => 'webhook:fixture',
			),
			array( Generators::date_at( 30 ) ),
			array( 'event_type' => array( 'wedding' ) ),
			array( 'seeded' => true )
		);

		$this->assertNotWPError( $id, 'Seeding an enquiry should succeed.' );

		$this->assertGreaterThan(
			0,
			EnquiryStore::record_rejection( array( 'seeded' => true ), 'validation' ),
			'Seeding a rejection row should succeed.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The row count of every Enquiry Store table.
	 *
	 * @return array<string,int>
	 */
	private function row_counts() {
		global $wpdb;

		$counts = array();

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table          = Schema::table( $key );
			$counts[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		return $counts;
	}

	/**
	 * Drop every Enquiry Store table at the test prefix.
	 *
	 * @return void
	 */
	private function drop_tables() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
	}
}
