<?php
/**
 * Worked examples for `IntakeHandler`.
 *
 * Properties 6, 9 and 13 quantify intake behaviour over arbitrary payloads;
 * tasks 8.8 to 8.10 own those. What is asserted here is one worked example of
 * each outcome against real rows: a valid submission creates one enquiry
 * carrying the receipt time, the resolved source and the payload snapshot, plus
 * one `created` history entry; a payload naming no form identifier falls back to
 * `webhook:unidentified`; and the three rejection paths reachable without
 * breaking the store — duplicate, rate limited and validation — each create no
 * enquiry and write exactly one rejection row.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix, because `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see the test case's temporary tables.
 *
 * FluentCRM is absent from the test environment, so `ContactLinker::link()`
 * fails and leaves `crm_sync_state` at `pending`. That is Property 16's subject
 * (task 9.2); it is asserted here only to the extent of showing the enquiry
 * survives the failure.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\ContactLinker;
use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\IntakeHandler;
use MarthrownEnquiryHub\Schema;

/**
 * Class IntakeHandlerTest
 */
class IntakeHandlerTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehih_';

	/**
	 * The instant every example receives its webhook at.
	 */
	const AT = '2025-07-04 14:30:00';

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Email addresses whose rate-limit counters need forgetting.
	 *
	 * @var string[]
	 */
	private $counted = array();

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
		require_once MEH_INCLUDES_DIR . 'class-enquiry-validator.php';
		require_once MEH_INCLUDES_DIR . 'class-field-mapper.php';
		require_once MEH_INCLUDES_DIR . 'class-duplicate-detector.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-handler.php';
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

		Clock::freeze( self::AT );
	}

	public function tear_down() {
		global $wpdb;

		foreach ( $this->counted as $email ) {
			DuplicateDetector::reset( $email );
		}

		$this->counted = array();

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Requirements 2.1, 2.2, 2.3, 2.5, 2.6, 2.13, 5.1: a valid submission becomes
	 * one enquiry at status `new`, stamped with the receipt time, carrying the
	 * resolved source, its candidate date ranges, its terms and the verbatim payload,
	 * with one `created` history entry and no rejection row.
	 *
	 * @return void
	 */
	public function test_a_valid_submission_creates_one_enquiry() {
		$payload = $this->payload();

		$outcome = IntakeHandler::receive( $payload, 'webhook:enquiry-form', self::AT );

		$this->assertTrue( $outcome['created'] );
		$this->assertGreaterThan( 0, $outcome['enquiry_id'] );
		$this->assertSame( '', $outcome['reason'] );
		$this->assertSame( array(), $outcome['errors'] );

		$enquiry = EnquiryStore::find( $outcome['enquiry_id'] );

		$this->assertIsArray( $enquiry );
		$this->assertSame( 'new', $enquiry['status'] );
		$this->assertSame( self::AT, $enquiry['created_at'] );
		$this->assertSame( self::AT, $enquiry['updated_at'] );
		$this->assertSame( self::AT, $enquiry['status_changed_at'] );
		$this->assertSame( 'webhook:enquiry-form', $enquiry['source'] );
		$this->assertFalse( $enquiry['is_test'], 'The test environment is not the staging copy.' );

		// Resolved by label, with nothing configured (Requirement 2.10).
		$this->assertSame( 'Ada', $enquiry['first_name'] );
		$this->assertSame( 'Lovelace', $enquiry['last_name'] );
		$this->assertSame( 'ada@example.com', $enquiry['email'] );
		$this->assertSame( '0114 496 0000', $enquiry['phone'] );
		$this->assertSame( 40, $enquiry['total_guests'] );
		$this->assertSame( 'Looking at the barn for a September wedding.', $enquiry['message'] );

		// The ideal range as submitted, then the bare date as the single day it
		// names: both shapes a submitted range takes, stored in rank order.
		$this->assertSame(
			array(
				array(
					'start' => '2025-09-06',
					'end'   => '2025-09-07',
				),
				array(
					'start' => '2025-09-13',
					'end'   => '2025-09-13',
				),
			),
			$enquiry['date_ranges']
		);
		$this->assertSame( array( 'wedding' ), $enquiry['event_type'] );
		$this->assertSame( array( 'full site' ), $enquiry['site_exclusivity'] );

		// Requirement 2.6: the snapshot is what arrived, labels and all.
		$this->assertSame( $payload, $enquiry['payload'] );

		$created = array_values(
			array_filter(
				HistoryRecorder::for_enquiry( $outcome['enquiry_id'] ),
				static function ( $entry ) {
					return IntakeHandler::HISTORY_TYPE === $entry['entry_type'];
				}
			)
		);

		$this->assertCount( 1, $created );
		$this->assertSame( 0, $created[0]['actor_id'], 'Intake is attributed to the system.' );
		$this->assertSame( 'webhook:enquiry-form', $created[0]['context']['source'] );

		$this->assertSame( 0, EnquiryStore::rejections()['total'] );

		// Requirements 5.1, 5.2: FluentCRM is absent here, so linkage failed and
		// the enquiry is retained as `pending` rather than lost.
		$this->assertSame( ContactLinker::STATE_PENDING, $enquiry['crm_sync_state'] );
	}

	/**
	 * Requirement 2.4: a payload naming no form identifier is stored against the
	 * fixed `webhook:unidentified` source rather than an empty one.
	 *
	 * @return void
	 */
	public function test_an_unidentified_form_falls_back_to_the_fixed_source() {
		$outcome = IntakeHandler::receive( $this->payload( array( 'Email' => 'grace@example.com' ) ), '', self::AT );

		$this->assertTrue( $outcome['created'] );
		$this->assertSame( IntakeHandler::SOURCE_UNIDENTIFIED, EnquiryStore::find( $outcome['enquiry_id'] )['source'] );
	}

	/**
	 * Requirements 4.2, 4.3: the same email and the same candidate date set
	 * inside the window is a duplicate — no second enquiry, and one rejection row
	 * naming the enquiry it repeats.
	 *
	 * @return void
	 */
	public function test_a_repeat_submission_is_rejected_as_a_duplicate() {
		$payload = $this->payload( array( 'Email' => 'charles@example.com' ) );

		$first = IntakeHandler::receive( $payload, 'webhook:enquiry-form', self::AT );

		$this->assertTrue( $first['created'] );

		$second = IntakeHandler::receive( $payload, 'webhook:enquiry-form', self::AT );

		$this->assertFalse( $second['created'] );
		$this->assertSame( 0, $second['enquiry_id'] );
		$this->assertSame( IntakeHandler::REASON_DUPLICATE, $second['reason'] );

		$this->assertSame( 1, EnquiryStore::query( array() )['total'], 'Only the first submission is stored.' );

		$rejections = EnquiryStore::rejections();

		$this->assertSame( 1, $rejections['total'] );
		$this->assertSame( IntakeHandler::REASON_DUPLICATE, $rejections['items'][0]['reason'] );
		$this->assertSame( 'charles@example.com', $rejections['items'][0]['email'] );
		$this->assertSame( 'webhook:enquiry-form', $rejections['items'][0]['source'] );
		$this->assertSame( self::AT, $rejections['items'][0]['created_at'] );
		$this->assertSame( $first['enquiry_id'], $rejections['items'][0]['detail']['duplicate_of'] );
		$this->assertSame( $payload, $rejections['items'][0]['payload'] );
	}

	/**
	 * Requirements 2.11, 3.8: a submission missing required fields creates no
	 * enquiry and leaves one rejection row holding the payload and every failing
	 * field.
	 *
	 * @return void
	 */
	public function test_a_submission_failing_validation_is_rejected_and_recorded() {
		$payload = array(
			'First Name' => 'Ada',
			'Email'      => 'ada@example.com',
			'Dates'      => array( '2025-09-06' ),
		);

		$outcome = IntakeHandler::receive( $payload, 'webhook:enquiry-form', self::AT );

		$this->assertFalse( $outcome['created'] );
		$this->assertSame( 0, $outcome['enquiry_id'] );
		$this->assertSame( IntakeHandler::REASON_VALIDATION, $outcome['reason'] );

		// `Dates` resolves nothing: the label does not match `date_ranges`, so
		// the field is absent rather than empty (Requirement 2.11).
		$this->assertSame(
			array( 'last_name', 'phone', 'total_guests', 'date_ranges', 'event_type', 'site_exclusivity', 'message' ),
			array_keys( $outcome['errors'] )
		);

		$this->assertSame( 0, EnquiryStore::query( array() )['total'] );

		$rejections = EnquiryStore::rejections();

		$this->assertSame( 1, $rejections['total'] );
		$this->assertSame( IntakeHandler::REASON_VALIDATION, $rejections['items'][0]['reason'] );
		$this->assertSame( $payload, $rejections['items'][0]['payload'] );
		$this->assertSame( $outcome['errors'], $rejections['items'][0]['detail']['errors'] );
	}

	/**
	 * Requirement 4.6: the sixth request holding one email address inside the
	 * window creates no enquiry and is recorded as rate limited.
	 *
	 * @return void
	 */
	public function test_the_sixth_request_for_one_email_is_rate_limited() {
		$email = 'burst@example.com';

		$this->counted[] = $email;

		// Distinct date sets, so each request is a genuinely new enquiry and the
		// duplicate guard never fires ahead of the rate limit.
		for ( $day = 1; $day <= 5; $day++ ) {
			$outcome = IntakeHandler::receive(
				$this->payload(
					array(
						'Email' => $email,
						'Dates' => array( sprintf( '2025-09-%02d', $day ) ),
					)
				),
				'webhook:enquiry-form',
				self::AT
			);

			$this->assertTrue( $outcome['created'], sprintf( 'Request %d should be accepted.', $day ) );
		}

		$sixth = IntakeHandler::receive(
			$this->payload(
				array(
					'Email' => $email,
					'Dates' => array( '2025-09-06' ),
				)
			),
			'webhook:enquiry-form',
			self::AT
		);

		$this->assertFalse( $sixth['created'] );
		$this->assertSame( IntakeHandler::REASON_RATE_LIMITED, $sixth['reason'] );

		$this->assertSame( 5, EnquiryStore::query( array() )['total'] );

		$rejections = EnquiryStore::rejections();

		$this->assertSame( 1, $rejections['total'] );
		$this->assertSame( IntakeHandler::REASON_RATE_LIMITED, $rejections['items'][0]['reason'] );
		$this->assertSame( $email, $rejections['items'][0]['email'] );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * A complete webhook payload, keyed by human labels rather than field names.
	 *
	 * @param array $overrides Payload keys to replace.
	 * @return array
	 */
	private function payload( array $overrides = array() ) {
		$payload = array(
			'First Name'       => 'Ada',
			'Last Name'        => 'Lovelace',
			'Email'            => 'ada@example.com',
			'Phone'            => '0114 496 0000',
			'Total Guests'     => '40',
			'Date Ranges'      => array(
				array(
					'start' => '2025-09-06',
					'end'   => '2025-09-07',
				),
				'2025-09-13',
			),
			'Event Type'       => array( 'wedding' ),
			'Site Exclusivity' => array( 'full site' ),
			'Message'          => 'Looking at the barn for a September wedding.',
		);

		if ( isset( $overrides['Dates'] ) ) {
			$overrides['Date Ranges'] = $overrides['Dates'];
			unset( $overrides['Dates'] );
		}

		$payload = array_merge( $payload, $overrides );

		if ( isset( $payload['Email'] ) ) {
			$this->counted[] = (string) $payload['Email'];
		}

		return $payload;
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
