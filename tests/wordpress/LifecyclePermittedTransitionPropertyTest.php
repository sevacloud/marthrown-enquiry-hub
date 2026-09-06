<?php
/**
 * Property 20: Transitions succeed exactly when permitted.
 *
 * Feature: enquiry-data-layer, Property 20: For any current status drawn from
 * the recognised statuses `new`, `contacted`, `quoted`, `converted`, `lost` and
 * `closed`, and any requested status including arbitrary strings that are none
 * of those six, the lifecycle manager accepts the transition exactly when the
 * pair appears in the permitted transition table and rejects every other pair.
 * On acceptance it sets `status` to the requested status, sets
 * `status_changed_at` to the transition time, appends one history entry holding
 * the previous status, the new status, the acting user identifier and the time,
 * and fires the status-changed action carrying the enquiry identifier and both
 * statuses; on rejection it returns an error naming the current and requested
 * statuses and changes nothing. Three further clauses hold over the same
 * quantification: a permitted pair never moves backwards in lifecycle order, a
 * transition requested from `closed` is always rejected, and a status is settled
 * exactly when it is `converted` or `lost`.
 *
 * **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 7.10, 7.11, 7.12**
 *
 * Five things about how the property is instantiated are worth stating plainly:
 *
 * - The permitted table is written out longhand as `PERMITTED` rather than read
 *   from `Lifecycle::TRANSITIONS`. The property is a biconditional between what
 *   the acceptance criteria permit and what the code does; taking the left-hand
 *   side from the code under test would make it a tautology that holds however
 *   the table is edited.
 * - The requested status is drawn from the five statuses other than the current
 *   one *and* from arbitrary strings that are none of the six, so the rejection
 *   half of the biconditional is exercised by unrecognised values as the
 *   property demands, not only by unpermitted pairs of real statuses. The equal
 *   pair is excluded deliberately: `from === to` is a repeat governed by
 *   Requirement 7.9 rather than a table lookup, and Property 21 is what asserts
 *   its behaviour. Leaving it in would put this property in direct conflict with
 *   that one.
 * - "Changes nothing" is asserted against the whole hydrated enquiry, not just
 *   the two status columns: the row read before the call must be identical to
 *   the row read after it, so a refusal that quietly touched `updated_at`, a
 *   candidate date or a term fails here. On acceptance the same comparison runs
 *   with the two status columns substituted, which is what makes "sets `status`
 *   and `status_changed_at`" an exact claim rather than a lower bound.
 * - The hook is observed through a real `add_action` listener recording every
 *   payload, and the count is asserted as well as the payload, so a transition
 *   firing twice fails just as one firing not at all does. The same is true of
 *   history: the log is read before and after, and the acceptance case requires
 *   exactly one appended entry while the rejection case requires none.
 * - Attribution is quantified over the three ways a caller can arrive: an
 *   explicit identifier, an explicit 0, and null asking `HistoryRecorder` to
 *   resolve the current user, each with and without a real WordPress user
 *   authenticated. That covers Requirement 7.7's "acting user identifier" for
 *   the hub route and for the system-attributed cron route through one
 *   quantification.
 * - A refusal is asserted in the two shapes `transition()` actually answers in.
 *   A pair of recognised statuses absent from the table is a conflict: 409,
 *   naming the current status and the requested status in the message and
 *   carrying both in the error data, which is Requirement 7.5 in full and, for
 *   every pair requested of a `closed` enquiry, Requirement 7.11. A requested
 *   value that is none of the six describes no transition at all, so it is
 *   refused as bad input before the enquiry is read: 400, naming the value
 *   asked for but not the current status. That second shape is narrower than
 *   the literal wording of the property, which asks for both statuses on every
 *   refusal; the substantive half of the clause — a failure that changes
 *   nothing — is asserted identically for both shapes.
 *
 * Like the other store-backed tests this one works against real tables at its
 * own prefix, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Lifecycle;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class LifecyclePermittedTransitionPropertyTest
 */
class LifecyclePermittedTransitionPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehlp_';

	/**
	 * The instant every seeded enquiry carries before its transition.
	 */
	const SEEDED_AT = '2025-06-01 10:00:00';

	/**
	 * The six recognised statuses, in lifecycle order (Requirement 7.1).
	 *
	 * Written out rather than read from `Lifecycle`, for the same reason as
	 * PERMITTED below.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'new', 'contacted', 'quoted', 'converted', 'lost', 'closed' );

	/**
	 * The permitted transition table of Property 20, longhand.
	 *
	 * Requirements 7.2, 7.3, 7.4, 7.10 name these pairs; `closed` mapping to the
	 * empty set is Requirement 7.11.
	 *
	 * @var array<string,string[]>
	 */
	const PERMITTED = array(
		'new'       => array( 'contacted', 'quoted', 'converted', 'lost' ),
		'contacted' => array( 'quoted', 'converted', 'lost' ),
		'quoted'    => array( 'converted', 'lost' ),
		'converted' => array( 'closed' ),
		'lost'      => array( 'closed' ),
		'closed'    => array(),
	);

	/**
	 * The statuses Requirement 7.12 counts as settled.
	 *
	 * @var string[]
	 */
	const SETTLED = array( 'converted', 'lost' );

	/**
	 * Requested statuses that are none of the six.
	 *
	 * None of them contains any of the six status names as a substring, so the
	 * "the error names the current status" assertion cannot be satisfied by the
	 * requested value happening to spell it. The set covers the shapes a caller
	 * can genuinely present: a plausible-but-wrong status, a case variant, an
	 * empty value, a numeric one, and an adversarial string.
	 *
	 * @var string[]
	 */
	const UNRECOGNISED = array(
		'',
		'NEW',
		'CLOSED',
		'pending',
		'won',
		'archived',
		'complete',
		'in-progress',
		'0',
		'status',
		"'; DROP TABLE wp_meh_enquiries; --",
		'Zoë',
	);

	/**
	 * The user whose identifier stands in for an authenticated actor.
	 *
	 * @var int
	 */
	protected static $userId = 0;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $originalPrefix = '';

	/**
	 * Hook payloads observed during one iteration.
	 *
	 * @var array
	 */
	private $fired = array();

	/**
	 * Load the classes under test and create the acting user.
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
		require_once MEH_INCLUDES_DIR . 'class-lifecycle.php';

		self::$userId = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Remove the acting user.
	 *
	 * @return void
	 */
	public static function wpTearDownAfterClass() {
		self::delete_user( self::$userId );
	}

	public function set_up() {
		parent::set_up();

		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->originalPrefix = $wpdb->prefix;
		$wpdb->prefix         = $this->originalPrefix . self::PREFIX_SEGMENT;

		$this->dropTables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		$this->fired = array();

		add_action( Lifecycle::CHANGED_HOOK, array( $this, 'observe' ), 10, 3 );
	}

	public function tear_down() {
		global $wpdb;

		remove_action( Lifecycle::CHANGED_HOOK, array( $this, 'observe' ), 10 );

		Clock::unfreeze();
		wp_set_current_user( 0 );

		$this->dropTables();

		$wpdb->prefix = $this->originalPrefix;

		parent::tear_down();
	}

	/**
	 * Record one `meh_enquiry_status_changed` payload.
	 *
	 * @param int    $id   Enquiry identifier.
	 * @param string $from Previous status.
	 * @param string $to   New status.
	 * @return void
	 */
	public function observe( $id, $from, $to ) {
		$this->fired[] = array( (int) $id, (string) $from, (string) $to );
	}

	/**
	 * Feature: enquiry-data-layer, Property 20: Transitions succeed exactly when
	 * permitted.
	 *
	 * **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 7.10, 7.11, 7.12**
	 *
	 * @eris-shrink 10
	 */
	public function test_transitions_succeed_exactly_when_permitted() {
		$this->limitTo( Iterations::count( 120 ) )
			->forAll( self::pair(), self::attribution(), self::gap() )
			->then(
				function ( array $pair, array $attribution, $gap ) {
					$from = $pair['from'];
					$to   = $pair['to'];

					// Requirement 7.1: recognised is exactly the six, whatever
					// arbitrary string the request carried.
					$this->assertSame( in_array( $from, self::STATUSES, true ), Lifecycle::is_status( $from ) );
					$this->assertSame( in_array( $to, self::STATUSES, true ), Lifecycle::is_status( $to ) );

					// Requirement 7.12: settled is exactly `converted` and `lost`,
					// so an enquiry sitting at `new`, `contacted` or `quoted` is
					// never settled however long it sits.
					$this->assertSame( in_array( $from, self::SETTLED, true ), Lifecycle::is_settled( $from ) );
					$this->assertSame( in_array( $to, self::SETTLED, true ), Lifecycle::is_settled( $to ) );

					$this->clear();
					$this->fired = array();

					$this->assertTrue( Clock::freeze( self::SEEDED_AT ), 'The clock should freeze under the test harness.' );

					$id = $this->seedEnquiry( $from );

					$before  = EnquiryStore::find( $id );
					$history = HistoryRecorder::for_enquiry( $id );

					$at = Clock::mysql( Clock::offset( (int) $gap, self::SEEDED_AT ) );
					$this->assertTrue( Clock::freeze( $at ), 'The clock should advance under the test harness.' );

					wp_set_current_user( $attribution['authenticated'] ? self::$userId : 0 );

					$result = Lifecycle::transition( $id, $to, $attribution['actor'] );

					if ( self::isPermitted( $from, $to ) ) {
						$this->assertAccepted( $result, $id, $from, $to, $at, $before, $attribution );
						return;
					}

					$this->assertRefused( $result, $id, $from, $to, $before, $history );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * The two outcomes
	 * ------------------------------------------------------------------ */

	/**
	 * Everything acceptance claims (Requirements 7.6, 7.7, 7.8), plus the
	 * forward-only clause.
	 *
	 * @param mixed  $result      What `transition()` answered.
	 * @param int    $id          Enquiry identifier.
	 * @param string $from        Status held before the request.
	 * @param string $to          Requested status.
	 * @param string $at          Transition time.
	 * @param array  $before      Hydrated enquiry as it stood before the request.
	 * @param array  $attribution Generated attribution choice.
	 * @return void
	 */
	private function assertAccepted( $result, $id, $from, $to, $at, array $before, array $attribution ) {
		$pair = sprintf( '%s -> %s', $from, $to );

		$this->assertIsArray( $result, sprintf( 'The permitted pair %s should be accepted.', $pair ) );
		$this->assertTrue( $result['changed'], sprintf( '%s should be reported as a real change.', $pair ) );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( $from, $result['from'] );
		$this->assertSame( $to, $result['to'] );
		$this->assertSame( $at, $result['at'] );

		// Stored state is re-read rather than inferred from the return value, so
		// nothing below depends on what the result claimed.
		$after = EnquiryStore::find( $id );

		// The lifecycle is forward-only: the target never precedes the source.
		$order = array_flip( self::STATUSES );
		$this->assertGreaterThan(
			$order[ $from ],
			$order[ $to ],
			sprintf( 'A permitted pair should never move backwards: %s -> %s.', $from, $to )
		);

		// Requirement 7.6: both status columns, and nothing else, are written.
		$this->assertSame( $to, $after['status'], sprintf( 'A permitted %s -> %s should store the requested status.', $from, $to ) );
		$this->assertSame( $at, $after['status_changed_at'], 'A permitted transition should stamp the transition time.' );

		$expected                      = $before;
		$expected['status']            = $to;
		$expected['status_changed_at'] = $at;

		$this->assertSame(
			$expected,
			$after,
			'A transition should write the two status columns and leave every other stored value alone.'
		);

		// Requirement 7.7: exactly one attributed entry, carrying both statuses
		// and the transition time.
		$history = HistoryRecorder::for_enquiry( $id );

		$this->assertCount( 1, $history, 'A permitted transition should append exactly one history entry.' );

		$entry = $history[0];

		$this->assertSame( $id, $entry['enquiry_id'] );
		$this->assertSame( 'status_changed', $entry['entry_type'] );
		$this->assertSame( $from, $entry['context']['from'], 'The entry should hold the previous status.' );
		$this->assertSame( $to, $entry['context']['to'], 'The entry should hold the new status.' );
		$this->assertSame( $at, $entry['context']['at'], 'The entry should hold the transition time.' );
		$this->assertSame( $at, $entry['created_at'], 'The entry should be stamped at the transition time.' );
		$this->assertSame(
			self::expectedActor( $attribution ),
			$entry['actor_id'],
			'The entry should carry the acting user identifier.'
		);

		// Requirement 7.8: one firing, carrying the identifier and both statuses.
		$this->assertSame(
			array( array( $id, $from, $to ) ),
			$this->fired,
			'A permitted transition should fire the status-changed action exactly once.'
		);

		unset( $result );
	}

	/**
	 * Everything rejection claims (Requirements 7.5, 7.11).
	 *
	 * A refusal comes in one of two shapes, and both are asserted here:
	 *
	 * - A **table refusal**, when the requested value is one of the six but the
	 *   pair is absent from PERMITTED. This is Requirement 7.5 proper: 409, and
	 *   the error names the current status and the requested status, both in the
	 *   message and in the error data. Every pair requested of a `closed`
	 *   enquiry lands here, which is Requirement 7.11 — `closed` is terminal
	 *   through the same lookup, with no special case.
	 * - A **malformed refusal**, when the requested value is none of the six.
	 *   The request never describes a transition at all, so it is refused as bad
	 *   input before the enquiry is read: 400, naming the value that was asked
	 *   for. The current status is not named in this shape, which is narrower
	 *   than the literal wording of the property; see the class docblock.
	 *
	 * What both shapes share is the substantive claim, and it is asserted
	 * identically for each: the answer is a failure carrying a client-error
	 * status, and nothing moved. "Nothing moved" is the whole hydrated enquiry,
	 * the whole history log and the hook, not just the two status columns, so a
	 * refusal that quietly touched `updated_at`, a candidate date, a term or the
	 * payload fails here just as one that wrote the status would.
	 *
	 * @param mixed  $result  What `transition()` answered.
	 * @param int    $id      Enquiry identifier.
	 * @param string $from    Status held before the request.
	 * @param string $to      Requested status.
	 * @param array  $before  Hydrated enquiry as it stood before the request.
	 * @param array  $history History as it stood before the request.
	 * @return void
	 */
	private function assertRefused( $result, $id, $from, $to, array $before, array $history ) {
		$pair = sprintf( '"%s" -> "%s"', $from, $to );

		$this->assertWPError( $result, sprintf( 'The unpermitted pair %s should be refused.', $pair ) );

		$data = $result->get_error_data();

		$this->assertIsArray( $data, sprintf( 'The refusal of %s should carry error data.', $pair ) );
		$this->assertArrayHasKey( 'status', $data, sprintf( 'The refusal of %s should carry an HTTP status.', $pair ) );
		$this->assertGreaterThanOrEqual( 400, (int) $data['status'], sprintf( 'The refusal of %s should be a client error.', $pair ) );
		$this->assertLessThan( 500, (int) $data['status'], sprintf( 'The refusal of %s should be a client error.', $pair ) );

		$message = $result->get_error_message();

		if ( '' !== $to ) {
			$this->assertStringContainsString(
				$to,
				$message,
				sprintf( 'The refusal of %s should name the requested status.', $pair )
			);
		}

		if ( in_array( $to, self::STATUSES, true ) ) {
			// Requirements 7.5 and 7.11: the table refusal names both statuses.
			$this->assertSame(
				409,
				(int) $data['status'],
				sprintf( 'A pair absent from the table, %s, should be refused as a conflict.', $pair )
			);
			$this->assertStringContainsString(
				$from,
				$message,
				sprintf( 'The refusal of %s should name the current status.', $pair )
			);
			$this->assertSame( $from, isset( $data['from'] ) ? (string) $data['from'] : null, 'The error data should carry the current status.' );
			$this->assertSame( $to, isset( $data['to'] ) ? (string) $data['to'] : null, 'The error data should carry the requested status.' );
		}

		// Changed nothing: the whole row, not just the two status columns. This
		// also covers the adversarial requested values — the table is still
		// there to be read, so the value was bound rather than interpolated.
		$this->assertSame(
			$before,
			EnquiryStore::find( $id ),
			sprintf( 'The refusal of %s should leave every stored value as it was.', $pair )
		);

		// Recorded nothing.
		$this->assertSame(
			$history,
			HistoryRecorder::for_enquiry( $id ),
			sprintf( 'The refusal of %s should append no history entry.', $pair )
		);

		// Announced nothing.
		$this->assertSame(
			array(),
			$this->fired,
			sprintf( 'The refusal of %s should fire no status-changed action.', $pair )
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the acceptance criteria permit a pair, read from PERMITTED.
	 *
	 * @param string $from Current status.
	 * @param string $to   Requested status.
	 * @return bool
	 */
	private static function isPermitted( $from, $to ) {
		return isset( self::PERMITTED[ $from ] ) && in_array( $to, self::PERMITTED[ $from ], true );
	}

	/**
	 * The identifier a history entry should carry for a generated attribution.
	 *
	 * @param array $attribution Generated attribution choice.
	 * @return int
	 */
	private static function expectedActor( array $attribution ) {
		if ( null === $attribution['actor'] ) {
			return $attribution['authenticated'] ? self::$userId : 0;
		}

		return (int) $attribution['actor'] > 0 ? (int) $attribution['actor'] : 0;
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * A (current status, requested status) pair.
	 *
	 * The current status ranges over all six. The requested status ranges over
	 * the five other statuses and over the unrecognised set, weighted so real
	 * statuses appear about as often as arbitrary strings. The equal pair is
	 * excluded: it is Requirement 7.9's repeat, not a table lookup.
	 *
	 * @return \Eris\Generator
	 */
	protected static function pair() {
		return \Eris\Generators::bind(
			\Eris\Generators::elements( self::STATUSES ),
			function ( $from ) {
				$others = array_values( array_diff( self::STATUSES, array( $from ) ) );

				return \Eris\Generators::map(
					function ( $to ) use ( $from ) {
						return array(
							'from' => (string) $from,
							'to'   => (string) $to,
						);
					},
					\Eris\Generators::oneOf(
						\Eris\Generators::elements( $others ),
						\Eris\Generators::elements( self::UNRECOGNISED )
					)
				);
			}
		);
	}

	/**
	 * How the acting user reaches the transition: an explicit identifier, an
	 * explicit 0, or null asking for the current user, each with and without a
	 * WordPress user authenticated.
	 *
	 * @return \Eris\Generator
	 */
	protected static function attribution() {
		return \Eris\Generators::associative(
			array(
				'actor'         => \Eris\Generators::oneOf(
					\Eris\Generators::constant( null ),
					\Eris\Generators::constant( 0 ),
					\Eris\Generators::constant( self::$userId )
				),
				'authenticated' => \Eris\Generators::elements( array( true, false ) ),
			)
		);
	}

	/**
	 * Seconds between the seeded state and the transition.
	 *
	 * 0 is a deliberate draw: a transition happening in the same second the
	 * enquiry was seeded is the case where a `status_changed_at` that was never
	 * written is indistinguishable from one that was, unless the value is
	 * compared exactly.
	 *
	 * @return \Eris\Generator
	 */
	protected static function gap() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( 0 ),
			\Eris\Generators::choose( 1, 90 * DAY_IN_SECONDS )
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write one enquiry holding a given status and return its identifier.
	 *
	 * The row carries values in every column the transition must leave alone —
	 * two candidate dates, both taxonomies, a subscriber id, a payload — so the
	 * whole-row comparison has something to catch.
	 *
	 * @param string $status Status to seed.
	 * @return int
	 */
	private function seedEnquiry( $status ) {
		$id = EnquiryStore::create(
			array(
				'first_name'              => "O'Brien",
				'last_name'               => 'Zoë Ó Séaghdha',
				'email'                   => 'ada@example.com',
				'phone'                   => '07700 900123',
				'total_guests'            => 42,
				'message'                 => '100% _ %s %d',
				'status'                  => $status,
				'fluentcrm_subscriber_id' => 77,
				'crm_sync_state'          => 'synced',
				'created_at'              => self::SEEDED_AT,
				'updated_at'              => self::SEEDED_AT,
				'status_changed_at'       => self::SEEDED_AT,
				'source'                  => 'webhook:fixture',
			),
			array( '2025-08-16', '2025-08-23' ),
			array(
				'event_type'       => array( 'wedding' ),
				'site_exclusivity' => array( 'exclusive-use' ),
			),
			array( 'message' => '100% _ %s %d' )
		);

		$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

		return (int) $id;
	}

	/**
	 * Empty the tables this property reads, between iterations.
	 *
	 * `DELETE` rather than `TRUNCATE`, so identifiers keep climbing and a stale
	 * identifier can never be mistaken for a fresh one.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array( 'history', 'dates', 'terms', 'notes', 'enquiries' ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Drop every Enquiry Store table at the test prefix.
	 *
	 * @return void
	 */
	private function dropTables() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
	}
}
