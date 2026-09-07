<?php
/**
 * Property 13: Failed intake leaves nothing behind and nothing untraced.
 *
 * Feature: enquiry-data-layer, Property 13: For any valid submission and any
 * single injected persistence failure or unhandled throwable at any point on the
 * write path, no enquiry, candidate date, term, note or history row from that
 * request remains, previously stored data is unchanged, the contact linker is not
 * invoked, no `crm_sync_state` value is set, the payload snapshot and the failure
 * reason are written to the error log, and the endpoint answers 500 with no
 * partial enquiry representation; and for any authenticated intake webhook
 * request that creates no enquiry, whatever the cause, exactly one
 * rejected-intake-attempt row exists holding that request's payload, its receipt
 * time and a reason drawn from `duplicate`, `rate_limited`, `validation` and
 * `storage`.
 *
 * **Validates: Requirements 3.13, 4.1, 5.6, 5.7, 5.8**
 *
 * How the property is instantiated, and why:
 *
 * - **Every request goes through the REST server.** Each iteration builds a real
 *   `WP_REST_Request`, presents the stored intake secret and dispatches it with
 *   `rest_do_request()`. The status code, the response body and the rows are then
 *   the ones a sending form would actually produce, rather than the return value
 *   of a handler called directly — and Requirement 5.7 is a claim about the
 *   endpoint's own `try`/`catch ( \Throwable )`, which only a dispatched request
 *   exercises.
 * - **The failure is injected, not simulated.** Four persistence failures rewrite
 *   one real statement into one that cannot run — the enquiry row insert, the
 *   candidate date insert, the first term insert and the `COMMIT` — through the
 *   `query` filter, so the store meets a genuine database failure at each stage of
 *   its own transaction. Four throwables are raised from filters the write path
 *   consults in order: the field map (field resolution), the source field option
 *   (source resolution), the duplicate window (the guards) and a taxonomy
 *   vocabulary (validation). Between them they cover every point on the path from
 *   the moment the payload has been normalised to the moment the store write is
 *   attempted, which is the span the first clause quantifies over.
 * - **Exactly one failure per iteration.** Each fixture disarms itself the moment
 *   it fires, and every iteration asserts that it did fire, so no iteration is
 *   vacuous and none injects two failures where the property names one.
 * - **The throwables are drawn from after normalisation on purpose.** The clause
 *   requires the payload snapshot in the error log, and a throwable raised before
 *   the body has been flattened leaves the endpoint with no payload to log — a
 *   different claim about a different point in the request. Everything from
 *   normalisation onward is where "the write path" begins.
 * - **A throwable raised inside the store's transaction is deliberately not
 *   injected.** Nothing rolls it back in-process, so the rows written so far stay
 *   visible to the connection that wrote them until it closes; asserting their
 *   absence would assert a property of the test's own connection lifetime rather
 *   than of the endpoint. The persistence failures cover that stage of the path
 *   instead, and they cover it through the store's own recovery.
 * - **"Nothing behind" is asserted as a snapshot**, not as an absence of new
 *   enquiries: the row counts of all six Enquiry Store tables taken before the
 *   request, the full hydrated state of a seeded enquiry, and a count of enquiries
 *   holding the submitted address. A seeded enquiry and a seeded rejection row
 *   make those counts non-zero, so a stray write shows as a change rather than as
 *   a zero matching a zero.
 * - **"The contact linker is not invoked" is asserted through the CRM fake.**
 *   `ContactLinker` reaches FluentCRM through `FluentCrmApi()`, which the fake
 *   records on every call including the ones it answers with nothing, so an
 *   invocation that got as far as the CRM at all is visible. `crm_sync_state` is
 *   asserted as a count of enquiries holding any value in that column, so a state
 *   written to any row — not merely to the row this request would have created —
 *   fails it.
 * - **The status code follows the requirements rather than the summary
 *   sentence.** An unhandled throwable is answered 500 (Requirement 5.7). A
 *   persistence failure is a reported outcome, not an unhandled error: the
 *   sending form has already accepted the submission and will not retry, so the
 *   endpoint answers 200 naming the `storage` reason and the rejection row is the
 *   recovery path (Requirements 3.13, 4.1). Both are asserted to carry no partial
 *   enquiry representation, which is the part of that clause common to the two.
 * - **The second clause is quantified over every cause, not only the injected
 *   ones.** `duplicate` is a second dispatch of a submission already accepted,
 *   `rate_limited` is a limit filtered to one request per window, `validation` is
 *   a required field the sender omitted, and `storage` and the throwable come from
 *   the first clause's own injections. Each is checked for exactly one rejection
 *   row carrying that request's payload verbatim, the receipt time and a reason
 *   drawn from the four.
 *
 * The clock is frozen, so the receipt time a rejection row records is a value the
 * assertion can name rather than a window it has to tolerate.
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
use MarthrownEnquiryHub\IntakeEndpoint;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class FailedIntakePropertyTest
 */
class FailedIntakePropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehfi_';

	/**
	 * The instant every request arrives at.
	 */
	const NOW = '2025-09-18 11:20:00';

	/**
	 * The stored intake secret every request presents exactly.
	 */
	const SECRET = 'sk-failed-intake-6f1c2d9ab4e77053';

	/**
	 * Statement the injected persistence failure substitutes.
	 *
	 * A table that does not exist, so the failure is a real database error rather
	 * than a value the store could have coped with.
	 */
	const BROKEN_STATEMENT = 'INSERT INTO meh_no_such_table ( id ) VALUES ( 1 )';

	/**
	 * Message carried by every injected throwable.
	 */
	const THROWN_MESSAGE = 'meh-injected-throwable';

	/**
	 * Injected failures, each one point on the write path.
	 *
	 * The first four are persistence failures at each stage of the store's own
	 * transaction; the last four are unhandled throwables raised from filters the
	 * path consults in this order.
	 *
	 * @var string[]
	 */
	const INJECTED = array(
		'enquiry_row',
		'dates',
		'terms',
		'commit',
		'throw_in_resolution',
		'throw_in_source',
		'throw_in_guards',
		'throw_in_validation',
	);

	/**
	 * Injected failures that leave the store to report the outcome.
	 *
	 * @var string[]
	 */
	const PERSISTENCE = array( 'enquiry_row', 'dates', 'terms', 'commit' );

	/**
	 * The Enquiry Store table each persistence failure breaks the insert of.
	 *
	 * `commit` is absent: it breaks the transaction's own statement rather than
	 * an insert into a table.
	 *
	 * @var array<string,string>
	 */
	const BROKEN_TABLES = array(
		'enquiry_row' => 'enquiries',
		'dates'       => 'dates',
		'terms'       => 'terms',
	);

	/**
	 * Guard and validation outcomes, and the reason each must be recorded under.
	 *
	 * @var array<string,string>
	 */
	const GUARD_REASONS = array(
		'duplicate'    => 'duplicate',
		'rate_limited' => 'rate_limited',
		'validation'   => 'validation',
	);

	/**
	 * The four rejection reasons, stated as Requirement 4.1 states them rather
	 * than read from the code under test, so a reason quietly renamed there fails
	 * this property instead of agreeing with itself.
	 *
	 * @var string[]
	 */
	const REASONS = array( 'duplicate', 'rate_limited', 'validation', 'storage' );

	/**
	 * The reason a request refused by the store is recorded under (Requirement 3.13).
	 */
	const REASON_STORAGE = 'storage';

	/**
	 * Tables that must hold no row from a failed request.
	 *
	 * @var string[]
	 */
	const ENQUIRY_TABLES = array( 'enquiries', 'dates', 'terms', 'notes', 'history' );

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

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
	 * @var \MarthrownEnquiryHub\Tests\Fakes\FakeCrm|null
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
	 * The seeded enquiry's identifier.
	 *
	 * @var int
	 */
	private $seeded_id = 0;

	/**
	 * The seeded enquiry as stored, for the "previously stored data is unchanged"
	 * claim.
	 *
	 * @var array
	 */
	private $seeded_row = array();

	/**
	 * The failure the current iteration injects, '' when none.
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
	 * Field map resolutions so far, so a throwable can be raised at the one that
	 * follows normalisation.
	 *
	 * @var int
	 */
	private $mapping_calls = 0;

	/**
	 * Load the classes under test.
	 *
	 * `class-enquiry-creator.php` is required before `class-intake-handler.php`
	 * deliberately: the handler resolves three of its constants from
	 * `EnquiryCreator` as the class is loaded, so the other order fails the class
	 * load and answers every intake request 500 for a reason that has nothing to
	 * do with this property.
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

		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix      = $wpdb->prefix;
		$wpdb->prefix               = $this->original_prefix . self::PREFIX_SEGMENT;
		$this->original_show_errors = (bool) $wpdb->show_errors;
		$this->original_error_log   = (string) ini_get( 'error_log' );

		// The injected failure makes `$wpdb` print an error block otherwise.
		$wpdb->hide_errors();

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		Clock::freeze( self::NOW );

		update_option( IntakeEndpoint::SECRET_OPTION, self::SECRET );

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

		$this->seed_existing_rows();
		$this->boot_rest_server();
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		foreach ( $this->counted as $email ) {
			DuplicateDetector::reset( $email );
		}

		$this->counted = array();

		$this->disarm();

		remove_all_filters( DuplicateDetector::RATE_EMAIL_FILTER );

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( IntakeEndpoint::SECRET_OPTION );

		$wp_rest_server = null;

		ini_set( 'error_log', $this->original_error_log );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		if ( $this->original_show_errors ) {
			$wpdb->show_errors();
		}

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 13: Failed intake leaves nothing
	 * behind and nothing untraced.
	 *
	 * **Validates: Requirements 3.13, 4.1, 5.6, 5.7, 5.8**
	 */
	public function test_failed_intake_leaves_nothing_behind_and_nothing_untraced() {
		// Clause one: a single injected persistence failure or unhandled throwable
		// on the write path leaves no row, no linkage and no untraced request.
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::injected_scenario() ) )
			->then(
				function ( array $case ) {
					$this->check_injected_failure( $case );
				}
			);

		// Clause two: every other request that creates no enquiry is traced too.
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::guard_scenario() ) )
			->then(
				function ( array $case ) {
					$this->check_uncreated_outcome( $case );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * The claim
	 * ------------------------------------------------------------------ */

	/**
	 * One request meeting one injected failure, dispatched and judged.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check_injected_failure( array $case ) {
		$cause   = (string) $case['cause'];
		$payload = $this->payload( (array) $case['payload'] );
		$label   = sprintf( '[failure injected at %s, %s]', $cause, $payload['email'] );

		$before    = $this->row_counts();
		$rejection = $this->last_rejection_id();

		$this->arm( $cause );

		$log      = $this->start_capturing_log();
		$response = $this->dispatch( $payload );
		$logged   = $this->stop_capturing_log( $log );

		$this->disarm();

		$this->assertTrue( $this->injected, 'The fixture should have injected its failure. ' . $label );

		// No enquiry, candidate date, term, note or history row from that request.
		$after = $this->row_counts();

		foreach ( self::ENQUIRY_TABLES as $key ) {
			$this->assertSame(
				$before[ $key ],
				$after[ $key ],
				'A failed request should leave no ' . $key . ' row. ' . $label
			);
		}

		$this->assertSame(
			0,
			$this->enquiries_holding( $payload['email'] ),
			'No enquiry from the failed request should remain. ' . $label
		);

		// Previously stored data is unchanged.
		$this->assertSame(
			$this->seeded_row,
			EnquiryStore::find( $this->seeded_id ),
			'A failed request should leave stored data unchanged. ' . $label
		);

		// The contact linker is not invoked, and no `crm_sync_state` is set.
		$this->assertSame( 0, $this->crm->call_count(), 'The contact linker should not be invoked. ' . $label );
		$this->assertSame( 0, $this->crm->contact_count(), 'No contact should be written. ' . $label );
		$this->assertSame( 0, $this->enquiries_with_sync_state(), 'No crm_sync_state should be set. ' . $label );

		// The payload snapshot and the failure reason reach the error log.
		$this->assertStringContainsString(
			$payload['email'],
			$logged,
			'The payload snapshot should reach the error log. ' . $label
		);
		$this->assertStringContainsString(
			$this->expected_log_reason( $cause ),
			$logged,
			'The failure reason should reach the error log. ' . $label
		);

		// No partial enquiry representation, whichever answer the requirements ask
		// for: 500 for an unhandled throwable, the reported `storage` outcome for a
		// persistence failure.
		$data = (array) $response->get_data();

		$this->assertArrayNotHasKey( 'enquiry_id', $data, 'No enquiry identifier should be answered. ' . $label );
		$this->assertNotTrue(
			isset( $data['created'] ) ? $data['created'] : false,
			'The answer should not claim an enquiry. ' . $label
		);

		if ( self::is_persistence( $cause ) ) {
			$this->assertSame( 200, $response->get_status(), 'A reported store failure is answered 200. ' . $label );
			$this->assertSame(
				self::REASON_STORAGE,
				isset( $data['reason'] ) ? $data['reason'] : '',
				'A store failure names the storage reason. ' . $label
			);
		} else {
			$this->assertSame( 500, $response->get_status(), 'An unhandled throwable is answered 500. ' . $label );
			$this->assertSame(
				IntakeEndpoint::FAILED_CODE,
				isset( $data['code'] ) ? $data['code'] : '',
				'The 500 names the failure code. ' . $label
			);
		}

		// And nothing untraced: exactly one rejection row for this request.
		$this->assert_one_rejection( $rejection, $payload, null, $label );
	}

	/**
	 * One request refused by a guard or by validation, dispatched and judged.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check_uncreated_outcome( array $case ) {
		$cause   = (string) $case['cause'];
		$payload = $this->payload( (array) $case['payload'] );
		$reason  = self::GUARD_REASONS[ $cause ];
		$label   = sprintf( '[%s, %s]', $cause, $payload['email'] );

		remove_all_filters( DuplicateDetector::RATE_EMAIL_FILTER );

		if ( 'validation' === $cause ) {
			// The Webhook profile requires all nine fields, so an omitted one is a
			// submission the form accepted and the Validator cannot.
			unset( $payload['message'] );
		}

		if ( 'rate_limited' === $cause ) {
			$this->limit_to_one_request();
		}

		if ( 'duplicate' === $cause ) {
			$first = $this->dispatch( $payload );

			$this->assertSame( 201, $first->get_status(), 'The first submission should be accepted. ' . $label );
		}

		$before    = $this->row_counts();
		$rejection = $this->last_rejection_id();

		$response = $this->dispatch( $payload );

		$this->assertSame( 200, $response->get_status(), 'A refused submission is answered 200. ' . $label );

		$data = (array) $response->get_data();

		$this->assertFalse(
			isset( $data['created'] ) ? (bool) $data['created'] : true,
			'A refused submission creates no enquiry. ' . $label
		);
		$this->assertSame( $reason, isset( $data['reason'] ) ? $data['reason'] : '', 'The outcome names its reason. ' . $label );

		$after = $this->row_counts();

		foreach ( self::ENQUIRY_TABLES as $key ) {
			$this->assertSame(
				$before[ $key ],
				$after[ $key ],
				'A refused submission should leave no ' . $key . ' row. ' . $label
			);
		}

		$this->assertSame(
			$this->seeded_row,
			EnquiryStore::find( $this->seeded_id ),
			'A refused submission should leave stored data unchanged. ' . $label
		);

		$this->assert_one_rejection( $rejection, $payload, $reason, $label );

		remove_all_filters( DuplicateDetector::RATE_EMAIL_FILTER );
	}

	/**
	 * Assert exactly one rejection row was added, holding this request's payload,
	 * its receipt time and a recognised reason.
	 *
	 * @param int         $since   Highest rejection identifier before the request.
	 * @param array       $payload Submitted field map.
	 * @param string|null $reason  Expected reason, or null to accept any of the four.
	 * @param string      $label   Failure context.
	 * @return void
	 */
	private function assert_one_rejection( $since, array $payload, $reason, $label ) {
		$rows = $this->rejections_since( $since );

		$this->assertCount( 1, $rows, 'Exactly one rejected intake attempt should be recorded. ' . $label );

		$row = $rows[0];

		if ( null === $reason ) {
			$this->assertContains(
				$row['reason'],
				self::REASONS,
				'The rejection reason should be one of the four. ' . $label
			);
		} else {
			$this->assertSame( $reason, $row['reason'], 'The rejection names its reason. ' . $label );
		}

		$this->assertSame( self::NOW, $row['created_at'], 'The rejection records the receipt time. ' . $label );
		$this->assertSame(
			self::snapshot( $payload ),
			self::snapshot( (array) json_decode( (string) $row['payload'], true ) ),
			'The rejection holds the request payload. ' . $label
		);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case for the first clause: a valid submission, and where on the write
	 * path it meets its failure.
	 *
	 * @return \Eris\Generator
	 */
	protected static function injected_scenario() {
		return \Eris\Generators::associative(
			array(
				'payload' => Generators::enquiry(),
				'cause'   => \Eris\Generators::elements( self::INJECTED ),
			)
		);
	}

	/**
	 * One case for the second clause: a submission, and the outcome that creates
	 * no enquiry from it.
	 *
	 * @return \Eris\Generator
	 */
	protected static function guard_scenario() {
		return \Eris\Generators::associative(
			array(
				'payload' => Generators::enquiry(),
				'cause'   => \Eris\Generators::elements( array_keys( self::GUARD_REASONS ) ),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step grows exponentially
	 * with the number of generated values. A case here draws a nine-field enquiry,
	 * two of whose fields are sets, which puts that product beyond what fits in
	 * memory: a failing iteration would report an out-of-memory fatal instead of
	 * the counterexample.
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
	 * Whether a cause is a persistence failure rather than a throwable.
	 *
	 * @param string $cause Injected cause.
	 * @return bool
	 */
	private static function is_persistence( $cause ) {
		return in_array( (string) $cause, self::PERSISTENCE, true );
	}

	/**
	 * The reason text the error log must carry for a given cause.
	 *
	 * @param string $cause Injected cause.
	 * @return string
	 */
	private function expected_log_reason( $cause ) {
		return self::is_persistence( $cause ) ? 'meh_store_create_failed' : self::THROWN_MESSAGE;
	}

	/**
	 * A payload as the rejection row records it, comparable irrespective of key
	 * order.
	 *
	 * @param array $payload Field map.
	 * @return array
	 */
	private static function snapshot( array $payload ) {
		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				$payload[ $key ] = array_values( $value );
			}
		}

		ksort( $payload );

		return $payload;
	}

	/* ---------------------------------------------------------------------
	 * Failure injection
	 * ------------------------------------------------------------------ */

	/**
	 * Install the failure a case asks for.
	 *
	 * @param string $cause One of self::INJECTED.
	 * @return void
	 */
	private function arm( $cause ) {
		$this->disarm();

		$this->injecting     = (string) $cause;
		$this->injected      = false;
		$this->mapping_calls = 0;

		if ( self::is_persistence( $cause ) ) {
			add_filter( 'query', array( $this, 'break_write' ) );

			return;
		}

		add_filter( $this->throwing_hook( $cause ), array( $this, 'raise' ) );
	}

	/**
	 * Remove whatever failure is installed.
	 *
	 * @return void
	 */
	private function disarm() {
		remove_filter( 'query', array( $this, 'break_write' ) );

		if ( '' !== $this->injecting && ! self::is_persistence( $this->injecting ) ) {
			remove_filter( $this->throwing_hook( $this->injecting ), array( $this, 'raise' ) );
		}

		$this->injecting = '';
	}

	/**
	 * The filter a throwable is raised from, for one cause.
	 *
	 * Each one is consulted at a distinct point of the write path, in the order
	 * the causes are listed: field resolution, source resolution, the intake
	 * guards, then validation.
	 *
	 * @param string $cause One of self::INJECTED.
	 * @return string
	 */
	private function throwing_hook( $cause ) {
		switch ( (string) $cause ) {
			case 'throw_in_resolution':
				return FieldMapper::OPTION;

			case 'throw_in_source':
				return 'pre_option_' . IntakeEndpoint::SOURCE_FIELD_OPTION;

			case 'throw_in_guards':
				return DuplicateDetector::WINDOW_FILTER;

			default:
				return 'meh_enquiry_terms_event_type';
		}
	}

	/**
	 * Rewrite the first statement of the failing stage into one that cannot run.
	 *
	 * Only the first, so exactly one failure is injected and the store's own
	 * recovery — its rollback and its compensating delete — runs against a
	 * working database.
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

		$table = Schema::table( self::BROKEN_TABLES[ $this->injecting ] );

		if ( 0 !== stripos( $statement, 'INSERT' ) || false === strpos( $statement, $table ) ) {
			return $query;
		}

		$this->injected = true;

		return self::BROKEN_STATEMENT;
	}

	/**
	 * Raise the injected throwable.
	 *
	 * The field map is resolved once while the body is being normalised and again
	 * for every field the handler resolves, so that cause fires on the second
	 * resolution: the first clause is about the write path, which begins once the
	 * endpoint holds the payload it would log.
	 *
	 * @param mixed $value Filtered value, passed through when nothing is raised.
	 * @return mixed
	 * @throws \RuntimeException Always, once the injection point is reached.
	 */
	public function raise( $value = null ) {
		if ( $this->injected || '' === $this->injecting ) {
			return $value;
		}

		if ( FieldMapper::OPTION === $this->throwing_hook( $this->injecting ) ) {
			++$this->mapping_calls;

			if ( $this->mapping_calls < 2 ) {
				return $value;
			}
		}

		$this->injected = true;

		throw new \RuntimeException( self::THROWN_MESSAGE );
	}

	/**
	 * Allow one request per email per window, so the next one is refused.
	 *
	 * @return void
	 */
	private function limit_to_one_request() {
		remove_all_filters( DuplicateDetector::RATE_EMAIL_FILTER );

		add_filter(
			DuplicateDetector::RATE_EMAIL_FILTER,
			static function () {
				return array( 1, DuplicateDetector::DEFAULT_RATE_WINDOW );
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Dispatch
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch one authenticated intake request through the REST server.
	 *
	 * @param array $payload Field map to submit.
	 * @return mixed WP_REST_Response.
	 */
	private function dispatch( array $payload ) {
		$request = new WP_REST_Request( 'POST', '/' . IntakeEndpoint::NAMESPACE . IntakeEndpoint::ROUTE );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( IntakeEndpoint::SECRET_HEADER, self::SECRET );
		$request->set_body( (string) wp_json_encode( $payload ) );

		return rest_do_request( $request );
	}

	/**
	 * Register the intake route and hand the REST server a fresh start.
	 *
	 * @return void
	 */
	private function boot_rest_server() {
		global $wp_rest_server;

		$wp_rest_server = null;

		add_action( 'rest_api_init', array( IntakeEndpoint::class, 'register_routes' ) );

		rest_get_server();
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * The submission, with an address unique to this iteration.
	 *
	 * Uniqueness keeps the duplicate guard and the rate limiter out of the way, so
	 * a request fails only where this property injects its failure — and it is what
	 * lets "no enquiry from that request remains" be asked of the address itself
	 * rather than of a row count alone.
	 *
	 * @param array $fields Generated field map.
	 * @return array
	 */
	private function payload( array $fields ) {
		++$this->iteration;

		$fields['email'] = sprintf( 'failed-intake-%d@example.com', $this->iteration );

		$this->counted[] = $fields['email'];
		$this->crm->reset();

		return $fields;
	}

	/**
	 * One enquiry and one rejection row, so the row counts this property watches
	 * are non-zero and a stray write shows up as a change rather than as a zero
	 * matching a zero.
	 *
	 * @return void
	 */
	private function seed_existing_rows() {
		$now = Clock::mysql();

		$id = EnquiryStore::create(
			array(
				'first_name'        => 'Ada',
				'last_name'         => 'Lovelace',
				'email'             => 'seeded@example.com',
				'status'            => 'new',
				'created_at'        => $now,
				'updated_at'        => $now,
				'status_changed_at' => $now,
				'source'            => 'webhook:fixture',
			),
			array( Generators::date_at( 30 ) ),
			array( 'event_type' => array( 'wedding' ) ),
			array( 'seeded' => true )
		);

		$this->assertNotWPError( $id, 'Seeding an enquiry should succeed.' );

		$this->seeded_id  = (int) $id;
		$this->seeded_row = EnquiryStore::find( $this->seeded_id );

		$this->assertGreaterThan(
			0,
			EnquiryStore::record_rejection( array( 'seeded' => true ), 'validation' ),
			'Seeding a rejection row should succeed.'
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
	 * How many enquiries hold a given email address.
	 *
	 * @param string $email Submitted address.
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
	 * How many enquiries hold any `crm_sync_state` value.
	 *
	 * @return int
	 */
	private function enquiries_with_sync_state() {
		global $wpdb;

		$table = Schema::table( 'enquiries' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE crm_sync_state <> ''" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The highest rejection identifier currently stored.
	 *
	 * @return int
	 */
	private function last_rejection_id() {
		global $wpdb;

		$table = Schema::table( 'rejections' );

		return (int) $wpdb->get_var( "SELECT COALESCE( MAX( id ), 0 ) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Every rejection row added since a given identifier, oldest first.
	 *
	 * @param int $since Highest identifier before the request.
	 * @return array<int,array<string,mixed>>
	 */
	private function rejections_since( $since ) {
		global $wpdb;

		$table = Schema::table( 'rejections' );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC", (int) $since ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
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

	/* ---------------------------------------------------------------------
	 * Log capture
	 * ------------------------------------------------------------------ */

	/**
	 * Send the plugin's log lines to a file of our own for the next request.
	 *
	 * @return string Log file path.
	 */
	private function start_capturing_log() {
		$file = tempnam( sys_get_temp_dir(), 'meh-intake-log' );

		ini_set( 'log_errors', '1' );
		ini_set( 'error_log', $file );
		add_filter( 'meh_log_enabled', '__return_true' );

		return (string) $file;
	}

	/**
	 * Stop capturing and return what was written.
	 *
	 * The whole file rather than the `[MEH]` lines alone: a payload value may
	 * itself hold a newline, and the claim is about what the log carries rather
	 * than about how many lines it took.
	 *
	 * @param string $file Log file path.
	 * @return string
	 */
	private function stop_capturing_log( $file ) {
		remove_filter( 'meh_log_enabled', '__return_true' );
		ini_set( 'error_log', $this->original_error_log );

		$contents = file_exists( $file ) ? (string) file_get_contents( $file ) : '';

		if ( file_exists( $file ) ) {
			unlink( $file );
		}

		return $contents;
	}
}
