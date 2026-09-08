<?php
/**
 * Guards the test harness itself, not the plugin.
 *
 * The shared generators are the foundation every property test stands on, so a
 * break here would otherwise surface as dozens of confusing unrelated failures.
 * In particular this pins the Eris API: 0.14 exposes generators as static
 * methods on `Eris\Generators`, where other versions expose namespaced functions
 * such as `Eris\Generator\choose()`. Writing against the wrong one fails at
 * every call site at once, so it is worth catching in a single obvious place.
 *
 * Being in the `pure` suite, it also proves the generators need no WordPress.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Tests\Generators;
use PHPUnit\Framework\TestCase;

class HarnessTest extends TestCase {

	use TestTrait;

	public function test_enquiry_generator_produces_valid_shapes() {
		$this->forAll( Generators::enquiry() )
			->then( function ( array $fields ) {
				$this->assertCount( 9, $fields );
				$this->assertGreaterThanOrEqual( 1, count( $fields['date_ranges'] ) );
				$this->assertLessThanOrEqual( 3, count( $fields['date_ranges'] ) );
				$this->assertGreaterThanOrEqual( 1, $fields['total_guests'] );
				$this->assertLessThanOrEqual( 10000, $fields['total_guests'] );
			} );
	}

	public function test_candidate_ranges_respect_bounds_and_are_distinct() {
		$this->forAll( Generators::candidate_ranges( 1, 3 ) )
			->then( function ( array $ranges ) {
				$keys = array();

				foreach ( $ranges as $range ) {
					$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $range['start'] );
					$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $range['end'] );
					$this->assertLessThanOrEqual( $range['end'], $range['start'] );

					$keys[] = $range['start'] . '/' . $range['end'];
				}

				// Distinct, because the Validator collapses duplicates: a
				// generator emitting one twice would emit a shorter list than
				// the count it chose.
				$this->assertSame( $keys, array_values( array_unique( $keys ) ) );
			} );
	}

	public function test_term_sets_stay_within_capacity() {
		$this->forAll( Generators::term_set( 0, 20 ) )
			->then( function ( array $terms ) {
				$this->assertLessThanOrEqual( 20, count( $terms ) );
				foreach ( $terms as $term ) {
					$this->assertLessThanOrEqual( 100, strlen( $term ) );
				}
			} );
	}

	public function test_adversarial_strings_are_generated() {
		$this->forAll( Generators::adversarial_string() )
			->then( function ( $value ) {
				$this->assertIsString( $value );
			} );
	}
}
