<?php
/**
 * Property 6: Intake outcome for a valid submission.
 *
 * Feature: enquiry-data-layer, Property 6: For any valid intake webhook request
 * that authenticates against the intake secret, any receipt time, any site
 * timezone, and whether or not a WordPress user is authenticated, intake creates
 * exactly one enquiry whose stored field values equal the submitted values,
 * whose status is `new`, whose `created_at` and `updated_at` equal the receipt
 * time in the site timezone, whose `source` holds the form identifier carried in
 * the request payload — or exactly `webhook:unidentified` when the payload
 * carries no form identifier — whose candidate dates and whose `event_type` and
 * `site_exclusivity` values each equal the submitted values as a set, whose
 * payload snapshot contains every submitted field value, and against which
 * exactly one history entry of type `created` exists carrying the system
 * attribution.
 *
 * **Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.13, 16.8**
 *
 * How the property is instantiated, and why:
 *
 * - **Every submission travels the real route.** Each iteration builds a
 *   `WP_REST_Request` and dispatches it with `rest_do_request()`, so the secret
 *   is authenticated where WordPress authenticates it and the enquiry is created
 *   by the code path a website form actually reaches. Calling
 *   `IntakeHandler::receive()` directly would skip the endpoint's own share of
 *   the property: the receipt time it reads and the `source` it resolves.
 * - **No WordPress user is authenticated for half the run** (Requirement 16.8).
 *   `wp_set_current_user( 0 )` is the case a server-to-server webhook presents,
 *   and it is the one that has to work: nothing about the outcome may depend on
 *   a user being logged in. The other half runs with an editor authenticated, so
 *   the system attribution on the `created` entry is asserted to be 0 because
 *   intake attributes to the system, not because no user happened to be there.
 * - **The receipt time is drawn as a Unix instant and the site timezone
 *   independently of it**, then the expected `created_at` is computed with plain
 *   PHP date handling rather than by asking `Clock` what it thinks. A timezone
 *   set of seven zones covers a whole-hour offset, a half-hour offset
 *   (`Asia/Kolkata`), a three-quarter-hour offset (`Pacific/Chatham`) and both
 *   northern and southern daylight saving, and instants are drawn across a whole
 *   year so both sides of each transition are reached.
 * - **`source` is quantified over the three shapes it has** (Requirements 2.3,
 *   2.4): the configured payload field carries an identifier; the field is
 *   configured but the payload carries no such key; and no field is configured
 *   at all. The last case leaves the identifier in the payload deliberately —
 *   Settings names the field that supplies `source` (Requirement 2.9), so a
 *   payload value nothing points at is not a form identifier and must yield
 *   exactly `webhook:unidentified`.
 * - **The submitted values are ones sanitisation leaves alone**, drawn from
 *   `Generators::enquiry()`: unicode, quotes, the adversarial token set and
 *   values at exactly their stored capacity, none of which carries markup or
 *   surrounding whitespace. That is what lets "stored equals submitted" be
 *   asserted against the submission itself rather than against what the
 *   Validator says it made of it — an oracle taken from the code under test
 *   could not catch that code mangling a value.
 * - **`meh_field_map` is unset throughout**, so every field is resolved by the
 *   field name itself and no part of the outcome depends on configuration
 *   specific to a sender.
 * - **Each iteration submits an address unique to it**, so neither the duplicate
 *   guard nor the rate limiter can turn a valid submission away for a reason
 *   this property is not about and leave it asserting nothing. The address is
 *   still drawn in three shapes, one of them at exactly the stored 254-character
 *   capacity, so uniqueness costs no coverage of the values that get stored.
 * - **"Exactly one enquiry" is asserted as a row count taken before and after**,
 *   together with no new rejected intake attempt: a valid submission that also
 *   wrote a rejection row, or wrote two enquiries, would satisfy a bare
 *   "the enquiry exists" assertion.
 *
 * The empty-value and zero-row cases of Requirements 1.3 and 1.19 are absent by
 * construction, as the design's note on this property says: the Webhook profile
 * requires all nine fields, so no empty scalar and no empty multi-select can
 * reach the store down this path.
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
use MarthrownEnquiryHub\IntakeHandler;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class ValidSubmissionIntakePropertyTest
 */
class ValidSubmissionIntakePropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table or with another test's fixture in the same database.
	 */
	const PREFIX_SEGMENT = 'mehvsi_';

	/**
	 * The Intake Secret held in Settings for the length of the run.
	 */
	const SECRET = 'sk-7f2c91ae4b6d05e83a1c';

	/**
	 * Site timezones the property is quantified over.
	 *
	 * A whole-hour offset with northern daylight saving, one with southern
	 * daylight saving, a half-hour offset and a three-quarter-hour offset, so a
	 * timestamp-to-wall-clock conversion that only ever divides by 3600 fails
	 * here rather than in production.
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

	/**
	 * First instant a receipt time may be drawn at: 2025-01-01 00:00:00 UTC.
	 */
	const WINDOW_START = 1735689600;

	/**
	 * Last instant a receipt time may be drawn at: 2025-12-31 23:59:59 UTC.
	 */
	const WINDOW_END = 1767225599;

	/**
	 * The three shapes `source` resolution has (Requirements 2.3, 2.4, 2.9).
	 *
	 * @var string[]
	 */
	const SOURCE_MODES = array( 'carried', 'field_absent', 'unconfigured' );

	/**
	 * Payload keys a site may name as the one holding the form identifier.
	 *
	 * None of them canonicalises to an enquiry field name, so none can be
	 * mistaken for a submitted field.
	 *
	 * @var string[]
	 */
	const SOURCE_FIELDS = array( 'form_id', 'formId', 'kadence_form', 'Source Form' );

	/**
	 * Form identifiers a sender may carry.
	 *
	 * Every one is non-empty, carries no markup and holds no repeated
	 * whitespace, so `webhook:{identifier}` is the whole of the expected value.
	 *
	 * @var string[]
	 */
	const FORM_IDS = array(
		'kadence-enquiry',
		'Contact Form 7',
		'wpforms:3',
		'enquiry form',
		'elementor_42',
	);

	/**
	 * Address shapes an iteration may submit.
	 *
	 * @var string[]
	 */
	const EMAIL_SHAPES = array( 'plain', 'quoted', 'capacity' );

	/**
	 * Whether a WordPress user is authenticated (Requirement 16.8).
	 *
	 * @var string[]
	 */
	const CALLERS = array( 'anonymous', 'authenticated' );

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
	 * An authenticated user, for the half of the run that presents one.
	 *
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * The FluentCRM fake, so the linkage leg of creation behaves as it does on a
	 * site that has FluentCRM active.
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
		require_once MEH_INCLUDES_DIR . 'class-enquiry-validator.php';
		require_once MEH_INCLUDES_DIR . 'class-field-mapper.php';
		require_once MEH_INCLUDES_DIR . 'class-duplicate-detector.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-handler.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-endpoint.php';
	}

	public function set_up() {
		parent::set_up();

		global $wpdb, $wp_rest_server;

		// Created before the prefix is switched, so it lands in the real users
		// table rather than being affected by the fixture prefix.
		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'editor' ) );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		$this->original_timezone = (string) get_option( 'timezone_string', '' );

		update_option( IntakeEndpoint::SECRET_OPTION, self::SECRET );

		// Requirement 2.12: nothing here is configured for a particular sender,
		// so every field is resolved by its own name.
		delete_option( FieldMapper::OPTION );

		// The vocabularies the Validator checks a submitted multi-select
		// against, so every generated `event_type`/`site_exclusivity` value is
		// one the intake route accepts.
		foreach ( array_keys( Generators::VOCABULARIES ) as $taxonomy ) {
			add_filter(
				'meh_enquiry_terms_' . $taxonomy,
				static function () use ( $taxonomy ) {
					return Generators::vocabulary( $taxonomy );
				}
			);
		}

		$this->crm = FakeCrm::install();

		// A server of this test's own, so the route table holds what this test
		// registered and nothing a previous test left behind.
		$this->original_server = $wp_rest_server;
		$wp_rest_server        = new WP_REST_Server();

		IntakeEndpoint::init();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		foreach ( $this->counted as $email ) {
			DuplicateDetector::reset( $email );
		}

		$this->counted = array();

		wp_set_current_user( 0 );

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( IntakeEndpoint::SECRET_OPTION );
		delete_option( IntakeEndpoint::SOURCE_FIELD_OPTION );
		update_option( 'timezone_string', $this->original_timezone );

		$wp_rest_server = $this->original_server;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 6: Intake outcome for a valid
	 * submission.
	 *
	 * **Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.13, 16.8**
	 */
	public function test_intake_outcome_for_a_valid_submission() {
		$this->limitTo( Iterations::count( 100 ) )
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
	 * One valid submission, dispatched and judged.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check( array $case ) {
		$submitted = $this->submission( (array) $case['payload'], (string) $case['email_shape'] );
		$zone      = (string) $case['timezone'];
		$received  = (int) $case['received'];
		$expected  = self::wall_clock( $received, $zone );
		$label     = self::label( $case, $expected );

		update_option( 'timezone_string', $zone );
		Clock::freeze( $received );

		$payload = $this->configure_source( $submitted, $case );
		$source  = self::expected_source( $case );

		wp_set_current_user( 'authenticated' === (string) $case['caller'] ? $this->user_id : 0 );

		$before = $this->row_counts();

		$response = $this->dispatch( $payload );
		$data     = (array) $response->get_data();

		// Requirement 2.1, and Requirement 16.8: a caller with no WordPress user
		// creates an enquiry exactly as an authenticated one does.
		$this->assertSame( 201, $response->get_status(), 'A valid submission is accepted. ' . $label );
		$this->assertTrue( ! empty( $data['created'] ), 'A valid submission creates an enquiry. ' . $label );

		$after = $this->row_counts();

		$this->assertSame(
			$before['enquiries'] + 1,
			$after['enquiries'],
			'Exactly one enquiry is created. ' . $label
		);
		$this->assertSame(
			$before['rejections'],
			$after['rejections'],
			'A valid submission records no rejected intake attempt. ' . $label
		);

		$enquiry = EnquiryStore::find( (int) $data['enquiry_id'] );

		$this->assertIsArray( $enquiry, 'The created enquiry is readable. ' . $label );

		$this->assert_stored_values( $enquiry, $submitted, $label );
		$this->assert_sets( $enquiry, $submitted, $label );
		$this->assert_snapshot( $enquiry, $submitted, $label );

		// Requirement 2.1.
		$this->assertSame( 'new', $enquiry['status'], 'A created enquiry holds status new. ' . $label );

		// Requirement 2.2.
		$this->assertSame( $expected, $enquiry['created_at'], 'created_at is the receipt time, site-local. ' . $label );
		$this->assertSame( $expected, $enquiry['updated_at'], 'updated_at is the receipt time, site-local. ' . $label );

		// Requirements 2.3, 2.4.
		$this->assertSame( $source, $enquiry['source'], 'source holds the resolved form identifier. ' . $label );

		$this->assert_created_history( (int) $enquiry['id'], $label );
	}

	/**
	 * Every stored scalar equals the value that was submitted (Requirement 2.1).
	 *
	 * @param array  $enquiry   Stored enquiry.
	 * @param array  $submitted Submitted field map.
	 * @param string $label     Failure context.
	 * @return void
	 */
	private function assert_stored_values( array $enquiry, array $submitted, $label ) {
		foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'message' ) as $field ) {
			$this->assertSame(
				(string) $submitted[ $field ],
				$enquiry[ $field ],
				sprintf( 'Stored %s equals the submitted value. %s', $field, $label )
			);
		}

		$this->assertSame(
			(int) $submitted['total_guests'],
			$enquiry['total_guests'],
			'Stored total_guests equals the submitted value. ' . $label
		);
	}

	/**
	 * The candidate dates and both multi-select fields equal the submitted values
	 * as sets (Requirements 2.5, 2.1).
	 *
	 * @param array  $enquiry   Stored enquiry.
	 * @param array  $submitted Submitted field map.
	 * @param string $label     Failure context.
	 * @return void
	 */
	private function assert_sets( array $enquiry, array $submitted, $label ) {
		foreach ( array( 'selected_dates', 'event_type', 'site_exclusivity' ) as $field ) {
			$this->assertSame(
				self::as_set( $submitted[ $field ] ),
				self::as_set( $enquiry[ $field ] ),
				sprintf( 'Stored %s equals the submitted values as a set. %s', $field, $label )
			);
		}
	}

	/**
	 * The payload snapshot carries every submitted field value (Requirement 2.6).
	 *
	 * @param array  $enquiry   Stored enquiry.
	 * @param array  $submitted Submitted field map.
	 * @param string $label     Failure context.
	 * @return void
	 */
	private function assert_snapshot( array $enquiry, array $submitted, $label ) {
		$snapshot = (array) $enquiry['payload'];

		foreach ( FieldMapper::FIELDS as $field ) {
			$this->assertArrayHasKey(
				$field,
				$snapshot,
				sprintf( 'The payload snapshot carries %s. %s', $field, $label )
			);
			$this->assertSame(
				$submitted[ $field ],
				$snapshot[ $field ],
				sprintf( 'The snapshot holds the submitted %s value. %s', $field, $label )
			);
		}
	}

	/**
	 * Exactly one `created` entry exists, attributed to the system
	 * (Requirement 2.13).
	 *
	 * @param int    $enquiry_id Created enquiry.
	 * @param string $label      Failure context.
	 * @return void
	 */
	private function assert_created_history( $enquiry_id, $label ) {
		$created = array();

		foreach ( HistoryRecorder::for_enquiry( $enquiry_id ) as $entry ) {
			if ( IntakeHandler::HISTORY_TYPE === $entry['entry_type'] ) {
				$created[] = $entry;
			}
		}

		$this->assertCount( 1, $created, 'Exactly one created history entry exists. ' . $label );
		$this->assertSame(
			HistoryRecorder::SYSTEM_ACTOR,
			(int) $created[0]['actor_id'],
			'The created entry carries the system attribution. ' . $label
		);
	}

	/* ---------------------------------------------------------------------
	 * Dispatch
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch one authenticated intake request through the REST server.
	 *
	 * @param array $payload Body to post.
	 * @return mixed WP_REST_Response.
	 */
	private function dispatch( array $payload ) {
		$request = new WP_REST_Request( 'POST', '/' . IntakeEndpoint::NAMESPACE . IntakeEndpoint::ROUTE );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( IntakeEndpoint::SECRET_HEADER, self::SECRET );
		$request->set_body( (string) wp_json_encode( $payload ) );

		return rest_do_request( $request );
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: the submission, the receipt instant, the site timezone, how
	 * `source` is configured and carried, the address shape, and whether a
	 * WordPress user is authenticated.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'payload'      => Generators::enquiry(),
				'received'     => \Eris\Generators::choose( self::WINDOW_START, self::WINDOW_END ),
				'timezone'     => \Eris\Generators::elements( self::ZONES ),
				'source_mode'  => \Eris\Generators::elements( self::SOURCE_MODES ),
				'source_field' => \Eris\Generators::elements( self::SOURCE_FIELDS ),
				'form_id'      => \Eris\Generators::elements( self::FORM_IDS ),
				'email_shape'  => \Eris\Generators::elements( self::EMAIL_SHAPES ),
				'caller'       => \Eris\Generators::elements( self::CALLERS ),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step grows
	 * exponentially with the number of generated values. A case here draws a
	 * nine-field enquiry, two of whose fields are sets, plus six further
	 * choices, which puts that product beyond what fits in memory: a failing
	 * iteration would report an out-of-memory fatal instead of the
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
	 * The nine submitted field values, with an address unique to this iteration.
	 *
	 * Uniqueness keeps the duplicate guard and the rate limiter out of the way,
	 * so a valid submission is never turned away for a reason this property is
	 * not about.
	 *
	 * @param array  $fields Generated field map.
	 * @param string $shape  Address shape to submit.
	 * @return array
	 */
	private function submission( array $fields, $shape ) {
		++$this->iteration;

		$fields['email'] = self::unique_email( $shape, $this->iteration );

		$this->counted[] = $fields['email'];

		return $fields;
	}

	/**
	 * A submittable address of a given shape, unique to one iteration.
	 *
	 * The capacity shape lands at exactly the stored 254-character limit, with
	 * the length spent on extra domain labels rather than on a longer local part
	 * so no RFC ceiling is crossed on the way there — the same construction
	 * `Generators::email_of_length()` uses, with the iteration counter carried in
	 * the local part.
	 *
	 * @param string $shape     One of self::EMAIL_SHAPES.
	 * @param int    $iteration Iteration number.
	 * @return string
	 */
	private static function unique_email( $shape, $iteration ) {
		if ( 'quoted' === $shape ) {
			return sprintf( "o'brien+%d@example.com", $iteration );
		}

		if ( 'capacity' !== $shape ) {
			return sprintf( 'enquirer+%d@example.com', $iteration );
		}

		$local = 'ada' . $iteration;
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

	/**
	 * Configure Settings for this case's `source` shape, and return the body to
	 * post.
	 *
	 * @param array $submitted Submitted field map.
	 * @param array $case      Generated case.
	 * @return array
	 */
	private function configure_source( array $submitted, array $case ) {
		$mode  = (string) $case['source_mode'];
		$field = (string) $case['source_field'];

		if ( 'unconfigured' === $mode ) {
			delete_option( IntakeEndpoint::SOURCE_FIELD_OPTION );

			// The identifier is carried but nothing points at it, so it is not a
			// form identifier (Requirements 2.4, 2.9).
			$submitted[ $field ] = (string) $case['form_id'];

			return $submitted;
		}

		update_option( IntakeEndpoint::SOURCE_FIELD_OPTION, $field );

		if ( 'carried' === $mode ) {
			$submitted[ $field ] = (string) $case['form_id'];
		}

		return $submitted;
	}

	/**
	 * The `source` this case must produce (Requirements 2.3, 2.4).
	 *
	 * @param array $case Generated case.
	 * @return string
	 */
	private static function expected_source( array $case ) {
		if ( 'carried' !== (string) $case['source_mode'] ) {
			return IntakeHandler::SOURCE_UNIDENTIFIED;
		}

		return IntakeEndpoint::SOURCE_PREFIX . (string) $case['form_id'];
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
	 * A one-line description of the case, for failure messages.
	 *
	 * @param array  $case     Generated case.
	 * @param string $expected Expected site-local receipt time.
	 * @return string
	 */
	private static function label( array $case, $expected ) {
		return sprintf(
			'[%s in %s, source %s via %s, %s address, %s caller, expected %s]',
			$expected,
			(string) $case['timezone'],
			(string) $case['source_mode'],
			(string) $case['source_field'],
			(string) $case['email_shape'],
			(string) $case['caller'],
			self::expected_source( $case )
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
