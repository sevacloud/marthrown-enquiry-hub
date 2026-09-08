<?php
/**
 * Worked examples for `GET /enquiries/{id}`.
 *
 * Properties 31 and 32 quantify the route's completeness and its refusal of an
 * incomplete row over arbitrary enquiries; tasks 15.4 and 15.5 own those. What
 * is asserted here is one worked example of each outcome against real rows and a
 * real REST server: a fully populated enquiry comes back with its fields,
 * candidate dates, both multi-selects, its notes, its history, `crm_sync_state`,
 * `booking_id`, the FluentCRM contact URL, the lifecycle's permitted transitions
 * and its same-email siblings; an unknown identifier is 404; a row missing
 * `email`, `status` or `created_at` is 500 naming the missing field and carrying
 * no enquiry representation at all; and a closed enquiry still reads back, which
 * is why this route does not go through `guard_writable()`.
 *
 * Requests are dispatched with `rest_do_request()` rather than by calling the
 * callback, so the permission callback and the route's own `(?P<id>[\d]+)`
 * pattern run where WordPress runs them.
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
use MarthrownEnquiryHub\Lifecycle;
use MarthrownEnquiryHub\NoteService;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;

/**
 * Class RestEnquirySingleTest
 */
class RestEnquirySingleTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehrs_';

	/**
	 * The instant the fixtures are written at.
	 */
	const NOW = '2025-06-01 10:00:00';

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The reading user.
	 *
	 * @var int
	 */
	private $reader = 0;

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

		Clock::freeze( self::NOW );

		$this->reader = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->reader );

		$wp_rest_server = null;
		add_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );
		rest_get_server();
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		$wp_rest_server = null;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Requirements 5.3, 13.1, 13.2, 13.3, 13.6: one enquiry comes back with
	 * everything known about it.
	 *
	 * @return void
	 */
	public function test_the_route_returns_everything_known_about_one_enquiry() {
		$id = $this->seed_enquiry(
			array(
				'status'                  => 'contacted',
				'crm_sync_state'          => 'synced',
				'fluentcrm_subscriber_id' => 91,
				'booking_id'              => 5,
				'phone'                   => '07700 900123',
				'total_guests'            => 80,
				'message'                 => 'Looking at a summer weekend.',
			),
			array( '2025-08-16', '2025-08-23' ),
			array(
				'event_type'       => array( 'wedding', 'reception' ),
				'site_exclusivity' => array( 'whole_site' ),
			)
		);

		// A sibling sharing the email, holding multi-select values of its own so the
		// sets the panel shows beside a repeat address are asserted rather than
		// merely present, and an unrelated enquiry that must not appear.
		$sibling = $this->seed_enquiry(
			array( 'status' => 'closed' ),
			array( '2025-09-06' ),
			array(
				'event_type'       => array( 'party' ),
				'site_exclusivity' => array( 'shared' ),
			)
		);
		$this->seed_enquiry( array( 'email' => 'someone-else@example.com' ) );

		HistoryRecorder::record( $id, 'created', 'Enquiry created.', array(), 0 );
		$this->assertIsInt( NoteService::add( $id, 'Rang back, happy with the quote.', $this->reader ) );

		$response = $this->get_enquiry( $id );
		$data     = (array) $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 'ada@example.com', $data['email'] );
		$this->assertSame( '07700 900123', $data['phone'] );
		$this->assertSame( 80, $data['total_guests'] );
		$this->assertSame( 'Looking at a summer weekend.', $data['message'] );
		$this->assertSame( 'contacted', $data['status'] );
		$this->assertSame( 'synced', $data['crm_sync_state'] );
		$this->assertSame( 5, $data['booking_id'] );
		$this->assertSame( self::NOW, $data['created_at'] );

		$this->assertSame( array( '2025-08-16', '2025-08-23' ), $data['selected_dates'] );
		$this->assertSame( array( 'wedding', 'reception' ), $data['event_type'] );
		$this->assertSame( array( 'whole_site' ), $data['site_exclusivity'] );

		$reader = get_userdata( $this->reader )->display_name;

		$this->assertCount( 1, $data['notes'] );
		$this->assertSame( 'Rang back, happy with the quote.', $data['notes'][0]['body'] );
		$this->assertSame( $this->reader, $data['notes'][0]['author_id'] );
		$this->assertSame( $reader, $data['notes'][0]['author'] );

		// Oldest first (Requirement 11.4), the note's own entry included, each
		// attributed — the system where no user acted, the user where one did.
		$this->assertCount( 2, $data['history'] );
		$this->assertSame( 'created', $data['history'][0]['entry_type'] );
		$this->assertSame( RestEnquiries::SYSTEM_ACTOR_NAME, $data['history'][0]['actor'] );
		$this->assertSame( 'note_added', $data['history'][1]['entry_type'] );
		$this->assertSame( $reader, $data['history'][1]['actor'] );

		$this->assertSame(
			admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/91' ),
			$data['crm_url']
		);

		// Requirement 13.6: the lifecycle decides, not the controller.
		$this->assertSame( Lifecycle::allowed_from( 'contacted' ), $data['allowed_transitions'] );

		// Requirement 13.3: every other enquiry sharing the email, holding exactly
		// the identifier, `created_at`, status, both multi-select sets and the
		// closure outcome.
		//
		// `closed_from` is empty here even though the sibling holds `closed`, and
		// that is the correct answer rather than a gap: the fixture was written
		// straight into the status by the store, so no `status_changed` entry
		// records the closure and there is no earlier status to report. Only a
		// closure that went through `Lifecycle::transition()` has one, which is
		// what `LifecycleTransitionTest` asserts.
		$this->assertCount( 1, $data['siblings'] );
		$this->assertSame(
			array(
				'id'               => $sibling,
				'created_at'       => self::NOW,
				'status'           => 'closed',
				'event_type'       => array( 'party' ),
				'site_exclusivity' => array( 'shared' ),
				'closed_from'      => '',
			),
			$data['siblings'][0]
		);
	}

	/**
	 * Requirement 13.4: an identifier matching no enquiry is 404.
	 *
	 * @return void
	 */
	public function test_an_unknown_identifier_is_a_404() {
		$response = $this->get_enquiry( 987654 );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( RestEnquiries::NOT_FOUND_CODE, $response->get_data()['code'] );
	}

	/**
	 * Requirement 13.5: a row missing `email`, `status` or `created_at` is 500,
	 * names the missing field, and carries no partial representation.
	 *
	 * @dataProvider incomplete_fields
	 * @param string $field Field to blank.
	 * @param mixed  $blank The stored "no value" for that column.
	 * @return void
	 */
	public function test_an_incomplete_row_fails_loudly( $field, $blank ) {
		global $wpdb;

		$id = $this->seed_enquiry();

		$this->assertNotFalse(
			$wpdb->update( Schema::table( 'enquiries' ), array( $field => $blank ), array( 'id' => $id ) ),
			'Blanking ' . $field . ' should succeed.'
		);

		$response = $this->get_enquiry( $id );
		$data     = (array) $response->get_data();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( RestEnquiries::INCOMPLETE_CODE, $data['code'] );
		$this->assertStringContainsString( $field, $data['message'] );
		$this->assertSame( array( $field ), $data['data']['missing'] );

		// No partial representation: none of the enquiry's own fields is present.
		foreach ( array( 'id', 'first_name', 'selected_dates', 'notes', 'history', 'siblings' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $data, 'A failed read returns no enquiry field.' );
		}
	}

	/**
	 * The three fields a representation cannot be built without, and the value
	 * each column holds when nothing was stored.
	 *
	 * @return array<string,array>
	 */
	public function incomplete_fields() {
		return array(
			'email'      => array( 'email', '' ),
			'status'     => array( 'status', '' ),
			'created_at' => array( 'created_at', '0000-00-00 00:00:00' ),
		);
	}

	/**
	 * Requirement 9.2: a closed enquiry is frozen against writes and still
	 * readable, which is why the read path does not call `guard_writable()`. Its
	 * permitted-transition list is empty and, with no linked contact, so is its
	 * CRM URL.
	 *
	 * @return void
	 */
	public function test_a_closed_enquiry_is_readable() {
		$id = $this->seed_enquiry( array( 'status' => 'closed' ) );

		$response = $this->get_enquiry( $id );
		$data     = (array) $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'closed', $data['status'] );
		$this->assertSame( array(), $data['allowed_transitions'] );
		$this->assertSame( '', $data['crm_url'] );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch one read through the REST server.
	 *
	 * @param int $id Enquiry identifier.
	 * @return WP_REST_Response
	 */
	private function get_enquiry( $id ) {
		return rest_do_request(
			new WP_REST_Request( 'GET', '/' . RestEnquiries::NAMESPACE . '/enquiries/' . (int) $id )
		);
	}

	/**
	 * Write one enquiry through the store and return its identifier.
	 *
	 * @param array $fields Column overrides.
	 * @param array $dates  Candidate dates.
	 * @param array $terms  Multi-select values by taxonomy.
	 * @return int
	 */
	private function seed_enquiry( array $fields = array(), array $dates = array( '2025-08-16' ), array $terms = array() ) {
		$defaults = array(
			'first_name'        => 'Ada',
			'last_name'         => 'Lovelace',
			'email'             => 'ada@example.com',
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
