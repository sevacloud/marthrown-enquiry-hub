<?php
/**
 * In-memory WP Booking System fake.
 *
 * Records every wpbs_insert_booking() and wpbs_insert_event() call and exposes
 * calendar and legend fixtures, so the booking-creation properties run with WPBS
 * absent. The shapes match what SourceWpbs and BookingCreator read: calendar and
 * legend objects answering get( $key ), a legend item whose auto_pending is
 * 'booked', and one availability event row per blocked day.
 *
 * Usage:
 *
 *     $wpbs = FakeWpbs::install();            // seeds two calendars with legends
 *     $result = BookingCreator::create_from_enquiry( $id, 1, '2025-08-16' );
 *     $this->assertCount( 1, $wpbs->bookings() );
 *     $this->assertCount( 1, $wpbs->events_for( $result['booking_id'] ) );
 *     FakeWpbs::uninstall();                  // in tearDown
 *
 * Failure modes:
 *
 *     $wpbs->will_fail_booking_insert();      // wpbs_insert_booking() returns false
 *     $wpbs->will_fail_event_insert();        // date blocking fails, booking stands
 *     $wpbs->will_be_unavailable();           // every WPBS function answers as absent
 *
 * Loading: the file is picked up by the Composer classmap over tests/, so the
 * wpbs_* shims at the bottom are declared the moment FakeWpbs is first
 * referenced. Install the fake in setUp(), before exercising any code that gates
 * on SourceWpbs::available().
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub\Tests\Fakes {

	/**
	 * Class FakeWpbs
	 */
	class FakeWpbs {

		/**
		 * The installed instance, or null when the fake is not installed.
		 *
		 * @var FakeWpbs|null
		 */
		protected static $current = null;

		/**
		 * Ordered log of every call, oldest first.
		 *
		 * Each entry: array{ method: string, args: array }.
		 *
		 * @var array
		 */
		protected $calls = array();

		/**
		 * Calendar fixtures, id => name.
		 *
		 * @var array
		 */
		protected $calendars = array();

		/**
		 * Legend fixtures, calendar id => ( legend id => item array ).
		 *
		 * @var array
		 */
		protected $legend = array();

		/**
		 * Inserted bookings, id => args.
		 *
		 * @var array
		 */
		protected $bookings = array();

		/**
		 * Inserted availability events, oldest first.
		 *
		 * @var array
		 */
		protected $events = array();

		/**
		 * Booking meta, booking id => ( key => value ).
		 *
		 * @var array
		 */
		protected $meta = array();

		/**
		 * Identifier handed to the next inserted booking.
		 *
		 * @var int
		 */
		protected $next_booking_id = 5001;

		/**
		 * Whether the fake presents WPBS as active.
		 *
		 * @var bool
		 */
		protected $available = true;

		/**
		 * Whether a booking insert fails.
		 *
		 * @var bool
		 */
		protected $fail_booking_insert = false;

		/**
		 * Whether an availability event insert fails.
		 *
		 * @var bool
		 */
		protected $fail_event_insert = false;

		/**
		 * Install a fresh fake, seeded with default fixtures, and make it current.
		 *
		 * @return FakeWpbs
		 */
		public static function install() {
			self::$current = new self();
			self::$current->seed_defaults();
			return self::$current;
		}

		/**
		 * Remove the current fake. Call from tearDown().
		 */
		public static function uninstall() {
			self::$current = null;
		}

		/**
		 * The current fake, or null when none is installed.
		 *
		 * @return FakeWpbs|null
		 */
		public static function current() {
			return self::$current;
		}

		/**
		 * Forget every recorded call, booking, event and meta value.
		 *
		 * Fixtures and configuration are kept.
		 *
		 * @return FakeWpbs
		 */
		public function reset() {
			$this->calls    = array();
			$this->bookings = array();
			$this->events   = array();
			$this->meta     = array();
			return $this;
		}

		/**
		 * Seed two calendars, each with a booked, a pending and a default legend item.
		 *
		 * @return FakeWpbs
		 */
		public function seed_defaults() {
			$this->calendars = array();
			$this->legend    = array();

			$this->add_calendar( 1, 'Main House' );
			$this->add_calendar( 2, 'The Barn' );

			foreach ( array_keys( $this->calendars ) as $calendar_id ) {
				$base = $calendar_id * 10;
				$this->add_legend_item( $calendar_id, $base + 1, 'Available', '', true, '#7ad03a' );
				$this->add_legend_item( $calendar_id, $base + 2, 'Pending', 'pending', false, '#ffba00' );
				$this->add_legend_item( $calendar_id, $base + 3, 'Booked', 'booked', false, '#dd3d36' );
			}

			return $this;
		}

		/* ---------------------------------------------------------------------
		 * Fixtures
		 * ------------------------------------------------------------------ */

		/**
		 * Add a calendar fixture.
		 *
		 * @param int    $id   Calendar id.
		 * @param string $name Calendar name.
		 * @return FakeWpbs
		 */
		public function add_calendar( $id, $name ) {
			$this->calendars[ (int) $id ] = (string) $name;
			if ( ! isset( $this->legend[ (int) $id ] ) ) {
				$this->legend[ (int) $id ] = array();
			}
			return $this;
		}

		/**
		 * Add a legend item fixture.
		 *
		 * @param int    $calendar_id  Calendar id.
		 * @param int    $id           Legend item id.
		 * @param string $title        Item title.
		 * @param string $auto_pending WPBS auto_pending value: '', 'pending' or 'booked'.
		 * @param bool   $is_default   Whether this is the calendar's default item.
		 * @param string $color        Hex colour.
		 * @return FakeWpbs
		 */
		public function add_legend_item( $calendar_id, $id, $title, $auto_pending = '', $is_default = false, $color = '#cccccc' ) {
			$calendar_id = (int) $calendar_id;
			if ( ! isset( $this->legend[ $calendar_id ] ) ) {
				$this->legend[ $calendar_id ] = array();
			}
			$this->legend[ $calendar_id ][ (int) $id ] = array(
				'id'           => (int) $id,
				'calendar_id'  => $calendar_id,
				// `name`, not `title`: a real WPBS_Legend_Item has no `title`, and
				// asking it for one is what filled the live debug log with
				// "Undefined property" warnings.
				'name'         => (string) $title,
				// WPBS returns colour as an array; SourceWpbs reads element 0.
				'color'        => array( (string) $color ),
				'is_default'   => $is_default ? 1 : 0,
				'auto_pending' => (string) $auto_pending,
			);
			return $this;
		}

		/**
		 * Calendar fixtures as objects, as wpbs_get_calendars() returns.
		 *
		 * @return array
		 */
		public function calendar_objects() {
			$this->record( 'wpbs_get_calendars' );
			if ( ! $this->available ) {
				return array();
			}
			$out = array();
			foreach ( $this->calendars as $id => $name ) {
				$out[] = new FakeWpbsRecord(
					array(
						'id'   => $id,
						'name' => $name,
					)
				);
			}
			return $out;
		}

		/**
		 * Calendar fixtures as an id => name map.
		 *
		 * @return array
		 */
		public function calendars() {
			return $this->calendars;
		}

		/**
		 * Legend items for a calendar as objects, as wpbs_get_legend_items() returns.
		 *
		 * @param array $args Query args; only calendar_id is honoured.
		 * @return array
		 */
		public function legend_objects( array $args = array() ) {
			$this->record( 'wpbs_get_legend_items', $args );
			if ( ! $this->available ) {
				return array();
			}
			$calendar_id = isset( $args['calendar_id'] ) ? (int) $args['calendar_id'] : 0;
			$items       = isset( $this->legend[ $calendar_id ] ) ? $this->legend[ $calendar_id ] : array();

			$out = array();
			foreach ( $items as $item ) {
				$out[] = new FakeWpbsRecord( $item );
			}
			return $out;
		}

		/**
		 * The 'booked' legend item id for a calendar, 0 when none.
		 *
		 * @param int $calendar_id Calendar id.
		 * @return int
		 */
		public function booked_legend_item_id( $calendar_id ) {
			foreach ( isset( $this->legend[ (int) $calendar_id ] ) ? $this->legend[ (int) $calendar_id ] : array() as $item ) {
				if ( 'booked' === $item['auto_pending'] ) {
					return (int) $item['id'];
				}
			}
			return 0;
		}

		/* ---------------------------------------------------------------------
		 * Configuration
		 * ------------------------------------------------------------------ */

		/**
		 * Present WPBS as inactive: every function answers as absent.
		 *
		 * @return FakeWpbs
		 */
		public function will_be_unavailable() {
			$this->available = false;
			return $this;
		}

		/**
		 * Present WPBS as active.
		 *
		 * @return FakeWpbs
		 */
		public function will_be_available() {
			$this->available = true;
			return $this;
		}

		/**
		 * Whether the fake presents WPBS as active.
		 *
		 * @return bool
		 */
		public function is_available() {
			return $this->available;
		}

		/**
		 * Make wpbs_insert_booking() answer false.
		 *
		 * @param bool $fail Whether to fail.
		 * @return FakeWpbs
		 */
		public function will_fail_booking_insert( $fail = true ) {
			$this->fail_booking_insert = (bool) $fail;
			return $this;
		}

		/**
		 * Make wpbs_insert_event() answer false, so date blocking fails while the
		 * booking stands.
		 *
		 * @param bool $fail Whether to fail.
		 * @return FakeWpbs
		 */
		public function will_fail_event_insert( $fail = true ) {
			$this->fail_event_insert = (bool) $fail;
			return $this;
		}

		/**
		 * Identifier handed to the next inserted booking.
		 *
		 * @param int $id Booking id.
		 * @return FakeWpbs
		 */
		public function next_booking_id( $id ) {
			$this->next_booking_id = (int) $id;
			return $this;
		}

		/* ---------------------------------------------------------------------
		 * Recorded operations
		 * ------------------------------------------------------------------ */

		/**
		 * Insert a booking.
		 *
		 * @param array $args Booking args as handed to wpbs_insert_booking().
		 * @return int|false New booking id, or false on a configured failure.
		 */
		public function insert_booking( array $args ) {
			$this->record( 'wpbs_insert_booking', $args );

			if ( ! $this->available || $this->fail_booking_insert ) {
				return false;
			}

			$id = $this->next_booking_id;
			++$this->next_booking_id;

			$this->bookings[ $id ] = array_merge( array( 'id' => $id ), $args );

			return $id;
		}

		/**
		 * Insert an availability event (one blocked day).
		 *
		 * @param array $args Event args as handed to wpbs_insert_event().
		 * @return int|false New event id, or false on a configured failure.
		 */
		public function insert_event( array $args ) {
			$this->record( 'wpbs_insert_event', $args );

			if ( ! $this->available || $this->fail_event_insert ) {
				return false;
			}

			$id             = count( $this->events ) + 1;
			$this->events[] = array_merge( array( 'id' => $id ), $args );

			return $id;
		}

		/**
		 * Record booking meta.
		 *
		 * @param int    $booking_id Booking id.
		 * @param string $key        Meta key.
		 * @param mixed  $value      Meta value.
		 * @return bool
		 */
		public function add_booking_meta( $booking_id, $key, $value ) {
			$this->record(
				'wpbs_add_booking_meta',
				array(
					'booking_id' => (int) $booking_id,
					'key'        => (string) $key,
					'value'      => $value,
				)
			);
			if ( ! $this->available ) {
				return false;
			}
			$this->meta[ (int) $booking_id ][ (string) $key ] = $value;
			return true;
		}

		/**
		 * Record a call.
		 *
		 * @param string $method Function name.
		 * @param array  $args   Arguments.
		 */
		public function record( $method, array $args = array() ) {
			$this->calls[] = array(
				'method' => (string) $method,
				'args'   => $args,
			);
		}

		/* ---------------------------------------------------------------------
		 * Assertion helpers
		 * ------------------------------------------------------------------ */

		/**
		 * Recorded calls, optionally filtered by function name, oldest first.
		 *
		 * @param string|null $method Function name, or null for every call.
		 * @return array
		 */
		public function calls( $method = null ) {
			if ( null === $method ) {
				return $this->calls;
			}
			$out = array();
			foreach ( $this->calls as $call ) {
				if ( $call['method'] === $method ) {
					$out[] = $call;
				}
			}
			return $out;
		}

		/**
		 * Number of recorded calls, optionally for one function.
		 *
		 * @param string|null $method Function name.
		 * @return int
		 */
		public function call_count( $method = null ) {
			return count( $this->calls( $method ) );
		}

		/**
		 * Inserted bookings, id => args.
		 *
		 * @return array
		 */
		public function bookings() {
			return $this->bookings;
		}

		/**
		 * One inserted booking, or null.
		 *
		 * @param int $id Booking id.
		 * @return array|null
		 */
		public function booking( $id ) {
			return isset( $this->bookings[ (int) $id ] ) ? $this->bookings[ (int) $id ] : null;
		}

		/**
		 * Inserted availability events, oldest first.
		 *
		 * @return array
		 */
		public function events() {
			return $this->events;
		}

		/**
		 * Availability events inserted for one booking.
		 *
		 * @param int $booking_id Booking id.
		 * @return array
		 */
		public function events_for( $booking_id ) {
			$out = array();
			foreach ( $this->events as $event ) {
				if ( isset( $event['booking_id'] ) && (int) $event['booking_id'] === (int) $booking_id ) {
					$out[] = $event;
				}
			}
			return $out;
		}

		/**
		 * Meta recorded for a booking.
		 *
		 * @param int $booking_id Booking id.
		 * @return array
		 */
		public function booking_meta( $booking_id ) {
			return isset( $this->meta[ (int) $booking_id ] ) ? $this->meta[ (int) $booking_id ] : array();
		}
	}

	/**
	 * Class FakeWpbsRecord
	 *
	 * Stands in for a WPBS calendar, booking or legend item object: a value bag
	 * answering get( $key ), plus the get_name() helper SourceWpbs prefers.
	 *
	 * The values are held as real properties rather than in an array, because
	 * WPBS's own object base resolves `get( $key )` to a property and therefore
	 * raises "Undefined property" for a key the record does not carry. A fake that
	 * held them in an array answered `property_exists()` with false for every key,
	 * so code probing for a key before reading it could not be exercised at all —
	 * and code reading a key WPBS does not have looked perfectly fine here while
	 * filling the live site's debug log with warnings.
	 *
	 * `get()` itself stays tolerant of an absent key, returning null rather than
	 * warning: what the fake is faithful about is which keys a record *has*.
	 *
	 * The attribute is a comment on PHP 7.4 and suppresses the PHP 8.2 dynamic
	 * property deprecation on newer versions. Root-namespaced deliberately:
	 * attribute names resolve against the current namespace, so an unqualified
	 * `AllowDynamicProperties` here would name a class in this namespace that does
	 * not exist and suppress nothing.
	 */
	#[\AllowDynamicProperties]
	class FakeWpbsRecord {

		/**
		 * Constructor.
		 *
		 * @param array $data Values.
		 */
		public function __construct( array $data ) {
			foreach ( $data as $key => $value ) {
				$this->{$key} = $value;
			}
		}

		/**
		 * Read a value.
		 *
		 * @param string $key Key.
		 * @return mixed|null
		 */
		public function get( $key ) {
			return property_exists( $this, $key ) ? $this->{$key} : null;
		}

		/**
		 * Calendar name.
		 *
		 * @return string
		 */
		public function get_name() {
			return (string) $this->get( 'name' );
		}

		/**
		 * Every value.
		 *
		 * @return array
		 */
		public function to_array() {
			return get_object_vars( $this );
		}
	}
}

namespace {

	use MarthrownEnquiryHub\Tests\Fakes\FakeWpbs;

	/*
	 * WPBS function shims, declared only when the real plugin is absent. Each one
	 * answers as WPBS would when no calendars exist if no fake is installed, so a
	 * suite that never installs the fake sees an empty WPBS rather than a fatal.
	 */

	if ( ! function_exists( 'wpbs_get_calendars' ) ) {
		/**
		 * Calendars.
		 *
		 * @param array $args Ignored.
		 * @return array
		 */
		function wpbs_get_calendars( $args = array() ) {
			$wpbs = FakeWpbs::current();
			return $wpbs ? $wpbs->calendar_objects() : array();
		}
	}

	if ( ! function_exists( 'wpbs_get_legend_items' ) ) {
		/**
		 * Legend items for a calendar.
		 *
		 * @param array $args Query args.
		 * @return array
		 */
		function wpbs_get_legend_items( $args = array() ) {
			$wpbs = FakeWpbs::current();
			return $wpbs ? $wpbs->legend_objects( (array) $args ) : array();
		}
	}

	if ( ! function_exists( 'wpbs_get_bookings' ) ) {
		/**
		 * Bookings.
		 *
		 * @param array $args Query args.
		 * @return array
		 */
		function wpbs_get_bookings( $args = array() ) {
			$wpbs = FakeWpbs::current();
			if ( ! $wpbs ) {
				return array();
			}
			$wpbs->record( 'wpbs_get_bookings', (array) $args );
			$out = array();
			foreach ( $wpbs->bookings() as $booking ) {
				$out[] = new \MarthrownEnquiryHub\Tests\Fakes\FakeWpbsRecord( $booking );
			}
			return $out;
		}
	}

	if ( ! function_exists( 'wpbs_get_booking' ) ) {
		/**
		 * One booking.
		 *
		 * @param int $id Booking id.
		 * @return \MarthrownEnquiryHub\Tests\Fakes\FakeWpbsRecord|false
		 */
		function wpbs_get_booking( $id ) {
			$wpbs = FakeWpbs::current();
			if ( ! $wpbs ) {
				return false;
			}
			$wpbs->record( 'wpbs_get_booking', array( 'id' => (int) $id ) );
			$booking = $wpbs->booking( $id );
			return $booking ? new \MarthrownEnquiryHub\Tests\Fakes\FakeWpbsRecord( $booking ) : false;
		}
	}

	if ( ! function_exists( 'wpbs_insert_booking' ) ) {
		/**
		 * Insert a booking.
		 *
		 * @param array $args Booking args.
		 * @return int|false
		 */
		function wpbs_insert_booking( $args = array() ) {
			$wpbs = FakeWpbs::current();
			return $wpbs ? $wpbs->insert_booking( (array) $args ) : false;
		}
	}

	if ( ! function_exists( 'wpbs_insert_event' ) ) {
		/**
		 * Insert an availability event.
		 *
		 * @param array $args Event args.
		 * @return int|false
		 */
		function wpbs_insert_event( $args = array() ) {
			$wpbs = FakeWpbs::current();
			return $wpbs ? $wpbs->insert_event( (array) $args ) : false;
		}
	}

	if ( ! function_exists( 'wpbs_add_booking_meta' ) ) {
		/**
		 * Record booking meta.
		 *
		 * @param int    $booking_id Booking id.
		 * @param string $key        Meta key.
		 * @param mixed  $value      Meta value.
		 * @return bool
		 */
		function wpbs_add_booking_meta( $booking_id, $key, $value ) {
			$wpbs = FakeWpbs::current();
			return $wpbs ? $wpbs->add_booking_meta( $booking_id, $key, $value ) : false;
		}
	}

	if ( ! function_exists( 'wpbs_generate_hash' ) ) {
		/**
		 * Invoice hash.
		 *
		 * @return string
		 */
		function wpbs_generate_hash() {
			return md5( uniqid( 'fake-wpbs-', true ) );
		}
	}
}
