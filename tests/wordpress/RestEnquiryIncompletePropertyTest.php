<?php
/**
 * Property 32: Incomplete rows fail loudly.
 *
 * Feature: enquiry-data-layer, Property 32: For any stored enquiry missing a
 * value for `email`, `status` or `created_at`, the single-enquiry route responds
 * 500 with an error identifying the missing field and returns no partial
 * enquiry representation.
 *
 * **Validates: Requirements 13.5**
 *
 * How the property is instantiated:
 *
 * - The three fields are blanked *after* the row is written, with a direct
 *   `$wpdb->update` on the enquiries table, exactly as the worked examples in
 *   `RestEnquirySingleTest` do. Nothing in the plugin can write a row this way —
 *   which is the point. Requirement 13.5 is about a row that a migration, a
 *   hand-run `UPDATE` or a half-finished write left incomplete, so the only
 *   honest way to reach that state is to reach around the store.
 * - Quantification is over *which subset* of the three is blanked, not over one
 *   field at a time: all seven non-empty subsets are drawn, so blanking two or
 *   all three is covered, and the reported field list has to name every one of
 *   them rather than stopping at the first. The subset is drawn as a bitmask over
 *   the three field names, so no generator has to carry an array of arrays.
 * - Every case reads the enquiry once *before* blanking and asserts 200. Without
 *   that the property would pass just as happily against a route that answered
 *   500 for every read, and it would tell us nothing about the blanking being the
 *   cause.
 * - "No partial representation" is checked two ways. Every key a single-enquiry
 *   representation carries — the stored columns, the candidate dates, both
 *   multi-selects, the payload snapshot, the notes, the history, the CRM URL, the
 *   permitted transitions and the siblings — must be absent from the response
 *   body; and the distinctive fixture values the row holds must not appear
 *   anywhere in the encoded body either, so nothing leaks through a key this test
 *   did not think to name. Those values are fixed sentinels rather than generated
 *   strings, because a generated value can collide with the error payload's own
 *   text — an `_` or a `%s` drawn from the adversarial set would match
 *   `meh_enquiry_incomplete` or `enquiry_id` and fail for the wrong reason.
 * - The row's own shape is generated: its status, whether it is a test record,
 *   its CRM sync state, whether it carries a subscriber identifier and a booking
 *   identifier, how many candidate dates and multi-select values it holds, how
 *   many notes and history entries it has, and its guest count and names. A
 *   sibling sharing the sentinel email is seeded too, so `siblings` would be
 *   non-empty if a partial representation were ever assembled.
 * - The case generator is wide and Eris shrinks a wide composite generator by
 *   building the cartesian product of every component's alternatives, which
 *   exhausts memory before it reports anything. The whole case is therefore drawn
 *   through `unshrunk()`, which reports the failing case exactly as generated.
 *
 * Requests go through the real route with `rest_do_request()` and an
 * authenticated administrator, so the route pattern, the permission callback and
 * the `absint` sanitisation of the identifier are all in the loop.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Auth;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\Lifecycle;
use MarthrownEnquiryHub\NoteService;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class RestEnquiryIncompletePropertyTest
 */
class RestEnquiryIncompletePropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table, or with another test's fixture, in the same database.
	 */
	const PREFIX_SEGMENT = 'mehic_';

	/**
	 * The single-enquiry route, without the identifier.
	 */
	const ROUTE = '/marthrown-enquiry-hub/v1/enquiries/';

	/**
	 * The instant the clock is frozen at, so nothing depends on the wall clock.
	 */
	const AT = '2025-07-04 14:30:00';

	/**
	 * The fields a representation cannot be built without (Requirement 13.5).
	 *
	 * Written out rather than read from `RestEnquiries::REQUIRED_FIELDS`, so a
	 * field quietly dropped from that constant is a failure here.
	 *
	 * @var string[]
	 */
	const REQUIRED_FIELDS = array( 'email', 'status', 'created_at' );

	/**
	 * The documented error code for an incomplete row (Requirement 13.5).
	 */
	const INCOMPLETE_CODE = 'meh_enquiry_incomplete';

	/**
	 * What each column holds when it holds no value.
	 *
	 * A `varchar` column's "no value" is the empty string; a `DATETIME` column's
	 * is the zero date, which is what the column defaults to and what the store
	 * hydrates back to the empty string.
	 *
	 * @var array<string,string>
	 */
	const BLANKS = array(
		'email'      => '',
		'status'     => '',
		'created_at' => '0000-00-00 00:00:00',
	);

	/** Sentinel email, shared with the sibling so `siblings` is non-empty. */
	const EMAIL = 'qqq-enquirer@example.com';

	/** Sentinel message body. */
	const MESSAGE = 'QQQ-message-body';

	/** Sentinel note body. */
	const NOTE = 'QQQ-note-body';

	/** Sentinel source. */
	const SOURCE = 'webhook:QQQ-source';

	/** Sentinel value inside the payload snapshot. */
	const PAYLOAD = 'QQQ-payload-value';

	/** Sentinel history description. */
	const HISTORY = 'QQQ-history-description';

	/** Most candidate date ranges one generated enquiry holds. */
	const RANGES_MAX = 3;

	/** Most notes and history entries one generated enquiry holds. */
	const ENTRIES_MAX = 2;

	/**
	 * Every key a single-enquiry representation carries.
	 *
	 * Listed here rather than read from a response, so a representation that grew
	 * a key is still covered by the "nothing partial comes back" assertion once
	 * this list is updated, and so the assertion cannot be satisfied by an empty
	 * list.
	 *
	 * `message` is the one stored field missing from the list, because a REST
	 * error body carries a `message` of its own — the explanation naming the
	 * missing fields, which Requirement 13.5 asks for. That the enquiry's own
	 * message does not come back is asserted by the sentinel check instead.
	 *
	 * @var string[]
	 */
	const REPRESENTATION_KEYS = array(
		'id',
		'first_name',
		'last_name',
		'email',
		'phone',
		'total_guests',
		'status',
		'crm_sync_state',
		'fluentcrm_subscriber_id',
		'booking_id',
		'created_at',
		'updated_at',
		'status_changed_at',
		'source',
		'is_test',
		'duplicated_from_id',
		'duplicated_to_id',
		'date_ranges',
		'event_type',
		'site_exclusivity',
		'payload',
		'notes',
		'history',
		'crm_url',
		'allowed_transitions',
		'siblings',
	);

	/**
	 * The keys a REST error body may carry, and nothing else.
	 *
	 * @var string[]
	 */
	const ERROR_KEYS = array( 'code', 'message', 'data', 'additional_errors' );

	/**
	 * Multi-select value sets one generated enquiry may hold.
	 *
	 * @var array
	 */
	const TERM_SETS = array(
		array(),
		array( 'wedding' ),
		array( 'wedding', 'reception' ),
	);

	/**
	 * The administrator every request is made as.
	 *
	 * `Auth::rest_permission` admits a logged-in user holding `manage_options`
	 * whatever the Access settings say, so an administrator is the caller that
	 * cannot be refused for a reason this property is not about.
	 *
	 * @var int
	 */
	private static $user_id = 0;

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The REST server in force outside this test.
	 *
	 * @var \WP_REST_Server|null
	 */
	private $original_server = null;

	/**
	 * Load the classes under test, and create the calling user.
	 *
	 * The user is created before any prefix switch, so it lands in the real users
	 * table rather than in a fixture one.
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
		require_once MEH_INCLUDES_DIR . 'class-note-service.php';
		require_once MEH_INCLUDES_DIR . 'class-lifecycle.php';
		require_once MEH_INCLUDES_DIR . 'class-auth.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';

		self::$user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up() {
		parent::set_up();

		global $wpdb, $wp_rest_server;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		Clock::freeze( self::AT );
		wp_set_current_user( self::$user_id );

		// A server of this test's own, so the route table holds what this test
		// registered and nothing a previous test left behind.
		$this->original_server = $wp_rest_server;
		$wp_rest_server        = new WP_REST_Server();

		RestEnquiries::init();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->assertTrue( Auth::rest_permission(), 'The calling user should be admitted by the route.' );
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		$wp_rest_server = $this->original_server;

		wp_set_current_user( 0 );
		Clock::unfreeze();

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 32: Incomplete rows fail loudly.
	 *
	 * **Validates: Requirements 13.5**
	 */
	public function test_incomplete_rows_fail_loudly() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::scenario() )
			->then(
				function ( array $case ) {
					$blanked = self::blank_set( (int) $case['mask'] );
					$id      = $this->seed( $case );
					$context = ' Case: ' . wp_json_encode(
						array(
							'blanked' => $blanked,
							'status'  => $case['status'],
							'ranges'  => (int) $case['range_count'],
							'notes'   => (int) $case['notes'],
						)
					);

					// The same row reads back whole before it is damaged, so the
					// 500 below can only be the blanking talking.
					$whole = $this->read( $id );

					$this->assertSame( 200, $whole->get_status(), 'The intact row should read back.' . $context );
					$this->assertSame( $id, (int) $whole->get_data()['id'], 'The intact read should be this enquiry.' . $context );

					$this->blank( $id, $blanked );

					$response = $this->read( $id );
					$data     = (array) $response->get_data();

					// Requirement 13.5: 500, naming every missing field.
					$this->assertSame( 500, $response->get_status(), 'An incomplete row should answer 500.' . $context );
					$this->assertTrue( $response->is_error(), 'An incomplete row should answer an error.' . $context );
					$this->assertSame( self::INCOMPLETE_CODE, isset( $data['code'] ) ? $data['code'] : '', 'The error code.' . $context );

					$this->assert_names_the_missing_fields( $data, $blanked, $context );

					// Requirement 13.5: no partial representation at all.
					$this->assert_no_representation( $data, $context );
					$this->assert_nothing_leaks( $data, $case, $blanked, $context );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: which of the three fields to blank, and the shape of the row to
	 * blank them on.
	 *
	 * Candidate dates and entry counts are generated at full width and sliced in
	 * the property, so their sizes are quantified over without a bound generator.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return self::unshrunk(
			\Eris\Generators::associative(
				array(
					// 1 to 7: every non-empty subset of the three fields.
					'mask'         => \Eris\Generators::choose( 1, ( 1 << count( self::REQUIRED_FIELDS ) ) - 1 ),
					'status'       => \Eris\Generators::elements( array_values( Lifecycle::STATUSES ) ),
					'crm_state'    => \Eris\Generators::elements( array( 'pending', 'synced' ) ),
					'subscriber'   => \Eris\Generators::elements( array( 0, 91 ) ),
					'booking'      => \Eris\Generators::elements( array( 0, 7 ) ),
					'is_test'      => \Eris\Generators::elements( array( true, false ) ),
					'first_name'   => Generators::first_name(),
					'last_name'    => Generators::last_name(),
					'phone'        => Generators::phone_or_empty(),
					'total_guests' => Generators::total_guests_or_unsupplied(),
					'range_count'  => \Eris\Generators::choose( 1, self::RANGES_MAX ),
					'range_gaps'   => \Eris\Generators::vector( self::RANGES_MAX, \Eris\Generators::choose( 1, 45 ) ),
					'range_spans'  => \Eris\Generators::vector( self::RANGES_MAX, \Eris\Generators::choose( 0, 5 ) ),
					'terms'        => \Eris\Generators::choose( 0, count( self::TERM_SETS ) - 1 ),
					'notes'        => \Eris\Generators::choose( 0, self::ENTRIES_MAX ),
					'entries'      => \Eris\Generators::choose( 0, self::ENTRIES_MAX ),
				)
			)
		);
	}

	/**
	 * The same generator, with shrinking switched off.
	 *
	 * Eris shrinks a composite generator by taking the cartesian product of every
	 * component's alternatives. For a generator as wide as the case above that
	 * product is large enough to exhaust the process' memory, so a genuine
	 * failure would be reported as an out-of-memory error rather than as a
	 * counterexample. Returning the element unchanged from `shrink()` is the
	 * termination condition Eris' own interface names, so the failing case is
	 * reported exactly as it was generated.
	 *
	 * @param \Eris\Generator $generator Generator to wrap.
	 * @return \Eris\Generator
	 */
	protected static function unshrunk( \Eris\Generator $generator ) {
		return new class( $generator ) implements \Eris\Generator {

			/**
			 * The wrapped generator.
			 *
			 * @var \Eris\Generator
			 */
			private $generator;

			/**
			 * @param \Eris\Generator $generator Generator to wrap.
			 */
			public function __construct( \Eris\Generator $generator ) {
				$this->generator = $generator;
			}

			/**
			 * Generate one value, exactly as the wrapped generator would.
			 *
			 * @param int                      $size Generation size.
			 * @param \Eris\Random\RandomRange $rand Randomness source.
			 * @return \Eris\Generator\GeneratedValueSingle
			 */
			public function __invoke( $size, \Eris\Random\RandomRange $rand ) {
				return call_user_func( $this->generator, $size, $rand );
			}

			/**
			 * Offer no simpler value, which ends shrinking immediately.
			 *
			 * @param \Eris\Generator\GeneratedValue $element Failing value.
			 * @return \Eris\Generator\GeneratedValue
			 */
			public function shrink( \Eris\Generator\GeneratedValue $element ) {
				return $element;
			}
		};
	}

	/**
	 * The fields a bitmask selects, in the order the fields are declared.
	 *
	 * @param int $mask Bitmask over REQUIRED_FIELDS, 1 to 7.
	 * @return string[] At least one field name.
	 */
	protected static function blank_set( $mask ) {
		$fields = array();

		foreach ( self::REQUIRED_FIELDS as $index => $field ) {
			if ( (int) $mask & ( 1 << $index ) ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * The error names every blanked field and no other (Requirement 13.5).
	 *
	 * The reported list is compared as a set, because Requirement 13.5 asks the
	 * response to identify the missing field rather than to order the names.
	 *
	 * @param array    $data    Response body.
	 * @param string[] $blanked Fields that were blanked.
	 * @param string   $context Case description for failure messages.
	 * @return void
	 */
	private function assert_names_the_missing_fields( array $data, array $blanked, $context ) {
		$this->assertArrayHasKey( 'data', $data, 'The error should carry data.' . $context );
		$this->assertArrayHasKey( 'missing', (array) $data['data'], 'The error should name the missing fields.' . $context );

		$reported = array_values( (array) $data['data']['missing'] );
		$expected = $blanked;

		sort( $reported );
		sort( $expected );

		$this->assertSame( $expected, $reported, 'The reported missing fields.' . $context );

		$message = isset( $data['message'] ) ? (string) $data['message'] : '';

		foreach ( $blanked as $field ) {
			$this->assertStringContainsString( $field, $message, 'The message should name ' . $field . '.' . $context );
		}
	}

	/**
	 * The response carries no part of an enquiry representation
	 * (Requirement 13.5).
	 *
	 * @param array  $data    Response body.
	 * @param string $context Case description for failure messages.
	 * @return void
	 */
	private function assert_no_representation( array $data, $context ) {
		foreach ( self::REPRESENTATION_KEYS as $key ) {
			$this->assertArrayNotHasKey( $key, $data, 'A failed read returns no ' . $key . '.' . $context );
		}

		foreach ( array_keys( $data ) as $key ) {
			$this->assertContains( $key, self::ERROR_KEYS, 'A failed read carries error keys only.' . $context );
		}
	}

	/**
	 * None of the row's distinctive values appears anywhere in the response, so
	 * nothing leaks through a key this test did not name (Requirement 13.5).
	 *
	 * A blanked field holds no value to leak, so it is skipped.
	 *
	 * @param array    $data    Response body.
	 * @param array    $case    Generated case.
	 * @param string[] $blanked Fields that were blanked.
	 * @param string   $context Case description for failure messages.
	 * @return void
	 */
	private function assert_nothing_leaks( array $data, array $case, array $blanked, $context ) {
		$encoded = (string) wp_json_encode( $data );

		$sentinels = array_merge(
			array( self::MESSAGE, self::NOTE, self::SOURCE, self::PAYLOAD, self::HISTORY ),
			self::range_bounds( $case )
		);

		if ( ! in_array( 'email', $blanked, true ) ) {
			$sentinels[] = self::EMAIL;
		}

		if ( ! in_array( 'created_at', $blanked, true ) ) {
			$sentinels[] = self::AT;
		}

		foreach ( $sentinels as $sentinel ) {
			$this->assertStringNotContainsString(
				(string) $sentinel,
				$encoded,
				'A failed read leaks nothing of the enquiry.' . $context
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * The route
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch one read through the real route.
	 *
	 * @param int $id Enquiry identifier.
	 * @return \WP_REST_Response
	 */
	private function read( $id ) {
		return rest_do_request( new WP_REST_Request( 'GET', self::ROUTE . (int) $id ) );
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Write the generated enquiry and a sibling sharing its email, and return the
	 * generated enquiry's identifier.
	 *
	 * @param array $case Generated case.
	 * @return int
	 */
	private function seed( array $case ) {
		$this->clear();

		$fields = array(
			'first_name'              => $case['first_name'],
			'last_name'               => $case['last_name'],
			'email'                   => self::EMAIL,
			'phone'                   => $case['phone'],
			'total_guests'            => $case['total_guests'],
			'message'                 => self::MESSAGE,
			'status'                  => $case['status'],
			'crm_sync_state'          => $case['crm_state'],
			'fluentcrm_subscriber_id' => $case['subscriber'] > 0 ? (int) $case['subscriber'] : null,
			'booking_id'              => $case['booking'] > 0 ? (int) $case['booking'] : null,
			'created_at'              => self::AT,
			'updated_at'              => self::AT,
			'status_changed_at'       => self::AT,
			'source'                  => self::SOURCE,
			'is_test'                 => $case['is_test'] ? 1 : 0,
		);

		$terms = self::TERM_SETS[ (int) $case['terms'] ];

		$id = EnquiryStore::create(
			$fields,
			self::ranges( $case ),
			array(
				'event_type'       => $terms,
				'site_exclusivity' => $terms ? array( 'exclusive-use' ) : array(),
			),
			array( 'submitted' => self::PAYLOAD )
		);

		$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

		$id = (int) $id;

		for ( $index = 0; $index < (int) $case['notes']; $index++ ) {
			$this->assertIsInt(
				NoteService::add( $id, self::NOTE . '-' . $index, self::$user_id ),
				'Seeding a note should succeed.'
			);
		}

		for ( $index = 0; $index < (int) $case['entries']; $index++ ) {
			HistoryRecorder::record( $id, 'created', self::HISTORY . '-' . $index, array(), 0 );
		}

		// A sibling sharing the email, so `siblings` would be non-empty if a
		// partial representation were ever assembled.
		$sibling = EnquiryStore::create(
			array_merge( $fields, array( 'status' => 'new' ) ),
			array( Generators::date_at( 60 ) )
		);

		$this->assertIsInt( $sibling, 'Seeding the sibling should succeed.' );

		return $id;
	}

	/**
	 * Blank the named columns with a direct write, which is the only way to reach
	 * the state Requirement 13.5 describes.
	 *
	 * @param int      $id     Enquiry identifier.
	 * @param string[] $fields Fields to blank.
	 * @return void
	 */
	private function blank( $id, array $fields ) {
		global $wpdb;

		$values = array();

		foreach ( $fields as $field ) {
			$values[ $field ] = self::BLANKS[ $field ];
		}

		$this->assertNotFalse(
			$wpdb->update( Schema::table( 'enquiries' ), $values, array( 'id' => (int) $id ) ),
			'Blanking ' . implode( ', ', $fields ) . ' should succeed: ' . $wpdb->last_error
		);
	}

	/**
	 * The candidate date ranges one generated case holds, distinct and ascending.
	 *
	 * Each range starts after the previous one ended, so no two of them overlap
	 * and the store cannot collapse two into one: the row count this property
	 * seeds is the count it asked for. A span of zero days is a single day, which
	 * is a range like any other.
	 *
	 * @param array $case Generated case.
	 * @return array<int,array{start:string,end:string}>
	 */
	private static function ranges( array $case ) {
		$ranges = array();
		$gaps   = array_values( (array) $case['range_gaps'] );
		$spans  = array_values( (array) $case['range_spans'] );
		$offset = 0;

		for ( $index = 0; $index < (int) $case['range_count']; $index++ ) {
			$offset  += max( 1, (int) $gaps[ $index ] );
			$span     = max( 0, (int) $spans[ $index ] );
			$ranges[] = array(
				'start' => Generators::date_at( $offset ),
				'end'   => Generators::date_at( $offset + $span ),
			);

			$offset += $span;
		}

		return $ranges;
	}

	/**
	 * Every date one generated case names, as a flat list of sentinels.
	 *
	 * @param array $case Generated case.
	 * @return string[]
	 */
	private static function range_bounds( array $case ) {
		$bounds = array();

		foreach ( self::ranges( $case ) as $range ) {
			$bounds[] = $range['start'];
			$bounds[] = $range['end'];
		}

		return array_values( array_unique( $bounds ) );
	}

	/**
	 * Empty the tables this property writes, between iterations.
	 *
	 * `DELETE` rather than `TRUNCATE`: the identifiers keep climbing, which is
	 * closer to a live table and means a stale identifier can never be mistaken
	 * for a fresh one.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );

			if ( '' === $table ) {
				continue;
			}

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
