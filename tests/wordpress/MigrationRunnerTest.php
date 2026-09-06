<?php
/**
 * Unit tests for the FluentCRM migration runner.
 *
 * Covers the worked examples behind `preview()` and `run()`: the status map, the
 * fields a migrated enquiry carries, one note per subscriber note with its own
 * creation time, the two idempotence checks, the skip reasons, the union of list
 * and tag membership, and the fact that nothing at all is written to FluentCRM.
 *
 * The quantified claims live in the property tests for Properties 35 and 36.
 *
 * FluentCRM is stood in for by tests/fakes/FakeCrm.php: contacts are seeded into
 * its read universe with `add_subscriber()`, and the write universe it records
 * separately is asserted to stay empty.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\MigrationRunner;
use MarthrownEnquiryHub\NoteService;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;

/**
 * Class MigrationRunnerTest
 */
class MigrationRunnerTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehmig_';

	/**
	 * Configured enquiry list identifier.
	 */
	const LIST_ID = 7;

	/**
	 * Configured enquiry tag identifier.
	 */
	const TAG_ID = 12;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The installed CRM fake.
	 *
	 * @var FakeCrm|null
	 */
	private $crm = null;

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
		require_once MEH_INCLUDES_DIR . 'class-note-service.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-migration-runner.php';
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

		update_option( 'meh_enquiry_list', self::LIST_ID );
		update_option( 'meh_enquiry_tag', self::TAG_ID );
		delete_option( MigrationRunner::LEDGER_OPTION );
		delete_option( MigrationRunner::COMPLETED_OPTION );

		$this->crm = FakeCrm::install();

		Clock::freeze( '2025-07-14 09:00:00' );
	}

	public function tear_down() {
		global $wpdb;

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );
		delete_option( MigrationRunner::LEDGER_OPTION );
		delete_option( MigrationRunner::COMPLETED_OPTION );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * The status map
	 * ------------------------------------------------------------------ */

	/**
	 * Every legacy value except `replied` maps to itself (Requirement 15.4).
	 *
	 * @return void
	 */
	public function test_status_map_is_the_identity_apart_from_replied() {
		$this->assertSame( 'new', MigrationRunner::map_status( 'new' ) );
		$this->assertSame( 'contacted', MigrationRunner::map_status( 'replied' ) );
		$this->assertSame( 'quoted', MigrationRunner::map_status( 'quoted' ) );
		$this->assertSame( 'converted', MigrationRunner::map_status( 'converted' ) );
		$this->assertSame( 'closed', MigrationRunner::map_status( 'closed' ) );
	}

	/**
	 * An unrecognised or absent value maps to `new` (Requirement 15.5).
	 *
	 * @return void
	 */
	public function test_unrecognised_status_maps_to_new() {
		$this->assertSame( 'new', MigrationRunner::map_status( 'archived' ) );
		$this->assertSame( 'new', MigrationRunner::map_status( '' ) );
		$this->assertSame( 'new', MigrationRunner::map_status( null ) );
		$this->assertSame( 'new', MigrationRunner::map_status( array( 'quoted' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * preview()
	 * ------------------------------------------------------------------ */

	/**
	 * Preview reports what a run would create and writes nothing
	 * (Requirement 15.8).
	 *
	 * @return void
	 */
	public function test_preview_counts_without_writing() {
		$this->seed( 'ada@example.com' );
		$this->seed( 'bob@example.com' );

		$preview = MigrationRunner::preview();

		$this->assertSame( 2, $preview['eligible'] );
		$this->assertSame( 2, $preview['would_create'] );
		$this->assertSame( 0, $preview['skipped'] );
		$this->assertSame( array(), $preview['reasons'] );

		$this->assertSame( 0, $this->enquiry_count(), 'Preview must create no enquiry.' );
		$this->assertSame( array(), MigrationRunner::ledger(), 'Preview must not write the ledger.' );
		$this->assertSame( '', MigrationRunner::completed_at(), 'Preview must not record a completion time.' );
	}

	/**
	 * Nothing eligible reports zero (Requirement 15.9).
	 *
	 * @return void
	 */
	public function test_preview_reports_zero_when_nothing_is_eligible() {
		$preview = MigrationRunner::preview();

		$this->assertSame( 0, $preview['eligible'] );
		$this->assertSame( 0, $preview['would_create'] );
	}

	/* ---------------------------------------------------------------------
	 * run()
	 * ------------------------------------------------------------------ */

	/**
	 * One enquiry per contact, carrying the contact's own values
	 * (Requirements 15.2, 15.3, 15.4, 15.6).
	 *
	 * @return void
	 */
	public function test_run_reproduces_the_contact_as_an_enquiry() {
		$this->crm->add_subscriber(
			array(
				'id'            => 41,
				'first_name'    => 'Ada',
				'last_name'     => 'Lovelace',
				'email'         => 'ada@example.com',
				'phone'         => '01142551122',
				'created_at'    => '2024-03-04 15:20:00',
				'lists'         => array( self::LIST_ID ),
				'custom_fields' => array( 'meh_enquiry_status' => 'quoted' ),
				'notes'         => array(
					array(
						'description' => 'Called back, wants the barn.',
						'created_at'  => '2024-03-05 10:00:00',
					),
					array(
						'description' => '<p>Quote sent.</p>',
						'created_at'  => '2024-03-06 11:30:00',
					),
				),
			)
		);

		$result = MigrationRunner::run();

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( '2025-07-14 09:00:00', $result['completed_at'] );

		$enquiry = EnquiryStore::find( $result['enquiry_ids'][0] );

		$this->assertSame( 'Ada', $enquiry['first_name'] );
		$this->assertSame( 'Lovelace', $enquiry['last_name'] );
		$this->assertSame( 'ada@example.com', $enquiry['email'] );
		$this->assertSame( '01142551122', $enquiry['phone'] );
		$this->assertSame( '2024-03-04 15:20:00', $enquiry['created_at'] );
		$this->assertSame( 41, (int) $enquiry['fluentcrm_subscriber_id'] );
		$this->assertSame( MigrationRunner::SOURCE, $enquiry['source'] );
		$this->assertSame( 'quoted', $enquiry['status'] );

		$notes = NoteService::for_enquiry( $enquiry['id'] );

		$this->assertCount( 2, $notes );
		$this->assertSame( 'Quote sent.', $notes[0]['body'], 'Tags are stripped and the newest note reads first.' );
		$this->assertSame( '2024-03-06 11:30:00', $notes[0]['created_at'] );
		$this->assertSame( 'Called back, wants the barn.', $notes[1]['body'] );
		$this->assertSame( '2024-03-05 10:00:00', $notes[1]['created_at'] );
	}

	/**
	 * The completion time and the ledger are recorded (Requirement 15.12).
	 *
	 * @return void
	 */
	public function test_run_records_the_ledger_and_the_completion_time() {
		$this->seed( 'ada@example.com', array( 'id' => 41 ) );
		$this->seed( 'bob@example.com', array( 'id' => 42 ) );

		MigrationRunner::run();

		$this->assertSame( array( 41, 42 ), MigrationRunner::ledger() );
		$this->assertSame( '2025-07-14 09:00:00', MigrationRunner::completed_at() );
	}

	/**
	 * A contact in the list, one holding the tag, and one in both are three
	 * contacts, not four (Requirement 15.1).
	 *
	 * @return void
	 */
	public function test_run_reads_the_union_of_list_and_tag_membership() {
		$this->crm->add_subscriber(
			array(
				'id'    => 1,
				'email' => 'listed@example.com',
				'lists' => array( self::LIST_ID ),
			)
		);
		$this->crm->add_subscriber(
			array(
				'id'    => 2,
				'email' => 'tagged@example.com',
				'tags'  => array( self::TAG_ID ),
			)
		);
		$this->crm->add_subscriber(
			array(
				'id'    => 3,
				'email' => 'both@example.com',
				'lists' => array( self::LIST_ID ),
				'tags'  => array( self::TAG_ID ),
			)
		);
		$this->crm->add_subscriber(
			array(
				'id'    => 4,
				'email' => 'elsewhere@example.com',
				'lists' => array( 99 ),
			)
		);

		$result = MigrationRunner::run();

		$this->assertSame( 3, $result['eligible'] );
		$this->assertSame( 3, $result['created'] );
		$this->assertSame( array( 1, 2, 3 ), MigrationRunner::ledger() );
	}

	/**
	 * A second run creates nothing (Requirement 15.7).
	 *
	 * @return void
	 */
	public function test_second_run_creates_no_further_enquiry() {
		$this->seed( 'ada@example.com', array( 'id' => 41 ) );

		$first = MigrationRunner::run();
		$this->assertSame( 1, $first['created'] );

		$second = MigrationRunner::run();

		$this->assertSame( 0, $second['created'] );
		$this->assertSame( 1, $second['skipped'] );
		$this->assertSame( MigrationRunner::SKIP_LEDGER, $second['reasons'][0]['reason'] );
		$this->assertSame( 1, $this->enquiry_count(), 'The enquiry set is unchanged by the second run.' );
	}

	/**
	 * A lost ledger does not cause a re-migration: the stored enquiry answers
	 * for it (Requirement 15.7).
	 *
	 * @return void
	 */
	public function test_a_stored_enquiry_stands_in_for_a_lost_ledger() {
		$this->seed( 'ada@example.com', array( 'id' => 41 ) );

		MigrationRunner::run();
		delete_option( MigrationRunner::LEDGER_OPTION );

		$result = MigrationRunner::run();

		$this->assertSame( 0, $result['created'] );
		$this->assertSame( MigrationRunner::SKIP_STORED, $result['reasons'][0]['reason'] );
		$this->assertSame( 1, $this->enquiry_count() );
		$this->assertSame( array( 41 ), MigrationRunner::ledger(), 'The run rebuilds the ledger it found missing.' );
	}

	/**
	 * Created and skipped account for every eligible contact, with a reason per
	 * skip (Requirement 15.10).
	 *
	 * @return void
	 */
	public function test_counts_account_for_every_contact_with_a_reason_per_skip() {
		$this->seed( 'ada@example.com', array( 'id' => 41 ) );
		$this->seed( '', array( 'id' => 42 ) );

		$result = MigrationRunner::run();

		$this->assertSame( 2, $result['eligible'] );
		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( $result['eligible'], $result['created'] + $result['skipped'] );
		$this->assertCount( 1, $result['reasons'] );
		$this->assertSame( MigrationRunner::SKIP_NO_EMAIL, $result['reasons'][0]['reason'] );
		$this->assertNotSame( '', $result['reasons'][0]['detail'] );
	}

	/**
	 * Nothing is written to FluentCRM (Requirement 15.11).
	 *
	 * @return void
	 */
	public function test_run_writes_nothing_to_fluentcrm() {
		$this->seed( 'ada@example.com', array( 'id' => 41 ) );
		$this->seed( 'bob@example.com', array( 'id' => 42 ) );

		$before = $this->crm->subscribers();

		MigrationRunner::run();

		$this->assertSame( $before, $this->crm->subscribers(), 'Contact records, lists and tags are untouched.' );
		$this->assertSame( array(), $this->crm->create_or_update_calls() );
		$this->assertSame( array(), $this->crm->list_calls() );
		$this->assertSame( array(), $this->crm->tag_calls() );
		$this->assertSame( 0, $this->crm->contact_count(), 'No contact was written.' );
	}

	/**
	 * With no list and no tag configured, nothing is read at all: migrating the
	 * whole CRM is never the intent.
	 *
	 * @return void
	 */
	public function test_no_configuration_migrates_nothing() {
		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		$this->seed( 'ada@example.com', array( 'id' => 41 ) );

		$result = MigrationRunner::run();

		$this->assertSame( 0, $result['eligible'] );
		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 0, $this->enquiry_count() );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Seed one contact in the configured list.
	 *
	 * @param string $email Contact email.
	 * @param array  $extra Overrides merged over the defaults.
	 * @return void
	 */
	private function seed( $email, array $extra = array() ) {
		$this->crm->add_subscriber(
			array_merge(
				array(
					'first_name' => 'Test',
					'last_name'  => 'Contact',
					'email'      => $email,
					'created_at' => '2024-01-02 03:04:05',
					'lists'      => array( self::LIST_ID ),
				),
				$extra
			)
		);
	}

	/**
	 * Enquiries currently stored.
	 *
	 * @return int
	 */
	private function enquiry_count() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'enquiries' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Drop every Enquiry Store table under the test prefix.
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
