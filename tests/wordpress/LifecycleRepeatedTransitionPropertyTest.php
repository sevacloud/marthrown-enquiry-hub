<?php
/**
 * Property 21: Repeating a transition is a no-op.
 *
 * Feature: enquiry-data-layer, Property 21: For any enquiry and any permitted
 * transition, applying that same transition request a second time leaves
 * `status` and `status_changed_at` equal to their values after the first
 * application and appends no further history entry.
 *
 * **Validates: Requirements 7.9**
 *
 * Five things about how the property is instantiated are worth stating plainly:
 *
 * - The quantification is over the permitted transition table itself, written
 *   out longhand rather than read from `Lifecycle::TRANSITIONS`, so a pair
 *   quietly dropped from the table narrows the code under test rather than the
 *   generator. Every enquiry starts at the pair's source status, so the first
 *   application is always a real change and the repeat is always a repeat.
 * - "A second time" is generated as one to four further applications. Requirement
 *   7.9 is about the second, but a no-op that only holds once would satisfy the
 *   letter of it while still drifting `status_changed_at` on the third request,
 *   so the property is asserted after every repeat and again over the whole run.
 * - The clock is frozen and moved forward by a strictly positive gap before each
 *   repeat, so every repeat happens at a demonstrably later instant than the
 *   first application. Without that, a repeat that *did* rewrite
 *   `status_changed_at` would write the same value it already held and the
 *   property would pass while the settlement clock was being reset — which is
 *   the bug that matters here, because the auto-closure job measures the closure
 *   interval from that column.
 * - The actor changes between applications. A repeat attributed to a different
 *   user must still append nothing: attribution is not a reason to record a
 *   second entry.
 * - The pairs whose target is `closed` carry the clause the ordering of
 *   `transition()` turns on. `closed` → `closed` is absent from the transition
 *   table, so a repeat is only a no-op if idempotence is checked before the
 *   table lookup; were the order reversed, those repeats would come back as
 *   409 refusals rather than as successes that wrote nothing.
 *
 * The assertions are stronger than the property's letter in one respect: the
 * whole hydrated enquiry, and the whole history list, must be identical before
 * and after a repeat. A repeat writes through no code path at all, so nothing
 * else may move either, and a stray touch of `updated_at` is as much a failure
 * as a moved `status_changed_at`. The hook count is asserted for the same
 * reason: a listener that emails the enquirer on a status change must not fire
 * again because someone pressed the button twice.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix, because `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see the test case's temporary tables.
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
 * Class LifecycleRepeatedTransitionPropertyTest
 */
class LifecycleRepeatedTransitionPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehidem_';

	/**
	 * The instant the seeded enquiry is created at.
	 */
	const SEEDED_AT = '2025-06-01 10:00:00';

	/**
	 * The instant the first application of the transition happens at.
	 */
	const FIRST_AT = '2025-07-04 14:30:00';

	/**
	 * Every permitted transition, as `from` => `to` pairs.
	 *
	 * Written out longhand rather than derived from `Lifecycle::TRANSITIONS`, so
	 * the generator's idea of a permitted pair is independent of the code under
	 * test.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	const PERMITTED = array(
		array( 'new', 'contacted' ),
		array( 'new', 'quoted' ),
		array( 'new', 'converted' ),
		array( 'new', 'lost' ),
		array( 'contacted', 'quoted' ),
		array( 'contacted', 'converted' ),
		array( 'contacted', 'lost' ),
		array( 'quoted', 'converted' ),
		array( 'quoted', 'lost' ),
		array( 'converted', 'closed' ),
		array( 'lost', 'closed' ),
	);

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Hook payloads observed during one iteration.
	 *
	 * @var array
	 */
	private $fired = array();

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
		require_once MEH_INCLUDES_DIR . 'class-lifecycle.php';
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

		$this->fired = array();

		add_action( Lifecycle::CHANGED_HOOK, array( $this, 'observe' ), 10, 3 );
	}

	public function tear_down() {
		global $wpdb;

		remove_action( Lifecycle::CHANGED_HOOK, array( $this, 'observe' ), 10 );

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

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
	 * Feature: enquiry-data-layer, Property 21: Repeating a transition is a
	 * no-op.
	 *
	 * **Validates: Requirements 7.9**
	 *
	 * @eris-shrink 10
	 */
	public function test_repeating_a_transition_is_a_no_op() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::repeated_transition() )
			->then(
				function ( array $scenario ) {
					list( $from, $to ) = $scenario['pair'];

					$this->clear();

					$this->assertTrue(
						Clock::freeze( self::SEEDED_AT ),
						'The clock should freeze under the test harness.'
					);

					$enquiry_id = $this->seed_enquiry( $from );

					// The first application: a real change, against which every
					// repeat is measured.
					$this->assertTrue( Clock::freeze( self::FIRST_AT ) );

					$first = Lifecycle::transition( $enquiry_id, $to, $scenario['actor'] );

					$this->assertIsArray( $first, sprintf( '%s to %s is a permitted transition.', $from, $to ) );
					$this->assertTrue( $first['changed'], 'The first application should change the status.' );
					$this->assertSame( self::FIRST_AT, $first['at'] );

					$settled  = EnquiryStore::find( $enquiry_id );
					$recorded = HistoryRecorder::for_enquiry( $enquiry_id );

					$this->assertSame( $to, $settled['status'], 'The first application should store the new status.' );
					$this->assertSame(
						self::FIRST_AT,
						$settled['status_changed_at'],
						'The first application should stamp the transition time.'
					);
					$this->assertCount( 1, $recorded, 'The first application should append one entry.' );
					$this->assertCount( 1, $this->fired, 'The first application should fire once.' );

					$at = Clock::now();

					foreach ( $scenario['repeats'] as $index => $repeat ) {
						// Strictly later than the first application, so a repeat
						// that rewrote the column would write a different value.
						$at = Clock::offset( $repeat['gap'], $at );
						$this->assertTrue( Clock::freeze( $at ) );
						$this->assertNotSame(
							self::FIRST_AT,
							Clock::mysql(),
							'Each repeat should happen at a demonstrably later instant.'
						);

						$result = Lifecycle::transition( $enquiry_id, $to, $repeat['actor'] );

						$this->assert_repeat_is_a_no_op(
							$result,
							$enquiry_id,
							$to,
							$settled,
							$recorded,
							(int) $index + 2
						);
					}
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Everything one repeat has to leave exactly as it found it
	 * (Requirement 7.9).
	 *
	 * @param array|\WP_Error $result     What the repeat returned.
	 * @param int             $enquiry_id Enquiry the repeat was applied to.
	 * @param string          $status     Status the enquiry already held.
	 * @param array           $settled    Hydrated enquiry after the first application.
	 * @param array           $recorded   History after the first application.
	 * @param int             $attempt    Which application this was, counting from 1.
	 * @return void
	 */
	private function assert_repeat_is_a_no_op( $result, $enquiry_id, $status, array $settled, array $recorded, $attempt ) {
		$context = sprintf( 'application %d of "%s"', $attempt, $status );

		// A repeat is a success reporting that it wrote nothing, not a refusal:
		// the caller asked for a state the enquiry is already in.
		$this->assertIsArray( $result, 'A repeat should be a success, not a failure: ' . $context );
		$this->assertFalse( $result['changed'], 'A repeat should report that it wrote nothing: ' . $context );
		$this->assertSame( (int) $enquiry_id, $result['id'] );
		$this->assertSame( $status, $result['from'], 'A repeat reports the status the enquiry already holds.' );
		$this->assertSame( $status, $result['to'] );
		$this->assertSame(
			$settled['status_changed_at'],
			$result['at'],
			'A repeat should report the time the first application stamped: ' . $context
		);

		$after = EnquiryStore::find( $enquiry_id );

		$this->assertSame( $status, $after['status'], 'A repeat should leave the status alone: ' . $context );
		$this->assertSame(
			$settled['status_changed_at'],
			$after['status_changed_at'],
			'A repeat should leave the settlement clock where the first application set it: ' . $context
		);

		// Nothing else moves either: a repeat writes through no code path, so a
		// touched `updated_at` is as much a failure as a moved status.
		$this->assertSame( $settled, $after, 'A repeat should leave the whole enquiry unchanged: ' . $context );

		// Requirement 7.9: no further history entry, and no second hook.
		$this->assertSame(
			$recorded,
			HistoryRecorder::for_enquiry( $enquiry_id ),
			'A repeat should append no history entry: ' . $context
		);
		$this->assertCount( 1, $this->fired, 'A repeat should fire no hook: ' . $context );
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One permitted transition, applied once and then repeated one to four times.
	 *
	 * @return \Eris\Generator
	 */
	protected static function repeated_transition() {
		return \Eris\Generators::associative(
			array(
				'pair'    => \Eris\Generators::elements( self::PERMITTED ),
				'actor'   => self::actor(),
				'repeats' => \Eris\Generators::bind(
					\Eris\Generators::choose( 1, 4 ),
					function ( $count ) {
						return \Eris\Generators::vector( (int) $count, self::repeat() );
					}
				),
			)
		);
	}

	/**
	 * One repeat: how long after the previous application it happens, and who
	 * makes it.
	 *
	 * The gap is strictly positive, and reaches from the next second to well
	 * beyond the auto-closure interval, so a repeat that reset the settlement
	 * clock would be observable as both a changed value and a changed verdict
	 * about whether the enquiry is overdue for closure.
	 *
	 * @return \Eris\Generator
	 */
	protected static function repeat() {
		return \Eris\Generators::associative(
			array(
				'gap'   => \Eris\Generators::oneOf(
					\Eris\Generators::constant( 1 ),
					\Eris\Generators::choose( 1, 60 ),
					\Eris\Generators::choose( 1, 30 * DAY_IN_SECONDS )
				),
				'actor' => self::actor(),
			)
		);
	}

	/**
	 * An acting user identifier, including the system attribution 0.
	 *
	 * Repeats draw their own actor, so a repeat made by a different user from the
	 * one who made the first application still has to append nothing.
	 *
	 * @return \Eris\Generator
	 */
	protected static function actor() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( 0 ),
			\Eris\Generators::choose( 1, 500 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write one enquiry holding a given status and return its identifier.
	 *
	 * `status_changed_at` is seeded well before the first application, so that
	 * application demonstrably moves it and the repeat has a value to preserve
	 * that is neither the seeded one nor the current instant.
	 *
	 * @param string $status Status the enquiry starts at.
	 * @return int
	 */
	private function seed_enquiry( $status ) {
		$id = EnquiryStore::create(
			array(
				'first_name'        => 'Ada',
				'last_name'         => 'Lovelace',
				'email'             => 'ada@example.com',
				'status'            => $status,
				'created_at'        => self::SEEDED_AT,
				'updated_at'        => self::SEEDED_AT,
				'status_changed_at' => self::SEEDED_AT,
				'source'            => 'webhook:fixture',
			),
			array( '2025-08-16' )
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

		$this->fired = array();

		foreach ( array( 'history', 'terms', 'dates', 'enquiries' ) as $key ) {
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
