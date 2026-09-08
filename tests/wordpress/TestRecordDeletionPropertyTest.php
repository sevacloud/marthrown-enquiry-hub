<?php
/**
 * Property 38: Deleting test records spares live records.
 *
 * Feature: enquiry-data-layer, Property 38: For any population of enquiries
 * mixing test and live records, the delete-test-records route removes every
 * enquiry whose `is_test` value is true along with that enquiry's candidate
 * date ranges, terms, notes and history, and leaves every enquiry whose `is_test`
 * value is false, and all of its child rows, byte-identical.
 *
 * **Validates: Requirements 17.7, 17.8**
 *
 * Three things about how the property is instantiated are worth stating plainly:
 *
 * - The claim is about what a `DELETE` leaves behind, so it runs against real
 *   tables. No amount of PHP-level inspection can tell whether an `IN` list
 *   named one identifier too many.
 * - "Byte-identical" is asserted by comparing a snapshot of every column of
 *   every row of all six Enquiry Store tables, taken before the delete and
 *   filtered down to the live identifiers, against the same snapshot taken
 *   after. That is stronger than reading the spared enquiries back through
 *   `find()`: it also catches a child row of a deleted enquiry surviving as an
 *   orphan, and a spared enquiry's `updated_at` being nudged in passing.
 * - The population quantifies over the mix, not just over its size: the
 *   all-test, all-live and empty populations are all reachable, and a spared
 *   enquiry may hold zero rows in a child table as easily as several.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class TestRecordDeletionPropertyTest
 */
class TestRecordDeletionPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehtd_';

	/**
	 * Most enquiries one population holds.
	 *
	 * Small on purpose: the interesting variable is the *mix* of test and live
	 * records and of their child rows, and a population of four already reaches
	 * every mix that matters while leaving 100 iterations affordable against a
	 * real database.
	 */
	const MAX_ENQUIRIES = 4;

	/**
	 * Most notes, and most history entries, one enquiry holds.
	 */
	const MAX_CHILD_ROWS = 2;

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
	}

	public function set_up() {
		parent::set_up();

		global $wpdb;

		/*
		 * The WordPress test case rewrites CREATE TABLE into its TEMPORARY form,
		 * and `Schema::install()` verifies each table through `SHOW TABLES`,
		 * which cannot see a temporary table. This test therefore works against
		 * real tables at its own prefix and cleans them up itself.
		 */
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );
	}

	public function tear_down() {
		global $wpdb;

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 38: Deleting test records spares
	 * live records.
	 *
	 * **Validates: Requirements 17.7, 17.8**
	 *
	 * @eris-shrink 10
	 */
	public function test_deleting_test_records_spares_live_records() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::population() ) )
			->then(
				function ( array $population ) {
					$this->clear();

					$seeded = $this->seed_population( $population );

					// Everything as it stands, before anything is deleted.
					$before = $this->snapshot();

					// Requirement 17.8: what the delete must leave behind is
					// exactly the live rows, unchanged, and nothing else.
					$expected = $this->only( $before, $seeded['live'] );

					$deleted = EnquiryStore::delete_test_records();

					// Requirement 17.7: every test enquiry, and only those.
					$this->assertSame(
						count( $seeded['test'] ),
						$deleted,
						'The delete should report one removal per test enquiry.'
					);

					$this->assertSame(
						$expected,
						$this->snapshot(),
						'The spared rows should be byte-identical and the deleted rows wholly gone.'
					);

					foreach ( $seeded['test'] as $id ) {
						$this->assertNull( EnquiryStore::find( $id ), 'Test enquiry ' . $id . ' should be gone.' );
					}

					foreach ( $seeded['live'] as $id ) {
						$this->assertIsArray( EnquiryStore::find( $id ), 'Live enquiry ' . $id . ' should remain.' );
					}
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * A population of 0 to MAX_ENQUIRIES enquiries, each independently a test or
	 * a live record.
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
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step is exponential in
	 * the number of generated values. A population holds up to four records of
	 * seven drawn values each, two of them sets, which puts that product far
	 * beyond what fits in memory: a failing iteration reports an out-of-memory
	 * fatal instead of the counterexample. The `@eris-shrink` time limit does not
	 * help, because the explosion happens inside a single shrink call.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the population as generated, together with the snapshot
	 * diff and the `ERIS_SEED` line that reproduces the run exactly.
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

	/**
	 * One enquiry: whether it is a test record, and how many rows it holds in
	 * each of the four child tables.
	 *
	 * @return \Eris\Generator
	 */
	protected static function record() {
		return \Eris\Generators::associative(
			array(
				'is_test'          => \Eris\Generators::elements( array( true, false ) ),
				'email'            => Generators::email(),
				'ranges'           => Generators::candidate_ranges( 1, 3 ),
				'event_type'       => Generators::term_set( 0, 2 ),
				'site_exclusivity' => Generators::term_set( 0, 2 ),
				'notes'            => \Eris\Generators::choose( 0, self::MAX_CHILD_ROWS ),
				'history'          => \Eris\Generators::choose( 0, self::MAX_CHILD_ROWS ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write a whole population, and report which identifiers are test records
	 * and which are live.
	 *
	 * @param array $population Records as generated.
	 * @return array{test:int[],live:int[]}
	 */
	private function seed_population( array $population ) {
		$seeded = array(
			'test' => array(),
			'live' => array(),
		);

		foreach ( $population as $record ) {
			$is_test = (bool) $record['is_test'];

			$id = EnquiryStore::create(
				array(
					'first_name' => 'Ada',
					'last_name'  => 'Lovelace',
					'email'      => (string) $record['email'],
					'status'     => 'new',
					'source'     => 'webhook:fixture',
					'is_test'    => $is_test ? 1 : 0,
				),
				$record['ranges'],
				array(
					'event_type'       => $record['event_type'],
					'site_exclusivity' => $record['site_exclusivity'],
				),
				array( 'email' => (string) $record['email'] )
			);

			$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

			for ( $index = 0; $index < (int) $record['notes']; $index++ ) {
				$this->seed_child( 'notes', $id, $index );
			}

			for ( $index = 0; $index < (int) $record['history']; $index++ ) {
				$this->seed_child( 'history', $id, $index );
			}

			$seeded[ $is_test ? 'test' : 'live' ][] = (int) $id;
		}

		return $seeded;
	}

	/**
	 * Write one note or history row against an enquiry.
	 *
	 * @param string $key   Table key, `notes` or `history`.
	 * @param int    $id    Enquiry identifier.
	 * @param int    $index Which row of that kind this is.
	 * @return void
	 */
	private function seed_child( $key, $id, $index ) {
		global $wpdb;

		$row = array(
			'enquiry_id' => (int) $id,
			'created_at' => '2025-06-01 10:00:00',
		);

		if ( 'notes' === $key ) {
			$row['body']      = 'Note ' . $index . '.';
			$row['author_id'] = 3;
		} else {
			$row['entry_type']  = 'created';
			$row['description'] = 'Entry ' . $index . '.';
			$row['actor_id']    = 0;
		}

		$this->assertNotFalse(
			$wpdb->insert( Schema::table( $key ), $row ),
			sprintf( 'Seeding a %s row should succeed: %s', $key, $wpdb->last_error )
		);
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Every column of every row of every Enquiry Store table, keyed by table.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function snapshot() {
		global $wpdb;

		$snapshot = array();

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );

			$wpdb->last_error = '';

			// phpcs:ignore WordPress.DB
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

			$this->assertSame( '', (string) $wpdb->last_error, 'The snapshot read should run without error.' );

			$snapshot[ $key ] = is_array( $rows ) ? $rows : array();
		}

		return $snapshot;
	}

	/**
	 * A snapshot narrowed to the rows belonging to a set of enquiries.
	 *
	 * The enquiry table is narrowed on `id`, every child table on `enquiry_id`,
	 * and the rejections table is left whole because it relates to no enquiry.
	 *
	 * @param array $snapshot Snapshot as taken.
	 * @param int[] $ids      Enquiry identifiers to keep.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function only( array $snapshot, array $ids ) {
		$ids = array_map( 'intval', $ids );

		foreach ( $snapshot as $key => $rows ) {
			if ( ! in_array( $key, self::enquiry_tables(), true ) ) {
				continue;
			}

			$column = 'enquiries' === $key ? 'id' : 'enquiry_id';

			$snapshot[ $key ] = array_values(
				array_filter(
					$rows,
					function ( array $row ) use ( $column, $ids ) {
						return in_array( (int) $row[ $column ], $ids, true );
					}
				)
			);
		}

		return $snapshot;
	}

	/**
	 * The tables whose rows belong to one enquiry.
	 *
	 * @return string[]
	 */
	private static function enquiry_tables() {
		return array_merge( array( 'enquiries' ), EnquiryStore::CHILD_TABLES );
	}

	/**
	 * Empty every Enquiry Store table, between iterations.
	 *
	 * `DELETE` rather than `TRUNCATE`: the identifiers keep climbing, which is
	 * closer to a live table and means a stale identifier can never be mistaken
	 * for a fresh one.
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
