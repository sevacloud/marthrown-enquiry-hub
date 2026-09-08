<?php
/**
 * Property 4: Schema upgrades are ordered, atomic and non-destructive.
 *
 * Feature: enquiry-data-layer, Property 4: For any stored schema version
 * (absent, zero, or lower than current), any subset of Enquiry Store tables
 * already present, and any set of pre-existing rows in those tables, running the
 * schema manager applies exactly the changes defined for each version between
 * the stored version exclusive and the current version inclusive in ascending
 * order, records the current version only when every change succeeds, and leaves
 * every pre-existing row byte-identical; and for any single failing change, the
 * stored version is unchanged, every row is retained, and an error naming the
 * table and reason is recorded.
 *
 * **Validates: Requirements 1.9, 1.10, 1.11, 1.12, 1.16**
 *
 * Two things about how the property is instantiated are worth stating plainly,
 * because both are consequences of `Schema::CURRENT_VERSION` being 1 rather than
 * of anything the test chose:
 *
 * - The version range `(stored, CURRENT]` holds exactly one member today, and
 *   the change defined for version 1 is the initial install. "Ascending order"
 *   over a one-member range is satisfied by construction, so the ordering clause
 *   is asserted against the range the upgrade actually computes rather than
 *   against observable side effects — that computation is the only place order
 *   lives until a version 2 exists. Every generator here derives its bounds from
 *   `Schema::CURRENT_VERSION`, so adding a version 2 widens the quantification
 *   with no change to this file.
 * - "Any subset already present" is built by installing all six tables and
 *   dropping the ones the case wants absent. `Schema` exposes no drop method by
 *   design (Requirement 1.14), so the test issues that DROP itself, against its
 *   own throwaway table prefix.
 *
 * A failing change is injected by moving one table's last-declared index onto
 * another column while keeping its name, which makes `dbDelta()` re-add it and
 * MySQL refuse with a duplicate key name. That is a genuine failed `ALTER`
 * against a real database — nothing here is mocked — and it is the last
 * statement `dbDelta()` issues for that table, so the failure is what the
 * schema manager sees.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class SchemaUpgradePropertyTest
 */
class SchemaUpgradePropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehp_';

	/**
	 * Fixed creation time for every seeded row.
	 */
	const SEEDED_AT = '2025-06-01 09:30:00';

	/**
	 * The last-declared index of each table, and a column to misdirect it onto.
	 *
	 * The last index is the one that matters: `dbDelta()` emits index statements
	 * in declaration order, so re-adding the last one fails after every other
	 * statement for that table has run, leaving the error where the schema
	 * manager reads it.
	 *
	 * @var array<string,array{index:string,decoy:string}>
	 */
	const LAST_INDEX = array(
		'enquiries'  => array(
			'index' => 'is_test',
			'decoy' => 'status',
		),
		'dates'      => array(
			'index' => 'end_date',
			'decoy' => 'enquiry_id',
		),
		'terms'      => array(
			'index' => 'enquiry_taxonomy',
			'decoy' => 'taxonomy',
		),
		'notes'      => array(
			'index' => 'enquiry_id',
			'decoy' => 'author_id',
		),
		'history'    => array(
			'index' => 'created_at',
			'decoy' => 'actor_id',
		),
		'rejections' => array(
			'index' => 'email',
			'decoy' => 'source',
		),
	);

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Whether `$wpdb` was printing errors before this test hid them.
	 *
	 * @var bool
	 */
	private $original_show_errors = true;

	/**
	 * Where PHP was logging before this test redirected it.
	 *
	 * @var string
	 */
	private $original_error_log = '';

	/**
	 * Load the classes under test.
	 *
	 * The plugin's own bootstrap does not run in the test environment, so the
	 * class files are required here.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';
	}

	public function set_up() {
		parent::set_up();

		global $wpdb;

		/*
		 * The WordPress test case rewrites CREATE/DROP TABLE into their
		 * TEMPORARY forms, and a temporary table is invisible to `SHOW TABLES`.
		 * Schema existence checks would then see nothing it had just created, so
		 * this test works against real tables and cleans them up itself.
		 */
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix      = $wpdb->prefix;
		$wpdb->prefix               = $this->original_prefix . self::PREFIX_SEGMENT;
		$this->original_show_errors = (bool) $wpdb->show_errors;
		$this->original_error_log   = (string) ini_get( 'error_log' );

		// The injected failure makes `$wpdb` print an error block otherwise.
		$wpdb->hide_errors();

		$this->drop_tables();
		delete_option( Schema::VERSION_OPTION );
	}

	public function tear_down() {
		global $wpdb;

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		if ( $this->original_show_errors ) {
			$wpdb->show_errors();
		}

		ini_set( 'error_log', $this->original_error_log );

		/*
		 * Table creation implicitly commits, so the transaction the WordPress
		 * test case opened is gone and its rollback cannot take this option with
		 * it.
		 */
		delete_option( Schema::VERSION_OPTION );

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 4: Schema upgrades are ordered,
	 * atomic and non-destructive.
	 *
	 * **Validates: Requirements 1.9, 1.10, 1.11, 1.12, 1.16**
	 *
	 * @eris-shrink 10
	 */
	public function test_schema_upgrades_are_ordered_atomic_and_non_destructive() {
		// Clause one: an upgrade from below the current version completes,
		// records the version, and destroys nothing.
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::scenario() )
			->then( function ( array $case ) {
				$this->reset_state();
				$this->seed( $case['present'], $case['rows'], $case );
				$before = $this->snapshot( $case['present'] );

				$stored = $this->store_version( $case['version'] );
				$this->assert_pending_range_ascending( $stored );

				$log    = $this->start_capturing_log();
				$result = Schema::maybe_upgrade();
				$logged = $this->stop_capturing_log( $log );

				// The log is asserted first: where an upgrade did fail, the logged
				// reason says which table and why, which the bare `false` does not.
				$this->assertSame( '', $logged, 'A successful upgrade should record no failure.' );
				$this->assertTrue( $result, 'An upgrade from below the current version should succeed.' );

				// The change defined for the version range was applied: every
				// table exists and carries its declared columns.
				foreach ( array_keys( Schema::TABLES ) as $key ) {
					$this->assertTrue( $this->table_exists( $key ), Schema::table( $key ) . ' should exist after the upgrade.' );
				}
				$this->assertSame( self::enquiry_columns(), $this->columns( 'enquiries' ) );

				// Recorded, because every change succeeded.
				$this->assertSame( Schema::CURRENT_VERSION, (int) get_option( Schema::VERSION_OPTION ) );

				// Every pre-existing row byte-identical.
				$this->assert_rows_retained( $before );
			} );

		// Clause two: a single failing change leaves the stored version and
		// every row alone, and says which table failed and why.
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::failure_scenario() )
			->then( function ( array $case ) {
				$this->reset_state();
				$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );
				$this->seed( array_keys( Schema::TABLES ), $case['rows'], $case );
				$this->break_index( $case['failing'] );

				$before = $this->snapshot( array_keys( Schema::TABLES ) );
				$stored = $this->store_version( $case['version'] );

				$log    = $this->start_capturing_log();
				$result = Schema::maybe_upgrade();
				$logged = $this->stop_capturing_log( $log );

				$this->assertFalse( $result, 'A failing change should not report success.' );

				// The stored version is exactly what it was.
				$this->assertSame( $stored, $this->raw_version(), 'A failing change must leave the stored version alone.' );
				$this->assertNotSame( Schema::CURRENT_VERSION, (int) get_option( Schema::VERSION_OPTION, 0 ) );

				// Every row retained.
				$this->assert_rows_retained( $before );

				// An error naming the table and the reason.
				$table = Schema::table( $case['failing'] );
				$this->assertStringContainsString( '[MEH] schema: ' . $table . ' — ', $logged );
				$this->assertMatchesRegularExpression(
					'/\[MEH\] schema: ' . preg_quote( $table, '/' ) . ' — \S.*/u',
					$logged,
					'The logged failure should carry a non-empty reason.'
				);
			} );
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * A starting state: a stored version below the current one, a subset of the
	 * tables already present, a row count, and the values those rows hold.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'version' => self::version_below_current(),
				'present' => \Eris\Generators::subset( array_keys( Schema::TABLES ) ),
				'rows'    => \Eris\Generators::choose( 0, 3 ),
				'name'    => Generators::first_name(),
				'text'    => Generators::adversarial_string(),
				'email'   => Generators::email(),
				'guests'  => Generators::total_guests_or_unsupplied(),
				'range'   => Generators::candidate_range(),
			)
		);
	}

	/**
	 * The same starting state, plus which table's change is made to fail.
	 *
	 * Every table is present here: a change can only fail against a table that
	 * exists to be altered.
	 *
	 * @return \Eris\Generator
	 */
	protected static function failure_scenario() {
		return \Eris\Generators::associative(
			array(
				'version' => self::version_below_current(),
				'failing' => \Eris\Generators::elements( array_keys( self::LAST_INDEX ) ),
				'rows'    => \Eris\Generators::choose( 1, 3 ),
				'name'    => Generators::first_name(),
				'text'    => Generators::adversarial_string(),
				'email'   => Generators::email(),
				'guests'  => Generators::total_guests_or_unsupplied(),
				'range'   => Generators::candidate_range(),
			)
		);
	}

	/**
	 * A stored version below the current one: absent, or any value from 0 up.
	 *
	 * Derived from `Schema::CURRENT_VERSION` rather than written out, so the set
	 * widens by itself when a version 2 arrives.
	 *
	 * @return \Eris\Generator
	 */
	protected static function version_below_current() {
		$states = array( 'absent' );

		foreach ( range( 0, Schema::CURRENT_VERSION - 1 ) as $version ) {
			$states[] = (int) $version;
		}

		return \Eris\Generators::elements( $states );
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * The versions the upgrade will work through are exactly the range
	 * `(stored, CURRENT]`, in ascending order.
	 *
	 * Steps are registered in descending order so an implementation that simply
	 * iterated whatever it was handed would come out wrong.
	 *
	 * @param int $stored Stored version.
	 * @return void
	 */
	private function assert_pending_range_ascending( $stored ) {
		$steps = array();

		for ( $version = Schema::CURRENT_VERSION; $version >= 1; $version-- ) {
			$steps[ $version ] = '__return_true';
		}

		$supply = function () use ( $steps ) {
			return $steps;
		};

		add_filter( 'meh_schema_migrations', $supply );

		$pending = new ReflectionMethod( Schema::class, 'pending' );
		$pending->setAccessible( true );
		$versions = array_keys( (array) $pending->invoke( null, (int) $stored ) );

		remove_filter( 'meh_schema_migrations', $supply );

		$this->assertSame(
			range( (int) $stored + 1, Schema::CURRENT_VERSION ),
			$versions,
			'The pending versions should be exactly (stored, CURRENT] in ascending order.'
		);
	}

	/**
	 * Every row in the snapshot is still there, byte for byte.
	 *
	 * @param array<string,array> $before Snapshot keyed by table key.
	 * @return void
	 */
	private function assert_rows_retained( array $before ) {
		foreach ( $before as $key => $rows ) {
			$now = $this->rows( $key );

			$this->assertCount( count( $rows ), $now, Schema::table( $key ) . ' should keep every row.' );

			foreach ( $rows as $index => $row ) {
				foreach ( $row as $column => $value ) {
					$this->assertArrayHasKey( $column, $now[ $index ] );
					$this->assertSame(
						$value,
						$now[ $index ][ $column ],
						Schema::table( $key ) . ".{$column} should be unchanged."
					);
				}
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Fixture helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return the database and the version option to a clean slate.
	 *
	 * @return void
	 */
	private function reset_state() {
		$this->drop_tables();
		delete_option( Schema::VERSION_OPTION );
		$this->release_upgrade_guard();
	}

	/**
	 * Clear the once-per-request latch inside the schema manager, so each
	 * iteration exercises a fresh load rather than a second call in the same one.
	 *
	 * @return void
	 */
	private function release_upgrade_guard() {
		$checked = new ReflectionProperty( Schema::class, 'checked' );
		$checked->setAccessible( true );
		$checked->setValue( null, false );
	}

	/**
	 * Create the named subset of tables and fill each with rows.
	 *
	 * @param array $present Table keys that should exist.
	 * @param int   $rows    Rows per table.
	 * @param array $case    Generated values.
	 * @return void
	 */
	private function seed( array $present, $rows, array $case ) {
		global $wpdb;

		if ( ! $present ) {
			return;
		}

		// Everything, then drop back to the requested subset: the schema
		// manager offers no way to create one table on its own, and none is
		// wanted in production.
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			if ( ! in_array( $key, $present, true ) ) {
				$table = Schema::table( $key );
				$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
			}
		}

		for ( $index = 1; $index <= (int) $rows; $index++ ) {
			foreach ( $present as $key ) {
				$wpdb->insert( Schema::table( $key ), self::row( $key, $index, $case ) );
			}
		}
	}

	/**
	 * One row of fixture data for a table.
	 *
	 * Values come from the shared generators, adversarial strings included, so
	 * "byte-identical" is a claim about awkward content and not only about
	 * plain ASCII.
	 *
	 * @param string $key   Table key.
	 * @param int    $index Row number.
	 * @param array  $case  Generated values.
	 * @return array Column => value.
	 */
	private static function row( $key, $index, array $case ) {
		$context = wp_json_encode( array( 'raw' => $case['text'] ) );

		$rows = array(
			'enquiries'  => array(
				'first_name'        => $case['name'],
				'last_name'         => $case['text'],
				'email'             => $case['email'],
				'phone'             => '07700 900' . str_pad( (string) $index, 3, '0', STR_PAD_LEFT ),
				'total_guests'      => $case['guests'],
				'message'           => $case['text'],
				'status'            => 'new',
				'crm_sync_state'    => 'pending',
				'created_at'        => self::SEEDED_AT,
				'updated_at'        => self::SEEDED_AT,
				'status_changed_at' => self::SEEDED_AT,
				'source'            => 'webhook:fixture-' . $index,
				'is_test'           => 1,
				'payload'           => $context,
			),
			'dates'      => array(
				'enquiry_id' => $index,
				'start_date' => $case['range']['start'],
				'end_date'   => $case['range']['end'],
				'position'   => 0,
			),
			'terms'      => array(
				'enquiry_id' => $index,
				'taxonomy'   => 'event_type',
				'term'       => $case['text'],
			),
			'notes'      => array(
				'enquiry_id' => $index,
				'body'       => $case['text'],
				'author_id'  => 0,
				'created_at' => self::SEEDED_AT,
			),
			'history'    => array(
				'enquiry_id'  => $index,
				'entry_type'  => 'created',
				'description' => $case['text'],
				'context'     => $context,
				'actor_id'    => 0,
				'created_at'  => self::SEEDED_AT,
			),
			'rejections' => array(
				'reason'     => 'validation',
				'detail'     => $context,
				'payload'    => $context,
				'email'      => $case['email'],
				'source'     => 'webhook:fixture-' . $index,
				'is_test'    => 0,
				'created_at' => self::SEEDED_AT,
			),
		);

		return $rows[ $key ];
	}

	/**
	 * Move a table's last-declared index onto another column, keeping its name,
	 * so re-adding it fails.
	 *
	 * @param string $key Table key.
	 * @return void
	 */
	private function break_index( $key ) {
		global $wpdb;

		$table = Schema::table( $key );
		$index = self::LAST_INDEX[ $key ]['index'];
		$decoy = self::LAST_INDEX[ $key ]['decoy'];

		// Identifiers come from constants in this file, never from generated data.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX `{$index}`" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "ALTER TABLE {$table} ADD KEY `{$index}` (`{$decoy}`)" ); // phpcs:ignore WordPress.DB

		$this->assertSame( '', (string) $wpdb->last_error, 'Breaking the index should itself succeed.' );
	}

	/**
	 * Put the stored version into the generated starting state.
	 *
	 * @param string|int $state 'absent', or a version number.
	 * @return string|int What was stored, for a later comparison.
	 */
	private function store_version( $state ) {
		if ( 'absent' === $state ) {
			delete_option( Schema::VERSION_OPTION );

			return 'absent';
		}

		update_option( Schema::VERSION_OPTION, (int) $state, false );

		return (int) $state;
	}

	/**
	 * The stored version in the same shape `store_version()` reports.
	 *
	 * @return string|int
	 */
	private function raw_version() {
		$stored = get_option( Schema::VERSION_OPTION, null );

		return null === $stored ? 'absent' : (int) $stored;
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

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

	/**
	 * Whether a table exists.
	 *
	 * @param string $key Table key.
	 * @return bool
	 */
	private function table_exists( $key ) {
		global $wpdb;

		$table = Schema::table( $key );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return (string) $table === (string) $found;
	}

	/**
	 * Every row of a table, oldest identifier first.
	 *
	 * @param string $key Table key.
	 * @return array<int,array<string,string|null>>
	 */
	private function rows( $key ) {
		global $wpdb;

		$table = Schema::table( $key );

		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Rows of several tables, keyed by table key.
	 *
	 * @param array $keys Table keys.
	 * @return array<string,array>
	 */
	private function snapshot( array $keys ) {
		$snapshot = array();

		foreach ( $keys as $key ) {
			$snapshot[ $key ] = $this->rows( $key );
		}

		return $snapshot;
	}

	/**
	 * A table's column names, in declaration order.
	 *
	 * @param string $key Table key.
	 * @return array
	 */
	private function columns( $key ) {
		global $wpdb;

		$table = Schema::table( $key );

		return array_map(
			'strval',
			(array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * The columns the enquiry table is declared with.
	 *
	 * Written out rather than read from `Schema`, so a column silently dropped
	 * from the declaration is a failure here.
	 *
	 * @return array
	 */
	private static function enquiry_columns() {
		return array(
			'id',
			'first_name',
			'last_name',
			'email',
			'phone',
			'total_guests',
			'message',
			'status',
			'fluentcrm_subscriber_id',
			'booking_id',
			'crm_sync_state',
			'created_at',
			'updated_at',
			'status_changed_at',
			'source',
			'is_test',
			'duplicated_from_id',
			'duplicated_to_id',
			'payload',
		);
	}

	/* ---------------------------------------------------------------------
	 * Log capture
	 * ------------------------------------------------------------------ */

	/**
	 * Send the plugin's log lines to a file of our own for the next call.
	 *
	 * @return string Log file path.
	 */
	private function start_capturing_log() {
		$file = tempnam( sys_get_temp_dir(), 'meh-schema-log' );

		ini_set( 'log_errors', '1' );
		ini_set( 'error_log', $file );
		add_filter( 'meh_log_enabled', '__return_true' );

		return (string) $file;
	}

	/**
	 * Stop capturing and return the plugin's lines only.
	 *
	 * @param string $file Log file path.
	 * @return string
	 */
	private function stop_capturing_log( $file ) {
		remove_filter( 'meh_log_enabled', '__return_true' );
		ini_set( 'error_log', $this->original_error_log );

		$contents = file_exists( $file ) ? (string) file_get_contents( $file ) : '';

		if ( file_exists( $file ) ) {
			unlink( $file );
		}

		$lines = array();

		foreach ( explode( "\n", $contents ) as $line ) {
			if ( false !== strpos( $line, '[MEH]' ) ) {
				$lines[] = trim( $line );
			}
		}

		return implode( "\n", $lines );
	}
}
