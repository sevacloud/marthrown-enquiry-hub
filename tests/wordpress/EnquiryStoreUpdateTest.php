<?php
/**
 * Unit tests for `EnquiryStore::update()`, the correction primitive.
 *
 * The worked examples behind Requirement 19's store half: a partial write, a
 * null versus an array candidate-date set, a null taxonomy, the columns a
 * correction never writes, the no-op that issues no statement at all, and a
 * failure part-way through leaving every stored value as it was — on a
 * transactional engine and on one without transactions.
 *
 * Properties 40, 41 and 43 quantify these claims over generated inputs and are
 * separate tests; what is asserted here is the example, not the quantifier.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix: the WordPress test case rewrites `CREATE TABLE` into its `TEMPORARY`
 * form, and `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see a temporary table.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\Schema;

/**
 * Class EnquiryStoreUpdateTest
 */
class EnquiryStoreUpdateTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehup_';

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Statements seen while a recording filter is installed.
	 *
	 * @var string[]
	 */
	private $recorded = array();

	/**
	 * Whether the one-shot statement breaker has already fired.
	 *
	 * @var bool
	 */
	private $broken = false;

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

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->recorded = array();
		$this->broken   = false;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );
	}

	public function tear_down() {
		global $wpdb;

		remove_filter( 'query', array( $this, 'record_query' ) );
		remove_filter( 'query', array( $this, 'break_term_insert_once' ) );

		$this->set_transactional( null );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * Partial writes
	 * ------------------------------------------------------------------ */

	/**
	 * A correction writes the submitted fields and leaves every other stored
	 * value alone (Requirement 19.7).
	 *
	 * @return void
	 */
	public function test_update_writes_only_the_submitted_fields() {
		$id     = $this->seed_enquiry();
		$before = EnquiryStore::find( $id );

		$result = EnquiryStore::update(
			$id,
			array(
				'email'        => 'ada.lovelace@example.com',
				'total_guests' => 95,
			)
		);

		$this->assertIsArray( $result, 'A correction of an existing enquiry should succeed.' );

		$this->assertSame(
			array(
				'email'        => array(
					'from' => 'ada@example.com',
					'to'   => 'ada.lovelace@example.com',
				),
				'total_guests' => array(
					'from' => 80,
					'to'   => 95,
				),
			),
			$result['changed'],
			'The changed map should name each field that actually changed, with its previous and new value.'
		);

		$after = EnquiryStore::find( $id );

		$this->assertSame( 'ada.lovelace@example.com', $after['email'] );
		$this->assertSame( 95, $after['total_guests'] );

		// Every field absent from the request keeps its stored value.
		foreach ( array( 'first_name', 'last_name', 'phone', 'message', 'selected_dates', 'event_type', 'site_exclusivity' ) as $field ) {
			$this->assertSame( $before[ $field ], $after[ $field ], sprintf( '%s should be untouched.', $field ) );
		}

		// `updated_at` belongs to the caller, which sets it from the request time
		// only when the changed map came back non-empty (Requirement 19.8).
		$this->assertSame( $before['updated_at'], $after['updated_at'], 'The store does not write updated_at itself.' );
	}

	/**
	 * An empty submitted value for an optional field is a change to the empty
	 * value, and a value equal to the stored one is not a change at all.
	 *
	 * @return void
	 */
	public function test_update_reports_only_the_fields_whose_value_differs() {
		$id = $this->seed_enquiry();

		$result = EnquiryStore::update(
			$id,
			array(
				'first_name'   => 'Ada',
				'message'      => '',
				'total_guests' => null,
			)
		);

		$this->assertSame(
			array( 'total_guests', 'message' ),
			array_keys( $result['changed'] ),
			'An unchanged first name should not appear in the changed map.'
		);

		$after = EnquiryStore::find( $id );

		$this->assertSame( '', $after['message'] );
		$this->assertNull( $after['total_guests'], 'A cleared guest count reads back as null, not 0.' );
	}

	/* ---------------------------------------------------------------------
	 * Candidate dates and terms
	 * ------------------------------------------------------------------ */

	/**
	 * A null candidate-date set leaves the stored set alone; an array replaces it
	 * wholesale.
	 *
	 * @return void
	 */
	public function test_update_leaves_dates_alone_when_null_and_replaces_them_when_given() {
		$id = $this->seed_enquiry();

		$untouched = EnquiryStore::update( $id, array( 'phone' => '07700 900999' ), null );

		$this->assertSame( array( 'phone' ), array_keys( $untouched['changed'] ) );
		$this->assertSame(
			array( '2025-08-16', '2025-08-23' ),
			EnquiryStore::find( $id )['selected_dates'],
			'A null date set is "not submitted", not "clear the set".'
		);

		// The replacement drops one stored date and adds two, which is what
		// "replaced wholesale rather than diffed" means.
		$replaced = EnquiryStore::update( $id, array(), array( '2025-09-13', '2025-08-16', '2025-09-06' ) );

		$this->assertSame(
			array(
				'from' => array( '2025-08-16', '2025-08-23' ),
				'to'   => array( '2025-08-16', '2025-09-06', '2025-09-13' ),
			),
			$replaced['changed']['selected_dates'],
			'The date set should be compared and reported as a set.'
		);

		$this->assertSame(
			array( '2025-08-16', '2025-09-06', '2025-09-13' ),
			EnquiryStore::find( $id )['selected_dates']
		);
	}

	/**
	 * An unreadable candidate date fails the correction with nothing written
	 * (Requirement 19.5).
	 *
	 * @return void
	 */
	public function test_update_rejects_an_unreadable_candidate_date_without_writing() {
		$id     = $this->seed_enquiry();
		$before = EnquiryStore::find( $id );

		$result = EnquiryStore::update( $id, array( 'email' => 'grace@example.com' ), array( 'next Thursdayish' ) );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( $before, EnquiryStore::find( $id ), 'Every stored value should be as it was.' );
	}

	/**
	 * A null taxonomy leaves that term set alone while the other is replaced, and
	 * an empty array clears a set.
	 *
	 * @return void
	 */
	public function test_update_leaves_a_null_taxonomy_alone_and_replaces_the_other() {
		$id = $this->seed_enquiry();

		$result = EnquiryStore::update(
			$id,
			array(),
			null,
			array(
				'event_type'       => array( 'wedding', 'ceremony' ),
				'site_exclusivity' => null,
			)
		);

		$this->assertSame( array( 'event_type' ), array_keys( $result['changed'] ) );

		$after = EnquiryStore::find( $id );

		$this->assertSame( array( 'wedding', 'ceremony' ), $after['event_type'] );
		$this->assertSame( array( 'whole_site' ), $after['site_exclusivity'], 'A null taxonomy is not submitted.' );

		$cleared = EnquiryStore::update( $id, array(), null, array( 'site_exclusivity' => array() ) );

		$this->assertSame(
			array(
				'from' => array( 'whole_site' ),
				'to'   => array(),
			),
			$cleared['changed']['site_exclusivity'],
			'An empty array is a submitted, empty set.'
		);

		$after = EnquiryStore::find( $id );

		$this->assertSame( array(), $after['site_exclusivity'] );
		$this->assertSame( array( 'wedding', 'ceremony' ), $after['event_type'], 'The other taxonomy is undisturbed.' );
	}

	/* ---------------------------------------------------------------------
	 * The columns a correction never writes
	 * ------------------------------------------------------------------ */

	/**
	 * `status`, `status_changed_at`, `created_at`, `source`, `is_test`,
	 * `booking_id` and the payload snapshot are never written (Requirement 19.9).
	 *
	 * @return void
	 */
	public function test_update_writes_none_of_the_protected_columns() {
		$this->assertSame(
			array(),
			array_intersect( EnquiryStore::EDITABLE_COLUMNS, EnquiryStore::CORRECTION_PROTECTED_COLUMNS ),
			'No protected column should be reachable through the editable whitelist.'
		);

		$id     = $this->seed_enquiry();
		$before = EnquiryStore::find( $id );

		$protected = array(
			'status'            => 'converted',
			'status_changed_at' => '2030-01-01 00:00:00',
			'created_at'        => '2030-01-01 00:00:00',
			'source'            => 'manual:forged',
			'is_test'           => 1,
			'booking_id'        => 4242,
			'payload'           => 'rewritten',
			'id'                => 999,
		);

		// On their own they are not a correction at all: nothing changed.
		$ignored = EnquiryStore::update( $id, $protected );

		$this->assertSame( array(), $ignored['changed'], 'A request holding only protected columns changes nothing.' );
		$this->assertSame( $before, EnquiryStore::find( $id ) );

		// Alongside a real change they are still ignored.
		$applied = EnquiryStore::update( $id, array_merge( $protected, array( 'last_name' => 'Byron' ) ) );

		$this->assertSame( array( 'last_name' ), array_keys( $applied['changed'] ) );

		$after = EnquiryStore::find( $id );

		$this->assertSame( 'Byron', $after['last_name'] );

		foreach ( array( 'status', 'status_changed_at', 'created_at', 'source', 'is_test', 'booking_id', 'payload', 'id' ) as $column ) {
			$this->assertSame( $before[ $column ], $after[ $column ], sprintf( '%s should be unchanged.', $column ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * The no-op
	 * ------------------------------------------------------------------ */

	/**
	 * A correction holding the stored values issues no write at all — not an
	 * `UPDATE` setting a column to its own value (Requirement 19.18).
	 *
	 * @return void
	 */
	public function test_update_issues_no_write_when_nothing_changed() {
		$id     = $this->seed_enquiry();
		$before = EnquiryStore::find( $id );

		add_filter( 'query', array( $this, 'record_query' ) );

		$result = EnquiryStore::update(
			$id,
			array(
				'first_name'   => 'Ada',
				'last_name'    => 'Lovelace',
				'email'        => 'ada@example.com',
				'phone'        => '07700 900123',
				'total_guests' => 80,
				'message'      => 'A summer weekend, ideally.',
			),
			// The same sets, differently ordered and with a repeat: still the same
			// sets, so still no change.
			array( '2025-08-23', '2025-08-16', '2025-08-16' ),
			array(
				'event_type'       => array( 'reception', 'wedding', 'wedding' ),
				'site_exclusivity' => array( 'whole_site' ),
			)
		);

		remove_filter( 'query', array( $this, 'record_query' ) );

		$this->assertSame( array(), $result['changed'] );

		$writes = array_values(
			array_filter(
				$this->recorded,
				static function ( $query ) {
					return (bool) preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|START TRANSACTION|COMMIT)/i', $query );
				}
			)
		);

		$this->assertSame( array(), $writes, 'A no-op correction should issue no write and open no transaction.' );
		$this->assertSame( $before, EnquiryStore::find( $id ), 'Every stored value, updated_at included, should be untouched.' );
	}

	/**
	 * An unknown enquiry is a 404 and an invalid identifier a 400, neither of them
	 * writing anything.
	 *
	 * @return void
	 */
	public function test_update_reports_an_unknown_or_invalid_identifier() {
		$missing = EnquiryStore::update( 99999, array( 'email' => 'nobody@example.com' ) );

		$this->assertWPError( $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );

		$invalid = EnquiryStore::update( 0, array( 'email' => 'nobody@example.com' ) );

		$this->assertWPError( $invalid );
		$this->assertSame( 400, $invalid->get_error_data()['status'] );

		$this->assertSame( 0, $this->count_rows( 'enquiries' ), 'Neither should have written an enquiry.' );
	}

	/* ---------------------------------------------------------------------
	 * All-or-nothing
	 * ------------------------------------------------------------------ */

	/**
	 * A failure part-way through rolls back, leaving every stored value as it was
	 * (Requirements 19.4, 19.5).
	 *
	 * The term insert is the last write the method makes, so breaking it is the
	 * case where the scalar update and the date replacement have both already
	 * happened and still must not survive.
	 *
	 * @return void
	 */
	public function test_update_rolls_back_every_write_when_a_step_fails() {
		$id     = $this->seed_enquiry();
		$before = EnquiryStore::find( $id );

		$result = $this->update_with_a_broken_term_insert( $id );

		$this->assertWPError( $result, 'A failed correction should be reported.' );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( $before, EnquiryStore::find( $id ), 'The enquiry should be exactly as it was.' );
	}

	/**
	 * Without transaction support the same failure restores the scalar values and
	 * the child rows read before the write (Requirements 19.4, 19.5).
	 *
	 * @return void
	 */
	public function test_update_restores_the_previous_values_without_transaction_support() {
		$id     = $this->seed_enquiry();
		$before = EnquiryStore::find( $id );

		$this->set_transactional( false );

		$result = $this->update_with_a_broken_term_insert( $id );

		$this->assertWPError( $result, 'A failed correction should be reported.' );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( $before, EnquiryStore::find( $id ), 'The enquiry should be exactly as it was.' );
	}

	/**
	 * Apply a correction touching scalars, dates and terms whose first term insert
	 * fails.
	 *
	 * @param int $id Enquiry identifier.
	 * @return array|\WP_Error
	 */
	private function update_with_a_broken_term_insert( $id ) {
		global $wpdb;

		add_filter( 'query', array( $this, 'break_term_insert_once' ) );

		// The failure is the point of the test, so its error is not news.
		$suppressed = $wpdb->suppress_errors( true );

		$result = EnquiryStore::update(
			$id,
			array(
				'email'        => 'grace@example.com',
				'total_guests' => 12,
			),
			array( '2026-01-31' ),
			array( 'event_type' => array( 'ceremony' ) )
		);

		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'query', array( $this, 'break_term_insert_once' ) );

		$this->assertTrue( $this->broken, 'The fixture should have broken a term insert.' );

		return $result;
	}

	/**
	 * Rewrite the first term insert into a statement that fails.
	 *
	 * Only the first, so the compensating restore can write the previous term
	 * rows back.
	 *
	 * @param string $query Statement about to run.
	 * @return string
	 */
	public function break_term_insert_once( $query ) {
		$query = (string) $query;

		if ( ! $this->broken && 0 === stripos( trim( $query ), 'INSERT' ) && false !== strpos( $query, 'taxonomy' ) ) {
			$this->broken = true;

			return 'INSERT INTO meh_no_such_table ( id ) VALUES ( 1 )';
		}

		return $query;
	}

	/**
	 * Record a statement and pass it through unchanged.
	 *
	 * @param string $query Statement about to run.
	 * @return string
	 */
	public function record_query( $query ) {
		$this->recorded[] = (string) $query;

		return $query;
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Force, or clear, the store's resolved verdict on transaction support.
	 *
	 * The verdict is read once per request and cached, so the filter it honours
	 * has already been consulted by the time a test runs. Setting the cache is the
	 * only way to exercise the non-transactional path.
	 *
	 * @param bool|null $value Verdict, or null to let it be resolved again.
	 * @return void
	 */
	private function set_transactional( $value ) {
		$property = new ReflectionProperty( EnquiryStore::class, 'transactional' );

		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	/**
	 * Write one enquiry through the store and return its identifier.
	 *
	 * @param array $fields Column overrides.
	 * @return int
	 */
	private function seed_enquiry( array $fields = array() ) {
		$defaults = array(
			'first_name'        => 'Ada',
			'last_name'         => 'Lovelace',
			'email'             => 'ada@example.com',
			'phone'             => '07700 900123',
			'total_guests'      => 80,
			'message'           => 'A summer weekend, ideally.',
			'status'            => 'new',
			'booking_id'        => 41,
			'created_at'        => '2025-06-01 10:00:00',
			'updated_at'        => '2025-06-01 10:00:00',
			'status_changed_at' => '2025-06-01 10:00:00',
			'source'            => 'webhook:enquiry-form',
		);

		$id = EnquiryStore::create(
			array_merge( $defaults, $fields ),
			array( '2025-08-23', '2025-08-16' ),
			array(
				'event_type'       => array( 'wedding', 'reception' ),
				'site_exclusivity' => array( 'whole_site' ),
			),
			array( 'submitted' => 'verbatim' )
		);

		$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

		return (int) $id;
	}

	/**
	 * Rows in one Enquiry Store table.
	 *
	 * @param string $key Table key.
	 * @return int
	 */
	private function count_rows( $key ) {
		global $wpdb;

		$table = Schema::table( $key );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
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
