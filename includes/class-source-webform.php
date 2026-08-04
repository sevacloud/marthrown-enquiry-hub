<?php
/**
 * Source: Web form submissions.
 *
 * Hooks Fluent Forms and the Kadence Form block, extracts name/email/message,
 * and calls FluentCrmWriter::log_enquiry() with source 'webform'.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SourceWebform
 */
class SourceWebform {

	const SOURCE = 'webform';

	/**
	 * Register submission hooks.
	 */
	public static function init() {
		// Fluent Forms: brief specifies fluentform_submission_inserted.
		// Support both the legacy underscore hook and the newer slashed hook.
		add_action( 'fluentform_submission_inserted', array( __CLASS__, 'handle_fluent_forms' ), 20, 3 );
		add_action( 'fluentform/submission_inserted', array( __CLASS__, 'handle_fluent_forms' ), 20, 3 );

		// Kadence Blocks form submission action.
		add_action( 'kadence_blocks_form_submission', array( __CLASS__, 'handle_kadence' ), 20, 3 );
	}

	/**
	 * Handle a Fluent Forms submission.
	 *
	 * @param int    $entry_id  Submission ID.
	 * @param array  $form_data Submitted values.
	 * @param object $form      Form object.
	 */
	public static function handle_fluent_forms( $entry_id, $form_data, $form ) {
		$fields = self::normalize_fields( (array) $form_data );

		if ( empty( $fields['email'] ) ) {
			return;
		}

		FluentCrmWriter::log_enquiry(
			$fields['email'],
			$fields['name'],
			$fields['message'],
			self::SOURCE
		);
	}

	/**
	 * Handle a Kadence Blocks form submission.
	 *
	 * @param array $form_args Form configuration.
	 * @param array $fields    Submitted fields.
	 * @param array $processed Processing result.
	 */
	public static function handle_kadence( $form_args, $fields, $processed ) {
		$normalized = self::normalize_fields( self::flatten_kadence( (array) $fields ) );

		if ( empty( $normalized['email'] ) ) {
			return;
		}

		FluentCrmWriter::log_enquiry(
			$normalized['email'],
			$normalized['name'],
			$normalized['message'],
			self::SOURCE
		);
	}

	/**
	 * Flatten Kadence field structure into a flat key => value map.
	 *
	 * @param array $fields Kadence fields.
	 * @return array
	 */
	protected static function flatten_kadence( array $fields ) {
		$flat = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && isset( $field['label'], $field['value'] ) ) {
				$flat[ sanitize_title( $field['label'] ) ] = $field['value'];
			}
		}
		return $flat;
	}

	/**
	 * Map arbitrary form field keys onto name/email/message.
	 *
	 * @param array $raw Raw submitted fields.
	 * @return array{name:string,email:string,message:string}
	 */
	protected static function normalize_fields( array $raw ) {
		$out = array(
			'name'    => '',
			'email'   => '',
			'message' => '',
		);

		$map = array(
			'email'   => array( 'email', 'email_address', 'your-email' ),
			'name'    => array( 'name', 'full_name', 'your-name', 'first_name', 'fname' ),
			'message' => array( 'message', 'enquiry', 'comments', 'your-message', 'details' ),
		);

		foreach ( $map as $canonical => $candidates ) {
			foreach ( $candidates as $key ) {
				if ( ! empty( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
					$out[ $canonical ] = trim( (string) $raw[ $key ] );
					break;
				}
			}
		}

		// If separate first/last exist, combine into a full name.
		if ( '' === $out['name'] ) {
			$first = isset( $raw['first_name'] ) ? $raw['first_name'] : ( isset( $raw['fname'] ) ? $raw['fname'] : '' );
			$last  = isset( $raw['last_name'] ) ? $raw['last_name'] : ( isset( $raw['lname'] ) ? $raw['lname'] : '' );
			$out['name'] = trim( $first . ' ' . $last );
		}

		return $out;
	}
}
