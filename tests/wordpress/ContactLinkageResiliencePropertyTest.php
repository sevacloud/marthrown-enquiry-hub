<?php
/**
 * Property 16: Contact linkage failure never loses the enquiry.
 *
 * Feature: enquiry-data-layer, Property 16: For any valid submission, whether it
 * arrived as an intake webhook request or through the manual enquiry creation
 * route, and any contact-linkage failure mode (FluentCRM absent, the API
 * throwing, or a response carrying no subscriber identifier), the enquiry exists
 * and is readable at the moment the linker is invoked, survives the failure with
 * `crm_sync_state` equal to `pending` and an empty `fluentcrm_subscriber_id`;
 * and for any such enquiry, a subsequent successful retry sets `crm_sync_state`
 * to `synced` and records the returned subscriber identifier.
 *
 * **Validates: Requirements 5.1, 5.2, 5.4, 5.5, 6.6, 6.7**
 *
 * How the property is instantiated, and why:
 *
 * - **Both creation routes are quantified over, not just one.** The webhook
 *   route runs through `IntakeHandler::receive()` and the manual route through
 *   `EnquiryCreator::create()` under the Manual profile, because Requirement 5.1
 *   is an ordering claim about *every* creation path: store first, link second.
 *   A route that linked before it stored would lose the enquiry on exactly these
 *   failures, and only driving both catches it.
 * - **All three failure modes are quantified over.** FluentCRM absent, the API
 *   raising, and an answer carrying no subscriber identifier are three different
 *   guards in `ContactLinker` (the existence check, the `try`/`catch`, and the
 *   identifier check), so the property is only about Requirements 6.6 and 6.7
 *   if all three are drawn.
 * - **"Readable at the moment the linker is invoked" is observed, not
 *   inferred.** The CRM fake is subclassed so that resolving the contacts API —
 *   the first thing the linker does with FluentCRM, and something it does in all
 *   three failure modes — reads the enquiry back out of the store and keeps the
 *   hydrated result. Asserting the row afterwards would say nothing about the
 *   ordering, which is the whole of Requirement 5.1.
 * - **Survival is asserted as equality, not as existence.** The hydrated enquiry
 *   observed mid-link, the one read after the failure and the one read after the
 *   retry must agree on every value but the two CRM columns, so a failure that
 *   quietly dropped a candidate date, a term or the payload snapshot fails here
 *   rather than passing as "the row is still there".
 * - **The retry returns a generated identifier.** The fake is told which
 *   subscriber id the contact resolves to before the first attempt, so
 *   Requirement 5.5's "records the returned subscriber identifier" is a claim
 *   about a value the test chose rather than about whatever the fake happened to
 *   count up to.
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
use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\EnquiryCreator;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\IntakeHandler;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;
use MarthrownEnquiryHub\Validator;

/**
 * Class ContactLinkageResiliencePropertyTest
 */
class ContactLinkageResiliencePropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehlr_';

	/**
	 * The instant every iteration receives its submission at.
	 */
	const AT = '2025-06-02 11:30:00';

	/**
	 * The two creation routes Requirement 5.1 covers.
	 *
	 * @var string[]
	 */
	const ROUTES = array( 'webhook', 'manual' );

	/**
	 * The three linkage failure modes, as FakeCrm configuration calls.
	 *
	 * @var array<string,string>
	 */
	const FAILURE_MODES = array(
		'unavailable' => 'will_be_unavailable',
		'throwing'    => 'will_throw',
		'no_id'       => 'will_return_no_subscriber_id',
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
	 * @var MehLinkageResilienceCrm|null
	 */
	private $crm = null;

	/**
	 * Email addresses whose rate-limit counters need forgetting.
	 *
	 * @var string[]
	 */
	private $counted = array();

	/**
	 * The hydrated enquiries readable at each moment the linker reached the CRM.
	 *
	 * @var array<int,array|null>
	 */
	private $observed = array();

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
		require_once MEH_INCLUDES_DIR . 'class-duplicate-detector.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-handler.php';
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

		// The vocabularies the Validator checks a submitted multi-select
		// against, so every generated `event_type`/`site_exclusivity` value is
		// one either creation route accepts.
		foreach ( array_keys( Generators::VOCABULARIES ) as $taxonomy ) {
			add_filter(
				'meh_enquiry_terms_' . $taxonomy,
				static function () use ( $taxonomy ) {
					return Generators::vocabulary( $taxonomy );
				}
			);
		}

		Clock::freeze( self::AT );
	}

	public function tear_down() {
		global $wpdb;

		FakeCrm::uninstall();
		$this->crm = null;

		foreach ( $this->counted as $email ) {
			DuplicateDetector::reset( $email );
		}

		$this->counted = array();

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 16: Contact linkage failure never
	 * loses the enquiry.
	 *
	 * **Validates: Requirements 5.1, 5.2, 5.4, 5.5, 6.6, 6.7**
	 *
	 * @eris-shrink 10
	 */
	public function test_contact_linkage_failure_never_loses_the_enquiry() {
		$this->limitTo( Iterations::count( 40 ) )
			->forAll( self::scenario() )
			->then(
				function ( array $case ) {
					$label = self::label( $case );

					$this->start_iteration( $case );

					$id = $this->create( $case );

					// Requirement 5.1: the linker was reached, and the enquiry
					// was already stored and readable when it was.
					$this->assertCount(
						1,
						$this->observed,
						'The linker should reach FluentCRM exactly once per created enquiry. ' . $label
					);

					$mid_link = $this->observed[0];

					$this->assertIsArray( $mid_link, 'The enquiry should be readable as the linker runs. ' . $label );
					$this->assertSame( $id, $mid_link['id'], 'The readable enquiry should be the one being linked. ' . $label );
					$this->assertSame( 'new', $mid_link['status'], 'A created enquiry is at status new. ' . $label );

					// Requirements 5.2, 6.6, 6.7: the failure downgrades the
					// enquiry rather than losing it.
					$failed = EnquiryStore::find( $id );

					$this->assertIsArray( $failed, 'The enquiry should survive the failure. ' . $label );
					$this->assertSame(
						ContactLinker::STATE_PENDING,
						$failed['crm_sync_state'],
						'A failed link leaves the enquiry pending. ' . $label
					);
					$this->assertEmpty(
						$failed['fluentcrm_subscriber_id'],
						'A failed link records no subscriber identifier. ' . $label
					);
					$this->assertSame(
						self::without_crm_state( $mid_link ),
						self::without_crm_state( $failed ),
						'The failure should change nothing but the CRM state. ' . $label
					);
					$this->assertNotContains(
						ContactLinker::HISTORY_TYPE,
						$this->history_types( $id ),
						'A failed link records no crm_linked entry. ' . $label
					);

					// Requirements 5.4, 5.5: the retry recovers the enquiry.
					$this->crm->will_succeed();

					$retried = ContactLinker::retry( $id );

					$this->assertNotWPError( $retried, 'A retry against a working API should succeed. ' . $label );
					$this->assertSame(
						(int) $case['subscriber_id'],
						(int) $retried['subscriber_id'],
						'The retry should report the identifier FluentCRM returned. ' . $label
					);

					$synced = EnquiryStore::find( $id );

					$this->assertSame(
						ContactLinker::STATE_SYNCED,
						$synced['crm_sync_state'],
						'A successful retry marks the enquiry synced. ' . $label
					);
					$this->assertSame(
						(int) $case['subscriber_id'],
						(int) $synced['fluentcrm_subscriber_id'],
						'A successful retry records the returned subscriber identifier. ' . $label
					);
					$this->assertSame(
						self::without_crm_state( $failed ),
						self::without_crm_state( $synced ),
						'The retry should change nothing but the CRM state. ' . $label
					);
					$this->assertContains(
						ContactLinker::HISTORY_TYPE,
						$this->history_types( $id ),
						'A successful retry records a crm_linked entry. ' . $label
					);
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: a valid submission, a creation route, a failure mode, the
	 * identifier the contact resolves to, and the acting user of a manual
	 * submission.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'fields'        => Generators::enquiry(),
				'route'         => \Eris\Generators::elements( self::ROUTES ),
				'mode'          => \Eris\Generators::elements( array_keys( self::FAILURE_MODES ) ),
				'subscriber_id' => \Eris\Generators::choose( 2, 99999 ),
				'actor'         => \Eris\Generators::choose( 1, 9 ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * The case, resolved
	 * ------------------------------------------------------------------ */

	/**
	 * Empty the tables and install the case's CRM fake.
	 *
	 * The subscriber identifier is pinned before the first attempt, so the
	 * contact the retry resolves carries it whether the failing attempt created a
	 * contact in the fake or not.
	 *
	 * @param array $case The generated case.
	 * @return void
	 */
	private function start_iteration( array $case ) {
		$this->clear();

		$this->observed = array();

		$this->crm = MehLinkageResilienceCrm::install_observed();
		$this->crm->next_subscriber_id( (int) $case['subscriber_id'] );
		$this->crm->{self::FAILURE_MODES[ $case['mode'] ]}();
		$this->crm->observe(
			function () {
				$this->observed[] = $this->newest_enquiry();
			}
		);
	}

	/**
	 * Create one enquiry through the case's route, and report its identifier.
	 *
	 * @param array $case The generated case.
	 * @return int
	 */
	private function create( array $case ) {
		$label = self::label( $case );
		$email = self::submitted_email( $case['fields'] );

		if ( '' !== $email ) {
			$this->counted[] = $email;
			DuplicateDetector::reset( $email );
		}

		if ( 'manual' === $case['route'] ) {
			$outcome = EnquiryCreator::create(
				$case['fields'],
				Validator::PROFILE_MANUAL,
				'manual:' . (int) $case['actor'],
				self::AT,
				(int) $case['actor']
			);

			$this->assertSame(
				ContactLinker::STATE_PENDING,
				$outcome['crm_sync_state'],
				'A failed link is reported as pending. ' . $label
			);
		} else {
			$outcome = IntakeHandler::receive( $case['fields'], 'webhook:enquiry-form', self::AT );
		}

		$this->assertTrue( $outcome['created'], 'A valid submission should create an enquiry. ' . $label );
		$this->assertGreaterThan( 0, $outcome['enquiry_id'], 'A created enquiry gets an identifier. ' . $label );

		return (int) $outcome['enquiry_id'];
	}

	/**
	 * The address a submission carries, as the guards read it.
	 *
	 * @param array $fields Submitted field map.
	 * @return string
	 */
	private static function submitted_email( array $fields ) {
		return isset( $fields['email'] ) && is_scalar( $fields['email'] ) ? trim( (string) $fields['email'] ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * The most recently written enquiry, hydrated.
	 *
	 * One enquiry is created per iteration and the tables are emptied between
	 * them, so the newest row is the one the linker is working on. Read this way
	 * rather than by identifier because the observation happens inside the
	 * linker, which the caller has not returned from yet.
	 *
	 * @return array|null
	 */
	private function newest_enquiry() {
		global $wpdb;

		$table = Schema::table( 'enquiries' );
		$id    = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB

		return $id > 0 ? EnquiryStore::find( $id ) : null;
	}

	/**
	 * One hydrated enquiry without its two CRM columns.
	 *
	 * Everything else must be identical before the link, after the failure and
	 * after the retry.
	 *
	 * @param array $enquiry Hydrated enquiry.
	 * @return array
	 */
	private static function without_crm_state( array $enquiry ) {
		unset( $enquiry['crm_sync_state'], $enquiry['fluentcrm_subscriber_id'] );

		return $enquiry;
	}

	/**
	 * The history entry types recorded against one enquiry, oldest first.
	 *
	 * @param int $id Enquiry identifier.
	 * @return string[]
	 */
	private function history_types( $id ) {
		$types = array();

		foreach ( HistoryRecorder::for_enquiry( $id ) as $entry ) {
			$types[] = $entry['entry_type'];
		}

		return $types;
	}

	/**
	 * A one-line description of the case, for failure messages.
	 *
	 * @param array $case The generated case.
	 * @return string
	 */
	private static function label( array $case ) {
		return sprintf(
			'[route: %s, failure: %s, subscriber: %d]',
			$case['route'],
			$case['mode'],
			(int) $case['subscriber_id']
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

/**
 * Class MehLinkageResilienceCrm
 *
 * The shared CRM fake with one addition: a callback fired the moment the linker
 * resolves the contacts API. That call happens in all three failure modes — an
 * unavailable FluentCRM resolves to nothing *here*, a raising one raises after
 * it, and one answering without an identifier answers after it — which makes it
 * the one point every failing link passes through with the store in the state
 * Requirement 5.1 is about.
 */
class MehLinkageResilienceCrm extends FakeCrm {

	/**
	 * Called on every contacts API resolution.
	 *
	 * @var callable|null
	 */
	protected $observer = null;

	/**
	 * Install a fresh observing fake and make it current.
	 *
	 * @return MehLinkageResilienceCrm
	 */
	public static function install_observed() {
		self::$current = new self();

		return self::$current;
	}

	/**
	 * Watch every contacts API resolution.
	 *
	 * @param callable $observer Called with no arguments.
	 * @return MehLinkageResilienceCrm
	 */
	public function observe( callable $observer ) {
		$this->observer = $observer;

		return $this;
	}

	/**
	 * Resolve an API module, telling the observer first.
	 *
	 * @param string $key Module key.
	 * @return mixed
	 */
	public function api( $key ) {
		if ( null !== $this->observer && 'contacts' === $key ) {
			call_user_func( $this->observer );
		}

		return parent::api( $key );
	}
}
