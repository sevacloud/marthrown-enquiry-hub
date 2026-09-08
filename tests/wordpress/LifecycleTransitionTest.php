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
 * `settled_before()` closes the loop on Requirement 7.12 with the lifecycle
 * loaded: it returns `converted` and `lost` enquiries only, so a `quoted` enquiry
 * that has sat for months is still not a candidate for auto-closure.
 *
 * The last two tests cover the other direction — reading a closure back.
 * `closed_from()` recovers the status an enquiry held when it closed rather than
 * storing it a second time, which is only sound because `closed` is terminal:
 * with nowhere to go from it, the closure is necessarily the last status change,
 * so the `from` of that entry is the outcome. The tests assert exactly that
 * reading, including the two cases that have no answer.
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

	/**
	 * The status an enquiry held when it closed is recovered from the trail rather
	 * than stored a second time.
	 *
	 * Both closure routes are covered, because both are the same route: the
	 * auto-close job goes through `transition()` like a person clicking a button,
	 * so both leave the `status_changed` entry this reads. The two answers that are
	 * deliberately empty matter as much as the two that are not — an enquiry that
	 * has not closed has no outcome, and neither has one whose closure predates the
	 * trail, and inventing one for either would be worse than reporting none.
	 *
	 * @return void
	 */
	public function test_the_closure_outcome_is_recovered_from_the_trail() {
		$won  = $this->seed_enquiry( array( 'status' => 'converted' ) );
		$lost = $this->seed_enquiry( array( 'status' => 'lost' ) );
		$open = $this->seed_enquiry( array( 'status' => 'quoted' ) );

		// Closed by the store rather than by the lifecycle, so nothing recorded how
		// it got there. This is what a row migrated in from before the trail looks
		// like.
		$untraced = $this->seed_enquiry( array( 'status' => 'closed' ) );

		Clock::freeze( '2025-09-01 09:00:00' );

		$this->assertTrue( Lifecycle::transition( $won, 'closed', 7 )['changed'] );
		$this->assertTrue( Lifecycle::transition( $lost, 'closed', 0 )['changed'] );

		// A later entry of another type must not displace the answer: it is the
		// latest *status change* that records the closure, not the latest entry.
		HistoryRecorder::record( $won, 'note_added', 'Filed the paperwork.', array(), 7 );

		$this->assertSame( 'converted', Lifecycle::closed_from( $won ) );
		$this->assertSame( 'lost', Lifecycle::closed_from( $lost ) );
		$this->assertSame( '', Lifecycle::closed_from( $open ) );
		$this->assertSame( '', Lifecycle::closed_from( $untraced ) );
		$this->assertSame( '', Lifecycle::closed_from( 987654 ) );

		// The batch read is what a list page uses, and answers for the enquiries
		// that have an outcome and for no others — an absent key, not an empty one.
		$outcomes = Lifecycle::closed_from_many( array( $won, $lost, $open, $untraced ) );

		// Sorted before comparing: the map is read by identifier, so the order the
		// rows happened to come back in is not part of the answer.
		ksort( $outcomes );

		$this->assertSame(
			array(
				$won  => 'converted',
				$lost => 'lost',
			),
			$outcomes
		);

		$this->assertSame( array(), Lifecycle::closed_from_many( array() ) );
	}

	/**
	 * An enquiry that passed through a settled status on its way somewhere else
	 * reports the status it closed *from*, not the first one it left.
	 *
	 * `new → lost → closed` and `new → converted → closed` both end at `closed`
	 * through one intermediate status, so the reading has to be of the last status
	 * change rather than of the first, and this is the shape that tells the two
	 * apart.
	 *
	 * @return void
	 */
	public function test_the_outcome_is_the_last_status_before_closure() {
		$id = $this->seed_enquiry( array( 'status' => 'new' ) );

		Clock::freeze( '2025-09-01 09:00:00' );
		$this->assertTrue( Lifecycle::transition( $id, 'contacted', 7 )['changed'] );

		Clock::freeze( '2025-09-02 09:00:00' );
		$this->assertTrue( Lifecycle::transition( $id, 'lost', 7 )['changed'] );

		// Before the closure there is no outcome to report, however many status
		// changes the enquiry has behind it.
		$this->assertSame( '', Lifecycle::closed_from( $id ) );

		Clock::freeze( '2025-09-03 09:00:00' );
		$this->assertTrue( Lifecycle::transition( $id, 'closed', 7 )['changed'] );

		$this->assertSame( 'lost', Lifecycle::closed_from( $id ) );
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
