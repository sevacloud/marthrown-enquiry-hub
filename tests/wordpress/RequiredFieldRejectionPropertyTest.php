<?php
/**
 * Property 9: Required-field validation is profile-scoped and names every failure.
 *
 * Feature: enquiry-data-layer, Property 9: For any required-field profile, any
 * subset of the nine enquiry fields made empty, and any emptiness style per
 * field in that subset (key absent, empty string, whitespace-only, or empty
 * collection), validation returns an error set naming exactly the intersection
 * of that subset with the applied profile's required set, and no other field.
 * Under the Webhook profile the required set is all nine fields; under the
 * Manual profile it is exactly `first_name`, `last_name`, `email` and
 * `selected_dates`, so an empty `phone`, `total_guests`, `message`,
 * `event_type` or `site_exclusivity` is named by nothing. For any required
 * field of the applied profile, the absence of that field's key fails in full
 * mode (creation, either route) and does not fail in partial mode (an edit),
 * while that field present and holding an empty value fails in both modes. For
 * any submission rejected on the webhook intake path, no enquiry is created and
 * exactly one rejected intake attempt is recorded holding the payload snapshot
 * and a reason for each failing field; for any submission rejected on the
 * manual creation or edit route, no enquiry is created or changed and no
 * rejected intake attempt is recorded.
 *
 * **Validates: Requirements 2.11, 3.1, 3.2, 3.8, 3.14, 3.15, 3.16, 18.2, 18.3, 18.15, 19.3**
 *
 * How the property is instantiated, and why:
 *
 * - **All three write paths are quantified over, in one property.** The claim is
 *   a comparison between profiles and between modes, so a test covering one path
 *   could not state it: `PROFILE_WEBHOOK` in `MODE_FULL` arrives as
 *   `POST marthrown-enquiry-hub/v1/intake`, `PROFILE_MANUAL` in `MODE_FULL` as
 *   `POST /enquiries`, and `PROFILE_MANUAL` in `MODE_PARTIAL` as
 *   `PATCH /enquiries/{id}`. Each iteration draws one of them and submits the
 *   same construction down it, which is what makes "the same empty `phone` is a
 *   failure here and is not a failure there" an assertion rather than two
 *   unrelated tests.
 * - **Every request goes through the real route.** `rest_do_request()` dispatches
 *   a real `WP_REST_Request` on a REST server this test registered, so the
 *   permission callbacks, the declared args and each route's own sanitisers all
 *   run where WordPress runs them. That matters here more than usual: the manual
 *   and edit routes' `sanitize_text_field` turns a whitespace-only submission
 *   into an empty string *before* the Validator sees it, and the intake
 *   endpoint's normalisation turns a whitespace-only multi-select into an empty
 *   list. Calling the Validator directly would test a submission no route can
 *   actually deliver.
 * - **The expected error set is computed from the profile tables restated here,
 *   not read from `Validator::REQUIRED_BY_PROFILE`.** Requirements 3.14 and 3.15
 *   name both sets outright; taking them from the code under test would leave the
 *   property unable to notice that code moving a field from one set to the other.
 * - **Emptiness is quantified per field, in every style the field can carry.** A
 *   scalar field is drawn absent, as `''` or as whitespace; a multi-value field
 *   is drawn absent, as `''`, as an empty list or as a list holding nothing but
 *   blanks. The four styles are not interchangeable: absence is the one the two
 *   modes disagree about, and a blank-filled list is the one a form padding a
 *   multi-select actually sends.
 * - **The subset may be empty, and that case is the acceptance half of the
 *   property.** An empty intersection has to mean no presence failure, so those
 *   iterations assert the submission was accepted — which under the Manual
 *   profile includes every subset drawn wholly from the five optional fields
 *   (Requirement 3.16), and under `MODE_PARTIAL` every subset whose members are
 *   all absent (Requirement 19.3).
 * - **Every failing field is asserted, not just the first.** The response's error
 *   map is compared as a whole against the expected map: same field names, same
 *   count, and the same code for each — `required` where the key was absent,
 *   `empty` where it was present and empty. A validator returning early on the
 *   first failure passes a "names the offending field" assertion and fails this
 *   one (Requirements 3.2, 18.6).
 * - **The rejection row is counted, not merely looked for.** The webhook path
 *   asserts the rejections table grew by exactly one and that the new row carries
 *   reason `validation`, the submitted payload and a per-field reason
 *   (Requirement 3.8); the manual and edit paths assert every Enquiry Store table
 *   still holds exactly the rows it held (Requirement 18.15). A rejected edit
 *   additionally asserts the stored enquiry and its history come back byte for
 *   byte as they were.
 * - **The edit fixture is seeded with values the submission does not carry**, so
 *   "leaves every stored value unchanged" is a real assertion: a route that
 *   wrote first and validated afterwards would show the submitted values in the
 *   comparison.
 * - **Each iteration submits an address unique to it**, so neither the duplicate
 *   guard nor the rate limiter can turn a submission away for a reason this
 *   property is not about. Where `email` itself is the field made empty, both
 *   guards decline to bucket it, so the outcome is validation's either way.
 * - **The multi-select vocabularies are installed on their filters**, so the
 *   values the valid fields carry are genuinely permitted and no value rule can
 *   contribute an error this property did not predict.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix: the WordPress test case rewrites `CREATE TABLE` into its
 * `TEMPORARY` form, and `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see a temporary table.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\FieldMapper;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\IntakeEndpoint;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class RequiredFieldRejectionPropertyTest
 */
class RequiredFieldRejectionPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table or with another test's fixture in the same database.
	 */
	const PREFIX_SEGMENT = 'mehrfr_';

	/**
	 * The Intake Secret held in Settings for the length of the run.
	 */
	const SECRET = 'sk-9c31be07d24f1a86e5b0';

	/**
	 * The instant every request is received at.
	 */
	const NOW = '2025-06-01 10:00:00';

	/**
	 * The nine enquiry fields, restated rather than read from `FieldMapper`.
	 *
	 * @var string[]
	 */
	const FIELDS = array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'total_guests',
		'selected_dates',
		'event_type',
		'site_exclusivity',
		'message',
	);

	/**
	 * The Webhook Validation Profile's required set: all nine (Requirement 3.14).
	 *
	 * @var string[]
	 */
	const WEBHOOK_REQUIRED = self::FIELDS;

	/**
	 * The Manual Validation Profile's required set: exactly four (Requirement 3.15).
	 *
	 * @var string[]
	 */
	const MANUAL_REQUIRED = array( 'first_name', 'last_name', 'email', 'selected_dates' );

	/**
	 * The three fields a submission carries as a set of values.
	 *
	 * @var string[]
	 */
	const COLLECTION_FIELDS = array( 'selected_dates', 'event_type', 'site_exclusivity' );

	/**
	 * The three write paths, and therefore the three profile/mode pairings.
	 *
	 * @var string[]
	 */
	const PATH_WEBHOOK = 'webhook';
	const PATH_MANUAL  = 'manual';
	const PATH_EDIT    = 'edit';

	/**
	 * The paths quantified over.
	 *
	 * @var string[]
	 */
	const PATHS = array( self::PATH_WEBHOOK, self::PATH_MANUAL, self::PATH_EDIT );

	/**
	 * Emptiness styles.
	 *
	 * `absent` is the one the two modes disagree about; the rest are all
	 * "present, holding nothing", which fails in either mode.
	 */
	const STYLE_ABSENT           = 'absent';
	const STYLE_EMPTY_STRING     = 'empty_string';
	const STYLE_WHITESPACE       = 'whitespace';
	const STYLE_EMPTY_COLLECTION = 'empty_collection';
	const STYLE_BLANK_COLLECTION = 'blank_collection';

	/**
	 * The failure code an absent required field earns in full mode.
	 */
	const CODE_REQUIRED = 'required';

	/**
	 * The failure code a present-but-empty required field earns in either mode.
	 */
	const CODE_EMPTY = 'empty';

	/**
	 * The error code both authenticated write routes answer a rejection with
	 * (Requirements 18.3, 19.5).
	 */
	const INVALID_CODE = 'meh_invalid_enquiry';

	/**
	 * The rejection reason a validation failure earns (Requirement 3.8).
	 */
	const REASON_VALIDATION = 'validation';

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
	 * The authenticated administrator both authenticated routes are called as.
	 *
	 * @var int
	 */
	private $actor = 0;

	/**
	 * The FluentCRM fake, so the linkage leg of an accepted write behaves as it
	 * does on a site that has FluentCRM active.
	 *
	 * @var FakeCrm|null
	 */
	private $crm = null;

	/**
	 * Email addresses whose rate-limit counters need forgetting.
	 *
	 * @var string[]
	 */
	private $counted = array();

	/**
	 * Iteration counter, used to keep every submitted address unique.
	 *
	 * @var int
	 */
	private $iteration = 0;

	/**
	 * Load the classes under test.
	 *
	 * `class-enquiry-creator.php` is required before `class-intake-handler.php`
	 * because the handler resolves constants from `EnquiryCreator` as its class
	 * body is evaluated; loading it second would leave the intake route
	 * answering 500.
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
		require_once MEH_INCLUDES_DIR . 'class-enquiry-validator.php';
		require_once MEH_INCLUDES_DIR . 'class-field-mapper.php';
		require_once MEH_INCLUDES_DIR . 'class-duplicate-detector.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-editor.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-handler.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-endpoint.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';
	}

	public function set_up() {
		parent::set_up();

		global $wpdb, $wp_rest_server;

		// Created before the prefix is switched, so it lands in the real users
		// table rather than being affected by the fixture prefix.
		$this->actor = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		update_option( IntakeEndpoint::SECRET_OPTION, self::SECRET );
		update_option( 'meh_enquiry_list', 7 );
		update_option( 'meh_enquiry_tag', 12 );

		// Requirement 2.12: nothing here is configured for a particular sender,
		// so every field is resolved by its own name.
		delete_option( FieldMapper::OPTION );

		add_filter( 'meh_enquiry_terms_event_type', array( __CLASS__, 'event_type_terms' ) );
		add_filter( 'meh_enquiry_terms_site_exclusivity', array( __CLASS__, 'site_exclusivity_terms' ) );

		$this->crm = FakeCrm::install();

		Clock::freeze( self::NOW );

		wp_set_current_user( $this->actor );

		// A server of this test's own, so the route table holds what this test
		// registered and nothing a previous test left behind.
		$this->original_server = $wp_rest_server;
		$wp_rest_server        = new WP_REST_Server();

		add_action( 'rest_api_init', array( IntakeEndpoint::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );

		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		foreach ( $this->counted as $email ) {
			DuplicateDetector::reset( $email );
		}

		$this->counted = array();

		remove_action( 'rest_api_init', array( IntakeEndpoint::class, 'register_routes' ) );
		remove_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );

		remove_filter( 'meh_enquiry_terms_event_type', array( __CLASS__, 'event_type_terms' ) );
		remove_filter( 'meh_enquiry_terms_site_exclusivity', array( __CLASS__, 'site_exclusivity_terms' ) );

		wp_set_current_user( 0 );

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( IntakeEndpoint::SECRET_OPTION );
		delete_option( IntakeEndpoint::SOURCE_FIELD_OPTION );
		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		$wp_rest_server        = $this->original_server;
		$this->original_server = null;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * The permitted `event_type` vocabulary.
	 *
	 * @return string[]
	 */
	public static function event_type_terms() {
		return Generators::vocabulary( 'event_type' );
	}

	/**
	 * The permitted `site_exclusivity` vocabulary.
	 *
	 * @return string[]
	 */
	public static function site_exclusivity_terms() {
		return Generators::vocabulary( 'site_exclusivity' );
	}

	/**
	 * Feature: enquiry-data-layer, Property 9: Required-field validation is
	 * profile-scoped and names every failure.
	 *
	 * **Validates: Requirements 2.11, 3.1, 3.2, 3.8, 3.14, 3.15, 3.16, 18.2, 18.3, 18.15, 19.3**
	 */
	public function test_required_field_validation_is_profile_scoped_and_names_every_failure() {
		$this->limitTo( Iterations::count( 120 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $case ) {
					$this->check( $case );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * The claim
	 * ------------------------------------------------------------------ */

	/**
	 * One submission, down one path, judged against the profile and mode that
	 * path applies.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check( array $case ) {
		++$this->iteration;

		$path      = (string) $case['path'];
		$valid     = $this->valid_fields( (array) $case['payload'] );
		$styles    = self::styles( (array) $case['emptied'], (array) $case['styles'] );
		$submitted = self::submission( $valid, $styles );
		$expected  = self::expected_errors( $path, $styles );
		$label     = self::label( $path, $styles, $expected );

		if ( self::PATH_WEBHOOK === $path ) {
			$this->check_webhook( $submitted, $styles, $expected, $label );

			return;
		}

		if ( self::PATH_MANUAL === $path ) {
			$this->check_manual( $submitted, $expected, $label );

			return;
		}

		$this->check_edit( $submitted, $expected, $label );
	}

	/**
	 * The Webhook profile in full mode: all nine fields required
	 * (Requirements 3.14, 2.11), and a rejection writes exactly one rejected
	 * intake attempt with reason `validation` (Requirement 3.8).
	 *
	 * @param array  $submitted Field map to post.
	 * @param array  $styles    Field => emptiness style, for the subset.
	 * @param array  $expected  Expected field => code map.
	 * @param string $label     Failure context.
	 * @return void
	 */
	private function check_webhook( array $submitted, array $styles, array $expected, $label ) {
		$before = $this->row_counts();

		$response = $this->dispatch_intake( $submitted );
		$data     = (array) $response->get_data();

		$after = $this->row_counts();

		if ( array() === $expected ) {
			// An empty intersection is equivalent to no presence failure.
			$this->assertSame( 201, $response->get_status(), 'A submission carrying all nine fields is accepted. ' . $label );
			$this->assertTrue( ! empty( $data['created'] ), 'An accepted submission creates an enquiry. ' . $label );
			$this->assertSame( $before['enquiries'] + 1, $after['enquiries'], 'Exactly one enquiry is created. ' . $label );
			$this->assertSame( $before['rejections'], $after['rejections'], 'An accepted submission records no rejection. ' . $label );

			return;
		}

		// The sending form has already accepted the submission, so a refusal is
		// answered 200 carrying the reason rather than as an HTTP error.
		$this->assertSame( 200, $response->get_status(), 'A refused submission is answered 200. ' . $label );
		$this->assertEmpty( $data['created'], 'A refused submission creates nothing. ' . $label );
		$this->assertSame( self::REASON_VALIDATION, (string) $data['reason'], 'The refusal is a validation failure. ' . $label );

		$this->assert_names_every_failure( $expected, (array) $data['errors'], $label );

		// Requirement 3.8: no enquiry, and exactly one rejected intake attempt.
		$this->assertSame( $before['enquiries'], $after['enquiries'], 'A rejected submission creates no enquiry. ' . $label );
		$this->assertSame( $before['rejections'] + 1, $after['rejections'], 'Exactly one rejection row is written. ' . $label );

		$this->assert_rejection_row( $submitted, $styles, $expected, $label );
	}

	/**
	 * The Manual profile in full mode: four fields required (Requirements 3.15,
	 * 18.2), an empty optional field named by nothing (Requirement 3.16), a
	 * rejection answered 400 naming every failing field (Requirement 18.3), and
	 * no rejection row either way (Requirement 18.15).
	 *
	 * @param array  $submitted Field map to post.
	 * @param array  $expected  Expected field => code map.
	 * @param string $label     Failure context.
	 * @return void
	 */
	private function check_manual( array $submitted, array $expected, $label ) {
		$before = $this->row_counts();

		$response = $this->dispatch_manual( $submitted );

		$after = $this->row_counts();

		if ( array() === $expected ) {
			$this->assertSame( 201, $response->get_status(), 'A submission carrying the four required fields is accepted. ' . $label );
			$this->assertSame( $before['enquiries'] + 1, $after['enquiries'], 'Exactly one enquiry is created. ' . $label );
			$this->assertSame( $before['rejections'], $after['rejections'], 'Manual creation records no rejection. ' . $label );

			return;
		}

		$data = (array) $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'A rejected manual creation is answered 400. ' . $label );
		$this->assertSame( self::INVALID_CODE, (string) $data['code'], 'The refusal names the invalid-enquiry code. ' . $label );

		$this->assert_names_every_failure( $expected, self::response_errors( $data ), $label );

		// Requirements 18.3, 18.15: nothing at all was written — no enquiry, and
		// no rejected intake attempt.
		$this->assertSame( $before, $after, 'A rejected manual creation writes nothing. ' . $label );
	}

	/**
	 * The Manual profile in partial mode: an absent required field is not being
	 * changed and produces no failure, while a required field submitted empty
	 * fails (Requirement 19.3), and a rejected edit leaves the stored enquiry
	 * exactly as it was and records no rejection.
	 *
	 * @param array  $submitted Field map to patch with.
	 * @param array  $expected  Expected field => code map.
	 * @param string $label     Failure context.
	 * @return void
	 */
	private function check_edit( array $submitted, array $expected, $label ) {
		$id = $this->seed_enquiry();

		$stored  = EnquiryStore::find( $id );
		$history = HistoryRecorder::for_enquiry( $id );
		$before  = $this->row_counts();

		$response = $this->dispatch_edit( $id, $submitted );

		$after = $this->row_counts();

		if ( array() === $expected ) {
			$this->assertSame( 200, $response->get_status(), 'An edit naming no empty required field is accepted. ' . $label );
			$this->assertSame( $before['enquiries'], $after['enquiries'], 'An edit creates no enquiry. ' . $label );
			$this->assertSame( $before['rejections'], $after['rejections'], 'An edit records no rejection. ' . $label );

			return;
		}

		$data = (array) $response->get_data();

		$this->assertSame( 400, $response->get_status(), 'A rejected edit is answered 400. ' . $label );
		$this->assertSame( self::INVALID_CODE, (string) $data['code'], 'The refusal names the invalid-enquiry code. ' . $label );

		$this->assert_names_every_failure( $expected, self::response_errors( $data ), $label );

		$this->assertSame( $before, $after, 'A rejected edit writes nothing. ' . $label );
		$this->assertSame( $stored, EnquiryStore::find( $id ), 'A rejected edit leaves every stored value alone. ' . $label );
		$this->assertSame( $history, HistoryRecorder::for_enquiry( $id ), 'A rejected edit appends no history. ' . $label );
	}

	/**
	 * The error map names exactly the expected fields, with the expected code for
	 * each, and no other field (Requirements 3.2, 18.6).
	 *
	 * @param array  $expected Expected field => code map.
	 * @param array  $actual   Reported field => code map.
	 * @param string $label    Failure context.
	 * @return void
	 */
	private function assert_names_every_failure( array $expected, array $actual, $label ) {
		$wanted = array_keys( $expected );
		$named  = array_keys( $actual );

		sort( $wanted );
		sort( $named );

		$this->assertSame(
			$wanted,
			$named,
			'The result names exactly the failing fields and no other. ' . $label
		);

		foreach ( $expected as $field => $code ) {
			$this->assertSame(
				$code,
				isset( $actual[ $field ] ) ? (string) $actual[ $field ] : '',
				sprintf( 'The failure for %s distinguishes absence from emptiness. %s', $field, $label )
			);
		}
	}

	/**
	 * The one rejection row a refused webhook submission earns carries the
	 * payload snapshot and a reason for each failing field (Requirement 3.8).
	 *
	 * The snapshot is what the endpoint handed the handler, so a multi-value
	 * field arrives as a list however the sender delivered it: a `selected_dates`
	 * posted as `''` is snapshotted as an empty list. So a field the case made
	 * empty is asserted to be empty in the snapshot, and every other field to be
	 * carried verbatim, rather than the test restating the endpoint's own
	 * normalisation and asserting against that.
	 *
	 * @param array  $submitted Field map that was posted.
	 * @param array  $styles    Field => emptiness style, for the subset.
	 * @param array  $expected  Expected field => code map.
	 * @param string $label     Failure context.
	 * @return void
	 */
	private function assert_rejection_row( array $submitted, array $styles, array $expected, $label ) {
		$page = EnquiryStore::rejections( array( 'per_page' => 1 ) );
		$rows = isset( $page['items'] ) ? (array) $page['items'] : array();

		$this->assertNotEmpty( $rows, 'The rejection row is readable. ' . $label );

		$row = (array) $rows[0];

		$this->assertSame( self::REASON_VALIDATION, (string) $row['reason'], 'The row names reason validation. ' . $label );

		$detail = isset( $row['detail'] ) ? (array) $row['detail'] : array();
		$errors = isset( $detail['errors'] ) ? (array) $detail['errors'] : array();

		$this->assert_names_every_failure( $expected, $errors, 'Rejection row. ' . $label );

		$payload = isset( $row['payload'] ) ? (array) $row['payload'] : array();

		foreach ( $submitted as $field => $value ) {
			$this->assertArrayHasKey( $field, $payload, sprintf( 'The row carries %s. %s', $field, $label ) );

			if ( array_key_exists( $field, $styles ) ) {
				$this->assertTrue(
					self::is_blank( $payload[ $field ] ),
					sprintf( 'The row carries %s as the empty value it was submitted as. %s', $field, $label )
				);

				continue;
			}

			$this->assertEquals(
				$value,
				$payload[ $field ],
				sprintf( 'The row carries the submitted %s. %s', $field, $label )
			);
		}
	}

	/**
	 * Whether a snapshotted value holds nothing.
	 *
	 * A local predicate rather than `Validator::is_empty()`: the emptiness this
	 * asserts is the property's own notion, and borrowing the one the code under
	 * test uses would make the assertion true by construction.
	 *
	 * @param mixed $value Snapshotted value.
	 * @return bool
	 */
	private static function is_blank( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::is_blank( $item ) ) {
					return false;
				}
			}

			return true;
		}

		return ! is_scalar( $value ) || '' === trim( (string) $value );
	}

	/* ---------------------------------------------------------------------
	 * Dispatch
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch one authenticated intake webhook request.
	 *
	 * @param array $payload Field map to post.
	 * @return \WP_REST_Response
	 */
	private function dispatch_intake( array $payload ) {
		$request = new WP_REST_Request( 'POST', '/' . IntakeEndpoint::NAMESPACE . IntakeEndpoint::ROUTE );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( IntakeEndpoint::SECRET_HEADER, self::SECRET );
		$request->set_body( (string) wp_json_encode( (object) $payload ) );

		return rest_do_request( $request );
	}

	/**
	 * Dispatch one manual enquiry creation request.
	 *
	 * @param array $fields Field map to post.
	 * @return \WP_REST_Response
	 */
	private function dispatch_manual( array $fields ) {
		$request = new WP_REST_Request( 'POST', '/' . RestEnquiries::NAMESPACE . RestEnquiries::ROUTE_COLLECTION );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( (object) $fields ) );

		return rest_do_request( $request );
	}

	/**
	 * Dispatch one enquiry edit request.
	 *
	 * @param int   $id     Enquiry to correct.
	 * @param array $fields Field map to patch with.
	 * @return \WP_REST_Response
	 */
	private function dispatch_edit( $id, array $fields ) {
		$request = new WP_REST_Request( 'PATCH', '/' . RestEnquiries::NAMESPACE . '/enquiries/' . (int) $id );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( (object) $fields ) );

		return rest_do_request( $request );
	}

	/**
	 * The per-field error map a 400 carries.
	 *
	 * WordPress renders a `WP_Error` as `{ code, message, data: { status, … } }`,
	 * so the map the route put under `errors` arrives nested inside `data`.
	 *
	 * @param array $data Response body.
	 * @return array<string,string>
	 */
	private static function response_errors( array $data ) {
		$detail = isset( $data['data'] ) ? (array) $data['data'] : array();

		return isset( $detail['errors'] ) ? (array) $detail['errors'] : array();
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: a valid nine-field submission, the subset of fields to be made
	 * empty, an emptiness style per field, and the path to submit down.
	 *
	 * The style is drawn for all nine fields rather than only for the subset,
	 * because Eris draws the whole structure before anything inspects it; the
	 * styles of fields outside the subset are simply never read.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		$styles = array();

		foreach ( self::FIELDS as $field ) {
			$styles[ $field ] = \Eris\Generators::elements( self::styles_for( $field ) );
		}

		return \Eris\Generators::associative(
			array(
				'payload' => Generators::enquiry(),
				'emptied' => \Eris\Generators::subset( self::FIELDS ),
				'styles'  => \Eris\Generators::associative( $styles ),
				'path'    => \Eris\Generators::elements( self::PATHS ),
			)
		);
	}

	/**
	 * The emptiness styles a field can carry.
	 *
	 * A multi-value field can arrive as an empty list or as a list holding
	 * nothing but blanks — the shape a form padding a multi-select actually
	 * sends — while a scalar cannot; a scalar can arrive whitespace-only.
	 *
	 * @param string $field Enquiry field name.
	 * @return string[]
	 */
	protected static function styles_for( $field ) {
		if ( in_array( $field, self::COLLECTION_FIELDS, true ) ) {
			return array(
				self::STYLE_ABSENT,
				self::STYLE_EMPTY_STRING,
				self::STYLE_EMPTY_COLLECTION,
				self::STYLE_BLANK_COLLECTION,
			);
		}

		return array(
			self::STYLE_ABSENT,
			self::STYLE_EMPTY_STRING,
			self::STYLE_WHITESPACE,
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step grows
	 * exponentially with the number of generated values. A case here draws a
	 * nine-field enquiry, two of whose fields are sets, plus a subset, nine
	 * styles and a path, which puts that product beyond what fits in memory: a
	 * failing iteration would report an out-of-memory fatal instead of the
	 * counterexample.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the case as generated, together with the assertion's own
	 * diff and the `ERIS_SEED` line that reproduces the run exactly.
	 *
	 * @param \Eris\Generator $generator Generator to draw from.
	 * @return \Eris\Generator
	 */
	protected static function unshrunk( \Eris\Generator $generator ) {
		return \Eris\Generators::bind(
			$generator,
			static function ( $drawn ) {
				return \Eris\Generators::constant( $drawn );
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * The case, resolved
	 * ------------------------------------------------------------------ */

	/**
	 * The nine valid field values, with an address unique to this iteration.
	 *
	 * Uniqueness keeps the duplicate guard and the rate limiter out of the way,
	 * so a submission is never turned away for a reason this property is not
	 * about.
	 *
	 * @param array $fields Generated field map.
	 * @return array
	 */
	private function valid_fields( array $fields ) {
		$fields['email'] = sprintf( 'enquirer+%d@example.com', $this->iteration );

		$this->counted[] = $fields['email'];

		return $fields;
	}

	/**
	 * The emptiness style of each field in the drawn subset.
	 *
	 * @param array $emptied Subset of the nine fields.
	 * @param array $drawn   Style drawn for every field.
	 * @return array<string,string> Field => style, for the subset only.
	 */
	private static function styles( array $emptied, array $drawn ) {
		$styles = array();

		foreach ( self::FIELDS as $field ) {
			if ( in_array( $field, $emptied, true ) && isset( $drawn[ $field ] ) ) {
				$styles[ $field ] = (string) $drawn[ $field ];
			}
		}

		return $styles;
	}

	/**
	 * The field map to submit: valid values throughout, except that each field in
	 * the subset is omitted or carries its emptiness style.
	 *
	 * @param array $valid  Valid values for all nine fields.
	 * @param array $styles Field => emptiness style, for the subset.
	 * @return array
	 */
	private static function submission( array $valid, array $styles ) {
		$submitted = array();

		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $styles ) ) {
				$submitted[ $field ] = $valid[ $field ];

				continue;
			}

			if ( self::STYLE_ABSENT === $styles[ $field ] ) {
				continue;
			}

			$submitted[ $field ] = self::empty_value( $styles[ $field ] );
		}

		return $submitted;
	}

	/**
	 * One empty value of a given style.
	 *
	 * @param string $style One of the STYLE_ constants.
	 * @return mixed
	 */
	private static function empty_value( $style ) {
		if ( self::STYLE_WHITESPACE === $style ) {
			return "  \t ";
		}

		if ( self::STYLE_EMPTY_COLLECTION === $style ) {
			return array();
		}

		if ( self::STYLE_BLANK_COLLECTION === $style ) {
			return array( '   ', '' );
		}

		return '';
	}

	/**
	 * The error map this case must produce: the intersection of the emptied
	 * subset with the path's required set, and nothing else.
	 *
	 * The required sets are the ones Requirements 3.14 and 3.15 name, restated in
	 * this file rather than read from the Validator. The mode decides only what
	 * absence means: a failure on creation, and no failure at all on an edit
	 * (Requirement 19.3).
	 *
	 * @param string $path   One of self::PATHS.
	 * @param array  $styles Field => emptiness style, for the subset.
	 * @return array<string,string> Field => expected code.
	 */
	private static function expected_errors( $path, array $styles ) {
		$required = self::PATH_WEBHOOK === $path ? self::WEBHOOK_REQUIRED : self::MANUAL_REQUIRED;
		$partial  = self::PATH_EDIT === $path;
		$errors   = array();

		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $styles ) || ! in_array( $field, $required, true ) ) {
				continue;
			}

			$absent = self::STYLE_ABSENT === $styles[ $field ];

			if ( $absent && $partial ) {
				continue;
			}

			$errors[ $field ] = $absent ? self::CODE_REQUIRED : self::CODE_EMPTY;
		}

		return $errors;
	}

	/**
	 * A one-line description of the case, for failure messages.
	 *
	 * @param string $path     Path submitted down.
	 * @param array  $styles   Field => emptiness style.
	 * @param array  $expected Expected field => code map.
	 * @return string
	 */
	private static function label( $path, array $styles, array $expected ) {
		$emptied = array();

		foreach ( $styles as $field => $style ) {
			$emptied[] = $field . '=' . $style;
		}

		return sprintf(
			'[%s path, emptied {%s}, expecting {%s}]',
			$path,
			implode( ', ', $emptied ),
			implode( ', ', array_keys( $expected ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write one open enquiry to correct, holding values no submission carries.
	 *
	 * Distinct from anything the generators emit on purpose: a rejected edit that
	 * had written the submitted values before validating would show up in the
	 * before/after comparison rather than passing unnoticed.
	 *
	 * @return int
	 */
	private function seed_enquiry() {
		$id = EnquiryStore::create(
			array(
				'first_name'        => 'Grace',
				'last_name'         => 'Hopper',
				'email'             => sprintf( 'seed+%d@example.com', $this->iteration ),
				'phone'             => '0131 496 0000',
				'total_guests'      => 42,
				'message'           => 'Telephoned about a weekend in July.',
				'status'            => 'new',
				'created_at'        => self::NOW,
				'updated_at'        => self::NOW,
				'status_changed_at' => self::NOW,
				'source'            => 'manual:' . $this->actor,
			),
			array( '2025-07-04' ),
			array(
				'event_type'       => array( 'wake' ),
				'site_exclusivity' => array( 'shared-use' ),
			)
		);

		$this->assertIsInt( $id, 'Seeding an enquiry to correct should succeed.' );

		return (int) $id;
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The row count of every Enquiry Store table.
	 *
	 * @return array<string,int>
	 */
	private function row_counts() {
		global $wpdb;

		$counts = array();

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table          = Schema::table( $key );
			$counts[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		return $counts;
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
