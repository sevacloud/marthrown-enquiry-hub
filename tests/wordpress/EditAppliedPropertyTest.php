<?php
/**
 * Property 40: An applied edit changes only what it names and records what it
 * changed.
 *
 * Feature: enquiry-data-layer, Property 40: For any enquiry that is not closed,
 * of any `source` value including one created from an intake webhook request, one
 * created manually and one created by the migration runner, and any sequence of
 * valid enquiry edit requests each carrying any subset of `first_name`,
 * `last_name`, `email`, `phone`, `total_guests`, `message`, `selected_dates`,
 * `event_type` and `site_exclusivity`, after each applied request:
 *
 * - the stored value of every field present in that request equals the submitted
 *   value with HTML tags removed and truncation applied, and the stored value of
 *   every field absent from that request is unchanged;
 * - a submitted `selected_dates`, `event_type` or `site_exclusivity` set replaces
 *   that set exactly, and a set absent from the request is unchanged;
 * - `status`, `status_changed_at`, `created_at`, `source`, `is_test`,
 *   `booking_id` and the payload snapshot are byte-identical to their values
 *   before the request;
 * - the payload snapshot equals the snapshot captured when the enquiry was
 *   created, after any number of applied edits in any order (invariant);
 * - `updated_at` equals the request time exactly when at least one stored value
 *   changed;
 * - every history entry that existed before the request is unchanged, and exactly
 *   one entry of type `fields_edited` is appended when at least one stored value
 *   changed, naming exactly the fields whose stored value changed — no more and
 *   no fewer — holding the previous and the new value of each and carrying the
 *   submitting user's identifier as the acting user.
 *
 * **Validates: Requirements 19.1, 19.2, 19.6, 19.7, 19.8, 19.9, 19.10, 19.11, 19.13**
 *
 * How the property is instantiated, and why:
 *
 * - **A sequence, not a single request.** Each iteration applies one to three
 *   edits to the same enquiry, and every claim is asserted after each one against
 *   the state as it stood immediately before that request. That is what makes the
 *   payload invariant and "absent means unchanged" claims about an enquiry that
 *   has already been corrected, rather than only about a freshly created one.
 * - **Three field modes per request: absent, changed, repeated.** A field is left
 *   out, submitted with a fresh value, or submitted at exactly the value the
 *   enquiry currently holds. The third mode is what gives the last bullet its
 *   teeth: a request naming nine fields of which two actually differ must append
 *   an entry naming those two and no others, which a generator drawing only
 *   fresh values could never produce reliably.
 * - **The changed set is computed independently.** Rather than trusting the
 *   `changed` map the store returned, the test compares the hydrated enquiry
 *   before and after the request field by field and derives the set itself, then
 *   holds the history entry to it. An implementation that reported a field it did
 *   not write, or wrote a field it did not report, fails here.
 * - **Tag removal and truncation are exercised, not assumed.** A changed
 *   `first_name`, `last_name` or `message` may arrive wrapped in markup and over
 *   its stored capacity, and the expected stored value is computed here as
 *   strip-tags, trim, then a character clip — independently of the Validator.
 *   Requirement 19.6 is therefore asserted rather than deferred, and Property 11
 *   still owns the full sanitisation surface.
 * - **`source` is quantified over the three creation routes.** The editor reads
 *   `source` nowhere (Requirement 19.2), so the way to hold it to that is to run
 *   the same sequence against a webhook-shaped, a manual-shaped and a
 *   migration-shaped source value. Enquiries are seeded through
 *   `EnquiryStore::create()` rather than through each creation route, because what
 *   varies here is the stored `source` string and nothing else; Property 39 and
 *   the migration properties own the routes that write it.
 * - **`crm_sync_state` and `fluentcrm_subscriber_id` are deliberately not among
 *   the columns asserted unchanged.** An edit that touches a linked field
 *   re-links the contact, and the linker writes both, which is Property 42's
 *   subject. FluentCRM is present here as `FakeCrm` set to succeed, so the
 *   re-link path runs for real and its `crm_linked` history entry is allowed
 *   alongside the one `fields_edited` entry rather than being mistaken for it.
 * - **The clock moves for every request**, so a `updated_at` that should not have
 *   moved cannot pass by coincidence.
 * - **The case generator is wide**, and Eris shrinks a wide composite generator
 *   by building the cartesian product of every component's alternatives, which
 *   exhausts memory before it reports anything. Every case is therefore drawn
 *   through `unshrunk()`, so a failure is reported exactly as generated with the
 *   `ERIS_SEED` line that reproduces it.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryEditor;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class EditAppliedPropertyTest
 */
class EditAppliedPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table, or with another test's fixture, in the same database.
	 */
	const PREFIX_SEGMENT = 'mehae_';

	/**
	 * The instant every enquiry is created at.
	 */
	const CREATED_AT = '2025-03-04 09:15:00';

	/**
	 * The instant each successive edit request is made at.
	 *
	 * All later than self::CREATED_AT and all distinct, so a moved `updated_at`
	 * names the request that moved it and an unmoved one cannot be a coincidence.
	 *
	 * @var string[]
	 */
	const EDIT_TIMES = array(
		'2025-05-06 08:00:00',
		'2025-06-07 09:30:00',
		'2025-07-08 10:45:00',
	);

	/** Most edit requests one iteration applies. */
	const EDITS_MAX = 3;

	/** The six scalar fields an edit may carry (Requirement 19.1). */
	const SCALARS = array( 'first_name', 'last_name', 'email', 'phone', 'total_guests', 'message' );

	/** The three set-valued fields an edit may carry (Requirement 19.1). */
	const SETS = array( 'selected_dates', 'event_type', 'site_exclusivity' );

	/** Columns an edit never writes (Requirement 19.9), plus the snapshot (19.11). */
	const PROTECTED_COLUMNS = array( 'status', 'status_changed_at', 'created_at', 'source', 'is_test', 'booking_id', 'payload' );

	/** The three field modes one request draws per field. */
	const MODE_ABSENT = 'absent';
	const MODE_CHANGED = 'changed';
	const MODE_REPEATED = 'repeated';

	/** Statuses an editable enquiry may hold: anything but `closed`. */
	const OPEN_STATUSES = array( 'new', 'contacted', 'quoted', 'converted', 'lost' );

	/** The value stored in every seeded payload snapshot. */
	const PAYLOAD = 'edit-fixture-payload';

	/**
	 * The user every edit is attributed to.
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
	 * Load the classes under test, and create the editing user.
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

		// The vocabularies the Validator checks a submitted multi-select against,
		// so every generated term value — seeded or submitted — is one the edit
		// route accepts.
		foreach ( array_keys( Generators::VOCABULARIES ) as $taxonomy ) {
			add_filter(
				'meh_enquiry_terms_' . $taxonomy,
				static function () use ( $taxonomy ) {
					return Generators::vocabulary( $taxonomy );
				}
			);
		}

		$this->crm = FakeCrm::install();

		wp_set_current_user( self::$actor );
		Clock::freeze( self::CREATED_AT );
	}

	public function tear_down() {
		global $wpdb;

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
	 * Feature: enquiry-data-layer, Property 40: An applied edit changes only what
	 * it names and records what it changed.
	 *
	 * **Validates: Requirements 19.1, 19.2, 19.6, 19.7, 19.8, 19.9, 19.10, 19.11, 19.13**
	 */
	public function test_an_applied_edit_changes_only_what_it_names() {
		$this->limitTo( Iterations::count( 40 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $case ) {
					$this->check_sequence( $case );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * The claim
	 * ------------------------------------------------------------------ */

	/**
	 * One enquiry, corrected once per generated request, checked after each.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check_sequence( array $case ) {
		$id      = $this->seed( $case );
		$created = EnquiryStore::find( $id );

		foreach ( array_values( (array) $case['edits'] ) as $index => $plan ) {
			$before  = EnquiryStore::find( $id );
			$history = HistoryRecorder::for_enquiry( $id );
			$at      = self::EDIT_TIMES[ $index % count( self::EDIT_TIMES ) ];
			$request = $this->request( (array) $plan, $before );
			$label   = $this->label( $case, $id, $index, $request );

			Clock::freeze( $at );

			$applied = EnquiryEditor::apply( $id, $request, self::$actor );

			$this->assertIsArray( $applied, 'A valid edit request should be applied. ' . $label );

			$after = EnquiryStore::find( $id );

			$this->check_values( $request, $before, $after, $label );
			$this->check_protected( $before, $after, $created, $label );

			$changed = self::changed_fields( $before, $after );

			$this->check_timestamp( $changed, $before, $after, $at, $label );
			$this->check_history( $changed, $before, $after, $history, $id, $label );
		}
	}

	/**
	 * Every field present in the request holds the submitted value, sanitised and
	 * truncated; every field absent from it is unchanged (Requirements 19.6, 19.7).
	 *
	 * @param array  $request Submitted field map.
	 * @param array  $before  Hydrated enquiry before the request.
	 * @param array  $after   Hydrated enquiry after the request.
	 * @param string $label   Case description.
	 * @return void
	 */
	private function check_values( array $request, array $before, array $after, $label ) {
		foreach ( self::SCALARS as $field ) {
			if ( ! array_key_exists( $field, $request ) ) {
				$this->assertSame(
					$before[ $field ],
					$after[ $field ],
					$field . ' is absent from the request, so it is unchanged. ' . $label
				);

				continue;
			}

			$this->assertSame(
				self::expected_scalar( $field, $request[ $field ] ),
				$after[ $field ],
				$field . ' holds the submitted value, tags removed and truncated. ' . $label
			);
		}

		foreach ( self::SETS as $field ) {
			if ( ! array_key_exists( $field, $request ) ) {
				$this->assertSame(
					self::as_set( $before[ $field ] ),
					self::as_set( $after[ $field ] ),
					$field . ' is absent from the request, so that set is unchanged. ' . $label
				);

				continue;
			}

			$this->assertSame(
				self::as_set( $request[ $field ] ),
				self::as_set( $after[ $field ] ),
				$field . ' is replaced by exactly the submitted set. ' . $label
			);
		}
	}

	/**
	 * The columns an edit never writes, and the snapshot invariant
	 * (Requirements 19.9, 19.11).
	 *
	 * @param array  $before  Hydrated enquiry before the request.
	 * @param array  $after   Hydrated enquiry after the request.
	 * @param array  $created Hydrated enquiry as created.
	 * @param string $label   Case description.
	 * @return void
	 */
	private function check_protected( array $before, array $after, array $created, $label ) {
		foreach ( self::PROTECTED_COLUMNS as $column ) {
			$this->assertSame(
				$before[ $column ],
				$after[ $column ],
				$column . ' is not written by an edit. ' . $label
			);
		}

		$this->assertSame(
			$created['payload'],
			$after['payload'],
			'The payload snapshot still equals the one captured at creation. ' . $label
		);
	}

	/**
	 * `updated_at` moves to the request time exactly when something changed
	 * (Requirement 19.8).
	 *
	 * @param array  $changed Fields whose stored value changed.
	 * @param array  $before  Hydrated enquiry before the request.
	 * @param array  $after   Hydrated enquiry after the request.
	 * @param string $at      The request time.
	 * @param string $label   Case description.
	 * @return void
	 */
	private function check_timestamp( array $changed, array $before, array $after, $at, $label ) {
		if ( array() === $changed ) {
			$this->assertSame(
				$before['updated_at'],
				$after['updated_at'],
				'Nothing changed, so updated_at is untouched. ' . $label
			);

			return;
		}

		$this->assertSame(
			(string) $at,
			$after['updated_at'],
			'Something changed, so updated_at holds the request time. ' . $label
		);
	}

	/**
	 * The history is appended to and never rewritten, and the one `fields_edited`
	 * entry names exactly what changed (Requirements 19.10, 19.13).
	 *
	 * @param array  $changed Fields whose stored value changed.
	 * @param array  $before  Hydrated enquiry before the request.
	 * @param array  $after   Hydrated enquiry after the request.
	 * @param array  $history History as it stood before the request.
	 * @param int    $id      Enquiry identifier.
	 * @param string $label   Case description.
	 * @return void
	 */
	private function check_history( array $changed, array $before, array $after, array $history, $id, $label ) {
		$entries = HistoryRecorder::for_enquiry( $id );

		// Requirement 19.10: every entry that existed is still there, unchanged
		// and in the same place.
		$this->assertSame(
			$history,
			array_slice( $entries, 0, count( $history ) ),
			'No history entry that existed before the request was altered. ' . $label
		);

		$appended = array_slice( $entries, count( $history ) );
		$edits    = array();

		foreach ( $appended as $entry ) {
			if ( EnquiryEditor::HISTORY_TYPE === $entry['entry_type'] ) {
				$edits[] = $entry;

				continue;
			}

			// The only other entry an applied edit may append is the re-link's own
			// (Property 42); anything else is an entry this path invented.
			$this->assertSame(
				'crm_linked',
				$entry['entry_type'],
				'An edit appends no history entry beyond fields_edited and crm_linked. ' . $label
			);
		}

		if ( array() === $changed ) {
			$this->assertSame( array(), $edits, 'Nothing changed, so no fields_edited entry was appended. ' . $label );

			return;
		}

		$this->assertCount( 1, $edits, 'Exactly one fields_edited entry is appended. ' . $label );

		$entry   = $edits[0];
		$context = (array) $entry['context'];
		$named   = array_keys( $context );

		sort( $named );

		$this->assertSame(
			$changed,
			$named,
			'The entry names exactly the fields whose stored value changed. ' . $label
		);
		$this->assertSame(
			self::$actor,
			(int) $entry['actor_id'],
			'The entry carries the submitting user as the acting user. ' . $label
		);

		foreach ( $context as $field => $change ) {
			$change = (array) $change;

			$this->assertSame(
				self::comparable( $before[ $field ] ),
				self::comparable( isset( $change['from'] ) ? $change['from'] : null ),
				'The entry holds the previous value of ' . $field . '. ' . $label
			);
			$this->assertSame(
				self::comparable( $after[ $field ] ),
				self::comparable( isset( $change['to'] ) ? $change['to'] : null ),
				'The entry holds the new value of ' . $field . '. ' . $label
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One enquiry and the sequence of edit requests offered to it.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'enquiry' => self::seed_values(),
				'source'  => self::source(),
				'status'  => \Eris\Generators::elements( self::OPEN_STATUSES ),
				'is_test' => \Eris\Generators::elements( array( true, false ) ),
				'booking' => \Eris\Generators::elements( array( 0, 31 ) ),
				'notes'   => \Eris\Generators::choose( 0, 2 ),
				'edits'   => \Eris\Generators::bind(
					\Eris\Generators::choose( 1, self::EDITS_MAX ),
					static function ( $count ) {
						return \Eris\Generators::vector( (int) $count, self::plan() );
					}
				),
			)
		);
	}

	/**
	 * The field values one seeded enquiry is created with.
	 *
	 * Every optional field may arrive empty, so a "repeated" submission of a
	 * stored empty value is among the requests the property covers.
	 *
	 * @return \Eris\Generator
	 */
	protected static function seed_values() {
		return \Eris\Generators::associative(
			array(
				'first_name'       => Generators::first_name(),
				'last_name'        => Generators::last_name(),
				'email'            => Generators::email(),
				'phone'            => Generators::phone_or_empty(),
				'total_guests'     => Generators::total_guests_or_unsupplied(),
				'message'          => Generators::message_or_empty(),
				'selected_dates'   => Generators::candidate_dates( 1, 4 ),
				'event_type'       => Generators::allowed_term_set( 'event_type', 0 ),
				'site_exclusivity' => Generators::allowed_term_set( 'site_exclusivity', 0 ),
			)
		);
	}

	/**
	 * A `source` value from each of the three creation routes (Requirement 19.2).
	 *
	 * @return \Eris\Generator
	 */
	protected static function source() {
		return \Eris\Generators::elements(
			array(
				'webhook:enquiry-form',
				'webhook:unidentified',
				'manual:' . 4242,
				'migration:fluentcrm',
			)
		);
	}

	/**
	 * One edit request: a mode per field, and the value to submit where that mode
	 * is "changed".
	 *
	 * @return \Eris\Generator
	 */
	protected static function plan() {
		$modes  = array();
		$values = array();

		foreach ( array_merge( self::SCALARS, self::SETS ) as $field ) {
			$modes[ $field ]  = \Eris\Generators::elements(
				array( self::MODE_ABSENT, self::MODE_CHANGED, self::MODE_REPEATED )
			);
			$values[ $field ] = self::submitted_value( $field );
		}

		return \Eris\Generators::associative(
			array(
				'modes'  => \Eris\Generators::associative( $modes ),
				'values' => \Eris\Generators::associative( $values ),
			)
		);
	}

	/**
	 * A valid submitted value for one field.
	 *
	 * `first_name`, `last_name` and `message` may arrive wrapped in markup and
	 * over their stored capacity, which is what makes Requirement 19.6 part of
	 * this property rather than an assumption it leans on.
	 *
	 * @param string $field Field name.
	 * @return \Eris\Generator
	 */
	protected static function submitted_value( $field ) {
		switch ( $field ) {
			case 'first_name':
			case 'last_name':
				return \Eris\Generators::oneOf( Generators::first_name(), self::marked_up( $field ) );

			case 'email':
				return Generators::email();

			case 'phone':
				return Generators::phone_or_empty();

			case 'total_guests':
				return Generators::total_guests_or_unsupplied();

			case 'message':
				return \Eris\Generators::oneOf( Generators::message_or_empty(), self::marked_up( $field ) );

			case 'selected_dates':
				return Generators::candidate_dates( 1, 4 );

			default:
				return Generators::allowed_term_set( $field, 0 );
		}
	}

	/**
	 * An over-capacity value for a field, wrapped in HTML markup.
	 *
	 * @param string $field Field name from Generators::LIMITS.
	 * @return \Eris\Generator
	 */
	protected static function marked_up( $field ) {
		return \Eris\Generators::map(
			static function ( array $parts ) {
				list( $core, $markup ) = $parts;

				return sprintf( (string) $markup, (string) $core );
			},
			\Eris\Generators::tuple( Generators::over_limit_core( $field ), Generators::markup() )
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
	 * Requests and fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * The field map one generated plan submits, against the current stored state.
	 *
	 * A "repeated" field carries exactly what the enquiry holds now, which is what
	 * lets the property distinguish "named in the request" from "actually changed".
	 *
	 * @param array $plan   Generated plan.
	 * @param array $stored Hydrated enquiry as it stands.
	 * @return array
	 */
	private function request( array $plan, array $stored ) {
		$modes   = (array) $plan['modes'];
		$values  = (array) $plan['values'];
		$request = array();

		foreach ( array_merge( self::SCALARS, self::SETS ) as $field ) {
			$mode = isset( $modes[ $field ] ) ? (string) $modes[ $field ] : self::MODE_ABSENT;

			if ( self::MODE_ABSENT === $mode ) {
				continue;
			}

			$request[ $field ] = self::MODE_REPEATED === $mode ? $stored[ $field ] : $values[ $field ];
		}

		return $request;
	}

	/**
	 * Write the generated enquiry and return its identifier.
	 *
	 * @param array $case Generated case.
	 * @return int
	 */
	private function seed( array $case ) {
		$this->clear();
		$this->crm->reset()->will_succeed();

		Clock::freeze( self::CREATED_AT );

		$fields = (array) $case['enquiry'];

		$id = EnquiryStore::create(
			array(
				'first_name'        => $fields['first_name'],
				'last_name'         => $fields['last_name'],
				'email'             => $fields['email'],
				'phone'             => $fields['phone'],
				'total_guests'      => $fields['total_guests'],
				'message'           => $fields['message'],
				'status'            => (string) $case['status'],
				'booking_id'        => $case['booking'] > 0 ? (int) $case['booking'] : null,
				'created_at'        => self::CREATED_AT,
				'updated_at'        => self::CREATED_AT,
				'status_changed_at' => self::CREATED_AT,
				'source'            => (string) $case['source'],
				'is_test'           => $case['is_test'] ? 1 : 0,
			),
			(array) $fields['selected_dates'],
			array(
				'event_type'       => (array) $fields['event_type'],
				'site_exclusivity' => (array) $fields['site_exclusivity'],
			),
			array( 'submitted' => self::PAYLOAD )
		);

		$this->assertIsInt( $id, 'Seeding the enquiry should succeed.' );

		$id = (int) $id;

		// A history entry or two already in place, so "every entry that existed
		// before is unchanged" has something to be about.
		for ( $index = 0; $index < (int) $case['notes']; $index++ ) {
			HistoryRecorder::record( $id, 'created', 'Seeded entry ' . $index . '.', array( 'seeded' => $index ), self::$actor );
		}

		return $id;
	}

	/**
	 * A description of the case, for failure messages.
	 *
	 * @param array $case    Generated case.
	 * @param int   $id      Enquiry identifier.
	 * @param int   $index   Position of the request in the sequence.
	 * @param array $request The submitted field map.
	 * @return string
	 */
	private function label( array $case, $id, $index, array $request ) {
		return 'Case: ' . (string) wp_json_encode(
			array(
				'enquiry_id' => (int) $id,
				'source'     => (string) $case['source'],
				'status'     => (string) $case['status'],
				'request'    => (int) $index,
				'of'         => count( (array) $case['edits'] ),
				'submitted'  => array_keys( $request ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The fields whose stored value differs between two hydrated enquiries.
	 *
	 * Computed here rather than read from the store's own `changed` map, so the
	 * history entry is held to what the database actually holds.
	 *
	 * @param array $before Hydrated enquiry before the request.
	 * @param array $after  Hydrated enquiry after the request.
	 * @return string[] Sorted.
	 */
	private static function changed_fields( array $before, array $after ) {
		$changed = array();

		foreach ( self::SCALARS as $field ) {
			if ( $before[ $field ] !== $after[ $field ] ) {
				$changed[] = $field;
			}
		}

		foreach ( self::SETS as $field ) {
			if ( self::as_set( $before[ $field ] ) !== self::as_set( $after[ $field ] ) ) {
				$changed[] = $field;
			}
		}

		sort( $changed );

		return $changed;
	}

	/**
	 * The value a submitted scalar is expected to be stored as.
	 *
	 * Tag removal, trimming, then a character clip — computed here rather than
	 * taken from the Validator, so a narrowing of either would surface as a
	 * failure (Requirement 19.6).
	 *
	 * @param string $field     Field name.
	 * @param mixed  $submitted Submitted value.
	 * @return string|int|null
	 */
	private static function expected_scalar( $field, $submitted ) {
		if ( 'total_guests' === $field ) {
			return ( null === $submitted || '' === $submitted ) ? null : (int) $submitted;
		}

		$text  = trim( wp_strip_all_tags( (string) $submitted ) );
		$limit = isset( Generators::LIMITS[ $field ] ) ? (int) Generators::LIMITS[ $field ] : 0;

		if ( $limit > 0 && mb_strlen( $text, 'UTF-8' ) > $limit ) {
			return trim( mb_substr( $text, 0, $limit, 'UTF-8' ) );
		}

		return $text;
	}

	/**
	 * A value in a form two of them can be compared in, sets as sets.
	 *
	 * @param mixed $value Stored or recorded value.
	 * @return mixed
	 */
	private static function comparable( $value ) {
		return is_array( $value ) ? self::as_set( $value ) : $value;
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
