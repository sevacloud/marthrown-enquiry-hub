<?php
/**
 * Worked example: the closed guard runs before the validator on the edit route.
 *
 * Requirement 19.12 asks for a closed enquiry to earn 409 from an edit request,
 * and Requirement 9.1 states the same rule once for every write route. Neither
 * is satisfied by answering 409 *sometimes*: a body that would also have failed
 * validation must still earn 409 rather than 400, because that is the only
 * observable difference between `guard_writable()` running first and it running
 * after the validator. Property 41 quantifies the same territory over arbitrary
 * enquiries and arbitrary broken bodies; what this file pins is the single
 * ordering claim, in a form a reader can check at a glance.
 *
 * The assertion is a pair, and it needs both halves to mean anything:
 *
 * - The same body, against an **open** enquiry, is answered 400 naming every
 *   field it got wrong. That is what establishes the body genuinely would fail
 *   validation — without it, a 409 for the closed enquiry would prove nothing,
 *   since a body the validator happens to accept earns 409 under either
 *   ordering.
 * - The same body, against a **closed** enquiry, is answered 409 carrying the
 *   closed guard's own error code and no per-field error map at all, and the
 *   stored enquiry and its history come back unchanged.
 *
 * The request goes through the real route with `rest_do_request()`, so the
 * permission callback and the declared args' `sanitize_callback`s run where
 * WordPress runs them. That matters here: `email` is sanitised as text rather
 * than with `sanitize_email()` and `total_guests` is not coerced to an integer,
 * so the two offending values reach the Validator as typed and would earn
 * `invalid_email` and `out_of_range` if it were ever consulted.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the temporary tables the WordPress test case
 * rewrites `CREATE TABLE` into.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\FieldMapper;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;

/**
 * Class EditGuardOrderingTest
 */
class EditGuardOrderingTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table, or with another test's fixture, in the same database.
	 */
	const PREFIX_SEGMENT = 'mehgo_';

	/** The instant the fixture is written at. */
	const SEEDED_AT = '2025-04-02 11:30:00';

	/**
	 * The instant the edit request is made at.
	 *
	 * Later than self::SEEDED_AT on purpose: a write that slipped past the
	 * refusal would move `updated_at` to a value the comparison cannot miss.
	 */
	const REQUEST_AT = '2025-10-08 16:45:00';

	/** The candidate date the fixture offers. */
	const CANDIDATE = '2025-12-13';

	/** The error code the closed guard answers with (Requirement 19.12). */
	const CLOSED_CODE = 'meh_enquiry_closed';

	/** The error code a rejected submission earns (Requirement 19.5). */
	const INVALID_CODE = 'meh_invalid_enquiry';

	/**
	 * The acting user.
	 *
	 * @var int
	 */
	private static $user_id = 0;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Load the classes under test, and create the calling user.
	 *
	 * The user is created before any prefix switch, so it lands in the real
	 * users table rather than in a fixture one.
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
		require_once MEH_INCLUDES_DIR . 'class-field-mapper.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-validator.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-editor.php';
		require_once MEH_INCLUDES_DIR . 'class-source-wpbs.php';
		require_once MEH_INCLUDES_DIR . 'class-booking-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';

		self::$user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
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

		// Nothing is configured for a particular sender, so every field resolves
		// by its own name.
		delete_option( FieldMapper::OPTION );

		Clock::freeze( self::SEEDED_AT );
		wp_set_current_user( self::$user_id );

		$wp_rest_server = null;

		add_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );

		rest_get_server();
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		remove_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );

		$wp_rest_server = null;

		wp_set_current_user( 0 );
		Clock::unfreeze();

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * The ordering claim
	 * ------------------------------------------------------------------ */

	/**
	 * The control half: this body really does fail validation.
	 *
	 * Against an open enquiry the same three values earn 400 naming each of them,
	 * which is what makes the 409 asserted below an ordering result rather than
	 * an accident of a body the validator would have accepted (Requirement 19.5).
	 *
	 * @return void
	 */
	public function test_the_invalid_body_is_answered_400_against_an_open_enquiry() {
		$id = $this->seed_enquiry( 'new' );

		Clock::freeze( self::REQUEST_AT );

		$response = $this->patch( $id, self::invalid_body() );
		$data     = (array) $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'An invalid edit of an open enquiry is refused with 400.' );
		$this->assertSame( self::INVALID_CODE, (string) $data['code'], 'The refusal comes from the validator.' );

		$errors = self::reported_errors( $data );

		$this->assertSame(
			array( 'email', 'first_name', 'total_guests' ),
			self::sorted_keys( $errors ),
			'Every field the body gets wrong is named.'
		);
		$this->assertSame( 'empty', (string) $errors['first_name'], 'A blanked required field fails as empty.' );
		$this->assertSame( 'invalid_email', (string) $errors['email'], 'A malformed address fails as invalid.' );
		$this->assertSame( 'out_of_range', (string) $errors['total_guests'], 'A guest count of 0 fails as out of range.' );
	}

	/**
	 * Requirements 9.1, 19.12: the same body against a closed enquiry is answered
	 * 409, not 400, so `guard_writable()` ran before the validator.
	 *
	 * @return void
	 */
	public function test_a_closed_enquiry_is_answered_409_rather_than_400() {
		$id = $this->seed_enquiry( 'closed' );

		$before  = EnquiryStore::find( $id );
		$history = HistoryRecorder::for_enquiry( $id );

		Clock::freeze( self::REQUEST_AT );

		$response = $this->patch( $id, self::invalid_body() );
		$data     = (array) $response->get_data();

		$this->assertSame(
			409,
			$response->get_status(),
			'A closed enquiry earns 409 even for a body that would have failed validation.'
		);
		$this->assertSame(
			self::CLOSED_CODE,
			(string) $data['code'],
			'The refusal comes from the closed guard rather than from the validator.'
		);

		// The validator was never consulted, so there is no per-field map to
		// report: a 400 would have carried one naming all three fields.
		$this->assertSame(
			array(),
			self::reported_errors( $data ),
			'A closed enquiry reports no field-level validation failures.'
		);

		// Requirement 19.12: every stored value, including `updated_at`, is as it
		// was, and no history entry was appended.
		$this->assertSame( $before, EnquiryStore::find( $id ), 'The stored enquiry is unchanged.' );
		$this->assertSame( self::SEEDED_AT, (string) $before['updated_at'], 'The fixture was written at the earlier instant.' );
		$this->assertSame( $history, HistoryRecorder::for_enquiry( $id ), 'The history is unchanged.' );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * A body every one of whose three values fails a Requirement 3 rule naming
	 * that field, under the Manual profile in partial mode.
	 *
	 * `total_guests` travels as a string because the route declares it as an
	 * integer or a string and deliberately does not coerce it, which is what
	 * keeps `out_of_range` and `not_whole_number` separate answers.
	 *
	 * @return array<string,mixed>
	 */
	private static function invalid_body() {
		return array(
			'first_name'   => '   ',
			'email'        => 'ada@@example',
			'total_guests' => '0',
		);
	}

	/**
	 * Dispatch one edit through the real route.
	 *
	 * @param int   $id     Enquiry identifier.
	 * @param array $fields Body to submit.
	 * @return WP_REST_Response
	 */
	private function patch( $id, array $fields ) {
		$request = new WP_REST_Request( 'PATCH', '/' . RestEnquiries::NAMESPACE . '/enquiries/' . (int) $id );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( (object) $fields ) );

		return rest_do_request( $request );
	}

	/**
	 * The per-field error map a refusal carries, if it carries one.
	 *
	 * WordPress renders a `WP_Error` as `{ code, message, data: { status, … } }`,
	 * so the map the route put under `errors` arrives nested inside `data`.
	 *
	 * @param array $data Response body.
	 * @return array<string,string>
	 */
	private static function reported_errors( array $data ) {
		$detail = isset( $data['data'] ) ? (array) $data['data'] : array();

		return isset( $detail['errors'] ) ? (array) $detail['errors'] : array();
	}

	/**
	 * The keys of a map, sorted, for an order-independent comparison.
	 *
	 * @param array $map Map to read.
	 * @return string[]
	 */
	private static function sorted_keys( array $map ) {
		$keys = array_keys( $map );

		sort( $keys );

		return $keys;
	}

	/**
	 * Write one enquiry holding the given status and return its identifier.
	 *
	 * @param string $status Status the enquiry holds.
	 * @return int
	 */
	private function seed_enquiry( $status ) {
		Clock::freeze( self::SEEDED_AT );

		$id = EnquiryStore::create(
			array(
				'first_name'        => 'Ada',
				'last_name'         => 'Lovelace',
				'email'             => 'ada@example.test',
				'phone'             => '0131 496 0000',
				'total_guests'      => 40,
				'message'           => 'A wake for forty, if the barn is free.',
				'status'            => (string) $status,
				'created_at'        => self::SEEDED_AT,
				'updated_at'        => self::SEEDED_AT,
				'status_changed_at' => self::SEEDED_AT,
				'source'            => 'webhook:edit-guard-fixture',
			),
			array( self::CANDIDATE ),
			array()
		);

		$this->assertIsInt( $id, 'Seeding the enquiry to edit should succeed.' );

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
