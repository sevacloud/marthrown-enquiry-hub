<?php
/**
 * Property 19: Test marking follows the environment.
 *
 * Feature: enquiry-data-layer, Property 19: For any submission, by either
 * creation path, and any environment mode, the created enquiry's `is_test` value
 * is true exactly when staging mode is reported, the linked contact's
 * `first_name` carries the configured test prefix and the `test-record` tag
 * exactly when staging mode is reported, and a booking created from that enquiry
 * carries the test-prefixed guest name exactly when staging mode is reported.
 *
 * **Validates: Requirements 6.10, 6.11, 17.1, 17.2, 17.3**
 *
 * How the property is instantiated, and why:
 *
 * - **Both environment modes are drawn, and production is the harder half.**
 *   Staging is switched on with the `meh_is_staging` filter the bootstrap helper
 *   ends on, and switched off by removing it, so `StagingMarker` answers through
 *   the same detection a real staging copy uses. Every claim is stated as an
 *   "exactly when": in production the enquiry is not marked, the contact name
 *   carries no prefix, the `test-record` tag is absent and the booking guest name
 *   is plain (Requirements 6.11, 17.2). A test that only ever ran in staging
 *   would pass against an implementation that marked everything unconditionally.
 * - **Both creation paths are drawn.** The webhook path goes through
 *   `IntakeHandler::receive()`, the manual path through `EnquiryCreator::create()`
 *   under the Manual profile — the two callers Requirement 18 makes share one
 *   store-then-link sequence. `is_test` is set in that shared sequence, so
 *   drawing the path is what makes "by either creation path" a claim rather than
 *   an assumption about where the marking happens.
 * - **The expectation is computed from the stored enquiry, not from the marker.**
 *   The expected contact name is the prefix constant concatenated with the
 *   `first_name` the store holds, and the expected guest name the same over
 *   `first_name` plus `last_name`. Asking `StagingMarker::apply_name()` what it
 *   thinks the name should be would take the oracle from the code under test.
 * - **The three surfaces are observed where they actually are.** `is_test` is
 *   read back out of the database, the contact name and tags out of
 *   `tests/fakes/FakeCrm.php` — both the upsert call log and the resting contact,
 *   so a prefix applied and then overwritten is still caught — and the guest name
 *   out of the booking `tests/fakes/FakeWpbs.php` recorded.
 * - **Each iteration submits an address unique to it**, so neither the duplicate
 *   guard nor the rate limiter can turn the webhook submission away for a reason
 *   this property is not about and leave it asserting nothing. The address is
 *   still drawn in three shapes, and a fresh address per iteration also means a
 *   fresh contact, so the marking observed is the one this iteration wrote.
 * - **The configured list and tag are drawn.** The permitted tag set is "the
 *   configured tag, plus `test-record` in staging", so a fixed configured tag
 *   would let an implementation that always attached a literal pass the staging
 *   half by accident.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix segment: the WordPress test case rewrites `CREATE TABLE` into its
 * `TEMPORARY` form, and `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see a temporary table.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\BookingCreator;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\ContactLinker;
use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\EnquiryCreator;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\IntakeHandler;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\StagingMarker;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Fakes\FakeWpbs;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;
use MarthrownEnquiryHub\Validator;

/**
 * Class StagingTestMarkingPropertyTest
 */
class StagingTestMarkingPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table, or with another test's fixture, in the same database.
	 */
	const PREFIX_SEGMENT = 'mehsm_';

	/** The instant the clock is frozen at for every iteration. */
	const NOW = '2025-06-10 14:20:00';

	/** The `source` a webhook-created enquiry carries. */
	const WEBHOOK_SOURCE = 'webhook:staging-property';

	/** The user a manually created enquiry is attributed to. */
	const MANUAL_ACTOR = 7;

	/**
	 * The two creation paths the property quantifies over.
	 *
	 * @var string[]
	 */
	const PATHS = array( 'webhook', 'manual' );

	/**
	 * Address shapes an iteration may submit.
	 *
	 * @var string[]
	 */
	const EMAIL_SHAPES = array( 'plain', 'quoted', 'mixed_case' );

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
	 * The installed WPBS fake.
	 *
	 * @var FakeWpbs|null
	 */
	private $wpbs = null;

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
	 * `class-enquiry-creator.php` comes before `class-intake-handler.php`,
	 * because the handler resolves constants from `EnquiryCreator` as its class
	 * body is evaluated.
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
		require_once MEH_INCLUDES_DIR . 'class-lifecycle.php';
		require_once MEH_INCLUDES_DIR . 'class-source-wpbs.php';
		require_once MEH_INCLUDES_DIR . 'class-booking-creator.php';
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

		$this->crm  = FakeCrm::install();
		$this->wpbs = FakeWpbs::install();
	}

	public function tear_down() {
		global $wpdb;

		foreach ( $this->counted as $email ) {
			DuplicateDetector::reset( $email );
		}

		$this->counted = array();

		FakeCrm::uninstall();
		FakeWpbs::uninstall();
		$this->crm  = null;
		$this->wpbs = null;

		remove_filter( 'meh_is_staging', '__return_true' );

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 19: Test marking follows the
	 * environment.
	 *
	 * **Validates: Requirements 6.10, 6.11, 17.1, 17.2, 17.3**
	 *
	 * @eris-shrink 10
	 */
	public function test_test_marking_follows_the_environment() {
		$this->limitTo( Iterations::count( 60 ) )
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
	 * One submission, created in the drawn mode by the drawn path, and judged on
	 * all three surfaces.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check( array $case ) {
		$this->prepare( $case );

		$staging = (bool) $case['staging'];
		$label   = self::label( $case );
		$fields  = $this->submission( $case );

		$enquiry_id = $this->create( $case, $fields, $label );
		$enquiry    = EnquiryStore::find( $enquiry_id );

		$this->assertIsArray( $enquiry, 'The created enquiry should read back. ' . $label );

		// Requirements 17.1, 17.2: the record itself is marked exactly when the
		// plugin is running as the staging copy.
		$this->assertSame(
			$staging,
			$enquiry['is_test'],
			'is_test should be true exactly when staging mode is reported. ' . $label
		);

		// The record keeps the name as submitted whichever mode created it: the
		// prefix marks what leaves the plugin, not what it stores.
		$this->assertStringStartsNotWith(
			self::prefix(),
			(string) $enquiry['first_name'],
			'The stored first_name should carry no test prefix. ' . $label
		);

		$this->assert_contact_marking( $enquiry, $case, $label );
		$this->assert_booking_marking( $enquiry, $case, $label );
	}

	/**
	 * The linked contact carries the prefixed name and the `test-record` tag
	 * exactly in staging (Requirements 6.10, 6.11).
	 *
	 * Asserted twice over: against every recorded upsert, so a prefix that was
	 * never sent is caught, and against the resting contact, so one that was sent
	 * and then overwritten is caught too.
	 *
	 * @param array  $enquiry Stored enquiry.
	 * @param array  $case    Generated case.
	 * @param string $label   Failure context.
	 * @return void
	 */
	private function assert_contact_marking( array $enquiry, array $case, $label ) {
		$staging  = (bool) $case['staging'];
		$key      = strtolower( trim( (string) $enquiry['email'] ) );
		$contact  = $this->crm->contact( $key );
		$expected = self::marked( (string) $enquiry['first_name'], $staging );

		$this->assertNotNull( $contact, 'Creation should link a FluentCRM contact. ' . $label );

		$upserts = $this->crm->create_or_update_calls();

		$this->assertNotSame( array(), $upserts, 'Creation should perform at least one upsert. ' . $label );

		foreach ( $upserts as $position => $data ) {
			$this->assertSame(
				$expected,
				isset( $data['first_name'] ) ? (string) $data['first_name'] : '',
				sprintf( 'Upsert %d should write the %s first_name. %s', $position + 1, $staging ? 'prefixed' : 'unprefixed', $label )
			);
		}

		$this->assertSame(
			$expected,
			isset( $contact['data']['first_name'] ) ? (string) $contact['data']['first_name'] : '',
			sprintf( 'The contact should hold the %s first_name. %s', $staging ? 'prefixed' : 'unprefixed', $label )
		);

		$this->assertSame(
			self::as_set( self::expected_tags( $case ) ),
			self::as_set( $this->crm->tags_for( $key ) ),
			sprintf(
				'The contact should carry the configured tag%s. %s',
				$staging ? ' and the test-record tag' : ' and no test-record tag',
				$label
			)
		);

		// Stated on its own as well as through the set comparison, because the
		// presence and absence of this one tag is half of what the property says.
		if ( $staging ) {
			$this->assertContains(
				ContactLinker::TEST_TAG,
				$this->crm->tags_for( $key ),
				'A staging contact should carry the test-record tag. ' . $label
			);

			return;
		}

		$this->assertNotContains(
			ContactLinker::TEST_TAG,
			$this->crm->tags_for( $key ),
			'A production contact should carry no test-record tag. ' . $label
		);
	}

	/**
	 * A booking created from the enquiry carries the prefixed guest name exactly
	 * in staging (Requirement 17.3).
	 *
	 * The date is one day of the enquiry's own candidate ranges and the calendar one
	 * the fake knows, so conversion passes its guards and the booking that lands
	 * in the fake is the one whose guest name the property is about.
	 *
	 * @param array  $enquiry Stored enquiry.
	 * @param array  $case    Generated case.
	 * @param string $label   Failure context.
	 * @return void
	 */
	private function assert_booking_marking( array $enquiry, array $case, $label ) {
		$staging     = (bool) $case['staging'];
		$calendar_id = (int) $case['calendar'];
		$days        = Generators::days_in_ranges( (array) $enquiry['date_ranges'] );
		$chosen      = (string) $days[ (int) $case['chosen_index'] % count( $days ) ];

		$result = BookingCreator::create_from_enquiry( (int) $enquiry['id'], $calendar_id, $chosen );

		$this->assertIsArray( $result, 'Converting an open enquiry on one of its own dates should succeed. ' . $label );

		$booking = $this->wpbs->booking( $result['booking_id'] );

		$this->assertIsArray( $booking, 'The reported booking identifier should name the created booking. ' . $label );

		$expected = self::marked( self::guest_name( $enquiry ), $staging );

		$this->assertSame(
			$expected,
			(string) $booking['fields'][0]['user_value'],
			sprintf( 'The booking guest name should be %s. %s', $staging ? 'test-prefixed' : 'unprefixed', $label )
		);
	}

	/* ---------------------------------------------------------------------
	 * Creation
	 * ------------------------------------------------------------------ */

	/**
	 * Create the enquiry by the drawn path.
	 *
	 * The webhook path runs the guards and derives its own `source`; the manual
	 * path validates under the Manual profile and is attributed to a user. Both
	 * reach the store, the marking and the linker through `EnquiryCreator`, which
	 * is what the property means by "either creation path".
	 *
	 * @param array  $case   Generated case.
	 * @param array  $fields Submitted field map.
	 * @param string $label  Failure context.
	 * @return int The created enquiry identifier.
	 */
	private function create( array $case, array $fields, $label ) {
		if ( 'manual' === (string) $case['path'] ) {
			$outcome = EnquiryCreator::create(
				$fields,
				Validator::PROFILE_MANUAL,
				'manual:' . self::MANUAL_ACTOR,
				self::NOW,
				self::MANUAL_ACTOR
			);
		} else {
			$outcome = IntakeHandler::receive( $fields, self::WEBHOOK_SOURCE, self::NOW );
		}

		$this->assertTrue(
			! empty( $outcome['created'] ),
			sprintf(
				'The generated submission should be created: %s. %s',
				wp_json_encode(
					array(
						'reason' => isset( $outcome['reason'] ) ? $outcome['reason'] : '',
						'errors' => isset( $outcome['errors'] ) ? $outcome['errors'] : array(),
					)
				),
				$label
			)
		);

		return (int) $outcome['enquiry_id'];
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: the submission, the environment mode, the creation path, the
	 * configured membership, and which calendar and candidate date the booking
	 * uses.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'fields'       => Generators::enquiry(),
				'staging'      => \Eris\Generators::elements( array( true, false ) ),
				'path'         => \Eris\Generators::elements( self::PATHS ),
				'email_shape'  => \Eris\Generators::elements( self::EMAIL_SHAPES ),
				'list_id'      => \Eris\Generators::choose( 1, 499 ),
				'tag_id'       => \Eris\Generators::choose( 500, 999 ),
				'calendar'     => \Eris\Generators::elements( array( 1, 2 ) ),
				// Reduced against the number of days the drawn ranges cover, so
				// every candidate day is reachable whatever the size of the set.
				'chosen_index' => \Eris\Generators::choose( 0, 60 ),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step grows
	 * exponentially with the number of generated values. A case here draws a
	 * nine-field enquiry, two of whose fields are sets, plus seven further
	 * choices, which puts that product beyond what fits in memory: a failing
	 * iteration would report an out-of-memory fatal instead of the counterexample.
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
	 * Put the site, the store and both fakes into the state one case names.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function prepare( array $case ) {
		$this->clear();

		$this->crm->reset()->will_succeed();
		$this->wpbs->reset()->seed_defaults();

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

		$this->assertTrue( Clock::freeze( self::NOW ), 'The clock should freeze under the test harness.' );
	}

	/**
	 * The submitted field map, with an address unique to this iteration.
	 *
	 * Uniqueness keeps the duplicate guard and the rate limiter out of the way on
	 * the webhook path, so a valid submission is never turned away for a reason
	 * this property is not about.
	 *
	 * @param array $case Generated case.
	 * @return array
	 */
	private function submission( array $case ) {
		++$this->iteration;

		$fields          = (array) $case['fields'];
		$fields['email'] = self::unique_email( (string) $case['email_shape'], $this->iteration );

		$this->counted[] = $fields['email'];

		return $fields;
	}

	/**
	 * A submittable address of a given shape, unique to one iteration.
	 *
	 * @param string $shape     One of self::EMAIL_SHAPES.
	 * @param int    $iteration Iteration number.
	 * @return string
	 */
	private static function unique_email( $shape, $iteration ) {
		if ( 'quoted' === $shape ) {
			return sprintf( "o'brien+%d@example.com", (int) $iteration );
		}

		if ( 'mixed_case' === $shape ) {
			return sprintf( 'Enquirer+%d@Example.com', (int) $iteration );
		}

		return sprintf( 'enquirer+%d@example.com', (int) $iteration );
	}

	/* ---------------------------------------------------------------------
	 * Expectations
	 * ------------------------------------------------------------------ */

	/**
	 * A name as the mode should leave it.
	 *
	 * The prefix comes from the `MEH_TEST_PREFIX` constant the bootstrap defines,
	 * not from `StagingMarker::prefix()`: an expectation taken from the code under
	 * test could not catch that code applying the wrong prefix, or none.
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

		return self::prefix() . $name;
	}

	/**
	 * The configured test prefix.
	 *
	 * @return string
	 */
	private static function prefix() {
		return defined( 'MEH_TEST_PREFIX' ) ? (string) MEH_TEST_PREFIX : 'TEST_';
	}

	/**
	 * The tags the contact should carry (Requirements 6.4, 6.10, 6.11).
	 *
	 * @param array $case Generated case.
	 * @return array<int,int|string>
	 */
	private static function expected_tags( array $case ) {
		$tags = array( (int) $case['tag_id'] );

		if ( $case['staging'] ) {
			$tags[] = ContactLinker::TEST_TAG;
		}

		return $tags;
	}

	/**
	 * The unmarked guest name a booking derives from the enquiry.
	 *
	 * @param array $enquiry Stored enquiry.
	 * @return string
	 */
	private static function guest_name( array $enquiry ) {
		$first = trim( (string) $enquiry['first_name'] );
		$last  = trim( (string) $enquiry['last_name'] );

		return trim( $first . ' ' . $last );
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
	 * @param array $case Generated case.
	 * @return string
	 */
	private static function label( array $case ) {
		return sprintf(
			'[%s mode, %s path, %s address, list %d, tag %d, calendar %d]',
			$case['staging'] ? 'staging' : 'production',
			(string) $case['path'],
			(string) $case['email_shape'],
			(int) $case['list_id'],
			(int) $case['tag_id'],
			(int) $case['calendar']
		);
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

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
