<?php
/**
 * Property 11 of the enquiry-data-layer design: sanitisation and truncation
 * never reject.
 *
 * Pure by construction: the Validator calls no WordPress function without an
 * existence check, so this runs with WordPress unloaded. The class file guards
 * itself on ABSPATH, which is defined below purely so the file can be loaded —
 * nothing here needs WordPress to be present.
 *
 * It does need a vocabulary of its own, though. The generated submissions carry
 * `event_type` and `site_exclusivity` values drawn from the fixture
 * vocabularies, and the property is that they are *accepted*, so whatever
 * `meh_enquiry_terms_{taxonomy}` answers has to contain them. With WordPress
 * unloaded nothing answers and the fields are unconstrained, which is why this
 * passed without saying so; run in the same process as the wordpress suite,
 * `EnquiryTaxonomies` answers with the live site vocabulary instead, and every
 * generated term reads as `not_allowed`. Pinning the fixture vocabulary here
 * makes the test say what it means in either process.
 *
 * @package MarthrownEnquiryHub
 */

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

class ValidatorSanitisationPropertyTest extends TestCase {

	use TestTrait;

	/**
	 * The four write paths the property is quantified over: both required-field
	 * profiles, and both of creation and an applied edit.
	 *
	 * @var array
	 */
	const WRITE_PATHS = array(
		'webhook-create' => array( Validator::PROFILE_WEBHOOK, Validator::MODE_FULL ),
		'webhook-edit'   => array( Validator::PROFILE_WEBHOOK, Validator::MODE_PARTIAL ),
		'manual-create'  => array( Validator::PROFILE_MANUAL, Validator::MODE_FULL ),
		'manual-edit'    => array( Validator::PROFILE_MANUAL, Validator::MODE_PARTIAL ),
	);

	/**
	 * Install the fixture vocabularies the generated submissions draw from.
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
	 * Feature: enquiry-data-layer, Property 11: For any submission whose
	 * `first_name`, `last_name`, `email`, `phone` or `message` values exceed their
	 * limits and contain arbitrary HTML markup, any required-field profile, and
	 * either write path (creation or an applied edit), validation still accepts the
	 * submission, each stored value contains no HTML tags, and each stored value
	 * length equals at most its configured limit measured after tag removal and
	 * trimming; no field length ever produces a validation failure under either
	 * profile.
	 *
	 * **Validates: Requirements 3.7, 3.9, 3.18, 19.6**
	 */
	public function test_sanitisation_and_truncation_never_reject() {
		$this->limitTo( Iterations::count( 100 ) );

		// A failure here has four bound generators to shrink, which Eris will
		// pursue for as long as it is given. Capped so a failure is reported
		// rather than turning the suite into a hang.
		$this->shrinkingTimeLimit( 1 );

		$this->forAll(
			Generators::enquiry(),
			Generators::over_limit_cores(),
			Generators::markup(),
			\Eris\Generators::elements( array_keys( self::WRITE_PATHS ) )
		)->then(
			function ( array $enquiry, array $cores, $markup, $path ) {
				list( $profile, $mode ) = self::WRITE_PATHS[ $path ];

				$fields = $enquiry;

				// Every truncated field arrives over its limit and wrapped in markup.
				foreach ( $cores as $field => $core ) {
					$fields[ $field ] = sprintf( $markup, $core );
				}

				$where = $path . ' / ' . $markup;

				$result = Validator::validate( $fields, $profile, $mode );

				// No length and no markup ever produces a failure.
				$this->assertSame( array(), $result['errors'], 'unexpected errors on ' . $where );
				$this->assertTrue( $result['ok'], 'submission rejected on ' . $where );

				foreach ( $cores as $field => $core ) {
					$this->assertArrayHasKey( $field, $result['values'], $field . ' missing on ' . $where );

					$value = $result['values'][ $field ];
					$limit = Generators::LIMITS[ $field ];

					// No markup survives: neither the tags nor the bodies of the
					// elements whose content is not text (Requirement 3.9).
					$this->assertDoesNotMatchRegularExpression( '/[<>]/', $value, $field . ' kept markup on ' . $where );
					$this->assertStringNotContainsString( 'alert(', $value, $field . ' kept script body on ' . $where );
					$this->assertStringNotContainsString( 'color:red', $value, $field . ' kept style body on ' . $where );

					// Trimmed, and inside the limit counted in characters rather
					// than bytes (Requirement 3.7).
					$this->assertSame( trim( $value ), $value, $field . ' not trimmed on ' . $where );
					$this->assertLessThanOrEqual(
						$limit,
						mb_strlen( $value, 'UTF-8' ),
						$field . ' over its limit on ' . $where
					);

					// Clipped, not mangled: the stored value is the submitted value
					// with the markup gone and nothing beyond the limit.
					$this->assertSame(
						mb_substr( $core, 0, $limit, 'UTF-8' ),
						$value,
						$field . ' not clipped to its limit on ' . $where
					);
				}
			}
		);
	}
}
