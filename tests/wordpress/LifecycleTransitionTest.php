<?php
/**
 * Worked examples for `Lifecycle::transition()`.
 *
 * The transition table itself is covered by the `pure` suite; Properties 20 and
 * 21 quantify the behaviour over every status pair. What is asserted here is the
 * worked example of each of the three outcomes against real rows: a permitted
 * move writes both status columns, appends one `status_changed` entry and fires
 * the hook; a repeat writes nothing; a refusal names both statuses and changes
 * nothing.
 *
 * The last test closes the loop on Requirement 7.12 with the lifecycle loaded:
 * `settled_before()` returns `converted` and `lost` enquiries only, so a `quoted`
 * enquiry that has sat for months is still not a candidate for auto-closure.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix, because `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Lifecycle;
use MarthrownEnquiryHub\Schema;

/**
 * Class LifecycleTransitionTest
 */
class LifecycleTransitionTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehlc_';

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Hook payloads observed during one test.
	 *
	 * @var array
	 */
	private $fired = array();

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

		$this->fired = array();

		add_action( Lifecycle::CHANGED_HOOK, array( $this, 'observe' ), 10, 3 );
	}

	public function tear_down() {
		global $wpdb;

		remove_action( Lifecycle::CHANGED_HOOK, array( $this, 'observe' ), 10 );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Record one `meh_enquiry_status_changed` payload.
	 *
	 * @param int    $id   Enquiry identifier.
	 * @param string $from Previous status.
	 * @param string $to   New status.
	 * @return void
	 */
	public function observe( $id, $from, $to ) {
		$this->fired[] = array( (int) $id, (string) $from, (string) $to );
	}

	/**
	 * A permitted move writes both status columns, records one history entry and
	 * fires the hook (Requirements 7.6, 7.7, 7.8).
	 *
	 * @return void
	 */
	public function test_a_permitted_transition_writes_records_and_announces() {
		$id = $this->seed_enquiry( array( 'status' => 'new' ) );

		Clock::freeze( '2025-07-04 14:30:00' );

		$result = Lifecycle::transition( $id, 'contacted', 7 );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'new', $result['from'] );
		$this->assertSame( 'contacted', $result['to'] );
		$this->assertSame( '2025-07-04 14:30:00', $result['at'] );

		$enquiry = EnquiryStore::find( $id );

		$this->assertSame( 'contacted', $enquiry['status'] );
		$this->assertSame( '2025-07-04 14:30:00', $enquiry['status_changed_at'] );

		// Only the two status columns move: the transition is not an edit.
		$this->assertSame( '2025-06-01 10:00:00', $enquiry['created_at'] );
		$this->assertSame( '2025-06-01 10:00:00', $enquiry['updated_at'] );

		$history = HistoryRecorder::for_enquiry( $id );

		$this->assertCount( 1, $history );
		$this->assertSame( 'status_changed', $history[0]['entry_type'] );
		$this->assertSame( 'new', $history[0]['context']['from'] );
		$this->assertSame( 'contacted', $history[0]['context']['to'] );
		$this->assertSame( '2025-07-04 14:30:00', $history[0]['context']['at'] );
		$this->assertSame( 7, $history[0]['actor_id'] );

		$this->assertSame( array( array( $id, 'new', 'contacted' ) ), $this->fired );
	}

	/**
	 * Requirement 7.9: an enquiry already holding the requested status is a
	 * success that writes nothing and records nothing.
	 *
	 * @return void
	 */
	public function test_repeating_a_transition_writes_nothing() {
		$id = $this->seed_enquiry( array( 'status' => 'new' ) );

		Clock::freeze( '2025-07-04 14:30:00' );
		$this->assertTrue( Lifecycle::transition( $id, 'quoted', 7 )['changed'] );

		Clock::freeze( '2025-07-09 09:00:00' );
		$repeat = Lifecycle::transition( $id, 'quoted', 7 );

		$this->assertIsArray( $repeat, 'A repeat is a success, not a failure.' );
		$this->assertFalse( $repeat['changed'] );
		$this->assertSame( 'quoted', $repeat['from'] );

		$enquiry = EnquiryStore::find( $id );

		$this->assertSame( 'quoted', $enquiry['status'] );
		$this->assertSame(
			'2025-07-04 14:30:00',
			$enquiry['status_changed_at'],
			'The repeat should leave the settlement clock where the first move set it.'
		);

		$this->assertCount( 1, HistoryRecorder::for_enquiry( $id ), 'The repeat should append no entry.' );
		$this->assertCount( 1, $this->fired, 'The repeat should fire no hook.' );
	}

	/**
	 * Requirements 7.5, 7.11: a refusal names both statuses and changes nothing,
	 * including a request made of a closed enquiry.
	 *
	 * @return void
	 */
	public function test_an_impermissible_transition_is_refused_and_changes_nothing() {
		$id = $this->seed_enquiry(
			array(
				'status'            => 'closed',
				'status_changed_at' => '2025-06-20 08:00:00',
			)
		);

		Clock::freeze( '2025-07-04 14:30:00' );

		$result = Lifecycle::transition( $id, 'contacted', 7 );

		$this->assertWPError( $result );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'closed', $result->get_error_message() );
		$this->assertStringContainsString( 'contacted', $result->get_error_message() );

		$enquiry = EnquiryStore::find( $id );

		$this->assertSame( 'closed', $enquiry['status'] );
		$this->assertSame( '2025-06-20 08:00:00', $enquiry['status_changed_at'] );
		$this->assertSame( array(), HistoryRecorder::for_enquiry( $id ) );
		$this->assertSame( array(), $this->fired );

		// A backwards move is refused by the same lookup.
		$quoted = $this->seed_enquiry( array( 'status' => 'quoted' ) );

		$this->assertWPError( Lifecycle::transition( $quoted, 'new', 7 ) );
		$this->assertSame( 'quoted', EnquiryStore::find( $quoted )['status'] );
	}

	/**
	 * Requirement 7.12: with the lifecycle loaded, the auto-closure read returns
	 * settled enquiries only, so a long-standing `quoted` enquiry is not a
	 * candidate.
	 *
	 * @return void
	 */
	public function test_the_settled_read_ignores_active_statuses() {
		$converted = $this->seed_enquiry(
			array(
				'status'            => 'converted',
				'status_changed_at' => '2025-01-02 09:00:00',
			)
		);
		$lost      = $this->seed_enquiry(
			array(
				'status'            => 'lost',
				'status_changed_at' => '2025-01-03 09:00:00',
			)
		);

		foreach ( array( 'new', 'contacted', 'quoted' ) as $active ) {
			$this->seed_enquiry(
				array(
					'status'            => $active,
					'status_changed_at' => '2024-01-01 09:00:00',
				)
			);
		}

		$this->assertSame(
			array( $converted, $lost ),
			EnquiryStore::settled_before( '2025-06-01 00:00:00' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

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
			'status'            => 'new',
			'created_at'        => '2025-06-01 10:00:00',
			'updated_at'        => '2025-06-01 10:00:00',
			'status_changed_at' => '2025-06-01 10:00:00',
			'source'            => 'webhook:fixture',
		);

		$id = EnquiryStore::create( array_merge( $defaults, $fields ), array( '2025-08-16' ) );

		$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

		return (int) $id;
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
