<?php
/**
 * Property 41: A rejected edit writes nothing at all.
 *
 * Feature: enquiry-data-layer, Property 41: For any enquiry and any enquiry edit
 * request carrying at least one value that fails a Requirement 3 criterion naming
 * its field under the Manual Validation Profile in partial mode — including
 * `first_name`, `last_name`, `email` or `selected_dates` present and submitted
 * empty, which fails, as distinct from those fields being absent, which does not
 * — alongside any number of valid values, the response is 400 naming every field
 * that fails validation, and the enquiry's stored field values, candidate dates,
 * terms, notes, history and `updated_at` are byte-identical afterwards: no valid
 * value from that request is applied. For any enquiry holding status `closed` and
 * any edit request body, valid or invalid, the response is 409 and the same
 * stored state is byte-identical, the closed guard being evaluated before the
 * request body is validated.
 *
 * **Validates: Requirements 19.4, 19.5, 19.12**
 *
 * How the property is instantiated, and why:
 *
 * - **Both halves are quantified in one property**, because the two are the same
 *   claim about the same request: nothing is written. The generated case draws
 *   whether the enquiry is closed; a closed one earns 409 whatever the body says,
 *   an open one earns 400 for a body carrying at least one failing value, and the
 *   before/after comparison that follows is identical in both branches.
 * - **Every request goes through the real route.** `rest_do_request()` dispatches
 *   a `PATCH /enquiries/{id}` on a REST server this test registered, so the
 *   permission callback, the declared args and their `sanitize_callback`s all run
 *   where WordPress runs them. That matters for this property: the route's
 *   `sanitize_text_field` turns a whitespace-only submission into an empty
 *   string, and `total_guests` is deliberately *not* coerced to an integer, so a
 *   value rule that only fires on the value as typed is exercised here as it is
 *   in production. Calling `EnquiryEditor::apply()` directly would test a body no
 *   client can actually send.
 * - **Failure is generated per field, in every mode that field can fail.** For
 *   `first_name` and `last_name` the only way to fail under the Manual profile is
 *   to submit them empty, so those two draw an empty string or whitespace; `email`
 *   adds a malformed address; `phone` fails only by carrying no digit; `total_guests`
 *   fails out of range and as a non-whole number, which are separate codes;
 *   `selected_dates` fails empty, over ten entries, and on an entry naming no
 *   calendar date; and each taxonomy fails on a value outside its vocabulary.
 *   `message` is absent from the breakable set on purpose — under the Manual
 *   profile it is optional and carries no value rule, so no submitted `message`
 *   can be rejected (Requirement 3.18), and pretending otherwise would be
 *   generating a case the requirements say cannot exist.
 * - **The expected error code is stated per failure mode in this file**, rather
 *   than read back from the Validator, so a rule that silently changed which
 *   answer a failing value earns — `out_of_range` collapsing into
 *   `not_whole_number`, a value failure masking a presence failure — is caught
 *   rather than agreed with.
 * - **Every failing field is asserted, not just the first** (Requirement 19.5):
 *   the response's `errors` map is compared as a whole, same field names and the
 *   same code for each, so a validator returning on its first failure fails here.
 * - **Valid values ride along on the same request**, drawn as any subset of the
 *   fields the case did not break, and every one of them is chosen to differ from
 *   what the fixture stores: the fixture's scalars, dates and terms all sit
 *   outside what the valid generators can emit. That is what makes "no valid value
 *   from that request is applied" a real assertion rather than a comparison of
 *   equal values (Requirement 19.4).
 * - **"Byte-identical" is asserted as a whole-row comparison**, not as a list of
 *   fields: the hydrated enquiry is read before and after, which covers
 *   `updated_at`, the candidate dates, both taxonomies and every column this test
 *   did not think to name in one assertion. Notes and history are compared
 *   verbatim, entries and all, and every Enquiry Store table's row count is
 *   compared too, so a stray insert anywhere — a rejection row, a history entry,
 *   a replaced date set — is visible.
 * - **The clock moves between seeding and the request.** The fixture is written at
 *   self::SEEDED_AT and the request is made at self::REQUEST_AT, so a write that
 *   slipped past the refusal would move `updated_at` to a value the comparison
 *   cannot miss. A single instant throughout would let a stray `UPDATE` write the
 *   value that was already there.
 * - **No FluentCRM call is asserted as well.** A refused edit produced no
 *   `changed` map, so the re-link cannot have been reached; the fake records every
 *   upsert, so the claim is checked rather than assumed.
 * - **An open enquiry is drawn across the five non-closed statuses**, since
 *   `EnquiryEditor` refuses on `closed` alone and nothing else about the status
 *   should decide whether a rejected edit writes.
 * - **The case generator is wide**, and Eris shrinks a wide composite generator by
 *   building the cartesian product of every component's alternatives, which
 *   exhausts memory before it reports anything. Every case is therefore drawn
 *   through `unshrunk()`, so a failure is reported exactly as generated, with the
 *   `ERIS_SEED` line that reproduces the run.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the temporary tables the WordPress test case
 * rewrites `CREATE TABLE` into.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\FieldMapper;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\NoteService;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class RejectedEditPropertyTest
 */
class RejectedEditPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table or with another test's fixture in the same database.
	 */
	const PREFIX_SEGMENT = 'mehre_';

	/** The instant every fixture is written at. */
	const SEEDED_AT = '2025-03-11 09:15:00';

	/**
	 * The instant every edit request is made at.
	 *
	 * Later than self::SEEDED_AT on purpose: a write that slipped past the refusal
	 * would move `updated_at` to this value (Requirement 19.4).
	 */
	const REQUEST_AT = '2025-09-24 14:05:00';

	/** The status a frozen enquiry holds (Requirement 19.12). */
	const CLOSED = 'closed';

	/**
	 * The statuses an editable enquiry may hold: everything but `closed`.
	 *
	 * @var string[]
	 */
	const OPEN_STATUSES = array( 'new', 'contacted', 'quoted', 'converted', 'lost' );

	/**
	 * The nine editable fields (Requirement 19.1).
	 *
	 * @var string[]
	 */
	const FIELDS = array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'total_guests',
		'message',
		'selected_dates',
		'event_type',
		'site_exclusivity',
	);

	/**
	 * The eight fields a submission can get wrong under the Manual profile in
	 * partial mode.
	 *
	 * `message` is not among them: it is optional, it carries no value rule, and
	 * an over-long one is truncated rather than refused (Requirements 3.16, 3.18).
	 *
	 * @var string[]
	 */
	const BREAKABLE = array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'total_guests',
		'selected_dates',
		'event_type',
		'site_exclusivity',
	);

	/**
	 * Failure modes, and the error code each one earns.
	 *
	 * Stated here rather than read from the Validator: Requirement 3 names these
	 * answers, and borrowing them from the code under test would leave the
	 * property unable to notice that code changing its mind.
	 *
	 * @var array<string,string>
	 */
	const CODES = array(
		'empty_string'  => 'empty',
		'whitespace'    => 'empty',
		'empty_list'    => 'empty',
		'blank_list'    => 'empty',
		'invalid_email' => 'invalid_email',
		'no_digits'     => 'no_digits',
		'out_of_range'  => 'out_of_range',
		'not_whole'     => 'not_whole_number',
		'too_many'      => 'too_many_dates',
		'unparseable'   => 'unparseable_date',
		'not_allowed'   => 'not_allowed',
	);

	/**
	 * The error code the route answers a rejected submission with
	 * (Requirement 19.5), restated rather than read from the controller.
	 */
	const INVALID_CODE = 'meh_invalid_enquiry';

	/**
	 * The error code the closed guard answers with (Requirement 19.12).
	 */
	const CLOSED_CODE = 'meh_enquiry_closed';

	/** Most notes one fixture holds. */
	const NOTES_MAX = 2;

	/** Most extra history entries one fixture holds. */
	const ENTRIES_MAX = 2;

	/** The `total_guests` value every fixture stores. */
	const SEEDED_GUESTS = 42;

	/** The `event_type` value every fixture stores. */
	const SEEDED_EVENT_TYPE = 'wake';

	/** The `site_exclusivity` value every fixture stores. */
	const SEEDED_EXCLUSIVITY = 'shared-use';

	/**
	 * The candidate dates every fixture stores.
	 *
	 * Before `Generators::BASE_DATE`, which every generated candidate date counts
	 * forward from, so a generated valid date set can never coincide with the
	 * stored one.
	 *
	 * @var string[]
	 */
	const SEEDED_DATES = array( '2024-03-01', '2024-03-02' );

	/**
	 * The administrator every request is made as.
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
	 * The REST server in force outside this test.
	 *
	 * @var \WP_REST_Server|null
	 */
	private $original_server = null;

	/**
	 * The FluentCRM fake, so a re-link that should not happen is recorded if it
	 * does.
	 *
	 * @var FakeCrm|null
	 */
	private $crm = null;

	/**
	 * Iteration counter, so each fixture carries values of its own.
	 *
	 * @var int
	 */
	private $iteration = 0;

	/**
	 * Load the classes under test, and create the calling user.
	 *
	 * The user is created before any prefix switch, so it lands in the real users
	 * table rather than in a fixture one.
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

		update_option( 'meh_enquiry_list', 7 );
		update_option( 'meh_enquiry_tag', 12 );

		// Nothing is configured for a particular sender, so every field resolves
		// by its own name.
		delete_option( FieldMapper::OPTION );

		// The value rules the taxonomies carry need a known universe to work
		// against (Requirement 3.12).
		add_filter( 'meh_enquiry_terms_event_type', array( __CLASS__, 'event_type_terms' ) );
		add_filter( 'meh_enquiry_terms_site_exclusivity', array( __CLASS__, 'site_exclusivity_terms' ) );

		$this->crm = FakeCrm::install();

		Clock::freeze( self::SEEDED_AT );
		wp_set_current_user( self::$user_id );

		// A server of this test's own, so the route table holds what this test
		// registered and nothing a previous test left behind.
		$this->original_server = $wp_rest_server;
		$wp_rest_server        = new WP_REST_Server();

		add_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );

		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		remove_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );

		remove_filter( 'meh_enquiry_terms_event_type', array( __CLASS__, 'event_type_terms' ) );
		remove_filter( 'meh_enquiry_terms_site_exclusivity', array( __CLASS__, 'site_exclusivity_terms' ) );

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		$wp_rest_server        = $this->original_server;
		$this->original_server = null;

		wp_set_current_user( 0 );
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
	 * Feature: enquiry-data-layer, Property 41: A rejected edit writes nothing at
	 * all.
	 *
	 * **Validates: Requirements 19.4, 19.5, 19.12**
	 */
	public function test_a_rejected_edit_writes_nothing_at_all() {
		$this->limitTo( Iterations::count( 80 ) )
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
	 * One edit request, refused, against an enquiry that must come back unchanged.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check( array $case ) {
		++$this->iteration;

		$closed   = (bool) $case['closed'];
		$broken   = self::broken( $case );
		$expected = self::expected_errors( $broken );
		$body     = $this->body( $case, $broken );
		$label    = self::label( $closed, $broken, $body );

		$id = $this->seed( $closed ? self::CLOSED : (string) $case['status'] );

		$this->seed_entries( $id, $case );

		$stored  = EnquiryStore::find( $id );
		$notes   = NoteService::for_enquiry( $id );
		$history = HistoryRecorder::for_enquiry( $id );
		$counts  = $this->row_counts();

		$this->crm->reset()->will_succeed();

		Clock::freeze( self::REQUEST_AT );

		$response = $this->dispatch_edit( $id, $body );
		$data     = (array) $response->get_data();

		if ( $closed ) {
			// Requirement 19.12: the guard runs before the body is validated, so
			// an invalid body earns 409 rather than the 400 it would otherwise.
			$this->assertSame( 409, $response->get_status(), 'A closed enquiry refuses the edit with 409. ' . $label );
			$this->assertSame(
				self::CLOSED_CODE,
				isset( $data['code'] ) ? (string) $data['code'] : '',
				'The refusal comes from the closed guard rather than from the validator. ' . $label
			);
		} else {
			// Requirements 19.4, 19.5: 400, naming every failing field.
			$this->assertSame( 400, $response->get_status(), 'An invalid edit is refused with 400. ' . $label );
			$this->assertSame(
				self::INVALID_CODE,
				isset( $data['code'] ) ? (string) $data['code'] : '',
				'The refusal names the invalid-enquiry code. ' . $label
			);
			$this->assert_names_every_failure( $expected, self::response_errors( $data ), $label );
		}

		// Requirements 19.4, 19.12: no stored field value, candidate date, term,
		// note, history entry or `updated_at` moved, and no valid value the
		// request carried was applied.
		$this->assertSame( $stored, EnquiryStore::find( $id ), 'The stored enquiry is byte-identical. ' . $label );
		$this->assertSame( $notes, NoteService::for_enquiry( $id ), 'The notes are byte-identical. ' . $label );
		$this->assertSame( $history, HistoryRecorder::for_enquiry( $id ), 'The history is byte-identical. ' . $label );
		$this->assertSame( $counts, $this->row_counts(), 'No row was written to any table. ' . $label );

		$this->assertSame(
			0,
			$this->crm->call_count( 'createOrUpdate' ),
			'A refused edit tells FluentCRM nothing. ' . $label
		);
	}

	/**
	 * The error map names exactly the expected fields, with the expected code for
	 * each, and no other field (Requirement 19.5).
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

		$this->assertSame( $wanted, $named, 'The refusal names exactly the failing fields. ' . $label );

		foreach ( $expected as $field => $code ) {
			$this->assertSame(
				$code,
				isset( $actual[ $field ] ) ? (string) $actual[ $field ] : '',
				sprintf( 'The failure reported for %s is the one the rule earns. %s', $field, $label )
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Dispatch
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch one edit request through the real route.
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
	 * One case: whether the enquiry is closed, the status it holds if not, the
	 * fields the request gets wrong and how, the valid fields it also carries,
	 * and the valid values those fields carry.
	 *
	 * `failures` draws a mode and a matching value for all eight breakable
	 * fields, because Eris draws the whole structure before anything inspects it;
	 * the entries outside the drawn subset are simply never read.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		$failures = array();

		foreach ( self::BREAKABLE as $field ) {
			$failures[ $field ] = self::failure( $field );
		}

		return \Eris\Generators::associative(
			array(
				'closed'   => \Eris\Generators::elements( array( true, false ) ),
				'status'   => \Eris\Generators::elements( self::OPEN_STATUSES ),
				'invalid'  => \Eris\Generators::subset( self::BREAKABLE ),
				'fallback' => \Eris\Generators::elements( self::BREAKABLE ),
				'failures' => \Eris\Generators::associative( $failures ),
				'carried'  => \Eris\Generators::subset( self::FIELDS ),
				'valid'    => Generators::enquiry(),
				'notes'    => \Eris\Generators::choose( 0, self::NOTES_MAX ),
				'entries'  => \Eris\Generators::choose( 0, self::ENTRIES_MAX ),
			)
		);
	}

	/**
	 * One way of getting a field wrong, as `{ mode, value }`.
	 *
	 * Every mode here fails a Requirement 3 criterion naming that field under the
	 * Manual profile in partial mode, and nothing else: an optional field is
	 * broken only by a violating value, never by being blanked, because blanking
	 * one is a legitimate correction rather than a failure (Requirement 3.16).
	 *
	 * @param string $field Field name from self::BREAKABLE.
	 * @return \Eris\Generator
	 */
	protected static function failure( $field ) {
		if ( 'email' === $field ) {
			return \Eris\Generators::oneOf(
				self::blanked(),
				self::mode( 'invalid_email', Generators::invalid_email() )
			);
		}

		if ( 'phone' === $field ) {
			return self::mode( 'no_digits', Generators::digitless_phone() );
		}

		if ( 'total_guests' === $field ) {
			return \Eris\Generators::oneOf(
				self::mode( 'out_of_range', Generators::invalid_total_guests() ),
				self::mode(
					'not_whole',
					// Sent as text, because the route declares `total_guests` as an
					// integer or a string: a JSON fraction would be refused by
					// core's own type check before the Validator ever saw it, which
					// is a different refusal from the one this property is about.
					\Eris\Generators::map(
						function ( $value ) {
							return (string) $value;
						},
						Generators::non_integer_total_guests()
					)
				)
			);
		}

		if ( 'selected_dates' === $field ) {
			return \Eris\Generators::oneOf(
				self::mode( 'empty_string', \Eris\Generators::constant( '' ) ),
				self::mode( 'empty_list', \Eris\Generators::constant( array() ) ),
				self::mode( 'blank_list', \Eris\Generators::constant( array( '   ', '' ) ) ),
				self::mode( 'too_many', Generators::oversized_candidate_dates() ),
				self::mode( 'unparseable', self::dates_with_one_unparseable() )
			);
		}

		if ( in_array( $field, array( 'event_type', 'site_exclusivity' ), true ) ) {
			return self::mode( 'not_allowed', self::terms_with_one_disallowed( $field ) );
		}

		// `first_name` and `last_name`: required, and carrying no value rule, so
		// the only way to fail them is to submit them empty (Requirement 19.4).
		return self::blanked();
	}

	/**
	 * A required field submitted empty: the failure that distinguishes a blanked
	 * field from an absent one (Requirement 19.4).
	 *
	 * @return \Eris\Generator
	 */
	protected static function blanked() {
		return \Eris\Generators::oneOf(
			self::mode( 'empty_string', \Eris\Generators::constant( '' ) ),
			self::mode( 'whitespace', \Eris\Generators::constant( "  \t " ) )
		);
	}

	/**
	 * A candidate date set inside the accepted count carrying one entry that names
	 * no calendar date (Requirement 3.6).
	 *
	 * Nine valid dates at most, so the count rule cannot fire first and change
	 * which code the failure earns.
	 *
	 * @return \Eris\Generator
	 */
	protected static function dates_with_one_unparseable() {
		return \Eris\Generators::map(
			function ( array $parts ) {
				list( $dates, $bad ) = $parts;

				$dates   = array_values( (array) $dates );
				$dates[] = (string) $bad;

				return $dates;
			},
			\Eris\Generators::tuple(
				Generators::candidate_dates( 0, Generators::DATES_MAX - 1 ),
				Generators::unparseable_date()
			)
		);
	}

	/**
	 * A term set for a taxonomy carrying one value outside its vocabulary
	 * (Requirement 3.12), alongside any number of permitted ones.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return \Eris\Generator
	 */
	protected static function terms_with_one_disallowed( $taxonomy ) {
		return \Eris\Generators::map(
			function ( array $parts ) {
				list( $allowed, $bad ) = $parts;

				$terms   = array_values( (array) $allowed );
				$terms[] = (string) $bad;

				return $terms;
			},
			\Eris\Generators::tuple(
				Generators::allowed_term_set( $taxonomy, 0 ),
				Generators::disallowed_term( $taxonomy )
			)
		);
	}

	/**
	 * Pair a failure mode with the value that produces it.
	 *
	 * @param string           $mode  Mode name, a key of self::CODES.
	 * @param \Eris\Generator  $value Generator for the offending value.
	 * @return \Eris\Generator
	 */
	protected static function mode( $mode, \Eris\Generator $value ) {
		return \Eris\Generators::map(
			function ( $drawn ) use ( $mode ) {
				return array(
					'mode'  => (string) $mode,
					'value' => $drawn,
				);
			},
			$value
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a composite generator by taking the cartesian product of every
	 * component's alternatives, and a case here draws nine valid field values, two
	 * of which are sets, plus eight failure modes with their values, two subsets
	 * and four scalars. That product does not fit in memory, so a genuine failure
	 * would be reported as an out-of-memory fatal rather than as a counterexample.
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * the failing case is reported exactly as generated, alongside the `ERIS_SEED`
	 * line that reproduces it.
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
	 * The fields this request gets wrong, as field => `{ mode, value }`.
	 *
	 * A closed enquiry may be offered a wholly valid body — Requirement 19.12 asks
	 * for 409 either way — so the subset is left as drawn there. An open enquiry
	 * needs at least one failing value for the property to say anything, so an
	 * empty subset is topped up with the drawn fallback field.
	 *
	 * @param array $case Generated case.
	 * @return array<string,array{mode:string,value:mixed}>
	 */
	private static function broken( array $case ) {
		$chosen = (array) $case['invalid'];

		if ( array() === $chosen && empty( $case['closed'] ) ) {
			$chosen = array( (string) $case['fallback'] );
		}

		$broken   = array();
		$failures = (array) $case['failures'];

		foreach ( self::BREAKABLE as $field ) {
			if ( in_array( $field, $chosen, true ) && isset( $failures[ $field ] ) ) {
				$broken[ $field ] = (array) $failures[ $field ];
			}
		}

		return $broken;
	}

	/**
	 * The error map this case must produce: one entry per broken field, holding
	 * the code that field's failure mode earns, and nothing else.
	 *
	 * @param array $broken Field => `{ mode, value }`.
	 * @return array<string,string>
	 */
	private static function expected_errors( array $broken ) {
		$errors = array();

		foreach ( $broken as $field => $failure ) {
			$mode  = (string) $failure['mode'];
			$codes = self::CODES;

			$errors[ $field ] = isset( $codes[ $mode ] ) ? $codes[ $mode ] : $mode;
		}

		return $errors;
	}

	/**
	 * The body to submit: every broken field carrying its offending value, plus
	 * any subset of the remaining fields carrying a valid value the fixture does
	 * not already hold.
	 *
	 * @param array $case   Generated case.
	 * @param array $broken Field => `{ mode, value }`.
	 * @return array<string,mixed>
	 */
	private function body( array $case, array $broken ) {
		$carried = (array) $case['carried'];
		$valid   = (array) $case['valid'];
		$body    = array();

		foreach ( self::FIELDS as $field ) {
			if ( isset( $broken[ $field ] ) ) {
				$body[ $field ] = $broken[ $field ]['value'];

				continue;
			}

			if ( in_array( $field, $carried, true ) && array_key_exists( $field, $valid ) ) {
				$body[ $field ] = self::unstored( $field, $valid[ $field ] );
			}
		}

		return $body;
	}

	/**
	 * A valid submitted value the fixture cannot already be holding.
	 *
	 * Only the three fields whose generators can land on a stored value need
	 * adjusting: the two taxonomies draw from the same vocabulary the fixture
	 * stores a value from, and `total_guests` draws from the same 1 to 10000
	 * range. The scalars and the candidate dates are stored as values no
	 * generator emits, so they arrive different by construction.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Generated valid value.
	 * @return mixed
	 */
	private static function unstored( $field, $value ) {
		if ( 'total_guests' === $field ) {
			return self::SEEDED_GUESTS === (int) $value ? self::SEEDED_GUESTS + 1 : $value;
		}

		if ( 'event_type' === $field ) {
			return self::without( (array) $value, self::SEEDED_EVENT_TYPE, 'wedding' );
		}

		if ( 'site_exclusivity' === $field ) {
			return self::without( (array) $value, self::SEEDED_EXCLUSIVITY, 'no-preference' );
		}

		return $value;
	}

	/**
	 * A term set with one value removed, topped up so it stays non-empty.
	 *
	 * @param array  $values   Generated term set.
	 * @param string $excluded Value the fixture stores.
	 * @param string $fallback Value to use when nothing else is left.
	 * @return array
	 */
	private static function without( array $values, $excluded, $fallback ) {
		$kept = array();

		foreach ( $values as $value ) {
			if ( (string) $value !== (string) $excluded ) {
				$kept[] = (string) $value;
			}
		}

		return array() === $kept ? array( $fallback ) : $kept;
	}

	/**
	 * A one-line description of the case, for failure messages.
	 *
	 * @param bool  $closed Whether the enquiry is closed.
	 * @param array $broken Field => `{ mode, value }`.
	 * @param array $body   Body submitted.
	 * @return string
	 */
	private static function label( $closed, array $broken, array $body ) {
		$modes = array();

		foreach ( $broken as $field => $failure ) {
			$modes[] = $field . '=' . $failure['mode'];
		}

		return sprintf(
			'[%s enquiry, broken {%s}, body carrying {%s}]',
			$closed ? 'closed' : 'open',
			implode( ', ', $modes ),
			implode( ', ', array_keys( $body ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write one enquiry to be edited, holding values no submission carries, plus
	 * its notes and history.
	 *
	 * The distinctness is what makes the before/after comparison meaningful: an
	 * edit that wrote before it validated would show the submitted values rather
	 * than passing unnoticed.
	 *
	 * @param string $status Status the enquiry holds.
	 * @return int
	 */
	private function seed( $status ) {
		$this->clear();

		Clock::freeze( self::SEEDED_AT );

		$id = EnquiryStore::create(
			array(
				'first_name'              => 'Seeded-Grace-' . $this->iteration,
				'last_name'               => 'Seeded-Hopper-' . $this->iteration,
				'email'                   => sprintf( 'seeded+%d@example.test', $this->iteration ),
				'phone'                   => '0131 496 0000',
				'total_guests'            => self::SEEDED_GUESTS,
				'message'                 => 'Seeded message for iteration ' . $this->iteration . '.',
				'status'                  => (string) $status,
				'crm_sync_state'          => 'synced',
				'fluentcrm_subscriber_id' => 777,
				'created_at'              => self::SEEDED_AT,
				'updated_at'              => self::SEEDED_AT,
				'status_changed_at'       => self::SEEDED_AT,
				'source'                  => 'webhook:rejected-edit-fixture',
			),
			self::SEEDED_DATES,
			array(
				'event_type'       => array( self::SEEDED_EVENT_TYPE ),
				'site_exclusivity' => array( self::SEEDED_EXCLUSIVITY ),
			),
			array( 'submitted' => 'seeded-payload-value' )
		);

		$this->assertIsInt( $id, 'Seeding the enquiry to edit should succeed.' );

		$id = (int) $id;

		HistoryRecorder::record( $id, 'created', 'Seeded enquiry.', array(), self::$user_id );

		return $id;
	}

	/**
	 * Seed the notes and extra history entries the case asked for.
	 *
	 * @param int   $id   Enquiry identifier.
	 * @param array $case Generated case.
	 * @return void
	 */
	private function seed_entries( $id, array $case ) {
		for ( $index = 0; $index < (int) $case['notes']; $index++ ) {
			$this->assertIsInt(
				NoteService::add( (int) $id, 'Spoke to the enquirer, round ' . $index . '.', self::$user_id ),
				'Seeding a note should succeed.'
			);
		}

		for ( $index = 0; $index < (int) $case['entries']; $index++ ) {
			HistoryRecorder::record( (int) $id, 'note_added', 'Seeded entry ' . $index . '.', array(), self::$user_id );
		}
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
	 * Empty the tables this property writes, between iterations.
	 *
	 * `DELETE` rather than `TRUNCATE`: the identifiers keep climbing, which is
	 * closer to a live table and means a stale identifier can never be mistaken
	 * for a fresh one.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );

			if ( '' === $table ) {
				continue;
			}

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
