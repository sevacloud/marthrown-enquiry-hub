<?php
/**
 * Unit tests for the FluentCRM contact linker.
 *
 * Covers the worked examples behind `available()`, `link()`, `retry()` and
 * `relink()`: what the upsert writes, what it never writes, what a failure
 * leaves behind, the staging treatment, subscriber-id reuse for a duplicated
 * enquiry, and the three re-link outcomes.
 *
 * The quantified claims live in the property tests for Properties 16, 17, 18
 * and 42; what is asserted here are the specific cases and boundaries the
 * acceptance criteria name.
 *
 * FluentCRM is stood in for by tests/fakes/FakeCrm.php, which declares the
 * global `FluentCrmApi()` shim the linker gates on. `Settings` is deliberately
 * not loaded, so the linker reads the configured list and tag from the stored
 * options.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\ContactLinker;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;

/**
 * Class ContactLinkerTest
 */
class ContactLinkerTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehcl_';

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
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
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

		update_option( 'meh_enquiry_list', 7 );
		update_option( 'meh_enquiry_tag', 12 );

		$this->crm = FakeCrm::install();

		Clock::freeze( '2025-06-02 11:30:00' );
	}

	public function tear_down() {
		global $wpdb;

		FakeCrm::uninstall();
		$this->crm = null;

		remove_filter( 'meh_is_staging', '__return_true' );
		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * available()
	 * ------------------------------------------------------------------ */

	/**
	 * Availability follows the API, not the plugin's own state
	 * (Requirement 6.7).
	 *
	 * @return void
	 */
	public function test_availability_follows_the_crm_api() {
		$this->assertTrue( ContactLinker::available(), 'An answering API is available.' );

		$this->crm->will_be_unavailable();

		$this->assertFalse( ContactLinker::available(), 'An inactive FluentCRM is not available.' );
	}

	/* ---------------------------------------------------------------------
	 * link()
	 * ------------------------------------------------------------------ */

	/**
	 * A successful link writes the four contact fields, joins the configured
	 * list, applies the configured tag, records the subscriber id and marks the
	 * enquiry `synced` (Requirements 6.1, 6.3, 6.4, 6.5, 5.5).
	 *
	 * @return void
	 */
	public function test_link_writes_the_contact_and_records_the_link() {
		$this->crm->assign_subscriber_id( 'ada@example.com', 501 );

		$id = $this->seed_enquiry(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'email'      => 'ada@example.com',
				'phone'      => '07700 900123',
				'message'    => 'A summer weekend, ideally.',
				'status'     => 'contacted',
			),
			array( '2025-08-16' ),
			array( 'event_type' => array( 'wedding' ) )
		);

		$result = ContactLinker::link( $id );

		$this->assertSame(
			array(
				'subscriber_id' => 501,
				'reused'        => false,
			),
			$result
		);

		$calls = $this->crm->create_or_update_calls();

		$this->assertCount( 1, $calls, 'One upsert per link.' );
		$this->assertSame(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'email'      => 'ada@example.com',
				'phone'      => '07700 900123',
			),
			$calls[0]
		);

		// Requirements 6.8, 6.9: nothing of the enquiry's workflow crosses over.
		$this->assertSame( ContactLinker::LINKED_FIELDS, $this->crm->written_fields() );

		// Requirement 6.4: the configured list and tag, and nothing else.
		$this->assertSame( array( 7 ), $this->crm->lists_for( 'ada@example.com' ) );
		$this->assertSame( array( 12 ), $this->crm->tags_for( 'ada@example.com' ) );

		$enquiry = EnquiryStore::find( $id );

		$this->assertSame( 501, $enquiry['fluentcrm_subscriber_id'] );
		$this->assertSame( 'synced', $enquiry['crm_sync_state'] );

		$types = $this->history_types( $id );

		$this->assertSame( array( 'crm_linked' ), $types, 'One crm_linked entry is recorded.' );
	}

	/**
	 * Two enquiries sharing an email share the contact and its subscriber id
	 * (Requirement 6.2).
	 *
	 * @return void
	 */
	public function test_two_enquiries_for_one_email_share_one_contact() {
		$this->crm->assign_subscriber_id( 'ada@example.com', 501 );

		$first  = $this->seed_enquiry( array( 'email' => 'ada@example.com' ) );
		$second = $this->seed_enquiry( array( 'email' => 'ADA@example.com' ) );

		ContactLinker::link( $first );
		ContactLinker::link( $second );

		$this->assertSame( 1, $this->crm->contact_count(), 'One contact for one email.' );
		$this->assertSame( 501, EnquiryStore::find( $first )['fluentcrm_subscriber_id'] );
		$this->assertSame( 501, EnquiryStore::find( $second )['fluentcrm_subscriber_id'] );
	}

	/**
	 * Each failure mode leaves the enquiry stored, `pending` and without a
	 * subscriber id (Requirements 5.2, 6.6, 6.7).
	 *
	 * @return void
	 */
	public function test_every_failure_mode_leaves_the_enquiry_pending() {
		$modes = array(
			'unavailable' => 'will_be_unavailable',
			'throwing'    => 'will_throw',
			'no id'       => 'will_return_no_subscriber_id',
		);

		foreach ( $modes as $label => $configure ) {
			$id = $this->seed_enquiry( array( 'email' => 'grace@example.com' ) );

			$this->crm->reset()->{$configure}();

			$result = ContactLinker::link( $id );

			$this->assertWPError( $result, sprintf( 'A %s API is a failure.', $label ) );

			$enquiry = EnquiryStore::find( $id );

			$this->assertNotNull( $enquiry, sprintf( 'The enquiry survives a %s API.', $label ) );
			$this->assertSame( 'pending', $enquiry['crm_sync_state'], sprintf( 'A %s API leaves pending.', $label ) );
			$this->assertNull( $enquiry['fluentcrm_subscriber_id'], sprintf( 'A %s API records no id.', $label ) );
			$this->assertSame( array(), $this->history_types( $id ), 'A failed link records no history.' );
		}
	}

	/**
	 * A retry after a failure links the enquiry from its stored values
	 * (Requirement 5.4).
	 *
	 * @return void
	 */
	public function test_retry_links_an_enquiry_left_pending() {
		$this->crm->will_throw();

		$id = $this->seed_enquiry( array( 'email' => 'grace@example.com' ) );

		$this->assertWPError( ContactLinker::link( $id ) );
		$this->assertSame( 'pending', EnquiryStore::find( $id )['crm_sync_state'] );

		$this->crm->will_succeed()->assign_subscriber_id( 'grace@example.com', 802 );

		$retried = ContactLinker::retry( $id );

		$this->assertSame( 802, $retried['subscriber_id'] );

		$enquiry = EnquiryStore::find( $id );

		$this->assertSame( 802, $enquiry['fluentcrm_subscriber_id'] );
		$this->assertSame( 'synced', $enquiry['crm_sync_state'] );
	}

	/**
	 * Staging prefixes the first name and applies the `test-record` tag;
	 * production does neither (Requirements 6.10, 6.11).
	 *
	 * @return void
	 */
	public function test_staging_marks_the_contact_and_production_does_not() {
		$live = $this->seed_enquiry(
			array(
				'first_name' => 'Ada',
				'email'      => 'live@example.com',
			)
		);

		ContactLinker::link( $live );

		$this->assertSame( 'Ada', $this->crm->contact( 'live@example.com' )['data']['first_name'] );
		$this->assertSame( array( 12 ), $this->crm->tags_for( 'live@example.com' ) );

		add_filter( 'meh_is_staging', '__return_true' );

		$test = $this->seed_enquiry(
			array(
				'first_name' => 'Ada',
				'email'      => 'staging@example.com',
			)
		);

		ContactLinker::link( $test );

		$this->assertSame( 'TEST_Ada', $this->crm->contact( 'staging@example.com' )['data']['first_name'] );
		$this->assertSame( array( 12, 'test-record' ), $this->crm->tags_for( 'staging@example.com' ) );
	}

	/**
	 * A duplicated enquiry reuses the subscriber id its source holds
	 * (Requirement 9.8).
	 *
	 * @return void
	 */
	public function test_a_duplicated_enquiry_reuses_the_source_subscriber_id() {
		$this->crm->assign_subscriber_id( 'ada@example.com', 501 );

		$source = $this->seed_enquiry( array( 'email' => 'ada@example.com' ) );

		ContactLinker::link( $source );

		$copy = EnquiryStore::duplicate( $source );

		$this->assertIsInt( $copy );

		// A different id would come back from the upsert if the source's were
		// not preferred.
		$this->crm->assign_subscriber_id( 'ada@example.com', 999 );

		$result = ContactLinker::link( $copy );

		$this->assertSame( 501, $result['subscriber_id'] );
		$this->assertTrue( $result['reused'] );
		$this->assertSame( 501, EnquiryStore::find( $copy )['fluentcrm_subscriber_id'] );
	}

	/* ---------------------------------------------------------------------
	 * relink()
	 * ------------------------------------------------------------------ */

	/**
	 * A change touching no linked field makes no CRM call and writes nothing
	 * (Requirement 19.17).
	 *
	 * @return void
	 */
	public function test_relink_does_nothing_when_no_linked_field_changed() {
		$this->crm->assign_subscriber_id( 'ada@example.com', 501 );

		$id = $this->seed_enquiry( array( 'email' => 'ada@example.com' ) );

		ContactLinker::link( $id );
		$this->crm->reset();

		$changed = array(
			'message'        => array(
				'from' => '',
				'to'   => 'Now with detail.',
			),
			'total_guests'   => array(
				'from' => 40,
				'to'   => 60,
			),
			'selected_dates' => array(
				'from' => array( '2025-08-16' ),
				'to'   => array( '2025-08-23' ),
			),
		);

		$this->assertNull( ContactLinker::relink( $id, $changed ) );
		$this->assertSame( 0, $this->crm->call_count(), 'No FluentCRM call at all.' );

		$enquiry = EnquiryStore::find( $id );

		$this->assertSame( 501, $enquiry['fluentcrm_subscriber_id'] );
		$this->assertSame( 'synced', $enquiry['crm_sync_state'] );
	}

	/**
	 * A corrected email resolving a different subscriber replaces the stored id
	 * and leaves the previously linked contact alone (Requirement 19.15).
	 *
	 * @return void
	 */
	public function test_a_corrected_email_replaces_the_stored_subscriber_id() {
		$this->crm->assign_subscriber_id( 'ada@exmaple.com', 501 );
		$this->crm->assign_subscriber_id( 'ada@example.com', 622 );

		$id = $this->seed_enquiry( array( 'email' => 'ada@exmaple.com' ) );

		ContactLinker::link( $id );

		$updated = EnquiryStore::update( $id, array( 'email' => 'ada@example.com' ) );

		$this->assertIsArray( $updated );
		$this->assertArrayHasKey( 'email', $updated['changed'] );

		$result = ContactLinker::relink( $id, $updated['changed'] );

		$this->assertSame( 622, $result['subscriber_id'] );
		$this->assertTrue( $result['replaced'] );

		$enquiry = EnquiryStore::find( $id );

		$this->assertSame( 622, $enquiry['fluentcrm_subscriber_id'] );
		$this->assertSame( 'synced', $enquiry['crm_sync_state'] );

		// The old contact is left exactly as it was: no delete, no merge, no tag
		// removal.
		$previous = $this->crm->contact( 'ada@exmaple.com' );

		$this->assertNotNull( $previous );
		$this->assertSame( 501, (int) $previous['subscriber_id'] );
		$this->assertSame( array( 12 ), $this->crm->tags_for( 'ada@exmaple.com' ) );
	}

	/**
	 * A failed re-link keeps the corrected values and the previous subscriber id
	 * (Requirement 19.16).
	 *
	 * @return void
	 */
	public function test_a_failed_relink_keeps_the_edit_and_the_previous_id() {
		$this->crm->assign_subscriber_id( 'ada@exmaple.com', 501 );

		$id = $this->seed_enquiry( array( 'email' => 'ada@exmaple.com' ) );

		ContactLinker::link( $id );

		$updated = EnquiryStore::update( $id, array( 'email' => 'ada@example.com' ) );

		$this->assertIsArray( $updated );

		$this->crm->will_throw();

		$this->assertWPError( ContactLinker::relink( $id, $updated['changed'] ) );

		$enquiry = EnquiryStore::find( $id );

		$this->assertSame( 'ada@example.com', $enquiry['email'], 'The correction stands.' );
		$this->assertSame( 'pending', $enquiry['crm_sync_state'] );
		$this->assertSame( 501, $enquiry['fluentcrm_subscriber_id'], 'The previous id is kept, not cleared.' );
	}

	/**
	 * A re-link is the same confined upsert as a link (Requirements 19.14, 6.9).
	 *
	 * @return void
	 */
	public function test_relink_writes_only_the_linked_fields() {
		$id = $this->seed_enquiry(
			array(
				'email'   => 'ada@example.com',
				'message' => 'A summer weekend, ideally.',
			)
		);

		ContactLinker::link( $id );

		$updated = EnquiryStore::update(
			$id,
			array(
				'phone'   => '07700 900456',
				'message' => 'A winter weekend now.',
			)
		);

		$this->assertIsArray( $updated );

		$result = ContactLinker::relink( $id, $updated['changed'] );

		$this->assertIsArray( $result );
		$this->assertSame( ContactLinker::LINKED_FIELDS, $this->crm->written_fields() );
		$this->assertSame( '07700 900456', $this->crm->contact( 'ada@example.com' )['data']['phone'] );
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
			'phone'             => '07700 900123',
			'total_guests'      => 40,
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
	 * The history entry types recorded against one enquiry, oldest first.
	 *
	 * @param int $id Enquiry identifier.
	 * @return string[]
	 */
	private function history_types( $id ) {
		$types = array();

		foreach ( HistoryRecorder::for_enquiry( $id ) as $entry ) {
			$types[] = $entry['entry_type'];
		}

		return $types;
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
