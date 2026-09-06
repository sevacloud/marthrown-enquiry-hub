<?php
/**
 * Property 31: The single-enquiry view is complete.
 *
 * Feature: enquiry-data-layer, Property 31: For any stored enquiry, the
 * single-enquiry route returns every stored field value, every candidate date,
 * every `event_type` and `site_exclusivity` value as sets, every note, every
 * history entry, the `crm_sync_state` value in both the `pending` and `synced`
 * states, the linked booking identifier, the FluentCRM contact URL when a
 * subscriber identifier is present, a permitted-transition list equal to the
 * lifecycle manager's permitted transitions for that enquiry's status, and a
 * summary of every other enquiry sharing that email holding exactly the
 * identifier, `created_at` and status.
 *
 * **Validates: Requirements 5.3, 13.2, 13.3, 13.6**
 *
 * Five things about how the property is instantiated are worth stating plainly:
 *
 * - It goes through the real route. `rest_do_request()` dispatches
 *   `GET /marthrown-enquiry-hub/v1/enquiries/{id}` on a REST server this test
 *   registered, with an administrator authenticated so `Auth::rest_permission`
 *   passes. The route pattern, the `absint` sanitisation of the identifier, the
 *   store's hydration and the presenter are therefore all in the loop, which is
 *   what "the view is complete" is a claim about.
 * - Completeness is asserted against what was *written*, not against what a
 *   second read of the store returns: the expected representation is built from
 *   the generated case, so a value the presenter drops, renames or rounds fails
 *   here rather than passing because both sides read the same row.
 * - `crm_sync_state` is quantified over both states in every iteration rather
 *   than left to the draw. The enquiry is read in the drawn state, the state is
 *   then flipped to the other one and read again, and the two representations
 *   must be identical apart from that one value (Requirement 5.3) — which is a
 *   stronger claim than "the field is present", because it also says a `pending`
 *   enquiry is not rendered any less completely than a `synced` one.
 * - The sibling claim needs three populations to mean anything: the enquiry
 *   itself, other enquiries sharing its email, and enquiries sharing nothing.
 *   Each iteration seeds all three, and the expected summary list is computed
 *   here by a plain sort, so a sibling list that included the subject, omitted a
 *   sharer, admitted a stranger, or carried more than the identifier,
 *   `created_at` and status would fail (Requirement 13.3).
 * - The case generator is wide — a whole enquiry, up to ten candidate dates, two
 *   term sets, notes, history entries and two sibling populations — and Eris
 *   shrinks a generator that wide by building the cartesian product of every
 *   component's alternatives, which exhausts memory long before it reports
 *   anything. The case is therefore drawn through `unshrunk()`, which reports the
 *   failing case exactly as it was generated, alongside the `ERIS_SEED` line that
 *   reproduces the run.
 *
 * `RestEnquirySingleTest` holds the worked examples this quantifies, the 404 and
 * the 500 among them; Property 32 owns the incomplete-row case, so nothing here
 * blanks a required column.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Auth;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Lifecycle;
use MarthrownEnquiryHub\NoteService;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class EnquirySingleViewPropertyTest
 */
class EnquirySingleViewPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table, or with another test's fixture, in the same database.
	 */
	const PREFIX_SEGMENT = 'mehsv_';

	/**
	 * The single-enquiry route under test, minus the identifier.
	 */
	const ROUTE = '/marthrown-enquiry-hub/v1/enquiries/';

	/**
	 * The instant the clock is frozen at, so nothing depends on the wall clock.
	 */
	const AT = '2025-07-04 14:30:00';

	/**
	 * The two `crm_sync_state` values Requirement 5.3 names.
	 *
	 * @var string[]
	 */
	const CRM_STATES = array( 'pending', 'synced' );

	/**
	 * `source` values, one per creation route.
	 *
	 * @var string[]
	 */
	const SOURCES = array( 'webhook:unidentified', 'webhook:kadence-form-3', 'manual:7', 'migration:fluentcrm' );

	/**
	 * Times of day the generated timestamps land on, both day boundaries
	 * included.
	 *
	 * @var string[]
	 */
	const TIMES = array( '00:00:00', '09:30:00', '23:59:59' );

	/**
	 * Most notes, history entries, siblings and strangers one iteration seeds.
	 */
	const CHILD_MAX = 3;

	/**
	 * Most values one taxonomy carries here.
	 *
	 * Smaller than the twenty the store accepts: how many values a taxonomy can
	 * hold is Property 1's subject, and what this one needs is only that an empty,
	 * a single-valued and a multi-valued set all reach the response.
	 */
	const TERMS_MAX = 4;

	/**
	 * The stored fields the representation must carry, so a field the presenter
	 * quietly dropped is a missing key rather than a silently skipped assertion.
	 *
	 * @var string[]
	 */
	const STORED_FIELDS = array(
		'id',
		'first_name',
		'last_name',
		'email',
		'phone',
		'total_guests',
		'message',
		'status',
		'crm_sync_state',
		'fluentcrm_subscriber_id',
		'booking_id',
		'created_at',
		'updated_at',
		'status_changed_at',
		'source',
		'is_test',
		'duplicated_from_id',
		'duplicated_to_id',
		'selected_dates',
		'event_type',
		'site_exclusivity',
		'payload',
	);

	/**
	 * The scalar columns a generated case supplies to `create()`.
	 *
	 * `id` is assigned by the store, the two `duplicated_*` columns belong to the
	 * duplication path, and the sets and the snapshot are separate arguments, so
	 * none of them is drawn as a scalar.
	 *
	 * @var string[]
	 */
	const SCALAR_COLUMNS = array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'total_guests',
		'message',
		'status',
		'crm_sync_state',
		'fluentcrm_subscriber_id',
		'booking_id',
		'created_at',
		'updated_at',
		'status_changed_at',
		'source',
		'is_test',
	);

	/**
	 * The keys a sibling summary holds, and no others (Requirement 13.3).
	 *
	 * @var string[]
	 */
	const SIBLING_KEYS = array( 'id', 'created_at', 'status' );

	/**
	 * The administrator every request is made as, and every note is authored by.
	 *
	 * `Auth::rest_permission` admits a logged-in user holding `manage_options`
	 * whatever the Access settings say, so an administrator is the caller that
	 * cannot be refused for a reason this property is not about.
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
	 * Serial number behind the stranger email addresses, so no stranger can ever
	 * share an email with the subject or with another stranger.
	 *
	 * @var int
	 */
	private $stranger_serial = 0;

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
		require_once MEH_INCLUDES_DIR . 'class-schema.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-query.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-store.php';
		require_once MEH_INCLUDES_DIR . 'class-history-recorder.php';
		require_once MEH_INCLUDES_DIR . 'class-note-service.php';
		require_once MEH_INCLUDES_DIR . 'class-lifecycle.php';
		require_once MEH_INCLUDES_DIR . 'class-auth.php';
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

		Clock::freeze( self::AT );
		wp_set_current_user( self::$user_id );

		// A server of this test's own, so the route table holds what this test
		// registered and nothing a previous test left behind.
		$this->original_server = $wp_rest_server;
		$wp_rest_server        = new WP_REST_Server();

		RestEnquiries::init();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->assertTrue( Auth::rest_permission(), 'The calling user should be admitted by the route.' );
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		$wp_rest_server = $this->original_server;

		wp_set_current_user( 0 );
		Clock::unfreeze();

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 31: The single-enquiry view is
	 * complete.
	 *
	 * **Validates: Requirements 5.3, 13.2, 13.3, 13.6**
	 */
	public function test_the_single_enquiry_view_is_complete() {
		$this->limitTo( Iterations::count( 50 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $case ) {
					$this->clear();

					$stored  = self::stored_values( $case );
					$dates   = $case['dates'];
					$terms   = self::terms( $case );
					$payload = self::payload( $stored, $dates, $terms );

					$id = $this->write( $stored, $dates, $terms, $payload );

					// Requirement 13.3 needs all three populations: the enquiry,
					// the enquiries sharing its email, and enquiries sharing
					// nothing with it.
					$siblings  = $this->write_siblings( $stored['email'], self::taken( $case, 'siblings' ) );
					$strangers = $this->write_strangers( (int) $case['stranger_count'] );

					$entries = $this->write_history( $id, self::taken( $case, 'history' ) );
					$notes   = $this->write_notes( $id, self::taken( $case, 'notes' ) );

					$response = $this->read( $id );
					$data     = (array) $response->get_data();
					$context  = self::context( $id, $case, $stored );

					$this->assertSame( 200, $response->get_status(), 'The route should answer 200.' . $context );

					// Requirement 13.2: every stored field value, the candidate
					// dates and both multi-selects as sets.
					$this->assert_stored_values( self::expected( $id, $stored, $dates, $terms, $payload ), $data, $context );

					// Requirement 13.2: every note, every history entry, the
					// linked booking identifier and the contact URL.
					$this->assert_notes( $notes, $id, $data, $context );
					$this->assert_history( $entries, count( $notes ), $id, $data, $context );
					$this->assert_crm_url( $stored, $data, $context );

					// Requirement 13.6: the lifecycle decides what may be done
					// next, not the controller.
					$this->assert_allowed_transitions( $stored['status'], $data, $context );

					// Requirement 13.3: every other enquiry sharing the email,
					// and nothing else.
					$this->assert_siblings( $siblings, $strangers, $id, $data, $context );

					// Requirement 5.3: the value is carried in both states, and
					// the state alone decides nothing else about the response.
					$this->assert_both_crm_states( $id, $stored['crm_sync_state'], $data, $context );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: a whole enquiry, its candidate dates and term sets, the notes and
	 * history entries to append, and the two sibling populations.
	 *
	 * The child populations are generated at full width and sliced to a generated
	 * count inside the property, so each count is quantified over without a bound
	 * generator.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'first_name'              => Generators::first_name(),
				'last_name'               => Generators::last_name(),
				'email'                   => Generators::email(),
				'phone'                   => Generators::phone_or_empty(),
				'total_guests'            => Generators::total_guests_or_unsupplied(),
				'message'                 => Generators::message_or_empty(),
				'status'                  => \Eris\Generators::elements( self::statuses() ),
				'crm_sync_state'          => \Eris\Generators::elements( self::CRM_STATES ),
				'source'                  => \Eris\Generators::elements( self::SOURCES ),
				'is_test'                 => \Eris\Generators::elements( array( true, false ) ),
				'fluentcrm_subscriber_id' => self::reference_id(),
				'booking_id'              => self::reference_id(),
				'created_at'              => self::stamp(),
				'updated_at'              => self::stamp(),
				'status_changed_at'       => self::stamp(),
				'dates'                   => Generators::candidate_dates(),
				'event_type'              => Generators::term_set( 0, self::TERMS_MAX ),
				'site_exclusivity'        => Generators::term_set( 0, self::TERMS_MAX ),
				'notes'                   => \Eris\Generators::vector( self::CHILD_MAX, self::note_body() ),
				'notes_count'             => \Eris\Generators::choose( 0, self::CHILD_MAX ),
				'history'                 => \Eris\Generators::vector( self::CHILD_MAX, \Eris\Generators::elements( HistoryRecorder::TYPES ) ),
				'history_count'           => \Eris\Generators::choose( 0, self::CHILD_MAX ),
				'siblings'                => \Eris\Generators::vector( self::CHILD_MAX, self::sibling() ),
				'siblings_count'          => \Eris\Generators::choose( 0, self::CHILD_MAX ),
				'stranger_count'          => \Eris\Generators::choose( 0, self::CHILD_MAX ),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step is exponential in
	 * the number of generated values. This case holds more than twenty drawn
	 * fields, several of them collections, which puts that product far past what
	 * the process can build: a failing iteration would exhaust memory and report
	 * an out-of-memory fatal instead of the counterexample.
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
			function ( $drawn ) {
				return \Eris\Generators::constant( $drawn );
			}
		);
	}

	/**
	 * A nullable identifier column value: unset, or a positive whole number.
	 *
	 * Both halves matter here: a present `fluentcrm_subscriber_id` is what the
	 * contact URL is built from, and an absent one is what makes it empty.
	 *
	 * @return \Eris\Generator
	 */
	protected static function reference_id() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( null ),
			\Eris\Generators::choose( 1, 999999 )
		);
	}

	/**
	 * A site-local `DATETIME` string, on a day boundary or between.
	 *
	 * @return \Eris\Generator
	 */
	protected static function stamp() {
		return \Eris\Generators::map(
			function ( array $parts ) {
				list( $offset, $time ) = $parts;

				return Generators::date_at( (int) $offset ) . ' ' . $time;
			},
			\Eris\Generators::tuple(
				\Eris\Generators::choose( -400, 400 ),
				\Eris\Generators::elements( self::TIMES )
			)
		);
	}

	/**
	 * A note body that survives the service's sanitisation unchanged, so the
	 * expected stored body is the generated one.
	 *
	 * Carries no HTML and no surrounding whitespace — a stripped or trimmed body
	 * is Property 26's subject — but does include the adversarial token set, which
	 * must reach the response character-for-character.
	 *
	 * @return \Eris\Generator
	 */
	protected static function note_body() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::map(
				function ( $number ) {
					return 'Rang back on the ' . $number . 'th, happy with the quote.';
				},
				\Eris\Generators::choose( 1, 28 )
			),
			\Eris\Generators::constant( 'Zoë asked about the barn — awaiting the caterer.' ),
			Generators::adversarial_string()
		);
	}

	/**
	 * One enquiry sharing the subject's email: its own status and creation time.
	 *
	 * @return \Eris\Generator
	 */
	protected static function sibling() {
		return \Eris\Generators::associative(
			array(
				'status'     => \Eris\Generators::elements( self::statuses() ),
				'created_at' => self::stamp(),
			)
		);
	}

	/**
	 * The recognised statuses, drawn from the lifecycle so a status added later
	 * cannot leave this property under-quantified.
	 *
	 * @return string[]
	 */
	protected static function statuses() {
		return array_values( Lifecycle::STATUSES );
	}

	/* ---------------------------------------------------------------------
	 * Case shaping
	 * ------------------------------------------------------------------ */

	/**
	 * The generated members of one child population that this iteration seeds.
	 *
	 * @param array  $case Generated case.
	 * @param string $key  Population key; its count lives at `{key}_count`.
	 * @return array
	 */
	protected static function taken( array $case, $key ) {
		return array_slice( (array) $case[ $key ], 0, (int) $case[ $key . '_count' ] );
	}

	/**
	 * The scalar column values of a case, in the shape `create()` takes.
	 *
	 * @param array $case Generated case.
	 * @return array<string,mixed>
	 */
	protected static function stored_values( array $case ) {
		$stored = array();

		foreach ( self::SCALAR_COLUMNS as $column ) {
			$stored[ $column ] = $case[ $column ];
		}

		return $stored;
	}

	/**
	 * The two term lists of a case, keyed by taxonomy.
	 *
	 * @param array $case Generated case.
	 * @return array<string,string[]>
	 */
	protected static function terms( array $case ) {
		return array(
			'event_type'       => $case['event_type'],
			'site_exclusivity' => $case['site_exclusivity'],
		);
	}

	/**
	 * The Enquiry Payload Snapshot written alongside the enquiry.
	 *
	 * Shaped like a webhook body — the submitted values as they arrived — because
	 * the snapshot the single-enquiry route carries is what a questionable intake
	 * is traced through, and a snapshot that lost its collections on the way back
	 * would trace nothing.
	 *
	 * @param array $stored Scalar column values.
	 * @param array $dates  Candidate dates.
	 * @param array $terms  Term lists keyed by taxonomy.
	 * @return array
	 */
	protected static function payload( array $stored, array $dates, array $terms ) {
		return array(
			'submitted' => array(
				'Your name'      => $stored['first_name'] . ' ' . $stored['last_name'],
				'Email'          => $stored['email'],
				'Preferred date' => implode( ', ', $dates ),
				'Event type'     => array_values( $terms['event_type'] ),
			),
			'meta'      => array(
				'source'      => $stored['source'],
				'received_at' => self::AT,
			),
		);
	}

	/**
	 * The representation the route should answer with, built from what was
	 * written rather than from a second read of the store.
	 *
	 * @param int   $id      Enquiry identifier.
	 * @param array $stored  Scalar column values that were written.
	 * @param array $dates   Candidate dates that were written.
	 * @param array $terms   Term lists that were written.
	 * @param array $payload Payload snapshot that was written.
	 * @return array<string,mixed>
	 */
	protected static function expected( $id, array $stored, array $dates, array $terms, array $payload ) {
		$expected = array(
			'id'                      => (int) $id,
			'total_guests'            => self::expected_number( $stored['total_guests'] ),
			'fluentcrm_subscriber_id' => self::expected_number( $stored['fluentcrm_subscriber_id'] ),
			'booking_id'              => self::expected_number( $stored['booking_id'] ),
			'is_test'                 => (bool) $stored['is_test'],
			'duplicated_from_id'      => null,
			'duplicated_to_id'        => null,
			'selected_dates'          => self::set( $dates ),
			'event_type'              => self::set( $terms['event_type'] ),
			'site_exclusivity'        => self::set( $terms['site_exclusivity'] ),
			'payload'                 => $payload,
		);

		foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'message', 'status', 'crm_sync_state', 'source' ) as $field ) {
			$expected[ $field ] = (string) $stored[ $field ];
		}

		foreach ( array( 'created_at', 'updated_at', 'status_changed_at' ) as $field ) {
			$expected[ $field ] = Clock::mysql( $stored[ $field ] );
		}

		return $expected;
	}

	/**
	 * What a nullable numeric column holds for a submitted value.
	 *
	 * @param mixed $value Submitted value.
	 * @return int|null
	 */
	protected static function expected_number( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return max( 0, (int) $value );
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Every stored field value reaches the representation, the candidate dates
	 * and both multi-selects as sets (Requirement 13.2).
	 *
	 * @param array  $expected Expected representation.
	 * @param array  $data     Response body.
	 * @param string $context  Case description for failure messages.
	 * @return void
	 */
	private function assert_stored_values( array $expected, array $data, $context ) {
		foreach ( self::STORED_FIELDS as $field ) {
			$this->assertArrayHasKey( $field, $data, sprintf( 'The representation should carry `%s`.', $field ) . $context );
		}

		foreach ( array( 'selected_dates', 'event_type', 'site_exclusivity' ) as $field ) {
			$this->assertIsArray( $data[ $field ], sprintf( '`%s` should be a list.', $field ) . $context );

			$this->assertSame(
				$expected[ $field ],
				self::set( (array) $data[ $field ] ),
				sprintf( '`%s` should be the written set.', $field ) . $context
			);

			unset( $expected[ $field ], $data[ $field ] );
		}

		foreach ( $expected as $field => $value ) {
			$this->assertSame(
				$value,
				$data[ $field ],
				sprintf( '`%s` should read back as it was written.', $field ) . $context
			);
		}
	}

	/**
	 * Every note reaches the representation, attributed and dated
	 * (Requirement 13.2).
	 *
	 * Bodies are compared as a multiset: which order they come back in is
	 * Property 26's subject, whereas "every note" is this one's.
	 *
	 * @param array  $notes   Bodies that were written, in the order written.
	 * @param int    $id      Enquiry identifier.
	 * @param array  $data    Response body.
	 * @param string $context Case description for failure messages.
	 * @return void
	 */
	private function assert_notes( array $notes, $id, array $data, $context ) {
		$this->assertIsArray( $data['notes'], 'The representation should carry a note list.' . $context );
		$this->assertCount( count( $notes ), $data['notes'], 'Every note should be returned, and no other.' . $context );

		$written  = $notes;
		$returned = array();

		foreach ( $data['notes'] as $note ) {
			$returned[] = isset( $note['body'] ) ? (string) $note['body'] : '';

			$this->assertSame( (int) $id, (int) $note['enquiry_id'], 'A note should belong to the enquiry.' . $context );
			$this->assertSame( self::$user_id, (int) $note['author_id'], 'A note should carry its author.' . $context );
			$this->assertSame( self::AT, (string) $note['created_at'], 'A note should carry when it was written.' . $context );
			$this->assertSame( self::author_name(), (string) $note['author'], 'A note should name its author.' . $context );
		}

		sort( $written, SORT_STRING );
		sort( $returned, SORT_STRING );

		$this->assertSame( $written, $returned, 'Every note body should reach the representation.' . $context );
	}

	/**
	 * Every history entry reaches the representation, attributed and typed
	 * (Requirement 13.2).
	 *
	 * The expected sequence is the seeded entries followed by one `note_added`
	 * entry per stored note, which is the order they were appended in; with the
	 * clock frozen, that is also the order the recorder reads them back in.
	 *
	 * Each expected entry carries its attribution as well as its type, because
	 * the two are independent: a seeded entry is attributed to the system even
	 * when the type drawn for it happens to be `note_added`, while the entry a
	 * stored note appends is attributed to that note's author
	 * (Requirement 11.5).
	 *
	 * @param array  $entries Entry types that were seeded, in the order written.
	 * @param int    $notes   How many notes were stored after them.
	 * @param int    $id      Enquiry identifier.
	 * @param array  $data    Response body.
	 * @param string $context Case description for failure messages.
	 * @return void
	 */
	private function assert_history( array $entries, $notes, $id, array $data, $context ) {
		$expected = array();

		foreach ( $entries as $type ) {
			$expected[] = array(
				'entry_type' => (string) $type,
				'actor'      => RestEnquiries::SYSTEM_ACTOR_NAME,
			);
		}

		for ( $index = 0; $index < (int) $notes; $index++ ) {
			$expected[] = array(
				'entry_type' => NoteService::HISTORY_TYPE,
				'actor'      => self::author_name(),
			);
		}

		$this->assertIsArray( $data['history'], 'The representation should carry a history list.' . $context );
		$this->assertCount( count( $expected ), $data['history'], 'Every history entry should be returned, and no other.' . $context );

		$returned = array();

		foreach ( $data['history'] as $entry ) {
			$returned[] = array(
				'entry_type' => isset( $entry['entry_type'] ) ? (string) $entry['entry_type'] : '',
				'actor'      => isset( $entry['actor'] ) ? (string) $entry['actor'] : '',
			);

			$this->assertSame( (int) $id, (int) $entry['enquiry_id'], 'An entry should belong to the enquiry.' . $context );
			$this->assertSame( self::AT, (string) $entry['created_at'], 'An entry should carry when it was written.' . $context );
			$this->assertIsArray( $entry['context'], 'An entry should carry its context.' . $context );
			$this->assertNotSame( '', (string) $entry['description'], 'An entry should carry a description.' . $context );
		}

		$this->assertSame( $expected, $returned, 'Every history entry should reach the representation, attributed.' . $context );
	}

	/**
	 * The FluentCRM contact URL is present exactly when a subscriber identifier
	 * is (Requirement 13.2).
	 *
	 * @param array  $stored  Scalar column values that were written.
	 * @param array  $data    Response body.
	 * @param string $context Case description for failure messages.
	 * @return void
	 */
	private function assert_crm_url( array $stored, array $data, $context ) {
		$subscriber = self::expected_number( $stored['fluentcrm_subscriber_id'] );

		$this->assertArrayHasKey( 'crm_url', $data, 'The representation should carry a contact URL field.' . $context );

		if ( null === $subscriber ) {
			$this->assertSame( '', (string) $data['crm_url'], 'An unlinked enquiry should carry no contact URL.' . $context );

			return;
		}

		$this->assertSame(
			admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . $subscriber ),
			(string) $data['crm_url'],
			'A linked enquiry should carry the contact URL of its subscriber.' . $context
		);
	}

	/**
	 * The permitted transitions are the lifecycle manager's, for this enquiry's
	 * status (Requirement 13.6).
	 *
	 * @param string $status  Status that was written.
	 * @param array  $data    Response body.
	 * @param string $context Case description for failure messages.
	 * @return void
	 */
	private function assert_allowed_transitions( $status, array $data, $context ) {
		$this->assertArrayHasKey( 'allowed_transitions', $data, 'The representation should carry the permitted transitions.' . $context );

		$this->assertSame(
			array_values( Lifecycle::allowed_from( $status ) ),
			array_values( (array) $data['allowed_transitions'] ),
			'The permitted transitions should be the lifecycle manager\'s.' . $context
		);

		$this->assertNotContains(
			$status,
			(array) $data['allowed_transitions'],
			'An enquiry should never be offered a transition to the status it holds.' . $context
		);

		foreach ( (array) $data['allowed_transitions'] as $target ) {
			$this->assertContains( $target, self::statuses(), 'Every offered transition should name a recognised status.' . $context );
		}
	}

	/**
	 * The sibling summary holds every other enquiry sharing the email, holding
	 * exactly the identifier, `created_at` and status — never the enquiry itself
	 * and never one sharing nothing with it (Requirement 13.3).
	 *
	 * @param array  $siblings  Sharers that were written: id, created_at, status.
	 * @param int[]  $strangers Identifiers of enquiries sharing no email.
	 * @param int    $id        Enquiry identifier.
	 * @param array  $data      Response body.
	 * @param string $context   Case description for failure messages.
	 * @return void
	 */
	private function assert_siblings( array $siblings, array $strangers, $id, array $data, $context ) {
		$this->assertIsArray( $data['siblings'], 'The representation should carry a sibling list.' . $context );

		$this->assertSame(
			self::most_recent_first( $siblings ),
			array_values( (array) $data['siblings'] ),
			'The siblings should be every other enquiry sharing the email, most recent first.' . $context
		);

		$returned = array();

		foreach ( $data['siblings'] as $sibling ) {
			$returned[] = (int) $sibling['id'];

			$this->assertSame(
				self::SIBLING_KEYS,
				array_keys( (array) $sibling ),
				'A sibling summary should hold the identifier, `created_at` and status, and nothing else.' . $context
			);
		}

		$this->assertNotContains( (int) $id, $returned, 'An enquiry is never its own sibling.' . $context );

		foreach ( $strangers as $stranger ) {
			$this->assertNotContains( (int) $stranger, $returned, 'An enquiry sharing no email is never a sibling.' . $context );
		}
	}

	/**
	 * The `crm_sync_state` value is carried in both the `pending` and the
	 * `synced` state, and nothing else about the representation turns on which
	 * state it holds (Requirement 5.3).
	 *
	 * @param int    $id      Enquiry identifier.
	 * @param string $state   State the enquiry was read in.
	 * @param array  $data    Response body of that read.
	 * @param string $context Case description for failure messages.
	 * @return void
	 */
	private function assert_both_crm_states( $id, $state, array $data, $context ) {
		$this->assertContains( $state, self::CRM_STATES, 'The drawn state should be one of the two.' . $context );
		$this->assertSame( $state, (string) $data['crm_sync_state'], 'The state the enquiry holds should be carried.' . $context );

		$other = 'synced' === $state ? 'pending' : 'synced';

		$this->assertNotWPError(
			EnquiryStore::update_fields( $id, array( 'crm_sync_state' => $other ) ),
			'Flipping the sync state should succeed.' . $context
		);

		$response = $this->read( $id );
		$flipped  = (array) $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'The route should answer 200 in either state.' . $context );
		$this->assertSame( $other, (string) $flipped['crm_sync_state'], 'The other state should be carried too.' . $context );

		unset( $data['crm_sync_state'], $flipped['crm_sync_state'] );

		$this->assertSame(
			$data,
			$flipped,
			'The sync state should decide nothing else about the representation.' . $context
		);
	}

	/* ---------------------------------------------------------------------
	 * The reference implementation
	 * ------------------------------------------------------------------ */

	/**
	 * The sibling summaries in the order the route should return them: by
	 * `created_at` descending, ties broken by descending identifier.
	 *
	 * Shares no code with `EnquiryStore`.
	 *
	 * @param array $siblings Sharers that were written.
	 * @return array<int,array{id:int,created_at:string,status:string}>
	 */
	private static function most_recent_first( array $siblings ) {
		usort(
			$siblings,
			function ( array $left, array $right ) {
				if ( $left['created_at'] === $right['created_at'] ) {
					return (int) $right['id'] - (int) $left['id'];
				}

				return strcmp( (string) $right['created_at'], (string) $left['created_at'] );
			}
		);

		return array_values( $siblings );
	}

	/**
	 * A list of values as a comparable set: deduplicated and ordered.
	 *
	 * @param array $values Values.
	 * @return string[]
	 */
	private static function set( array $values ) {
		$set = array_values( array_unique( array_map( 'strval', $values ) ) );

		sort( $set, SORT_STRING );

		return $set;
	}

	/**
	 * The display name the presenter should resolve for the note author.
	 *
	 * @return string
	 */
	private static function author_name() {
		return (string) get_userdata( self::$user_id )->display_name;
	}

	/**
	 * A short description of the case, for a failure message.
	 *
	 * Short deliberately: a generated `message` can run to 5000 characters, and a
	 * failure is easier to read with the shape of the case than with the whole of
	 * it. The `ERIS_SEED` line Eris prints reproduces the rest.
	 *
	 * @param int   $id     Enquiry identifier.
	 * @param array $case   Generated case.
	 * @param array $stored Scalar column values that were written.
	 * @return string
	 */
	private static function context( $id, array $case, array $stored ) {
		return ' Case: ' . wp_json_encode(
			array(
				'enquiry_id' => (int) $id,
				'status'     => $stored['status'],
				'crm'        => $stored['crm_sync_state'],
				'subscriber' => $stored['fluentcrm_subscriber_id'],
				'booking'    => $stored['booking_id'],
				'dates'      => count( $case['dates'] ),
				'terms'      => array( count( $case['event_type'] ), count( $case['site_exclusivity'] ) ),
				'notes'      => (int) $case['notes_count'],
				'history'    => (int) $case['history_count'],
				'siblings'   => (int) $case['siblings_count'],
				'strangers'  => (int) $case['stranger_count'],
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * The route
	 * ------------------------------------------------------------------ */

	/**
	 * Read one enquiry through the real route.
	 *
	 * @param int $id Enquiry identifier.
	 * @return \WP_REST_Response
	 */
	private function read( $id ) {
		return rest_do_request( new WP_REST_Request( 'GET', self::ROUTE . (int) $id ) );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write one enquiry through the store and return its identifier.
	 *
	 * @param array $stored  Scalar column values.
	 * @param array $dates   Candidate dates.
	 * @param array $terms   Term lists keyed by taxonomy.
	 * @param array $payload Payload snapshot.
	 * @return int
	 */
	private function write( array $stored, array $dates = array(), array $terms = array(), array $payload = array() ) {
		$id = EnquiryStore::create( $stored, $dates, $terms, $payload );

		$this->assertNotWPError( $id, 'Storing the enquiry should succeed.' );
		$this->assertGreaterThan( 0, (int) $id, 'A stored enquiry should hold a positive identifier.' );

		return (int) $id;
	}

	/**
	 * Write the enquiries sharing the subject's email, and return their
	 * summaries.
	 *
	 * @param string $email    Email every sharer carries.
	 * @param array  $siblings Generated sharers.
	 * @return array<int,array{id:int,created_at:string,status:string}>
	 */
	private function write_siblings( $email, array $siblings ) {
		$written = array();

		foreach ( $siblings as $sibling ) {
			$created = Clock::mysql( $sibling['created_at'] );

			$id = $this->write(
				array(
					'first_name'        => 'Ada',
					'last_name'         => 'Lovelace',
					'email'             => $email,
					'status'            => $sibling['status'],
					'created_at'        => $created,
					'updated_at'        => $created,
					'status_changed_at' => $created,
					'source'            => 'webhook:fixture',
				),
				array( Generators::date_at( 30 ) )
			);

			$written[] = array(
				'id'         => $id,
				'created_at' => $created,
				'status'     => (string) $sibling['status'],
			);
		}

		return $written;
	}

	/**
	 * Write enquiries sharing no email with the subject, and return their
	 * identifiers.
	 *
	 * @param int $count How many to write.
	 * @return int[]
	 */
	private function write_strangers( $count ) {
		$ids = array();

		for ( $index = 0; $index < (int) $count; $index++ ) {
			++$this->stranger_serial;

			$ids[] = $this->write(
				array(
					'first_name' => 'Grace',
					'last_name'  => 'Hopper',
					'email'      => 'stranger-' . $this->stranger_serial . '@example.test',
					'status'     => 'new',
					'created_at' => self::AT,
					'source'     => 'webhook:fixture',
				),
				array( Generators::date_at( 31 ) )
			);
		}

		return $ids;
	}

	/**
	 * Append the generated history entries, and return the types written.
	 *
	 * @param int   $id      Enquiry identifier.
	 * @param array $entries Entry types to append.
	 * @return string[]
	 */
	private function write_history( $id, array $entries ) {
		$written = array();

		foreach ( $entries as $index => $type ) {
			$this->assertGreaterThan(
				0,
				HistoryRecorder::record(
					$id,
					$type,
					sprintf( 'Seeded %s entry.', $type ),
					array( 'seeded' => $index ),
					HistoryRecorder::SYSTEM_ACTOR
				),
				'Appending a history entry should succeed.'
			);

			$written[] = (string) $type;
		}

		return $written;
	}

	/**
	 * Store the generated notes, and return the bodies written.
	 *
	 * @param int   $id     Enquiry identifier.
	 * @param array $bodies Note bodies to store.
	 * @return string[]
	 */
	private function write_notes( $id, array $bodies ) {
		$written = array();

		foreach ( $bodies as $body ) {
			$this->assertNotWPError(
				NoteService::add( $id, $body, self::$user_id ),
				'Storing a note should succeed.'
			);

			$written[] = (string) $body;
		}

		return $written;
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Empty the tables this property writes, between iterations.
	 *
	 * Necessary rather than tidy: a generated email can repeat across iterations,
	 * and an enquiry left behind by an earlier one would then be a genuine sibling
	 * of this one's subject.
	 *
	 * `DELETE` rather than `TRUNCATE`, so identifiers keep climbing and a stale
	 * identifier can never be mistaken for a fresh one.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array( 'enquiries', 'dates', 'terms', 'notes', 'history' ) as $key ) {
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
