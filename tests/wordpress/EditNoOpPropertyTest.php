<?php
/**
 * Property 43: An edit that changes nothing is a no-op.
 *
 * Feature: enquiry-data-layer, Property 43: For any enquiry that is not closed
 * and any subset of its editable fields, an edit request submitting those fields
 * at their currently stored values — and any number of repetitions of that
 * request — leaves `updated_at`, every stored field value, every candidate date
 * row, every term row and the entire history list byte-identical, appends no
 * history entry of any type, and makes no FluentCRM call (idempotence).
 *
 * **Validates: Requirements 19.18**
 *
 * How the property is instantiated, and why:
 *
 * - **The submitted values are read out of the store, not remembered from the
 *   submission.** Each iteration creates an enquiry, hydrates it, and builds the
 *   edit request from that hydrated row. That is the only honest reading of "at
 *   their currently stored values": the value a creation submitted and the value
 *   the store holds differ wherever sanitisation, truncation or a column type
 *   had anything to say, and it is the stored one the store compares against.
 * - **Every subset of the nine editable fields is reachable**, including the
 *   empty one. The subset is drawn as a nine-bit mask and decoded, rather than as
 *   a nested collection generator, so the case stays flat — see `unshrunk()` for
 *   why width matters here.
 * - **The optional fields are drawn absent, empty and populated.** Three
 *   creation shapes are drawn: all nine fields supplied, the optional five
 *   supplied-but-empty, and the optional five absent altogether. The last two are
 *   the interesting ones, because "the stored value" is then `''` for `phone` and
 *   `message`, `null` for `total_guests` and zero rows for both multi-selects —
 *   and a submission carrying those back has to be recognised as a match rather
 *   than as a clearing operation.
 * - **Repetition is quantified over, not assumed.** Each case applies the same
 *   request one to three times and asserts the whole invariant after every one,
 *   which is what makes this idempotence rather than a single-shot check.
 * - **"Byte-identical" is asserted as a whole-row comparison.** The hydrated
 *   enquiry read before the edits and the one read after each edit must be
 *   identical, which covers `updated_at`, the candidate dates, both taxonomies,
 *   the payload snapshot, `status`, `status_changed_at`, `booking_id` and the two
 *   CRM columns in one assertion — including a column this test did not think to
 *   name. The history list is compared verbatim in the same way, entries and all,
 *   rather than as a count, so "appends no history entry of any type" cannot pass
 *   by a substitution.
 * - **The clock moves between seeding and the requests.** Every fixture is
 *   written at self::SEEDED_AT and every edit is applied at self::REQUEST_AT, so
 *   an `updated_at` write that slipped through would land on a value the row
 *   comparison catches. Freezing one instant throughout would let a stray
 *   `UPDATE` write the value that was already there.
 * - **"No FluentCRM call" is asserted against the call log, not inferred from
 *   the CRM columns.** The fake's log is emptied after the creation link, so any
 *   call the edit makes — including the module resolution `ContactLinker` does
 *   before it upserts anything — shows up as a recorded call. A working CRM is
 *   left installed and a subscriber id is pinned for the enquiry's address, so a
 *   re-link that did happen would succeed and be visible rather than failing
 *   silently.
 * - **Both linkage outcomes at creation are drawn.** An enquiry seeded with the
 *   CRM unavailable holds `pending` and no subscriber id; one seeded with it
 *   working holds `synced` and an id. Requirement 19.18 has to hold for both, and
 *   a re-link on the `pending` enquiry is exactly the write that would be
 *   tempting to make "just to catch up".
 * - **Statuses and sources are drawn.** Every status but `closed` is reachable,
 *   because the property is about any enquiry the guard admits rather than only a
 *   fresh one, and three `source` values stand in for the webhook, manual and
 *   migration routes, which the editor is required not to be able to tell apart.
 *
 * The edit is applied through `EnquiryEditor::apply()` rather than through the
 * REST route: `apply()` is where Requirement 19.18's three decisions are made,
 * and the route's own refusals are Property 41's and Property 24's business.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\ContactLinker;
use MarthrownEnquiryHub\EnquiryCreator;
use MarthrownEnquiryHub\EnquiryEditor;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;
use MarthrownEnquiryHub\Validator;

/**
 * Class EditNoOpPropertyTest
 */
class EditNoOpPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table, or with another test's fixture, in the same database.
	 */
	const PREFIX_SEGMENT = 'mehne_';

	/**
	 * The instant every fixture is written at.
	 */
	const SEEDED_AT = '2025-05-04 09:15:00';

	/**
	 * The instant every edit is applied at.
	 *
	 * Later than self::SEEDED_AT on purpose: an `updated_at` write that slipped
	 * through would move the timestamp to this value, which the whole-row
	 * comparison catches.
	 */
	const REQUEST_AT = '2025-09-18 14:40:00';

	/**
	 * Every status the writable guard admits (Requirement 19.18 read against
	 * Requirement 19.12: `closed` is Property 41's case, not this one).
	 *
	 * @var string[]
	 */
	const OPEN_STATUSES = array( 'new', 'contacted', 'quoted', 'converted', 'lost' );

	/**
	 * The three creation routes an enquiry's `source` can name.
	 *
	 * The editor reads `source` nowhere, so every one of these must behave
	 * identically here (Requirement 19.2).
	 *
	 * @var string[]
	 */
	const SOURCES = array( 'webhook:enquiry-form', 'manual:1', 'migration:fluentcrm' );

	/**
	 * The subscriber identifier pinned for the enquiry's address before the edits.
	 *
	 * Never reached: no linked field changes, so no upsert happens. Pinned anyway,
	 * so a re-link that did happen would succeed and be recorded rather than
	 * failing for want of a CRM.
	 */
	const SUBSCRIBER = 771;

	/**
	 * Most times one case repeats its edit request.
	 */
	const REPEATS_MAX = 3;

	/**
	 * The administrator every edit is attributed to.
	 *
	 * @var int
	 */
	private static $actor = 0;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The CRM fake, standing in for FluentCRM.
	 *
	 * @var FakeCrm|null
	 */
	private $crm = null;

	/**
	 * Vocabulary filters installed for the two multi-selects, by taxonomy.
	 *
	 * @var array<string,callable>
	 */
	private $vocabularies = array();

	/**
	 * Load the classes under test, and create the acting user.
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
		require_once MEH_INCLUDES_DIR . 'class-field-mapper.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-validator.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-editor.php';
		require_once MEH_INCLUDES_DIR . 'class-source-wpbs.php';
		require_once MEH_INCLUDES_DIR . 'class-booking-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';

		self::$actor = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up() {
		parent::set_up();

		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		update_option( 'meh_enquiry_list', 7 );
		update_option( 'meh_enquiry_tag', 12 );

		// A vocabulary for each multi-select, so a submission carrying the stored
		// values back is checked against a real one rather than passing because
		// the taxonomy was unconstrained.
		foreach ( array( 'event_type', 'site_exclusivity' ) as $taxonomy ) {
			$vocabulary = Generators::vocabulary( $taxonomy );

			$this->vocabularies[ $taxonomy ] = static function () use ( $vocabulary ) {
				return $vocabulary;
			};

			add_filter( 'meh_enquiry_terms_' . $taxonomy, $this->vocabularies[ $taxonomy ] );
		}

		$this->crm = FakeCrm::install();

		Clock::freeze( self::SEEDED_AT );
		wp_set_current_user( self::$actor );
	}

	public function tear_down() {
		global $wpdb;

		foreach ( $this->vocabularies as $taxonomy => $callback ) {
			remove_filter( 'meh_enquiry_terms_' . $taxonomy, $callback );
		}

		$this->vocabularies = array();

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		wp_set_current_user( 0 );
		Clock::unfreeze();

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 43: An edit that changes nothing is a
	 * no-op.
	 *
	 * **Validates: Requirements 19.18**
	 */
	public function test_an_edit_that_changes_nothing_is_a_no_op() {
		$this->limitTo( Iterations::count( 50 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $case ) {
					$this->check_no_op( $case );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * The claim
	 * ------------------------------------------------------------------ */

	/**
	 * One enquiry, offered its own stored values back one to three times.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check_no_op( array $case ) {
		$id      = $this->seed( $case );
		$before  = EnquiryStore::find( $id );
		$history = HistoryRecorder::for_enquiry( $id );
		$label   = $this->label( $case, $id, $before );

		$this->assertIsArray( $before, 'The seeded enquiry should be readable. ' . $label );
		$this->assertNotSame( 'closed', $before['status'], 'The fixture is not closed. ' . $label );

		$submitted = self::stored_values( $before, (int) $case['mask'] );

		// A working CRM with the enquiry's address pinned, and an empty call log:
		// anything the edit says to FluentCRM is recorded from here on.
		$this->crm->reset()->will_succeed()->assign_subscriber_id( (string) $before['email'], self::SUBSCRIBER );

		Clock::freeze( self::REQUEST_AT );

		for ( $round = 1; $round <= (int) $case['repeats']; $round++ ) {
			$this->check_round( $id, $submitted, $before, $history, $round, $label );
		}
	}

	/**
	 * One application of the request, and the whole invariant after it.
	 *
	 * @param array  $submitted The stored values, offered back.
	 * @param array  $before    The hydrated enquiry before any edit.
	 * @param array  $history   The history list before any edit.
	 * @param int    $id        Enquiry identifier.
	 * @param int    $round     Which repetition this is.
	 * @param string $label     Case description.
	 * @return void
	 */
	private function check_round( $id, array $submitted, array $before, array $history, $round, $label ) {
		$at      = ' [round ' . (int) $round . '] ' . $label;
		$outcome = EnquiryEditor::apply( $id, $submitted, self::$actor );

		$this->assertNotWPError( $outcome, 'A submission carrying stored values is accepted.' . $at );

		// Requirement 19.18: nothing changed, so the editor has nothing to report,
		// nothing to record and nothing to tell FluentCRM.
		$this->assertSame(
			array(),
			$outcome['changed'],
			'A submission carrying stored values changes nothing.' . $at
		);
		$this->assertSame(
			(string) $before['crm_sync_state'],
			(string) $outcome['crm_sync_state'],
			'The CRM state is reported as the one the enquiry already held.' . $at
		);

		$this->assertSame(
			$before,
			EnquiryStore::find( $id ),
			'Every stored value, candidate date, term row and updated_at is unchanged.' . $at
		);
		$this->assertSame(
			$history,
			HistoryRecorder::for_enquiry( $id ),
			'No history entry of any type is appended.' . $at
		);
		$this->assertSame(
			0,
			$this->crm->call_count(),
			'No FluentCRM call is made.' . $at
		);
	}

	/* ---------------------------------------------------------------------
	 * The request
	 * ------------------------------------------------------------------ */

	/**
	 * The drawn subset of editable fields, each at its stored value.
	 *
	 * The mask's bit N selects field N of `EnquiryEditor::EDITABLE_FIELDS`, so
	 * every subset including the empty one is reachable. Values are taken from the
	 * hydrated enquiry rather than from the creation submission, because it is the
	 * stored value the store compares a submission against.
	 *
	 * @param array $stored Hydrated enquiry.
	 * @param int   $mask   Nine-bit subset selector.
	 * @return array<string,mixed>
	 */
	private static function stored_values( array $stored, $mask ) {
		$submitted = array();

		foreach ( array_values( EnquiryEditor::EDITABLE_FIELDS ) as $index => $field ) {
			if ( 0 === ( (int) $mask & ( 1 << $index ) ) ) {
				continue;
			}

			$submitted[ $field ] = $stored[ $field ];
		}

		return $submitted;
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: the enquiry to create, the status and source it ends up holding,
	 * whether its creation link succeeded, which fields the edit submits, and how
	 * many times it is submitted.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'fields'  => \Eris\Generators::oneOf(
					Generators::enquiry(),
					Generators::enquiry_with_empty_optionals(),
					Generators::minimal_enquiry()
				),
				'status'  => \Eris\Generators::elements( self::OPEN_STATUSES ),
				'source'  => \Eris\Generators::elements( self::SOURCES ),
				'linked'  => \Eris\Generators::elements( array( true, false ) ),
				'mask'    => \Eris\Generators::choose( 0, 511 ),
				'repeats' => \Eris\Generators::choose( 1, self::REPEATS_MAX ),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a composite generator by taking the cartesian product of every
	 * component's alternatives. For a case as wide as this one that product is
	 * large enough to exhaust the process' memory, so a genuine failure would be
	 * reported as an out-of-memory error rather than as a counterexample. Binding
	 * the drawn value to a constant generator makes shrinking a no-op, so the
	 * failing case is reported exactly as it was generated, alongside the
	 * `ERIS_SEED` line that reproduces it.
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
	 * Create the generated enquiry through the shared creation path.
	 *
	 * Created rather than hand-written, so the stored values, the payload
	 * snapshot and the `created` history entry are the ones a real enquiry holds.
	 * The status is moved afterwards where the case asks for one other than `new`,
	 * with `status_changed_at` pinned to the seeding instant so the row carries no
	 * timestamp from the edit window.
	 *
	 * @param array $case Generated case.
	 * @return int
	 */
	private function seed( array $case ) {
		$this->clear();

		Clock::freeze( self::SEEDED_AT );

		$this->crm->reset();

		if ( (bool) $case['linked'] ) {
			$this->crm->will_succeed();
		} else {
			// Requirement 19.18 has to hold for a pending enquiry too, which is
			// the one a re-link would be tempting to make "to catch up".
			$this->crm->will_be_unavailable();
		}

		$outcome = EnquiryCreator::create(
			(array) $case['fields'],
			Validator::PROFILE_MANUAL,
			(string) $case['source'],
			self::SEEDED_AT,
			self::$actor
		);

		$this->assertTrue(
			(bool) $outcome['created'],
			'Seeding should create an enquiry. Errors: ' . (string) wp_json_encode( $outcome['errors'] )
		);

		$id = (int) $outcome['enquiry_id'];

		$this->assertSame(
			(bool) $case['linked'] ? ContactLinker::STATE_SYNCED : ContactLinker::STATE_PENDING,
			(string) $outcome['crm_sync_state'],
			'The seeded enquiry should hold the CRM state the case asked for.'
		);

		if ( 'new' !== (string) $case['status'] ) {
			$this->assertTrue(
				true === EnquiryStore::update_fields(
					$id,
					array(
						'status'            => (string) $case['status'],
						'status_changed_at' => self::SEEDED_AT,
					)
				),
				'Moving the seeded enquiry to its case status should succeed.'
			);
		}

		return $id;
	}

	/**
	 * A description of the case, for failure messages.
	 *
	 * @param array $case   Generated case.
	 * @param int   $id     Enquiry identifier.
	 * @param array $stored Hydrated enquiry.
	 * @return string
	 */
	private function label( array $case, $id, array $stored ) {
		return 'Case: ' . (string) wp_json_encode(
			array(
				'enquiry_id' => (int) $id,
				'status'     => (string) $case['status'],
				'source'     => (string) $case['source'],
				'linked'     => (bool) $case['linked'],
				'crm_state'  => (string) $stored['crm_sync_state'],
				'repeats'    => (int) $case['repeats'],
				'mask'       => (int) $case['mask'],
				'submitted'  => array_keys( self::stored_values( $stored, (int) $case['mask'] ) ),
				'ranges'     => count( (array) $stored['date_ranges'] ),
				'terms'      => count( (array) $stored['event_type'] ) + count( (array) $stored['site_exclusivity'] ),
				'guests'     => $stored['total_guests'],
				'phone'      => '' === (string) $stored['phone'] ? 'empty' : 'set',
				'message'    => '' === (string) $stored['message'] ? 'empty' : 'set',
			)
		);
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
