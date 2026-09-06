<?php
/**
 * Property 30: Pagination, ordering and counts are consistent.
 *
 * Feature: enquiry-data-layer, Property 30: For any population, any requested
 * `per_page` value (including absent, zero, negative and values above 200) and
 * any page number, the list route returns at most `min(requested or 25, 200)`
 * items, returns exactly that many while unreturned matching rows remain,
 * orders results by `created_at` descending with a deterministic tie-break,
 * reports `X-WP-Total` equal to the total matching count and `X-WP-TotalPages`
 * equal to that total divided by the effective page size rounded up, and
 * reports per-status counts each equal to the number of stored enquiries
 * matching the other supplied filters with that status; and the union of all
 * pages equals the full matching set with no duplicates.
 *
 * **Validates: Requirements 12.9, 12.10, 12.11, 12.14**
 *
 * Five things about how the property is instantiated are worth stating plainly:
 *
 * - It goes through the real route. `rest_do_request()` dispatches
 *   `GET /marthrown-enquiry-hub/v1/enquiries` on a REST server this test
 *   registered, with an administrator authenticated so `Auth::rest_permission`
 *   passes. Argument defaults, the `absint` sanitisation of `page` and
 *   `per_page`, `EnquiryQuery::normalise()`, the SQL and the two headers are
 *   therefore all in the loop — which is the point, because "the page size the
 *   route used" is a claim about that whole chain rather than about any one link.
 * - Paging is only observable against a *known* order, so the whole matching set
 *   is computed by a plain `foreach` and `usort()` over the seeded population,
 *   sharing no code and no constant with `EnquiryQuery`. Every seeded creation
 *   time is drawn from a handful of values, so two enquiries sharing a
 *   `created_at` second are routine rather than rare: that is the case the `id`
 *   tie-break exists for, and without it a row can appear on two pages or on
 *   none.
 * - Requested page sizes stay small (1 to 4) alongside a population of up to
 *   nine, so a page genuinely truncates and the "exactly that many while rows
 *   remain" clause bites. The boundary values — absent, `0`, a negative, a
 *   non-numeric string, 25, 200, 201, 500 — are drawn too, and for those the
 *   effective size is read off the response and checked against the default and
 *   the cap. Seeding 201 rows per iteration to watch the cap truncate would cost
 *   minutes per run and tell us nothing the reported size and the fullness rule
 *   do not.
 * - A negative `per_page` becomes its magnitude, because the route sanitises the
 *   parameter with `absint` before anything else sees it; a value that is not a
 *   page size at all — absent, zero, non-numeric — takes the documented default
 *   of 25 (Requirement 12.10). The oracle below states both, and caps at 200.
 * - The case generator is wide — nine rows of four fields each, plus the filters
 *   and the two paging parameters — and Eris shrinks a generator that wide by
 *   building the cartesian product of every component's alternatives, which
 *   exhausts memory long before it reports anything. So the whole case is drawn
 *   through `unshrunk()` below, which reports the failing case as generated
 *   instead of trying to simplify it, and no `Eris\Generators::bind()` appears
 *   anywhere: the population is generated at full width and sliced to a
 *   generated size inside the property. Filters are drawn independently of the
 *   population for the same reason, and every filter here is a coarse one —
 *   status, `hide_test` and the `created_at` bounds — since which rows a filter
 *   matches is Property 28's subject, not this one's. What this property needs
 *   from them is only that they partition the population, so the counts and the
 *   total describe a proper subset rather than the whole table.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix, because `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Auth;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\Lifecycle;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class EnquiryPaginationPropertyTest
 */
class EnquiryPaginationPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table, or with another test's fixture, in the same database.
	 */
	const PREFIX_SEGMENT = 'mehpg_';

	/**
	 * The list route under test.
	 */
	const ROUTE = '/marthrown-enquiry-hub/v1/enquiries';

	/**
	 * The instant the clock is frozen at, so nothing depends on the wall clock.
	 */
	const AT = '2025-07-04 14:30:00';

	/**
	 * Documented default page size (Requirement 12.10).
	 *
	 * Written out rather than read from `EnquiryQuery`, so a default quietly
	 * changed in the code under test is a failure here.
	 */
	const PER_PAGE_DEFAULT = 25;

	/**
	 * Documented page size cap (Requirement 12.10).
	 */
	const PER_PAGE_MAX = 200;

	/**
	 * Most enquiries one iteration seeds.
	 */
	const POPULATION_MAX = 9;

	/**
	 * Creation days, as offsets from the generators' base date.
	 *
	 * Few enough that several enquiries share a creation day, which is what makes
	 * the `created_at` bounds bite and the ordering tie-break observable.
	 *
	 * @var int[]
	 */
	const CREATED_OFFSETS = array( 0, 1, 2, 3 );

	/**
	 * Times of day rows are created at, including the day's last second.
	 *
	 * @var string[]
	 */
	const CREATED_TIMES = array( '09:30:00', '23:59:59' );

	/**
	 * Requested page sizes.
	 *
	 * The small values are the ones a page of nine rows truncates on; the rest
	 * are the boundaries Requirement 12.10 and Property 30 name — absent (`null`,
	 * meaning the parameter is not sent at all), zero, negative, non-numeric, the
	 * default, the cap, and values above the cap.
	 *
	 * @var array
	 */
	const PER_PAGE_VALUES = array( null, 1, 2, 3, 4, 0, -3, '', 'abc', 25, 200, 201, 500 );

	/**
	 * Requested page numbers, including `0`, which is not a page number.
	 *
	 * @var int[]
	 */
	const PAGE_VALUES = array( 0, 1, 2, 3, 4, 9 );

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
	 * Feature: enquiry-data-layer, Property 30: Pagination, ordering and counts
	 * are consistent.
	 *
	 * **Validates: Requirements 12.9, 12.10, 12.11, 12.14**
	 */
	public function test_pagination_ordering_and_counts_are_consistent() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::scenario() )
			->then(
				function ( array $case ) {
					$population = $this->seed( array_slice( $case['rows'], 0, (int) $case['size'] ) );
					$query      = self::query_params( $case['filters'] );

					$size = self::effective_per_page( $case['per_page'] );
					$page = self::effective_page( $case['page'] );

					// The whole matching set, in the order the route should
					// return it (Requirement 12.14).
					$expected = self::reference_ids( $population, $query );
					$total    = count( $expected );
					$pages    = (int) ceil( $total / $size );

					$response = $this->dispatch( $query, $case['per_page'], $case['page'] );
					$context  = ' Case: ' . wp_json_encode(
						array(
							'query'    => $query,
							'per_page' => $case['per_page'],
							'page'     => $case['page'],
							'seeded'   => count( $population ),
						)
					);

					$this->assertSame( 200, $response->get_status(), 'The list route should answer 200.' . $context );

					// Requirement 12.10: the effective page size is the default
					// when none was usably requested, and never above the cap.
					$this->assertSame( $size, (int) $response->get_data()['per_page'], 'The effective page size.' . $context );
					$this->assertGreaterThanOrEqual( 1, (int) $response->get_data()['per_page'], 'A page size is at least one.' . $context );
					$this->assertLessThanOrEqual( self::PER_PAGE_MAX, (int) $response->get_data()['per_page'], 'A page size never exceeds the cap.' . $context );
					$this->assertSame( $page, (int) $response->get_data()['page'], 'The effective page number.' . $context );

					// Requirements 12.11, 12.14: the totals describe the whole
					// matching set, and this page is its ordered slice.
					$this->assert_totals( $response, $total, $pages, $context );
					$this->assertSame(
						array_slice( $expected, ( $page - 1 ) * $size, $size ),
						self::ids( $response ),
						'The page should be the ordered slice of the matching set.' . $context
					);

					// Requirement 12.9: the per-status counts.
					$this->assert_counts( $response, $population, $query, $context );

					// Every page concatenated is the matching set exactly once.
					$this->assert_pages_cover_the_set( $query, $case['per_page'], $expected, $pages, $context );

					// A page past the last one is empty but still reports the
					// totals of the whole matching set (Requirement 12.11).
					$this->assert_page_past_the_last( $query, $case['per_page'], $pages, $total, $context );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: a population, how much of it to seed, the filters to read it
	 * with, and the requested page size and page number.
	 *
	 * The population is generated at full width and sliced in the property, so
	 * the size is quantified over without a bound generator.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return self::unshrunk(
			\Eris\Generators::associative(
				array(
					'size'     => \Eris\Generators::choose( 0, self::POPULATION_MAX ),
					'rows'     => \Eris\Generators::vector( self::POPULATION_MAX, self::row() ),
					'filters'  => self::filters(),
					'per_page' => \Eris\Generators::elements( self::PER_PAGE_VALUES ),
					'page'     => \Eris\Generators::elements( self::PAGE_VALUES ),
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
			 * @param int                        $size Generation size.
			 * @param \Eris\Random\RandomRange   $rand Randomness source.
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
	 * One enquiry, described by only what this property reads: when it was
	 * created, what status it holds and whether it is a test record.
	 *
	 * @return \Eris\Generator
	 */
	protected static function row() {
		return \Eris\Generators::associative(
			array(
				'created_offset' => \Eris\Generators::elements( self::CREATED_OFFSETS ),
				'created_time'   => \Eris\Generators::elements( self::CREATED_TIMES ),
				'status'         => \Eris\Generators::elements( self::statuses() ),
				'is_test'        => \Eris\Generators::elements( array( true, false ) ),
			)
		);
	}

	/**
	 * A subset of the coarse filters, `null` meaning the parameter is not sent.
	 *
	 * @return \Eris\Generator
	 */
	protected static function filters() {
		$offsets = array_merge( array( null ), self::CREATED_OFFSETS );

		return \Eris\Generators::associative(
			array(
				'status'      => \Eris\Generators::elements(
					array_merge( array( null, 'all' ), self::statuses() )
				),
				'hide_test'   => \Eris\Generators::elements( array( null, true, false ) ),
				'from_offset' => \Eris\Generators::elements( $offsets ),
				'to_offset'   => \Eris\Generators::elements( $offsets ),
			)
		);
	}

	/**
	 * The recognised statuses, drawn from the lifecycle so a status added later
	 * cannot leave this property under-quantified.
	 *
	 * @return string[]
	 */
	protected static function statuses() {
		return array_values( Lifecycle::STATUSES );
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * The totals in the body and in the two headers describe the whole matching
	 * set (Requirement 12.11).
	 *
	 * @param \WP_REST_Response $response Response under test.
	 * @param int               $total    Expected matching count.
	 * @param int               $pages    Expected page count.
	 * @param string            $context  Case description for failure messages.
	 * @return void
	 */
	private function assert_totals( $response, $total, $pages, $context ) {
		$headers = $response->get_headers();

		$this->assertSame( $total, (int) $response->get_data()['total'], 'The reported total.' . $context );
		$this->assertSame( $pages, (int) $response->get_data()['total_pages'], 'The reported page count.' . $context );

		$this->assertArrayHasKey( 'X-WP-Total', $headers, 'The total header should be present.' . $context );
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers, 'The page count header should be present.' . $context );

		$this->assertSame( (string) $total, (string) $headers['X-WP-Total'], 'The total header.' . $context );
		$this->assertSame( (string) $pages, (string) $headers['X-WP-TotalPages'], 'The page count header.' . $context );
	}

	/**
	 * Each per-status count equals the number of stored enquiries matching the
	 * other supplied filters with that status, and `all` is their sum
	 * (Requirement 12.9).
	 *
	 * @param \WP_REST_Response $response   Response under test.
	 * @param array             $population Seeded population.
	 * @param array             $query      Supplied filters.
	 * @param string            $context    Case description for failure messages.
	 * @return void
	 */
	private function assert_counts( $response, array $population, array $query, $context ) {
		$counts = (array) $response->get_data()['counts'];
		$sum    = 0;

		foreach ( self::statuses() as $status ) {
			$this->assertArrayHasKey( $status, $counts, 'Every status should be counted.' . $context );

			$this->assertSame(
				self::reference_count( $population, $query, $status ),
				(int) $counts[ $status ],
				'The count for status ' . $status . '.' . $context
			);

			$sum += (int) $counts[ $status ];
		}

		$this->assertArrayHasKey( 'all', $counts, 'The unfiltered count should be present.' . $context );
		$this->assertSame( $sum, (int) $counts['all'], 'The `all` count should be the sum of the rest.' . $context );
		$this->assertSame(
			self::reference_count( $population, $query, null ),
			(int) $counts['all'],
			'The `all` count should ignore the status filter alone.' . $context
		);
	}

	/**
	 * Walking every page returns the whole matching set, in order, each row once.
	 *
	 * @param array  $query    Supplied filters.
	 * @param mixed  $per_page Requested page size.
	 * @param int[]  $expected The matching set, in order.
	 * @param int    $pages    Expected page count.
	 * @param string $context  Case description for failure messages.
	 * @return void
	 */
	private function assert_pages_cover_the_set( array $query, $per_page, array $expected, $pages, $context ) {
		$walked = array();

		for ( $page = 1; $page <= $pages; $page++ ) {
			$walked = array_merge( $walked, self::ids( $this->dispatch( $query, $per_page, $page ) ) );
		}

		$this->assertSame(
			$expected,
			$walked,
			'Every page concatenated should be the ordered matching set.' . $context
		);

		$this->assertSame(
			count( $walked ),
			count( array_unique( $walked ) ),
			'No enquiry should appear on two pages.' . $context
		);
	}

	/**
	 * A page past the last one is empty and still reports the whole matching set
	 * (Requirement 12.11).
	 *
	 * @param array  $query    Supplied filters.
	 * @param mixed  $per_page Requested page size.
	 * @param int    $pages    Expected page count.
	 * @param int    $total    Expected matching count.
	 * @param string $context  Case description for failure messages.
	 * @return void
	 */
	private function assert_page_past_the_last( array $query, $per_page, $pages, $total, $context ) {
		$beyond   = max( 1, (int) $pages + 1 );
		$response = $this->dispatch( $query, $per_page, $beyond );

		$this->assertSame( 200, $response->get_status(), 'A page past the last should still answer 200.' . $context );
		$this->assertSame( array(), self::ids( $response ), 'A page past the last should be empty.' . $context );

		$this->assert_totals( $response, $total, $pages, $context );
	}

	/* ---------------------------------------------------------------------
	 * The reference implementation
	 * ------------------------------------------------------------------ */

	/**
	 * The matching identifiers in the order the route should return them: by
	 * `created_at` descending, ties broken by descending identifier
	 * (Requirement 12.14).
	 *
	 * Shares no code and no constant with `EnquiryQuery`.
	 *
	 * @param array $population Seeded population.
	 * @param array $query      Supplied filters.
	 * @return int[]
	 */
	private static function reference_ids( array $population, array $query ) {
		$matching = array();

		foreach ( $population as $row ) {
			if ( self::reference_keeps( $row, $query, true ) ) {
				$matching[] = $row;
			}
		}

		usort(
			$matching,
			function ( array $left, array $right ) {
				if ( $left['created_at'] === $right['created_at'] ) {
					return (int) $right['id'] - (int) $left['id'];
				}

				return strcmp( (string) $right['created_at'], (string) $left['created_at'] );
			}
		);

		$ids = array();

		foreach ( $matching as $row ) {
			$ids[] = (int) $row['id'];
		}

		return $ids;
	}

	/**
	 * How many seeded enquiries hold a status, under every supplied filter but
	 * the status filter itself (Requirement 12.9).
	 *
	 * @param array       $population Seeded population.
	 * @param array       $query      Supplied filters.
	 * @param string|null $status     Status to count, or null for every status.
	 * @return int
	 */
	private static function reference_count( array $population, array $query, $status ) {
		$count = 0;

		foreach ( $population as $row ) {
			if ( ! self::reference_keeps( $row, $query, false ) ) {
				continue;
			}

			if ( null === $status || (string) $row['status'] === (string) $status ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Whether one enquiry satisfies the supplied filters.
	 *
	 * @param array $row           Seeded enquiry.
	 * @param array $query         Supplied filters.
	 * @param bool  $apply_status  Whether the status filter applies.
	 * @return bool
	 */
	private static function reference_keeps( array $row, array $query, $apply_status ) {
		if ( $apply_status
			&& isset( $query['status'] )
			&& 'all' !== $query['status']
			&& (string) $row['status'] !== (string) $query['status'] ) {
			return false;
		}

		if ( ! empty( $query['hide_test'] ) && $row['is_test'] ) {
			return false;
		}

		$created = substr( (string) $row['created_at'], 0, 10 );

		if ( isset( $query['from'] ) && $created < $query['from'] ) {
			return false;
		}

		if ( isset( $query['to'] ) && $created > $query['to'] ) {
			return false;
		}

		return true;
	}

	/**
	 * The page size the route should use for a requested value.
	 *
	 * An absent, zero or non-numeric value is not a page size at all and takes
	 * the documented default; a negative one becomes its magnitude, because the
	 * route sanitises the parameter with `absint` before anything else sees it;
	 * anything above the cap is capped (Requirement 12.10).
	 *
	 * @param mixed $requested Requested `per_page` value, or null when absent.
	 * @return int
	 */
	private static function effective_per_page( $requested ) {
		if ( null === $requested ) {
			return self::PER_PAGE_DEFAULT;
		}

		$size = abs( (int) $requested );

		if ( $size < 1 ) {
			return self::PER_PAGE_DEFAULT;
		}

		return min( self::PER_PAGE_MAX, $size );
	}

	/**
	 * The page number the route should use for a requested value.
	 *
	 * @param mixed $requested Requested `page` value.
	 * @return int
	 */
	private static function effective_page( $requested ) {
		return max( 1, abs( (int) $requested ) );
	}

	/* ---------------------------------------------------------------------
	 * The route
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch one list request through the real route.
	 *
	 * Every value goes out as a string, which is how a query string carries it.
	 * A `null` page size is not sent at all, so the route's own default applies.
	 *
	 * @param array $query    Supplied filters.
	 * @param mixed $per_page Requested page size, or null when absent.
	 * @param mixed $page     Requested page number.
	 * @return \WP_REST_Response
	 */
	private function dispatch( array $query, $per_page, $page ) {
		$request = new WP_REST_Request( 'GET', self::ROUTE );

		foreach ( $query as $key => $value ) {
			$request->set_param( $key, (string) $value );
		}

		if ( null !== $per_page ) {
			$request->set_param( 'per_page', (string) $per_page );
		}

		$request->set_param( 'page', (string) $page );

		return rest_do_request( $request );
	}

	/**
	 * The identifiers of the items a response carries, in the order given.
	 *
	 * @param \WP_REST_Response $response Response under test.
	 * @return int[]
	 */
	private static function ids( $response ) {
		$ids = array();

		foreach ( (array) $response->get_data()['items'] as $item ) {
			$ids[] = isset( $item['id'] ) ? (int) $item['id'] : 0;
		}

		return $ids;
	}

	/**
	 * The query parameters a generated filter case supplies, absent parameters
	 * genuinely absent rather than present and empty.
	 *
	 * `hide_test` false is a supplied parameter, not an absent one: it is the
	 * case that keeps test records in.
	 *
	 * @param array $filters Generated filter case.
	 * @return array<string,string>
	 */
	private static function query_params( array $filters ) {
		$query = array();

		if ( null !== $filters['status'] ) {
			$query['status'] = (string) $filters['status'];
		}

		if ( null !== $filters['hide_test'] ) {
			$query['hide_test'] = $filters['hide_test'] ? '1' : '0';
		}

		if ( null !== $filters['from_offset'] ) {
			$query['from'] = Generators::date_at( $filters['from_offset'] );
		}

		if ( null !== $filters['to_offset'] ) {
			$query['to'] = Generators::date_at( $filters['to_offset'] );
		}

		return $query;
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Write the generated population, and return it carrying the identifier the
	 * store gave each enquiry.
	 *
	 * Only the columns this property reads vary; the rest are fixed, so a
	 * difference in the returned order can only come from `created_at` and the
	 * identifier.
	 *
	 * @param array $rows Generated rows.
	 * @return array
	 */
	private function seed( array $rows ) {
		global $wpdb;

		$this->clear();

		$seeded = array();

		foreach ( $rows as $row ) {
			$created = Generators::date_at( $row['created_offset'] ) . ' ' . $row['created_time'];

			$inserted = $wpdb->insert(
				Schema::table( 'enquiries' ),
				array(
					'first_name'        => 'Ada',
					'last_name'         => 'Lovelace',
					'email'             => 'ada@example.com',
					'phone'             => '',
					'message'           => '',
					'status'            => $row['status'],
					'crm_sync_state'    => 'pending',
					'created_at'        => $created,
					'updated_at'        => $created,
					'status_changed_at' => $created,
					'source'            => 'webhook:fixture',
					'is_test'           => $row['is_test'] ? 1 : 0,
				)
			);

			$this->assertNotFalse( $inserted, 'Seeding an enquiry should succeed: ' . $wpdb->last_error );

			$row['id']         = (int) $wpdb->insert_id;
			$row['created_at'] = $created;
			$seeded[]          = $row;
		}

		return $seeded;
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

		foreach ( array( 'enquiries', 'dates', 'terms' ) as $key ) {
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
