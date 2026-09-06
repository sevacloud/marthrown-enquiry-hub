<?php
/**
 * Property 22: Auto-closure closes exactly the overdue settled enquiries.
 *
 * Feature: enquiry-data-layer, Property 22: For any population of enquiries with
 * arbitrary statuses drawn from all six — including `quoted` at arbitrary ages,
 * well beyond the closure interval — and arbitrary `status_changed_at` values,
 * any closure interval, and any run time, a run of the auto-close job sets
 * status to `closed` exactly for those enquiries whose status is `converted` or
 * `lost` and whose `status_changed_at` is strictly more than the interval before
 * the run time, leaves every other enquiry's status and `status_changed_at`
 * unchanged, and records one history entry of type `auto_closed` attributed to
 * the system for each enquiry it closes. The settled set is `converted` and
 * `lost` alone, so an enquiry holding `new`, `contacted` or `quoted` is never
 * closed by this job at any age.
 *
 * **Validates: Requirements 7.12, 8.3, 8.5, 8.6, 8.7, 8.8, 8.10**
 *
 * Six things about how the property is instantiated are worth stating plainly:
 *
 * - Each enquiry's `status_changed_at` is generated as an offset from the
 *   *cutoff*, not from the run time, and the offsets 0, -1 and +1 second are all
 *   reachable. That is what puts the boundary Requirements 8.6 and 8.7 turn on
 *   under test on most iterations: an enquiry that has held a settled status for
 *   exactly the interval sits on the cutoff and must survive the run, and one
 *   that has held it a single second longer must not.
 * - The cutoff the test expects is computed here, from the run time and the
 *   interval, rather than read back from `AutoCloseJob::cutoff()`. A job that
 *   measured the interval from the wrong end, or measured it in the wrong units,
 *   would otherwise agree with the test by construction.
 * - The interval is supplied through the `meh_auto_close_days` filter named by
 *   Requirement 8.10, by its literal name rather than through the class
 *   constant, and the generated values include 0 and the 7-day default. A job
 *   that ignored the filter would pass only on the iterations that happened to
 *   draw 7.
 * - Statuses are drawn from all six, so a `quoted` enquiry forty days stale, and
 *   an already-`closed` one, are both ordinary members of the population. Either
 *   one closing, or having its settlement clock touched, is a failure
 *   (Requirement 7.12).
 * - A WordPress user is authenticated for the run. Requirement 8.5 is about
 *   attribution, and attribution to the system is only observable when there is
 *   a user the entry could have been attributed to instead: a cron run triggered
 *   by an administrator's page load must still record `actor_id` 0.
 * - Untouched enquiries are asserted to hold *no* history at all, not merely no
 *   `auto_closed` entry, because the fixture writes none. So a job that recorded
 *   a `status_changed` entry for an enquiry it decided against closing fails
 *   here too.
 *
 * The population is drawn unshrunk, for the reason given on `self::unshrunk()`.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix, because `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\AutoCloseJob;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class AutoCloseSelectionPropertyTest
 */
class AutoCloseSelectionPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehac_';

	/**
	 * The filter carrying the closure interval (Requirement 8.10).
	 *
	 * Named literally rather than read from `AutoCloseJob::INTERVAL_FILTER`, so
	 * the filter the requirement names is the one under test.
	 */
	const INTERVAL_FILTER = 'meh_auto_close_days';

	/**
	 * The history entry type an automatic closure appends (Requirement 8.5).
	 */
	const AUTO_TYPE = 'auto_closed';

	/**
	 * Attribution an automatic closure must carry (Requirements 8.5, 11.5).
	 */
	const SYSTEM_ACTOR = 0;

	/**
	 * The six recognised statuses, written out longhand rather than read from
	 * `Lifecycle::STATUSES`, so a status quietly dropped from the code under test
	 * narrows the code rather than the generator.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'new', 'contacted', 'quoted', 'converted', 'lost', 'closed' );

	/**
	 * The settled statuses, likewise longhand (Requirement 7.12).
	 *
	 * @var string[]
	 */
	const SETTLED = array( 'converted', 'lost' );

	/**
	 * The instant every generated run time is offset from.
	 */
	const BASE_RUN = '2025-01-15 00:00:00';

	/**
	 * Most enquiries one population holds.
	 *
	 * Small on purpose: the interesting variable is the mix of statuses and
	 * dwells, and four records already reach every mix that matters — closed and
	 * spared, settled and active, either side of the cutoff and on it — while
	 * leaving 100 iterations affordable against a real database.
	 */
	const MAX_ENQUIRIES = 4;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The closure interval the filter reports for the current iteration.
	 *
	 * @var int
	 */
	private $days = 7;

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
		require_once MEH_INCLUDES_DIR . 'class-history-recorder.php';
		require_once MEH_INCLUDES_DIR . 'class-lifecycle.php';
		require_once MEH_INCLUDES_DIR . 'class-auto-close-job.php';
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

		add_filter( self::INTERVAL_FILTER, array( $this, 'interval' ) );

		// Requirement 8.5: a run that happens while an administrator is logged in
		// must still attribute its closures to the system.
		wp_set_current_user( 1 );
	}

	public function tear_down() {
		global $wpdb;

		wp_set_current_user( 0 );

		remove_filter( self::INTERVAL_FILTER, array( $this, 'interval' ) );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * The closure interval for the current iteration (Requirement 8.10).
	 *
	 * @param mixed $days Default the job offers.
	 * @return int
	 */
	public function interval( $days = null ) {
		unset( $days );

		return (int) $this->days;
	}

	/**
	 * Feature: enquiry-data-layer, Property 22: Auto-closure closes exactly the
	 * overdue settled enquiries.
	 *
	 * **Validates: Requirements 7.12, 8.3, 8.5, 8.6, 8.7, 8.8, 8.10**
	 *
	 * @eris-shrink 10
	 */
	public function test_auto_closure_closes_exactly_the_overdue_settled_enquiries() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $scenario ) {
					$this->clear();

					$this->days = (int) $scenario['days'];

					// The run instant, as the frozen clock reports it, is the one
					// the job's own timestamps are measured against.
					$this->assertTrue(
						Clock::freeze( self::run_time( $scenario['run'] ) ),
						'The clock should freeze under the test harness.'
					);

					$run_at = Clock::mysql();
					$cutoff = self::cutoff( $run_at, $this->days );

					$seeded   = $this->seed_population( $scenario['population'], $cutoff );
					$expected = self::overdue( $seeded );

					// Requirements 8.3, 8.8: exactly the overdue settled
					// enquiries, and zero when none of them is.
					$closed = AutoCloseJob::run();

					$this->assertSame(
						count( $expected ),
						$closed,
						sprintf(
							'The run should report one closure per overdue settled enquiry (cutoff %s, %d days).',
							$cutoff,
							$this->days
						)
					);

					foreach ( $seeded as $record ) {
						if ( in_array( (int) $record['id'], $expected, true ) ) {
							$this->assert_closed( $record, $run_at );
							continue;
						}

						$this->assert_untouched( $record, $cutoff );
					}
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Everything the job owes an enquiry it closed (Requirements 8.3, 8.5).
	 *
	 * @param array  $record Seeded record, as returned by seed_population().
	 * @param string $run_at The run instant.
	 * @return void
	 */
	private function assert_closed( array $record, $run_at ) {
		$context = sprintf(
			'enquiry %d, seeded %s at %s',
			$record['id'],
			$record['status'],
			$record['status_changed_at']
		);

		$after = EnquiryStore::find( $record['id'] );

		$this->assertIsArray( $after, 'A closed enquiry should still be readable: ' . $context );
		$this->assertSame( 'closed', $after['status'], 'An overdue settled enquiry should be closed: ' . $context );
		$this->assertSame(
			$run_at,
			$after['status_changed_at'],
			'A closure should stamp the run time on the settlement clock: ' . $context
		);

		$entries = HistoryRecorder::for_enquiry( $record['id'] );
		$auto    = self::of_type( $entries, self::AUTO_TYPE );

		// Requirement 8.5: one entry, of that type, attributed to the system and
		// not to the administrator who happens to be logged in.
		$this->assertCount( 1, $auto, 'A closure should append one auto_closed entry: ' . $context );
		$this->assertSame(
			self::SYSTEM_ACTOR,
			$auto[0]['actor_id'],
			'An auto_closed entry should be attributed to the system: ' . $context
		);
		$this->assertSame( (int) $record['id'], $auto[0]['enquiry_id'] );
	}

	/**
	 * Everything the job owes an enquiry it left alone (Requirements 7.12, 8.6,
	 * 8.7, 8.8).
	 *
	 * @param array  $record Seeded record, as returned by seed_population().
	 * @param string $cutoff The cutoff the run used.
	 * @return void
	 */
	private function assert_untouched( array $record, $cutoff ) {
		$context = sprintf(
			'enquiry %d, seeded %s at %s against cutoff %s',
			$record['id'],
			$record['status'],
			$record['status_changed_at'],
			$cutoff
		);

		$after = EnquiryStore::find( $record['id'] );

		$this->assertIsArray( $after, 'A spared enquiry should still be readable: ' . $context );
		$this->assertSame( $record['status'], $after['status'], 'A spared status should be unchanged: ' . $context );
		$this->assertSame(
			$record['status_changed_at'],
			$after['status_changed_at'],
			'A spared settlement clock should be unchanged: ' . $context
		);

		// The fixture writes no history, so a spared enquiry holding any entry at
		// all means the job wrote something it should not have.
		$this->assertSame(
			array(),
			HistoryRecorder::for_enquiry( $record['id'] ),
			'A spared enquiry should hold no history entry: ' . $context
		);
	}

	/* ---------------------------------------------------------------------
	 * The reference implementation
	 * ------------------------------------------------------------------ */

	/**
	 * The identifiers a correct run closes: settled, and settled strictly before
	 * the cutoff (Requirements 7.12, 8.3, 8.6, 8.7).
	 *
	 * Derived from the seeded values rather than from the generated offsets, so
	 * the expectation follows what is actually stored.
	 *
	 * @param array $seeded Seeded records.
	 * @return int[]
	 */
	private static function overdue( array $seeded ) {
		$overdue = array();

		foreach ( $seeded as $record ) {
			if ( ! in_array( $record['status'], self::SETTLED, true ) ) {
				continue;
			}

			// Both values are `Y-m-d H:i:s` in the site timezone, so a string
			// comparison is the DATETIME comparison.
			if ( strcmp( $record['status_changed_at'], $record['cutoff'] ) < 0 ) {
				$overdue[] = (int) $record['id'];
			}
		}

		return $overdue;
	}

	/**
	 * The cutoff a run at a given instant with a given interval uses.
	 *
	 * A calendar-day interval in the site timezone, which is what "more than N
	 * days before the run time" means (Requirement 8.3).
	 *
	 * @param string $run_at Run instant, MySQL `DATETIME`.
	 * @param int    $days   Closure interval in days.
	 * @return string MySQL `DATETIME`.
	 */
	private static function cutoff( $run_at, $days ) {
		$run = new \DateTimeImmutable( (string) $run_at, Clock::timezone() );

		return $run->modify( sprintf( '-%d days', max( 0, (int) $days ) ) )
			->format( Clock::MYSQL_FORMAT );
	}

	/**
	 * A run instant this many days and seconds after the base instant.
	 *
	 * The arithmetic is done on the wall clock rather than on an absolute
	 * instant, so every generated run time is a plain `Y-m-d H:i:s` value and no
	 * offset can land the run inside an hour the site timezone skips.
	 *
	 * @param array{days:int,seconds:int} $offset Offset as generated.
	 * @return string MySQL `DATETIME`.
	 */
	private static function run_time( array $offset ) {
		$base = new \DateTimeImmutable( self::BASE_RUN, new \DateTimeZone( 'UTC' ) );

		return $base->modify( sprintf( '+%d days', max( 0, (int) $offset['days'] ) ) )
			->modify( sprintf( '+%d seconds', max( 0, (int) $offset['seconds'] ) ) )
			->format( Clock::MYSQL_FORMAT );
	}

	/**
	 * A `DATETIME` this many seconds either side of another, on the wall clock.
	 *
	 * @param string $datetime Instant to shift, MySQL `DATETIME`.
	 * @param int    $seconds  Seconds to add; negative subtracts.
	 * @return string MySQL `DATETIME`.
	 */
	private static function shift( $datetime, $seconds ) {
		$at = new \DateTimeImmutable( (string) $datetime, new \DateTimeZone( 'UTC' ) );

		return $at->modify( sprintf( '%+d seconds', (int) $seconds ) )->format( Clock::MYSQL_FORMAT );
	}

	/**
	 * The history entries of one type, in order.
	 *
	 * @param array  $entries Hydrated history entries.
	 * @param string $type    Entry type to keep.
	 * @return array
	 */
	private static function of_type( array $entries, $type ) {
		return array_values(
			array_filter(
				$entries,
				function ( array $entry ) use ( $type ) {
					return (string) $type === $entry['entry_type'];
				}
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One whole scenario: an interval, a run time, and a population.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'days'       => self::days(),
				'run'        => self::run_offset(),
				'population' => self::population(),
			)
		);
	}

	/**
	 * A closure interval in days (Requirement 8.10).
	 *
	 * 0 and the 7-day default are both reachable, 0 being the interval that makes
	 * the cutoff the run time itself. The 7 is written out rather than read from
	 * `AutoCloseJob::DEFAULT_DAYS`, so the default the requirement names is the
	 * one exercised.
	 *
	 * @return \Eris\Generator
	 */
	protected static function days() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( 0 ),
			\Eris\Generators::constant( 7 ),
			\Eris\Generators::choose( 0, 60 )
		);
	}

	/**
	 * How far after the base instant the run happens.
	 *
	 * @return \Eris\Generator
	 */
	protected static function run_offset() {
		return \Eris\Generators::associative(
			array(
				'days'    => \Eris\Generators::choose( 0, 500 ),
				'seconds' => \Eris\Generators::choose( 0, DAY_IN_SECONDS - 1 ),
			)
		);
	}

	/**
	 * A population of 0 to MAX_ENQUIRIES enquiries.
	 *
	 * The empty population is reachable, and is the plainest case of
	 * Requirement 8.8.
	 *
	 * @return \Eris\Generator
	 */
	protected static function population() {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 0, self::MAX_ENQUIRIES ),
			function ( $count ) {
				if ( $count < 1 ) {
					return \Eris\Generators::constant( array() );
				}

				return \Eris\Generators::vector( (int) $count, self::record() );
			}
		);
	}

	/**
	 * One enquiry: a status drawn from all six, and a dwell expressed as an
	 * offset in seconds from the cutoff.
	 *
	 * The offset reaches both boundaries Requirements 8.6 and 8.7 name — exactly
	 * on the cutoff, and one second either side of it — and reaches well beyond
	 * the interval in both directions, so a `quoted` enquiry forty days stale is
	 * an ordinary member of the population.
	 *
	 * @return \Eris\Generator
	 */
	protected static function record() {
		return \Eris\Generators::associative(
			array(
				'status' => \Eris\Generators::elements( self::STATUSES ),
				'offset' => \Eris\Generators::oneOf(
					\Eris\Generators::constant( 0 ),
					\Eris\Generators::constant( -1 ),
					\Eris\Generators::constant( 1 ),
					\Eris\Generators::choose( -40 * DAY_IN_SECONDS, DAY_IN_SECONDS )
				),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step is exponential in
	 * the number of generated values. A scenario holds an interval, two run
	 * offsets and up to four records of two drawn values each, which puts that
	 * product beyond what fits in memory: a failing iteration reports an
	 * out-of-memory fatal instead of the counterexample. The `@eris-shrink` time
	 * limit does not help, because the explosion happens inside a single shrink
	 * call.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the scenario as generated, together with the assertion's
	 * own expected-versus-actual diff and the `ERIS_SEED` line that reproduces
	 * the run exactly.
	 *
	 * @param \Eris\Generator $generator Generator to draw from.
	 * @return \Eris\Generator
	 */
	protected static function unshrunk( \Eris\Generator $generator ) {
		return \Eris\Generators::bind(
			$generator,
			function ( $drawn ) {
				return \Eris\Generators::constant( $drawn );
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write a whole population, placing each enquiry's `status_changed_at` at its
	 * generated offset from the cutoff.
	 *
	 * `created_at` and `updated_at` are seeded to the same instant, so nothing in
	 * the row depends on the frozen clock and a touched `updated_at` is visible.
	 *
	 * @param array  $population Records as generated.
	 * @param string $cutoff     The cutoff the run will use.
	 * @return array<int,array{id:int,status:string,status_changed_at:string,cutoff:string}>
	 */
	private function seed_population( array $population, $cutoff ) {
		$seeded = array();

		foreach ( $population as $index => $record ) {
			$status  = (string) $record['status'];
			$settled = self::shift( $cutoff, (int) $record['offset'] );

			$id = EnquiryStore::create(
				array(
					'first_name'        => 'Ada',
					'last_name'         => 'Lovelace',
					'email'             => sprintf( 'ada+%d@example.com', $index ),
					'status'            => $status,
					'created_at'        => $settled,
					'updated_at'        => $settled,
					'status_changed_at' => $settled,
					'source'            => 'webhook:fixture',
				),
				array( '2025-08-16' )
			);

			$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

			$seeded[] = array(
				'id'                => (int) $id,
				'status'            => $status,
				'status_changed_at' => $settled,
				'cutoff'            => (string) $cutoff,
			);
		}

		return $seeded;
	}

	/**
	 * Empty the tables this property reads, between iterations.
	 *
	 * `DELETE` rather than `TRUNCATE`, so identifiers keep climbing and a stale
	 * identifier can never be mistaken for a fresh one.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array( 'history', 'terms', 'dates', 'enquiries' ) as $key ) {
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
