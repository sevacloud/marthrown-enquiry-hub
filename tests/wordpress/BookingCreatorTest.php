<?php
/**
 * Worked examples for `BookingCreator::create_from_enquiry()`.
 *
 * Properties 33 and 34 quantify conversion and its guards over generated
 * enquiries; what is asserted here is one worked example of each outcome against
 * real rows and a recording WPBS fake: the happy path inserting one booking,
 * blocking one day with the calendar's own `booked` legend item, recording
 * `booking_id`, appending `booking_linked` and transitioning to `converted`;
 * each guard refusing with its documented status and leaving nothing behind; a
 * second conversion refused; a calendar with no `booked` legend item keeping the
 * booking and warning; and the staging copy prefixing the guest name.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix, because `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\BookingCreator;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeWpbs;

/**
 * Class BookingCreatorTest
 */
class BookingCreatorTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehbc_';

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The installed WPBS fake.
	 *
	 * @var FakeWpbs
	 */
	private $wpbs;

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
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-source-wpbs.php';
		require_once MEH_INCLUDES_DIR . 'class-booking-creator.php';
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

		$this->wpbs = FakeWpbs::install();
	}

	public function tear_down() {
		global $wpdb;

		FakeWpbs::uninstall();
		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * The happy path (Requirements 14.2, 14.3, 14.4, 14.5, 14.6, 14.7).
	 *
	 * @return void
	 */
	public function test_conversion_creates_blocks_records_and_transitions() {
		$id = $this->seed_enquiry();

		Clock::freeze( '2025-07-04 14:30:00' );

		$result = BookingCreator::create_from_enquiry( $id, 1, '2025-08-16' );

		$this->assertIsArray( $result, 'Conversion should succeed.' );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertGreaterThan( 0, $result['booking_id'] );
		$this->assertSame( '2025-08-16', $result['date'] );
		$this->assertStringContainsString( 'booking_id=' . $result['booking_id'], $result['edit_url'] );

		$booking = $this->wpbs->booking( $result['booking_id'] );

		// Requirement 14.2: start and end are both the chosen candidate date.
		$this->assertSame( 1, (int) $booking['calendar_id'] );
		$this->assertSame( '2025-08-16', $booking['start_date'] );
		$this->assertSame( '2025-08-16', $booking['end_date'] );

		// Requirement 14.3, and Requirement 14.11: no WPBS form is involved.
		$this->assertSame( 'Ada Lovelace', $booking['fields'][0]['user_value'] );
		$this->assertSame( 'ada@example.com', $booking['fields'][1]['user_value'] );
		$this->assertSame( 0, (int) $booking['form_id'] );

		// Requirement 14.4: one blocked day, using the calendar's booked legend
		// item — 13 is the `booked` item the fake seeds for calendar 1.
		$events = $this->wpbs->events_for( $result['booking_id'] );

		$this->assertCount( 1, $events );
		$this->assertSame( 13, (int) $events[0]['legend_item_id'] );
		$this->assertSame(
			array( 2025, 8, 16 ),
			array( (int) $events[0]['date_year'], (int) $events[0]['date_month'], (int) $events[0]['date_day'] )
		);

		$enquiry = EnquiryStore::find( $id );

		// Requirements 14.5, 14.6.
		$this->assertSame( $result['booking_id'], $enquiry['booking_id'] );
		$this->assertSame( 'converted', $enquiry['status'] );
		$this->assertSame( '2025-07-04 14:30:00', $enquiry['status_changed_at'] );

		// Requirement 14.7.
		$linked = array_values(
			array_filter(
				HistoryRecorder::for_enquiry( $id ),
				static function ( $entry ) {
					return 'booking_linked' === $entry['entry_type'];
				}
			)
		);

		$this->assertCount( 1, $linked );
		$this->assertSame( $result['booking_id'], (int) $linked[0]['context']['booking_id'] );
		$this->assertSame( '2025-08-16', $linked[0]['context']['date'] );
	}

	/**
	 * Every guard refuses with its documented status and leaves nothing behind
	 * (Requirements 14.8, 14.9, and the existence and closure guards).
	 *
	 * @return void
	 */
	public function test_guards_reject_without_side_effects() {
		$id = $this->seed_enquiry();

		// Requirement 14.8: a calendar WPBS does not know.
		$bad_calendar = BookingCreator::create_from_enquiry( $id, 99, '2025-08-16' );
		$this->assertWPError( $bad_calendar );
		$this->assertSame( 400, $bad_calendar->get_error_data()['status'] );

		// A date the enquirer never offered.
		$bad_date = BookingCreator::create_from_enquiry( $id, 1, '2025-09-01' );
		$this->assertWPError( $bad_date );
		$this->assertSame( 400, $bad_date->get_error_data()['status'] );

		// An enquiry that does not exist.
		$missing = BookingCreator::create_from_enquiry( $id + 5000, 1, '2025-08-16' );
		$this->assertWPError( $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );

		// A closed enquiry.
		$closed        = $this->seed_enquiry( array( 'status' => 'closed' ) );
		$closed_result = BookingCreator::create_from_enquiry( $closed, 1, '2025-08-16' );
		$this->assertWPError( $closed_result );
		$this->assertSame( 409, $closed_result->get_error_data()['status'] );

		// Requirement 14.9: WPBS inactive.
		add_filter( 'meh_wpbs_available', '__return_false' );
		$unavailable = BookingCreator::create_from_enquiry( $id, 1, '2025-08-16' );
		remove_filter( 'meh_wpbs_available', '__return_false' );

		$this->assertWPError( $unavailable );
		$this->assertSame( 503, $unavailable->get_error_data()['status'] );

		$this->assertSame( array(), $this->wpbs->bookings(), 'No guard should create a booking.' );
		$this->assertSame( array(), $this->wpbs->events(), 'No guard should block a date.' );
		$this->assertSame( 'new', EnquiryStore::find( $id )['status'] );
		$this->assertNull( EnquiryStore::find( $id )['booking_id'] );
		$this->assertSame( array(), HistoryRecorder::for_enquiry( $id ) );
		$this->assertSame( 'closed', EnquiryStore::find( $closed )['status'] );
	}

	/**
	 * Requirement 14.10: one enquiry, one booking.
	 *
	 * @return void
	 */
	public function test_a_second_conversion_is_refused() {
		$id = $this->seed_enquiry();

		$first = BookingCreator::create_from_enquiry( $id, 1, '2025-08-16' );

		$this->assertIsArray( $first );

		$second = BookingCreator::create_from_enquiry( $id, 2, '2025-08-16' );

		$this->assertWPError( $second );
		$this->assertSame( 409, $second->get_error_data()['status'] );
		$this->assertSame( $first['booking_id'], $second->get_error_data()['booking_id'] );
		$this->assertCount( 1, $this->wpbs->bookings(), 'No additional booking should exist.' );
		$this->assertSame( $first['booking_id'], EnquiryStore::find( $id )['booking_id'] );
	}

	/**
	 * A calendar with no `booked` legend item keeps the booking and warns: the
	 * booking exists, so discarding it would be the worse answer.
	 *
	 * @return void
	 */
	public function test_a_blocking_failure_keeps_the_booking() {
		$id = $this->seed_enquiry();

		$this->wpbs->add_calendar( 3, 'No Legend' );

		$result = BookingCreator::create_from_enquiry( $id, 3, '2025-08-16' );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['booking_id'] );
		$this->assertSame( 0, $result['blocked'] );
		$this->assertContains( 'meh_booking_date_not_blocked', $result['warnings'] );
		$this->assertSame( array(), $this->wpbs->events_for( $result['booking_id'] ) );
		$this->assertSame( $result['booking_id'], EnquiryStore::find( $id )['booking_id'] );
		$this->assertSame( 'converted', EnquiryStore::find( $id )['status'] );
	}

	/**
	 * Requirement 17.3: the staging copy prefixes the booking guest name.
	 *
	 * @return void
	 */
	public function test_staging_prefixes_the_guest_name() {
		$id = $this->seed_enquiry();

		add_filter( 'meh_is_staging', '__return_true' );
		$result = BookingCreator::create_from_enquiry( $id, 1, '2025-08-16' );
		remove_filter( 'meh_is_staging', '__return_true' );

		$this->assertIsArray( $result );

		$booking = $this->wpbs->booking( $result['booking_id'] );

		$this->assertStringStartsWith( 'TEST_', $booking['fields'][0]['user_value'] );
		$this->assertSame( 'ada@example.com', $booking['fields'][1]['user_value'], 'The email is not prefixed.' );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write one enquiry, holding a single candidate date, through the store.
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
