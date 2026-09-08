<?php
/**
 * Facts about the installed schema that only a real database can answer.
 *
 * Three of the four facts task 2.4 names need MySQL to be present: the naming
 * rule is checked against the live `$wpdb->prefix`, the indexes are read back
 * out of the created tables, and the install budget is measured against an
 * empty database. The fourth — that no code path drops an Enquiry Store table —
 * is a source-level fact and lives in the `pure` suite instead, in
 * tests/pure/SchemaNoDropTest.php.
 *
 * Every test here works under a temporary table prefix, so the tables it
 * creates are its own and "against an empty database" is true on every run
 * rather than only the first. Cleaning those up is the one place a `DROP TABLE`
 * is legitimate: they were created by this test, seconds earlier, and hold no
 * enquiry.
 *
 * Covers Requirements 1.9, 1.13, 1.14, 1.15.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Schema;

class SchemaFactsTest extends WP_UnitTestCase {

	/**
	 * Table prefix used for the tables this test creates.
	 */
	const TEST_PREFIX = 'mehfacts_';

	/**
	 * Prefix the test environment was using.
	 *
	 * @var string
	 */
	protected $original_prefix = '';

	/**
	 * Indexes Requirement 1.15 asks for on the enquiry table.
	 *
	 * @var string[]
	 */
	protected $enquiry_indexes = array(
		'PRIMARY',
		'email',
		'status',
		'created_at',
		'fluentcrm_subscriber_id',
		'is_test',
	);

	/**
	 * Indexes the design declares on the candidate date table.
	 *
	 * Both bounds of a range are indexed, because the list filter's overlap test
	 * reads them both: a range overlaps the window when its start is not after
	 * the window's end and its end is not before the window's start.
	 *
	 * @var string[]
	 */
	protected $date_indexes = array(
		'PRIMARY',
		'enquiry_id',
		'start_date',
		'end_date',
	);

	/**
	 * Load the classes under test. meh_bootstrap() does not load them, because
	 * FluentCRM Pro is absent from the test environment.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';

		$this->original_prefix = $wpdb->prefix;
	}

	/**
	 * Restore the prefix and remove the tables this test created.
	 */
	public function tear_down() {
		global $wpdb;

		$this->drop_test_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Requirement 1.13: prefix, component prefix, and 64 characters.
	 */
	public function test_table_names_carry_both_prefixes_and_fit_the_limit() {
		global $wpdb;

		$names = Schema::tables();

		$this->assertCount( 6, $names, 'The Enquiry Store has six tables.' );

		foreach ( Schema::TABLES as $key => $unprefixed ) {
			$expected = $wpdb->prefix . Schema::PREFIX . $unprefixed;

			$this->assertSame( $expected, $names[ $key ], "Table {$key} carries the WordPress prefix then meh_." );
			$this->assertLessThanOrEqual( 64, strlen( $names[ $key ] ), "Table {$key} fits MySQL's 64-character limit." );
		}

		$this->assertSame(
			array_values( $names ),
			array_values( array_unique( $names ) ),
			'No two tables share a name.'
		);
	}

	/**
	 * Requirement 1.13 under a prefix long enough to breach the limit.
	 *
	 * The prefix cannot survive intact here — it is longer than the whole budget
	 * — so what has to hold is that the names still fit, still differ from one
	 * another, and are stable between calls, which is what keeps `table()` usable
	 * as an identity.
	 */
	public function test_table_names_stay_within_the_limit_under_a_long_prefix() {
		global $wpdb;

		$wpdb->prefix = str_repeat( 'longprefix_', 6 ); // 66 characters, on its own already over.

		$names = Schema::tables();

		foreach ( $names as $key => $name ) {
			$this->assertLessThanOrEqual( 64, strlen( $name ), "Table {$key} fits MySQL's 64-character limit." );
			$this->assertNotSame( '', $name, "Table {$key} resolves to a name." );
		}

		$this->assertSame(
			array_values( $names ),
			array_values( array_unique( $names ) ),
			'A shortened name is still unique per table.'
		);

		$this->assertSame( $names, Schema::tables(), 'Shortening is deterministic, not random.' );
	}

	/**
	 * Requirement 1.15: the list queries' indexes exist once installed.
	 */
	public function test_declared_indexes_exist_on_the_enquiry_and_date_tables() {
		$this->install_under_test_prefix();

		$this->assertIndexesExist( 'enquiries', $this->enquiry_indexes );
		$this->assertIndexesExist( 'dates', $this->date_indexes );
	}

	/**
	 * Requirement 1.9: every absent table is created inside 30 seconds.
	 */
	public function test_install_completes_within_thirty_seconds_against_an_empty_database() {
		global $wpdb;

		$wpdb->prefix = self::TEST_PREFIX;

		$this->drop_test_tables();

		foreach ( Schema::tables() as $key => $table ) {
			$this->assertFalse( $this->table_exists( $table ), "Table {$key} is absent before the install." );
		}

		$started   = microtime( true );
		$installed = Schema::install();
		$elapsed   = microtime( true ) - $started;

		$this->assertTrue( $installed, 'install() reports success.' );
		$this->assertLessThan( 30, $elapsed, 'install() completes inside the 30-second budget.' );

		foreach ( Schema::tables() as $key => $table ) {
			$this->assertTrue( $this->table_exists( $table ), "Table {$key} exists after the install." );
		}
	}

	/**
	 * Install the schema under the test prefix.
	 */
	protected function install_under_test_prefix() {
		global $wpdb;

		$wpdb->prefix = self::TEST_PREFIX;

		$this->drop_test_tables();

		$this->assertTrue( Schema::install(), 'install() reports success.' );
	}

	/**
	 * Assert every named index is present on one table.
	 *
	 * @param string   $key     Table key.
	 * @param string[] $indexes Index names expected.
	 */
	protected function assertIndexesExist( $key, array $indexes ) {
		global $wpdb;

		$table = Schema::table( $key );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`" );

		$found = array();

		foreach ( (array) $rows as $row ) {
			$found[] = $row->Key_name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		$found = array_unique( $found );

		foreach ( $indexes as $index ) {
			$this->assertContains( $index, $found, "Index {$index} exists on {$key}." );
		}
	}

	/**
	 * Whether a table is present.
	 *
	 * @param string $table Fully qualified table name.
	 * @return bool
	 */
	protected function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return (string) $table === (string) $found;
	}

	/**
	 * Remove the tables created under the test prefix, and only those.
	 */
	protected function drop_test_tables() {
		global $wpdb;

		$prefix       = $wpdb->prefix;
		$wpdb->prefix = self::TEST_PREFIX;

		foreach ( Schema::tables() as $table ) {
			if ( 0 !== strpos( $table, self::TEST_PREFIX ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		$wpdb->prefix = $prefix;
	}
}
