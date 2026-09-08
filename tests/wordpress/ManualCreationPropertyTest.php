<?php
/**
 * Property 39: Manual creation is a full enquiry, unguarded and attributed.
 *
 * Feature: enquiry-data-layer, Property 39: For any manual enquiry creation
 * request that validates under the Manual Validation Profile — over any subset
 * of `phone`, `total_guests`, `message`, `event_type` and `site_exclusivity`
 * omitted or submitted empty, any 1 to 3 candidate date ranges, any submitting user
 * identifier, any receipt time, any site timezone and any environment mode — the
 * route creates exactly one enquiry in which every submitted field value is
 * stored, each omitted or empty scalar stores its empty value and each omitted or
 * empty multi-select stores zero rows; `status` is `new` and the three timestamps
 * all equal the receipt time in the site timezone; `source` is `manual:{user
 * id}`; one candidate range row exists per submitted range and one term row per
 * submitted value; the payload snapshot carries every submitted field value;
 * `is_test` and the contact's test marking follow staging mode; exactly one
 * `created` history entry exists carrying the submitting user; and
 * `crm_sync_state` is `synced` on linkage success and `pending` on failure with
 * the enquiry retained either way. And for any sequence of manual creation
 * requests holding the same `email` and the same candidate-range set, arriving
 * within any window including one shorter than the duplicate window and the
 * rate-limit period: every one of them creates an enquiry, the duplicate
 * detector and the rate limiter are not consulted, and no rejected intake
 * attempt row is written for any of them — while intake webhook requests
 * interleaved among them remain guarded exactly as Properties 14 and 15
 * describe.
 *
 * **Validates: Requirements 18.1, 18.4, 18.5, 18.7, 18.8, 18.9, 18.10, 18.11,
 * 18.12, 18.13, 18.14, 18.15, 18.16, 18.17, 18.18, 18.19, 18.20**
 *
 * How the property is instantiated, and why:
 *
 * - **Every submission travels the real route.** Each iteration builds a
 *   `WP_REST_Request` for `POST /enquiries` and dispatches it with
 *   `rest_do_request()`, so the permission callback, the declared args and their
 *   `sanitize_callback`s all run where WordPress runs them. Calling
 *   `EnquiryCreator::create()` directly would skip the two things the route
 *   itself decides and the property is about: the receipt time it reads and the
 *   `manual:{user id}` source it derives.
 * - **The five optional fields are quantified independently over three
 *   treatments** — submitted with a value, omitted altogether, and submitted
 *   empty (Requirements 18.4, 18.5). All three have to be drawn separately:
 *   "omitted" and "submitted empty" arrive at the same stored value by different
 *   routes through the Validator, and an implementation that confused either
 *   with "submitted" would store a value nobody chose.
 * - **The submitting user is drawn from three real WordPress users**, created
 *   before the fixture prefix is switched so they land in the real users table.
 *   `source` and the `created` entry's actor are then asserted against that
 *   user's own identifier, so an implementation hard-coding one or attributing to
 *   the system fails here.
 * - **The receipt instant and the site timezone are drawn independently**, and
 *   the expected `DATETIME` is computed with plain PHP date handling rather than
 *   by asking `Clock` what it thinks. The zone set covers whole-hour, half-hour
 *   and three-quarter-hour offsets and both northern and southern daylight
 *   saving, and instants span a whole year, so both sides of every transition are
 *   reached.
 * - **Both environment modes are drawn**, staging switched on with the
 *   `meh_is_staging` filter the bootstrap helper ends on. Every marking claim is
 *   an "exactly when": in production `is_test` is false, the contact name carries
 *   no prefix and the `test-record` tag is absent (Requirements 18.19, 18.20).
 * - **All four CRM outcomes are drawn** — success, an API that raises, a response
 *   carrying no subscriber id, and FluentCRM absent — so `crm_sync_state` is
 *   asserted to be `synced` exactly on success and `pending` otherwise, with the
 *   enquiry and every stored value retained either way (Requirement 18.16).
 * - **The submitted values are ones the route's declared sanitisation is the
 *   identity on**: no markup, no surrounding or repeated whitespace and no
 *   percent-encoded sequence, drawn at plain, unicode, quoted, adversarial and
 *   exactly-at-capacity shapes. That is what lets "stored equals submitted" be
 *   asserted against the submission itself rather than against what the code
 *   says it made of it. A value carrying markup or a percent-encoded sequence is
 *   one `sanitize_text_field()` is required to rewrite (Requirement 16.7), and
 *   what the stored value then is belongs to the sanitisation property
 *   (Property 11) rather than here.
 * - **`total_guests` is submitted as a numeric string.** The route declares
 *   `sanitize_text_field` on it, so an integer would arrive as its string form
 *   and the payload snapshot would hold that string; submitting the string is a
 *   shape a form genuinely sends and keeps the snapshot assertion exact.
 * - **The unguarded half is stated as three separate observations**, because
 *   "the guards were not consulted" is not directly visible: every request in the
 *   sequence is created even though `DuplicateDetector::find_duplicate()` is
 *   asserted to be reporting a match at the time it arrives, so the detector
 *   cannot have been asked; `DuplicateDetector::hits()` — a read that counts
 *   nothing — is asserted to be 0 after the whole sequence, so the rate limiter's
 *   counter was never incremented and therefore never consulted; and the
 *   rejections table is asserted unchanged across every manual dispatch.
 * - **The webhook requests interleaved among them are asserted to still be
 *   guarded**, which is the other half of Requirement 18.12: a webhook carrying
 *   the manual sequence's email and date set is refused as a duplicate, and the
 *   sixth webhook of the run is refused as rate limited — the sixth *webhook*,
 *   not the sixth request, which is only true if the manual requests never
 *   touched the counter. They go through `IntakeHandler::receive()` directly,
 *   since the guards live there and the endpoint's own share of intake belongs to
 *   Properties 6 and 7.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix segment: the WordPress test case rewrites `CREATE TABLE` into its
 * `TEMPORARY` form, and `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see a temporary table.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\ContactLinker;
use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\IntakeHandler;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\StagingMarker;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class ManualCreationPropertyTest
 */
class ManualCreationPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table or with another test's fixture in the same database.
	 */
	const PREFIX_SEGMENT = 'mehmc_';

	/**
	 * The history entry type a created enquiry appends (Requirement 18.18).
	 */
	const CREATED_ENTRY = 'created';

	/**
	 * The `source` prefix a manually created enquiry must carry
	 * (Requirement 18.8).
	 *
	 * Spelled out rather than read from `RestEnquiries`, so an implementation
	 * changing the shape of the value fails here.
	 */
	const MANUAL_PREFIX = 'manual:';

	/**
	 * The five fields the Manual profile leaves optional.
	 *
	 * @var string[]
	 */
	const OPTIONAL_FIELDS = array( 'phone', 'total_guests', 'message', 'event_type', 'site_exclusivity' );

	/**
	 * How one optional field may arrive (Requirements 18.4, 18.5).
	 *
	 * @var string[]
	 */
	const TREATMENTS = array( 'present', 'omitted', 'empty' );

	/**
	 * Site timezones the property is quantified over.
	 *
	 * @var string[]
	 */
	const ZONES = array(
		'UTC',
		'Europe/London',
		'Europe/Berlin',
		'America/Los_Angeles',
		'Australia/Sydney',
		'Asia/Kolkata',
		'Pacific/Chatham',
	);

	/** First instant a receipt time may be drawn at: 2025-01-01 00:00:00 UTC. */
	const WINDOW_START = 1735689600;

	/** Last instant a receipt time may be drawn at: 2025-12-31 23:59:59 UTC. */
	const WINDOW_END = 1767225599;

	/**
	 * The four contact-linkage outcomes (Requirement 18.16).
	 *
	 * @var string[]
	 */
	const CRM_OUTCOMES = array( 'ok', 'throws', 'no_id', 'unavailable' );

	/**
	 * Address shapes an iteration may submit.
	 *
	 * @var string[]
	 */
	const EMAIL_SHAPES = array( 'plain', 'quoted', 'capacity' );

	/**
	 * Values the route's declared sanitisation is the identity on: no markup, no
	 * repeated or surrounding whitespace, and no percent-encoded sequence.
	 *
	 * @var string[]
	 */
	const SAFE_TOKENS = array(
		"'",
		'"',
		'\\',
		'--',
		';',
		'%',
		'_',
		'%s',
		'%%',
		"Robert'); DROP TABLE meh_enquiries; --",
		'100% _ %s',
		'C:\\path\\to\\nowhere',
		"Ada'",
	);

	/** Webhook requests one unguarded-sequence iteration interleaves. */
	const WEBHOOK_STEPS = 6;

	/** The `source` an interleaved webhook submission carries. */
	const WEBHOOK_SOURCE = 'webhook:manual-property';

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The `timezone_string` option in force outside this test.
	 *
	 * @var string
	 */
	private $original_timezone = '';

	/**
	 * The REST server in force outside this test.
	 *
	 * @var \WP_REST_Server|null
	 */
	private $original_server = null;

	/**
	 * Real WordPress users a submission may be attributed to.
	 *
	 * @var int[]
	 */
	private $users = array();

	/**
	 * The FluentCRM fake.
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

		// Created before the prefix is switched, so they land in the real users
		// table rather than in a fixture one.
		for ( $index = 0; $index < 3; $index++ ) {
			$this->users[] = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		}

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		$this->original_timezone = (string) get_option( 'timezone_string', '' );

		$this->crm = FakeCrm::install();

		// The vocabularies the Validator checks a submitted multi-select
		// against, so every generated `event_type`/`site_exclusivity` value is
		// one the manual creation route accepts.
		foreach ( array_keys( Generators::VOCABULARIES ) as $taxonomy ) {
			add_filter(
				'meh_enquiry_terms_' . $taxonomy,
				static function () use ( $taxonomy ) {
					return Generators::vocabulary( $taxonomy );
				}
			);
		}

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
		remove_filter( 'meh_is_staging', '__return_true' );

		wp_set_current_user( 0 );

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );
		update_option( 'timezone_string', $this->original_timezone );

		$wp_rest_server = $this->original_server;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * The two claims
	 * ------------------------------------------------------------------ */

	/**
	 * Feature: enquiry-data-layer, Property 39: Manual creation is a full
	 * enquiry, unguarded and attributed — the created enquiry.
	 *
	 * **Validates: Requirements 18.1, 18.4, 18.5, 18.7, 18.8, 18.9, 18.10,
	 * 18.11, 18.16, 18.17, 18.18, 18.19, 18.20**
	 */
	public function test_manual_creation_produces_a_full_attributed_enquiry() {
		$this->limitTo( Iterations::count( 60 ) )
			->forAll( self::unshrunk( self::creation_scenario() ) )
			->then(
				function ( array $case ) {
					$this->check_creation( $case );
				}
			);
	}

	/**
	 * Feature: enquiry-data-layer, Property 39: Manual creation is a full
	 * enquiry, unguarded and attributed — the unguarded sequence.
	 *
	 * **Validates: Requirements 18.12, 18.13, 18.14, 18.15**
	 */
	public function test_manual_creation_is_never_guarded() {
		$this->limitTo( Iterations::count( 25 ) )
			->forAll( self::unshrunk( self::sequence_scenario() ) )
			->then(
				function ( array $case ) {
					$this->check_sequence( $case );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Claim one: one manual creation request
	 * ------------------------------------------------------------------ */

	/**
	 * One manual creation request, dispatched and judged.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check_creation( array $case ) {
		$this->prepare( $case );

		$actor     = $this->users[ (int) $case['user'] % count( $this->users ) ];
		$submitted = $this->submission( $case );
		$expected  = self::wall_clock( (int) $case['received'], (string) $case['timezone'] );
		$label     = self::creation_label( $case, $expected );

		wp_set_current_user( $actor );

		$before   = $this->row_counts();
		$response = $this->dispatch_manual( $submitted );
		$data     = (array) $response->get_data();

		// Requirement 18.1.
		$this->assertSame( 201, $response->get_status(), 'A valid manual submission is created. ' . $label . ' ' . wp_json_encode( $data ) );

		$after = $this->row_counts();

		$this->assertSame(
			$before['enquiries'] + 1,
			$after['enquiries'],
			'Exactly one enquiry is created. ' . $label
		);

		// Requirement 18.15.
		$this->assertSame(
			$before['rejections'],
			$after['rejections'],
			'A created manual enquiry records no rejected intake attempt. ' . $label
		);

		$id      = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$enquiry = EnquiryStore::find( $id );

		$this->assertIsArray( $enquiry, 'The created enquiry is readable. ' . $label );

		$this->assert_scalars( $enquiry, $submitted, $label );
		$this->assert_sets( $enquiry, $submitted, $label );
		$this->assert_child_rows( $id, $submitted, $label );
		$this->assert_snapshot( $enquiry, $submitted, $label );

		// Requirement 18.7.
		$this->assertSame( 'new', $enquiry['status'], 'A manually created enquiry holds status new. ' . $label );

		// Requirement 18.9.
		foreach ( array( 'created_at', 'updated_at', 'status_changed_at' ) as $column ) {
			$this->assertSame(
				$expected,
				$enquiry[ $column ],
				sprintf( '%s is the receipt time, site-local. %s', $column, $label )
			);
		}

		// Requirement 18.8.
		$this->assertSame(
			self::MANUAL_PREFIX . $actor,
			$enquiry['source'],
			'source identifies manual creation and the submitting user. ' . $label
		);

		// Requirements 18.19, 18.20.
		$this->assertSame(
			(bool) $case['staging'],
			$enquiry['is_test'],
			'is_test is true exactly when staging mode is reported. ' . $label
		);

		$this->assert_created_history( $id, $actor, $label );
		$this->assert_linkage( $enquiry, $case, $label );
	}

	/**
	 * Every stored scalar equals the value the request submitted, and an omitted
	 * or empty optional scalar holds its empty value (Requirements 18.4, 18.10).
	 *
	 * @param array  $enquiry   Stored enquiry.
	 * @param array  $submitted Submitted field map.
	 * @param string $label     Failure context.
	 * @return void
	 */
	private function assert_scalars( array $enquiry, array $submitted, $label ) {
		foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'message' ) as $field ) {
			$expected = array_key_exists( $field, $submitted ) ? (string) $submitted[ $field ] : '';

			$this->assertSame(
				$expected,
				$enquiry[ $field ],
				sprintf( 'Stored %s equals the submitted value. %s', $field, $label )
			);
		}

		// An unsupplied or empty guest count is null, never 0: 0 is outside the
		// valid range, so it could never be told apart from a real count.
		$guests = isset( $submitted['total_guests'] ) && '' !== $submitted['total_guests']
			? (int) $submitted['total_guests']
			: null;

		$this->assertSame(
			$guests,
			$enquiry['total_guests'],
			'Stored total_guests equals the submitted value, or is null. ' . $label
		);
	}

	/**
	 * The candidate ranges and both multi-selects equal the submitted values as
	 * sets, an omitted or empty multi-select being the empty set
	 * (Requirements 18.5, 18.10).
	 *
	 * @param array  $enquiry   Stored enquiry.
	 * @param array  $submitted Submitted field map.
	 * @param string $label     Failure context.
	 * @return void
	 */
	private function assert_sets( array $enquiry, array $submitted, $label ) {
		$this->assertSame(
			array_values( (array) $submitted['date_ranges'] ),
			$enquiry['date_ranges'],
			'Stored date_ranges equals the submitted list, rank order and all. ' . $label
		);

		foreach ( array( 'event_type', 'site_exclusivity' ) as $field ) {
			$expected = array_key_exists( $field, $submitted ) ? (array) $submitted[ $field ] : array();

			$this->assertSame(
				self::as_set( $expected ),
				self::as_set( $enquiry[ $field ] ),
				sprintf( 'Stored %s equals the submitted values as a set. %s', $field, $label )
			);
		}
	}

	/**
	 * One candidate range row per submitted range and one term row per submitted
	 * value, counted in the tables themselves (Requirements 18.5, 18.10).
	 *
	 * Counted rather than read back through `find()`, because "zero rows" is the
	 * claim Requirement 18.5 makes and a hydrated empty array would also be
	 * produced by rows a `taxonomy` filter happened to miss.
	 *
	 * @param int    $enquiry_id Created enquiry.
	 * @param array  $submitted  Submitted field map.
	 * @param string $label      Failure context.
	 * @return void
	 */
	private function assert_child_rows( $enquiry_id, array $submitted, $label ) {
		global $wpdb;

		$dates = Schema::table( 'dates' );
		$terms = Schema::table( 'terms' );

		$this->assertSame(
			count( (array) $submitted['date_ranges'] ),
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$dates} WHERE enquiry_id = %d", $enquiry_id ) ), // phpcs:ignore WordPress.DB
			'One candidate range row exists per submitted range. ' . $label
		);

		foreach ( array( 'event_type', 'site_exclusivity' ) as $taxonomy ) {
			$expected = array_key_exists( $taxonomy, $submitted ) ? (array) $submitted[ $taxonomy ] : array();

			$this->assertSame(
				count( self::as_set( $expected ) ),
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$terms} WHERE enquiry_id = %d AND taxonomy = %s", $enquiry_id, $taxonomy ) ), // phpcs:ignore WordPress.DB
				sprintf( 'One %s row exists per submitted value, and zero when none was submitted. %s', $taxonomy, $label )
			);
		}
	}

	/**
	 * The payload snapshot carries every field value the request submitted, and
	 * nothing it did not (Requirement 18.11).
	 *
	 * @param array  $enquiry   Stored enquiry.
	 * @param array  $submitted Submitted field map.
	 * @param string $label     Failure context.
	 * @return void
	 */
	private function assert_snapshot( array $enquiry, array $submitted, $label ) {
		$snapshot = (array) $enquiry['payload'];

		foreach ( $submitted as $field => $value ) {
			$this->assertArrayHasKey(
				$field,
				$snapshot,
				sprintf( 'The payload snapshot carries %s. %s', $field, $label )
			);
			$this->assertSame(
				$value,
				$snapshot[ $field ],
				sprintf( 'The snapshot holds the submitted %s value. %s', $field, $label )
			);
		}

		foreach ( self::OPTIONAL_FIELDS as $field ) {
			if ( array_key_exists( $field, $submitted ) ) {
				continue;
			}

			$this->assertArrayNotHasKey(
				$field,
				$snapshot,
				sprintf( 'The snapshot carries no %s the request did not submit. %s', $field, $label )
			);
		}
	}

	/**
	 * Exactly one `created` entry exists, attributed to the submitting user
	 * rather than to the system (Requirement 18.18).
	 *
	 * @param int    $enquiry_id Created enquiry.
	 * @param int    $actor      Submitting user.
	 * @param string $label      Failure context.
	 * @return void
	 */
	private function assert_created_history( $enquiry_id, $actor, $label ) {
		$created = array();

		foreach ( HistoryRecorder::for_enquiry( $enquiry_id ) as $entry ) {
			if ( self::CREATED_ENTRY === $entry['entry_type'] ) {
				$created[] = $entry;
			}
		}

		$this->assertCount( 1, $created, 'Exactly one created history entry exists. ' . $label );
		$this->assertSame(
			(int) $actor,
			(int) $created[0]['actor_id'],
			'The created entry carries the submitting user as the acting user. ' . $label
		);
	}

	/**
	 * `crm_sync_state` follows the linkage outcome, and the linked contact carries
	 * the test marking exactly in staging (Requirements 18.16, 18.17).
	 *
	 * @param array  $enquiry Stored enquiry.
	 * @param array  $case    Generated case.
	 * @param string $label   Failure context.
	 * @return void
	 */
	private function assert_linkage( array $enquiry, array $case, $label ) {
		$linked  = 'ok' === (string) $case['crm'];
		$staging = (bool) $case['staging'];

		// Requirement 18.16: either way the enquiry is retained, which the
		// stored-value assertions above have already established.
		$this->assertSame(
			$linked ? ContactLinker::STATE_SYNCED : ContactLinker::STATE_PENDING,
			$enquiry['crm_sync_state'],
			'crm_sync_state is synced on linkage success and pending on failure. ' . $label
		);

		if ( ! $linked ) {
			return;
		}

		$key      = strtolower( trim( (string) $enquiry['email'] ) );
		$contact  = $this->crm->contact( $key );
		$expected = self::marked( (string) $enquiry['first_name'], $staging );

		$this->assertIsArray( $contact, 'A successful linkage writes a contact. ' . $label );

		$this->assertSame(
			$expected,
			isset( $contact['data']['first_name'] ) ? (string) $contact['data']['first_name'] : '',
			sprintf( 'The contact holds the %s first_name. %s', $staging ? 'prefixed' : 'unprefixed', $label )
		);

		// Requirement 18.17, stated in both directions.
		if ( $staging ) {
			$this->assertContains(
				ContactLinker::TEST_TAG,
				$this->crm->tags_for( $key ),
				'A staging contact carries the test-record tag. ' . $label
			);

			return;
		}

		$this->assertNotContains(
			ContactLinker::TEST_TAG,
			$this->crm->tags_for( $key ),
			'A production contact carries no test-record tag. ' . $label
		);
	}

	/* ---------------------------------------------------------------------
	 * Claim two: a sequence of identical manual creation requests
	 * ------------------------------------------------------------------ */

	/**
	 * A run of manual creation requests all holding the same email and the same
	 * candidate ranges, with intake webhook requests interleaved among them.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check_sequence( array $case ) {
		$this->prepare( $case );

		$actor  = $this->users[ (int) $case['user'] % count( $this->users ) ];
		$fields = $this->submission( $case );
		$email  = (string) $fields['email'];
		$manual = (int) $case['length'];
		$label  = self::sequence_label( $case );
		$gaps   = array_values( (array) $case['gaps'] );
		$at     = (int) $case['received'];
		$step   = 0;
		$ids    = array();
		$refused = 0;

		wp_set_current_user( $actor );

		for ( $index = 0; $index < self::WEBHOOK_STEPS; $index++ ) {
			if ( $index < $manual ) {
				Clock::freeze( $at );
				$at += (int) $gaps[ $step % count( $gaps ) ];
				++$step;

				$ids[] = $this->create_manually( $fields, $refused, $index, $label );
			}

			Clock::freeze( $at );
			$at += (int) $gaps[ $step % count( $gaps ) ];
			++$step;

			$refused += $this->submit_webhook( $fields, $index, $label ) ? 0 : 1;
		}

		// Every manual request left in the sequence, after the webhook run, so a
		// sequence longer than the webhook run is still exercised in full.
		for ( $index = self::WEBHOOK_STEPS; $index < $manual; $index++ ) {
			Clock::freeze( $at );
			$at += (int) $gaps[ $step % count( $gaps ) ];
			++$step;

			$ids[] = $this->create_manually( $fields, $refused, $index, $label );
		}

		// Requirements 18.13, 18.14: every request in the sequence created an
		// enquiry of its own, however close together they arrived.
		$this->assertCount( $manual, array_unique( $ids ), 'Every manual request creates a distinct enquiry. ' . $label );

		foreach ( $ids as $id ) {
			$this->assertSame(
				self::MANUAL_PREFIX . $actor,
				EnquiryStore::find( $id )['source'],
				'Every enquiry in the sequence is a manual one. ' . $label
			);
		}

		// Requirement 18.12, the rate limiter half: `hits()` reads the counter
		// without touching it, so a manual request that had consulted the limiter
		// would have left the count above the two the interleaved webhook run is
		// answerable for.
		$this->assertSame(
			self::WEBHOOK_STEPS,
			DuplicateDetector::hits( $email ),
			'Only the interleaved webhook requests are counted against the address. ' . $label
		);

		// Requirement 18.15: the only rejection rows are the interleaved webhook
		// refusals — the duplicate and the rate-limited one.
		$this->assertSame( 2, $refused, 'Exactly two interleaved webhook requests are refused. ' . $label );
		$this->assertSame(
			2,
			$this->row_counts()['rejections'],
			'No manual request writes a rejected intake attempt. ' . $label
		);
	}

	/**
	 * Dispatch one manual creation request from the sequence and assert it was
	 * created, unguarded, and wrote no rejection row.
	 *
	 * The duplicate detector is asked here as a witness rather than as a
	 * collaborator: from the second request onward it reports a match, so a route
	 * that consulted it would have refused this request.
	 *
	 * @param array  $fields  Submitted field map.
	 * @param int    $refused Webhook refusals so far, each of which wrote a row.
	 * @param int    $index   Position in the sequence.
	 * @param string $label   Failure context.
	 * @return int The created enquiry identifier.
	 */
	private function create_manually( array $fields, $refused, $index, $label ) {
		$context = sprintf( ' [manual request %d] %s', (int) $index + 1, $label );

		if ( $index > 0 ) {
			$this->assertGreaterThan(
				0,
				DuplicateDetector::find_duplicate( (string) $fields['email'], (array) $fields['date_ranges'] ),
				'The duplicate detector reports a match at this point, so a route consulting it would refuse.' . $context
			);
		}

		$response = $this->dispatch_manual( $fields );
		$data     = (array) $response->get_data();

		$this->assertSame( 201, $response->get_status(), 'A repeated manual submission is created.' . $context . ' ' . wp_json_encode( $data ) );

		$this->assertSame(
			(int) $refused,
			$this->row_counts()['rejections'],
			'A manual request writes no rejected intake attempt.' . $context
		);

		return isset( $data['id'] ) ? (int) $data['id'] : 0;
	}

	/**
	 * Submit one interleaved intake webhook request and assert it is guarded
	 * exactly as Properties 14 and 15 describe.
	 *
	 * The first carries the manual sequence's own email and range set, so it is a
	 * duplicate. The rest carry range sets far outside the generated span and
	 * distinct from one another, so none of them is, and the sixth is refused for
	 * bringing the per-address count up to the limit — the sixth webhook, which is
	 * only where the limit lands if the manual requests never counted.
	 *
	 * @param array  $fields Submitted field map.
	 * @param int    $index  Webhook position, from 0.
	 * @param string $label  Failure context.
	 * @return bool Whether the submission created an enquiry.
	 */
	private function submit_webhook( array $fields, $index, $label ) {
		$context = sprintf( ' [webhook request %d] %s', (int) $index + 1, $label );

		if ( $index > 0 ) {
			$day = Generators::date_at( 1000 + ( 20 * (int) $index ) );

			$fields['date_ranges'] = array(
				array(
					'start' => $day,
					'end'   => $day,
				),
			);
		}

		$outcome = IntakeHandler::receive( $fields, self::WEBHOOK_SOURCE, Clock::mysql() );

		if ( 0 === (int) $index ) {
			$this->assertFalse( (bool) $outcome['created'], 'A duplicate webhook submission is refused.' . $context );
			$this->assertSame( IntakeHandler::REASON_DUPLICATE, (string) $outcome['reason'], 'It is refused as a duplicate.' . $context );

			return false;
		}

		if ( self::WEBHOOK_STEPS - 1 === (int) $index ) {
			$this->assertFalse( (bool) $outcome['created'], 'The sixth webhook submission is refused.' . $context );
			$this->assertSame( IntakeHandler::REASON_RATE_LIMITED, (string) $outcome['reason'], 'It is refused as rate limited.' . $context );

			return false;
		}

		$this->assertTrue(
			(bool) $outcome['created'],
			'A webhook submission inside the limit is created: ' . wp_json_encode( $outcome ) . $context
		);

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Dispatch
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch one manual creation request through the REST server.
	 *
	 * @param array $fields Body to post.
	 * @return mixed WP_REST_Response.
	 */
	private function dispatch_manual( array $fields ) {
		$request = new WP_REST_Request( 'POST', '/' . RestEnquiries::NAMESPACE . RestEnquiries::ROUTE_COLLECTION );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $fields ) );

		return rest_do_request( $request );
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One creation case: the submitted values, how each optional field arrives,
	 * the receipt instant, the site timezone, the environment mode, the linkage
	 * outcome, the submitting user and the configured membership.
	 *
	 * @return \Eris\Generator
	 */
	protected static function creation_scenario() {
		return \Eris\Generators::associative(
			array(
				'fields'      => self::submittable_enquiry(),
				'treatments'  => self::treatments(),
				'received'    => \Eris\Generators::choose( self::WINDOW_START, self::WINDOW_END ),
				'timezone'    => \Eris\Generators::elements( self::ZONES ),
				'staging'     => \Eris\Generators::elements( array( true, false ) ),
				'crm'         => \Eris\Generators::elements( self::CRM_OUTCOMES ),
				'user'        => \Eris\Generators::choose( 0, 2 ),
				'email_shape' => \Eris\Generators::elements( self::EMAIL_SHAPES ),
				'list_id'     => \Eris\Generators::choose( 1, 499 ),
				'tag_id'      => \Eris\Generators::choose( 500, 999 ),
			)
		);
	}

	/**
	 * One sequence case: the submitted values every request in the run carries,
	 * how many manual requests there are, and the gaps between arrivals.
	 *
	 * Every field is present, so the run is valid under the Webhook profile too
	 * and the interleaved webhook submissions reach the guards rather than the
	 * validator. Gaps are short enough that the whole run — at most eleven
	 * requests — stays well inside both the 900 second duplicate window and the
	 * 900 second rate-limit period, which is the window the claim is about.
	 *
	 * @return \Eris\Generator
	 */
	protected static function sequence_scenario() {
		return \Eris\Generators::associative(
			array(
				'fields'      => self::submittable_enquiry(),
				'treatments'  => \Eris\Generators::constant( array() ),
				'length'      => \Eris\Generators::choose( 2, 7 ),
				'gaps'        => \Eris\Generators::vector( 4, \Eris\Generators::choose( 0, 60 ) ),
				'received'    => \Eris\Generators::choose( self::WINDOW_START, self::WINDOW_END ),
				'timezone'    => \Eris\Generators::elements( self::ZONES ),
				'staging'     => \Eris\Generators::elements( array( true, false ) ),
				'crm'         => \Eris\Generators::constant( 'ok' ),
				'user'        => \Eris\Generators::choose( 0, 2 ),
				'email_shape' => \Eris\Generators::elements( self::EMAIL_SHAPES ),
				'list_id'     => \Eris\Generators::choose( 1, 499 ),
				'tag_id'      => \Eris\Generators::choose( 500, 999 ),
			)
		);
	}

	/**
	 * A nine-field submission whose every value the route's declared sanitisation
	 * is the identity on.
	 *
	 * `total_guests` is a numeric string because the route declares
	 * `sanitize_text_field` on it, so that is the shape the payload snapshot has
	 * to hold; the rest are drawn at plain, unicode, quoted, adversarial and
	 * exactly-at-capacity shapes, none of which carries markup, repeated or
	 * surrounding whitespace, or a percent-encoded sequence.
	 *
	 * @return \Eris\Generator
	 */
	protected static function submittable_enquiry() {
		return \Eris\Generators::associative(
			array(
				'first_name'       => self::safe_name( 'first_name' ),
				'last_name'        => self::safe_name( 'last_name' ),
				'email'            => Generators::email(),
				'phone'            => Generators::phone(),
				'total_guests'     => \Eris\Generators::map(
					function ( $count ) {
						return (string) (int) $count;
					},
					Generators::total_guests()
				),
				'message'          => self::safe_message(),
				'date_ranges'      => Generators::candidate_ranges(),
				'event_type'       => Generators::allowed_term_set( 'event_type', 1 ),
				'site_exclusivity' => Generators::allowed_term_set( 'site_exclusivity', 1 ),
			)
		);
	}

	/**
	 * How each of the five optional fields arrives.
	 *
	 * @return \Eris\Generator
	 */
	protected static function treatments() {
		$spec = array();

		foreach ( self::OPTIONAL_FIELDS as $field ) {
			$spec[ $field ] = \Eris\Generators::elements( self::TREATMENTS );
		}

		return \Eris\Generators::associative( $spec );
	}

	/**
	 * A name-shaped value sanitisation leaves alone.
	 *
	 * @param string $field Field name from Generators::LIMITS.
	 * @return \Eris\Generator
	 */
	protected static function safe_name( $field ) {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( 'Ada' ),
			\Eris\Generators::constant( "O'Brien" ),
			\Eris\Generators::constant( 'Zoë Ó Séaghdha' ),
			\Eris\Generators::constant( Generators::capacity_value( $field ) ),
			\Eris\Generators::constant( Generators::capacity_value( $field, true ) ),
			\Eris\Generators::elements( self::SAFE_TOKENS )
		);
	}

	/**
	 * A `message` value sanitisation leaves alone, including one at exactly the
	 * stored capacity.
	 *
	 * @return \Eris\Generator
	 */
	protected static function safe_message() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::map(
				function ( $guests ) {
					return 'Looking at a summer weekend for around ' . (int) $guests . ' guests.';
				},
				\Eris\Generators::choose( 2, 400 )
			),
			\Eris\Generators::constant( Generators::capacity_value( 'message' ) ),
			\Eris\Generators::elements( self::SAFE_TOKENS )
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step grows exponentially
	 * with the number of generated values. A case here draws a nine-field
	 * enquiry, two of whose fields are sets, plus ten further choices, which puts
	 * that product beyond what fits in memory: a failing iteration would report an
	 * out-of-memory fatal instead of the counterexample.
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
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Put the site, the store and the CRM fake into the state one case names.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function prepare( array $case ) {
		$this->clear();

		$this->crm->reset();

		switch ( (string) $case['crm'] ) {
			case 'throws':
				$this->crm->will_throw();
				break;
			case 'no_id':
				$this->crm->will_return_no_subscriber_id();
				break;
			case 'unavailable':
				$this->crm->will_be_unavailable();
				break;
			default:
				$this->crm->will_succeed();
				break;
		}

		update_option( 'meh_enquiry_list', (int) $case['list_id'] );
		update_option( 'meh_enquiry_tag', (int) $case['tag_id'] );

		// The bootstrap helper ends on this filter, so switching it is switching
		// the environment as far as every reader of `StagingMarker` is concerned.
		remove_filter( 'meh_is_staging', '__return_true' );

		if ( $case['staging'] ) {
			add_filter( 'meh_is_staging', '__return_true' );
		}

		$this->assertSame(
			(bool) $case['staging'],
			StagingMarker::is_staging(),
			'The environment mode should be the drawn one.'
		);

		update_option( 'timezone_string', (string) $case['timezone'] );

		$this->assertTrue(
			Clock::freeze( (int) $case['received'] ),
			'The clock should freeze under the test harness.'
		);
	}

	/**
	 * The field map one case submits: the drawn values, with an address unique to
	 * this iteration, and each optional field present, omitted or empty.
	 *
	 * A fresh address per iteration means a fresh contact, so the linkage observed
	 * is the one this iteration wrote, and it keeps the interleaved webhook
	 * guards in the sequence claim measuring this run rather than the last.
	 *
	 * @param array $case Generated case.
	 * @return array
	 */
	private function submission( array $case ) {
		++$this->iteration;

		$fields          = (array) $case['fields'];
		$fields['email'] = self::unique_email( (string) $case['email_shape'], $this->iteration );

		$this->counted[] = $fields['email'];

		foreach ( (array) $case['treatments'] as $field => $treatment ) {
			if ( 'present' === (string) $treatment ) {
				continue;
			}

			if ( 'omitted' === (string) $treatment ) {
				unset( $fields[ $field ] );

				continue;
			}

			$fields[ $field ] = in_array( $field, array( 'event_type', 'site_exclusivity' ), true )
				? array()
				: '';
		}

		return $fields;
	}

	/**
	 * A submittable address of a given shape, unique to one iteration.
	 *
	 * The capacity shape lands at exactly the stored 254-character limit, with the
	 * length spent on extra domain labels rather than on a longer local part so no
	 * RFC ceiling is crossed on the way there.
	 *
	 * @param string $shape     One of self::EMAIL_SHAPES.
	 * @param int    $iteration Iteration number.
	 * @return string
	 */
	private static function unique_email( $shape, $iteration ) {
		if ( 'quoted' === $shape ) {
			return sprintf( "o'brien+%d@example.com", (int) $iteration );
		}

		if ( 'capacity' !== $shape ) {
			return sprintf( 'enquirer+%d@example.com', (int) $iteration );
		}

		$local = 'ada' . (int) $iteration;
		$tld   = '.com';
		$body  = '';

		// What is left for the domain, minus the '@' and the trailing '.com'.
		$body_length = Generators::LIMITS['email'] - strlen( $local ) - 1 - strlen( $tld );

		// Leave at least one character for a final label, so the body never ends
		// on the '.' that precedes the TLD.
		while ( strlen( $body ) + 4 <= $body_length - 1 ) {
			$body .= 'sub.';
		}

		$body .= str_repeat( 'x', $body_length - strlen( $body ) );

		return $local . '@' . $body . $tld;
	}

	/* ---------------------------------------------------------------------
	 * Expectations
	 * ------------------------------------------------------------------ */

	/**
	 * A name as the environment should leave it on the contact.
	 *
	 * The prefix comes from the `MEH_TEST_PREFIX` constant the bootstrap defines
	 * rather than from `StagingMarker::prefix()`: an expectation taken from the
	 * code under test could not catch that code applying the wrong prefix, or
	 * none.
	 *
	 * @param string $name    Name as stored.
	 * @param bool   $staging Whether staging mode was reported.
	 * @return string
	 */
	private static function marked( $name, $staging ) {
		$name = trim( (string) $name );

		if ( ! $staging || '' === $name ) {
			return $name;
		}

		$prefix = defined( 'MEH_TEST_PREFIX' ) ? (string) MEH_TEST_PREFIX : 'TEST_';

		return $prefix . $name;
	}

	/**
	 * A Unix instant as a MySQL `DATETIME` in a given zone.
	 *
	 * Computed here rather than through `Clock`, so the expectation does not come
	 * from the code the property is about.
	 *
	 * @param int    $timestamp Unix instant.
	 * @param string $zone      Timezone identifier.
	 * @return string
	 */
	private static function wall_clock( $timestamp, $zone ) {
		$instant = new \DateTimeImmutable( '@' . (int) $timestamp );

		return $instant->setTimezone( new \DateTimeZone( (string) $zone ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A list of values as a comparable set: strings, de-duplicated and sorted.
	 *
	 * @param mixed $values List of values.
	 * @return string[]
	 */
	private static function as_set( $values ) {
		$set = array();

		foreach ( (array) $values as $value ) {
			$set[] = (string) $value;
		}

		$set = array_values( array_unique( $set ) );
		sort( $set );

		return $set;
	}

	/**
	 * A one-line description of a creation case, for failure messages.
	 *
	 * @param array  $case     Generated case.
	 * @param string $expected Expected site-local receipt time.
	 * @return string
	 */
	private static function creation_label( array $case, $expected ) {
		$treatments = array();

		foreach ( (array) $case['treatments'] as $field => $treatment ) {
			$treatments[] = $field . '=' . $treatment;
		}

		return sprintf(
			'[%s in %s, %s mode, crm %s, %s address, optionals %s]',
			$expected,
			(string) $case['timezone'],
			$case['staging'] ? 'staging' : 'production',
			(string) $case['crm'],
			(string) $case['email_shape'],
			implode( ' ', $treatments )
		);
	}

	/**
	 * A one-line description of a sequence case, for failure messages.
	 *
	 * @param array $case Generated case.
	 * @return string
	 */
	private static function sequence_label( array $case ) {
		return sprintf(
			'[%d manual requests, gaps %s, %s, %s mode, %s address]',
			(int) $case['length'],
			implode( '/', array_map( 'intval', (array) $case['gaps'] ) ),
			(string) $case['timezone'],
			$case['staging'] ? 'staging' : 'production',
			(string) $case['email_shape']
		);
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
	 * Empty every Enquiry Store table, between iterations.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
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
