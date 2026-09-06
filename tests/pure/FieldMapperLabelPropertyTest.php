<?php
/**
 * Property 8 of the enquiry-data-layer design.
 *
 * FieldMapper is pure, so this test needs no WordPress and no database. Two
 * pieces of scaffolding are all it takes: `ABSPATH` so the guard at the top of
 * the class file does not exit, and FakeFilters so `meh_field_map` answers with
 * a known value. Pinning that option to the empty array is what makes "no
 * mapping is configured for this field" an explicit precondition of the property
 * rather than an accident of WordPress being absent.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\Generators as Gen;
use Eris\TestTrait;
use MarthrownEnquiryHub\FieldMapper;
use MarthrownEnquiryHub\Tests\Fakes\FakeFilters;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__ ) . '/fakes/FakeFilters.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-field-mapper.php';

class FieldMapperLabelPropertyTest extends TestCase {

	use TestTrait;

	/**
	 * The label styles a sending form might present a field name in.
	 *
	 * Each one is a re-casing, a re-separating, or both, of the enquiry field
	 * name itself. Nothing here renames a field: that would be a mapping
	 * control's job, not label matching's.
	 *
	 * @var array
	 */
	const STYLES = array(
		'snake',
		'upper_snake',
		'title_snake',
		'title_space',
		'lower_space',
		'upper_space',
		'kebab',
		'title_kebab',
		'camel',
		'pascal',
		'dotted',
		'padded_space',
	);

	/**
	 * Payload keys a real webhook body carries alongside the enquiry fields.
	 *
	 * None of them canonicalises to any of the nine enquiry field names, which
	 * the test asserts rather than assumes, so a resolution landing on one of
	 * these is a failure the property will report.
	 *
	 * @var array
	 */
	const NOISE = array(
		'form_id',
		'formName',
		'_wpnonce',
		'submit',
		'entry_id',
		'page-url',
		'User Agent',
		'consent',
		'utm_source',
		'referrer',
	);

	/**
	 * Pin `meh_field_map` to the empty mapping: no control is set for any field.
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeFilters::install();
		FakeFilters::set( FieldMapper::OPTION, array() );
	}

	/**
	 * Leave no mapping behind for the next test.
	 */
	protected function tearDown(): void {
		FakeFilters::uninstall();

		parent::tearDown();
	}

	/**
	 * Feature: enquiry-data-layer, Property 8: Unmapped fields resolve by
	 * case-insensitive label. For any enquiry field and any submitted label
	 * produced by re-casing and re-separating that field's name (for example
	 * "First Name", "first name", "FIRST_NAME"), with no mapping configured for
	 * that field, resolution returns the value submitted under that label.
	 *
	 * **Validates: Requirements 2.10**
	 */
	public function test_unmapped_fields_resolve_by_case_insensitive_label() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll(
				Generators::enquiry(),
				self::label_styles(),
				Gen::subset( self::NOISE )
			)
			->disableShrinking()
			->then( function ( array $fields, array $styles, array $noise ) {
				// The precondition of the property: nothing is configured, so
				// every resolution below can only have come from the label.
				$this->assertSame( array(), FieldMapper::mapping() );

				$labels = array();

				foreach ( $fields as $field => $value ) {
					$labels[ self::relabel( $field, $styles[ $field ] ) ] = $value;
				}

				// Two fields relabelling to one key would silently drop a field
				// and make the assertions below cover eight fields, not nine.
				$this->assertCount( count( $fields ), $labels );

				$noise_map = self::noise_map( $noise );

				foreach ( array_keys( $noise_map ) as $key ) {
					$this->assertNotContains(
						self::canonical( $key ),
						array_map( array( self::class, 'canonical' ), FieldMapper::FIELDS ),
						'noise key ' . $key . ' names an enquiry field'
					);
				}

				// Submission order must not decide anything, so the noise is
				// tried both ahead of the labels and behind them.
				$orderings = array(
					'noise first' => array_merge( $noise_map, $labels ),
					'noise last'  => array_merge( $labels, $noise_map ),
				);

				foreach ( $orderings as $ordering => $submitted ) {
					foreach ( $fields as $field => $value ) {
						$label = self::relabel( $field, $styles[ $field ] );

						$this->assertSame(
							$value,
							FieldMapper::resolve( $submitted, $field ),
							$field . ' submitted as "' . $label . '" (' . $ordering . ')'
						);
					}
				}
			} );
	}

	/**
	 * One label style per enquiry field.
	 *
	 * @return \Eris\Generator
	 */
	protected static function label_styles() {
		$spec = array();

		foreach ( FieldMapper::FIELDS as $field ) {
			$spec[ $field ] = Gen::elements( self::STYLES );
		}

		return Gen::associative( $spec );
	}

	/**
	 * An enquiry field name re-cased and re-separated in a given style.
	 *
	 * @param string $field Enquiry field name, e.g. 'first_name'.
	 * @param string $style One of STYLES.
	 * @return string
	 */
	protected static function relabel( $field, $style ) {
		$words  = explode( '_', (string) $field );
		$titled = array_map( 'ucfirst', $words );

		$labels = array(
			'snake'        => implode( '_', $words ),
			'upper_snake'  => strtoupper( implode( '_', $words ) ),
			'title_snake'  => implode( '_', $titled ),
			'title_space'  => implode( ' ', $titled ),
			'lower_space'  => implode( ' ', $words ),
			'upper_space'  => strtoupper( implode( ' ', $words ) ),
			'kebab'        => implode( '-', $words ),
			'title_kebab'  => implode( '-', $titled ),
			'camel'        => lcfirst( implode( '', $titled ) ),
			'pascal'       => implode( '', $titled ),
			'dotted'       => implode( '.', $words ),
			'padded_space' => '  ' . implode( '   ', $titled ) . " \t",
		);

		if ( ! isset( $labels[ $style ] ) ) {
			throw new \InvalidArgumentException( 'unknown label style: ' . $style );
		}

		return $labels[ $style ];
	}

	/**
	 * The noise keys as a submitted map, each holding a distinctive value.
	 *
	 * The values are what makes a wrong resolution visible: resolving a field to
	 * a noise key reports that key's name rather than an equal-looking value.
	 *
	 * @param array $noise Noise keys drawn for this case.
	 * @return array
	 */
	protected static function noise_map( array $noise ) {
		$map = array();

		foreach ( $noise as $key ) {
			$map[ $key ] = 'noise:' . $key;
		}

		return $map;
	}

	/**
	 * Canonical form of a key, mirroring the class under test.
	 *
	 * Repeated here rather than reached into, so a change to the class's own
	 * canonicalisation cannot quietly move this test's guard with it.
	 *
	 * @param string $key Key or label.
	 * @return string
	 */
	protected static function canonical( $key ) {
		return (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $key ) );
	}
}
