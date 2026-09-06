<?php
/**
 * A webhook submission and a manual submission of the same values produce the
 * same enquiry.
 *
 * `SharedCreationPathSourceTest` establishes structurally that both creation
 * routes reach the store through `EnquiryCreator::create()` and that neither
 * writes an enquiry of its own. This is the behavioural half of the same claim,
 * and it is the one that would notice a shared path whose behaviour depends on
 * who called it: two submissions carrying equivalent field values are created,
 * one through `IntakeHandler::receive()` and one through `POST /enquiries`, and
 * the two enquiries are compared column by column.
 *
 * Exactly three differences are permitted, and each is asserted rather than
 * merely excused:
 *
 * 1. **`source`** — `webhook:{form id}` against `manual:{user id}`
 *    (Requirements 2.3, 18.8).
 * 2. **The `created` entry's `actor_id`** — the system against the submitting
 *    user (Requirements 11.5, 18.18).
 * 3. **Whether a guard ran** — the webhook is rate-limit counted and duplicate
 *    checked, the manual request is neither (Requirement 18.12).
 *
 * Everything else is asserted equal: every stored scalar, the status, all three
 * timestamps, `is_test`, the candidate dates, both multi-selects, the payload
 * snapshot, `crm_sync_state`, the linked subscriber identifier and the sequence
 * of history entry types. A difference anywhere in that set is drift between the
 * two paths, which is what the shared service exists to prevent.
 *
 * How the comparison is set up, and why:
 *
 * - **The webhook goes first.** Both submissions carry the same address and the
 *   same candidate dates, so once either enquiry exists the duplicate detector
 *   reports a match on it. Sending the webhook first is what lets the manual
 *   request be dispatched with the detector *already matching*, which is the
 *   observation behind "no guard ran": a route that consulted the detector would
 *   have refused.
 * - **The clock is frozen**, so "the time the request was received" is one
 *   instant for both and the three timestamps are comparable at all. Both routes
 *   read it themselves — intake from the receipt time it is handed, the REST
 *   route from `Clock::mysql()` — so the frozen clock is what makes them agree
 *   rather than an argument being copied.
 * - **The field map is keyed by the field names themselves**, in the order the
 *   route declares its args, and holds only values the route's declared
 *   sanitisation is the identity on. That is what lets the two payload snapshots
 *   be compared to each other *and* to the submission: a value carrying markup
 *   would be rewritten on the REST path only, and what it then becomes belongs to
 *   the sanitisation property rather than here.
 * - **All nine fields are submitted**, because the Webhook profile requires all
 *   nine (Requirement 3.14). A manual submission of the same nine validates under
 *   the Manual profile too, so one field map serves both routes and the
 *   comparison is not confounded by one path having been handed less.
 * - **The linker's own history entry is left out of the actor comparison.**
 *   `ContactLinker::link()` is passed the same actor the `created` entry carries,
 *   so its entry differs in `actor_id` for the same reason and by the same
 *   design. What is compared is the sequence of entry *types*, which is the claim
 *   worth making: both routes leave the same trail.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix segment, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the test case's temporary tables.
 *
 * Covers Requirements 18.1, 18.8, 18.18.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\ContactLinker;
use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\IntakeHandler;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;

/**
 * Class SharedCreationPathTest
 */
class SharedCreationPathTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table or with another test's fixture in the same database.
	 */
	const PREFIX_SEGMENT = 'mehsc_';

	/**
	 * The instant both submissions are received at.
	 */
	const NOW = '2025-06-01 10:00:00';

	/**
	 * The `source` the webhook submission carries (Requirement 2.3).
	 */
	const WEBHOOK_SOURCE = 'webhook:shared-path';

	/**
	 * The `source` prefix a manually created enquiry must carry.
	 *
	 * Spelled out rather than read from `RestEnquiries`, so an implementation
	 * changing the shape of the value fails here (Requirement 18.8).
	 */
	const MANUAL_PREFIX = 'manual:';

	/**
	 * The history entry type a created enquiry appends.
	 */
	const CREATED_ENTRY = 'created';

	/**
	 * Columns the two enquiries are allowed to differ in.
	 *
	 * @var string[]
	 */
	const PERMITTED_DIFFERENCES = array( 'id', 'source' );

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The REST server in force outside this test.
	 *
	 * @var \WP_REST_Server|null
	 */
	private $original_server = null;

	/**
	 * The user the manual submission is attributed to.
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
	 * Addresses whose rate-limit counters need forgetting.
	 *
	 * @var string[]
	 */
	private $counted = array();

	/**
	 * Load the classes under test.
	 *
	 * `class-enquiry-creator.php` is required before `class-intake-handler.php`
	 * because the handler resolves constants from `EnquiryCreator` as its class
	 * body is evaluated; loading it second would leave intake answering 500.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-clock.php';
		require_once MEH_INCLUDES_DIR . 'class-auth.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-query.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-store.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-validator.php';
		require_once MEH_INCLUDES_DIR . 'class-field-mapper.php';
		require_once MEH_INCLUDES_DIR . 'class-history-recorder.php';
		require_once MEH_INCLUDES_DIR . 'class-note-service.php';
		require_once MEH_INCLUDES_DIR . 'class-lifecycle.php';
		require_once MEH_INCLUDES_DIR . 'class-duplicate-detector.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-handler.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-editor.php';
		require_once MEH_INCLUDES_DIR . 'class-source-wpbs.php';
		require_once MEH_INCLUDES_DIR . 'class-booking-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';
	}

	public function set_up() {
		parent::set_up();

		global $wpdb, $wp_rest_server;

		// Created before the prefix is switched, so the user lands in the real
		// users table rather than in a fixture one.
		$this->actor = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		update_option( 'meh_enquiry_list', 7 );
		update_option( 'meh_enquiry_tag', 12 );

		$this->crm = FakeCrm::install();

		Clock::freeze( self::NOW );

		// A server of this test's own, so the route table holds what this test
		// registered and nothing a previous test left behind.
		$this->original_server = $wp_rest_server;
		$wp_rest_server        = new WP_REST_Server();

		add_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		foreach ( $this->counted as $email ) {
			DuplicateDetector::reset( $email );
		}

		$this->counted = array();

		remove_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );

		wp_set_current_user( 0 );

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		$wp_rest_server = $this->original_server;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * The two enquiries differ only where they are permitted to
	 * ------------------------------------------------------------------ */

	/**
	 * Requirements 18.1, 18.8, 18.18: equivalent submissions through the two
	 * routes produce enquiries differing only in `source`.
	 *
	 * @return void
	 */
	public function test_the_two_routes_produce_enquiries_differing_only_in_source() {
		$fields = $this->submission( 'ada@example.com' );

		$webhook_id = $this->submit_webhook( $fields );
		$manual_id  = $this->submit_manually( $fields );

		$webhook = EnquiryStore::find( $webhook_id );
		$manual  = EnquiryStore::find( $manual_id );

		$this->assertIsArray( $webhook, 'The webhook enquiry is readable.' );
		$this->assertIsArray( $manual, 'The manually created enquiry is readable.' );

		// The two permitted differences, asserted rather than assumed.
		$this->assertSame( self::WEBHOOK_SOURCE, $webhook['source'], 'The webhook enquiry names the form it came from.' );
		$this->assertSame(
			self::MANUAL_PREFIX . $this->actor,
			$manual['source'],
			'The manual enquiry names manual creation and the submitting user (Requirement 18.8).'
		);
		$this->assertNotSame( $webhook_id, $manual_id, 'The two submissions are two enquiries.' );

		// Everything else, column by column. The key check first, so a comparison
		// of two enquiries that had stopped carrying most of their values fails
		// here rather than passing.
		$compared = $this->comparable( $webhook );

		foreach ( array( 'first_name', 'email', 'total_guests', 'status', 'created_at', 'updated_at', 'status_changed_at', 'is_test', 'crm_sync_state', 'fluentcrm_subscriber_id', 'selected_dates', 'event_type', 'site_exclusivity', 'payload' ) as $column ) {
			$this->assertArrayHasKey( $column, $compared, 'The comparison covers ' . $column . '.' );
		}

		$this->assertSame(
			$compared,
			$this->comparable( $manual ),
			'Two equivalent submissions differ in nothing but source and identifier.'
		);
	}

	/**
	 * Requirement 18.18: the `created` entry is the system's on the webhook path
	 * and the submitting user's on the manual one, and the trail is otherwise the
	 * same.
	 *
	 * @return void
	 */
	public function test_the_created_entry_is_the_only_difference_in_the_trail() {
		$fields = $this->submission( 'grace@example.com' );

		$webhook_id = $this->submit_webhook( $fields );
		$manual_id  = $this->submit_manually( $fields );

		$this->assertSame(
			HistoryRecorder::SYSTEM_ACTOR,
			$this->created_entry( $webhook_id )['actor_id'],
			'A webhook enquiry is created by the system.'
		);

		$this->assertSame(
			$this->actor,
			$this->created_entry( $manual_id )['actor_id'],
			'A manual enquiry is created by the submitting user (Requirement 18.18).'
		);

		$this->assertSame(
			$this->entry_types( $webhook_id ),
			$this->entry_types( $manual_id ),
			'Both routes leave the same sequence of history entries.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Whether a guard ran is the third permitted difference
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 18.12: the guards run on the webhook path and on no other.
	 *
	 * Three observations, because "the guards were not consulted" is not directly
	 * visible: the manual request is created while the duplicate detector is
	 * asserted to be reporting a match, so the route cannot have asked it; the
	 * rate-limit counter holds only the webhook's own hit, so the manual request
	 * never touched it; and no rejection row exists until a *second webhook*
	 * earns one.
	 *
	 * @return void
	 */
	public function test_only_the_webhook_path_is_guarded() {
		$fields = $this->submission( 'hopper@example.com' );
		$email  = (string) $fields['email'];

		$this->submit_webhook( $fields );

		// The detector is a witness here rather than a collaborator: it matches
		// the enquiry the webhook just created.
		$this->assertGreaterThan(
			0,
			DuplicateDetector::find_duplicate( $email, (array) $fields['selected_dates'] ),
			'The duplicate detector reports a match, so a route consulting it would refuse.'
		);

		$this->submit_manually( $fields );

		$this->assertSame(
			1,
			DuplicateDetector::hits( $email ),
			'Only the webhook request is counted against the address (Requirement 18.12).'
		);
		$this->assertSame(
			0,
			$this->rejection_count(),
			'Neither a created webhook enquiry nor a manual one records a rejected intake attempt.'
		);

		// The other half: intake is still guarded, so the difference is which
		// route ran the guard rather than the guard having been removed.
		$refused = IntakeHandler::receive( $fields, self::WEBHOOK_SOURCE, self::NOW );

		$this->assertFalse( (bool) $refused['created'], 'A repeated webhook submission is refused.' );
		$this->assertSame( IntakeHandler::REASON_DUPLICATE, $refused['reason'], 'It is refused as a duplicate.' );
		$this->assertSame( 1, $this->rejection_count(), 'The refused webhook records one rejected intake attempt.' );

		// Two webhook requests have now been counted, and only webhook requests.
		$counted = DuplicateDetector::hits( $email );

		$this->assertSame( 2, $counted, 'Both webhook requests are counted against the address.' );

		// And a repeated manual submission is still created, at the same instant
		// and with the same values as the webhook that was just refused.
		$repeat = $this->submit_manually( $fields );

		$this->assertGreaterThan( 0, $repeat, 'A repeated manual submission is created (Requirement 18.13).' );
		$this->assertSame( 1, $this->rejection_count(), 'It records no rejected intake attempt (Requirement 18.15).' );
		$this->assertSame(
			$counted,
			DuplicateDetector::hits( $email ),
			'And it leaves the count where the webhook requests left it (Requirement 18.14).'
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * The field map both routes are handed.
	 *
	 * Keyed by the field names themselves, in the order the manual route declares
	 * its args, and holding only values the declared sanitisation is the identity
	 * on, so the two payload snapshots are comparable to each other and to this
	 * submission. All nine fields are present because the Webhook profile
	 * requires all nine (Requirement 3.14).
	 *
	 * @param string $email Address to submit.
	 * @return array<string,mixed>
	 */
	private function submission( $email ) {
		$this->counted[] = $email;

		return array(
			'first_name'       => 'Ada',
			'last_name'        => 'Lovelace',
			'email'            => (string) $email,
			'phone'            => '01142 700 700',
			'total_guests'     => '80',
			'selected_dates'   => array( '2025-08-16', '2025-08-23' ),
			'event_type'       => array( 'wedding' ),
			'site_exclusivity' => array( 'exclusive' ),
			'message'          => 'We would like to hold the ceremony outdoors.',
		);
	}

	/**
	 * Create one enquiry through the intake webhook path.
	 *
	 * @param array $fields Submitted field map.
	 * @return int The created enquiry identifier.
	 */
	private function submit_webhook( array $fields ) {
		wp_set_current_user( 0 );

		$outcome = IntakeHandler::receive( $fields, self::WEBHOOK_SOURCE, self::NOW );

		$this->assertTrue(
			(bool) $outcome['created'],
			'The webhook submission is created. ' . wp_json_encode( $outcome['errors'] )
		);

		return (int) $outcome['enquiry_id'];
	}

	/**
	 * Create one enquiry through the manual creation route.
	 *
	 * Dispatched with `rest_do_request()` rather than by calling the callback, so
	 * the permission callback, the declared args and their `sanitize_callback`s
	 * all run where WordPress runs them — including the receipt time and the
	 * `manual:{user id}` source the route itself decides.
	 *
	 * @param array $fields Submitted field map.
	 * @return int The created enquiry identifier.
	 */
	private function submit_manually( array $fields ) {
		wp_set_current_user( $this->actor );

		$request = new WP_REST_Request( 'POST', '/' . RestEnquiries::NAMESPACE . '/enquiries' );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $fields ) );

		$response = rest_do_request( $request );
		$data     = (array) $response->get_data();

		$this->assertSame(
			201,
			$response->get_status(),
			'The manual submission is created. ' . wp_json_encode( $data )
		);

		return isset( $data['id'] ) ? (int) $data['id'] : 0;
	}

	/**
	 * One stored enquiry reduced to what the two routes must agree on.
	 *
	 * The identifier and `source` are removed because they are the permitted
	 * differences; the payload snapshot is key-sorted so the comparison is of the
	 * values captured rather than of the order the two routes happened to read
	 * their fields in.
	 *
	 * @param array $enquiry Stored enquiry.
	 * @return array
	 */
	private function comparable( array $enquiry ) {
		foreach ( self::PERMITTED_DIFFERENCES as $column ) {
			unset( $enquiry[ $column ] );
		}

		$payload = (array) $enquiry['payload'];
		ksort( $payload );
		$enquiry['payload'] = $payload;

		ksort( $enquiry );

		return $enquiry;
	}

	/**
	 * The one `created` history entry of an enquiry.
	 *
	 * @param int $enquiry_id Enquiry to read.
	 * @return array
	 */
	private function created_entry( $enquiry_id ) {
		$found = array();

		foreach ( HistoryRecorder::for_enquiry( $enquiry_id ) as $entry ) {
			if ( self::CREATED_ENTRY === $entry['entry_type'] ) {
				$found[] = $entry;
			}
		}

		$this->assertCount( 1, $found, 'Exactly one created history entry exists for enquiry ' . (int) $enquiry_id . '.' );

		return $found[0];
	}

	/**
	 * The history entry types of an enquiry, oldest first.
	 *
	 * @param int $enquiry_id Enquiry to read.
	 * @return string[]
	 */
	private function entry_types( $enquiry_id ) {
		$types = array();

		foreach ( HistoryRecorder::for_enquiry( $enquiry_id ) as $entry ) {
			$types[] = (string) $entry['entry_type'];
		}

		return $types;
	}

	/**
	 * Rejected intake attempts recorded so far.
	 *
	 * @return int
	 */
	private function rejection_count() {
		global $wpdb;

		$table = Schema::table( 'rejections' );

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
