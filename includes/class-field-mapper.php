<?php
/**
 * Field mapping between a submitted webhook payload and the nine enquiry fields.
 *
 * The plugin depends on no form plugin's internal field naming. An Intake
 * Webhook Request arrives as a flat map of submitted keys (or labels) to
 * values, and this class answers one question per enquiry field: which
 * submitted value supplies it?
 *
 * Resolution order for a given enquiry field (Requirements 2.7, 2.10):
 *
 * 1. The payload key configured for that field in the `meh_field_map` Settings
 *    control, matched exactly and then, failing that, ignoring case and
 *    separators.
 * 2. A case-insensitive, separator-insensitive match of a submitted label
 *    against the enquiry field name itself, so `first_name` is supplied by
 *    "First Name", "first name" or "FIRST_NAME" with nothing configured.
 *
 * When neither step resolves a value, resolution returns null. Nothing here
 * treats that as an error: an unresolved field is simply absent from the field
 * map handed to the Validator, which reports it as a presence failure when the
 * applied profile requires it (Requirement 2.11).
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FieldMapper
 */
class FieldMapper {

	/**
	 * Option holding the configured mapping: enquiry field => payload field key.
	 */
	const OPTION = 'meh_field_map';

	/**
	 * The nine enquiry fields a submission can supply.
	 *
	 * Also the whitelist mapping() filters the stored option against, so a
	 * stale or hand-edited option cannot introduce a tenth field.
	 */
	const FIELDS = array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'total_guests',
		'selected_dates',
		'event_type',
		'site_exclusivity',
		'message',
	);

	/**
	 * The configured mapping, enquiry field => payload field key.
	 *
	 * Only the nine recognised enquiry fields are returned, and only where the
	 * configured key is a non-empty string, so an unset control is absent from
	 * the result rather than present with an empty value. That is what makes
	 * "unset" a single condition for resolve() to test (Requirement 2.10).
	 *
	 * @return array<string,string>
	 */
	public static function mapping() {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();

		if ( function_exists( 'apply_filters' ) ) {
			$stored = apply_filters( self::OPTION, $stored );
		}

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$mapping = array();

		foreach ( self::FIELDS as $field ) {
			if ( ! isset( $stored[ $field ] ) || ! is_scalar( $stored[ $field ] ) ) {
				continue;
			}

			$key = trim( (string) $stored[ $field ] );

			if ( '' !== $key ) {
				$mapping[ $field ] = $key;
			}
		}

		return $mapping;
	}

	/**
	 * The submitted value supplying a given enquiry field, or null.
	 *
	 * A configured key that is present in the submission wins outright, even
	 * where the value it holds is empty: the configuration is an explicit
	 * statement about where the value comes from, and emptiness is the
	 * Validator's business, not this class's. Label matching is reached only
	 * when the configured key resolves nothing at all, which covers both the
	 * unset control of Requirement 2.10 and a control naming a key the sender
	 * did not include.
	 *
	 * @param array  $submitted     Flat map of submitted key/label => value.
	 * @param string $enquiry_field Enquiry field to resolve, e.g. 'first_name'.
	 * @return mixed|null The submitted value, or null when unresolved.
	 */
	public static function resolve( array $submitted, $enquiry_field ) {
		$enquiry_field = (string) $enquiry_field;

		if ( '' === $enquiry_field || array() === $submitted ) {
			return null;
		}

		$mapping = self::mapping();

		if ( isset( $mapping[ $enquiry_field ] ) ) {
			$configured = self::lookup( $submitted, $mapping[ $enquiry_field ] );

			if ( null !== $configured ) {
				return $configured[0];
			}
		}

		$labelled = self::lookup( $submitted, $enquiry_field );

		return null === $labelled ? null : $labelled[0];
	}

	/**
	 * Find a value in the submitted map by key, exactly and then loosely.
	 *
	 * The return is a single-element array wrapping the value, or null when
	 * there is no match, so that a resolved value of null, '' or 0 is
	 * distinguishable from "no such key".
	 *
	 * @param array  $submitted Flat map of submitted key/label => value.
	 * @param string $wanted    Key or label to find.
	 * @return array|null
	 */
	protected static function lookup( array $submitted, $wanted ) {
		if ( array_key_exists( $wanted, $submitted ) ) {
			return array( $submitted[ $wanted ] );
		}

		$target = self::canonical( $wanted );

		if ( '' === $target ) {
			return null;
		}

		// Submission order decides between two labels that canonicalise alike,
		// so the same payload always resolves to the same value.
		foreach ( $submitted as $key => $value ) {
			if ( self::canonical( $key ) === $target ) {
				return array( $value );
			}
		}

		return null;
	}

	/**
	 * Canonical form of a key or label: lower case, alphanumerics only.
	 *
	 * Dropping every non-alphanumeric character is what makes the match
	 * separator-insensitive as well as case-insensitive, so "First Name",
	 * "first-name", "FIRST_NAME" and "firstName" all canonicalise to
	 * "firstname" (Requirement 2.10).
	 *
	 * @param mixed $key Key or label to canonicalise.
	 * @return string
	 */
	protected static function canonical( $key ) {
		if ( ! is_scalar( $key ) ) {
			return '';
		}

		$key = preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $key ) );

		return null === $key ? '' : $key;
	}
}

