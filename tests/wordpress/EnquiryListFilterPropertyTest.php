<?php
/**
 * Property 28: List filters match a reference implementation and combine
 * conjunctively.
 *
 * Feature: enquiry-data-layer, Property 28: For any population of enquiries and
 * any subset of the supported filter parameters (`status` including `all`,
 * search term, `from`, `to`, `date_from` with `date_to`, `hide_test`), the
 * identifiers returned by the list route equal those produced by a
 * straightforward reference filter over the same population applying every
 * supplied filter conjunctively, where search matches `first_name`,
 * `last_name`, `email`, `phone` or `message` case-insensitively and treats `%`
 * and `_` literally, `from` and `to` bound `created_at` inclusively, and the
 * candidate-date range matches an enquiry holding at least one date inside it
 * inclusively; and when exactly one of `date_from` and `date_to` is supplied,
 * no candidate-date filter is applied and the response warns naming the missing
 * parameter.
 *
 * **Validates: Requirements 12.2, 12.3, 12.4, 12.5, 12.6, 12.7, 12.8, 12.12, 12.15, 17.5**
 *
 * Four things about how the property is instantiated are worth stating plainly:
 *
 * - `EnquiryQuery` is pure, but the claim being tested is about *which rows come
 *   back*, and only MySQL can answer that: `LIKE` semantics, the collation's
 *   case folding and `EXISTS` over the candidate-date table all live in the
 *   database. So this test composes the fragments the way `EnquiryStore` will —
 *   `SELECT e.id FROM {enquiries} e WHERE {where} {order} {limit}`, bindings
 *   through `$wpdb->prepare()`, the dates-table token substituted — and runs
 *   them against real tables. The list route does not exist yet; the SQL the
 *   route will issue does.
 * - The reference filter is a plain `foreach` over the seeded population,
 *   written for clarity and sharing no code and no constant with the builder. It
 *   re-states the acceptance criteria in PHP rather than re-deriving them. Every
 *   supplied filter is compared against it on its own as well as in combination,
 *   a singleton being a subset like any other: a boundary error in one filter is
 *   otherwise easy to hide behind a second filter that excludes the same row.
 * - Searchable field values are confined to ASCII. The columns collate
 *   `..._ci`, which is accent-insensitive, so MySQL matches `Sea` against
 *   `Séaghdha` where a PHP `stripos()` oracle does not — a divergence about the
 *   oracle, not about the filter. Unicode fidelity of stored values is Property
 *   1's subject. Case folding, adversarial tokens, `%`, `_` and backslashes are
 *   all still quantified over here, and those are what this property is about.
 * - `per_page` is left at its default 25 while the population tops out at 5, so
 *   the page never truncates the result set and set equality is a fair
 *   comparison. `per_page` bounds belong to Requirement 12.10, not here.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\EnquiryQuery;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class EnquiryListFilterPropertyTest
 */
class EnquiryListFilterPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehq_';

	/**
	 * Columns a search term is matched against (Requirement 12.4).
	 *
	 * Written out rather than read from `EnquiryQuery`, so a column quietly
	 * dropped from the builder's list is a failure here.
	 *
	 * @var string[]
	 */
	const SEARCH_COLUMNS = array( 'first_name', 'last_name', 'email', 'phone', 'message' );

	/**
	 * Search terms that match nothing in any generated population.
	 *
	 * @var string[]
	 */
	const UNMATCHABLE_TERMS = array( 'zzq-no-such-enquiry' );

	/**
	 * Wildcard and SQL-significant terms every population is searched for.
	 *
	 * `%` and `_` are here because they must match literally rather than
	 * wildcarding (Requirement 12.4, Property 28), and a lone `%` would
	 * otherwise match every row.
	 *
	 * @var string[]
	 */
	const LITERAL_TERMS = array( '%', '_', '%s', '%d', '\\', "'", '--', ';', '100%' );

	/**
	 * Times of day seeded rows are created at, including both day boundaries.
	 *
	 * @var string[]
	 */
	const CREATED_TIMES = array( '00:00:00', '09:30:00', '23:59:59' );

	/**
	 * Which of a bound pair a case supplies.
	 *
	 * `from` and `to` alone are the lone-bound cases; for the candidate-date
	 * pair they are the ones Requirement 12.8 is about.
	 *
	 * @var string[]
	 */
	const SUPPLY_MODES = array( 'both', 'from', 'to', 'none' );

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Load the classes under test.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-query.php';
	}

	public function set_up() {
		parent::set_up();

		global $wpdb;

		/*
		 * The WordPress test case rewrites CREATE TABLE into its TEMPORARY form,
		 * and `Schema::install()` verifies each table through `SHOW TABLES`,
		 * which cannot see a temporary table. This test therefore works against
		 * real tables at its own prefix and cleans them up itself.
		 */
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );
	}

	public function tear_down() {
		global $wpdb;

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 28: List filters match a reference
	 * implementation and combine conjunctively.
	 *
	 * **Validates: Requirements 12.2, 12.3, 12.4, 12.5, 12.6, 12.7, 12.8, 12.12, 12.15, 17.5**
	 *
	 * @eris-shrink 10
	 */
	public function test_list_filters_match_a_reference_implementation() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::scenario() )
			->then(
				function ( array $case ) {
					$population = $this->seed( $case['population'] );
					$args       = self::args( $case['filters'] );

					$built  = EnquiryQuery::build( $args, array( 'dates' => Schema::table( 'dates' ) ) );
					$actual = $this->matching_ids( $args );

					// The whole filter set, against the reference.
					$this->assertSame(
						self::reference( $population, $args ),
						$actual,
						'The filtered identifiers should equal the reference filter over the same population. Args: '
							. wp_json_encode( $args )
					);

					// Each filter alone is also a subset of the supported
					// parameters, and the whole set is exactly the intersection of
					// those singletons (Requirement 12.12).
					$this->assert_conjunctive( $population, $args, $actual );

					// A lone candidate-date bound applies no filter and warns
					// naming the parameter that is missing (Requirement 12.8).
					$this->assert_lone_bound_warning( $args, $built, $actual );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * A population of enquiries, and a subset of the filters to read it with.
	 *
	 * The filters are drawn after the population so a search term can be a
	 * substring of a value that was actually seeded; a term generated
	 * independently of the population would almost never match anything, and the
	 * property would pass on empty result sets.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::bind(
			self::population(),
			function ( array $population ) {
				return \Eris\Generators::map(
					function ( array $filters ) use ( $population ) {
						return array(
							'population' => $population,
							'filters'    => $filters,
						);
					},
					self::filters( $population )
				);
			}
		);
	}

	/**
	 * Between zero and five enquiries. The empty population is a boundary the
	 * list route has to answer as an empty list rather than as a failure.
	 *
	 * @return \Eris\Generator
	 */
	protected static function population() {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( 0, 5 ),
			function ( $count ) {
				if ( $count < 1 ) {
					return \Eris\Generators::constant( array() );
				}

				return \Eris\Generators::vector( (int) $count, self::enquiry() );
			}
		);
	}

	/**
	 * One enquiry: the five searchable columns, a status, a creation time, the
	 * test flag, and zero to three candidate dates.
	 *
	 * Zero candidate dates is a valid state and the interesting one for the
	 * candidate-date filter, since such an enquiry can never satisfy it.
	 *
	 * @return \Eris\Generator
	 */
	protected static function enquiry() {
		return \Eris\Generators::associative(
			array(
				'first_name'     => self::ascii( Generators::first_name() ),
				'last_name'      => self::ascii( Generators::last_name() ),
				'email'          => self::ascii( Generators::email() ),
				'phone'          => self::ascii( Generators::phone_or_empty() ),
				'message'        => self::ascii( Generators::message_or_empty() ),
				'status'         => \Eris\Generators::elements( EnquiryQuery::statuses() ),
				'created_offset' => \Eris\Generators::choose( 2, 30 ),
				'created_time'   => \Eris\Generators::elements( self::CREATED_TIMES ),
				'is_test'        => \Eris\Generators::elements( array( true, false ) ),
				'dates'          => Generators::candidate_dates( 0, 3 ),
			)
		);
	}

	/**
	 * A subset of the supported filter parameters.
	 *
	 * `null` means the parameter is not supplied at all, and is stripped when the
	 * argument array is assembled, so every subset of the seven parameters is
	 * reachable. `hide_test` false is a supplied parameter, not an absent one:
	 * Requirements 12.15 and 17.5 make it the case that keeps test records in.
	 *
	 * @param array $population Seeded population, for the search terms.
	 * @return \Eris\Generator
	 */
	protected static function filters( array $population ) {
		return \Eris\Generators::associative(
			array(
				// `all` and every recognised status, drawn from the lifecycle.
				'status'    => \Eris\Generators::elements(
					array_merge( array( null, EnquiryQuery::STATUS_ALL ), EnquiryQuery::statuses() )
				),
				's'         => \Eris\Generators::elements(
					array_merge( array( null ), self::search_terms( $population ) )
				),
				'created'   => self::bounds( self::created_pool( $population ), 32 ),
				'dates'     => self::bounds( self::candidate_pool( $population ), 40 ),
				'hide_test' => \Eris\Generators::elements( array( null, true, false ) ),
			)
		);
	}

	/**
	 * A bound pair drawn from a pool of dates the population actually holds: the
	 * lower bound, the range shape, and which of the two bounds is supplied.
	 *
	 * The bounds are drawn from the data rather than from a wide independent
	 * span for two reasons. A pair of independently drawn bounds mostly describes
	 * a range holding nothing, so every case would pass on an empty result set.
	 * And "inclusive" is only observable when a bound lands *exactly* on a date a
	 * row holds, which an independent draw almost never does — the pool is built
	 * from each seeded date and its two neighbours, so the exact-hit and
	 * just-missed cases are both routine. A `point` range collapses both bounds
	 * onto that one date, which is the case that fails if either comparison is
	 * made exclusive.
	 *
	 * @param string[] $pool  Candidate bound dates, `Y-m-d`.
	 * @param int      $width Widest span range, in days.
	 * @return \Eris\Generator
	 */
	protected static function bounds( array $pool, $width ) {
		return \Eris\Generators::associative(
			array(
				'lower'  => \Eris\Generators::elements( $pool ),
				'width'  => \Eris\Generators::choose( 0, (int) $width ),
				'shape'  => \Eris\Generators::elements( array( 'point', 'span' ) ),
				'supply' => \Eris\Generators::elements( self::SUPPLY_MODES ),
			)
		);
	}

	/**
	 * Bound dates worth applying to `created_at`: every seeded creation date and
	 * its two neighbours.
	 *
	 * @param array $population Generated population.
	 * @return string[]
	 */
	protected static function created_pool( array $population ) {
		$dates = array();

		foreach ( $population as $enquiry ) {
			$dates[] = Generators::date_at( $enquiry['created_offset'] );
		}

		return self::pool( $dates, Generators::date_at( 15 ) );
	}

	/**
	 * Bound dates worth applying to the candidate dates: every seeded candidate
	 * date and its two neighbours.
	 *
	 * @param array $population Generated population.
	 * @return string[]
	 */
	protected static function candidate_pool( array $population ) {
		$dates = array();

		foreach ( $population as $enquiry ) {
			foreach ( $enquiry['dates'] as $date ) {
				$dates[] = $date;
			}
		}

		return self::pool( $dates, Generators::date_at( 40 ) );
	}

	/**
	 * Each date plus the day either side of it, deduplicated.
	 *
	 * @param string[] $dates    Dates the population holds.
	 * @param string   $fallback Date to use when the population holds none.
	 * @return string[]
	 */
	protected static function pool( array $dates, $fallback ) {
		$pool = array();

		foreach ( $dates as $date ) {
			// Exact dates are the ones inclusivity is observable on, so they are
			// deliberately the likeliest draw.
			$pool[] = $date;
			$pool[] = $date;
			$pool[] = self::shift( $date, -1 );
			$pool[] = self::shift( $date, 1 );
		}

		if ( ! $pool ) {
			$pool[] = $fallback;
		}

		// Duplicates are weights, not accidents, so the pool is not deduplicated.
		return array_values( $pool );
	}

	/**
	 * A date this many days from another.
	 *
	 * @param string $date Date, `Y-m-d`.
	 * @param int    $days Day offset, positive or negative.
	 * @return string `Y-m-d`.
	 */
	protected static function shift( $date, $days ) {
		$moved = new \DateTimeImmutable( (string) $date, new \DateTimeZone( 'UTC' ) );

		return $moved->modify( sprintf( '%+d days', (int) $days ) )->format( 'Y-m-d' );
	}

	/**
	 * The search terms a population is worth being searched for.
	 *
	 * Substrings of values that were actually seeded, an upper-cased substring
	 * so the case-insensitivity clause is exercised rather than assumed, the
	 * wildcard and SQL-significant literals, and one term that matches nothing.
	 *
	 * Every term is trimmed and non-empty, so normalisation leaves it alone and
	 * the reference and the query are searching for the same string.
	 *
	 * @param array $population Seeded population.
	 * @return string[]
	 */
	protected static function search_terms( array $population ) {
		$terms = array_merge( self::LITERAL_TERMS, self::UNMATCHABLE_TERMS );

		foreach ( $population as $enquiry ) {
			foreach ( self::SEARCH_COLUMNS as $column ) {
				$value = (string) $enquiry[ $column ];

				if ( '' === $value ) {
					continue;
				}

				$terms[] = substr( $value, 0, 3 );
				$terms[] = strtoupper( substr( $value, 0, 4 ) );
				$terms[] = substr( $value, 1, 5 );
			}
		}

		$terms = array_map( 'trim', $terms );
		$terms = array_filter(
			$terms,
			function ( $term ) {
				return '' !== $term;
			}
		);

		return array_values( array_unique( $terms ) );
	}

	/**
	 * Confine a generated string to printable ASCII.
	 *
	 * The columns collate `..._ci`, which folds accents as well as case, so
	 * MySQL considers `Sea` and `Séa` the same string while a PHP `stripos()`
	 * oracle does not. Keeping searchable values ASCII keeps the oracle
	 * faithful; a value that stripped down to nothing becomes `Ada` rather than
	 * silently turning into the empty string, which would mean "not supplied".
	 *
	 * @param \Eris\Generator $generator Source generator.
	 * @return \Eris\Generator
	 */
	protected static function ascii( \Eris\Generator $generator ) {
		return \Eris\Generators::map(
			function ( $value ) {
				$value    = (string) $value;
				$stripped = (string) preg_replace( '/[^\x20-\x7E]/', '', $value );

				if ( '' === $stripped && '' !== $value ) {
					return 'Ada';
				}

				return $stripped;
			},
			$generator
		);
	}

	/* ---------------------------------------------------------------------
	 * Assertions
	 * ------------------------------------------------------------------ */

	/**
	 * Each supplied filter on its own matches the reference, and the whole set is
	 * exactly the intersection of those singletons (Requirement 12.12).
	 *
	 * The single-filter comparisons are what make each criterion observable: a
	 * boundary error in one filter is otherwise easy to hide, because a second
	 * filter that excludes the same row makes both sides agree for the wrong
	 * reason.
	 *
	 * The candidate-date pair counts as one filter, because a single bound is by
	 * definition no filter at all (Requirement 12.8).
	 *
	 * @param array $population Seeded population.
	 * @param array $args       Supplied arguments.
	 * @param array $actual     Identifiers the whole filter set returned.
	 * @return void
	 */
	private function assert_conjunctive( array $population, array $args, array $actual ) {
		$groups       = self::filter_groups( $args );
		$intersection = null;

		foreach ( $groups as $group ) {
			$ids = $this->matching_ids( $group );

			$this->assertSame(
				self::reference( $population, $group ),
				$ids,
				'One filter on its own should equal the reference filter. Args: ' . wp_json_encode( $group )
			);

			$intersection = null === $intersection
				? $ids
				: array_values( array_intersect( $intersection, $ids ) );
		}

		if ( count( $groups ) < 2 ) {
			return;
		}

		sort( $intersection, SORT_NUMERIC );

		$this->assertSame(
			$intersection,
			$actual,
			'Combined filters should return the intersection of the same filters applied singly. Args: '
				. wp_json_encode( $args )
		);
	}

	/**
	 * A lone `date_from` or `date_to` applies no candidate-date filter and warns
	 * naming the missing parameter (Requirement 12.8).
	 *
	 * @param array $args   Supplied arguments.
	 * @param array $built  What the builder returned for those arguments.
	 * @param array $actual Identifiers the whole filter set returned.
	 * @return void
	 */
	private function assert_lone_bound_warning( array $args, array $built, array $actual ) {
		$has_from = array_key_exists( 'date_from', $args );
		$has_to   = array_key_exists( 'date_to', $args );

		if ( $has_from === $has_to ) {
			$this->assertSame(
				array(),
				$built['warnings'],
				'A complete or absent candidate-date range should produce no warning. Args: ' . wp_json_encode( $args )
			);

			return;
		}

		$missing  = $has_from ? 'date_to' : 'date_from';
		$supplied = $has_from ? 'date_from' : 'date_to';

		$this->assertCount( 1, $built['warnings'], 'A lone candidate-date bound should produce one warning.' );
		$this->assertStringContainsString(
			$missing,
			$built['warnings'][0],
			'The warning should name the missing parameter.'
		);

		// No candidate-date filter applied: the same rows come back as when the
		// lone bound is not supplied at all.
		$without = $args;
		unset( $without[ $supplied ] );

		$this->assertSame(
			$this->matching_ids( $without ),
			$actual,
			'A lone candidate-date bound should filter nothing. Args: ' . wp_json_encode( $args )
		);
	}

	/* ---------------------------------------------------------------------
	 * The reference implementation
	 * ------------------------------------------------------------------ */

	/**
	 * The identifiers the supplied filters should return, by plain PHP filtering
	 * of the seeded population.
	 *
	 * Shares no code and no constant with `EnquiryQuery`: each clause below
	 * re-states one acceptance criterion directly.
	 *
	 * @param array $population Seeded population, each entry carrying its `id`.
	 * @param array $args       Supplied arguments.
	 * @return int[] Ascending identifiers.
	 */
	private static function reference( array $population, array $args ) {
		$ids = array();

		foreach ( $population as $enquiry ) {
			if ( self::reference_keeps( $enquiry, $args ) ) {
				$ids[] = (int) $enquiry['id'];
			}
		}

		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Whether one enquiry satisfies every supplied filter.
	 *
	 * @param array $enquiry Seeded enquiry.
	 * @param array $args    Supplied arguments.
	 * @return bool
	 */
	private static function reference_keeps( array $enquiry, array $args ) {
		// Requirements 12.2, 12.3: a recognised status filters; `all` does not.
		if ( isset( $args['status'] ) && 'all' !== $args['status'] && $enquiry['status'] !== $args['status'] ) {
			return false;
		}

		// Requirement 12.4: case-insensitive substring match over five columns,
		// `%` and `_` meaning themselves.
		if ( isset( $args['s'] ) && ! self::reference_matches_term( $enquiry, (string) $args['s'] ) ) {
			return false;
		}

		// Requirements 12.5, 12.6: `created_at` bounded inclusively, by date.
		$created = substr( (string) $enquiry['created_at'], 0, 10 );

		if ( isset( $args['from'] ) && $created < $args['from'] ) {
			return false;
		}

		if ( isset( $args['to'] ) && $created > $args['to'] ) {
			return false;
		}

		// Requirements 12.7, 12.8: at least one candidate date inside the range,
		// and only when both bounds were supplied.
		if ( isset( $args['date_from'], $args['date_to'] )
			&& ! self::reference_has_date_within( $enquiry, $args['date_from'], $args['date_to'] ) ) {
			return false;
		}

		// Requirements 12.15, 17.5: test records are in unless excluded.
		if ( ! empty( $args['hide_test'] ) && $enquiry['is_test'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether any searchable column of an enquiry contains the term, ignoring
	 * case and treating every character of the term literally.
	 *
	 * @param array  $enquiry Seeded enquiry.
	 * @param string $term    Search term.
	 * @return bool
	 */
	private static function reference_matches_term( array $enquiry, $term ) {
		foreach ( self::SEARCH_COLUMNS as $column ) {
			if ( false !== stripos( (string) $enquiry[ $column ], $term ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an enquiry holds at least one candidate date inside the range,
	 * both bounds inclusive.
	 *
	 * @param array  $enquiry Seeded enquiry.
	 * @param string $from    Range start, `Y-m-d`.
	 * @param string $to      Range end, `Y-m-d`.
	 * @return bool
	 */
	private static function reference_has_date_within( array $enquiry, $from, $to ) {
		foreach ( $enquiry['dates'] as $date ) {
			if ( $date >= $from && $date <= $to ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Arguments
	 * ------------------------------------------------------------------ */

	/**
	 * The argument array a generated filter case supplies, absent parameters
	 * genuinely absent rather than present and empty.
	 *
	 * @param array $filters Generated filter case.
	 * @return array
	 */
	private static function args( array $filters ) {
		$args = array();

		if ( null !== $filters['status'] ) {
			$args['status'] = $filters['status'];
		}

		if ( null !== $filters['s'] ) {
			$args['s'] = $filters['s'];
		}

		foreach ( self::bound_values( $filters['created'], 'from', 'to' ) as $key => $value ) {
			$args[ $key ] = $value;
		}

		foreach ( self::bound_values( $filters['dates'], 'date_from', 'date_to' ) as $key => $value ) {
			$args[ $key ] = $value;
		}

		if ( null !== $filters['hide_test'] ) {
			$args['hide_test'] = $filters['hide_test'];
		}

		return $args;
	}

	/**
	 * The bounds of one pair that the case supplies, keyed by parameter name.
	 *
	 * @param array  $bounds Generated bound pair.
	 * @param string $lower  Parameter name of the lower bound.
	 * @param string $upper  Parameter name of the upper bound.
	 * @return array<string,string>
	 */
	private static function bound_values( array $bounds, $lower, $upper ) {
		$supplied = array();
		$mode     = $bounds['supply'];
		$start    = (string) $bounds['lower'];
		$end      = 'point' === $bounds['shape'] ? $start : self::shift( $start, $bounds['width'] );

		if ( in_array( $mode, array( 'both', 'from' ), true ) ) {
			$supplied[ $lower ] = $start;
		}

		if ( in_array( $mode, array( 'both', 'to' ), true ) ) {
			$supplied[ $upper ] = $end;
		}

		return $supplied;
	}

	/**
	 * The supplied filters, one argument array per filter, the candidate-date
	 * pair kept together.
	 *
	 * @param array $args Supplied arguments.
	 * @return array<int,array>
	 */
	private static function filter_groups( array $args ) {
		$groups = array();
		$pair   = array();

		foreach ( $args as $key => $value ) {
			if ( 'date_from' === $key || 'date_to' === $key ) {
				$pair[ $key ] = $value;
				continue;
			}

			$groups[] = array( $key => $value );
		}

		if ( $pair ) {
			$groups[] = $pair;
		}

		return $groups;
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Run the list query the route will run, and return the identifiers.
	 *
	 * This is the composition `EnquiryStore` owns: the builder's `where` with its
	 * bindings through `$wpdb->prepare()`, the enquiry table aliased as the
	 * builder expects, and the dates-table token already substituted.
	 *
	 * @param array $args Supplied arguments.
	 * @return int[] Identifiers, in the order the query returned them.
	 */
	private function matching_ids( array $args ) {
		global $wpdb;

		$built = EnquiryQuery::build( $args, array( 'dates' => Schema::table( 'dates' ) ) );
		$alias = EnquiryQuery::ALIAS;

		$sql = 'SELECT ' . $alias . '.id FROM ' . Schema::table( 'enquiries' ) . ' ' . $alias
			. ' WHERE ' . $built['where']
			. ' ' . $built['order']
			. ' ' . $built['limit'];

		if ( $built['bindings'] ) {
			$sql = $wpdb->prepare( $sql, $built['bindings'] ); // phpcs:ignore WordPress.DB
		}

		$wpdb->last_error = '';
		$ids              = (array) $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB

		$this->assertSame(
			'',
			(string) $wpdb->last_error,
			'The composed list query should run without error. Args: ' . wp_json_encode( $args )
		);

		$ids = array_map( 'intval', $ids );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Write the generated population, and return it carrying the identifiers the
	 * store gave each enquiry.
	 *
	 * @param array $population Generated population.
	 * @return array
	 */
	private function seed( array $population ) {
		global $wpdb;

		$this->clear();

		$seeded = array();

		foreach ( $population as $enquiry ) {
			$created = Generators::date_at( $enquiry['created_offset'] ) . ' ' . $enquiry['created_time'];

			$inserted = $wpdb->insert(
				Schema::table( 'enquiries' ),
				array(
					'first_name'        => $enquiry['first_name'],
					'last_name'         => $enquiry['last_name'],
					'email'             => $enquiry['email'],
					'phone'             => $enquiry['phone'],
					'message'           => $enquiry['message'],
					'status'            => $enquiry['status'],
					'crm_sync_state'    => 'pending',
					'created_at'        => $created,
					'updated_at'        => $created,
					'status_changed_at' => $created,
					'source'            => 'webhook:fixture',
					'is_test'           => $enquiry['is_test'] ? 1 : 0,
				)
			);

			$this->assertNotFalse( $inserted, 'Seeding an enquiry should succeed: ' . $wpdb->last_error );

			$id = (int) $wpdb->insert_id;

			foreach ( $enquiry['dates'] as $date ) {
				$wpdb->insert(
					Schema::table( 'dates' ),
					array(
						'enquiry_id' => $id,
						'event_date' => $date,
					)
				);
			}

			$enquiry['id']         = $id;
			$enquiry['created_at'] = $created;
			$seeded[]              = $enquiry;
		}

		return $seeded;
	}

	/**
	 * Empty the two tables this property reads, between iterations.
	 *
	 * `DELETE` rather than `TRUNCATE`: the identifiers keep climbing, which is
	 * closer to a live table and means a stale identifier can never be mistaken
	 * for a fresh one.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array( 'enquiries', 'dates' ) as $key ) {
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
