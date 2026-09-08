<?php
/**
 * Property 10 of the enquiry-data-layer design.
 *
 * The Validator is pure, so this test needs no WordPress and no database. Two
 * pieces of scaffolding are all it takes: `ABSPATH` so the guard at the top of
 * the class file does not exit, and FakeFilters so the multi-select
 * vocabularies have a known universe. Without a vocabulary,
 * `Validator::allowed_terms()` returns nothing and treats the field as
 * unconstrained, which would leave the vocabulary half of the property vacuous.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\Generators as Gen;
use Eris\TestTrait;
use MarthrownEnquiryHub\Tests\Fakes\FakeFilters;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;
use MarthrownEnquiryHub\Validator;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__ ) . '/fakes/FakeFilters.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-enquiry-validator.php';

class ValidatorFieldRulesPropertyTest extends TestCase {

	use TestTrait;

	/**
	 * Install the fixture vocabularies the two multi-select rules are checked against.
	 */
	protected function setUp(): void {
		parent::setUp();

		FakeFilters::install();

		foreach ( Generators::VOCABULARIES as $taxonomy => $vocabulary ) {
			FakeFilters::set( 'meh_enquiry_terms_' . $taxonomy, $vocabulary );
		}
	}

	/**
	 * Leave no vocabulary behind for the next test.
	 */
	protected function tearDown(): void {
		FakeFilters::uninstall();

		parent::tearDown();
	}

	/**
	 * Feature: enquiry-data-layer, Property 10: Field rule violations name the
	 * offending field. For any otherwise-valid submission, any required-field
	 * profile, and any single rule violation drawn from {malformed email,
	 * `total_guests` outside 1–10000 or non-integer, fewer than 1 or more than 10
	 * candidate date ranges, an unreadable candidate date, a `phone` value containing
	 * no digits, an `event_type` or `site_exclusivity` value outside the
	 * permitted vocabulary} carried by a field that is present and non-empty
	 * after trimming, validation fails naming that field, identically under both
	 * profiles, and no enquiry is created or changed. Optionality under the
	 * Manual profile therefore governs presence only.
	 *
	 * **Validates: Requirements 3.3, 3.4, 3.5, 3.6, 3.11, 3.12, 3.17, 18.6**
	 */
	public function test_field_rule_violations_name_the_offending_field() {
		$this->limitTo( Iterations::count( 100 ) )
			// Shrinking a tuple of bound generators is expensive enough in Eris
			// to exhaust memory before it reports anything, so it gets a second.
			// The failure message carries the offending value either way.
			->shrinkingTimeLimit( 1 )
			->forAll(
				Generators::enquiry(),
				self::rule_violation(),
				Gen::elements( array( Validator::PROFILE_WEBHOOK, Validator::PROFILE_MANUAL ) )
			)
			->disableShrinking()
			->then( function ( array $fields, array $violation, $profile ) {
				$field     = $violation['field'];
				$submitted = array_merge( $fields, array( $field => $violation['value'] ) );

				// The property quantifies over violations carried by a field that
				// is present and non-empty after trimming. Anything else would be
				// a presence failure, which is Property 9's business.
				$this->assertArrayHasKey( $field, $submitted );
				$this->assertFalse(
					Validator::is_empty( $submitted[ $field ] ),
					$field . ' must be present and non-empty for this property to apply'
				);

				$result = Validator::validate( $submitted, $profile, Validator::MODE_FULL );

				$this->assertFalse(
					$result['ok'],
					'a rule violation must not be accepted: ' . $field . ' = '
					. self::describe( $violation['value'] ) . ' was accepted as '
					. self::describe( self::accepted( $result, $field ) )
				);
				$this->assertArrayHasKey(
					$field,
					$result['errors'],
					'the failure must name ' . $field . ': ' . self::describe( $violation['value'] )
				);

				// The submission was otherwise valid, so the offending field is
				// the only one that may be named.
				$this->assertSame(
					array( $field ),
					array_keys( $result['errors'] ),
					'only ' . $field . ' should fail: ' . self::describe( $result['errors'] )
				);

				// Nothing is accepted, so nothing downstream can be created or
				// changed from this submission.
				$this->assertSame( array(), $result['values'] );
				$this->assertSame( array(), $result['ranges'] );
				$this->assertSame( array(), $result['terms'] );

				// Identically under both profiles: optionality governs presence
				// only, never whether a value rule runs.
				$other = Validator::PROFILE_WEBHOOK === $profile
					? Validator::PROFILE_MANUAL
					: Validator::PROFILE_WEBHOOK;

				$under_other = Validator::validate( $submitted, $other, Validator::MODE_FULL );

				$this->assertSame(
					$result['errors'],
					$under_other['errors'],
					'the ' . $profile . ' and ' . $other . ' profiles must agree on a value rule'
				);
			} );
	}

	/**
	 * One rule violation: the field it is carried by, and the value carrying it.
	 *
	 * Every branch produces a value that is present and non-empty after
	 * trimming, so none of them can be mistaken for a presence failure.
	 *
	 * @return \Eris\Generator
	 */
	protected static function rule_violation() {
		return Gen::oneOf(
			// Requirement 3.3: malformed email.
			self::carried_by( 'email', Generators::invalid_email() ),
			// Requirement 3.4: outside 1–10000, and not a whole number.
			self::carried_by( 'total_guests', Generators::invalid_total_guests() ),
			self::carried_by( 'total_guests', Generators::non_integer_total_guests() ),
			// Requirement 3.11: no digits.
			self::carried_by( 'phone', Generators::digitless_phone() ),
			// Requirement 3.5: more than three candidate date ranges.
			self::carried_by( 'date_ranges', Generators::oversized_candidate_ranges() ),
			// Requirement 3.6: a bound that names no calendar date.
			self::carried_by( 'date_ranges', self::ranges_holding_an_unparseable_bound() ),
			// A range whose end precedes its start is not a range.
			self::carried_by( 'date_ranges', self::ranges_holding_a_reversed_range() ),
			// A range missing one of its two bounds is half-drawn.
			self::carried_by( 'date_ranges', self::ranges_holding_a_half_drawn_range() ),
			// Requirement 3.12: a value outside the field's vocabulary.
			self::carried_by( 'event_type', self::terms_holding_a_disallowed_value( 'event_type' ) ),
			self::carried_by( 'site_exclusivity', self::terms_holding_a_disallowed_value( 'site_exclusivity' ) )
		);
	}

	/**
	 * Tag a generated value with the field it violates a rule on.
	 *
	 * @param string           $field     Enquiry field name.
	 * @param \Eris\Generator  $generator Values violating a rule on that field.
	 * @return \Eris\Generator
	 */
	protected static function carried_by( $field, $generator ) {
		return Gen::map(
			function ( $value ) use ( $field ) {
				return array(
					'field' => $field,
					'value' => $value,
				);
			},
			$generator
		);
	}

	/**
	 * A candidate range list within the accepted count holding one range whose
	 * start names no calendar date.
	 *
	 * At most two valid ranges plus the bad one, so the count rule stays out of
	 * the way and the failure can only come from the bound itself.
	 *
	 * @return \Eris\Generator
	 */
	protected static function ranges_holding_an_unparseable_bound() {
		return Gen::bind(
			Generators::candidate_ranges( 1, Generators::RANGES_MAX - 1 ),
			function ( array $ranges ) {
				return Gen::map(
					function ( $bad ) use ( $ranges ) {
						return array_merge(
							$ranges,
							array(
								array(
									'start' => $bad,
									'end'   => $bad,
								),
							)
						);
					},
					Generators::unparseable_date()
				);
			}
		);
	}

	/**
	 * A candidate range list within the accepted count holding one range that
	 * ends before it starts.
	 *
	 * Both bounds are real dates, so the failure can only come from their order.
	 *
	 * @return \Eris\Generator
	 */
	protected static function ranges_holding_a_reversed_range() {
		return Gen::map(
			function ( array $ranges ) {
				$last = $ranges[ count( $ranges ) - 1 ];

				// The generator never emits a reversed range, so the last one is
				// turned round here: its own two bounds, the wrong way about.
				$ranges[ count( $ranges ) - 1 ] = array(
					'start' => $ranges[0]['end'],
					'end'   => $ranges[0]['start'],
				);

				// A single-day range cannot be reversed, so the day after its
				// start stands in for the end that is now too early.
				if ( $ranges[0]['start'] === $ranges[0]['end'] ) {
					$ranges[ count( $ranges ) - 1 ] = array(
						'start' => $last['end'],
						'end'   => $ranges[0]['start'],
					);
				}

				return $ranges;
			},
			Generators::candidate_ranges( 2, Generators::RANGES_MAX )
		);
	}

	/**
	 * A candidate range list within the accepted count holding one range with an
	 * end but no start.
	 *
	 * @return \Eris\Generator
	 */
	protected static function ranges_holding_a_half_drawn_range() {
		return Gen::map(
			function ( array $ranges ) {
				$last = count( $ranges ) - 1;

				$ranges[ $last ] = array(
					'start' => '',
					'end'   => $ranges[ $last ]['end'],
				);

				return $ranges;
			},
			Generators::candidate_ranges( 1, Generators::RANGES_MAX )
		);
	}

	/**
	 * A term set for a taxonomy holding one value outside its vocabulary.
	 *
	 * The permitted values alongside it are what makes this a value-rule failure
	 * rather than a whole set being wrong.
	 *
	 * @param string $taxonomy 'event_type' | 'site_exclusivity'.
	 * @return \Eris\Generator
	 */
	protected static function terms_holding_a_disallowed_value( $taxonomy ) {
		return Gen::bind(
			Generators::allowed_term_set( $taxonomy, 0 ),
			function ( array $terms ) use ( $taxonomy ) {
				return Gen::map(
					function ( $bad ) use ( $terms ) {
						return array_merge( $terms, array( $bad ) );
					},
					Generators::disallowed_term( $taxonomy )
				);
			}
		);
	}

	/**
	 * Whatever a validation result accepted for a field, wherever it landed.
	 *
	 * The Validator answers with the scalar values, the candidate ranges and the
	 * term sets in three separate keys, so a failure message that wants to show
	 * "this is what it stored instead" has to look in the right one.
	 *
	 * @param array  $result Validation result.
	 * @param string $field  Field name.
	 * @return mixed
	 */
	protected static function accepted( array $result, $field ) {
		if ( 'date_ranges' === $field ) {
			return $result['ranges'];
		}

		if ( array_key_exists( $field, $result['terms'] ) ) {
			return $result['terms'][ $field ];
		}

		return array_key_exists( $field, $result['values'] ) ? $result['values'][ $field ] : null;
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

