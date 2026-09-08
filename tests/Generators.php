<?php
/**
 * Shared Eris generators for the enquiry-data-layer property tests.
 *
 * Every generator here is a plain static factory returning an Eris generator, so
 * a property test composes them rather than hand-rolling input:
 *
 *     $this->forAll( Generators::enquiry() )
 *          ->then( function ( array $fields ) { … } );
 *
 * The boundary values the design's testing strategy names are emitted by these
 * generators rather than being left to chance: 1 and 3 candidate date ranges,
 * 0, 1 and
 * 20 term values, field values at exactly their stored capacity, `total_guests`
 * of 1 and 10000 and an unsupplied `total_guests` distinct from 0, empty `phone`
 * and `message`, and the adversarial string set.
 *
 * Capacities mirror Requirement 1.18 and are repeated here rather than read from
 * Validator::LIMITS on purpose: a generator that took its bounds from the code
 * under test could not catch that code narrowing them.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub\Tests;

use Eris\Generator;

/**
 * Class Generators
 */
class Generators {

	/**
	 * Stored capacity per field, in characters (Requirement 1.18).
	 *
	 * @var array
	 */
	const LIMITS = array(
		'first_name' => 100,
		'last_name'  => 100,
		'email'      => 254,
		'phone'      => 32,
		'message'    => 5000,
	);

	/** Lowest valid guest count (Requirement 1.18). */
	const TOTAL_GUESTS_MIN = 1;

	/** Highest valid guest count (Requirement 1.18). */
	const TOTAL_GUESTS_MAX = 10000;

	/** Stored capacity of one multi-select value, in characters. */
	const TERM_LIMIT = 100;

	/** Fewest candidate date ranges an enquiry may hold: the ideal one. */
	const RANGES_MIN = 1;

	/** Most candidate date ranges an enquiry may hold: ideal plus two. */
	const RANGES_MAX = 3;

	/** Longest range one generated candidate range spans, in days. */
	const RANGE_DAYS_MAX = 6;

	/** Most values one taxonomy may hold for one enquiry. */
	const TERMS_MAX = 20;

	/**
	 * The adversarial string set: SQL-significant and printf placeholder tokens.
	 *
	 * Used by the parameter-binding property (Property 12); every one of these
	 * must round-trip unchanged and must never be interpolated into SQL.
	 *
	 * @var array
	 */
	const ADVERSARIAL = array( "'", '"', '\\', '--', ';', '%', '_', '%s', '%d' );

	/**
	 * Fixture vocabularies for the two multi-select taxonomies.
	 *
	 * A test installs these on the `meh_enquiry_terms_{taxonomy}` filter so the
	 * Validator's vocabulary checks have a known universe to work against.
	 *
	 * @var array
	 */
	const VOCABULARIES = array(
		'event_type'       => array(
			'wedding',
			'wedding-reception',
			'civil-ceremony',
			'birthday',
			'anniversary',
			'corporate-away-day',
			'team-retreat',
			'conference',
			'family-gathering',
			'wake',
			'photo-shoot',
			'workshop',
		),
		'site_exclusivity' => array(
			'exclusive-use',
			'shared-use',
			'no-preference',
		),
	);

	/** Fixed base date every generated candidate date is offset from. */
	const BASE_DATE = '2025-06-01';

	/**
	 * HTML markup wrappers, each a sprintf template taking the plain value.
	 *
	 * Used by the sanitisation property (Property 11): every one of these must be
	 * gone from the accepted value, and the value inside it must survive. The
	 * script and style entries are here because their bodies must not survive as
	 * text, which is stronger than plain tag removal.
	 *
	 * @var array
	 */
	const MARKUP = array(
		'<b>%s</b>',
		'<p>%s</p>',
		'<span class="x">%s</span>',
		'<div><em>%s</em></div>',
		'<a href="https://example.com/?a=1&b=2">%s</a>',
		'%s<br/>',
		'<script>alert("x")</script>%s',
		'<style>p{color:red}</style>%s',
		"  \t<b>%s</b>\n  ",
		'<b><i>%s</i></b><!-- comment -->',
	);

	/* -----------------------------------------------------------------------
	 * Whole enquiries
	 * -------------------------------------------------------------------- */

	/**
	 * A valid enquiry field map carrying all nine fields.
	 *
	 * Valid under the Webhook profile, and therefore under the Manual profile
	 * too. Pass overrides to pin or widen individual fields; an override may be
	 * a generator or a plain value.
	 *
	 * @param array $overrides field => generator|value.
	 * @return Generator
	 */
	public static function enquiry( array $overrides = array() ) {
		return self::field_map(
			array(
				'first_name'       => self::first_name(),
				'last_name'        => self::last_name(),
				'email'            => self::email(),
				'phone'            => self::phone(),
				'total_guests'     => self::total_guests(),
				'message'          => self::message(),
				'date_ranges'      => self::candidate_ranges(),
				'event_type'       => self::allowed_term_set( 'event_type', 1 ),
				'site_exclusivity' => self::allowed_term_set( 'site_exclusivity', 1 ),
			),
			$overrides
		);
	}

	/**
	 * A valid enquiry field map carrying only the four fields the Manual profile
	 * requires, the five optional fields being absent altogether.
	 *
	 * This is the "absent, not blanked" input the Manual profile and MODE_PARTIAL
	 * both turn on.
	 *
	 * @param array $overrides field => generator|value.
	 * @return Generator
	 */
	public static function minimal_enquiry( array $overrides = array() ) {
		return self::field_map(
			array(
				'first_name'  => self::first_name(),
				'last_name'   => self::last_name(),
				'email'       => self::email(),
				'date_ranges' => self::candidate_ranges(),
			),
			$overrides
		);
	}

	/**
	 * A valid enquiry whose optional fields may be empty or unsupplied:
	 * `phone` and `message` empty, `total_guests` null, the two taxonomies empty.
	 *
	 * @param array $overrides field => generator|value.
	 * @return Generator
	 */
	public static function enquiry_with_empty_optionals( array $overrides = array() ) {
		return self::enquiry(
			array_merge(
				array(
					'phone'            => self::phone_or_empty(),
					'total_guests'     => self::total_guests_or_unsupplied(),
					'message'          => self::message_or_empty(),
					'event_type'       => self::allowed_term_set( 'event_type', 0 ),
					'site_exclusivity' => self::allowed_term_set( 'site_exclusivity', 0 ),
				),
				$overrides
			)
		);
	}

	/* -----------------------------------------------------------------------
	 * Scalar fields
	 * -------------------------------------------------------------------- */

	/**
	 * A valid `first_name`: a plain name, a unicode name, an adversarial string,
	 * or a value at exactly the stored capacity.
	 *
	 * @return Generator
	 */
	public static function first_name() {
		return self::name_like( 'first_name' );
	}

	/**
	 * A valid `last_name`, drawn the same way as `first_name`.
	 *
	 * @return Generator
	 */
	public static function last_name() {
		return self::name_like( 'last_name' );
	}

	/**
	 * A valid email address, including one at exactly 254 characters.
	 *
	 * @return Generator
	 */
	public static function email() {
		$domains = array( 'example.com', 'example.co.uk', 'test.example' );

		$plain = \Eris\Generators::map(
			function ( array $parts ) use ( $domains ) {
				list( $local, $index ) = $parts;
				$local                 = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', (string) $local ) );
				if ( '' === $local ) {
					$local = 'enquirer';
				}
				return $local . '+' . $index . '@' . $domains[ $index % count( $domains ) ];
			},
			\Eris\Generators::tuple( \Eris\Generators::names(), \Eris\Generators::choose( 0, 99 ) )
		);

		return \Eris\Generators::oneOf(
			$plain,
			\Eris\Generators::constant( self::capacity_value( 'email' ) ),
			\Eris\Generators::constant( "o'brien@example.com" )
		);
	}

	/**
	 * An email address that fails email validation, for the rejection properties.
	 *
	 * Every value here is malformed under both WordPress `is_email()` and the
	 * `filter_var()` fallback, so the same generator serves the pure suite and
	 * the WordPress suite. Each one is non-empty after trimming, which is the
	 * precondition the value rules are gated on.
	 *
	 * @return Generator
	 */
	public static function invalid_email() {
		return \Eris\Generators::elements(
			array(
				'not-an-email',
				'plainaddress',
				'enquirer@',
				'@example.com',
				'enquirer name@example.com',
				'enquirer@exam ple.com',
				'enquirer,name@example.com',
				'enquirer@@example.com',
				'enquirer@.com',
				'enquirer at example dot com',
			)
		);
	}

	/**
	 * A valid `phone`: contains at least one digit (Requirement 3.11), including
	 * a value at exactly the stored capacity.
	 *
	 * @return Generator
	 */
	public static function phone() {
		$plain = \Eris\Generators::map(
			function ( $number ) {
				return '07700 ' . str_pad( (string) $number, 6, '0', STR_PAD_LEFT );
			},
			\Eris\Generators::choose( 0, 999999 )
		);

		return \Eris\Generators::oneOf(
			$plain,
			\Eris\Generators::constant( '+44 131 496 0000' ),
			\Eris\Generators::constant( self::capacity_value( 'phone' ) )
		);
	}

	/**
	 * A `phone` that may also be empty, which the store permits (Requirement 1.19)
	 * and the Manual profile accepts (Requirement 3.16).
	 *
	 * @return Generator
	 */
	public static function phone_or_empty() {
		return \Eris\Generators::oneOf( self::phone(), \Eris\Generators::constant( '' ) );
	}

	/**
	 * A `phone` value carrying no digit at all (Requirement 3.11), for the
	 * rejection properties. Non-empty after trimming in every case.
	 *
	 * @return Generator
	 */
	public static function digitless_phone() {
		return \Eris\Generators::elements(
			array(
				'call the office',
				'n/a',
				'-',
				'+ () -',
				'no phone',
				'TBC',
				'ext. unknown',
			)
		);
	}

	/**
	 * A supplied `total_guests`: the 1 and 10000 boundaries plus the range between.
	 *
	 * @return Generator
	 */
	public static function total_guests() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( self::TOTAL_GUESTS_MIN ),
			\Eris\Generators::constant( self::TOTAL_GUESTS_MAX ),
			\Eris\Generators::choose( self::TOTAL_GUESTS_MIN, self::TOTAL_GUESTS_MAX )
		);
	}

	/**
	 * A `total_guests` that may be unsupplied.
	 *
	 * Unsupplied is null, never 0: 0 sits outside the valid range, so it could
	 * never be told apart from a genuine count.
	 *
	 * @return Generator
	 */
	public static function total_guests_or_unsupplied() {
		return \Eris\Generators::oneOf( self::total_guests(), \Eris\Generators::constant( null ) );
	}

	/**
	 * A `total_guests` value outside the valid range, for the rejection properties.
	 *
	 * @return Generator
	 */
	public static function invalid_total_guests() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( 0 ),
			\Eris\Generators::constant( self::TOTAL_GUESTS_MAX + 1 ),
			\Eris\Generators::choose( -100, 0 ),
			\Eris\Generators::choose( self::TOTAL_GUESTS_MAX + 1, self::TOTAL_GUESTS_MAX + 1000 )
		);
	}

	/**
	 * A `total_guests` value that is not a whole number (Requirement 3.4).
	 *
	 * Kept apart from `invalid_total_guests()` because "that is not a number" and
	 * "that number is out of range" are two different failures of the same
	 * criterion, and a property covering the criterion needs both.
	 *
	 * @return Generator
	 */
	public static function non_integer_total_guests() {
		return \Eris\Generators::oneOf(
			\Eris\Generators::elements(
				array(
					'about forty',
					'40-50',
					'3.5',
					'12a',
					'1e3',
					'~20',
					'twelve',
				)
			),
			\Eris\Generators::map(
				function ( $whole ) {
					return $whole + 0.5;
				},
				\Eris\Generators::choose( self::TOTAL_GUESTS_MIN, self::TOTAL_GUESTS_MAX - 1 )
			)
		);
	}

	/**
	 * A `message`, including one at exactly the stored capacity and ones carrying
	 * adversarial tokens.
	 *
	 * @return Generator
	 */
	public static function message() {
		$plain = \Eris\Generators::map(
			function ( $words ) {
				return 'Looking at a summer weekend for around ' . $words . ' guests.';
			},
			\Eris\Generators::choose( 2, 400 )
		);

		return \Eris\Generators::oneOf(
			$plain,
			\Eris\Generators::constant( self::capacity_value( 'message' ) ),
			self::adversarial_string()
		);
	}

	/**
	 * A `message` that may be empty, which the store permits (Requirement 1.19).
	 *
	 * @return Generator
	 */
	public static function message_or_empty() {
		return \Eris\Generators::oneOf( self::message(), \Eris\Generators::constant( '' ) );
	}

	/* -----------------------------------------------------------------------
	 * Candidate date ranges
	 * -------------------------------------------------------------------- */

	/**
	 * A ranked candidate range list holding between $min and $max ranges.
	 *
	 * Each entry is `array( 'start' => 'Y-m-d', 'end' => 'Y-m-d' )` with the end
	 * on or after the start, and every range begins after the previous one ends,
	 * which is what keeps them distinct: the Validator collapses duplicates, so a
	 * generator that could emit the same range twice would emit a list shorter
	 * than the count it chose. Single-day ranges — both bounds the same — are
	 * reachable, because that is how a one-day enquiry is stored.
	 *
	 * The list is in generation order, which is the rank the store preserves:
	 * position 0 is the ideal range. Defaults span the whole accepted range, 1 to
	 * 3, and both boundaries are reachable.
	 *
	 * @param int $min Fewest ranges.
	 * @param int $max Most ranges.
	 * @return Generator
	 */
	public static function candidate_ranges( $min = self::RANGES_MIN, $max = self::RANGES_MAX ) {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( (int) $min, (int) $max ),
			function ( $count ) {
				if ( $count < 1 ) {
					return \Eris\Generators::constant( array() );
				}

				return \Eris\Generators::map(
					function ( array $steps ) {
						$ranges = array();
						$offset = 0;

						foreach ( $steps as $step ) {
							// The gap is at least one day, so this range starts
							// after the last one ended.
							$offset  += max( 1, (int) $step[0] );
							$span     = max( 0, (int) $step[1] );
							$ranges[] = array(
								'start' => self::date_at( $offset ),
								'end'   => self::date_at( $offset + $span ),
							);
							$offset  += $span;
						}

						return $ranges;
					},
					\Eris\Generators::vector(
						(int) $count,
						\Eris\Generators::tuple(
							\Eris\Generators::choose( 1, 45 ),
							\Eris\Generators::choose( 0, self::RANGE_DAYS_MAX )
						)
					)
				);
			}
		);
	}

	/**
	 * One candidate range, for fixtures that hold a single row.
	 *
	 * @return Generator
	 */
	public static function candidate_range() {
		return \Eris\Generators::map(
			function ( array $ranges ) {
				return $ranges[0];
			},
			self::candidate_ranges( 1, 1 )
		);
	}

	/**
	 * A candidate range list longer than the accepted maximum, for the rejection
	 * properties.
	 *
	 * @return Generator
	 */
	public static function oversized_candidate_ranges() {
		return self::candidate_ranges( self::RANGES_MAX + 1, self::RANGES_MAX + 3 );
	}

	/**
	 * Every day one generated range covers, inclusive of both bounds.
	 *
	 * The store holds ranges, but a booking is one day and several tests need a
	 * day the enquiry actually offered, so this is how they get one.
	 *
	 * @param array $ranges Candidate ranges.
	 * @return string[] Days as `Y-m-d`, in rank order then chronological.
	 */
	public static function days_in_ranges( array $ranges ) {
		$days = array();

		foreach ( $ranges as $range ) {
			if ( ! is_array( $range ) || ! isset( $range['start'], $range['end'] ) ) {
				continue;
			}

			$cursor = new \DateTimeImmutable( (string) $range['start'], new \DateTimeZone( 'UTC' ) );
			$end    = new \DateTimeImmutable( (string) $range['end'], new \DateTimeZone( 'UTC' ) );

			while ( $cursor <= $end ) {
				$day = $cursor->format( 'Y-m-d' );

				if ( ! in_array( $day, $days, true ) ) {
					$days[] = $day;
				}

				$cursor = $cursor->modify( '+1 day' );
			}
		}

		return $days;
	}

	/**
	 * A value that names no calendar date (Requirement 3.6).
	 *
	 * Two shapes, both of which a submission can genuinely carry: text that is
	 * not a date at all, and a date-shaped value whose day does not exist in the
	 * month it names. The second shape is the interesting one, because a lenient
	 * parser rolls `2025-02-30` forward to 2 March and so stores a day the
	 * enquirer never chose.
	 *
	 * @return Generator
	 */
	public static function unparseable_date() {
		return \Eris\Generators::elements(
			array(
				'not a date',
				'tbc',
				'any weekend',
				'16',
				'summer',
				'2025-13-45',
				'31/31/2025',
				'2025-02-30',
				'2025-04-31',
				'2026-02-29',
			)
		);
	}

	/**
	 * One candidate date as Y-m-d.
	 *
	 * @return Generator
	 */
	public static function candidate_date() {
		return \Eris\Generators::map(
			function ( $offset ) {
				return self::date_at( (int) $offset );
			},
			\Eris\Generators::choose( 0, 720 )
		);
	}

	/**
	 * A date this many days after the fixed base date.
	 *
	 * @param int $days Day offset.
	 * @return string Y-m-d.
	 */
	public static function date_at( $days ) {
		$base = new \DateTimeImmutable( self::BASE_DATE, new \DateTimeZone( 'UTC' ) );
		return $base->modify( '+' . (int) $days . ' days' )->format( 'Y-m-d' );
	}

	/* -----------------------------------------------------------------------
	 * Multi-select values
	 * -------------------------------------------------------------------- */

	/**
	 * A term set of between $min and $max distinct arbitrary values, each within
	 * the stored 100-character capacity.
	 *
	 * This is the store-level generator: the store accepts any value, so nothing
	 * here is confined to a vocabulary. 0 is a valid size and 20 is the maximum
	 * (Requirement 1.3).
	 *
	 * @param int $min Fewest values.
	 * @param int $max Most values.
	 * @return Generator
	 */
	public static function term_set( $min = 0, $max = self::TERMS_MAX ) {
		return \Eris\Generators::bind(
			\Eris\Generators::choose( (int) $min, (int) $max ),
			function ( $count ) {
				if ( $count < 1 ) {
					return \Eris\Generators::constant( array() );
				}
				return \Eris\Generators::map(
					function ( array $suffixes ) {
						$terms = array();
						foreach ( array_values( $suffixes ) as $index => $suffix ) {
							// The index keeps every value distinct whatever the suffix.
							$terms[] = 'term-' . $index . '-' . $suffix;
						}
						return $terms;
					},
					\Eris\Generators::vector(
						(int) $count,
						\Eris\Generators::oneOf(
							\Eris\Generators::choose( 1, 9999 ),
							\Eris\Generators::constant( str_repeat( 'x', self::TERM_LIMIT - 10 ) )
						)
					)
				);
			}
		);
	}

	/**
	 * A term set drawn from a taxonomy's fixture vocabulary, holding between $min
	 * values and the whole vocabulary.
	 *
	 * This is the Validator-level generator: every value passes the vocabulary
	 * check for that taxonomy.
	 *
	 * @param string $taxonomy 'event_type' or 'site_exclusivity'.
	 * @param int    $min      Fewest values. 0 permits the empty set.
	 * @return Generator
	 */
	public static function allowed_term_set( $taxonomy, $min = 0 ) {
		$vocabulary = self::vocabulary( $taxonomy );
		$min        = max( 0, (int) $min );

		return \Eris\Generators::map(
			function ( array $subset ) use ( $vocabulary, $min ) {
				$subset = array_values( array_unique( $subset ) );
				// Top the subset up when it came back below the requested floor.
				foreach ( $vocabulary as $value ) {
					if ( count( $subset ) >= $min ) {
						break;
					}
					if ( ! in_array( $value, $subset, true ) ) {
						$subset[] = $value;
					}
				}
				return $subset;
			},
			\Eris\Generators::subset( $vocabulary )
		);
	}

	/**
	 * A term value outside the taxonomy's vocabulary, for the rejection properties.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return Generator
	 */
	public static function disallowed_term( $taxonomy ) {
		$vocabulary = self::vocabulary( $taxonomy );

		return \Eris\Generators::map(
			function ( $suffix ) use ( $vocabulary ) {
				$value = 'not-a-' . $suffix;
				return in_array( $value, $vocabulary, true ) ? $value . '-x' : $value;
			},
			\Eris\Generators::choose( 1, 9999 )
		);
	}

	/**
	 * The fixture vocabulary for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array
	 */
	public static function vocabulary( $taxonomy ) {
		return isset( self::VOCABULARIES[ $taxonomy ] ) ? self::VOCABULARIES[ $taxonomy ] : array();
	}

	/* -----------------------------------------------------------------------
	 * Adversarial and capacity values
	 * -------------------------------------------------------------------- */

	/**
	 * The adversarial token set, plus the strings that embed each token.
	 *
	 * @return array
	 */
	public static function adversarial_values() {
		$values = self::ADVERSARIAL;

		foreach ( self::ADVERSARIAL as $token ) {
			$values[] = 'before' . $token . 'after';
			$values[] = $token . $token;
			$values[] = 'Ada' . $token;
		}

		// Every token at once, plus the classic injection shapes.
		$values[] = implode( '', self::ADVERSARIAL );
		$values[] = "Robert'); DROP TABLE wp_meh_enquiries; --";
		$values[] = '100% _ %s %d';
		$values[] = 'C:\\path\\to\\nowhere';

		return $values;
	}

	/**
	 * One adversarial string.
	 *
	 * @return Generator
	 */
	public static function adversarial_string() {
		return \Eris\Generators::elements( self::adversarial_values() );
	}

	/**
	 * A value of exactly a field's stored capacity, in characters.
	 *
	 * @param string $field   Field name from LIMITS.
	 * @param bool   $unicode Whether to fill with a multibyte character.
	 * @return string
	 */
	public static function capacity_value( $field, $unicode = false ) {
		$limit = isset( self::LIMITS[ $field ] ) ? self::LIMITS[ $field ] : 100;

		if ( 'email' === $field ) {
			/*
			 * Spend the length on extra domain labels, not on a longer local
			 * part: a local part over 64 characters is malformed, and the
			 * `filter_var()` fallback the Validator uses with WordPress unloaded
			 * rejects it. A generator emitting an address the code under test is
			 * right to reject would make every "otherwise-valid submission"
			 * property fail for the wrong reason.
			 */
			return self::email_of_length( $limit );
		}

		if ( $unicode ) {
			return str_repeat( 'é', $limit );
		}

		if ( 'phone' === $field ) {
			return '+44' . str_repeat( '7', $limit - 3 );
		}

		return str_pad( 'Ada', $limit, 'x' );
	}

	/**
	 * A value at exactly a field's capacity, either ASCII or unicode filled.
	 *
	 * @param string $field Field name from LIMITS.
	 * @return Generator
	 */
	public static function capacity_field( $field ) {
		return \Eris\Generators::oneOf(
			\Eris\Generators::constant( self::capacity_value( $field ) ),
			\Eris\Generators::constant( self::capacity_value( $field, true ) )
		);
	}

	/**
	 * A value one character longer than a field's capacity, for the truncation
	 * property.
	 *
	 * @param string $field Field name from LIMITS.
	 * @return Generator
	 */
	public static function over_capacity_field( $field ) {
		$limit = isset( self::LIMITS[ $field ] ) ? self::LIMITS[ $field ] : 100;

		return \Eris\Generators::map(
			function ( $extra ) use ( $limit ) {
				return str_pad( 'Ada', $limit + (int) $extra, 'x' );
			},
			\Eris\Generators::choose( 1, 50 )
		);
	}

	/* -----------------------------------------------------------------------
	 * Markup and over-limit values
	 * -------------------------------------------------------------------- */

	/**
	 * One HTML markup wrapper, as a sprintf template.
	 *
	 * @return Generator
	 */
	public static function markup() {
		return \Eris\Generators::elements( self::MARKUP );
	}

	/**
	 * The plain value a marked-up over-limit submission carries, before any markup
	 * is wrapped around it.
	 *
	 * Carries no `<`, no `>` and no leading or trailing whitespace, so tag removal
	 * and trimming leave it untouched and a test can compute the expected stored
	 * value as a plain character-count clip of it.
	 *
	 * Every field but `email` is generated longer than its stored limit, in ASCII
	 * or in a multibyte character, so truncation has to count characters rather
	 * than bytes to stay inside the limit.
	 *
	 * `email` is the exception, and deliberately lands at exactly its 254
	 * character limit rather than beyond it: 254 is also the longest address RFC
	 * 5321 permits, so a longer value fails email validation as an address
	 * (Requirement 3.3) rather than on its length. The submitted value still
	 * exceeds the limit once the markup is wrapped around it, which is the
	 * over-limit input the property is about.
	 *
	 * @param string $field Field name from LIMITS.
	 * @return Generator
	 */
	public static function over_limit_core( $field ) {
		$limit = isset( self::LIMITS[ $field ] ) ? self::LIMITS[ $field ] : 100;

		if ( 'email' === $field ) {
			return \Eris\Generators::constant( self::email_of_length( $limit ) );
		}

		return \Eris\Generators::map(
			function ( array $parts ) use ( $field, $limit ) {
				list( $extra, $unicode ) = $parts;
				$length                  = $limit + (int) $extra;

				if ( 'phone' === $field ) {
					// Must still hold a digit (Requirement 3.11).
					return '+44' . str_repeat( '7', $length - 3 );
				}

				return $unicode
					? str_repeat( 'é', $length )
					: str_pad( 'Ada', $length, 'x' );
			},
			\Eris\Generators::tuple(
				\Eris\Generators::oneOf(
					\Eris\Generators::constant( 1 ),
					\Eris\Generators::choose( 1, 200 )
				),
				\Eris\Generators::elements( array( true, false ) )
			)
		);
	}

	/**
	 * The plain over-limit value of every truncated field, as one map.
	 *
	 * @return Generator
	 */
	public static function over_limit_cores() {
		$spec = array();

		foreach ( array_keys( self::LIMITS ) as $field ) {
			$spec[ $field ] = self::over_limit_core( $field );
		}

		return \Eris\Generators::associative( $spec );
	}

	/**
	 * A syntactically valid email address of exactly $length characters.
	 *
	 * Length is taken up by extra domain labels rather than by a longer local
	 * part, and every label stays short, so no per-label or local-part RFC ceiling
	 * is crossed on the way to the requested total.
	 *
	 * @param int $length Total character length; 20 or more.
	 * @return string
	 */
	public static function email_of_length( $length ) {
		$local  = 'ada';
		$tld    = '.com';
		$length = max( 20, (int) $length );

		// What is left for the domain, minus the '@' and the trailing '.com'.
		$body_length = $length - strlen( $local ) - 1 - strlen( $tld );
		$body        = '';

		// Leave at least one character for a final label, so the body never ends
		// on the '.' that precedes the TLD.
		while ( strlen( $body ) + 4 <= $body_length - 1 ) {
			$body .= 'sub.';
		}

		$body .= str_repeat( 'x', $body_length - strlen( $body ) );

		return $local . '@' . $body . $tld;
	}

	/* -----------------------------------------------------------------------
	 * Internals
	 * -------------------------------------------------------------------- */

	/**
	 * A name-shaped value for a field: plain, unicode, quoted, adversarial, or at
	 * exactly the stored capacity.
	 *
	 * @param string $field Field name from LIMITS.
	 * @return Generator
	 */
	protected static function name_like( $field ) {
		/*
		 * Eris' names() answers with the empty string at size 0, and an empty
		 * name is a presence failure rather than a valid value. Every generator
		 * here feeds submissions that are meant to be valid, so the empty answer
		 * is replaced rather than left to surface as a spurious rejection in
		 * whichever property happened to draw it.
		 */
		$names = \Eris\Generators::map(
			function ( $name ) {
				$name = is_scalar( $name ) ? trim( (string) $name ) : '';

				return '' === $name ? 'Ada' : $name;
			},
			\Eris\Generators::names()
		);

		return \Eris\Generators::oneOf(
			$names,
			\Eris\Generators::constant( 'Ada' ),
			\Eris\Generators::constant( "O'Brien" ),
			\Eris\Generators::constant( 'Zoë Ó Séaghdha' ),
			\Eris\Generators::constant( self::capacity_value( $field ) ),
			\Eris\Generators::constant( self::capacity_value( $field, true ) ),
			self::adversarial_string()
		);
	}

	/**
	 * Build an associative generator from a field spec plus overrides.
	 *
	 * An override may be a generator or a plain value; a null override removes
	 * the field from the map altogether, which is how "absent" is expressed.
	 *
	 * @param array $spec      field => generator.
	 * @param array $overrides field => generator|value|null.
	 * @return Generator
	 */
	protected static function field_map( array $spec, array $overrides ) {
		foreach ( $overrides as $field => $override ) {
			if ( null === $override ) {
				unset( $spec[ $field ] );
				continue;
			}
			$spec[ $field ] = self::as_generator( $override );
		}

		return \Eris\Generators::associative( $spec );
	}

	/**
	 * Wrap a plain value in a constant generator, leaving generators alone.
	 *
	 * @param mixed $value Generator or value.
	 * @return \Eris\Generator
	 */
	protected static function as_generator( $value ) {
		if ( $value instanceof \Eris\Generator ) {
			return $value;
		}
		return \Eris\Generators::constant( $value );
	}
}
