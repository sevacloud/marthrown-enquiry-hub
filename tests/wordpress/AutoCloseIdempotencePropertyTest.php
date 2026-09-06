<?php
/**
 * Property 23: Auto-closure is idempotent.
 *
 * Feature: enquiry-data-layer, Property 23: For any population of enquiries,
 * running the auto-close job twice with no intervening status change produces
 * the same set of enquiry statuses and the same history entries as a single run.
 *
 * **Validates: Requirements 8.9**
 *
 * Five things about how the property is instantiated are worth stating plainly:
 *
 * - The claim is about what a second run writes, so it runs against real tables.
 *   Whether the second run selected an enquiry it should not have is a question
 *   only the stored rows can answer.
 * - The population quantifies over all six statuses and over settlement times
 *   sitting either side of the cutoff — one second before it, exactly on it, one
 *   second after it, and well clear of it in both directions — so the same
 *   iteration mixes enquiries the first run closes with enquiries it leaves. A
 *   run that closes nothing and a run that closes everything are both reachable.
 * - "Twice" is generated as one to three further runs. Requirement 8.9 is about
 *   the second, but a job that were only idempotent once would satisfy the
 *   letter of it while still writing on the third run, so the property is
 *   asserted after each further run.
 * - The clock is moved strictly forward before each further run while the run
 *   time handed to the job stays put. That separates the two variables: the
 *   cutoff is unchanged, so nothing newly falls due, but the instant a write
 *   would be stamped with is demonstrably different from the first run's. A
 *   second run that re-closed an already-closed enquiry, or appended a second
 *   `auto_closed` entry, therefore shows up as a changed value rather than as an
 *   identical one written twice.
 * - The closure interval is generated and installed on the `meh_auto_close_days`
 *   filter, including 0 — an interval of no days closes the most enquiries it
 *   can, which is the population most likely to expose a second run that writes.
 *
 * The assertions are stronger than the property's letter in two respects. The
 * whole enquiries table, every column of every row, must be byte-identical after
 * each further run, so a touched `updated_at` is as much a failure as a moved
 * `status`. And the history of every enquiry must be identical too, not merely
 * the same length: a second run may not append an entry, and may not rewrite
 * one either.
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
 * Class AutoCloseIdempotencePropertyTest
 */
class AutoCloseIdempotencePropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehaci_';

	/**
	 * The instant every run of the job is asked to treat as now.
	 */
	const RUN_AT = '2025-07-04 14:30:00';

	/**
	 * Most enquiries one population holds.
	 *
	 * Small on purpose: the interesting variable is the mix of statuses and
	 * settlement times, and a population of four already reaches every mix that
	 * matters while leaving 100 iterations affordable against a real database.
	 */
	const MAX_ENQUIRIES = 4;

	/**
	 * Further runs of the job one iteration performs, beyond the first.
	 */
	const MAX_REPEATS = 3;

	/**
	 * The six recognised statuses.
	 *
	 * Written out longhand rather than read from `Lifecycle::STATUSES`, so the
	 * generator's idea of a status is independent of the code under test.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'new', 'contacted', 'quoted', 'converted', 'lost', 'closed' );

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The closure interval the current iteration installs on the filter.
	 *
	 * Given no default here, and set in `set_up()` instead: a default naming
	 * `AutoCloseJob::DEFAULT_DAYS` would have to resolve when PHPUnit builds the
	 * test object, which happens before `wpSetUpBeforeClass()` has loaded the
	 * class that constant belongs to.
	 *
	 * @var int
	 */
	private $days;

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

		$this->days = AutoCloseJob::DEFAULT_DAYS;

		add_filter( AutoCloseJob::INTERVAL_FILTER, array( $this, 'interval' ) );
	}

	public function tear_down() {
		global $wpdb;

		remove_filter( AutoCloseJob::INTERVAL_FILTER, array( $this, 'interval' ) );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * The generated closure interval, as the filter sees it (Requirement 8.10).
	 *
	 * @param int $days Interval the job proposes (unused).
	 * @return int
	 */
	public function interval( $days = null ) {
		unset( $days );

		return $this->days;
	}

	/**
	 * Feature: enquiry-data-layer, Property 23: Auto-closure is idempotent.
	 *
	 * **Validates: Requirements 8.9**
	 *
	 * @eris-shrink 10
	 */
	public function test_auto_closure_is_idempotent() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $scenario ) {
					$this->clear();

					$this->days = (int) $scenario['days'];

					$this->assertTrue(
						Clock::freeze( self::RUN_AT ),
						'The clock should freeze under the test harness.'
					);
					$this->assertSame(
						$this->days,
						AutoCloseJob::days(),
						'The generated interval should be the one the job uses.'
					);

					$ids = $this->seed_population( $scenario['population'], $this->cutoff() );

					$before = $this->statuses();

					// The single run every further run is measured against.
					$closed = AutoCloseJob::run( self::RUN_AT );

					$rows      = $this->snapshot();
					$histories = $this->histories( $ids );
					$after     = $this->statuses();

					$this->assertSame(
						$this->newly_closed( $before, $after ),
						$closed,
						'The first run should report one closure per enquiry it moved to closed.'
					);

					$at = Clock::now();

					foreach ( $scenario['repeats'] as $index => $gap ) {
						/*
						 * Later than the first run, so a write would be stamped
						 * with a different instant, while the run time handed to
						 * the job stays put so the cutoff cannot move and nothing
						 * newly falls due.
						 */
						$at = Clock::offset( $gap, $at );
						$this->assertTrue( Clock::freeze( $at ) );
						$this->assertNotSame(
							self::RUN_AT,
							Clock::mysql(),
							'Each further run should happen at a demonstrably later instant.'
						);

						$this->assert_further_run_writes_nothing(
							AutoCloseJob::run( self::RUN_AT ),
							$rows,
							$histories,
							$ids,
							(int) $index + 2
						);
					}
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Everything a further run has to leave exactly as it found it
	 * (Requirement 8.9).
	 *
	 * @param int   $closed    What the further run reported closing.
	 * @param array $rows      Enquiry rows after the first run.
	 * @param array $histories History per enquiry after the first run.
	 * @param int[] $ids       Every seeded enquiry identifier.
	 * @param int   $attempt   Which run this was, counting from 1.
	 * @return void
	 */
	private function assert_further_run_writes_nothing( $closed, array $rows, array $histories, array $ids, $attempt ) {
		$context = sprintf( 'run %d', $attempt );

		// Nothing is settled and overdue any more: what the first run closed is
		// closed, and `closed` is not a settled status.
		$this->assertSame( 0, $closed, 'A further run should close nothing: ' . $context );

		$this->assertSame(
			$rows,
			$this->snapshot(),
			'A further run should leave every enquiry row byte-identical: ' . $context
		);

		$this->assertSame(
			$histories,
			$this->histories( $ids ),
			'A further run should append and rewrite no history entry: ' . $context
		);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One scenario: a closure interval, a population, and the further runs.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'days'       => self::days(),
				'population' => self::population(),
				'repeats'    => \Eris\Generators::bind(
					\Eris\Generators::choose( 1, self::MAX_REPEATS ),
					function ( $count ) {
						return \Eris\Generators::vector( (int) $count, self::gap() );
					}
				),
			)
		);
	}

	/**
	 * A closure interval in days, including 0 and the default 7.
	 *
	 * @return \Eris\Generator
	 */
	protected static function days() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( 0 ),
			\Eris\Generators::constant( AutoCloseJob::DEFAULT_DAYS ),
			\Eris\Generators::choose( 0, 21 )
		);
	}

	/**
	 * A population of 0 to MAX_ENQUIRIES enquiries.
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
	 * One enquiry: the status it holds, and where its settlement time sits
	 * relative to the cutoff, in seconds.
	 *
	 * A negative offset is before the cutoff and so overdue; 0 sits exactly on it
	 * and a positive offset after it, neither of which is due.
	 *
	 * @return \Eris\Generator
	 */
	protected static function record() {
		return \Eris\Generators::associative(
			array(
				'status' => \Eris\Generators::elements( self::STATUSES ),
				'offset' => \Eris\Generators::oneOf(
					\Eris\Generators::constant( -1 ),
					\Eris\Generators::constant( 0 ),
					\Eris\Generators::constant( 1 ),
					\Eris\Generators::choose( -60 * DAY_IN_SECONDS, -2 ),
					\Eris\Generators::choose( 2, 5 * DAY_IN_SECONDS )
				),
			)
		);
	}

	/**
	 * How long after the previous run a further run happens, in seconds.
	 *
	 * Strictly positive, and reaching well beyond the closure interval, so a
	 * further run that wrote anything would stamp it with an instant nothing in
	 * the table already holds.
	 *
	 * @return \Eris\Generator
	 */
	protected static function gap() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( 1 ),
			\Eris\Generators::choose( 1, 60 ),
			\Eris\Generators::choose( 1, 30 * DAY_IN_SECONDS )
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step is exponential in
	 * the number of generated values. A scenario holds up to four records of two
	 * drawn values each, plus an interval and up to three gaps, which puts that
	 * product beyond what fits in memory: a failing iteration would report an
	 * out-of-memory fatal instead of the counterexample. The `@eris-shrink` time
	 * limit does not help, because the explosion happens inside a single shrink
	 * call.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the scenario as generated, together with the assertion's
	 * own diff and the `ERIS_SEED` line that reproduces the run exactly.
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
	 * The cutoff the run time and the generated interval imply.
	 *
	 * Computed here rather than read from `AutoCloseJob::cutoff()`, so the
	 * settlement times the population is seeded at are the test's own reckoning
	 * of the boundary rather than the code's.
	 *
	 * @return string MySQL `DATETIME`.
	 */
	private function cutoff() {
		return Clock::at( self::RUN_AT )
			->modify( sprintf( '-%d days', $this->days ) )
			->format( Clock::MYSQL_FORMAT );
	}

	/**
	 * Write a whole population and return its identifiers.
	 *
	 * @param array  $population Records as generated.
	 * @param string $cutoff     Cutoff each record's offset is measured from.
	 * @return int[]
	 */
	private function seed_population( array $population, $cutoff ) {
		$ids = array();

		foreach ( $population as $index => $record ) {
			$settled_at = Clock::mysql( Clock::offset( (int) $record['offset'], $cutoff ) );

			$id = EnquiryStore::create(
				array(
					'first_name'        => 'Ada',
					'last_name'         => 'Lovelace',
					'email'             => sprintf( 'ada+%d@example.com', $index ),
					'status'            => (string) $record['status'],
					'created_at'        => $settled_at,
					'updated_at'        => $settled_at,
					'status_changed_at' => $settled_at,
					'source'            => 'webhook:fixture',
				),
				array( '2025-08-16' )
			);

			$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

			$ids[] = (int) $id;
		}

		return $ids;
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Every column of every enquiry row, oldest identifier first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function snapshot() {
		global $wpdb;

		$table            = Schema::table( 'enquiries' );
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

		$this->assertSame( '', (string) $wpdb->last_error, 'The snapshot read should run without error.' );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The status of every enquiry, keyed by identifier.
	 *
	 * @return array<int,string>
	 */
	private function statuses() {
		$statuses = array();

		foreach ( $this->snapshot() as $row ) {
			$statuses[ (int) $row['id'] ] = (string) $row['status'];
		}

		return $statuses;
	}

	/**
	 * The history of every seeded enquiry, keyed by identifier.
	 *
	 * @param int[] $ids Enquiry identifiers.
	 * @return array<int,array>
	 */
	private function histories( array $ids ) {
		$histories = array();

		foreach ( $ids as $id ) {
			$histories[ (int) $id ] = HistoryRecorder::for_enquiry( (int) $id );
		}

		return $histories;
	}

	/**
	 * How many enquiries moved to `closed` between two status maps.
	 *
	 * @param array<int,string> $before Statuses before the run.
	 * @param array<int,string> $after  Statuses after the run.
	 * @return int
	 */
	private function newly_closed( array $before, array $after ) {
		$moved = 0;

		foreach ( $after as $id => $status ) {
			$was = isset( $before[ $id ] ) ? $before[ $id ] : '';

			if ( AutoCloseJob::TARGET_STATUS === $status && $was !== $status ) {
				++$moved;
			}
		}

		return $moved;
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
