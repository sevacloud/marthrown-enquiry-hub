<?php
/**
 * Submission validation for the enquiry layer.
 *
 * One validator serves every write path — the intake webhook, manual creation
 * and an edit — because the per-field value rules are identical on all three.
 * Two things vary, and they are the two arguments after the field map:
 *
 * - `$profile` selects the required-field set and nothing else. The Webhook
 *   Validation Profile requires all nine fields (Requirement 3.14); the Manual
 *   Validation Profile requires exactly `first_name`, `last_name`, `email` and
 *   `date_ranges` (Requirement 3.15).
 * - `$mode` decides only whether absence counts as a violation. On creation by
 *   either route (`MODE_FULL`) a required field that is absent fails. On an
 *   edit (`MODE_PARTIAL`) a required field that is absent is simply not being
 *   changed, so it produces no failure, while a required field that is present
 *   and holds an empty value fails in both modes (Requirement 19.4).
 *
 * Order of operations: presence against the profile's required set, then the
 * value rules, then HTML tag removal and trimming, then truncation. Truncation
 * is last and never produces an error, so an over-long value is accepted
 * clipped rather than rejected (Requirements 3.7, 3.18).
 *
 * Every value rule is gated on the field being present and non-empty after
 * trimming rather than on the profile (Requirement 3.17). That single gate
 * produces both behaviours the requirements ask for: an absent or empty
 * `phone` under the Manual profile yields no error of any kind (Requirement
 * 3.16), while a `phone` holding "abc" yields `phone => no_digits` under the
 * Manual profile exactly as it does under the Webhook profile. `is_empty()` is
 * the one emptiness predicate, used by the presence check and the value gate
 * alike, so the two can never disagree about what "empty" means.
 *
 * Every failing field is collected before returning, so one submission reports
 * all of its problems in a single error set (Requirement 3.2).
 *
 * No WordPress function is called without an existence check, so this class is
 * exercisable from the `pure` test suite with WordPress unloaded.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Validator
 */
class Validator {

	/**
	 * Webhook Validation Profile: a submission arriving as an Intake Webhook Request.
	 */
	const PROFILE_WEBHOOK = 'webhook';

	/**
	 * Manual Validation Profile: manual creation, and an edit.
	 */
	const PROFILE_MANUAL = 'manual';

	/**
	 * Creation, by either route: a required field must be present.
	 */
	const MODE_FULL = 'full';

	/**
	 * An edit: a required field must not be blanked, but may be omitted.
	 */
	const MODE_PARTIAL = 'partial';

	/**
	 * Required-field sets — the only thing a profile decides.
	 */
	const REQUIRED_BY_PROFILE = array(
		// All nine fields required (Requirement 3.14).
		self::PROFILE_WEBHOOK => array(
			'first_name',
			'last_name',
			'email',
			'phone',
			'total_guests',
			'date_ranges',
			'event_type',
			'site_exclusivity',
			'message',
		),
		// Four required; phone, total_guests, message, event_type and
		// site_exclusivity optional (Requirement 3.15).
		self::PROFILE_MANUAL  => array(
			'first_name',
			'last_name',
			'email',
			'date_ranges',
		),
	);

	/**
	 * Character limits applied by truncation, after tag removal and trimming.
	 */
	const LIMITS = array(
		'first_name' => 100,
		'last_name'  => 100,
		'email'      => 254,
		'phone'      => 32,
		'message'    => 5000,
	);

	/**
	 * The scalar enquiry fields, in the order the accepted value map returns them.
	 */
	const SCALAR_FIELDS = array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'total_guests',
		'message',
	);

	/**
	 * The two multi-select fields, held as term sets.
	 */
	const TAXONOMIES = array(
		'event_type',
		'site_exclusivity',
	);

	/**
	 * Character limit for one term value, matching the store column.
	 */
	const TERM_LIMIT = 100;

	/**
	 * Inclusive `total_guests` bounds (Requirement 3.4).
	 */
	const MIN_GUESTS = 1;
	const MAX_GUESTS = 10000;

	/**
	 * Inclusive candidate date range count bounds (Requirement 3.5).
	 *
	 * One is required — the ideal dates — and two more may be offered as
	 * alternatives, which is what the enquiry form asks for and what the hub has
	 * room to show. A sender offering a fourth is reporting a form the site does
	 * not have, so it fails rather than being silently clipped to three.
	 */
	const MIN_RANGES = 1;
	const MAX_RANGES = 3;

	/**
	 * Date formats accepted verbatim, tried in order.
	 *
	 * Both padded and unpadded variants are listed so that a day-first value is
	 * read day-first: `1/2/2025` is 1 February, where the looser parse below
	 * would read a slash-separated value month-first and silently return
	 * 2 January.
	 *
	 * A value matching none of these falls through to that looser parse, which
	 * insists on a four-digit year and on any day it names existing in the month
	 * it names, so "16 August 2025" is a date and "16", "next tuesday",
	 * "not a date" and "2026-02-29" are not.
	 */
	const DATE_FORMATS = array(
		'Y-m-d',
		'Y-n-j',
		'Y/m/d',
		'Y/n/j',
		'd/m/Y',
		'j/n/Y',
		'd-m-Y',
		'j-n-Y',
		'd.m.Y',
		'j.n.Y',
	);

	/**
	 * Validate a submitted field map.
	 *
	 * @param array  $fields  Flat map of enquiry field name => submitted value.
	 *                        A field is present only when its key is present.
	 * @param string $profile self::PROFILE_WEBHOOK | self::PROFILE_MANUAL.
	 * @param string $mode    self::MODE_FULL | self::MODE_PARTIAL.
	 * @return array{ok:bool, values:array, ranges:array, terms:array, errors:array<string,string>}
	 *         On failure, `values`, `ranges` and `terms` are empty and `errors`
	 *         names every failing field. On success, `values` holds the
	 *         sanitised scalar values of the fields the submission carried,
	 *         `ranges` the normalised candidate date ranges with the ideal one
	 *         first, and `terms` the accepted term sets keyed by taxonomy — each
	 *         present only where the submission carried that field, so a caller
	 *         can tell "not submitted" from "submitted empty".
	 */
	public static function validate( array $fields, $profile = self::PROFILE_WEBHOOK, $mode = self::MODE_FULL ) {
		$profile = self::PROFILE_MANUAL === $profile ? self::PROFILE_MANUAL : self::PROFILE_WEBHOOK;
		$mode    = self::MODE_PARTIAL === $mode ? self::MODE_PARTIAL : self::MODE_FULL;

		$errors = self::presence_errors( $fields, $profile, $mode );

		// The value rules are gated on presence and non-emptiness alone, so they
		// run identically under both profiles and add nothing for a field the
		// presence check has already named (Requirement 3.17).
		foreach ( self::value_errors( $fields ) as $field => $code ) {
			if ( ! isset( $errors[ $field ] ) ) {
				$errors[ $field ] = $code;
			}
		}

		if ( array() !== $errors ) {
			return array(
				'ok'     => false,
				'values' => array(),
				'ranges' => array(),
				'terms'  => array(),
				'errors' => $errors,
			);
		}

		return array(
			'ok'     => true,
			'values' => self::accepted_values( $fields ),
			'ranges' => self::accepted_ranges( $fields ),
			'terms'  => self::accepted_terms( $fields ),
			'errors' => array(),
		);
	}

	/**
	 * The required-field set of a profile.
	 *
	 * An unrecognised profile falls back to the Webhook set, which is the
	 * stricter of the two, so a mistyped profile cannot quietly wave a
	 * submission through.
	 *
	 * @param string $profile Profile name.
	 * @return string[]
	 */
	public static function required_for( $profile ) {
		$required = self::REQUIRED_BY_PROFILE;

		return isset( $required[ $profile ] ) ? $required[ $profile ] : $required[ self::PROFILE_WEBHOOK ];
	}

	/**
	 * The one emptiness predicate.
	 *
	 * Empty means: null, an empty string, a whitespace-only string, or a
	 * collection holding no non-empty value. A non-string scalar is compared
	 * through its string form, so `0` and `"0"` are both non-empty — a
	 * `total_guests` of 0 must reach the range rule and fail there rather than
	 * be reported as absent.
	 *
	 * A value that is neither scalar nor array — an object or a resource — is
	 * treated as empty, because nothing downstream can store it.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public static function is_empty( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::is_empty( $item ) ) {
					return false;
				}
			}

			return true;
		}

		if ( ! is_scalar( $value ) ) {
			return true;
		}

		return '' === trim( (string) $value );
	}

	/**
	 * The permitted vocabulary for a multi-select field.
	 *
	 * The plugin ships no vocabulary of its own: the values a site permits are
	 * supplied through the `meh_enquiry_terms_{taxonomy}` filter. An empty
	 * vocabulary therefore means "unconstrained" rather than "nothing is
	 * allowed", so an unconfigured site accepts what its form sends instead of
	 * rejecting every submission carrying a multi-select value.
	 *
	 * @param string $taxonomy 'event_type' | 'site_exclusivity'.
	 * @return string[] Permitted values; empty when unconstrained.
	 */
	public static function allowed_terms( $taxonomy ) {
		$taxonomy = is_scalar( $taxonomy ) ? (string) $taxonomy : '';

		if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			return array();
		}

		$terms = array();

		if ( function_exists( 'apply_filters' ) ) {
			$terms = apply_filters( 'meh_enquiry_terms_' . $taxonomy, $terms );
		}

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$allowed = array();

		foreach ( $terms as $term ) {
			if ( ! is_scalar( $term ) ) {
				continue;
			}

			$term = trim( (string) $term );

			if ( '' !== $term && ! in_array( $term, $allowed, true ) ) {
				$allowed[] = $term;
			}
		}

		return $allowed;
	}

	/**
	 * Presence failures for the applied profile and mode.
	 *
	 * @param array  $fields  Submitted field map.
	 * @param string $profile Applied profile.
	 * @param string $mode    Applied mode.
	 * @return array<string,string> Field => error code.
	 */
	protected static function presence_errors( array $fields, $profile, $mode ) {
		$errors = array();

		foreach ( self::required_for( $profile ) as $field ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				// Absence is a violation on creation only. On an edit the field
				// is simply not being changed.
				if ( self::MODE_FULL === $mode ) {
					$errors[ $field ] = 'required';
				}

				continue;
			}

			if ( self::is_empty( $fields[ $field ] ) ) {
				$errors[ $field ] = 'empty';
			}
		}

		return $errors;
	}

	/**
	 * Value-rule failures, one code per field.
	 *
	 * Each rule runs when its field is supplied — present and non-empty after
	 * trimming — whatever the profile and whatever the mode.
	 *
	 * @param array $fields Submitted field map.
	 * @return array<string,string> Field => error code.
	 */
	protected static function value_errors( array $fields ) {
		$errors = array();

		if ( self::supplied( $fields, 'email' ) && ! self::is_valid_email( self::sanitise_text( $fields['email'] ) ) ) {
			$errors['email'] = 'invalid_email';
		}

		if ( self::supplied( $fields, 'total_guests' ) ) {
			$code = self::guests_error( $fields['total_guests'] );

			if ( null !== $code ) {
				$errors['total_guests'] = $code;
			}
		}

		if ( self::supplied( $fields, 'phone' ) && ! preg_match( '/\d/', self::sanitise_text( $fields['phone'] ) ) ) {
			$errors['phone'] = 'no_digits';
		}

		if ( self::supplied( $fields, 'date_ranges' ) ) {
			$code = self::range_error( $fields['date_ranges'] );

			if ( null !== $code ) {
				$errors['date_ranges'] = $code;
			}
		}

		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( self::supplied( $fields, $taxonomy ) && ! self::terms_permitted( $fields[ $taxonomy ], $taxonomy ) ) {
				$errors[ $taxonomy ] = 'not_allowed';
			}
		}

		return $errors;
	}

	/**
	 * Whether a field is present and non-empty: the gate every value rule sits behind.
	 *
	 * @param array  $fields Submitted field map.
	 * @param string $field  Field name.
	 * @return bool
	 */
	protected static function supplied( array $fields, $field ) {
		return array_key_exists( $field, $fields ) && ! self::is_empty( $fields[ $field ] );
	}

	/**
	 * The `total_guests` failure code for a submitted value, or null when it passes.
	 *
	 * @param mixed $value Submitted `total_guests` value.
	 * @return string|null 'not_whole_number' | 'out_of_range' | null.
	 */
	protected static function guests_error( $value ) {
		$guests = self::parse_guests( $value );

		if ( null === $guests ) {
			return 'not_whole_number';
		}

		if ( $guests < self::MIN_GUESTS || $guests > self::MAX_GUESTS ) {
			return 'out_of_range';
		}

		return null;
	}

	/**
	 * The candidate date range failure code for a submitted value, or null when
	 * it passes.
	 *
	 * The count rule is evaluated over the submitted entries rather than over the
	 * distinct ranges they resolve to, so a submission carrying four entries fails
	 * whether or not two of them name the same fortnight. That is the same
	 * decision the day list made, and for the same reason: a form offering more
	 * ranges than the site has is worth reporting rather than quietly accepting
	 * three of them.
	 *
	 * @param mixed $value Submitted `date_ranges` value.
	 * @return string|null 'too_few_ranges' | 'too_many_ranges' | 'incomplete_range'
	 *                     | 'unparseable_date' | 'ends_before_start' | null.
	 */
	protected static function range_error( $value ) {
		$entries = self::to_ranges( $value );
		$count   = count( $entries );

		if ( $count < self::MIN_RANGES ) {
			return 'too_few_ranges';
		}

		if ( $count > self::MAX_RANGES ) {
			return 'too_many_ranges';
		}

		foreach ( $entries as $entry ) {
			// A bare date is the single-day range it names, so only an array can
			// be missing a bound.
			if ( is_array( $entry ) && ( ! array_key_exists( 'start', $entry ) || ! array_key_exists( 'end', $entry ) ) ) {
				return 'incomplete_range';
			}

			$start = self::parse_date( is_array( $entry ) ? $entry['start'] : $entry );
			$end   = self::parse_date( is_array( $entry ) ? $entry['end'] : $entry );

			// An entry naming one bound and leaving the other blank reaches here
			// rather than the check above, since the key is present.
			if ( null === $start || null === $end ) {
				return 'unparseable_date';
			}

			if ( $end < $start ) {
				return 'ends_before_start';
			}
		}

		return null;
	}

	/**
	 * Whether every submitted term of a taxonomy sits inside its vocabulary.
	 *
	 * Membership is compared case-insensitively and without surrounding
	 * whitespace, so a form sending "Wedding" against a vocabulary spelling it
	 * "wedding" is accepted. The submitted spelling is what gets stored: this
	 * class decides acceptance, it does not rewrite values.
	 *
	 * @param mixed  $value    Submitted value.
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	protected static function terms_permitted( $value, $taxonomy ) {
		$allowed = self::allowed_terms( $taxonomy );

		if ( array() === $allowed ) {
			return true;
		}

		$permitted = array();

		foreach ( $allowed as $term ) {
			$permitted[] = self::fold( $term );
		}

		foreach ( self::to_list( $value ) as $term ) {
			if ( ! in_array( self::fold( self::sanitise_text( $term ) ), $permitted, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The accepted scalar values, sanitised then truncated.
	 *
	 * Only fields the submission carried appear, so a caller can tell a field
	 * left alone from a field cleared. `total_guests` is an integer when
	 * supplied and null when carried but empty, which is the store's
	 * "not supplied" value.
	 *
	 * @param array $fields Submitted field map.
	 * @return array<string,string|int|null>
	 */
	protected static function accepted_values( array $fields ) {
		$values = array();

		foreach ( self::SCALAR_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				continue;
			}

			if ( 'total_guests' === $field ) {
				$values[ $field ] = self::is_empty( $fields[ $field ] )
					? null
					: self::parse_guests( $fields[ $field ] );

				continue;
			}

			$limits = self::LIMITS;
			$limit  = isset( $limits[ $field ] ) ? $limits[ $field ] : 0;

			$values[ $field ] = self::truncate( self::sanitise_text( $fields[ $field ] ), $limit );
		}

		return $values;
	}

	/**
	 * The accepted candidate date ranges, normalised and de-duplicated.
	 *
	 * Both bounds come back as `Y-m-d` whatever form they arrived in, and two
	 * entries naming the same range yield one — which is what the store holds. The
	 * submitted order survives, because the first range is the ideal one.
	 *
	 * An empty result means the submission carried no `date_ranges` at all: a
	 * submitted-but-empty list is a presence failure under both profiles and never
	 * reaches here.
	 *
	 * @param array $fields Submitted field map.
	 * @return array<int,array{start:string,end:string}>
	 */
	protected static function accepted_ranges( array $fields ) {
		if ( ! array_key_exists( 'date_ranges', $fields ) ) {
			return array();
		}

		$ranges = array();

		foreach ( self::to_ranges( $fields['date_ranges'] ) as $entry ) {
			if ( is_array( $entry ) ) {
				$start = self::parse_date( isset( $entry['start'] ) ? $entry['start'] : null );
				$end   = self::parse_date( isset( $entry['end'] ) ? $entry['end'] : null );
			} else {
				$start = self::parse_date( $entry );
				$end   = $start;
			}

			if ( null === $start || null === $end ) {
				continue;
			}

			$range = array(
				'start' => $start,
				'end'   => $end,
			);

			if ( ! in_array( $range, $ranges, true ) ) {
				$ranges[] = $range;
			}
		}

		return $ranges;
	}

	/**
	 * A submitted `date_ranges` value as a list of entries.
	 *
	 * `to_list()` cannot be used directly: a lone range is an array with `start`
	 * and `end` keys, which that method would read as a list of two dates. So a
	 * value carrying either of those keys is wrapped as the single entry it is,
	 * and anything else is listed as usual.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<int,mixed>
	 */
	protected static function to_ranges( $value ) {
		if ( is_array( $value ) && ( array_key_exists( 'start', $value ) || array_key_exists( 'end', $value ) ) ) {
			return array( $value );
		}

		return self::to_list( $value );
	}

	/**
	 * The accepted term sets, keyed by taxonomy.
	 *
	 * A taxonomy appears only when the submission carried it, and appears with
	 * an empty array when it carried it empty — which under the Manual profile
	 * is an accepted way of clearing the set. Values are sanitised, truncated to
	 * the store's column width and de-duplicated.
	 *
	 * @param array $fields Submitted field map.
	 * @return array<string,string[]>
	 */
	protected static function accepted_terms( array $fields ) {
		$terms = array();

		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( ! array_key_exists( $taxonomy, $fields ) ) {
				continue;
			}

			$terms[ $taxonomy ] = array();

			foreach ( self::to_list( $fields[ $taxonomy ] ) as $term ) {
				$term = self::truncate( self::sanitise_text( $term ), self::TERM_LIMIT );

				if ( '' !== $term && ! in_array( $term, $terms[ $taxonomy ], true ) ) {
					$terms[ $taxonomy ][] = $term;
				}
			}
		}

		return $terms;
	}

	/**
	 * A submitted value as a list of non-empty entries.
	 *
	 * A scalar is a single-entry list: splitting a delimited string is the
	 * intake endpoint's job, done once for every sender before the field map
	 * reaches here. Empty entries are dropped, because a form padding a
	 * multi-value field with blanks has not submitted those values.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<int,mixed>
	 */
	protected static function to_list( $value ) {
		$items = is_array( $value ) ? $value : array( $value );
		$list  = array();

		foreach ( $items as $item ) {
			if ( ! self::is_empty( $item ) ) {
				$list[] = $item;
			}
		}

		return $list;
	}

	/**
	 * Strip HTML tags and trim (Requirements 3.9, 3.18).
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	protected static function sanitise_text( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = (string) $value;

		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return trim( wp_strip_all_tags( $text ) );
		}

		// Same shape as wp_strip_all_tags(): drop script and style content
		// before dropping tags, so their bodies do not survive as text.
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );

		return trim( strip_tags( (string) $text ) );
	}

	/**
	 * Truncate to a character limit, counting characters rather than bytes.
	 *
	 * Applied last of everything and never a source of failure, so no field
	 * length can reject a submission (Requirement 3.7).
	 *
	 * @param string $text  Sanitised text.
	 * @param int    $limit Character limit; 0 or less leaves the text alone.
	 * @return string
	 */
	protected static function truncate( $text, $limit ) {
		$limit = (int) $limit;

		if ( $limit <= 0 ) {
			return $text;
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );

		if ( $length <= $limit ) {
			return $text;
		}

		$clipped = function_exists( 'mb_substr' )
			? mb_substr( $text, 0, $limit, 'UTF-8' )
			: substr( $text, 0, $limit );

		// Trimming a cut that landed on whitespace only shortens the value, so
		// the limit still holds.
		return trim( $clipped );
	}

	/**
	 * WordPress email validation, with a standalone fallback.
	 *
	 * @param string $email Sanitised email value.
	 * @return bool
	 */
	protected static function is_valid_email( $email ) {
		if ( function_exists( 'is_email' ) ) {
			return (bool) is_email( $email );
		}

		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * A submitted `total_guests` value as an integer, or null when it is not a whole number.
	 *
	 * A negative or zero value parses successfully and then fails the range
	 * rule, which keeps "that is not a number" and "that number is out of
	 * range" as separate answers.
	 *
	 * @param mixed $value Submitted value.
	 * @return int|null
	 */
	protected static function parse_guests( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) ) {
			return floor( $value ) === $value ? (int) $value : null;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$text = trim( $value );

		return preg_match( '/^[+-]?\d+$/', $text ) ? (int) $text : null;
	}

	/**
	 * A submitted candidate date as `Y-m-d`, or null when it does not parse.
	 *
	 * The accepted formats are tried first and must round-trip, which rejects an
	 * out-of-range day such as `2025-02-30` that a lenient parser would roll
	 * forward. Anything else has to carry a four-digit year to be considered a
	 * calendar date at all, so "16 August 2025" parses while "16" and
	 * "next tuesday" do not.
	 *
	 * Failing the round-trip is not on its own a rejection, because a value can
	 * name a real date in a spelling no listed format matches. So the looser
	 * parse applies the existence check itself rather than relying on the loop
	 * above having caught it: where the value names a year, a month and a day
	 * outright, that day has to exist in that month. Without it `2026-02-29`
	 * would be accepted and stored as 1 March — a day the enquirer never chose
	 * (Requirement 3.6).
	 *
	 * Public rather than protected: `IntakeEndpoint::days_between()` parses a
	 * configured start/end date pair through the same rule this class applies
	 * to every other candidate date, so a value accepted as one end of a range
	 * is a value `accepted_dates()` would accept on its own (Task 5 of the
	 * candidate-dates-as-range change).
	 *
	 * @param mixed $value Submitted date value.
	 * @return string|null
	 */
	public static function parse_date( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$text = trim( (string) $value );

		if ( '' === $text ) {
			return null;
		}

		foreach ( self::DATE_FORMATS as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $text );

			if ( false !== $date && $date->format( $format ) === $text ) {
				return $date->format( 'Y-m-d' );
			}
		}

		if ( ! preg_match( '/\d{4}/', $text ) ) {
			return null;
		}

		try {
			$date = new \DateTimeImmutable( $text );
		} catch ( \Exception $e ) {
			return null;
		}

		if ( ! self::names_an_existing_day( $text ) ) {
			return null;
		}

		return $date->format( 'Y-m-d' );
	}

	/**
	 * Whether a value that parsed leniently names a day that actually exists.
	 *
	 * The lenient parser accepts an out-of-range day and rolls it forward, so
	 * `2025-04-31` comes back as 1 May. The parsed components are checked against
	 * the calendar to catch that.
	 *
	 * Only a value naming all three of a year, a month and a day is judged here.
	 * A value leaving one of them to the parser to fill in — "August 2025" — names
	 * no day that could fail to exist, so it is left to the lenient parse, which
	 * is the behaviour it already had.
	 *
	 * @param string $text Trimmed submitted value.
	 * @return bool
	 */
	protected static function names_an_existing_day( $text ) {
		$parts = date_parse( $text );

		if ( ! is_array( $parts ) ) {
			return true;
		}

		foreach ( array( 'year', 'month', 'day' ) as $part ) {
			if ( ! isset( $parts[ $part ] ) || ! is_int( $parts[ $part ] ) ) {
				return true;
			}
		}

		return checkdate( $parts['month'], $parts['day'], $parts['year'] );
	}

	/**
	 * Case-folded form of a value, for vocabulary comparison.
	 *
	 * @param string $value Value to fold.
	 * @return string
	 */
	protected static function fold( $value ) {
		$value = trim( (string) $value );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
