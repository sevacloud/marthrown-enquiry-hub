<?php
/**
 * Property 34: Conversion guards reject without side effects.
 *
 * Feature: enquiry-data-layer, Property 34: For any conversion request that
 * names a calendar identifier matching no WP Booking System calendar, or a date
 * that is not one of the enquiry's candidate dates, or targets an enquiry that
 * already holds a booking identifier, or is made while WP Booking System is
 * inactive, the response carries the status defined for that condition (400,
 * 400, 409 and 503 respectively), no booking and no blocking event is created,
 * and the enquiry's status and `booking_id` are unchanged.
 *
 * **Validates: Requirements 14.8, 14.9, 14.10**
 *
 * How the property is instantiated:
 *
 * - The four conditions are drawn independently, so a request can carry any
 *   combination of them and at least one is always present. The expected status
 *   follows the guard order `BookingCreator` documents — unavailable, already
 *   linked, unknown calendar, date not a candidate — because a request carrying
 *   two conditions can only be answered with one status, and the earlier guard
 *   is the one that answers.
 * - `closed` is deliberately absent from the generated statuses. Refusing a
 *   closed enquiry is a fifth guard, not one of the four conditions this
 *   property names, and it answers 409 ahead of the unknown-calendar and
 *   date-not-a-candidate conditions; quantifying over it would make a correct
 *   implementation disagree with the property. The status set is otherwise
 *   derived from `Lifecycle::STATUSES`, so a status added later widens this
 *   generator with it, and the closure guard keeps its worked example in
 *   `BookingCreatorTest`.
 * - Absence of a booking is asserted two ways: the fake records no
 *   `wpbs_insert_booking` and no `wpbs_insert_event` call at all, and it holds
 *   no booking and no event afterwards. The call-count half is the stronger
 *   claim, and it is the one that says every guard returned *before* the insert
 *   rather than merely that the insert was undone.
 * - The refused enquiry is asserted to hold no history entry whatsoever, not
 *   merely no `booking_linked` entry, because the fixture writes none. A guard
 *   that recorded anything on its way out fails here.
 * - An unavailable WP Booking System is presented through the
 *   `meh_wpbs_available` filter rather than by unloading functions: the suite
 *   declares `wpbs_*` shims, so `function_exists()` is always true and the
 *   filter is the only seam that can present WPBS as inactive.
 *
 * The scenario is drawn unshrunk, for the reason given on `self::unshrunk()`.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix, because `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\BookingCreator;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Lifecycle;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeWpbs;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class ConversionGuardPropertyTest
 */
class ConversionGuardPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table, or with another test's fixture, in the same database.
	 */
	const PREFIX_SEGMENT = 'mehcg_';

	/**
	 * The filter presenting WP Booking System as inactive (Requirement 14.9).
	 *
	 * Named literally rather than read from the class under test, so the seam the
	 * implementation documents is the one exercised.
	 */
	const AVAILABILITY_FILTER = 'meh_wpbs_available';

	/**
	 * The calendar identifiers `FakeWpbs` seeds.
	 *
	 * Asserted against the fake in `set_up()`, so a change to its fixtures
	 * surfaces here rather than turning a known calendar into an unknown one
	 * halfway through a run.
	 *
	 * @var int[]
	 */
	const KNOWN_CALENDARS = array( 1, 2 );

	/**
	 * The status a request carrying no other condition than an inactive WP
	 * Booking System is answered with (Requirement 14.9).
	 */
	const STATUS_UNAVAILABLE = 503;

	/**
	 * The status an already-linked enquiry is answered with (Requirement 14.10).
	 */
	const STATUS_ALREADY_LINKED = 409;

	/**
	 * The status an unknown calendar, or a date the enquirer never offered, is
	 * answered with (Requirement 14.8).
	 */
	const STATUS_BAD_REQUEST = 400;

	/**
	 * The instant the clock is frozen at for every iteration.
	 */
	const FROZEN_NOW = '2025-07-04 14:30:00';

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The installed WPBS fake.
	 *
	 * @var FakeWpbs
	 */
	private $wpbs;

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
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
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

		$this->wpbs = FakeWpbs::install();

		$this->assertSame(
			self::KNOWN_CALENDARS,
			array_keys( $this->wpbs->calendars() ),
			'The generators assume the calendar identifiers the fake seeds.'
		);

		$this->assertTrue( Clock::freeze( self::FROZEN_NOW ), 'The clock should freeze under the test harness.' );
	}

	public function tear_down() {
		global $wpdb;

		remove_filter( self::AVAILABILITY_FILTER, '__return_false' );

		FakeWpbs::uninstall();
		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 34: Conversion guards reject without
	 * side effects.
	 *
	 * **Validates: Requirements 14.8, 14.9, 14.10**
	 *
	 * @eris-shrink 10
	 */
	public function test_conversion_guards_reject_without_side_effects() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $scenario ) {
					$this->clear();
					$this->wpbs->reset();

					$request = $this->seed_request( $scenario );
					$context = sprintf(
						'enquiry %d seeded %s holding booking %s, calendar %d, date %s',
						$request['id'],
						$request['status'],
						var_export( $request['booking_id'], true ),
						$request['calendar_id'],
						var_export( $request['date'], true )
					);

					$result = $this->convert( $request );

					// Requirements 14.8, 14.9, 14.10: refused, with the status
					// the earliest satisfied condition defines.
					$this->assertWPError( $result, 'A guarded request should be refused: ' . $context );

					$data = (array) $result->get_error_data();

					$this->assertSame(
						$request['expected_status'],
						isset( $data['status'] ) ? (int) $data['status'] : 0,
						sprintf(
							'The refusal should carry status %d (%s): %s',
							$request['expected_status'],
							$request['condition'],
							$context
						)
					);

					// Every guard returns before the insert, so WPBS is never
					// asked to create anything at all.
					$this->assertSame(
						0,
						$this->wpbs->call_count( 'wpbs_insert_booking' ),
						'A guarded request should not reach the booking insert: ' . $context
					);
					$this->assertSame(
						0,
						$this->wpbs->call_count( 'wpbs_insert_event' ),
						'A guarded request should not reach the date blocking: ' . $context
					);
					$this->assertSame( array(), $this->wpbs->bookings(), 'No booking should exist: ' . $context );
					$this->assertSame( array(), $this->wpbs->events(), 'No date should be blocked: ' . $context );

					$after = EnquiryStore::find( $request['id'] );

					$this->assertIsArray( $after, 'The enquiry should still be readable: ' . $context );
					$this->assertSame(
						$request['status'],
						$after['status'],
						'A refused conversion should leave the status alone: ' . $context
					);
					$this->assertSame(
						$request['booking_id'],
						$after['booking_id'],
						'A refused conversion should leave booking_id alone: ' . $context
					);
					$this->assertSame(
						$request['status_changed_at'],
						$after['status_changed_at'],
						'A refused conversion should leave the settlement clock alone: ' . $context
					);

					// The fixture writes no history, so any entry at all means a
					// guard wrote something on its way out.
					$this->assertSame(
						array(),
						HistoryRecorder::for_enquiry( $request['id'] ),
						'A refused conversion should record no history: ' . $context
					);
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Exercise
	 * ------------------------------------------------------------------ */

	/**
	 * Make the conversion attempt, presenting WPBS as inactive when the scenario
	 * calls for it (Requirement 14.9).
	 *
	 * @param array $request Seeded request, as returned by seed_request().
	 * @return array|\WP_Error
	 */
	private function convert( array $request ) {
		if ( $request['unavailable'] ) {
			add_filter( self::AVAILABILITY_FILTER, '__return_false' );
		}

		try {
			return BookingCreator::create_from_enquiry( $request['id'], $request['calendar_id'], $request['date'] );
		} finally {
			remove_filter( self::AVAILABILITY_FILTER, '__return_false' );
		}
	}

	/* ---------------------------------------------------------------------
	 * The reference expectation
	 * ------------------------------------------------------------------ */

	/**
	 * The status a correct implementation answers a request with, and the
	 * condition that earns it.
	 *
	 * The order is the guard order: a request carrying two conditions can only be
	 * answered once, and the earlier guard is the one that answers.
	 *
	 * @param array $flags Condition flags as generated.
	 * @return array{status:int,condition:string}
	 */
	private static function expected( array $flags ) {
		if ( $flags['unavailable'] ) {
			return array(
				'status'    => self::STATUS_UNAVAILABLE,
				'condition' => 'WP Booking System inactive',
			);
		}

		if ( $flags['linked'] ) {
			return array(
				'status'    => self::STATUS_ALREADY_LINKED,
				'condition' => 'enquiry already holds a booking identifier',
			);
		}

		if ( $flags['bad_calendar'] ) {
			return array(
				'status'    => self::STATUS_BAD_REQUEST,
				'condition' => 'calendar matches no WPBS calendar',
			);
		}

		return array(
			'status'    => self::STATUS_BAD_REQUEST,
			'condition' => 'date is not a candidate date',
		);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One whole scenario: the four condition flags, the enquiry to target, and
	 * the calendar and date the request names.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::map(
			function ( array $scenario ) {
				// A scenario carrying none of the four conditions is not a
				// guarded request at all, so the plainest one is supplied.
				if ( ! $scenario['unavailable'] && ! $scenario['linked']
					&& ! $scenario['bad_calendar'] && ! $scenario['bad_date'] ) {
					$scenario['bad_date'] = true;
				}

				return $scenario;
			},
			\Eris\Generators::associative(
				array(
					'unavailable'  => self::flag(),
					'linked'       => self::flag(),
					'bad_calendar' => self::flag(),
					'bad_date'     => self::flag(),
					'status'       => \Eris\Generators::elements( self::statuses() ),
					'ranges'       => Generators::candidate_ranges(),
					'date_index'   => \Eris\Generators::choose( 0, 60 ),
					'known'        => \Eris\Generators::elements( self::KNOWN_CALENDARS ),
					'stray_offset' => \Eris\Generators::choose( 1, 500 ),
					'date_shape'   => \Eris\Generators::elements( array( 'outside', 'unparseable' ) ),
					'stray_days'   => \Eris\Generators::choose( 0, 400 ),
					'unparseable'  => Generators::unparseable_date(),
					'booking'      => \Eris\Generators::choose( 1, 9999 ),
					'first_name'   => Generators::first_name(),
					'last_name'    => Generators::last_name(),
					'email'        => Generators::email(),
				)
			)
		);
	}

	/**
	 * A condition flag, true or false.
	 *
	 * @return \Eris\Generator
	 */
	protected static function flag() {
		return \Eris\Generators::elements( array( true, false ) );
	}

	/**
	 * The statuses a targeted enquiry may hold.
	 *
	 * Every recognised status but `closed`, derived from `Lifecycle::STATUSES` so
	 * a status added later is drawn too. `closed` is excluded because refusing a
	 * closed enquiry is a separate guard answering ahead of two of the four
	 * conditions this property quantifies over.
	 *
	 * @return string[]
	 */
	protected static function statuses() {
		return array_values( array_diff( Lifecycle::STATUSES, array( 'closed' ) ) );
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step is exponential in
	 * the number of generated values. A scenario holds sixteen of them, including
	 * a candidate date set of up to ten, which puts that product beyond what fits
	 * in memory: a failing iteration would report an out-of-memory fatal instead
	 * of the counterexample.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the scenario as generated, together with the assertion's
	 * own expected-versus-actual diff and the `ERIS_SEED` line that reproduces
	 * the run exactly.
	 *
	 * @param \Eris\Generator $generator Generator to draw from.
	 * @return \Eris\Generator
	 */
	protected static function unshrunk( \Eris\Generator $generator ) {
		return \Eris\Generators::bind(
			$generator,
			function ( $drawn ) {
				return \Eris\Generators::constant( $drawn );
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write the enquiry the scenario targets and resolve the calendar and date
	 * the request names.
	 *
	 * @param array $scenario Scenario as generated.
	 * @return array{id:int,status:string,status_changed_at:string,booking_id:int|null,calendar_id:int,date:mixed,unavailable:bool,expected_status:int,condition:string}
	 */
	private function seed_request( array $scenario ) {
		$ranges     = array_values( (array) $scenario['ranges'] );
		$booking_id = $scenario['linked'] ? (int) $scenario['booking'] : null;
		$settled    = '2025-06-01 10:00:00';

		$id = EnquiryStore::create(
			array(
				'first_name'        => (string) $scenario['first_name'],
				'last_name'         => (string) $scenario['last_name'],
				'email'             => (string) $scenario['email'],
				'status'            => (string) $scenario['status'],
				'booking_id'        => $booking_id,
				'created_at'        => $settled,
				'updated_at'        => $settled,
				'status_changed_at' => $settled,
				'source'            => 'webhook:fixture',
			),
			$ranges
		);

		$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

		$stored = EnquiryStore::find( (int) $id );

		$this->assertSame( $booking_id, $stored['booking_id'], 'The fixture should seed booking_id as generated.' );

		$expected = self::expected( $scenario );

		return array(
			'id'                => (int) $id,
			'status'            => (string) $scenario['status'],
			'status_changed_at' => $settled,
			'booking_id'        => $booking_id,
			'calendar_id'       => $this->calendar_for( $scenario ),
			'date'              => self::date_for( $scenario, Generators::days_in_ranges( $ranges ) ),
			'unavailable'       => (bool) $scenario['unavailable'],
			'expected_status'   => $expected['status'],
			'condition'         => $expected['condition'],
		);
	}

	/**
	 * The calendar identifier the request names: one the fake knows, or one it
	 * demonstrably does not (Requirement 14.8).
	 *
	 * @param array $scenario Scenario as generated.
	 * @return int
	 */
	private function calendar_for( array $scenario ) {
		if ( ! $scenario['bad_calendar'] ) {
			return (int) $scenario['known'];
		}

		$calendar_id = max( self::KNOWN_CALENDARS ) + (int) $scenario['stray_offset'];

		$this->assertArrayNotHasKey(
			$calendar_id,
			$this->wpbs->calendars(),
			'An unknown calendar identifier should match no fixture calendar.'
		);

		return $calendar_id;
	}

	/**
	 * The date the request names: one of the enquiry's candidate dates, or a
	 * value that is not one of them.
	 *
	 * The two ways a date can fail to be a candidate date are both drawn: a
	 * perfectly good date the enquirer never offered, and a value that names no
	 * calendar date at all.
	 *
	 * @param array $scenario Scenario as generated.
	 * @param array $dates    Every day the enquiry's candidate ranges cover.
	 * @return string
	 */
	private static function date_for( array $scenario, array $dates ) {
		if ( ! $scenario['bad_date'] ) {
			return (string) $dates[ (int) $scenario['date_index'] % count( $dates ) ];
		}

		if ( 'unparseable' === $scenario['date_shape'] ) {
			return (string) $scenario['unparseable'];
		}

		// Candidate ranges sit within 450 days of the generator's base date, so a
		// date beyond 1000 days cannot be one of them whatever was drawn.
		$outside = Generators::date_at( 1000 + (int) $scenario['stray_days'] );

		return in_array( $outside, $dates, true ) ? Generators::date_at( 5000 ) : $outside;
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
