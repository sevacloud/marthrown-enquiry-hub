<?php
/**
 * Property 33: Conversion creates the booking and records it.
 *
 * Feature: enquiry-data-layer, Property 33: For any enquiry that is not closed
 * and holds no booking identifier, any WP Booking System calendar known to the
 * plugin, and any candidate date belonging to that enquiry, conversion creates
 * one booking whose start and end date equal the chosen date and whose guest
 * name and email derive from the enquiry's `first_name`, `last_name` and
 * `email`, blocks that date on the target calendar using that calendar's booked
 * legend item, records the resulting booking identifier on the enquiry,
 * transitions the enquiry to `converted`, appends one history entry of type
 * `booking_linked` holding the booking identifier, and triggers no WP Booking
 * System email, payment, pricing or inventory operation.
 *
 * **Validates: Requirements 14.2, 14.3, 14.4, 14.5, 14.6, 14.7, 14.11**
 *
 * Four notes on how the property is instantiated:
 *
 * - It runs against real tables at its own prefix segment, because the claim is
 *   about what conversion *stored*: `booking_id` on the enquiry row, the status
 *   the transition left behind, and one `booking_linked` history entry. Reading
 *   the returned array alone would pass while nothing reached the database. The
 *   WordPress test case rewrites `CREATE TABLE` into its temporary form and
 *   `Schema::install()` verifies each table through `SHOW TABLES`, which cannot
 *   see a temporary table, so the tables are made real here and dropped again.
 * - WP Booking System is the recording fake. "Creates one booking" and "blocks
 *   that date" are asserted over its call log rather than over the return value,
 *   which is also how Requirement 14.11 is checked: the whole log is compared
 *   against the set of operations conversion is allowed to perform, so an email,
 *   payment, pricing or inventory call would show up as an unexpected method
 *   whatever it was named, and the created booking is asserted to carry
 *   `form_id` 0 — no WPBS form is submitted, so no form flow can run.
 * - The target calendar is quantified over, not fixed. Two of the three draws
 *   are the fake's seeded calendars and the third is a calendar seeded per
 *   iteration with its own `booked` legend identifier, sitting either first or
 *   last in the legend. "That calendar's booked legend item" is therefore a real
 *   claim: an implementation blocking with a fixed identifier, with the
 *   calendar's default item, or with whichever item happens to come first would
 *   fail here.
 * - The starting status is drawn from `new`, `contacted` and `quoted` — the
 *   statuses the Lifecycle transition table permits `converted` from
 *   (Requirement 7.4). `lost` is excluded on purpose: it is not closed, so the
 *   property's precondition admits it, but Requirement 7.11 makes the
 *   forward-only table the sole authority on transitions and it holds no
 *   `lost` → `converted` pair. Generating `lost` would ask conversion to
 *   contradict the lifecycle rather than test the conversion path, so the
 *   generator constrains itself to the convertible statuses.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\BookingCreator;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeWpbs;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class BookingConversionPropertyTest
 */
class BookingConversionPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table, with `BookingCreatorTest`'s fixtures, or with the
	 * conversion-guard property's.
	 */
	const PREFIX_SEGMENT = 'mehcv_';

	/** The instant the clock is frozen at for every iteration. */
	const NOW = '2025-07-14 09:00:00';

	/** The identifier of the calendar this test seeds itself, per iteration. */
	const GENERATED_CALENDAR = 3;

	/**
	 * The statuses `converted` is reachable from (Requirement 7.4).
	 *
	 * @var string[]
	 */
	const CONVERTIBLE_STATUSES = array( 'new', 'contacted', 'quoted' );

	/**
	 * The WP Booking System operations conversion is allowed to perform.
	 *
	 * The two reads answer the calendar and legend guards, the two writes are the
	 * booking and the day it blocks, and the meta call records which enquiry the
	 * booking came from. Any other WPBS call — a form submission, a notification,
	 * a payment request, a price calculation, an inventory adjustment — is
	 * outside this set, which is how Requirement 14.11 is checked without having
	 * to name each side effect.
	 *
	 * @var string[]
	 */
	const ALLOWED_WPBS_CALLS = array(
		'wpbs_get_calendars',
		'wpbs_get_legend_items',
		'wpbs_insert_booking',
		'wpbs_insert_event',
		'wpbs_add_booking_meta',
	);

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The installed WPBS fake.
	 *
	 * @var FakeWpbs|null
	 */
	private $wpbs = null;

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

		Clock::freeze( self::NOW );
	}

	public function tear_down() {
		global $wpdb;

		FakeWpbs::uninstall();
		$this->wpbs = null;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 33: Conversion creates the booking
	 * and records it.
	 *
	 * **Validates: Requirements 14.2, 14.3, 14.4, 14.5, 14.6, 14.7, 14.11**
	 *
	 * @eris-shrink 10
	 */
	public function test_conversion_creates_the_booking_and_records_it() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $scenario ) {
					$this->reset_state();

					$calendar_id = $this->seed_calendar( $scenario['calendar'] );
					$chosen      = self::chosen_date( $scenario );
					$enquiry_id  = $this->seed_enquiry( $scenario );

					$result = BookingCreator::create_from_enquiry( $enquiry_id, $calendar_id, $chosen );

					$this->assertIsArray(
						$result,
						'Conversion of an open, unbooked enquiry to a known calendar on one of its own candidate dates should succeed.'
					);
					$this->assertSame( array(), $result['warnings'], 'A successful conversion should report no warning.' );

					$this->assert_one_booking_was_created( $result, $scenario, $calendar_id, $chosen );
					$this->assert_the_date_was_blocked( $result, $scenario, $calendar_id, $chosen );
					$this->assert_the_enquiry_records_the_booking( $result, $enquiry_id, $chosen );
					$this->assert_no_wpbs_form_flow_ran();
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Exactly one booking, on the target calendar, for the chosen day, carrying
	 * the enquirer's name and email (Requirements 14.2, 14.3).
	 *
	 * @param array  $result      What conversion returned.
	 * @param array  $scenario    The generated scenario.
	 * @param int    $calendar_id Target calendar.
	 * @param string $chosen      Chosen candidate date, `Y-m-d`.
	 * @return void
	 */
	private function assert_one_booking_was_created( array $result, array $scenario, $calendar_id, $chosen ) {
		$this->assertCount( 1, $this->wpbs->bookings(), 'Conversion should create exactly one booking.' );
		$this->assertSame( 1, $this->wpbs->call_count( 'wpbs_insert_booking' ), 'Conversion should insert one booking, once.' );

		$booking = $this->wpbs->booking( $result['booking_id'] );

		$this->assertIsArray( $booking, 'The reported booking identifier should name the created booking.' );
		$this->assertSame( (int) $calendar_id, (int) $booking['calendar_id'], 'The booking should sit on the target calendar.' );

		// Requirement 14.2: a single day, start and end both the chosen date.
		$this->assertSame( $chosen, (string) $booking['start_date'], 'The booking should start on the chosen candidate date.' );
		$this->assertSame( $chosen, (string) $booking['end_date'], 'The booking should end on the chosen candidate date.' );

		// Requirement 14.3.
		$this->assertSame(
			self::expected_guest_name( $scenario ),
			(string) $booking['fields'][0]['user_value'],
			'The booking guest name should come from the enquiry given and family names.'
		);
		$this->assertSame(
			(string) $scenario['email'],
			(string) $booking['fields'][1]['user_value'],
			'The booking guest email should come from the enquiry email address.'
		);
	}

	/**
	 * One blocked day, on the target calendar, using that calendar's own `booked`
	 * legend item (Requirement 14.4).
	 *
	 * @param array  $result      What conversion returned.
	 * @param array  $scenario    The generated scenario.
	 * @param int    $calendar_id Target calendar.
	 * @param string $chosen      Chosen candidate date, `Y-m-d`.
	 * @return void
	 */
	private function assert_the_date_was_blocked( array $result, array $scenario, $calendar_id, $chosen ) {
		$this->assertSame( 1, (int) $result['blocked'], 'Conversion should block exactly the one chosen day.' );

		$events = $this->wpbs->events_for( $result['booking_id'] );

		$this->assertCount( 1, $events, 'A single-day booking should block a single day.' );
		$this->assertSame( $events, $this->wpbs->events(), 'No day should be blocked for any other booking.' );

		$this->assertSame( (int) $calendar_id, (int) $events[0]['calendar_id'], 'The day should be blocked on the target calendar.' );
		$this->assertSame(
			self::expected_legend_id( $scenario ),
			(int) $events[0]['legend_item_id'],
			'The day should be blocked with the target calendar own booked legend item.'
		);
		$this->assertSame(
			self::date_parts( $chosen ),
			array(
				(int) $events[0]['date_year'],
				(int) $events[0]['date_month'],
				(int) $events[0]['date_day'],
			),
			'The blocked day should be the chosen candidate date.'
		);
	}

	/**
	 * The enquiry records the booking, moves to `converted`, and carries one
	 * `booking_linked` entry naming that booking (Requirements 14.5, 14.6, 14.7).
	 *
	 * @param array  $result     What conversion returned.
	 * @param int    $enquiry_id Converted enquiry.
	 * @param string $chosen     Chosen candidate date, `Y-m-d`.
	 * @return void
	 */
	private function assert_the_enquiry_records_the_booking( array $result, $enquiry_id, $chosen ) {
		$enquiry = EnquiryStore::find( $enquiry_id );

		$this->assertIsArray( $enquiry, 'The converted enquiry should read back.' );

		// Requirement 14.5.
		$this->assertSame(
			(int) $result['booking_id'],
			(int) $enquiry['booking_id'],
			'The enquiry should record the created booking identifier.'
		);

		// Requirement 14.6.
		$this->assertSame( 'converted', $enquiry['status'], 'The converted enquiry should hold status converted.' );
		$this->assertSame( self::NOW, $enquiry['status_changed_at'], 'The status change should be timed by the clock.' );

		// Requirement 14.7.
		$linked = array_values(
			array_filter(
				HistoryRecorder::for_enquiry( $enquiry_id ),
				static function ( array $entry ) {
					return 'booking_linked' === $entry['entry_type'];
				}
			)
		);

		$this->assertCount( 1, $linked, 'Conversion should append exactly one booking_linked history entry.' );
		$this->assertSame(
			(int) $result['booking_id'],
			(int) $linked[0]['context']['booking_id'],
			'The booking_linked entry should hold the booking identifier.'
		);
		$this->assertSame( $chosen, (string) $linked[0]['context']['date'], 'The booking_linked entry should hold the booked date.' );
	}

	/**
	 * No WP Booking System email, payment, pricing or inventory operation ran
	 * (Requirement 14.11).
	 *
	 * Two independent checks. The created booking carries `form_id` 0, so no WPBS
	 * form was submitted and none of the flows a form drives can have run. And
	 * every recorded WPBS call is one of the operations conversion is allowed to
	 * perform, so a side effect reaching WPBS by any other function would surface
	 * here as an unexpected method name.
	 *
	 * @return void
	 */
	private function assert_no_wpbs_form_flow_ran() {
		$booking = array_values( $this->wpbs->bookings() )[0];

		$this->assertSame( 0, (int) $booking['form_id'], 'A converted booking should belong to no WPBS form.' );

		$unexpected = array();

		foreach ( $this->wpbs->calls() as $call ) {
			if ( ! in_array( $call['method'], self::ALLOWED_WPBS_CALLS, true ) ) {
				$unexpected[] = $call['method'];
			}
		}

		$this->assertSame(
			array(),
			array_values( array_unique( $unexpected ) ),
			'Conversion should perform no WP Booking System operation beyond reading calendars and legends, inserting the booking, blocking the day and recording the enquiry identifier.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One conversion: the enquiry to convert, the calendar to convert onto, and
	 * which of the enquiry's candidate dates was agreed.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'first_name'   => Generators::first_name(),
				'last_name'    => Generators::last_name(),
				'email'        => Generators::email(),
				'dates'        => Generators::candidate_dates(),
				// Reduced against the drawn date count, so every candidate date
				// is reachable whatever the size of the set.
				'chosen_index' => \Eris\Generators::choose( 0, Generators::DATES_MAX - 1 ),
				'status'       => \Eris\Generators::elements( self::CONVERTIBLE_STATUSES ),
				'calendar'     => self::calendar(),
			)
		);
	}

	/**
	 * The target calendar: one of the fake's two seeded calendars, or a third one
	 * this test seeds itself with its own `booked` legend identifier.
	 *
	 * The generated calendar is what makes "that calendar's booked legend item" a
	 * claim rather than a restatement of a fixture: its identifier is drawn, and
	 * the `booked` item sits either first or last in the legend, so neither a
	 * hard-coded identifier nor "whichever item comes first" passes.
	 *
	 * @return \Eris\Generator
	 */
	protected static function calendar() {
		return \Eris\Generators::associative(
			array(
				'id'        => \Eris\Generators::elements( array( 1, 2, self::GENERATED_CALENDAR ) ),
				'booked_id' => \Eris\Generators::choose( 101, 199 ),
				'order'     => \Eris\Generators::elements( array( 'first', 'last' ) ),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step is exponential in
	 * the number of generated values. A scenario holds six drawn values, one of
	 * them a nested date set of up to ten elements and another a nested calendar
	 * spec, which puts that product beyond what fits in memory: a failing
	 * iteration would report an out-of-memory fatal instead of the counterexample.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the scenario as generated, together with the assertion's
	 * own diff and the `ERIS_SEED` line that reproduces the run exactly.
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
	 * Write the enquiry to convert: open, holding no booking, carrying every
	 * generated candidate date.
	 *
	 * @param array $scenario The generated scenario.
	 * @return int
	 */
	private function seed_enquiry( array $scenario ) {
		$id = EnquiryStore::create(
			array(
				'first_name'        => $scenario['first_name'],
				'last_name'         => $scenario['last_name'],
				'email'             => $scenario['email'],
				'status'            => $scenario['status'],
				'created_at'        => '2025-06-01 10:00:00',
				'updated_at'        => '2025-06-01 10:00:00',
				'status_changed_at' => '2025-06-01 10:00:00',
				'source'            => 'webhook:property-33',
			),
			$scenario['dates']
		);

		$this->assertIsInt( $id, 'Seeding the enquiry to convert should succeed.' );

		$stored = EnquiryStore::find( (int) $id );

		$this->assertNull( $stored['booking_id'], 'The seeded enquiry should hold no booking identifier.' );
		$this->assertNotSame( 'closed', $stored['status'], 'The seeded enquiry should not be closed.' );

		return (int) $id;
	}

	/**
	 * Make the target calendar exist, seeding the generated one when it is drawn.
	 *
	 * @param array $calendar The generated calendar spec.
	 * @return int The target calendar identifier.
	 */
	private function seed_calendar( array $calendar ) {
		$calendar_id = (int) $calendar['id'];

		if ( self::GENERATED_CALENDAR !== $calendar_id ) {
			return $calendar_id;
		}

		$booked_id = (int) $calendar['booked_id'];

		$this->wpbs->add_calendar( $calendar_id, 'Generated Calendar' );

		if ( 'first' === $calendar['order'] ) {
			$this->wpbs->add_legend_item( $calendar_id, $booked_id, 'Booked', 'booked' );
		}

		// Two decoys: the calendar's default item, and a pending item.
		$this->wpbs->add_legend_item( $calendar_id, $booked_id + 200, 'Available', '', true );
		$this->wpbs->add_legend_item( $calendar_id, $booked_id + 300, 'Pending', 'pending' );

		if ( 'last' === $calendar['order'] ) {
			$this->wpbs->add_legend_item( $calendar_id, $booked_id, 'Booked', 'booked' );
		}

		return $calendar_id;
	}

	/**
	 * The candidate date the team agreed, drawn from the enquiry's own set.
	 *
	 * @param array $scenario The generated scenario.
	 * @return string `Y-m-d`.
	 */
	private static function chosen_date( array $scenario ) {
		$dates = array_values( $scenario['dates'] );

		return (string) $dates[ (int) $scenario['chosen_index'] % count( $dates ) ];
	}

	/**
	 * The guest name the booking should carry (Requirement 14.3).
	 *
	 * @param array $scenario The generated scenario.
	 * @return string
	 */
	private static function expected_guest_name( array $scenario ) {
		return trim( trim( (string) $scenario['first_name'] ) . ' ' . trim( (string) $scenario['last_name'] ) );
	}

	/**
	 * The `booked` legend item the target calendar holds (Requirement 14.4).
	 *
	 * The fake seeds calendar N's items at N*10+1..N*10+3, the third being the
	 * `booked` one; the generated calendar carries the drawn identifier.
	 *
	 * @param array $scenario The generated scenario.
	 * @return int
	 */
	private static function expected_legend_id( array $scenario ) {
		$calendar_id = (int) $scenario['calendar']['id'];

		return self::GENERATED_CALENDAR === $calendar_id
			? (int) $scenario['calendar']['booked_id']
			: ( $calendar_id * 10 ) + 3;
	}

	/**
	 * A date as its year, month and day.
	 *
	 * @param string $date `Y-m-d`.
	 * @return int[]
	 */
	private static function date_parts( $date ) {
		$parts = explode( '-', (string) $date );

		return array( (int) $parts[0], (int) $parts[1], (int) $parts[2] );
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Return the store and the WPBS fake to their starting state, between
	 * iterations.
	 *
	 * @return void
	 */
	private function reset_state() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		$this->wpbs->reset()->seed_defaults();
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
