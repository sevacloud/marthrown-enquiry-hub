<?php
/**
 * Property 14: Duplicate detection is exactly email plus date set within the
 * window.
 *
 * Feature: enquiry-data-layer, Property 14: For any existing enquiry, any second
 * submission arriving as an intake webhook request, any duplicate window value,
 * and any elapsed time between them, the submission is reported as a duplicate
 * exactly when its email equals the existing enquiry's email, its candidate date
 * ranges equal the existing enquiry's candidate ranges as a set, and the elapsed
 * time is less than the window; when reported, no enquiry is created and one
 * rejected intake attempt with reason `duplicate` referencing the existing
 * enquiry identifier is recorded; otherwise a new enquiry is created.
 *
 * **Validates: Requirements 4.2, 4.3, 4.4, 4.5**
 *
 * How the property is instantiated, and why:
 *
 * - **The claim is a three-way conjunction, so the generator quantifies over
 *   each conjunct independently.** The submitted email is the same address, the
 *   same address surrounded by whitespace, or a different address; the submitted
 *   range set is equal to the stored one (as written, rotated, or with a repeat),
 *   or unequal (a subset, a superset, one range swapped, one range's end day moved
 *   while its start stays put, or disjoint); the window
 *   is the unfiltered default of 900 seconds, a filtered value, or 0; and the
 *   elapsed time sits at 0, one second inside the window, exactly on it, one
 *   second past it, or anywhere in twice its span. The oracle is the conjunction
 *   itself, written out independently of the SQL under test, so a `HAVING` clause
 *   that accidentally matched a subset would fail rather than agree with itself.
 * - **It runs against real tables.** Set equality is decided by a `GROUP BY` and
 *   two `COUNT( DISTINCT … )` terms over a key built from both bounds inside one
 *   statement; no amount of PHP-level inspection would show whether that statement
 *   admits a superset, or whether it keys on the start day alone.
 * - **Every iteration seeds decoys.** An enquiry holding the same ranges under
 *   another address, one holding the same address over disjoint ranges, and one
 *   holding both but created long outside the window. Each is a row the
 *   statement must not return, and without them a detector matching on email
 *   alone — or on nothing but recency — would still pass.
 * - **The identifier matters, not just the verdict** (Requirement 4.3). The
 *   rejection row references the existing enquiry, so the property asserts
 *   *which* identifier comes back, and half the iterations seed an older twin
 *   holding the same address and the same ranges so that "the most recent match"
 *   is a claim under test rather than an accident of there being one match.
 * - **The `otherwise` half is asserted where it can be.** `DuplicateDetector`
 *   creates nothing and rejects nothing — it answers a question — so this test
 *   asserts that the call writes no row at all, and that a submission it
 *   reported as *not* a duplicate is storable and immediately becomes the
 *   duplicate reference for a resubmission of itself, which is Requirement 4.5
 *   in one step. The remaining consequences of the verdict, the absent enquiry
 *   and the single `duplicate` rejection row, belong to `IntakeHandler` and are
 *   asserted by Property 13's test on the intake path.
 *
 * Case is deliberately not varied. Requirement 4.2 names "the same `email`
 * value", and the collation deciding whether `Ada@Example.com` is that same
 * value is a property of the database rather than of this component, so it is
 * not what this property pins down.
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
 * Class DuplicateDetectionPropertyTest
 */
class DuplicateDetectionPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehdp_';

	/**
	 * The instant every iteration treats as "now".
	 */
	const NOW = '2025-06-01 12:00:00';

	/**
	 * Duplicate window values, in seconds.
	 *
	 * `null` leaves the filter off altogether, which is how the 900-second
	 * default of Requirement 4.4 is exercised as a default rather than as a
	 * filtered value. `0` switches detection off, the only sensible reading of a
	 * filter asking for no window at all.
	 *
	 * @var array
	 */
	const WINDOWS = array( null, 0, 1, 60, 900, 3600 );

	/**
	 * How the submitted address relates to the stored one.
	 *
	 * @var string[]
	 */
	const EMAIL_RELATIONS = array( 'same', 'padded', 'different' );

	/**
	 * How the submitted range set relates to the stored one.
	 *
	 * The first three are set-equal and the last five are not, which is the whole
	 * of what Requirement 4.2 turns on. `end_shifted` is the relation ranges add
	 * over bare dates: the same first day, a different last one, which is a
	 * different enquiry about different days however alike the two look.
	 *
	 * @var string[]
	 */
	const RANGE_RELATIONS = array( 'same', 'rotated', 'repeated', 'subset', 'superset', 'swapped', 'end_shifted', 'disjoint' );

	/**
	 * Range relations that are set-equal to the stored range set.
	 *
	 * @var string[]
	 */
	const EQUAL_RANGE_RELATIONS = array( 'same', 'rotated', 'repeated' );

	/**
	 * Where the stored enquiry's creation time sits relative to the window.
	 *
	 * @var string[]
	 */
	const ELAPSED_RELATIONS = array( 'now', 'just_inside', 'on_the_boundary', 'just_outside', 'well_outside', 'anywhere' );

	/**
	 * Most candidate date ranges an enquiry may hold (Requirement 1.2).
	 */
	const RANGES_MAX = 3;

	/**
	 * Day offset of the ranges a submission adds or swaps in, comfortably beyond
	 * the widest span the candidate range generator can produce.
	 */
	const SUBMITTED_OFFSET = 900;

	/**
	 * Day offset of the decoy enquiry's ranges.
	 *
	 * A family of its own, so the decoy holding the same address over other ranges
	 * can never coincide with the range set a `disjoint` submission carries — which
	 * would make that submission a genuine duplicate of the decoy and turn the
	 * oracle into a lie.
	 */
	const DECOY_OFFSET = 1900;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

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
	}

	public function tear_down() {
		global $wpdb;

		remove_all_filters( DuplicateDetector::WINDOW_FILTER );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 14: Duplicate detection is exactly
	 * email plus range set within the window.
	 *
	 * **Validates: Requirements 4.2, 4.3, 4.4, 4.5**
	 *
	 * @eris-shrink 10
	 */
	public function test_duplicate_detection_is_email_plus_range_set_within_the_window() {
		$this->limitTo( Iterations::count( 70 ) )
			->forAll( self::scenario() )
			->then(
				function ( array $case ) {
					$this->clear();

					$window = $this->apply_window( $case['window'] );
					$stored = self::normalise( $case['ranges'] );

					$submitted_email  = self::submitted_email( (string) $case['email'], $case['email_relation'] );
					$submitted_ranges = self::submitted_ranges( $case['ranges'], $case['range_relation'], (int) $case['rotation'] );

					$elapsed = self::elapsed( $window, $case['elapsed_relation'], (int) $case['jitter'] );

					$target = $this->seed( (string) $case['email'], $case['ranges'], $elapsed );
					$this->seed_decoys( (string) $case['email'], $case['ranges'], $window );

					if ( $case['twin_gap'] > 0 ) {
						// An older enquiry holding the same identity, so "the most
						// recent match" is a claim rather than a coincidence.
						$this->seed( (string) $case['email'], $case['ranges'], $elapsed + (int) $case['twin_gap'] );
					}

					$expected = self::equal_emails( (string) $case['email'], $submitted_email )
						&& self::normalise( $submitted_ranges ) === $stored
						&& $window > 0
						&& $elapsed < $window;

					$label = self::label( $case, $window, $elapsed );

					$before = $this->row_counts();
					$found  = DuplicateDetector::find_duplicate( $submitted_email, $submitted_ranges );

					// Asking the question stores nothing: the rejection row is the
					// handler's to write, on the handler's terms.
					$this->assertSame( $before, $this->row_counts(), 'Detection should write no row. ' . $label );

					if ( $expected ) {
						$this->assertSame(
							$target,
							$found,
							'The most recent matching enquiry should be reported. ' . $label
						);

						return;
					}

					$this->assertSame( 0, $found, 'The submission should not be a duplicate. ' . $label );

					$this->assert_storable_as_a_new_enquiry( $submitted_email, $submitted_ranges, $window, $label );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: a stored enquiry, a submission related to it, a window, and how
	 * long ago the stored enquiry was created.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'email'            => Generators::email(),
				'ranges'           => Generators::candidate_ranges( 1, self::RANGES_MAX ),
				'email_relation'   => \Eris\Generators::elements( self::EMAIL_RELATIONS ),
				'range_relation'   => \Eris\Generators::elements( self::RANGE_RELATIONS ),
				'window'           => \Eris\Generators::elements( self::WINDOWS ),
				'elapsed_relation' => \Eris\Generators::elements( self::ELAPSED_RELATIONS ),
				'jitter'           => \Eris\Generators::choose( 0, 7200 ),
				'rotation'         => \Eris\Generators::choose( 0, self::RANGES_MAX ),
				// Zero means "no twin", so half the iterations have a single match.
				'twin_gap'         => \Eris\Generators::choose( 0, 600 ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * The case, resolved
	 * ------------------------------------------------------------------ */

	/**
	 * Install the case's duplicate window and report the value in force.
	 *
	 * @param int|null $window Filtered window in seconds, or null to leave the
	 *                         filter off and take the default.
	 * @return int Seconds; 0 means detection is off.
	 */
	private function apply_window( $window ) {
		remove_all_filters( DuplicateDetector::WINDOW_FILTER );

		if ( null === $window ) {
			return DuplicateDetector::DEFAULT_WINDOW;
		}

		$window = (int) $window;

		add_filter(
			DuplicateDetector::WINDOW_FILTER,
			static function () use ( $window ) {
				return $window;
			}
		);

		return $window > 0 ? $window : 0;
	}

	/**
	 * The address the submission carries.
	 *
	 * @param string $email    Stored address.
	 * @param string $relation One of self::EMAIL_RELATIONS.
	 * @return string
	 */
	private static function submitted_email( $email, $relation ) {
		if ( 'padded' === $relation ) {
			return "  \t" . $email . " \n";
		}

		if ( 'different' === $relation ) {
			return self::distinct_email( $email, 'submitted' );
		}

		return $email;
	}

	/**
	 * The candidate range set the submission carries.
	 *
	 * @param array  $ranges   Stored candidate ranges, in rank order and distinct.
	 * @param string $relation One of self::RANGE_RELATIONS.
	 * @param int    $rotation How far to rotate, for the `rotated` relation.
	 * @return array
	 */
	private static function submitted_ranges( array $ranges, $relation, $rotation ) {
		switch ( $relation ) {
			case 'rotated':
				$offset = count( $ranges ) > 0 ? (int) $rotation % count( $ranges ) : 0;

				return array_merge( array_slice( $ranges, $offset ), array_slice( $ranges, 0, $offset ) );

			case 'repeated':
				// Two identical candidate ranges are one candidate range.
				return array_merge( $ranges, array( $ranges[0], end( $ranges ) ) );

			case 'subset':
				// One range short, and the empty set when the stored enquiry holds
				// a single range — both unequal to the stored set.
				return array_slice( $ranges, 1 );

			case 'superset':
				return array_merge( $ranges, array( self::range_at( self::SUBMITTED_OFFSET, 1 ) ) );

			case 'swapped':
				return array_merge( array_slice( $ranges, 1 ), array( self::range_at( self::SUBMITTED_OFFSET, 1 ) ) );

			case 'end_shifted':
				// The last range's first day kept, and a last day well beyond it: a
				// submission a detector keying on the start alone would wrongly call
				// a duplicate.
				$shifted = $ranges;
				$last    = count( $shifted ) - 1;

				$shifted[ $last ] = array(
					'start' => $shifted[ $last ]['start'],
					'end'   => self::date_at( self::SUBMITTED_OFFSET, 1 ),
				);

				return $shifted;

			case 'disjoint':
				return self::ranges_at( self::SUBMITTED_OFFSET, count( $ranges ) );

			default:
				return $ranges;
		}
	}

	/**
	 * How long before "now" the stored enquiry was created.
	 *
	 * @param int    $window   Window in force, in seconds.
	 * @param string $relation One of self::ELAPSED_RELATIONS.
	 * @param int    $jitter   Generated spread.
	 * @return int Seconds, never negative.
	 */
	private static function elapsed( $window, $relation, $jitter ) {
		switch ( $relation ) {
			case 'just_inside':
				return max( 0, $window - 1 );

			case 'on_the_boundary':
				return $window;

			case 'just_outside':
				return $window + 1;

			case 'well_outside':
				return $window + 1 + $jitter;

			case 'anywhere':
				return $jitter % ( ( 2 * $window ) + 2 );

			default:
				return 0;
		}
	}

	/**
	 * Whether two addresses are the same address, as the store compares them.
	 *
	 * @param string $stored    Stored address.
	 * @param string $submitted Submitted address.
	 * @return bool
	 */
	private static function equal_emails( $stored, $submitted ) {
		return trim( $stored ) === trim( $submitted );
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * A submission reported as not a duplicate is stored as a new enquiry, and is
	 * then itself the enquiry an immediate resubmission duplicates
	 * (Requirement 4.5).
	 *
	 * Skipped where a resubmission could not be a duplicate whatever the
	 * detector did: with detection off, or with no readable candidate range to
	 * form a set from. Skipped too where the submission carries more candidate
	 * ranges than an enquiry may hold, since storing it would be a fixture outside
	 * Requirement 1.2's range rather than a test of anything — which is what the
	 * `superset` relation on an already-full stored set produces.
	 *
	 * @param string $email  Submitted address.
	 * @param array  $ranges Submitted candidate ranges.
	 * @param int    $window Window in force, in seconds.
	 * @param string $label  Case description for failure messages.
	 * @return void
	 */
	private function assert_storable_as_a_new_enquiry( $email, array $ranges, $window, $label ) {
		$normalised = self::normalise( $ranges );
		$count      = count( $normalised );

		if ( $window <= 0 || $count < 1 || $count > self::RANGES_MAX ) {
			return;
		}

		// The trimmed address, because that is what an intake would store: the
		// Validator trims before the store sees the value. Detection is then asked
		// with the address as submitted, padding and all.
		$created = $this->seed( trim( $email ), $ranges, 0 );

		$this->assertSame(
			$created,
			DuplicateDetector::find_duplicate( $email, $ranges ),
			'A stored submission should be the enquiry an immediate resubmission duplicates. ' . $label
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Three enquiries the statement must not return, whatever the case.
	 *
	 * Each fails exactly one conjunct of the duplicate identity, so a detector
	 * dropping that conjunct is caught by one of them.
	 *
	 * @param string $email  Stored address.
	 * @param array  $ranges Stored candidate ranges.
	 * @param int    $window Window in force, in seconds.
	 * @return void
	 */
	private function seed_decoys( $email, array $ranges, $window ) {
		// Same ranges, another enquirer.
		$this->seed( self::distinct_email( $email, 'decoy' ), $ranges, 0 );

		// Same enquirer, ranges that share nothing with the stored set — nor with
		// any set a submission can carry.
		$this->seed( $email, self::ranges_at( self::DECOY_OFFSET, count( $ranges ) ), 0 );

		// Same enquirer and the same ranges, but long out of range.
		$this->seed( $email, $ranges, $window + self::DECOY_OFFSET );
	}

	/**
	 * Store one enquiry a given number of seconds before "now".
	 *
	 * @param string $email       Email address.
	 * @param array  $ranges      Candidate date ranges.
	 * @param int    $seconds_ago Age of the enquiry, in seconds.
	 * @return int Enquiry identifier.
	 */
	private function seed( $email, array $ranges, $seconds_ago ) {
		$created = Clock::mysql( Clock::offset( -1 * (int) $seconds_ago, self::NOW ) );

		$id = EnquiryStore::create(
			array(
				'first_name'        => 'Ada',
				'last_name'         => 'Lovelace',
				'email'             => $email,
				'status'            => 'new',
				'created_at'        => $created,
				'updated_at'        => $created,
				'status_changed_at' => $created,
				'source'            => 'webhook:fixture',
			),
			$ranges,
			array(),
			array( 'seeded' => true )
		);

		$this->assertNotWPError( $id, 'Seeding an enquiry should succeed.' );
		$this->assertGreaterThan( 0, $id, 'A seeded enquiry should get a positive identifier.' );

		return (int) $id;
	}

	/* ---------------------------------------------------------------------
	 * Values
	 * ------------------------------------------------------------------ */

	/**
	 * A range set of `$count` ranges drawn from one offset family.
	 *
	 * @param int $family Day offset the family starts at.
	 * @param int $count  How many ranges; at least one.
	 * @return array[]
	 */
	private static function ranges_at( $family, $count ) {
		$ranges = array();

		for ( $index = 1; $index <= max( 1, (int) $count ); $index++ ) {
			$ranges[] = self::range_at( $family, $index );
		}

		return $ranges;
	}

	/**
	 * One two-day range from an offset family.
	 *
	 * Members of a family are spaced three days apart, so no two of them touch and
	 * a set of them holds as many distinct ranges as its count.
	 *
	 * @param int $family Day offset the family starts at.
	 * @param int $index  Position within the family; 1 is the first.
	 * @return array{start:string,end:string}
	 */
	private static function range_at( $family, $index ) {
		$start = ( 3 * (int) $index ) - 2;

		return array(
			'start' => self::date_at( $family, $start ),
			'end'   => self::date_at( $family, $start + 1 ),
		);
	}

	/**
	 * One date from an offset family.
	 *
	 * @param int $family Day offset the family starts at.
	 * @param int $index  Position within the family; 1 is the first.
	 * @return string Y-m-d.
	 */
	private static function date_at( $family, $index ) {
		return Generators::date_at( (int) $family + (int) $index );
	}

	/**
	 * An address that is not the given one.
	 *
	 * @param string $email Address to differ from.
	 * @param string $tag   Distinguishes one constructed address from another.
	 * @return string
	 */
	private static function distinct_email( $email, $tag ) {
		$candidate = 'meh-' . $tag . '@example.com';
		$suffix    = 0;

		while ( self::equal_emails( $email, $candidate ) ) {
			++$suffix;
			$candidate = 'meh-' . $tag . '-' . $suffix . '@example.com';
		}

		return $candidate;
	}

	/**
	 * Reduce candidate ranges to the sorted set of `start/end` keys the store
	 * holds.
	 *
	 * The oracle's notion of set equality, written independently of the one under
	 * test. Both bounds are part of the key, so two ranges sharing a first day but
	 * not a last one are two members rather than one.
	 *
	 * @param array $ranges Candidate date ranges.
	 * @return string[]
	 */
	private static function normalise( array $ranges ) {
		$set = array();

		foreach ( $ranges as $range ) {
			if ( is_array( $range ) ) {
				$start = trim( (string) $range['start'] );
				$end   = trim( (string) $range['end'] );
			} else {
				$start = trim( (string) $range );
				$end   = $start;
			}

			if ( '' === $start || '' === $end ) {
				continue;
			}

			$key = $start . '/' . $end;

			if ( in_array( $key, $set, true ) ) {
				continue;
			}

			$set[] = $key;
		}

		sort( $set );

		return $set;
	}

	/**
	 * A one-line description of the case, for failure messages.
	 *
	 * @param array $case    The generated case.
	 * @param int   $window  Window in force, in seconds.
	 * @param int   $elapsed Age of the stored enquiry, in seconds.
	 * @return string
	 */
	private static function label( array $case, $window, $elapsed ) {
		return sprintf(
			'[email: %s, ranges: %s (%d stored), window: %d, elapsed: %d, twin gap: %d]',
			$case['email_relation'],
			$case['range_relation'],
			count( $case['ranges'] ),
			$window,
			$elapsed,
			$case['twin_gap']
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
	 * Empty the tables this property writes to, between iterations.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
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
