<?php
/**
 * Enquiry taxonomy vocabularies: `event_type` and `site_exclusivity`.
 *
 * `Validator::allowed_terms()` ships no vocabulary of its own — a site
 * supplies one through the `meh_enquiry_terms_{taxonomy}` filter, and an
 * unconfigured taxonomy is unconstrained rather than empty. This class is one
 * concrete supplier, wired to both filters on `init()`, so the plugin has a
 * working default instead of leaving every install to configure its own filter
 * before either dropdown means anything.
 *
 * The two taxonomies are deliberately not symmetric:
 *
 * - `event_type` is configurable in Settings. The list stored there was asked
 *   for as a stand-in for reading the vocabulary from ACF's Event Fields group;
 *   no ACF field name or key was available to wire to, so a plugin setting
 *   fills the same role — administrator-editable, without code — and is easy
 *   to point at ACF later by replacing `event_types()`'s option read with an
 *   ACF read, or by removing this filter entirely once a site defines its own.
 * - `site_exclusivity` is fixed: Full Site, Top Site, None. Nothing about it
 *   was asked to be configurable, so it is not — a shorter, constant list is
 *   less for an administrator to accidentally break.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EnquiryTaxonomies
 */
class EnquiryTaxonomies {

	/**
	 * Option holding the configured Event Type list, one value per line.
	 */
	const EVENT_TYPE_OPTION = 'meh_event_types';

	/**
	 * Event Type default, used until Settings holds something else and by
	 * "Reset to defaults" there.
	 *
	 * @var string[]
	 */
	const EVENT_TYPE_DEFAULTS = array( 'Wedding', 'Retreat', 'Mini Festival', 'Corporate', 'Party' );

	/**
	 * Site Exclusivity's fixed vocabulary. Not stored as an option: nothing
	 * asked for this one to be editable, and a hard-coded list is one fewer
	 * setting an administrator can empty by mistake and one fewer place this
	 * and the Validator's own copy could drift apart, because there is only
	 * the one copy.
	 *
	 * @var string[]
	 */
	const SITE_EXCLUSIVITY = array( 'Full Site', 'Top Site', 'None' );

	/**
	 * Wire both taxonomy vocabularies to `Validator::allowed_terms()`.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'meh_enquiry_terms_event_type', array( __CLASS__, 'event_types' ) );
		add_filter( 'meh_enquiry_terms_site_exclusivity', array( __CLASS__, 'site_exclusivity' ) );
	}

	/**
	 * The configured Event Type list, falling back to the defaults.
	 *
	 * Deferring to a non-empty `$terms` rather than overwriting it unconditionally
	 * is what makes this filter callback composable with another one on the same
	 * hook: `apply_filters()` runs every registered callback in registration
	 * order, each fed the previous one's return value, so were this the later of
	 * two registrations it would otherwise clobber whatever the earlier one
	 * supplied regardless of what it was.
	 *
	 * @param string[] $terms Vocabulary as the filter received it so far.
	 * @return string[]
	 */
	public static function event_types( $terms = array() ) {
		if ( is_array( $terms ) && array() !== $terms ) {
			return $terms;
		}

		return self::sanitise_list( get_option( self::EVENT_TYPE_OPTION, self::EVENT_TYPE_DEFAULTS ) );
	}

	/**
	 * Site Exclusivity's fixed vocabulary.
	 *
	 * @param string[] $terms Vocabulary as the filter received it so far.
	 * @return string[]
	 */
	public static function site_exclusivity( $terms = array() ) {
		if ( is_array( $terms ) && array() !== $terms ) {
			return $terms;
		}

		return self::SITE_EXCLUSIVITY;
	}

	/**
	 * The configured Event Type list as Settings stores and edits it: one
	 * value per line, defaults included until something is saved.
	 *
	 * @return string[]
	 */
	public static function configured_event_types() {
		return self::sanitise_list( get_option( self::EVENT_TYPE_OPTION, self::EVENT_TYPE_DEFAULTS ) );
	}

	/**
	 * Parse a submitted Event Type textarea into a clean, ordered, de-duplicated
	 * list, ready to store.
	 *
	 * Blank lines are dropped rather than stored as an empty term — an empty
	 * term is a value nothing could ever match, so it can only ever be dead
	 * weight in the dropdown.
	 *
	 * @param mixed $raw Newline-delimited text, or an array of terms.
	 * @return string[]
	 */
	public static function sanitise_list( $raw ) {
		$lines = is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', (string) $raw );
		$out   = array();

		foreach ( (array) $lines as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}

			$term = trim( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $line ) : (string) $line );

			if ( '' !== $term && ! in_array( $term, $out, true ) ) {
				$out[] = $term;
			}
		}

		return $out;
	}
}
