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
				$this->assertGreaterThanOrEqual( 1, count( $fields['selected_dates'] ) );
				$this->assertLessThanOrEqual( 10, count( $fields['selected_dates'] ) );
				$this->assertGreaterThanOrEqual( 1, $fields['total_guests'] );
				$this->assertLessThanOrEqual( 10000, $fields['total_guests'] );
			} );
	}

	public function test_candidate_dates_respect_bounds_and_are_distinct() {
		$this->forAll( Generators::candidate_dates( 1, 10 ) )
			->then( function ( array $dates ) {
				$this->assertSame( $dates, array_values( array_unique( $dates ) ) );
				foreach ( $dates as $date ) {
					$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $date );
				}
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
