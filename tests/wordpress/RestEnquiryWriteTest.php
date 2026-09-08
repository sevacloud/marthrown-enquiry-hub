<?php
/**
 * Worked examples for the enquiry write routes.
 *
 * Properties 24 and 25 quantify closed-enquiry immutability and re-raising over
 * arbitrary enquiries; tasks 15.7 and 15.8 own those. What is asserted here is
 * one worked example of each outcome of the five write routes, against real rows,
 * a real REST server, a recording FluentCRM fake and a recording WP Booking
 * System fake:
 *
 * - `POST /enquiries/{id}/status` applies a permitted transition, refuses an
 *   unpermitted one with the lifecycle's own 409, and refuses a closed enquiry
 *   with the guard's 409 while leaving it exactly as it was.
 * - `POST /enquiries/{id}/notes` stores a note and reports it back with the
 *   enquiry, and refuses a body that holds nothing once tags are stripped.
 * - `POST /enquiries/{id}/duplicate` copies a *closed* enquiry forward, because
 *   re-raising a closed enquiry is the case Requirement 9 exists for; the copy
 *   reuses the source's subscriber identifier, both enquiries gain a `duplicated`
 *   history entry, and the source's status and notes are otherwise untouched.
 * - `POST /enquiries/{id}/convert` creates the booking and reports the converted
 *   enquiry, and passes the creator's refusal of a date the enquirer never
 *   offered straight through.
 * - `POST /enquiries/{id}/retry-crm` links a `pending` enquiry and reports it
 *   `synced`.
 * - Every one of the five answers 404 for an identifier matching nothing.
 *
 * Requests are dispatched with `rest_do_request()` rather than by calling the
 * callbacks, so the permission callback, the declared args and each route's
 * digits-only identifier pattern all run where WordPress runs them.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\NoteService;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Fakes\FakeWpbs;

/**
 * Class RestEnquiryWriteTest
 */
class RestEnquiryWriteTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehrw_';

	/**
	 * The instant the fixtures are written at.
	 */
	const NOW = '2025-06-01 10:00:00';

	/**
	 * The candidate date every fixture offers.
	 */
	const CANDIDATE = '2025-08-16';

	/**
	 * The email address every fixture uses.
	 */
	const EMAIL = 'ada@example.com';

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The acting user.
	 *
	 * @var int
	 */
	private $actor = 0;

	/**
	 * The installed CRM fake.
	 *
	 * @var FakeCrm|null
	 */
	private $crm = null;

	/**
	 * The installed WPBS fake.
	 *
	 * @var FakeWpbs|null
	 */
	private $wpbs = null;

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
		require_once MEH_INCLUDES_DIR . 'class-auth.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-query.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-store.php';
		require_once MEH_INCLUDES_DIR . 'class-history-recorder.php';
		require_once MEH_INCLUDES_DIR . 'class-note-service.php';
		require_once MEH_INCLUDES_DIR . 'class-lifecycle.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-source-wpbs.php';
		require_once MEH_INCLUDES_DIR . 'class-booking-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';
	}

	public function set_up() {
		parent::set_up();

		global $wpdb, $wp_rest_server;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		update_option( 'meh_enquiry_list', 7 );
		update_option( 'meh_enquiry_tag', 12 );

		$this->crm  = FakeCrm::install();
		$this->wpbs = FakeWpbs::install();

		Clock::freeze( self::NOW );

		$this->actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->actor );

		$wp_rest_server = null;
		add_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );
		rest_get_server();
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		FakeWpbs::uninstall();
		FakeCrm::uninstall();

		$this->crm  = null;
		$this->wpbs = null;

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		$wp_rest_server = null;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * POST /enquiries/{id}/status
	 * ------------------------------------------------------------------ */

	/**
	 * Requirements 7.6, 7.7: a permitted transition is applied, and the response
	 * carries both what changed and the enquiry as it now stands.
	 *
	 * @return void
	 */
	public function test_the_status_route_applies_a_permitted_transition() {
		$id = $this->seed_enquiry();

		Clock::freeze( '2025-06-02 09:00:00' );

		$response = $this->post( $id, 'status', array( 'status' => 'contacted' ) );
		$data     = (array) $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'new', $data['transition']['from'] );
		$this->assertSame( 'contacted', $data['transition']['to'] );
		$this->assertTrue( $data['transition']['changed'] );

		$this->assertSame( 'contacted', $data['enquiry']['status'] );
		$this->assertSame( '2025-06-02 09:00:00', $data['enquiry']['status_changed_at'] );
		$this->assertSame( 'contacted', EnquiryStore::find( $id )['status'] );

		$types = wp_list_pluck( HistoryRecorder::for_enquiry( $id ), 'entry_type' );

		$this->assertSame( array( 'status_changed' ), $types );
	}

	/**
	 * Requirement 7.5: a move the transition table does not permit is refused
	 * with the lifecycle's own error, and nothing is written.
	 *
	 * @return void
	 */
	public function test_the_status_route_passes_through_an_unpermitted_transition() {
		$id = $this->seed_enquiry();

		$response = $this->post( $id, 'status', array( 'status' => 'closed' ) );
		$data     = (array) $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'meh_lifecycle_transition_not_allowed', $data['code'] );

		$this->assertSame( 'new', EnquiryStore::find( $id )['status'] );
		$this->assertSame( array(), HistoryRecorder::for_enquiry( $id ) );
	}

	/**
	 * Requirement 9.1: a closed enquiry is refused with 409 and left byte for
	 * byte as it was, both for a status change and for a note.
	 *
	 * @return void
	 */
	public function test_a_closed_enquiry_refuses_status_and_note_writes() {
		$id = $this->seed_enquiry( array( 'status' => 'closed' ) );

		$before  = EnquiryStore::find( $id );
		$history = HistoryRecorder::for_enquiry( $id );

		$writes = array(
			'status' => array( 'status' => 'lost' ),
			'notes'  => array( 'body' => 'Rang back.' ),
		);

		foreach ( $writes as $route => $body ) {
			$response = $this->post( $id, $route, $body );

			$this->assertSame( 409, $response->get_status(), $route . ' is refused.' );
			$this->assertSame( RestEnquiries::CLOSED_CODE, $response->get_data()['code'], $route . ' names the closed code.' );
		}

		$this->assertSame( $before, EnquiryStore::find( $id ), 'The closed enquiry is unchanged.' );
		$this->assertSame( array(), NoteService::for_enquiry( $id ), 'No note was stored.' );
		$this->assertSame( $history, HistoryRecorder::for_enquiry( $id ), 'No history was appended.' );
	}

	/* ---------------------------------------------------------------------
	 * POST /enquiries/{id}/notes
	 * ------------------------------------------------------------------ */

	/**
	 * Requirements 10.1, 10.2, 10.6: the note is stored against the enquiry and
	 * the acting user, and comes back with the enquiry.
	 *
	 * @return void
	 */
	public function test_the_note_route_stores_a_note() {
		$id = $this->seed_enquiry();

		$response = $this->post( $id, 'notes', array( 'body' => "Rang back.\nHappy with the quote." ) );
		$data     = (array) $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertGreaterThan( 0, $data['note_id'] );

		$this->assertCount( 1, $data['enquiry']['notes'] );
		$this->assertSame( "Rang back.\nHappy with the quote.", $data['enquiry']['notes'][0]['body'] );
		$this->assertSame( $this->actor, $data['enquiry']['notes'][0]['author_id'] );

		$this->assertSame( array( 'note_added' ), wp_list_pluck( $data['enquiry']['history'], 'entry_type' ) );
	}

	/**
	 * Requirement 10.4: a body holding nothing once tags are stripped is refused
	 * with 400, and no note is stored.
	 *
	 * @return void
	 */
	public function test_the_note_route_refuses_an_empty_body() {
		$id = $this->seed_enquiry();

		$response = $this->post( $id, 'notes', array( 'body' => '<p>   </p>' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'meh_note_empty', $response->get_data()['code'] );
		$this->assertSame( array(), NoteService::for_enquiry( $id ) );
	}

	/* ---------------------------------------------------------------------
	 * POST /enquiries/{id}/duplicate
	 * ------------------------------------------------------------------ */

	/**
	 * Requirements 9.3 to 9.9: a closed enquiry is re-raised as a copy that
	 * starts at `new`, carries the source's values, dates and terms, reuses the
	 * source's subscriber identifier, and records the relationship both ways —
	 * while the source keeps its status and its notes and gains nothing but the
	 * `duplicated` entry.
	 *
	 * @return void
	 */
	public function test_the_duplicate_route_re_raises_a_closed_enquiry() {
		$id = $this->seed_enquiry(
			array(
				'status'                  => 'closed',
				'phone'                   => '07700 900123',
				'total_guests'            => 80,
				'message'                 => 'A summer weekend, ideally.',
				'fluentcrm_subscriber_id' => 501,
				'booking_id'              => 9,
			),
			array( self::CANDIDATE, '2025-08-23' ),
			array(
				'event_type'       => array( 'wedding' ),
				'site_exclusivity' => array( 'whole_site' ),
			)
		);

		$this->assertIsInt( NoteService::add( $id, 'Closed after the season.', $this->actor ) );

		$before  = EnquiryStore::find( $id );
		$notes   = NoteService::for_enquiry( $id );
		$history = wp_list_pluck( HistoryRecorder::for_enquiry( $id ), 'entry_type' );

		// A different identifier for the same address, so reuse of the source's
		// identifier is a real assertion rather than a coincidence.
		$this->crm->assign_subscriber_id( self::EMAIL, 777 );

		Clock::freeze( '2025-09-01 08:30:00' );

		$response = $this->post( $id, 'duplicate' );
		$data     = (array) $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $id, $data['source_id'] );
		$this->assertSame( array(), $data['warnings'] );

		$copy = $data['enquiry'];

		$this->assertNotSame( $id, $copy['id'] );

		// Requirement 9.4.
		foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'total_guests', 'message' ) as $field ) {
			$this->assertSame( $before[ $field ], $copy[ $field ], $field . ' is copied.' );
		}

		$this->assertSame( $before['date_ranges'], $copy['date_ranges'] );
		$this->assertSame( $before['event_type'], $copy['event_type'] );
		$this->assertSame( $before['site_exclusivity'], $copy['site_exclusivity'] );

		// Requirement 9.5.
		$this->assertSame( 'new', $copy['status'] );
		$this->assertNull( $copy['booking_id'] );
		$this->assertSame( '2025-09-01 08:30:00', $copy['created_at'] );

		// Requirements 9.6, 9.8.
		$this->assertSame( $id, $copy['duplicated_from_id'] );
		$this->assertSame( 501, $copy['fluentcrm_subscriber_id'] );
		$this->assertSame( 'synced', $copy['crm_sync_state'] );

		$this->assertContains( RestEnquiries::DUPLICATED_HISTORY_TYPE, wp_list_pluck( $copy['history'], 'entry_type' ) );

		$source = EnquiryStore::find( $id );

		$this->assertSame( (int) $copy['id'], $source['duplicated_to_id'] );

		// Requirement 9.9: the source keeps its status and its notes, and its
		// history gains the `duplicated` entry and nothing else.
		$this->assertSame( 'closed', $source['status'] );
		$this->assertSame( $notes, NoteService::for_enquiry( $id ) );
		$this->assertSame(
			array_merge( $history, array( RestEnquiries::DUPLICATED_HISTORY_TYPE ) ),
			wp_list_pluck( HistoryRecorder::for_enquiry( $id ), 'entry_type' )
		);
	}

	/* ---------------------------------------------------------------------
	 * POST /enquiries/{id}/convert
	 * ------------------------------------------------------------------ */

	/**
	 * Requirements 14.1, 14.5, 14.6: the route takes the target calendar and the
	 * agreed date, and answers with the booking and the converted enquiry.
	 *
	 * An omitted `end_date` is the single-day booking written the short way.
	 *
	 * @return void
	 */
	public function test_the_convert_route_creates_the_booking() {
		$id = $this->seed_enquiry();

		$response = $this->post(
			$id,
			'convert',
			array(
				'calendar_id' => 1,
				'date'        => self::CANDIDATE,
			)
		);
		$data     = (array) $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $data['warnings'] );
		$this->assertGreaterThan( 0, $data['booking']['booking_id'] );
		$this->assertSame( self::CANDIDATE, $data['booking']['start_date'] );
		$this->assertSame( self::CANDIDATE, $data['booking']['end_date'] );

		$this->assertSame( 'converted', $data['enquiry']['status'] );
		$this->assertSame( $data['booking']['booking_id'], $data['enquiry']['booking_id'] );

		$booking = $this->wpbs->booking( $data['booking']['booking_id'] );

		$this->assertSame( self::CANDIDATE, $booking['start_date'] );
	}

	/**
	 * Requirement 14.1: the route carries an end date through, and the range it
	 * books need not be one the enquirer offered — the team agrees dates by
	 * telephone that were never typed into the form.
	 *
	 * @return void
	 */
	public function test_the_convert_route_books_a_custom_range() {
		$id = $this->seed_enquiry();

		$response = $this->post(
			$id,
			'convert',
			array(
				'calendar_id' => 1,
				'date'        => '2025-12-24',
				'end_date'    => '2025-12-26',
			)
		);
		$data     = (array) $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2025-12-24', $data['booking']['start_date'] );
		$this->assertSame( '2025-12-26', $data['booking']['end_date'] );
		$this->assertSame( 3, $data['booking']['blocked'] );

		$booking = $this->wpbs->booking( $data['booking']['booking_id'] );

		$this->assertSame( '2025-12-24', $booking['start_date'] );
		$this->assertSame( '2025-12-26', $booking['end_date'] );
	}

	/**
	 * Requirement 14.1: a range ending before it starts names no days, which is
	 * the creator's 400, passed through, and the enquiry is left alone.
	 *
	 * @return void
	 */
	public function test_the_convert_route_passes_through_a_date_refusal() {
		$id = $this->seed_enquiry();

		$response = $this->post(
			$id,
			'convert',
			array(
				'calendar_id' => 1,
				'date'        => '2025-12-25',
				'end_date'    => '2025-12-20',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'meh_booking_invalid_range', $response->get_data()['code'] );

		$enquiry = EnquiryStore::find( $id );

		$this->assertSame( 'new', $enquiry['status'] );
		$this->assertNull( $enquiry['booking_id'] );
	}

	/* ---------------------------------------------------------------------
	 * POST /enquiries/{id}/retry-crm
	 * ------------------------------------------------------------------ */

	/**
	 * Requirements 5.4, 5.5: a `pending` enquiry is linked and reported `synced`.
	 *
	 * @return void
	 */
	public function test_the_retry_route_links_a_pending_enquiry() {
		$id = $this->seed_enquiry( array( 'crm_sync_state' => 'pending' ) );

		$this->crm->assign_subscriber_id( self::EMAIL, 601 );

		$response = $this->post( $id, 'retry-crm' );
		$data     = (array) $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 601, $data['crm']['subscriber_id'] );

		$this->assertSame( 'synced', $data['enquiry']['crm_sync_state'] );
		$this->assertSame( 601, $data['enquiry']['fluentcrm_subscriber_id'] );
	}

	/* ---------------------------------------------------------------------
	 * Every write route
	 * ------------------------------------------------------------------ */

	/**
	 * An identifier matching no enquiry is 404 on every write route, and writes
	 * nothing anywhere.
	 *
	 * @return void
	 */
	public function test_every_write_route_answers_404_for_an_unknown_enquiry() {
		$bodies = array(
			'status'    => array( 'status' => 'contacted' ),
			'notes'     => array( 'body' => 'Rang back.' ),
			'duplicate' => array(),
			'convert'   => array(
				'calendar_id' => 1,
				'date'        => self::CANDIDATE,
			),
			'retry-crm' => array(),
		);

		foreach ( $bodies as $route => $body ) {
			$response = $this->post( 987654, $route, $body );

			$this->assertSame( 404, $response->get_status(), $route . ' answers 404.' );
			$this->assertSame(
				RestEnquiries::NOT_FOUND_CODE,
				$response->get_data()['code'],
				$route . ' names the not-found code.'
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch one write through the REST server.
	 *
	 * @param int    $id    Enquiry identifier.
	 * @param string $route Sub-route name.
	 * @param array  $body  JSON body to send.
	 * @return WP_REST_Response
	 */
	private function post( $id, $route, array $body = array() ) {
		$request = new WP_REST_Request(
			'POST',
			'/' . RestEnquiries::NAMESPACE . '/enquiries/' . (int) $id . '/' . $route
		);

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/**
	 * Write one enquiry through the store and return its identifier.
	 *
	 * @param array $fields Column overrides.
	 * @param array $dates  Candidate dates.
	 * @param array $terms  Multi-select values by taxonomy.
	 * @return int
	 */
	private function seed_enquiry( array $fields = array(), array $dates = array( self::CANDIDATE ), array $terms = array() ) {
		$defaults = array(
			'first_name'        => 'Ada',
			'last_name'         => 'Lovelace',
			'email'             => self::EMAIL,
			'status'            => 'new',
			'created_at'        => self::NOW,
			'updated_at'        => self::NOW,
			'status_changed_at' => self::NOW,
			'source'            => 'webhook:fixture',
		);

		$id = EnquiryStore::create( array_merge( $defaults, $fields ), $dates, $terms );

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
