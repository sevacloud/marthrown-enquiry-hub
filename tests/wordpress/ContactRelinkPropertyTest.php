<?php
/**
 * Property 42: An edit re-links the contact exactly when identity changed.
 *
 * Feature: enquiry-data-layer, Property 42: For any applied enquiry edit
 * request, any subset of fields it changes, and any contact-linkage outcome:
 *
 * - the FluentCRM upsert is performed exactly when the changed set intersects
 *   `first_name`, `last_name`, `email` and `phone`, and is performed with the
 *   changed values under every criterion of Requirement 6;
 * - when the changed set does not intersect those four, `fluentcrm_subscriber_id`
 *   and `crm_sync_state` are both unchanged and no FluentCRM call is made;
 * - when the changed set includes `email` and the upsert returns a subscriber
 *   identifier differing from the stored `fluentcrm_subscriber_id`, the stored
 *   value is replaced by the returned identifier and `crm_sync_state` is set to
 *   `synced`; when the returned identifier equals the stored one, the stored
 *   value and `synced` state are equivalent to their prior state;
 * - for any linkage failure mode (FluentCRM absent, the API throwing, or a
 *   response carrying no subscriber identifier), every changed field value is
 *   retained, `crm_sync_state` is `pending`, and `fluentcrm_subscriber_id` holds
 *   the value it held before the edit rather than being cleared;
 * - no enquiry status, candidate date, `event_type`, `site_exclusivity` or
 *   `message` value is written to FluentCRM by the re-link, consistent with
 *   Property 18.
 *
 * **Validates: Requirements 19.14, 19.15, 19.16, 19.17**
 *
 * How the property is instantiated, and why each choice was made:
 *
 * - **The edit goes through `EnquiryEditor::apply()`, not through
 *   `relink()` directly.** The claim is about what an *applied edit* does to the
 *   CRM, and the `changed` map the linker keys off is produced by
 *   `EnquiryStore::update()` inside that path. Calling `relink()` with a
 *   hand-written map would test the linker against a map no edit could produce,
 *   which is precisely the disagreement the design put the map at the centre to
 *   rule out.
 * - **The changed set is chosen, then verified, then used.** Each case names the
 *   fields whose submitted value differs from the stored one, and the test
 *   asserts that the `changed` map the edit reported holds exactly those keys
 *   before making any claim about the CRM. Without that check, a store that
 *   under-reported a change would make the CRM claims vacuously true.
 * - **The four categories of changed set are drawn explicitly.** Nothing
 *   changed, workflow fields only, contact fields without `email`, and `email`
 *   itself: the first two are the "no call at all" half of the property, the
 *   last two the "upsert performed" half, and drawing them as a kind rather than
 *   hoping a per-field coin toss lands on each keeps every branch exercised in
 *   every run.
 * - **Base values and replacement values come from disjoint pools.** A "changed"
 *   field has to actually change after the Validator has stripped and truncated
 *   it, and a value at exactly the stored capacity cannot be changed by
 *   appending to it — the truncation would put it back. Disjoint pools make
 *   "differs" true by construction rather than by luck, while still carrying
 *   unicode, an apostrophe, the adversarial token set and capacity-length values.
 * - **All three failure modes are drawn, and so is the prior link state.**
 *   Requirement 19.16's "rather than being cleared" only says something when the
 *   enquiry held a subscriber identifier before the edit, so the fixture is
 *   created against both an answering CRM and an absent one, and the identifier
 *   held before the edit is what the assertion compares against.
 * - **The subscriber identifier the corrected address resolves to is pinned.**
 *   Requirement 19.15 is about the returned identifier differing from the stored
 *   one, so the fake is told which identifier each address resolves to, and both
 *   the differing and the equal case are drawn.
 * - **Confinement is re-asserted here.** Requirement 6's criteria are part of
 *   clause one, so every workflow value this test generates carries the
 *   self::SENTINEL prefix and no value written to FluentCRM may hold it, any
 *   candidate date, or any status name. Property 18 owns that claim across whole
 *   operation sequences; this is the same claim narrowed to the re-link.
 * - **The case is a handful of integers, decoded.** Eris shrinks a composite
 *   generator by materialising the cartesian product of every leaf's
 *   alternatives at once, so a case assembled from thirty generators exhausts
 *   memory instead of reporting a counterexample. The case is drawn as eleven
 *   plain values through `unshrunk()` and decoded deterministically, so a failure
 *   reports the case exactly as generated alongside the `ERIS_SEED` line that
 *   reproduces it.
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
use MarthrownEnquiryHub\ContactLinker;
use MarthrownEnquiryHub\EnquiryCreator;
use MarthrownEnquiryHub\EnquiryEditor;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\Lifecycle;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\StagingMarker;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;
use MarthrownEnquiryHub\Validator;

/**
 * Class ContactRelinkPropertyTest
 */
class ContactRelinkPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehrl_';

	/**
	 * The instant every fixture enquiry is created at.
	 */
	const CREATED_AT = '2025-05-01 09:00:00';

	/**
	 * The instant every edit request arrives at.
	 */
	const EDITED_AT = '2025-05-02 14:15:00';

	/**
	 * The source recorded against every fixture enquiry.
	 */
	const SOURCE = 'webhook:relink-fixture';

	/**
	 * Prefix every generated workflow value carries.
	 *
	 * The detection mechanism behind clause five: a message or a term value that
	 * reached FluentCRM would carry this, and no value the linker is permitted to
	 * write can.
	 */
	const SENTINEL = 'MEH42-';

	/**
	 * Upper bound of each decoded seed.
	 */
	const SEED_MAX = 99999;

	/**
	 * The four shapes a changed set can take.
	 *
	 * `none` and `workflow` are the halves of the property where no FluentCRM
	 * call may happen; `contact` and `email` are the halves where the upsert must.
	 *
	 * @var string[]
	 */
	const KINDS = array( 'none', 'workflow', 'contact', 'email' );

	/**
	 * The linkage outcomes, as FakeCrm configuration calls.
	 *
	 * `ok` is the success branch; the other three are the three separate guards in
	 * `ContactLinker` — the existence check, the `try`/`catch`, and the identifier
	 * check — that Requirement 19.16 covers.
	 *
	 * @var array<string,string>
	 */
	const MODES = array(
		'ok'          => 'will_succeed',
		'unavailable' => 'will_be_unavailable',
		'throwing'    => 'will_throw',
		'no_id'       => 'will_return_no_subscriber_id',
	);

	/**
	 * Linked contact fields other than `email`.
	 *
	 * @var string[]
	 */
	const CONTACT_FIELDS = array( 'first_name', 'last_name', 'phone' );

	/**
	 * The editable fields whose change must reach FluentCRM in no way at all
	 * (Requirement 19.17).
	 *
	 * @var string[]
	 */
	const WORKFLOW_FIELDS = array(
		'total_guests',
		'message',
		'selected_dates',
		'event_type',
		'site_exclusivity',
	);

	/**
	 * The nine fields an edit may carry.
	 *
	 * @var string[]
	 */
	const ALL_FIELDS = array(
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
	 * `class-enquiry-creator.php` before `class-rest-enquiries.php`, and both
	 * before anything that resolves their constants as its class body evaluates.
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
	 * Feature: enquiry-data-layer, Property 42: An edit re-links the contact
	 * exactly when identity changed.
	 *
	 * **Validates: Requirements 19.14, 19.15, 19.16, 19.17**
	 */
	public function test_an_edit_relinks_the_contact_exactly_when_identity_changed() {
		$this->limitTo( Iterations::count( 60 ) )
			->forAll( self::unshrunk( self::drawn() ) )
			->then(
				function ( array $drawn ) {
					$this->check_relink( self::decode( $drawn ) );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * The claim
	 * ------------------------------------------------------------------ */

	/**
	 * One enquiry, one applied edit, and everything the property claims about it.
	 *
	 * @param array $case The decoded case.
	 * @return void
	 */
	private function check_relink( array $case ) {
		$label = self::label( $case );

		$id     = $this->seed( $case );
		$before = EnquiryStore::find( $id );

		$this->assertIsArray( $before, 'The fixture enquiry should be readable. ' . $label );

		$previous_id    = (int) $before['fluentcrm_subscriber_id'];
		$previous_state = (string) $before['crm_sync_state'];

		$this->arm( $case );

		$expected = self::expected_values( $case );
		$outcome  = EnquiryEditor::apply( $id, self::submitted( $case, $before ), $case['actor'] );

		$this->assertNotWPError( $outcome, 'The generated edit should be applied. ' . $label );

		// The changed set the CRM decision is made from is the one the store
		// reported, so it is checked before anything is claimed about the CRM.
		$this->assertSame(
			self::sorted( $case['changes'] ),
			self::sorted( array_keys( (array) $outcome['changed'] ) ),
			'The edit should report exactly the fields whose stored value changed. ' . $label
		);

		$after = EnquiryStore::find( $id );

		$this->assertIsArray( $after, 'The enquiry should survive the edit. ' . $label );

		// Requirement 19.16's first half, asserted for every outcome rather than
		// only for the failures: an edit never loses the values it applied.
		$this->assert_values_retained( $expected, $after, $label );

		if ( array() === array_intersect( $case['changes'], ContactLinker::LINKED_FIELDS ) ) {
			$this->assert_untouched( $case, $outcome, $after, $previous_id, $previous_state, $label );

			return;
		}

		$this->assert_upserted( $case, $expected, $label );

		if ( 'ok' === $case['mode'] ) {
			$this->assert_synced( $case, $expected, $outcome, $after, $previous_id, $label );

			return;
		}

		$this->assert_pending( $outcome, $after, $previous_id, $label );
	}

	/**
	 * Requirement 19.17: nothing linked changed, so the CRM hears nothing and
	 * neither CRM column moves.
	 *
	 * @param array  $case           The decoded case.
	 * @param array  $outcome        What the edit returned.
	 * @param array  $after          The enquiry after the edit.
	 * @param int    $previous_id    Subscriber identifier held before the edit.
	 * @param string $previous_state `crm_sync_state` held before the edit.
	 * @param string $label          Case description for failure messages.
	 * @return void
	 */
	private function assert_untouched( array $case, array $outcome, array $after, $previous_id, $previous_state, $label ) {
		$this->assertSame(
			0,
			$this->crm->call_count(),
			'An edit changing no linked field should make no FluentCRM call at all. ' . $label
		);
		$this->assertSame(
			$previous_id,
			(int) $after['fluentcrm_subscriber_id'],
			'An edit changing no linked field should leave fluentcrm_subscriber_id alone. ' . $label
		);
		$this->assertSame(
			$previous_state,
			(string) $after['crm_sync_state'],
			'An edit changing no linked field should leave crm_sync_state alone. ' . $label
		);
		$this->assertSame(
			$previous_state,
			(string) $outcome['crm_sync_state'],
			'The reported state should be the state the enquiry still holds. ' . $label
		);

		$this->assert_confined( $case, $label );
	}

	/**
	 * Requirement 19.14: the upsert is performed, once, with the corrected values
	 * and under every criterion of Requirement 6.
	 *
	 * An absent FluentCRM cannot be upserted against, so what is asserted there is
	 * that the linker reached for it: the module resolution is the call every
	 * other mode also makes, and the one an edit that decided not to re-link would
	 * never make.
	 *
	 * @param array  $case     The decoded case.
	 * @param array  $expected The values the enquiry holds after the edit.
	 * @param string $label    Case description for failure messages.
	 * @return void
	 */
	private function assert_upserted( array $case, array $expected, $label ) {
		if ( 'unavailable' === $case['mode'] ) {
			$this->assertSame(
				0,
				$this->crm->call_count( 'createOrUpdate' ),
				'An absent FluentCRM cannot be upserted against. ' . $label
			);
			$this->assertGreaterThan(
				0,
				$this->crm->call_count( 'api' ),
				'An edit changing a linked field should reach for the contacts API. ' . $label
			);

			$this->assert_confined( $case, $label );

			return;
		}

		$calls = $this->crm->create_or_update_calls();

		$this->assertCount(
			1,
			$calls,
			'An edit changing a linked field should upsert the contact exactly once. ' . $label
		);
		$this->assertSame(
			self::expected_contact( $case, $expected ),
			$calls[0],
			'The upsert should carry the corrected contact values and nothing else. ' . $label
		);

		$this->assert_confined( $case, $label );
	}

	/**
	 * Requirement 19.15: the successful branch, including the identifier
	 * replacement a corrected address can cause.
	 *
	 * @param array  $case        The decoded case.
	 * @param array  $expected    The values the enquiry holds after the edit.
	 * @param array  $outcome     What the edit returned.
	 * @param array  $after       The enquiry after the edit.
	 * @param int    $previous_id Subscriber identifier held before the edit.
	 * @param string $label       Case description for failure messages.
	 * @return void
	 */
	private function assert_synced( array $case, array $expected, array $outcome, array $after, $previous_id, $label ) {
		$email    = (string) $expected['email'];
		$resolved = $this->crm->subscriber_id_for( $email );
		$stored   = (int) $after['fluentcrm_subscriber_id'];

		$this->assertSame(
			ContactLinker::STATE_SYNCED,
			(string) $after['crm_sync_state'],
			'A successful re-link marks the enquiry synced. ' . $label
		);
		$this->assertSame(
			ContactLinker::STATE_SYNCED,
			(string) $outcome['crm_sync_state'],
			'The reported state should be the state the enquiry holds. ' . $label
		);
		$this->assertSame(
			$resolved,
			$stored,
			'The stored subscriber identifier should be the one the upsert returned. ' . $label
		);

		if ( in_array( 'email', $case['changes'], true ) ) {
			if ( $resolved === $previous_id ) {
				$this->assertSame(
					$previous_id,
					$stored,
					'An identifier equal to the stored one leaves the stored value as it was. ' . $label
				);
			} else {
				$this->assertNotSame(
					$previous_id,
					$stored,
					'A differing identifier should replace the stored one. ' . $label
				);
			}
		}

		// Requirement 6.4, and Requirements 6.10 and 6.11 through the drawn mode:
		// the configured list and tag, plus the staging tag exactly in staging.
		$this->assertSame(
			array( $case['list_id'] ),
			$this->crm->lists_for( $email ),
			'The contact should belong to the configured list and no other. ' . $label
		);
		$this->assertSame(
			self::expected_tags( $case ),
			$this->crm->tags_for( $email ),
			'The contact should hold the configured tag, and the staging tag only in staging. ' . $label
		);
	}

	/**
	 * Requirement 19.16: a failed re-link leaves the edit standing, marks the
	 * enquiry `pending`, and does not clear the identifier it held.
	 *
	 * @param array  $outcome     What the edit returned.
	 * @param array  $after       The enquiry after the edit.
	 * @param int    $previous_id Subscriber identifier held before the edit.
	 * @param string $label       Case description for failure messages.
	 * @return void
	 */
	private function assert_pending( array $outcome, array $after, $previous_id, $label ) {
		$this->assertSame(
			ContactLinker::STATE_PENDING,
			(string) $after['crm_sync_state'],
			'A failed re-link leaves the enquiry pending. ' . $label
		);
		$this->assertSame(
			ContactLinker::STATE_PENDING,
			(string) $outcome['crm_sync_state'],
			'The reported state should be the state the enquiry holds. ' . $label
		);
		$this->assertSame(
			$previous_id,
			(int) $after['fluentcrm_subscriber_id'],
			'A failed re-link keeps the subscriber identifier it held rather than clearing it. ' . $label
		);
	}

	/**
	 * Every value the edit applied is stored (Requirement 19.16).
	 *
	 * @param array  $expected Expected post-edit values.
	 * @param array  $after    The enquiry after the edit.
	 * @param string $label    Case description for failure messages.
	 * @return void
	 */
	private function assert_values_retained( array $expected, array $after, $label ) {
		foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'message' ) as $field ) {
			$this->assertSame(
				(string) $expected[ $field ],
				(string) $after[ $field ],
				sprintf( 'The stored `%s` should be the corrected value. %s', $field, $label )
			);
		}

		$this->assertSame(
			(int) $expected['total_guests'],
			(int) $after['total_guests'],
			'The stored `total_guests` should be the corrected value. ' . $label
		);

		foreach ( array( 'selected_dates', 'event_type', 'site_exclusivity' ) as $field ) {
			$this->assertSame(
				self::sorted( (array) $expected[ $field ] ),
				self::sorted( (array) $after[ $field ] ),
				sprintf( 'The stored `%s` set should be the corrected one. %s', $field, $label )
			);
		}
	}

	/**
	 * Clause five: nothing but contact data reached FluentCRM
	 * (Requirements 6.8, 6.9).
	 *
	 * @param array  $case  The decoded case.
	 * @param string $label Case description for failure messages.
	 * @return void
	 */
	private function assert_confined( array $case, $label ) {
		$this->assertSame(
			array(),
			array_values( array_diff( $this->crm->written_fields(), ContactLinker::LINKED_FIELDS ) ),
			'The re-link should write no contact field beyond the four linked ones. ' . $label
		);

		$forbidden = self::forbidden( $case );

		foreach ( $this->crm->create_or_update_calls() as $data ) {
			foreach ( $data as $key => $value ) {
				$value = (string) $value;

				$this->assertStringNotContainsString(
					self::SENTINEL,
					$value,
					sprintf( 'The re-link wrote workflow data into `%s`. %s', $key, $label )
				);

				foreach ( $forbidden as $workflow ) {
					$this->assertStringNotContainsString(
						$workflow,
						$value,
						sprintf( 'The re-link wrote the workflow value `%s` into `%s`. %s', $workflow, $key, $label )
					);
				}
			}
		}

		$permitted = self::permitted_members( $case );

		foreach ( array_merge( $this->crm->list_calls(), $this->crm->tag_calls() ) as $call ) {
			foreach ( $call['args']['values'] as $value ) {
				$this->assertContains(
					$value,
					$permitted,
					'The re-link attached a list or tag beyond the configured ones. ' . $label
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * The fixture
	 * ------------------------------------------------------------------ */

	/**
	 * Create the enquiry one case edits, and report its identifier.
	 *
	 * Created through `EnquiryCreator::create()`, the shared creation path, so the
	 * enquiry the edit works on is one a real creation route would have produced —
	 * including its `crm_sync_state` and its subscriber identifier, which are what
	 * the property compares against afterwards.
	 *
	 * @param array $case The decoded case.
	 * @return int
	 */
	private function seed( array $case ) {
		$label = self::label( $case );

		$this->clear();

		update_option( 'meh_enquiry_list', $case['list_id'] );
		update_option( 'meh_enquiry_tag', $case['tag_id'] );

		remove_filter( 'meh_is_staging', '__return_true' );

		if ( $case['staging'] ) {
			add_filter( 'meh_is_staging', '__return_true' );
		}

		$this->crm->reset()->will_succeed();
		$this->crm->assign_subscriber_id( $case['base']['email'], $case['base_subscriber'] );
		$this->crm->assign_subscriber_id( $case['edit']['email'], $case['resolved_subscriber'] );

		// The prior link state is drawn: an enquiry that linked when it was
		// created, and one whose CRM was absent then, answer Requirement 19.16
		// differently.
		if ( ! $case['linked'] ) {
			$this->crm->will_be_unavailable();
		}

		$this->assertTrue( Clock::freeze( self::CREATED_AT ), 'The clock should freeze under the test harness.' );

		$outcome = EnquiryCreator::create(
			$case['base'],
			Validator::PROFILE_WEBHOOK,
			self::SOURCE,
			self::CREATED_AT,
			0
		);

		$this->assertTrue(
			$outcome['created'],
			'The generated fixture should be valid: ' . wp_json_encode( $outcome['errors'] ) . ' ' . $label
		);
		$this->assertSame(
			$case['linked'] ? ContactLinker::STATE_SYNCED : ContactLinker::STATE_PENDING,
			(string) $outcome['crm_sync_state'],
			'The fixture should start in the drawn link state. ' . $label
		);

		return (int) $outcome['enquiry_id'];
	}

	/**
	 * Put the CRM fake into the state the edit is to meet, and forget everything
	 * the creation did to it.
	 *
	 * Resetting is what makes "no FluentCRM call is made" and "the upsert is
	 * performed exactly once" claims about the *edit* rather than about the run.
	 *
	 * @param array $case The decoded case.
	 * @return void
	 */
	private function arm( array $case ) {
		$this->crm->reset();
		$this->crm->assign_subscriber_id( $case['base']['email'], $case['base_subscriber'] );
		$this->crm->assign_subscriber_id( $case['edit']['email'], $case['resolved_subscriber'] );
		$this->crm->{self::MODES[ $case['mode'] ]}();

		$this->assertTrue( Clock::freeze( self::EDITED_AT ), 'The clock should freeze under the test harness.' );
	}

	/**
	 * The field map one edit request carries.
	 *
	 * Changed fields carry their replacement value; the rest of the submitted
	 * fields carry the value the enquiry already holds, which is what keeps a
	 * wide request from widening the changed set.
	 *
	 * @param array $case   The decoded case.
	 * @param array $before The enquiry as it stands before the edit.
	 * @return array<string,mixed>
	 */
	private static function submitted( array $case, array $before ) {
		$submitted = array();

		foreach ( $case['changes'] as $field ) {
			$submitted[ $field ] = $case['edit'][ $field ];
		}

		foreach ( $case['unchanged'] as $field ) {
			$submitted[ $field ] = $before[ $field ];
		}

		return $submitted;
	}

	/**
	 * The values the enquiry holds once the edit has been applied.
	 *
	 * Every generated value is already tag-free, untrimmed of nothing and inside
	 * its stored capacity, so the Validator's sanitisation and truncation leave it
	 * as submitted and the expectation is the submitted value itself.
	 *
	 * @param array $case The decoded case.
	 * @return array<string,mixed>
	 */
	private static function expected_values( array $case ) {
		$values = $case['base'];

		foreach ( $case['changes'] as $field ) {
			$values[ $field ] = $case['edit'][ $field ];
		}

		return $values;
	}

	/**
	 * The contact data the upsert must carry (Requirements 6.3, 6.8, 6.9).
	 *
	 * Exactly the four linked fields, in the order the linker writes them, with
	 * the staging prefix on the first name in staging mode (Requirements 6.10,
	 * 6.11). Every generated value is non-empty, so no field is omitted.
	 *
	 * @param array $case     The decoded case.
	 * @param array $expected The values the enquiry holds after the edit.
	 * @return array<string,string>
	 */
	private static function expected_contact( array $case, array $expected ) {
		$first = (string) $expected['first_name'];

		return array(
			'first_name' => $case['staging'] ? StagingMarker::prefix() . $first : $first,
			'last_name'  => (string) $expected['last_name'],
			'email'      => (string) $expected['email'],
			'phone'      => (string) $expected['phone'],
		);
	}

	/**
	 * The tags a successful upsert must apply.
	 *
	 * @param array $case The decoded case.
	 * @return array<int,int|string>
	 */
	private static function expected_tags( array $case ) {
		$tags = array( $case['tag_id'] );

		if ( $case['staging'] ) {
			$tags[] = ContactLinker::TEST_TAG;
		}

		return $tags;
	}

	/**
	 * The list and tag values the linker is permitted to attach.
	 *
	 * @param array $case The decoded case.
	 * @return array<int,int|string>
	 */
	private static function permitted_members( array $case ) {
		return array_merge( array( $case['list_id'] ), self::expected_tags( $case ) );
	}

	/**
	 * Workflow values that may appear nowhere in FluentCRM.
	 *
	 * The six status names and every candidate date the enquiry ever holds.
	 * Messages and term values are covered by the sentinel prefix instead, which
	 * catches a fragment as readily as a whole value.
	 *
	 * @param array $case The decoded case.
	 * @return string[]
	 */
	private static function forbidden( array $case ) {
		$values = Lifecycle::STATUSES;

		foreach ( array_merge( $case['base']['selected_dates'], $case['edit']['selected_dates'] ) as $date ) {
			$values[] = (string) $date;
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * A one-line description of the case, for failure messages.
	 *
	 * @param array $case The decoded case.
	 * @return string
	 */
	private static function label( array $case ) {
		return sprintf(
			'[kind: %s, changed: %s, also submitted: %s, outcome: %s, linked before: %s, staging: %s, ids: %d -> %d]',
			$case['kind'],
			$case['changes'] ? implode( '+', $case['changes'] ) : 'nothing',
			$case['unchanged'] ? implode( '+', $case['unchanged'] ) : 'nothing',
			$case['mode'],
			$case['linked'] ? 'yes' : 'no',
			$case['staging'] ? 'yes' : 'no',
			$case['base_subscriber'],
			$case['resolved_subscriber']
		);
	}

	/**
	 * One list, sorted, for a set comparison.
	 *
	 * @param array $values Values to compare as a set.
	 * @return array
	 */
	private static function sorted( array $values ) {
		$values = array_map( 'strval', array_values( $values ) );
		sort( $values );

		return $values;
	}

	/* ---------------------------------------------------------------------
	 * The generator: a few integers
	 * ------------------------------------------------------------------ */

	/**
	 * What Eris draws: three seeds and eight small choices.
	 *
	 * @return \Eris\Generator
	 */
	protected static function drawn() {
		return \Eris\Generators::associative(
			array(
				'base'                => \Eris\Generators::choose( 0, self::SEED_MAX ),
				'edit'                => \Eris\Generators::choose( 0, self::SEED_MAX ),
				'shape'               => \Eris\Generators::choose( 0, self::SEED_MAX ),
				'kind'                => \Eris\Generators::elements( self::KINDS ),
				'mode'                => \Eris\Generators::elements( array_keys( self::MODES ) ),
				'linked'              => \Eris\Generators::elements( array( true, false ) ),
				'staging'             => \Eris\Generators::elements( array( true, false ) ),
				'same_subscriber'     => \Eris\Generators::elements( array( true, false ) ),
				'base_subscriber'     => \Eris\Generators::choose( 2000, 2999 ),
				'other_subscriber'    => \Eris\Generators::choose( 3000, 3999 ),
				'list_id'             => \Eris\Generators::choose( 1, 499 ),
				'tag_id'              => \Eris\Generators::choose( 500, 999 ),
				'actor'               => \Eris\Generators::choose( 1, 9 ),
			)
		);
	}

	/**
	 * Turn the drawn values into the case the test works with.
	 *
	 * Pure and total: every seed decodes to a valid nine-field submission and a
	 * valid replacement for each of those fields, so no draw is ever discarded.
	 *
	 * @param array $drawn What Eris drew.
	 * @return array
	 */
	private static function decode( array $drawn ) {
		$pick    = self::picker( $drawn['shape'] );
		$changes = self::changes( (string) $drawn['kind'], $pick );
		$base    = self::base_fields( $drawn['base'] );
		$edit    = self::edit_fields( $drawn['edit'] );

		// Every other pool is disjoint by construction; a guest count is a number
		// in a fixed range, so the two seeds can land on the same one. Displacing
		// it inside the permitted range is what makes "changed" mean changed for
		// this field too.
		$edit['total_guests'] = self::other_guests( $base['total_guests'] );

		$unchanged = array();

		foreach ( self::ALL_FIELDS as $field ) {
			// A field submitted at its stored value is a real part of an edit
			// request, and the part that must not widen the changed set.
			if ( ! in_array( $field, $changes, true ) && 0 === $pick( 2 ) ) {
				$unchanged[] = $field;
			}
		}

		return array(
			'base'                => $base,
			'edit'                => $edit,
			'changes'             => $changes,
			'unchanged'           => $unchanged,
			'kind'                => (string) $drawn['kind'],
			'mode'                => (string) $drawn['mode'],
			'linked'              => (bool) $drawn['linked'],
			'staging'             => (bool) $drawn['staging'],
			'base_subscriber'     => (int) $drawn['base_subscriber'],
			// Requirement 19.15 has two branches: the corrected address resolves
			// a different contact, or the same one.
			'resolved_subscriber' => $drawn['same_subscriber'] ? (int) $drawn['base_subscriber'] : (int) $drawn['other_subscriber'],
			'list_id'             => (int) $drawn['list_id'],
			'tag_id'              => (int) $drawn['tag_id'],
			'actor'               => (int) $drawn['actor'],
		);
	}

	/**
	 * The fields one case changes.
	 *
	 * @param string   $kind One of self::KINDS.
	 * @param callable $pick Deterministic small-number stream.
	 * @return string[]
	 */
	private static function changes( $kind, callable $pick ) {
		switch ( $kind ) {
			case 'none':
				return array();

			case 'workflow':
				return self::subset( self::WORKFLOW_FIELDS, $pick, 1 );

			case 'contact':
				return array_merge(
					self::subset( self::CONTACT_FIELDS, $pick, 1 ),
					self::subset( self::WORKFLOW_FIELDS, $pick, 0 )
				);

			case 'email':
			default:
				return array_merge(
					array( 'email' ),
					self::subset( self::CONTACT_FIELDS, $pick, 0 ),
					self::subset( self::WORKFLOW_FIELDS, $pick, 0 )
				);
		}
	}

	/**
	 * A subset of a field list, holding at least $min of them.
	 *
	 * @param string[] $fields Field names.
	 * @param callable $pick   Deterministic small-number stream.
	 * @param int      $min    Fewest fields to return.
	 * @return string[]
	 */
	private static function subset( array $fields, callable $pick, $min ) {
		$chosen = array();

		foreach ( $fields as $field ) {
			if ( 0 === $pick( 2 ) ) {
				$chosen[] = $field;
			}
		}

		if ( count( $chosen ) < (int) $min ) {
			$chosen = array( $fields[ $pick( count( $fields ) ) ] );
		}

		return $chosen;
	}

	/**
	 * The nine field values the fixture enquiry is created with.
	 *
	 * @param int $seed Drawn seed.
	 * @return array<string,mixed>
	 */
	private static function base_fields( $seed ) {
		$pick  = self::picker( $seed );
		$names = self::base_names();

		return array(
			'first_name'       => $names[ $pick( count( $names ) ) ],
			'last_name'        => $names[ $pick( count( $names ) ) ],
			'email'            => 'ada+' . $pick( 900 ) . '@example.com',
			'phone'            => '07700 ' . str_pad( (string) $pick( 999999 ), 6, '0', STR_PAD_LEFT ),
			'total_guests'     => self::guests( $pick( 3 ), $pick( Generators::TOTAL_GUESTS_MAX ) ),
			'message'          => self::SENTINEL . 'MESSAGE-A-' . $pick( 999 ) . ': a summer weekend, and a marquee.',
			'selected_dates'   => self::dates( $pick, 1 ),
			'event_type'       => self::terms( 'EVENT-A', 1 + $pick( 3 ), $pick ),
			'site_exclusivity' => self::terms( 'EXCLUSIVITY-A', 1 + $pick( 3 ), $pick ),
		);
	}

	/**
	 * The replacement value of each of the nine fields.
	 *
	 * Every pool here is disjoint from its counterpart in `base_fields()`, so a
	 * field named as changed is changed whatever the seeds drew — including the
	 * capacity-length values, which cannot be changed by appending to them because
	 * truncation would put them back. `total_guests` is the one field a pool
	 * cannot separate, being a number in a fixed range; `decode()` displaces it
	 * from the base value instead.
	 *
	 * @param int $seed Drawn seed.
	 * @return array<string,mixed>
	 */
	private static function edit_fields( $seed ) {
		$pick  = self::picker( $seed );
		$names = self::edit_names();

		return array(
			'first_name'       => $names[ $pick( count( $names ) ) ],
			'last_name'        => $names[ $pick( count( $names ) ) ],
			'email'            => 'grace+' . $pick( 900 ) . '@example.test',
			'phone'            => '+44 131 ' . str_pad( (string) $pick( 999999 ), 6, '0', STR_PAD_LEFT ),
			'total_guests'     => self::guests( $pick( 3 ), $pick( Generators::TOTAL_GUESTS_MAX ) ),
			'message'          => self::SENTINEL . 'MESSAGE-B-' . $pick( 999 ) . ': the date has moved, and so has the count.',
			'selected_dates'   => self::dates( $pick, 300 ),
			'event_type'       => self::terms( 'EVENT-B', 1 + $pick( 3 ), $pick ),
			'site_exclusivity' => self::terms( 'EXCLUSIVITY-B', 1 + $pick( 3 ), $pick ),
		);
	}

	/**
	 * The `first_name` and `last_name` pool of a fixture enquiry.
	 *
	 * Unicode, an apostrophe, the adversarial tokens and a value at exactly the
	 * stored capacity. None of them carries the sentinel, a status name or a date,
	 * which is what makes a leak into a name field detectable.
	 *
	 * @return string[]
	 */
	private static function base_names() {
		return array(
			'Ada',
			"O'Brien",
			'Zoë Ó Séaghdha',
			"Robert'); DROP TABLE wp_meh_enquiries; --",
			Generators::capacity_value( 'first_name' ),
		);
	}

	/**
	 * The `first_name` and `last_name` pool of a correction, disjoint from
	 * `base_names()`.
	 *
	 * @return string[]
	 */
	private static function edit_names() {
		return array(
			'Grace',
			'Ó Ceallaigh',
			'100% _ %s %d',
			'C:\\path\\to\\nowhere',
			Generators::capacity_value( 'first_name', true ),
		);
	}

	/**
	 * A guest count, reaching both ends of the permitted 1 to 10000 range.
	 *
	 * @param int $shape 0, 1 or 2.
	 * @param int $count Drawn count.
	 * @return int
	 */
	private static function guests( $shape, $count ) {
		if ( 0 === $shape ) {
			return Generators::TOTAL_GUESTS_MIN;
		}

		if ( 1 === $shape ) {
			return Generators::TOTAL_GUESTS_MAX;
		}

		return max( Generators::TOTAL_GUESTS_MIN, (int) $count );
	}

	/**
	 * A guest count inside the permitted range and different from a given one.
	 *
	 * Both ends of the range stay reachable: displacing by half the range keeps a
	 * low count high and a high count low, so 1 and 10000 both appear as
	 * replacement values as well as base ones.
	 *
	 * @param int $guests The count to differ from.
	 * @return int
	 */
	private static function other_guests( $guests ) {
		$guests = (int) $guests;
		$half   = intdiv( Generators::TOTAL_GUESTS_MAX, 2 );

		return $guests > $half ? $guests - $half : $guests + $half;
	}

	/**
	 * A candidate date set of one to four distinct dates, from a given offset.
	 *
	 * The base and correction offsets are far enough apart that the two sets are
	 * always disjoint, so a submitted correction always changes the set.
	 *
	 * @param callable $pick  Deterministic small-number stream.
	 * @param int      $start First day offset.
	 * @return string[]
	 */
	private static function dates( callable $pick, $start ) {
		$count  = 1 + $pick( 4 );
		$offset = (int) $start;
		$dates  = array();

		for ( $index = 0; $index < $count; $index++ ) {
			// A strictly positive step keeps every date distinct.
			$offset += 1 + $pick( 30 );
			$dates[] = Generators::date_at( $offset );
		}

		return $dates;
	}

	/**
	 * A sentinel-marked term set of $count distinct values.
	 *
	 * @param string   $tag   Distinguishing tag, disjoint between base and edit.
	 * @param int      $count How many values.
	 * @param callable $pick  Deterministic small-number stream.
	 * @return string[]
	 */
	private static function terms( $tag, $count, callable $pick ) {
		$terms = array();

		for ( $index = 0; $index < (int) $count; $index++ ) {
			$terms[] = self::SENTINEL . $tag . '-' . $index . '-' . $pick( 9999 );
		}

		return $terms;
	}

	/**
	 * A deterministic stream of small numbers from one seed.
	 *
	 * A plain linear congruential mixer, so a seed spreads over the whole space of
	 * each field it feeds and successive draws are unrelated. Deterministic, so a
	 * reported seed reproduces its case exactly.
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
	 * Draw a case without offering Eris anything to shrink.
	 *
	 * Eris shrinks a composite generator by materialising the cartesian product of
	 * every leaf's alternatives in one call, which exhausts memory before it can
	 * report anything. Binding the drawn value to a constant generator leaves the
	 * shrinker nothing to expand, so a failure reports the case exactly as
	 * generated together with the `ERIS_SEED` line that reproduces the run.
	 *
	 * @param \Eris\Generator $generator Generator to draw from.
	 * @return \Eris\Generator
	 */
	protected static function unshrunk( \Eris\Generator $generator ) {
		return \Eris\Generators::bind(
			$generator,
			function ( $value ) {
				return \Eris\Generators::constant( $value );
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Empty the tables this property writes to, between iterations.
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
