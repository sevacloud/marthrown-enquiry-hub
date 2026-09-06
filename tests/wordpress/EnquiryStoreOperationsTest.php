<?php
/**
 * Unit tests for the remaining Enquiry Store operations.
 *
 * Covers the eight operations added in task 3.6 — `duplicate()`,
 * `record_rejection()`, `rejections()`, `delete_test_records()`,
 * `siblings_by_email()`, `settled_before()`, `query()` and `status_counts()` —
 * at the specific examples and boundaries the acceptance criteria name.
 *
 * Property 38 (deleting test records spares live records) is a separate
 * property test; what is asserted here about `delete_test_records()` is the
 * worked example, not the quantified claim.
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
 * Class EnquiryStoreOperationsTest
 */
class EnquiryStoreOperationsTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehop_';

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

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );
	}

	public function tear_down() {
		global $wpdb;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * duplicate()
	 * ------------------------------------------------------------------ */

	/**
	 * A copy carries the enquirer's details, dates and terms, and starts its own
	 * lifecycle (Requirements 9.4, 9.5).
	 *
	 * @return void
	 */
	public function test_duplicate_copies_details_and_resets_the_lifecycle() {
		$source_id = $this->seed_enquiry(
			array(
				'first_name'   => 'Ada',
				'last_name'    => 'Lovelace',
				'email'        => 'ada@example.com',
				'phone'        => '07700 900123',
				'total_guests' => 80,
				'message'      => 'A summer weekend, ideally.',
				'status'       => 'closed',
				'booking_id'   => 41,
				'source'       => 'webhook:enquiry-form',
				'is_test'      => 1,
			),
			array( '2025-08-23', '2025-08-16' ),
			array(
				'event_type'       => array( 'wedding', 'reception' ),
				'site_exclusivity' => array( 'whole_site' ),
			)
		);

		Clock::freeze( '2025-07-01 09:15:00' );

		$new_id = EnquiryStore::duplicate( $source_id );

		$this->assertIsInt( $new_id, 'A copy of an existing enquiry should succeed.' );
		$this->assertGreaterThan( 0, $new_id );

		$copy = EnquiryStore::find( $new_id );

		// Requirement 9.4: the enquirer's details, the dates and both term sets.
		$this->assertSame( 'Ada', $copy['first_name'] );
		$this->assertSame( 'Lovelace', $copy['last_name'] );
		$this->assertSame( 'ada@example.com', $copy['email'] );
		$this->assertSame( '07700 900123', $copy['phone'] );
		$this->assertSame( 80, $copy['total_guests'] );
		$this->assertSame( 'A summer weekend, ideally.', $copy['message'] );
		$this->assertSame( array( '2025-08-16', '2025-08-23' ), $copy['selected_dates'] );
		$this->assertSame( array( 'wedding', 'reception' ), $copy['event_type'] );
		$this->assertSame( array( 'whole_site' ), $copy['site_exclusivity'] );

		// Requirement 9.5: a new lifecycle, no booking, created now.
		$this->assertSame( 'new', $copy['status'] );
		$this->assertNull( $copy['booking_id'] );
		$this->assertSame( '2025-07-01 09:15:00', $copy['created_at'] );
		$this->assertSame( '2025-07-01 09:15:00', $copy['status_changed_at'] );

		// The copy of a staging record is a staging record, and its origin is
		// still where the enquiry came from.
		$this->assertTrue( $copy['is_test'] );
		$this->assertSame( 'webhook:enquiry-form', $copy['source'] );

		// No CRM call has touched the copy yet, so it carries no subscriber id:
		// reusing the source's is `ContactLinker`'s decision (Requirement 9.8).
		$this->assertNull( $copy['fluentcrm_subscriber_id'] );
		$this->assertSame( '', $copy['crm_sync_state'] );

		// Requirement 9.6: the relationship, both ways.
		$this->assertSame( $source_id, $copy['duplicated_from_id'] );

		$source = EnquiryStore::find( $source_id );

		$this->assertSame( $new_id, $source['duplicated_to_id'] );

		// Requirement 9.9: the source is otherwise untouched.
		$this->assertSame( 'closed', $source['status'] );
		$this->assertSame( 41, $source['booking_id'] );
		$this->assertSame( array( '2025-08-16', '2025-08-23' ), $source['selected_dates'] );
	}

	/**
	 * A copy of an enquiry that does not exist is a 404 and writes nothing.
	 *
	 * @return void
	 */
	public function test_duplicate_of_an_unknown_enquiry_fails_without_writing() {
		$result = EnquiryStore::duplicate( 99999 );

		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->count_rows( 'enquiries' ), 'No enquiry should have been written.' );
	}

	/**
	 * A failure while relating the two enquiries discards the copy entirely and
	 * records no relationship against the source (Requirement 9.7).
	 *
	 * The relationship is the last thing `duplicate()` writes, so breaking the
	 * write of the source's half of it is the case where every copy step has
	 * already succeeded and the copy still must not survive. The break is applied
	 * to that one statement through the test case's `query` filter, so every
	 * other read and write in the method runs normally.
	 *
	 * @return void
	 */
	public function test_duplicate_discards_the_copy_when_the_relationship_cannot_be_written() {
		$source_id = $this->seed_enquiry(
			array( 'email' => 'grace@example.com' ),
			array( '2025-09-13' ),
			array( 'event_type' => array( 'wedding' ) )
		);

		global $wpdb;

		add_filter( 'query', array( $this, 'break_relationship_write' ) );

		// The failure is the point of the test, so its error is not news.
		$suppressed = $wpdb->suppress_errors( true );
		$result     = EnquiryStore::duplicate( $source_id );

		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'query', array( $this, 'break_relationship_write' ) );

		$this->assertWPError( $result, 'A failed relationship write should be reported.' );
		$this->assertSame( 500, $result->get_error_data()['status'] );

		$this->assertSame( 1, $this->count_rows( 'enquiries' ), 'Only the source should remain.' );
		$this->assertSame( 1, $this->count_rows( 'dates' ), 'The copy should have left no candidate dates.' );
		$this->assertSame( 1, $this->count_rows( 'terms' ), 'The copy should have left no term rows.' );

		$source = EnquiryStore::find( $source_id );

		$this->assertNull( $source['duplicated_to_id'], 'The source should hold no relationship.' );
	}

	/**
	 * Rewrite the write of the source's half of the copy relationship into a
	 * statement that fails.
	 *
	 * @param string $query Statement about to run.
	 * @return string
	 */
	public function break_relationship_write( $query ) {
		if ( false !== strpos( (string) $query, 'duplicated_to_id' ) && 0 === stripos( trim( (string) $query ), 'UPDATE' ) ) {
			return 'UPDATE meh_no_such_table SET id = 1';
		}

		return $query;
	}

	/* ---------------------------------------------------------------------
	 * record_rejection() and rejections()
	 * ------------------------------------------------------------------ */

	/**
	 * A rejected intake attempt is recorded with its payload, reason and detail,
	 * and reads back decoded (Requirements 3.8, 4.3, 5.8).
	 *
	 * @return void
	 */
	public function test_record_rejection_stores_the_payload_reason_and_detail() {
		$payload = array(
			'email'          => 'ada@example.com',
			'selected_dates' => array( '2025-08-16' ),
			'nested'         => array( 'deep' => array( 'value' => 1 ) ),
		);

		$id = EnquiryStore::record_rejection(
			$payload,
			'duplicate',
			array(
				'matched_enquiry_id' => 17,
				'source'             => 'webhook:enquiry-form',
				'is_test'            => true,
				'received_at'        => '2025-07-02 11:00:00',
			)
		);

		$this->assertGreaterThan( 0, $id );

		$page = EnquiryStore::rejections();

		$this->assertSame( 1, $page['total'] );
		$this->assertCount( 1, $page['items'] );

		$row = $page['items'][0];

		$this->assertSame( 'duplicate', $row['reason'] );
		$this->assertSame( 'ada@example.com', $row['email'], 'The email should be lifted from the payload.' );
		$this->assertSame( 'webhook:enquiry-form', $row['source'] );
		$this->assertTrue( $row['is_test'] );
		$this->assertSame( '2025-07-02 11:00:00', $row['created_at'] );
		$this->assertSame( 17, $row['detail']['matched_enquiry_id'] );
		$this->assertSame( $payload, $row['payload'], 'The payload should round-trip unchanged.' );
	}

	/**
	 * The rejections list filters by reason and by email, and paginates.
	 *
	 * @return void
	 */
	public function test_rejections_filters_by_reason_and_email() {
		EnquiryStore::record_rejection( array( 'email' => 'ada@example.com' ), 'duplicate' );
		EnquiryStore::record_rejection( array( 'email' => 'grace@example.com' ), 'validation' );
		EnquiryStore::record_rejection( array( 'email' => 'grace@example.com' ), 'rate_limited' );

		$this->assertSame( 3, EnquiryStore::rejections()['total'] );
		$this->assertSame( 1, EnquiryStore::rejections( array( 'reason' => 'duplicate' ) )['total'] );
		$this->assertSame( 2, EnquiryStore::rejections( array( 's' => 'grace@' ) )['total'] );

		$page = EnquiryStore::rejections( array( 'per_page' => 2 ) );

		$this->assertCount( 2, $page['items'], 'The page should hold at most per_page rows.' );
		$this->assertSame( 3, $page['total'], 'The total should count every match, not the page.' );
		$this->assertSame( 2, $page['total_pages'] );
	}

	/* ---------------------------------------------------------------------
	 * delete_test_records()
	 * ------------------------------------------------------------------ */

	/**
	 * Deleting test records removes their child rows and spares live records
	 * (Requirements 17.7, 17.8).
	 *
	 * @return void
	 */
	public function test_delete_test_records_removes_test_rows_and_spares_live_rows() {
		$test_id = $this->seed_enquiry(
			array(
				'email'   => 'test@example.com',
				'is_test' => 1,
			),
			array( '2025-08-16' ),
			array( 'event_type' => array( 'wedding' ) )
		);

		$live_id = $this->seed_enquiry(
			array(
				'email'   => 'live@example.com',
				'is_test' => 0,
			),
			array( '2025-09-13', '2025-09-20' ),
			array( 'site_exclusivity' => array( 'whole_site' ) )
		);

		$this->seed_child( 'notes', $test_id );
		$this->seed_child( 'notes', $live_id );
		$this->seed_child( 'history', $test_id );
		$this->seed_child( 'history', $live_id );

		$live_before = EnquiryStore::find( $live_id );

		$this->assertSame( 1, EnquiryStore::delete_test_records(), 'One test enquiry should be reported deleted.' );

		$this->assertNull( EnquiryStore::find( $test_id ), 'The test enquiry should be gone.' );
		$this->assertSame( $live_before, EnquiryStore::find( $live_id ), 'The live enquiry should be unchanged.' );

		foreach ( array( 'dates', 'terms', 'notes', 'history' ) as $key ) {
			$this->assertSame(
				0,
				$this->count_children( $key, $test_id ),
				sprintf( 'The test enquiry should hold no %s rows.', $key )
			);

			$this->assertGreaterThan(
				0,
				$this->count_children( $key, $live_id ),
				sprintf( 'The live enquiry should keep its %s rows.', $key )
			);
		}
	}

	/**
	 * With no test records stored, the delete reports nothing and touches nothing.
	 *
	 * @return void
	 */
	public function test_delete_test_records_reports_zero_when_there_are_none() {
		$live_id = $this->seed_enquiry( array( 'email' => 'live@example.com' ), array( '2025-09-13' ) );

		$this->assertSame( 0, EnquiryStore::delete_test_records() );
		$this->assertNotNull( EnquiryStore::find( $live_id ) );
	}

	/* ---------------------------------------------------------------------
	 * siblings_by_email()
	 * ------------------------------------------------------------------ */

	/**
	 * Siblings are the other enquiries holding the same email, most recent first
	 * (Requirement 13.3).
	 *
	 * @return void
	 */
	public function test_siblings_by_email_summarises_the_other_enquiries() {
		$older = $this->seed_enquiry(
			array(
				'email'      => 'ada@example.com',
				'status'     => 'closed',
				'created_at' => '2024-09-02 12:00:00',
			)
		);

		$newer = $this->seed_enquiry(
			array(
				'email'      => 'ada@example.com',
				'status'     => 'new',
				'created_at' => '2025-06-01 10:04:11',
			)
		);

		$other = $this->seed_enquiry( array( 'email' => 'grace@example.com' ) );

		$siblings = EnquiryStore::siblings_by_email( 'ada@example.com', $newer );

		$this->assertSame(
			array(
				array(
					'id'         => $older,
					'created_at' => '2024-09-02 12:00:00',
					'status'     => 'closed',
				),
			),
			$siblings,
			'Only the other same-email enquiry should be summarised.'
		);

		$both = EnquiryStore::siblings_by_email( 'ada@example.com', 0 );

		$this->assertSame( array( $newer, $older ), wp_list_pluck( $both, 'id' ), 'Most recent first.' );
		$this->assertSame( array(), EnquiryStore::siblings_by_email( '', 0 ), 'An empty email relates nothing.' );

		unset( $other );
	}

	/* ---------------------------------------------------------------------
	 * settled_before()
	 * ------------------------------------------------------------------ */

	/**
	 * Only settled enquiries that settled strictly before the cutoff are returned,
	 * and the settled set is exactly `converted` and `lost` (Requirement 7.12).
	 *
	 * @return void
	 */
	public function test_settled_before_returns_only_settled_enquiries_past_the_cutoff() {
		$converted = $this->seed_enquiry(
			array(
				'status'            => 'converted',
				'status_changed_at' => '2025-01-01 00:00:00',
			)
		);

		$lost = $this->seed_enquiry(
			array(
				'status'            => 'lost',
				'status_changed_at' => '2025-01-02 00:00:00',
			)
		);

		// On the cutoff exactly: not yet past it.
		$this->seed_enquiry(
			array(
				'status'            => 'lost',
				'status_changed_at' => '2025-02-01 00:00:00',
			)
		);

		// Unsettled however long they sit (Requirement 7.12).
		foreach ( array( 'new', 'contacted', 'quoted', 'closed' ) as $status ) {
			$this->seed_enquiry(
				array(
					'status'            => $status,
					'status_changed_at' => '2024-01-01 00:00:00',
				)
			);
		}

		$this->assertSame(
			array( $converted, $lost ),
			EnquiryStore::settled_before( '2025-02-01 00:00:00' ),
			'Only converted and lost enquiries settled before the cutoff should be returned.'
		);

		$this->assertSame(
			array( 'converted', 'lost' ),
			EnquiryStore::settled_statuses(),
			'The settled set falls back to converted and lost while Lifecycle is not loaded.'
		);

		$this->assertSame( array(), EnquiryStore::settled_before( '' ), 'No cutoff closes nothing.' );
	}

	/**
	 * An enquiry whose status change time was never recorded is not auto-closable.
	 *
	 * @return void
	 */
	public function test_settled_before_skips_an_unset_status_change_time() {
		global $wpdb;

		$id = $this->seed_enquiry( array( 'status' => 'converted' ) );

		// Written directly: the store's own coercion turns every value into a
		// real time, so the column default is only reachable this way.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table( 'enquiries' ) . ' SET status_changed_at = %s WHERE id = %d', // phpcs:ignore WordPress.DB
				EnquiryStore::ZERO_DATETIME,
				$id
			)
		);

		$this->assertSame(
			'',
			EnquiryStore::find( $id )['status_changed_at'],
			'The fixture should hold the unset status change time.'
		);

		$this->assertSame( array(), EnquiryStore::settled_before( '2030-01-01 00:00:00' ) );
	}

	/* ---------------------------------------------------------------------
	 * query() and status_counts()
	 * ------------------------------------------------------------------ */

	/**
	 * The list returns hydrated enquiries, the full matching total and the
	 * per-status counts (Requirements 12.1, 12.9, 12.11, 12.14).
	 *
	 * @return void
	 */
	public function test_query_returns_hydrated_items_with_the_total_and_counts() {
		$first = $this->seed_enquiry(
			array(
				'email'      => 'ada@example.com',
				'status'     => 'new',
				'created_at' => '2025-06-01 10:00:00',
			),
			array( '2025-08-16' ),
			array( 'event_type' => array( 'wedding' ) )
		);

		$second = $this->seed_enquiry(
			array(
				'email'      => 'grace@example.com',
				'status'     => 'quoted',
				'created_at' => '2025-06-02 10:00:00',
			),
			array( '2025-09-13' )
		);

		$third = $this->seed_enquiry(
			array(
				'email'      => 'alan@example.com',
				'status'     => 'quoted',
				'created_at' => '2025-06-03 10:00:00',
			)
		);

		$page = EnquiryStore::query( array( 'per_page' => 2 ) );

		// Requirement 12.14: most recent first.
		$this->assertSame( array( $third, $second ), wp_list_pluck( $page['items'], 'id' ) );

		// Requirement 12.11: the total counts every match, not the page.
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( 2, $page['total_pages'] );
		$this->assertSame( array(), $page['warnings'] );

		// Requirement 12.9: counts per status, plus `all`.
		$this->assertSame( 3, $page['counts']['all'] );
		$this->assertSame( 1, $page['counts']['new'] );
		$this->assertSame( 2, $page['counts']['quoted'] );
		$this->assertSame( 0, $page['counts']['closed'] );

		// The items are hydrated, child rows and all.
		$this->assertSame( EnquiryStore::find( $first ), EnquiryStore::query( array( 'status' => 'new' ) )['items'][0] );
	}

	/**
	 * The counts describe every other supplied filter but ignore `status`, so a
	 * selected tab does not zero the others (Requirement 12.9).
	 *
	 * @return void
	 */
	public function test_status_counts_ignore_the_status_filter_but_apply_the_others() {
		$this->seed_enquiry(
			array(
				'status'  => 'new',
				'is_test' => 1,
			)
		);
		$this->seed_enquiry( array( 'status' => 'new' ) );
		$this->seed_enquiry( array( 'status' => 'lost' ) );

		$counts = EnquiryStore::status_counts( array( 'status' => 'lost' ) );

		$this->assertSame( 2, $counts['new'], 'A status filter should not zero the other statuses.' );
		$this->assertSame( 1, $counts['lost'] );
		$this->assertSame( 3, $counts['all'] );

		$hidden = EnquiryStore::status_counts( array( 'hide_test' => true ) );

		$this->assertSame( 1, $hidden['new'], 'Other filters should still apply.' );
		$this->assertSame( 2, $hidden['all'] );
	}

	/**
	 * A lone candidate-date bound applies no filter and carries its warning
	 * through the store (Requirement 12.8).
	 *
	 * @return void
	 */
	public function test_query_passes_the_lone_candidate_date_bound_warning_through() {
		$this->seed_enquiry( array( 'status' => 'new' ), array( '2025-08-16' ) );

		$page = EnquiryStore::query( array( 'date_from' => '2030-01-01' ) );

		$this->assertSame( 1, $page['total'], 'A lone bound should filter nothing.' );
		$this->assertSame( array( 'date_to missing' ), $page['warnings'] );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write one enquiry through the store and return its identifier.
	 *
	 * @param array $fields Column overrides.
	 * @param array $dates  Candidate dates.
	 * @param array $terms  Term lists keyed by taxonomy.
	 * @return int
	 */
	private function seed_enquiry( array $fields = array(), array $dates = array(), array $terms = array() ) {
		$defaults = array(
			'first_name'        => 'Ada',
			'last_name'         => 'Lovelace',
			'email'             => 'ada@example.com',
			'status'            => 'new',
			'created_at'        => '2025-06-01 10:00:00',
			'updated_at'        => '2025-06-01 10:00:00',
			'status_changed_at' => '2025-06-01 10:00:00',
			'source'            => 'webhook:fixture',
		);

		$id = EnquiryStore::create( array_merge( $defaults, $fields ), $dates, $terms, array( 'seeded' => true ) );

		$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

		return (int) $id;
	}

	/**
	 * Write one note or history row against an enquiry.
	 *
	 * @param string $key Table key, `notes` or `history`.
	 * @param int    $id  Enquiry identifier.
	 * @return void
	 */
	private function seed_child( $key, $id ) {
		global $wpdb;

		$row = array(
			'enquiry_id' => (int) $id,
			'created_at' => '2025-06-01 10:00:00',
		);

		if ( 'notes' === $key ) {
			$row['body']      = 'A note.';
			$row['author_id'] = 3;
		} else {
			$row['entry_type']  = 'created';
			$row['description'] = 'Enquiry created.';
			$row['actor_id']    = 0;
		}

		$this->assertNotFalse(
			$wpdb->insert( Schema::table( $key ), $row ),
			sprintf( 'Seeding a %s row should succeed: %s', $key, $wpdb->last_error )
		);
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
	 * Child rows of one enquiry in one table.
	 *
	 * @param string $key Table key.
	 * @param int    $id  Enquiry identifier.
	 * @return int
	 */
	private function count_children( $key, $id ) {
		global $wpdb;

		$table = Schema::table( $key );

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE enquiry_id = %d", (int) $id ) // phpcs:ignore WordPress.DB
		);
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
