<?php
/**
 * Property 18: No enquiry workflow data reaches FluentCRM.
 *
 * Feature: enquiry-data-layer, Property 18: For any enquiry and any sequence of
 * operations upon it (creation by either path, status transitions, notes, field
 * corrections, conversion, duplication, auto-closure), the values written to
 * FluentCRM are confined to `first_name`, `last_name`, `email`, `phone`, list
 * membership and tags: no enquiry status, candidate date, `event_type`,
 * `site_exclusivity` or `message` value is written to any subscriber field or
 * custom field.
 *
 * **Validates: Requirements 6.8, 6.9**
 *
 * Six things about how the property is instantiated are worth stating plainly:
 *
 * - **The CRM is the whole observation surface.** Every assertion is made
 *   against `tests/fakes/FakeCrm.php`, which records each `createOrUpdate`, each
 *   list attachment and each tag attachment. Confinement is asserted three ways
 *   over that record, because a leak can take three shapes: an *extra key* in
 *   the contact data (`status`, a custom field), a *workflow value stuffed into a
 *   permitted key* (`last_name` carrying "Lovelace (contacted)"), or a *workflow
 *   value used as a tag* (tagging the contact with its `event_type`, which is
 *   exactly the kind of thing a CRM integration is tempted to do). The third is
 *   why list and tag membership is checked against the configured identifiers
 *   rather than merely counted.
 * - **Contact values and workflow values are drawn to be distinguishable.** The
 *   claim "no `message` value is written" is only checkable if a `message` value
 *   could not have arrived legitimately as a `first_name`. Every workflow value
 *   this test produces therefore carries the self::SENTINEL prefix, and the four
 *   contact fields are drawn from pools that contain neither that prefix, nor any
 *   of the six status names, nor a date. The pools still carry unicode, an
 *   apostrophe, values at exactly the stored capacity and the adversarial token
 *   set, so nothing about the confinement question is narrowed by the choice.
 * - **An operation is the code that performs it, not a stand-in.** Status
 *   transitions go through `Lifecycle`, notes through `NoteService`, corrections
 *   through `EnquiryStore::update()` followed by `ContactLinker::relink()`,
 *   duplication through `EnquiryStore::duplicate()` followed by
 *   `ContactLinker::link()`, and auto-closure through `AutoCloseJob::run()`.
 *   Creation goes through `EnquiryCreator::create()`, the shared path both
 *   creation routes reach the store by, so "creation by either path" is covered
 *   at the layer where the CRM call actually happens. Conversion is the one
 *   composite: `BookingCreator` is a later task, so conversion is performed as
 *   what it consists of at this layer — the transition to `converted`, the
 *   `booking_id` write and the `booking_linked` history entry. Should a future
 *   booking path call FluentCRM, this test would not see it; the booking
 *   properties own that surface.
 * - **Confinement is asserted after every operation, not once at the end.** A
 *   sequence that leaked on its third operation and then overwrote the contact on
 *   its fourth would pass an end-state check. Asserting per operation also names
 *   the operation that leaked in the failure message.
 * - **Staging is drawn rather than fixed.** In staging mode the linker prefixes
 *   the first name and adds the `test-record` tag (Requirements 6.10, 6.11), so
 *   the permitted tag set depends on the mode. Fixing the mode would leave one of
 *   the two permitted tag sets unexercised.
 * - **The generated scenario is a handful of integers, decoded.** Eris shrinks a
 *   composite generator by materialising the cartesian product of every leaf's
 *   options at once, so a scenario assembled from thirty independent generators
 *   cannot be shrunk at all — it exhausts memory before the shrinking time limit
 *   is ever consulted, and the run dies with no counterexample. The scenario is
 *   therefore drawn as thirteen plain values and decoded by
 *   self::decode(), which keeps the *input* space wide (each seed spreads over
 *   100,000 values through a small mixer) while keeping the *shrink* space small
 *   enough that a failure reports. The operation kinds stay one leaf each,
 *   because the sequence is the dimension a failure most needs shrunk.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix, because `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\AutoCloseJob;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\ContactLinker;
use MarthrownEnquiryHub\EnquiryCreator;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Lifecycle;
use MarthrownEnquiryHub\NoteService;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;
use MarthrownEnquiryHub\Validator;

/**
 * Class CrmWriteConfinementPropertyTest
 */
class CrmWriteConfinementPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehwc_';

	/**
	 * The instant every generated sequence starts from.
	 */
	const START = '2025-06-01 09:00:00';

	/**
	 * Prefix every generated workflow value carries.
	 *
	 * The whole detection mechanism: a message, a term value or a note body that
	 * reached FluentCRM would carry this, and no value the linker is permitted to
	 * write can. Checked as a substring rather than by equality, so a fragment of
	 * a workflow value concatenated into a contact field is caught too.
	 */
	const SENTINEL = 'MEH-WORKFLOW-';

	/**
	 * The source recorded against every created enquiry.
	 */
	const SOURCE = 'webhook:confinement-fixture';

	/**
	 * Operations per generated sequence.
	 *
	 * Fixed rather than drawn, because the length would cost a shrink leaf and
	 * buy little: five operations already interleave every kind often enough that
	 * a leak on any one of them surfaces.
	 */
	const SEQUENCE_LENGTH = 5;

	/**
	 * Upper bound of each decoded seed.
	 */
	const SEED_MAX = 99999;

	/**
	 * The only FluentCRM calls the linker may make.
	 *
	 * `api` is the module resolution, the other three are the upsert and the two
	 * membership attachments. Any other API method would either appear here or,
	 * being absent from the fake's surface, fail the link outright.
	 *
	 * @var string[]
	 */
	const PERMITTED_CALLS = array( 'api', 'createOrUpdate', 'attachLists', 'attachTags' );

	/**
	 * The kinds of operation a generated sequence draws from.
	 *
	 * @var string[]
	 */
	const OPERATIONS = array(
		'status',
		'note',
		'correct_workflow',
		'correct_contact',
		'duplicate',
		'convert',
		'retry',
		'auto_close',
	);

	/**
	 * Curated `first_name` and `last_name` values.
	 *
	 * Unicode, an apostrophe and the adversarial tokens are all here; what none
	 * of them holds is the sentinel prefix, a status name or a date, which is what
	 * makes a leak into a name field detectable.
	 *
	 * @var string[]
	 */
	const NAMES = array(
		'Ada',
		"O'Brien",
		'Zoë Ó Séaghdha',
		"Robert'); DROP TABLE wp_meh_enquiries; --",
		'100% _ %s %d',
		'C:\\path\\to\\nowhere',
	);

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The installed CRM fake.
	 *
	 * @var FakeCrm|null
	 */
	private $crm = null;

	/**
	 * Load the classes under test.
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
		require_once MEH_INCLUDES_DIR . 'class-enquiry-validator.php';
		require_once MEH_INCLUDES_DIR . 'class-field-mapper.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-lifecycle.php';
		require_once MEH_INCLUDES_DIR . 'class-note-service.php';
		require_once MEH_INCLUDES_DIR . 'class-auto-close-job.php';
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

		// This test's `event_type`/`site_exclusivity` values are sentinel-marked
		// fixture strings, not drawn from a fixed vocabulary, so both taxonomies
		// are left unconstrained for the length of this test.
		foreach ( array( 'event_type', 'site_exclusivity' ) as $taxonomy ) {
			add_filter( 'meh_enquiry_terms_' . $taxonomy, '__return_empty_array' );
		}

		$this->crm = FakeCrm::install();
	}

	public function tear_down() {
		global $wpdb;

		FakeCrm::uninstall();
		$this->crm = null;

		remove_filter( 'meh_is_staging', '__return_true' );
		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 18: No enquiry workflow data reaches
	 * FluentCRM.
	 *
	 * **Validates: Requirements 6.8, 6.9**
	 *
	 * @eris-shrink 20
	 */
	public function test_no_enquiry_workflow_data_reaches_fluentcrm() {
		$this->limitTo( Iterations::count( 40 ) )
			->forAll( self::drawn() )
			->then(
				function ( array $drawn ) {
					$scenario = self::decode( $drawn );

					$this->prepare( $scenario );

					$forbidden = self::forbidden( $scenario );

					$outcome = EnquiryCreator::create(
						$scenario['fields'],
						Validator::PROFILE_WEBHOOK,
						self::SOURCE,
						self::START,
						0
					);

					$this->assertTrue(
						$outcome['created'],
						'The generated submission should be valid: ' . wp_json_encode( $outcome['errors'] )
					);
					$this->assertSame(
						ContactLinker::STATE_SYNCED,
						$outcome['crm_sync_state'],
						'An answering CRM should leave the enquiry synced, so the upsert really happened.'
					);

					$id = (int) $outcome['enquiry_id'];

					$this->assert_confined( $scenario, $forbidden, 'creation' );

					foreach ( $scenario['operations'] as $index => $operation ) {
						$this->assertTrue(
							Clock::freeze( Clock::offset( $operation['gap'] ) ),
							'The clock should advance under the test harness.'
						);

						$this->apply( $operation, $id, $index, $scenario );

						$this->assert_confined(
							$scenario,
							$forbidden,
							sprintf( 'operation %d (%s)', $index + 1, $operation['kind'] )
						);
					}
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Operations
	 * ------------------------------------------------------------------ */

	/**
	 * Perform one operation against the enquiry.
	 *
	 * Each branch performs the operation through the component that owns it, so
	 * the CRM traffic observed afterwards is whatever that component really
	 * causes.
	 *
	 * @param array $operation Decoded operation.
	 * @param int   $id        Enquiry the sequence is about.
	 * @param int   $index     Position in the sequence, used for note bodies.
	 * @param array $scenario  The whole decoded scenario.
	 * @return void
	 */
	private function apply( array $operation, $id, $index, array $scenario ) {
		switch ( $operation['kind'] ) {
			case 'status':
				$this->transition( $id, $operation['choice'] );
				break;

			case 'note':
				$note = NoteService::add( $id, self::note_body( $index ), 0 );
				$this->assertIsInt( $note, 'A generated note body should be accepted.' );
				break;

			case 'correct_workflow':
				$this->correct_workflow( $id, $scenario['workflow_edit'] );
				break;

			case 'correct_contact':
				$this->correct_contact( $id, $scenario['contact_edit'] );
				break;

			case 'duplicate':
				$copy = EnquiryStore::duplicate( $id );
				$this->assertIsInt( $copy, 'Duplicating a stored enquiry should succeed.' );
				ContactLinker::link( $copy, 0 );
				break;

			case 'convert':
				$this->convert( $id, $operation['choice'] );
				break;

			case 'retry':
				ContactLinker::retry( $id, 0 );
				break;

			case 'auto_close':
				// Settled enquiries older than the interval close; the rest are
				// left alone. Either way the job must reach no CRM.
				AutoCloseJob::run( Clock::mysql( Clock::offset( 60 * DAY_IN_SECONDS ) ) );
				break;
		}
	}

	/**
	 * Move the enquiry to one of the statuses permitted from where it stands.
	 *
	 * A terminal `closed` enquiry has nowhere to go, which is a legitimate step
	 * in a sequence rather than a reason to redraw it.
	 *
	 * @param int $id     Enquiry identifier.
	 * @param int $choice Decoded selector among the permitted statuses.
	 * @return void
	 */
	private function transition( $id, $choice ) {
		$enquiry = EnquiryStore::find( $id );

		$this->assertIsArray( $enquiry, 'The enquiry should be readable.' );

		$allowed = Lifecycle::allowed_from( $enquiry['status'] );

		if ( ! $allowed ) {
			return;
		}

		$to     = $allowed[ (int) $choice % count( $allowed ) ];
		$result = Lifecycle::transition( $id, $to, 0 );

		$this->assertIsArray( $result, 'A permitted transition should succeed.' );
	}

	/**
	 * Correct the workflow fields: the message, the guest count, the candidate
	 * dates and both taxonomies.
	 *
	 * Followed by `relink()` exactly as `EnquiryEditor` will, because the claim is
	 * about what the correction path does to the CRM, not about what the store
	 * does on its own.
	 *
	 * @param int   $id   Enquiry identifier.
	 * @param array $edit Decoded replacement values.
	 * @return void
	 */
	private function correct_workflow( $id, array $edit ) {
		$updated = EnquiryStore::update(
			$id,
			array(
				'message'      => $edit['message'],
				'total_guests' => $edit['total_guests'],
			),
			$edit['date_ranges'],
			array(
				'event_type'       => $edit['event_type'],
				'site_exclusivity' => $edit['site_exclusivity'],
			)
		);

		$this->assertIsArray( $updated, 'A workflow correction should be accepted by the store.' );

		ContactLinker::relink( $id, $updated['changed'], 0 );
	}

	/**
	 * Correct the contact fields, which is the one correction that legitimately
	 * reaches FluentCRM.
	 *
	 * Included precisely because it does: the confinement claim has to hold on
	 * the path that writes, not only on the paths that stay silent.
	 *
	 * @param int   $id   Enquiry identifier.
	 * @param array $edit Decoded replacement values.
	 * @return void
	 */
	private function correct_contact( $id, array $edit ) {
		$updated = EnquiryStore::update(
			$id,
			array(
				'first_name' => $edit['first_name'],
				'last_name'  => $edit['last_name'],
				'phone'      => $edit['phone'],
				'email'      => $edit['email'],
			)
		);

		$this->assertIsArray( $updated, 'A contact correction should be accepted by the store.' );

		ContactLinker::relink( $id, $updated['changed'], 0 );
	}

	/**
	 * Convert the enquiry, as conversion consists at this layer.
	 *
	 * The transition to `converted`, the `booking_id` write and the
	 * `booking_linked` history entry. When the enquiry has already settled or
	 * closed, the transition is not permitted and the booking link is all that
	 * happens, which is still an operation worth observing.
	 *
	 * @param int $id      Enquiry identifier.
	 * @param int $booking Decoded booking identifier.
	 * @return void
	 */
	private function convert( $id, $booking ) {
		$enquiry = EnquiryStore::find( $id );

		$this->assertIsArray( $enquiry, 'The enquiry should be readable.' );

		if ( Lifecycle::can( $enquiry['status'], 'converted' ) ) {
			$this->assertIsArray(
				Lifecycle::transition( $id, 'converted', 0 ),
				'A permitted conversion should succeed.'
			);
		}

		$booking = 1 + (int) $booking;

		$this->assertTrue(
			EnquiryStore::update_fields( $id, array( 'booking_id' => $booking ) ),
			'Recording the booking identifier should succeed.'
		);

		HistoryRecorder::record(
			$id,
			'booking_linked',
			sprintf( 'Linked to booking %d.', $booking ),
			array( 'booking_id' => $booking ),
			0
		);
	}

	/* ---------------------------------------------------------------------
	 * The confinement assertion
	 * ------------------------------------------------------------------ */

	/**
	 * Everything the property claims about what reached FluentCRM
	 * (Requirements 6.8, 6.9).
	 *
	 * Asserted over the whole recorded history of the fake rather than over the
	 * last call, so an operation cannot leak and be forgiven by a later
	 * overwrite.
	 *
	 * @param array  $scenario  The decoded scenario.
	 * @param array  $forbidden Workflow values that must appear nowhere.
	 * @param string $stage     What has just happened, for the failure message.
	 * @return void
	 */
	private function assert_confined( array $scenario, array $forbidden, $stage ) {
		$permitted_members = self::permitted_members( $scenario );

		foreach ( $this->crm->calls() as $call ) {
			$this->assertContains(
				$call['method'],
				self::PERMITTED_CALLS,
				sprintf( 'After %s, the linker should call no FluentCRM method beyond the upsert and its two attachments.', $stage )
			);
		}

		// A leak as an extra key: a status column, a custom field, anything.
		$this->assertSame(
			array(),
			array_values( array_diff( $this->crm->written_fields(), ContactLinker::LINKED_FIELDS ) ),
			sprintf( 'After %s, the contact data written should hold no field beyond the four linked ones.', $stage )
		);

		foreach ( $this->crm->create_or_update_calls() as $position => $data ) {
			foreach ( $data as $key => $value ) {
				$this->assertContains(
					$key,
					ContactLinker::LINKED_FIELDS,
					sprintf( 'After %s, upsert %d wrote the unexpected field `%s`.', $stage, $position + 1, $key )
				);
				$this->assert_carries_no_workflow_value(
					$value,
					$forbidden,
					sprintf( 'After %s, upsert %d wrote workflow data into `%s`', $stage, $position + 1, $key )
				);
			}
		}

		// A leak as membership: the event type as a tag, the status as a list.
		foreach ( array_merge( $this->crm->list_calls(), $this->crm->tag_calls() ) as $call ) {
			foreach ( $call['args']['values'] as $value ) {
				$this->assertContains(
					$value,
					$permitted_members,
					sprintf( 'After %s, the linker attached a list or tag beyond the configured ones.', $stage )
				);
			}
		}

		// The same three claims about the resting state of the contact universe,
		// which is what a later operation would have had to overwrite to hide a
		// leak from the call log.
		foreach ( $this->crm->contacts() as $email => $contact ) {
			foreach ( $contact['data'] as $key => $value ) {
				$this->assertContains(
					$key,
					ContactLinker::LINKED_FIELDS,
					sprintf( 'After %s, contact %s holds the unexpected field `%s`.', $stage, $email, $key )
				);
				$this->assert_carries_no_workflow_value(
					$value,
					$forbidden,
					sprintf( 'After %s, contact %s holds workflow data in `%s`', $stage, $email, $key )
				);
			}

			foreach ( array_merge( $contact['lists'], $contact['tags'] ) as $value ) {
				$this->assertContains(
					$value,
					$permitted_members,
					sprintf( 'After %s, contact %s belongs to a list or tag beyond the configured ones.', $stage, $email )
				);
			}
		}
	}

	/**
	 * One written value carries no workflow data.
	 *
	 * Two checks, because a leak can be whole or partial: the value must not hold
	 * any forbidden value, and must not hold the sentinel prefix every generated
	 * workflow value carries.
	 *
	 * @param mixed  $value     Value written to FluentCRM.
	 * @param array  $forbidden Workflow values that must appear nowhere.
	 * @param string $message   Failure message prefix.
	 * @return void
	 */
	private function assert_carries_no_workflow_value( $value, array $forbidden, $message ) {
		$value = (string) $value;

		$this->assertStringNotContainsString(
			self::SENTINEL,
			$value,
			$message . ': it carries the workflow sentinel.'
		);

		foreach ( $forbidden as $workflow ) {
			$this->assertStringNotContainsString(
				$workflow,
				$value,
				sprintf( '%s: it carries the workflow value `%s`.', $message, $workflow )
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Scenario helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Put the site and the fake into the state one decoded scenario names.
	 *
	 * @param array $scenario The decoded scenario.
	 * @return void
	 */
	private function prepare( array $scenario ) {
		$this->clear();

		$this->crm->reset()->will_succeed();

		update_option( 'meh_enquiry_list', (int) $scenario['list_id'] );
		update_option( 'meh_enquiry_tag', (int) $scenario['tag_id'] );

		remove_filter( 'meh_is_staging', '__return_true' );

		if ( $scenario['staging'] ) {
			add_filter( 'meh_is_staging', '__return_true' );
		}

		$this->assertTrue( Clock::freeze( self::START ), 'The clock should freeze under the test harness.' );
	}

	/**
	 * Every workflow value that must appear nowhere in FluentCRM.
	 *
	 * The six status names, plus every bound of every candidate range the enquiry
	 * ever holds.
	 * Messages, term values and note bodies are covered by the sentinel prefix
	 * instead, which catches a fragment as readily as a whole value.
	 *
	 * @param array $scenario The decoded scenario.
	 * @return string[]
	 */
	private static function forbidden( array $scenario ) {
		$values = Lifecycle::STATUSES;

		foreach ( array_merge( $scenario['fields']['date_ranges'], $scenario['workflow_edit']['date_ranges'] ) as $range ) {
			$values[] = (string) $range['start'];
			$values[] = (string) $range['end'];
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * The list and tag values the linker is permitted to attach
	 * (Requirements 6.4, 6.10, 6.11).
	 *
	 * @param array $scenario The decoded scenario.
	 * @return array<int,int|string>
	 */
	private static function permitted_members( array $scenario ) {
		$permitted = array( (int) $scenario['list_id'], (int) $scenario['tag_id'] );

		if ( $scenario['staging'] ) {
			$permitted[] = ContactLinker::TEST_TAG;
		}

		return $permitted;
	}

	/**
	 * The body of the note added at one position in the sequence.
	 *
	 * @param int $index Position in the sequence.
	 * @return string
	 */
	private static function note_body( $index ) {
		return sprintf( '%sNOTE-%d: chased by phone, no answer.', self::SENTINEL, (int) $index );
	}

	/* ---------------------------------------------------------------------
	 * The generator: a few integers
	 * ------------------------------------------------------------------ */

	/**
	 * What Eris draws: five seeds, the configured membership, the environment
	 * mode, and one operation kind per position in the sequence.
	 *
	 * Every member is a leaf whose shrink offers exactly one alternative, which
	 * is what keeps the shrink cartesian product to 2^self::LEAVES rather than to
	 * the product of a tree of composite generators.
	 *
	 * @return \Eris\Generator
	 */
	protected static function drawn() {
		$spec = array(
			'contact'  => \Eris\Generators::choose( 0, self::SEED_MAX ),
			'workflow' => \Eris\Generators::choose( 0, self::SEED_MAX ),
			'dates'    => \Eris\Generators::choose( 0, self::SEED_MAX ),
			'edit'     => \Eris\Generators::choose( 0, self::SEED_MAX ),
			'gaps'     => \Eris\Generators::choose( 0, self::SEED_MAX ),
			'list_id'  => \Eris\Generators::choose( 1, 499 ),
			'tag_id'   => \Eris\Generators::choose( 500, 999 ),
			'staging'  => \Eris\Generators::elements( array( true, false ) ),
		);

		for ( $position = 0; $position < self::SEQUENCE_LENGTH; $position++ ) {
			$spec[ 'operation_' . $position ] = \Eris\Generators::elements( self::OPERATIONS );
		}

		return \Eris\Generators::associative( $spec );
	}

	/**
	 * Turn the drawn integers into the scenario the test works with.
	 *
	 * Pure and total: every seed decodes to a valid nine-field submission, a
	 * valid pair of corrections and a well-formed operation sequence, so no draw
	 * is ever discarded.
	 *
	 * @param array $drawn What Eris drew.
	 * @return array
	 */
	private static function decode( array $drawn ) {
		$ranges      = self::decode_ranges( $drawn['dates'] );
		$edit_ranges = self::decode_ranges( $drawn['edit'] );

		$scenario = array(
			'fields'        => array_merge(
				self::decode_contact( $drawn['contact'] ),
				self::decode_workflow( $drawn['workflow'] ),
				array( 'date_ranges' => $ranges )
			),
			'contact_edit'  => self::decode_contact( $drawn['edit'] ),
			'workflow_edit' => array_merge(
				self::decode_workflow( $drawn['edit'] ),
				array( 'date_ranges' => $edit_ranges )
			),
			'list_id'       => (int) $drawn['list_id'],
			'tag_id'        => (int) $drawn['tag_id'],
			'staging'       => (bool) $drawn['staging'],
			'operations'    => array(),
		);

		$pick = self::picker( $drawn['gaps'] );

		for ( $position = 0; $position < self::SEQUENCE_LENGTH; $position++ ) {
			$scenario['operations'][] = array(
				'kind'   => (string) $drawn[ 'operation_' . $position ],
				'choice' => $pick( 97 ),
				// Zero is a deliberate draw, not a rarity: two operations inside
				// one second is the ordinary case for a fast sequence of writes.
				'gap'    => 0 === $pick( 3 ) ? 0 : $pick( 3600 ),
			);
		}

		return $scenario;
	}

	/**
	 * The four contact fields one seed names.
	 *
	 * @param int $seed Drawn seed.
	 * @return array{first_name:string,last_name:string,email:string,phone:string}
	 */
	private static function decode_contact( $seed ) {
		$pick  = self::picker( $seed );
		$names = self::names();

		return array(
			'first_name' => $names[ $pick( count( $names ) ) ],
			'last_name'  => $names[ $pick( count( $names ) ) ],
			'email'      => self::email( $pick( 3 ), $pick( 100 ) ),
			'phone'      => self::phone( $pick( 3 ), $pick( 1000000 ) ),
		);
	}

	/**
	 * The workflow fields one seed names, all sentinel-marked.
	 *
	 * `total_guests` reaches both ends of its permitted 1 to 10000 range
	 * (Requirement 1.18) as explicit draws rather than by luck.
	 *
	 * @param int $seed Drawn seed.
	 * @return array{message:string,total_guests:int,event_type:string[],site_exclusivity:string[]}
	 */
	private static function decode_workflow( $seed ) {
		$pick    = self::picker( $seed );
		$variant = $pick( 2 );
		$number  = $pick( 400 );
		$guests  = $pick( 4 );

		$tail = 0 === $variant
			? sprintf( 'a summer weekend for around %d guests.', 2 + $number )
			: '100% _ %s %d \'"\\ -- ;';

		return array(
			'message'          => sprintf( '%sMESSAGE-%d: %s', self::SENTINEL, $number, $tail ),
			'total_guests'     => self::guests( $guests, $pick( Generators::TOTAL_GUESTS_MAX ) ),
			'event_type'       => self::terms( 'EVENT', 1 + $pick( 3 ), $pick( 999 ) ),
			'site_exclusivity' => self::terms( 'EXCLUSIVITY', 1 + $pick( 3 ), $pick( 999 ) ),
		);
	}

	/**
	 * A candidate date set of one to four ascending, distinct dates.
	 *
	 * @param int $seed Drawn seed.
	 * @return string[]
	 */
	private static function decode_ranges( $seed ) {
		$pick   = self::picker( $seed );
		$count  = 1 + $pick( Generators::RANGES_MAX );
		$offset = 0;
		$ranges = array();

		for ( $index = 0; $index < $count; $index++ ) {
			// A strictly positive step, and the span added on afterwards, keep
			// every range distinct and clear of the one before it.
			$offset += 1 + $pick( 45 );
			$span    = $pick( 5 );

			$ranges[] = array(
				'start' => Generators::date_at( $offset ),
				'end'   => Generators::date_at( $offset + $span ),
			);

			$offset += $span;
		}

		return $ranges;
	}

	/**
	 * A deterministic stream of small numbers from one seed.
	 *
	 * A plain linear congruential mixer, so a seed spreads over the whole space
	 * of each field it feeds and successive draws from one seed are unrelated.
	 * Deterministic, so a reported seed reproduces its scenario exactly.
	 *
	 * @param int $seed Drawn seed.
	 * @return callable( int $modulus ): int
	 */
	private static function picker( $seed ) {
		$state = (int) $seed;

		return function ( $modulus ) use ( &$state ) {
			$modulus = max( 1, (int) $modulus );
			$state   = ( $state * 1103515245 + 12345 ) & 0x7fffffff;

			return intdiv( $state, 65536 ) % $modulus;
		};
	}

	/**
	 * The `first_name` and `last_name` pool: the curated values plus two at
	 * exactly the stored capacity, one ASCII and one multibyte.
	 *
	 * @return string[]
	 */
	private static function names() {
		return array_merge(
			self::NAMES,
			array(
				Generators::capacity_value( 'first_name' ),
				Generators::capacity_value( 'first_name', true ),
			)
		);
	}

	/**
	 * An email address carrying no sentinel, status name or date.
	 *
	 * @param int $shape  0, 1 or 2.
	 * @param int $number Distinguishing number for the plain shape.
	 * @return string
	 */
	private static function email( $shape, $number ) {
		if ( 1 === (int) $shape ) {
			return "o'brien@example.com";
		}

		if ( 2 === (int) $shape ) {
			return Generators::capacity_value( 'email' );
		}

		return sprintf( 'ada+%d@example.com', (int) $number );
	}

	/**
	 * A phone number carrying at least one digit (Requirement 3.11) and no
	 * workflow value.
	 *
	 * @param int $shape  0, 1 or 2.
	 * @param int $number Distinguishing number for the plain shape.
	 * @return string
	 */
	private static function phone( $shape, $number ) {
		if ( 1 === (int) $shape ) {
			return '+44 131 496 0000';
		}

		if ( 2 === (int) $shape ) {
			return Generators::capacity_value( 'phone' );
		}

		return '07700 ' . str_pad( (string) (int) $number, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * A guest count inside the permitted range, with both ends reachable.
	 *
	 * @param int $shape 0 draws the floor, 1 the ceiling, anything else the range.
	 * @param int $value Value for the range case.
	 * @return int
	 */
	private static function guests( $shape, $value ) {
		if ( 0 === (int) $shape ) {
			return Generators::TOTAL_GUESTS_MIN;
		}

		if ( 1 === (int) $shape ) {
			return Generators::TOTAL_GUESTS_MAX;
		}

		return Generators::TOTAL_GUESTS_MIN + ( (int) $value % Generators::TOTAL_GUESTS_MAX );
	}

	/**
	 * A sentinel-marked term set.
	 *
	 * The plugin ships no vocabulary of its own and this test installs none, so
	 * `Validator::allowed_terms()` is unconstrained and these values are accepted
	 * exactly as a site's configured vocabulary would be.
	 *
	 * @param string $kind   'EVENT' or 'EXCLUSIVITY'.
	 * @param int    $count  Values in the set.
	 * @param int    $suffix Distinguishing suffix.
	 * @return string[]
	 */
	private static function terms( $kind, $count, $suffix ) {
		$terms = array();

		for ( $index = 0; $index < (int) $count; $index++ ) {
			$terms[] = sprintf( '%s%s-%d-%d', self::SENTINEL, $kind, $index, (int) $suffix );
		}

		return $terms;
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Empty every Enquiry Store table between iterations.
	 *
	 * `DELETE` rather than `TRUNCATE`, so identifiers keep climbing and a stale
	 * identifier can never be mistaken for a fresh one.
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
