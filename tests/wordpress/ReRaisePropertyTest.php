<?php
/**
 * Property 25: Re-raising copies forward and leaves the source intact.
 *
 * Feature: enquiry-data-layer, Property 25: For any source enquiry, creating a
 * new enquiry from it produces a copy whose `first_name`, `last_name`, `email`,
 * `phone`, `total_guests` and `message` equal the source's, whose candidate
 * dates, `event_type` values and `site_exclusivity` values equal the source's as
 * sets, whose status is `new`, whose `booking_id` is empty, whose `created_at` is
 * the copy time, and which reuses the source's `fluentcrm_subscriber_id` when
 * present; on success the source records the new identifier and the copy records
 * the source identifier; and the source's status, notes and history are otherwise
 * unchanged. For any injected failure in a copy step, no partial enquiry remains
 * and no relationship is recorded on the source.
 *
 * **Validates: Requirements 9.4, 9.5, 9.6, 9.7, 9.8, 9.9**
 *
 * How the property is instantiated, and why:
 *
 * - **Every re-raise goes through the real route.** Each iteration dispatches
 *   `POST /enquiries/{id}/duplicate` with `rest_do_request()` as an authenticated
 *   administrator, so the permission callback, the declared args and the route's
 *   digits-only identifier pattern all run where WordPress runs them. Three of
 *   the six criteria are the route's work rather than the store's — the two
 *   `duplicated` history entries and the reuse of the source's subscriber
 *   identifier through `ContactLinker::link()` — so calling
 *   `EnquiryStore::duplicate()` directly would leave them unquantified.
 * - **Source statuses include `closed`.** The duplicate route deliberately does
 *   not carry the closed-enquiry guard: re-raising a closed enquiry is the case
 *   Requirement 9 exists for, and Requirement 9.6 has the closed source record
 *   the new identifier. Quantifying over the six statuses without `closed` would
 *   therefore skip the intended case, so the status is drawn from
 *   `Lifecycle::STATUSES` whole.
 * - **The CRM holds a different identifier for the same address.** `FakeCrm` is
 *   pinned so the upsert of the copy's email resolves to
 *   self::CRM_SUBSCRIBER, which is never the source's self::SOURCE_SUBSCRIBER.
 *   Requirement 9.8 is then a real assertion: a copy that took whatever the
 *   upsert returned instead of reusing its source's identifier would hold the
 *   pinned value and fail. Whether the source holds an identifier at all is
 *   generated, because 9.8 is conditional on its presence — and when it holds
 *   none the copy is asserted to take the pinned value, so the conditional is
 *   pinned from both sides.
 * - **"Otherwise unchanged" is asserted as a whole-row comparison**, not as a
 *   list of fields: the source is read before and after, and the two hydrated
 *   rows must be identical once `duplicated_to_id` — the one column
 *   Requirement 9.6 has the re-raise write — is set aside. Its status, its field
 *   values, its booking and its own subscriber identifier are covered by that in
 *   one assertion, and a column this test did not think to name is covered too.
 *   Notes are compared verbatim, and history as its ordered list of entry types,
 *   which must gain exactly the `duplicated` entry and nothing else.
 * - **The failure clause injects real database failures, one per iteration.**
 *   Six points can fail on the way through `EnquiryStore::duplicate()`: the
 *   copy's enquiry row insert, its candidate date insert, its first term insert,
 *   the transaction's `COMMIT`, and each half of the two-way relationship. Each
 *   is reached by rewriting exactly that one statement into one that cannot run,
 *   through the `query` filter, so the store meets a genuine failure and its own
 *   recovery — the rollback, the compensating delete of the copy — runs against a
 *   working database. The fixture disarms itself the moment it fires and every
 *   iteration asserts that it fired, so no iteration is vacuous and none injects
 *   two failures where the property names one. The failure cases are generated
 *   holding at least one term value, so the term insert is a statement that
 *   exists to break.
 * - **"No partial enquiry remains" is a snapshot comparison.** The row counts of
 *   all five enquiry tables are taken before the request and must be unchanged
 *   after it, and exactly one enquiry may hold the submitted address — the
 *   source. A seeded enquiry carrying notes and history makes those counts
 *   non-zero, so a stray row shows as a change rather than as a zero matching a
 *   zero.
 * - **The case generator is wide**, and Eris shrinks a wide composite generator
 *   by building the cartesian product of every component's alternatives, which
 *   exhausts memory before it reports anything. Every case is therefore drawn
 *   through `unshrunk()`, so a failure is reported exactly as it was generated,
 *   with the `ERIS_SEED` line that reproduces the run.
 *
 * The clock is frozen twice per iteration — once at the instant the source is
 * seeded, once at the instant the copy is made — so `created_at` on the copy is a
 * value the assertion can name, and one that differs from the source's.
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
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class ReRaisePropertyTest
 */
class ReRaisePropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table, or with another test's fixture, in the same database.
	 */
	const PREFIX_SEGMENT = 'mehrr_';

	/**
	 * The instant the source enquiry is written at.
	 */
	const SEEDED_AT = '2025-05-01 09:15:00';

	/**
	 * The instant the copy is made at (Requirement 9.5).
	 */
	const COPY_AT = '2025-09-01 08:30:00';

	/**
	 * The subscriber identifier a source enquiry holds when it holds one.
	 */
	const SOURCE_SUBSCRIBER = 501;

	/**
	 * The subscriber identifier the CRM resolves the copy's email to.
	 *
	 * Deliberately not self::SOURCE_SUBSCRIBER: reuse of the source's identifier
	 * (Requirement 9.8) is only a real assertion when the value the upsert would
	 * otherwise have produced is a different one.
	 */
	const CRM_SUBSCRIBER = 777;

	/**
	 * The `source` value every seeded enquiry carries.
	 */
	const SOURCE = 'webhook:re-raise-fixture';

	/**
	 * The value stored in every seeded payload snapshot.
	 */
	const PAYLOAD = 'reraise-payload-value';

	/**
	 * Scalar fields a copy carries from its source (Requirement 9.4).
	 *
	 * Written out rather than read from `EnquiryStore::DUPLICATED_COLUMNS`, so a
	 * field quietly dropped from that constant is a failure here.
	 *
	 * @var string[]
	 */
	const COPIED_FIELDS = array( 'first_name', 'last_name', 'email', 'phone', 'total_guests', 'message' );

	/**
	 * Set-valued fields a copy carries from its source (Requirement 9.4).
	 *
	 * @var string[]
	 */
	const COPIED_SETS = array( 'event_type', 'site_exclusivity' );

	/**
	 * Tables that must hold no row from a failed re-raise.
	 *
	 * @var string[]
	 */
	const ENQUIRY_TABLES = array( 'enquiries', 'dates', 'terms', 'notes', 'history' );

	/**
	 * The copy steps a failure is injected into, one per iteration.
	 *
	 * The first four are the stages of the copy's own transaction inside
	 * `EnquiryStore::create()`; the last two are the halves of the two-way
	 * relationship, written after every other step has succeeded.
	 *
	 * @var string[]
	 */
	const INJECTED = array( 'enquiry_row', 'dates', 'terms', 'commit', 'copy_relationship', 'source_relationship' );

	/**
	 * The Enquiry Store table each insert failure breaks the insert of.
	 *
	 * @var array<string,string>
	 */
	const BROKEN_TABLES = array(
		'enquiry_row' => 'enquiries',
		'dates'       => 'dates',
		'terms'       => 'terms',
	);

	/**
	 * Which `UPDATE` of the enquiries table each relationship failure breaks.
	 *
	 * `duplicate()` writes the copy's `duplicated_from_id` first and the source's
	 * `duplicated_to_id` last, and issues no other update, so the ordinal is the
	 * half.
	 *
	 * @var array<string,int>
	 */
	const RELATIONSHIP_UPDATES = array(
		'copy_relationship'   => 1,
		'source_relationship' => 2,
	);

	/**
	 * Statement every injected failure substitutes.
	 *
	 * A table that does not exist, so the failure is a real database error rather
	 * than a value the store could have coped with.
	 */
	const BROKEN_STATEMENT = 'INSERT INTO meh_no_such_table ( id ) VALUES ( 1 )';

	/** Most candidate date ranges one generated source holds. */
	const RANGES_MAX = 3;

	/** Most notes and history entries one generated source holds. */
	const ENTRIES_MAX = 2;

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
	 * Whether `$wpdb` was printing errors before this test hid them.
	 *
	 * @var bool
	 */
	private $original_show_errors = true;

	/**
	 * Where PHP was logging before this test redirected it.
	 *
	 * @var string
	 */
	private $original_error_log = '';

	/**
	 * The CRM fake, standing in for FluentCRM.
	 *
	 * @var FakeCrm|null
	 */
	private $crm = null;

	/**
	 * The copy step the current iteration breaks, '' when none.
	 *
	 * @var string
	 */
	private $injecting = '';

	/**
	 * Whether the injected failure has fired.
	 *
	 * @var bool
	 */
	private $injected = false;

	/**
	 * Updates of the enquiries table seen since the failure was armed.
	 *
	 * @var int
	 */
	private $updates = 0;

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
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
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

		$this->original_prefix      = $wpdb->prefix;
		$wpdb->prefix               = $this->original_prefix . self::PREFIX_SEGMENT;
		$this->original_show_errors = (bool) $wpdb->show_errors;
		$this->original_error_log   = (string) ini_get( 'error_log' );

		// The injected failures make `$wpdb` print an error block, and the store
		// logs each one; neither belongs in the test output.
		$wpdb->hide_errors();
		ini_set( 'error_log', (string) tempnam( sys_get_temp_dir(), 'meh-reraise-' ) );

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		update_option( 'meh_enquiry_list', 7 );
		update_option( 'meh_enquiry_tag', 12 );

		$this->crm = FakeCrm::install();

		Clock::freeze( self::SEEDED_AT );
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

		$this->disarm();

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		$wp_rest_server = $this->original_server;

		wp_set_current_user( 0 );
		Clock::unfreeze();

		$this->drop_tables();

		ini_set( 'error_log', $this->original_error_log );

		$wpdb->prefix = $this->original_prefix;

		if ( $this->original_show_errors ) {
			$wpdb->show_errors();
		}

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 25: Re-raising copies forward and
	 * leaves the source intact.
	 *
	 * **Validates: Requirements 9.4, 9.5, 9.6, 9.7, 9.8, 9.9**
	 */
	public function test_re_raising_copies_forward_and_leaves_the_source_intact() {
		// Clause one: the copy carries the source forward, the relationship is
		// recorded both ways, and the source is otherwise as it was.
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $case ) {
					$this->check_copy( $case );
				}
			);

		// Clause two: a failure in any copy step leaves no partial enquiry and no
		// relationship on the source.
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::failure_scenario() ) )
			->then(
				function ( array $case ) {
					$this->check_failed_copy( $case );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * The claim
	 * ------------------------------------------------------------------ */

	/**
	 * One successful re-raise, dispatched and judged.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check_copy( array $case ) {
		$id     = $this->seed( $case );
		$before = EnquiryStore::find( $id );
		$notes  = NoteService::for_enquiry( $id );
		$trail  = self::entry_types( HistoryRecorder::for_enquiry( $id ) );
		$label  = $this->label( $case, $id );

		$this->pin_crm( (string) $before['email'] );

		Clock::freeze( self::COPY_AT );

		$response = $this->duplicate( $id );
		$data     = (array) $response->get_data();

		$this->assertSame( 201, $response->get_status(), 'A re-raise is answered 201. ' . $label );
		$this->assertSame( $id, (int) $data['source_id'], 'The answer names the source. ' . $label );

		$copy    = (array) $data['enquiry'];
		$copy_id = (int) $copy['id'];

		$this->assertNotSame( $id, $copy_id, 'The copy is a new enquiry. ' . $label );

		// Requirement 9.4: the scalar values and the two sets come across.
		foreach ( self::COPIED_FIELDS as $field ) {
			$this->assertSame( $before[ $field ], $copy[ $field ], $field . ' is copied. ' . $label );
		}

		foreach ( self::COPIED_SETS as $field ) {
			$this->assertSame(
				self::as_set( $before[ $field ] ),
				self::as_set( $copy[ $field ] ),
				$field . ' is copied as a set. ' . $label
			);
		}

		// The ranked list, copied rank and all: a re-raise that reordered the
		// enquirer's preferences would be inventing an answer they never gave.
		$this->assertSame(
			$before['date_ranges'],
			$copy['date_ranges'],
			'date_ranges is copied as a list, in the source order. ' . $label
		);

		// Requirement 9.5: the copy starts its own lifecycle, carries no booking,
		// and is created now.
		$this->assertSame( 'new', $copy['status'], 'The copy starts at new. ' . $label );
		$this->assertNull( $copy['booking_id'], 'The copy carries no booking. ' . $label );
		$this->assertSame( self::COPY_AT, $copy['created_at'], 'The copy is created at the copy time. ' . $label );

		// Requirements 9.6, 9.7: the relationship is recorded both ways.
		$source = EnquiryStore::find( $id );

		$this->assertSame( $id, (int) $copy['duplicated_from_id'], 'The copy records its source. ' . $label );
		$this->assertSame( $copy_id, (int) $source['duplicated_to_id'], 'The source records the copy. ' . $label );

		// Requirement 9.8: the source's subscriber identifier is reused when it
		// holds one; otherwise the copy takes the one the CRM resolved.
		$this->assertSame(
			$case['subscriber'] > 0 ? self::SOURCE_SUBSCRIBER : self::CRM_SUBSCRIBER,
			(int) $copy['fluentcrm_subscriber_id'],
			'The copy holds the expected subscriber identifier. ' . $label
		);

		$this->assertContains(
			RestEnquiries::DUPLICATED_HISTORY_TYPE,
			self::entry_types( (array) $copy['history'] ),
			'The copy records where it came from. ' . $label
		);

		// Requirement 9.9: the source is unchanged bar the relationship column and
		// the one `duplicated` entry.
		$this->assertSame(
			self::without_relationship( $before ),
			self::without_relationship( $source ),
			'The source keeps every stored value. ' . $label
		);
		$this->assertSame( $notes, NoteService::for_enquiry( $id ), 'The source keeps its notes. ' . $label );
		$this->assertSame(
			array_merge( $trail, array( RestEnquiries::DUPLICATED_HISTORY_TYPE ) ),
			self::entry_types( HistoryRecorder::for_enquiry( $id ) ),
			'The source gains the duplicated entry and nothing else. ' . $label
		);
	}

	/**
	 * One re-raise meeting one injected failure, dispatched and judged.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check_failed_copy( array $case ) {
		$id     = $this->seed( $case );
		$before = EnquiryStore::find( $id );
		$notes  = NoteService::for_enquiry( $id );
		$trail  = self::entry_types( HistoryRecorder::for_enquiry( $id ) );
		$counts = $this->row_counts();
		$label  = $this->label( $case, $id );

		$this->pin_crm( (string) $before['email'] );

		Clock::freeze( self::COPY_AT );

		$this->arm( (string) $case['cause'] );

		$response = $this->duplicate( $id );

		$this->disarm();

		$this->assertTrue( $this->injected, 'The fixture should have injected its failure. ' . $label );

		// The failure is reported rather than swallowed, and nothing of a copy is
		// answered.
		$data = (array) $response->get_data();

		$this->assertTrue( $response->is_error(), 'A failed re-raise answers an error. ' . $label );
		$this->assertSame( 500, $response->get_status(), 'A failed re-raise answers 500. ' . $label );
		$this->assertArrayNotHasKey( 'enquiry', $data, 'A failed re-raise answers no enquiry. ' . $label );

		// Requirement 9.7: no partial enquiry remains.
		$this->assertSame( $counts, $this->row_counts(), 'A failed re-raise leaves no row behind. ' . $label );
		$this->assertSame(
			1,
			$this->enquiries_holding( (string) $before['email'] ),
			'Only the source holds the address. ' . $label
		);

		// Requirement 9.7: and no relationship is recorded on the source, which
		// is otherwise exactly as it was.
		$source = EnquiryStore::find( $id );

		$this->assertNull( $source['duplicated_to_id'], 'The source records no relationship. ' . $label );
		$this->assertSame( $before, $source, 'The source is unchanged. ' . $label );
		$this->assertSame( $notes, NoteService::for_enquiry( $id ), 'The source keeps its notes. ' . $label );
		$this->assertSame(
			$trail,
			self::entry_types( HistoryRecorder::for_enquiry( $id ) ),
			'The source gains no history entry. ' . $label
		);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One source enquiry: its status, its field values, its dates and terms, its
	 * CRM state, its booking, and how many notes and history entries it holds.
	 *
	 * `closed` is among the statuses on purpose: the duplicate route carries no
	 * closed-enquiry guard, because re-raising a closed enquiry is the case
	 * Requirement 9 exists for.
	 *
	 * @param array $overrides Component overrides.
	 * @return \Eris\Generator
	 */
	protected static function scenario( array $overrides = array() ) {
		return \Eris\Generators::associative(
			array_merge(
				array(
					'status'           => \Eris\Generators::elements( array_values( Lifecycle::STATUSES ) ),
					'first_name'       => Generators::first_name(),
					'last_name'        => Generators::last_name(),
					'email'            => Generators::email(),
					'phone'            => Generators::phone_or_empty(),
					'total_guests'     => Generators::total_guests_or_unsupplied(),
					'message'          => Generators::message_or_empty(),
					'ranges'           => Generators::candidate_ranges( 1, self::RANGES_MAX ),
					'event_type'       => Generators::term_set( 0, 3 ),
					'site_exclusivity' => Generators::term_set( 0, 2 ),
					'subscriber'       => \Eris\Generators::elements( array( 0, self::SOURCE_SUBSCRIBER ) ),
					'crm_state'        => \Eris\Generators::elements( array( '', 'pending', 'synced' ) ),
					'booking'          => \Eris\Generators::elements( array( 0, 9 ) ),
					'is_test'          => \Eris\Generators::elements( array( true, false ) ),
					'notes'            => \Eris\Generators::choose( 0, self::ENTRIES_MAX ),
					'entries'          => \Eris\Generators::choose( 0, self::ENTRIES_MAX ),
					'cause'            => \Eris\Generators::constant( '' ),
				),
				$overrides
			)
		);
	}

	/**
	 * One source enquiry and the copy step its re-raise fails at.
	 *
	 * Both taxonomies hold at least one value, so the term insert the `terms`
	 * cause breaks is a statement that exists.
	 *
	 * @return \Eris\Generator
	 */
	protected static function failure_scenario() {
		return self::scenario(
			array(
				'ranges'           => Generators::candidate_ranges( 1, 3 ),
				'event_type'       => Generators::term_set( 1, 2 ),
				'site_exclusivity' => Generators::term_set( 1, 2 ),
				'cause'            => \Eris\Generators::elements( self::INJECTED ),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a composite generator by taking the cartesian product of every
	 * component's alternatives. For a case as wide as these — sixteen components,
	 * three of them sets — that product is large enough to exhaust the process'
	 * memory, so a genuine failure would be reported as an out-of-memory error
	 * rather than as a counterexample. Binding the drawn value to a constant
	 * generator makes shrinking a no-op, so the failing case is reported exactly
	 * as it was generated, alongside the `ERIS_SEED` line that reproduces it.
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
	 * Failure injection
	 * ------------------------------------------------------------------ */

	/**
	 * Break the named copy step, and nothing else.
	 *
	 * @param string $cause One of self::INJECTED.
	 * @return void
	 */
	private function arm( $cause ) {
		$this->disarm();

		$this->injecting = (string) $cause;
		$this->injected  = false;
		$this->updates   = 0;

		add_filter( 'query', array( $this, 'break_write' ) );
	}

	/**
	 * Remove whatever failure is installed.
	 *
	 * @return void
	 */
	private function disarm() {
		remove_filter( 'query', array( $this, 'break_write' ) );

		$this->injecting = '';
	}

	/**
	 * Rewrite the one statement of the failing step into one that cannot run.
	 *
	 * Only the first matching statement, so exactly one failure is injected and
	 * the store's own recovery — its rollback and its compensating delete of the
	 * copy — runs against a working database.
	 *
	 * @param string $query Statement about to run.
	 * @return string
	 */
	public function break_write( $query ) {
		$statement = trim( (string) $query );

		if ( $this->injected || '' === $this->injecting ) {
			return $query;
		}

		if ( 'commit' === $this->injecting ) {
			if ( 0 !== stripos( $statement, 'COMMIT' ) ) {
				return $query;
			}

			$this->injected = true;

			return self::BROKEN_STATEMENT;
		}

		if ( isset( self::RELATIONSHIP_UPDATES[ $this->injecting ] ) ) {
			$enquiries = Schema::table( 'enquiries' );

			if ( 0 !== stripos( $statement, 'UPDATE' ) || false === strpos( $statement, $enquiries ) ) {
				return $query;
			}

			++$this->updates;

			if ( $this->updates < self::RELATIONSHIP_UPDATES[ $this->injecting ] ) {
				return $query;
			}

			$this->injected = true;

			return self::BROKEN_STATEMENT;
		}

		$table = Schema::table( self::BROKEN_TABLES[ $this->injecting ] );

		if ( 0 !== stripos( $statement, 'INSERT' ) || false === strpos( $statement, $table ) ) {
			return $query;
		}

		$this->injected = true;

		return self::BROKEN_STATEMENT;
	}

	/* ---------------------------------------------------------------------
	 * The route
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch one re-raise through the real route.
	 *
	 * @param int $id Enquiry identifier.
	 * @return \WP_REST_Response
	 */
	private function duplicate( $id ) {
		$request = new WP_REST_Request(
			'POST',
			'/' . RestEnquiries::NAMESPACE . '/enquiries/' . (int) $id . '/duplicate'
		);

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( '{}' );

		return rest_do_request( $request );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write the generated source enquiry and return its identifier.
	 *
	 * @param array $case Generated case.
	 * @return int
	 */
	private function seed( array $case ) {
		$this->clear();

		Clock::freeze( self::SEEDED_AT );

		$id = EnquiryStore::create(
			array(
				'first_name'              => $case['first_name'],
				'last_name'               => $case['last_name'],
				'email'                   => $case['email'],
				'phone'                   => $case['phone'],
				'total_guests'            => $case['total_guests'],
				'message'                 => $case['message'],
				'status'                  => $case['status'],
				'crm_sync_state'          => $case['crm_state'],
				'fluentcrm_subscriber_id' => $case['subscriber'] > 0 ? (int) $case['subscriber'] : null,
				'booking_id'              => $case['booking'] > 0 ? (int) $case['booking'] : null,
				'created_at'              => self::SEEDED_AT,
				'updated_at'              => self::SEEDED_AT,
				'status_changed_at'       => self::SEEDED_AT,
				'source'                  => self::SOURCE,
				'is_test'                 => $case['is_test'] ? 1 : 0,
			),
			(array) $case['ranges'],
			array(
				'event_type'       => (array) $case['event_type'],
				'site_exclusivity' => (array) $case['site_exclusivity'],
			),
			array( 'submitted' => self::PAYLOAD )
		);

		$this->assertIsInt( $id, 'Seeding the source enquiry should succeed.' );

		$id = (int) $id;

		for ( $index = 0; $index < (int) $case['notes']; $index++ ) {
			$this->assertIsInt(
				NoteService::add( $id, 'Spoke to the enquirer, round ' . $index . '.', self::$user_id ),
				'Seeding a note should succeed.'
			);
		}

		for ( $index = 0; $index < (int) $case['entries']; $index++ ) {
			HistoryRecorder::record( $id, 'created', 'Seeded entry ' . $index . '.', array(), self::$user_id );
		}

		return $id;
	}

	/**
	 * Hand the CRM a clean log, and pin the address to an identifier that is not
	 * the source's, so reuse is a real assertion (Requirement 9.8).
	 *
	 * @param string $email Address the copy carries.
	 * @return void
	 */
	private function pin_crm( $email ) {
		$this->crm->reset()->will_succeed()->assign_subscriber_id( $email, self::CRM_SUBSCRIBER );
	}

	/**
	 * A description of the case, for failure messages.
	 *
	 * @param array $case Generated case.
	 * @param int   $id   Source enquiry identifier.
	 * @return string
	 */
	private function label( array $case, $id ) {
		return 'Case: ' . (string) wp_json_encode(
			array(
				'source_id'  => (int) $id,
				'status'     => $case['status'],
				'subscriber' => (int) $case['subscriber'],
				'booking'    => (int) $case['booking'],
				'ranges'     => count( (array) $case['ranges'] ),
				'terms'      => count( (array) $case['event_type'] ) + count( (array) $case['site_exclusivity'] ),
				'notes'      => (int) $case['notes'],
				'entries'    => (int) $case['entries'],
				'cause'      => $case['cause'],
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Reading the tables
	 * ------------------------------------------------------------------ */

	/**
	 * Row counts of every table a re-raise could write to.
	 *
	 * @return array<string,int>
	 */
	private function row_counts() {
		global $wpdb;

		$counts = array();

		foreach ( self::ENQUIRY_TABLES as $key ) {
			$table          = Schema::table( $key );
			$counts[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		return $counts;
	}

	/**
	 * How many enquiries hold one address.
	 *
	 * @param string $email Address to count.
	 * @return int
	 */
	private function enquiries_holding( $email ) {
		global $wpdb;

		$table = Schema::table( 'enquiries' );

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s", (string) $email ) // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * The entry types of a history trail, oldest first.
	 *
	 * @param array $entries History entries.
	 * @return string[]
	 */
	private static function entry_types( array $entries ) {
		$types = array();

		foreach ( $entries as $entry ) {
			$types[] = isset( $entry['entry_type'] ) ? (string) $entry['entry_type'] : '';
		}

		return $types;
	}

	/**
	 * One hydrated enquiry with the relationship column set aside.
	 *
	 * `duplicated_to_id` is the one column Requirement 9.6 has a re-raise write
	 * on the source, so setting it aside is what makes "otherwise unchanged"
	 * (Requirement 9.9) assertable as a whole-row comparison.
	 *
	 * @param array $enquiry Hydrated enquiry.
	 * @return array
	 */
	private static function without_relationship( array $enquiry ) {
		unset( $enquiry['duplicated_to_id'] );

		return $enquiry;
	}

	/**
	 * A set-valued field as a comparable set: distinct values, sorted.
	 *
	 * @param mixed $values Stored values.
	 * @return array
	 */
	private static function as_set( $values ) {
		$set = array_values( array_unique( array_map( 'strval', (array) $values ) ) );

		sort( $set );

		return $set;
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

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
