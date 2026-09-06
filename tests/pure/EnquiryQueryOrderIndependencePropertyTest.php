<?php
/**
 * Property 29 of the enquiry-data-layer design: filter parameter order does not
 * matter.
 *
 * `EnquiryQuery` touches no `$wpdb`, reads no option and calls no WordPress
 * function, so this belongs in the `pure` suite. The class file guards itself on
 * ABSPATH, which is defined below purely so the file can be loaded.
 *
 * The list route's answer — the identifiers it returns, their order, the totals
 * in its headers — is a function of the SQL fragments and bindings this class
 * produces and of nothing else. So at this level the property reads: any two
 * argument arrays holding the same parameter names and values in any key order
 * normalise to identical arrays and build identical `where`, `bindings`,
 * `order`, `limit` and `warnings`. Identical `where` plus identical `bindings`
 * fixes the matching set, identical `order` fixes the sequence, and identical
 * `limit` fixes the page and therefore the counts derived from it.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\Generators as Gen;
use Eris\TestTrait;
use MarthrownEnquiryHub\EnquiryQuery;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/includes/class-enquiry-query.php';

class EnquiryQueryOrderIndependencePropertyTest extends TestCase {

	use TestTrait;

	/**
	 * Every supported filter parameter name.
	 *
	 * Listed here rather than read from `EnquiryQuery::DEFAULTS` so a parameter
	 * quietly dropped from the class still gets supplied by this test.
	 *
	 * @var array
	 */
	const PARAMETERS = array(
		'status',
		's',
		'from',
		'to',
		'date_from',
		'date_to',
		'hide_test',
		'page',
		'per_page',
		'orderby',
		'order',
	);

	/**
	 * Feature: enquiry-data-layer, Property 29: Filter parameter order does not
	 * matter. For any filter parameter set, two requests supplying the same
	 * parameter names and values in any order return the same enquiry identifiers
	 * in the same order, the same counts and the same headers.
	 *
	 * **Validates: Requirements 12.13**
	 */
	public function test_filter_parameter_order_does_not_matter() {
		$this->limitTo( Iterations::count( 200 ) )
			->forAll(
				self::filter_args(),
				// One rank per possible parameter; the ranks reorder whichever
				// keys the argument set happens to hold.
				Gen::vector( count( self::PARAMETERS ), Gen::choose( 0, 999 ) )
			)
			->then( function ( array $args, array $ranks ) {
				$permuted = self::reorder( $args, $ranks );

				// The precondition: same names, same values, different order.
				$this->assertSame(
					self::sorted( $args ),
					self::sorted( $permuted ),
					'the reordering must change key order and nothing else'
				);

				$normalised = EnquiryQuery::normalise( $args );
				$reordered  = EnquiryQuery::normalise( $permuted );

				// assertSame over arrays compares key order as well as values,
				// which is the whole point: the two sets normalise to one array.
				$this->assertSame(
					$normalised,
					$reordered,
					'normalise() must not depend on key order: '
					. self::describe( array_keys( $args ) ) . ' vs '
					. self::describe( array_keys( $permuted ) )
				);

				// Canonical order, not merely a shared one: whichever order came
				// in, the answer comes out sorted by key.
				$keys = array_keys( $normalised );
				$this->assertSame(
					self::canonical_keys( $keys ),
					$keys,
					'normalised keys must be in canonical order'
				);

				$built    = EnquiryQuery::build( $args );
				$rebuilt  = EnquiryQuery::build( $permuted );

				// The matching set: same conditions, same values bound to them in
				// the same sequence.
				$this->assertSame(
					$built['where'],
					$rebuilt['where'],
					'the WHERE fragment must not depend on key order'
				);
				$this->assertSame(
					$built['bindings'],
					$rebuilt['bindings'],
					'the bindings must not depend on key order'
				);

				// The sequence, the page, and the warnings the response carries.
				$this->assertSame( $built['order'], $rebuilt['order'] );
				$this->assertSame( $built['limit'], $rebuilt['limit'] );
				$this->assertSame( $built['warnings'], $rebuilt['warnings'] );
			} );
	}

	/**
	 * A filter argument set: any subset of the supported parameters, each holding
	 * a value the route could genuinely receive.
	 *
	 * @return \Eris\Generator
	 */
	protected static function filter_args() {
		$generators = self::value_generators();

		return Gen::bind(
			Gen::subset( self::PARAMETERS ),
			function ( array $keys ) use ( $generators ) {
				$spec = array();

				foreach ( $keys as $key ) {
					$spec[ $key ] = $generators[ $key ];
				}

				return $spec ? Gen::associative( $spec ) : Gen::constant( array() );
			}
		);
	}

	/**
	 * One value generator per supported parameter.
	 *
	 * Each covers the shapes a query string actually delivers — text booleans,
	 * numeric strings, out-of-range page sizes, unrecognised statuses and sort
	 * columns, unparseable dates — because a value that normalises to a default
	 * is exactly where an order dependency would hide.
	 *
	 * @return array parameter => generator.
	 */
	protected static function value_generators() {
		return array(
			'status'    => Gen::elements(
				array_merge(
					EnquiryQuery::STATUSES,
					array( EnquiryQuery::STATUS_ALL, 'NEW', ' quoted ', 'not-a-status', '' )
				)
			),
			's'         => Gen::oneOf(
				Generators::adversarial_string(),
				Gen::constant( '' ),
				Gen::constant( '  Ada  ' ),
				Gen::constant( "o'brien@example.com" )
			),
			'from'      => self::date_arg(),
			'to'        => self::date_arg(),
			'date_from' => self::date_arg(),
			'date_to'   => self::date_arg(),
			'hide_test' => Gen::elements( array( true, false, 1, 0, '1', '0', 'true', 'yes', 'on', 'no', '' ) ),
			'page'      => Gen::oneOf( Gen::choose( -3, 40 ), Gen::constant( '7' ), Gen::constant( 'x' ) ),
			'per_page'  => Gen::oneOf(
				Gen::choose( -5, 500 ),
				Gen::constant( 0 ),
				Gen::constant( '25' ),
				Gen::constant( EnquiryQuery::PER_PAGE_MAX ),
				Gen::constant( 'all' )
			),
			'orderby'   => Gen::elements(
				array_merge( EnquiryQuery::ORDERBY_COLUMNS, array( 'CREATED_AT', 'bogus', '' ) )
			),
			'order'     => Gen::elements( array( 'asc', 'desc', 'ASC', 'DESC', 'sideways', '' ) ),
		);
	}

	/**
	 * A date argument: a real date, a blank, or something that names no date.
	 *
	 * Every value is absolute, so two normalisations of the same argument set can
	 * never disagree because a relative date was resolved a second apart.
	 *
	 * @return \Eris\Generator
	 */
	protected static function date_arg() {
		return Gen::oneOf(
			Generators::candidate_date(),
			Gen::constant( '' ),
			Gen::constant( '2025-6-1' ),
			Gen::constant( '2025-06-01 14:30:00' ),
			Generators::unparseable_date()
		);
	}

	/**
	 * The same argument set with its keys in a different order.
	 *
	 * The order comes from the generated ranks, so it is part of the generated
	 * case and a failure is reproducible rather than depending on a shuffle.
	 *
	 * @param array $args  Argument set.
	 * @param array $ranks One rank per possible parameter.
	 * @return array
	 */
	protected static function reorder( array $args, array $ranks ) {
		$keys  = array_keys( $args );
		$ranks = array_values( $ranks );

		usort(
			$keys,
			function ( $left, $right ) use ( $ranks ) {
				$a = self::rank_of( $left, $ranks );
				$b = self::rank_of( $right, $ranks );

				return $a === $b ? strcmp( (string) $right, (string) $left ) : $a - $b;
			}
		);

		$reordered = array();

		foreach ( $keys as $key ) {
			$reordered[ $key ] = $args[ $key ];
		}

		return $reordered;
	}

	/**
	 * The rank generated for a parameter name.
	 *
	 * @param string $key   Parameter name.
	 * @param array  $ranks Generated ranks, positionally matching self::PARAMETERS.
	 * @return int
	 */
	protected static function rank_of( $key, array $ranks ) {
		$index = array_search( $key, self::PARAMETERS, true );

		return false === $index || ! isset( $ranks[ $index ] ) ? 0 : (int) $ranks[ $index ];
	}

	/**
	 * An argument set in a fixed key order, for comparing two sets as name/value
	 * pairs without regard to order.
	 *
	 * @param array $args Argument set.
	 * @return array
	 */
	protected static function sorted( array $args ) {
		ksort( $args );

		return $args;
	}

	/**
	 * The canonical order the normalised keys are expected to be in.
	 *
	 * @param array $keys Normalised keys.
	 * @return array
	 */
	protected static function canonical_keys( array $keys ) {
		sort( $keys );

		return $keys;
	}

	/**
	 * A readable form of a generated value, for a failure message.
	 *
	 * @param mixed $value Value to describe.
	 * @return string
	 */
	protected static function describe( $value ) {
		$json = json_encode( $value );

		return false === $json ? gettype( $value ) : $json;
	}
}
