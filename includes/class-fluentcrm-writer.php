<?php
/**
 * Single shared write path into FluentCRM Pro.
 *
 * Every source (WPBS, email, web form) funnels through FluentCrmWriter so that
 * subscriber creation, source tagging, and activity logging are consistent.
 *
 * NOTE ON NAMING: the build brief referenced `SevaEnquiryHub\FluentCrmWriter`.
 * This plugin is "Marthrown Enquiry Hub", so the namespace here is
 * `MarthrownEnquiryHub`. If the canonical namespace should be `SevaEnquiryHub`,
 * rename the namespace across the includes and the bootstrap references.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FluentCrmWriter
 */
class FluentCrmWriter {

	/**
	 * Meta key used to store the latest enquiry note on a subscriber.
	 *
	 * Stored via SubscriberMeta with object_type 'custom_field'.
	 */
	const NOTE_META_KEY = 'meh_latest_enquiry';

	/**
	 * Tag applied to records created in staging, for easy bulk removal.
	 */
	const STAGING_TAG = 'test-record';

	/**
	 * Find-or-create a FluentCRM subscriber, tag it by source, and log the
	 * enquiry as an activity/note.
	 *
	 * @param string $email   Contact email (required).
	 * @param string $name    Full name (split into first/last).
	 * @param string $message Enquiry body.
	 * @param string $source  Source key: 'wpbs' | 'email' | 'webform'.
	 * @return int|\WP_Error Subscriber ID on success, WP_Error on failure.
	 */
	public static function log_enquiry( $email, $name, $message, $source ) {
		$email  = sanitize_email( (string) $email );
		$source = sanitize_key( (string) $source );

		if ( ! $email || ! is_email( $email ) ) {
			return new \WP_Error( 'meh_invalid_email', __( 'A valid email address is required to log an enquiry.', 'marthrown-enquiry-hub' ) );
		}

		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return new \WP_Error( 'meh_no_fluentcrm', __( 'FluentCRM API is not available.', 'marthrown-enquiry-hub' ) );
		}

		list( $first_name, $last_name ) = self::split_name( $name );

		// In staging, mark records so they can be identified and removed
		// before go-live: prefix the name and add a removal tag.
		$is_staging = function_exists( 'meh_is_staging' ) && meh_is_staging();
		if ( $is_staging ) {
			$prefix     = defined( 'MEH_TEST_PREFIX' ) ? MEH_TEST_PREFIX : 'TEST_';
			$first_name = $prefix . $first_name;
			$message    = $prefix . (string) $message;
		}

		// 1. Find-or-create the subscriber.
		$subscriber = self::find_or_create_subscriber( $email, $first_name, $last_name );
		if ( ! $subscriber || empty( $subscriber->id ) ) {
			return new \WP_Error( 'meh_subscriber_failed', __( 'Could not find or create the FluentCRM subscriber.', 'marthrown-enquiry-hub' ) );
		}

		// 2. Tag the subscriber by source, e.g. source-wpbs.
		self::attach_source_tag( $subscriber, $source );

		// 2b. In staging, tag for bulk removal before go-live.
		if ( $is_staging ) {
			self::attach_tag_by_slug( $subscriber, self::STAGING_TAG, 'Test Record' );
		}

		// 3. Log the enquiry as an activity/note.
		self::log_activity_note( (int) $subscriber->id, $message, $source );

		/**
		 * Fires after an enquiry has been logged to FluentCRM.
		 *
		 * @param int    $subscriber_id FluentCRM subscriber ID.
		 * @param string $source        Source key.
		 * @param string $message       Enquiry body.
		 */
		do_action( 'meh_enquiry_logged', (int) $subscriber->id, $source, $message );

		return (int) $subscriber->id;
	}

	/**
	 * Find-or-create a subscriber by email.
	 *
	 * @param string $email      Email.
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 * @return object|null FluentCRM subscriber model.
	 */
	protected static function find_or_create_subscriber( $email, $first_name, $last_name ) {
		$contacts = FluentCrmApi( 'contacts' );

		$existing = $contacts->getContactByEmail( $email );
		if ( $existing ) {
			return $existing;
		}

		$data = array(
			'email'      => $email,
			'first_name' => sanitize_text_field( $first_name ),
			'last_name'  => sanitize_text_field( $last_name ),
			'status'     => 'subscribed',
		);

		return $contacts->createOrUpdate( array_filter( $data ) );
	}

	/**
	 * Attach a `source-{source}` tag, creating it if needed.
	 *
	 * @param object $subscriber FluentCRM subscriber model.
	 * @param string $source     Source key.
	 */
	protected static function attach_source_tag( $subscriber, $source ) {
		if ( ! $source ) {
			return;
		}
		self::attach_tag_by_slug( $subscriber, 'source-' . $source, 'Source: ' . ucfirst( $source ) );
	}

	/**
	 * Attach a tag by slug, creating it if needed.
	 *
	 * @param object $subscriber FluentCRM subscriber model.
	 * @param string $slug       Tag slug.
	 * @param string $title      Tag title used when creating the tag.
	 */
	protected static function attach_tag_by_slug( $subscriber, $slug, $title ) {
		$slug = sanitize_title( $slug );
		if ( ! $slug ) {
			return;
		}

		$tag = FluentCrmApi( 'tags' )->getInstance()->firstOrCreate(
			array( 'slug' => $slug ),
			array(
				'slug'  => $slug,
				'title' => $title,
			)
		);

		if ( $tag && ! empty( $tag->id ) ) {
			$subscriber->attachTags( array( $tag->id ) );
		}
	}

	/**
	 * Log the enquiry as an activity/note using SubscriberMeta.
	 *
	 * Uses SubscriberMeta::updateOrCreate() with object_type 'custom_field',
	 * per the build brief. Deliberately avoids syncCustomFieldValues() and
	 * update() so the write path is a single, explicit upsert.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $message       Enquiry body.
	 * @param string $source        Source key.
	 */
	protected static function log_activity_note( $subscriber_id, $message, $source ) {
		if ( ! class_exists( '\FluentCrm\App\Models\SubscriberMeta' ) ) {
			return;
		}

		$payload = array(
			'source'  => $source,
			'message' => wp_kses_post( (string) $message ),
			'logged_at' => current_time( 'mysql' ),
		);

		\FluentCrm\App\Models\SubscriberMeta::updateOrCreate(
			array(
				'subscriber_id' => (int) $subscriber_id,
				'object_type'   => 'custom_field',
				'key'           => self::NOTE_META_KEY,
			),
			array(
				'value' => maybe_serialize( $payload ),
			)
		);
	}

	/**
	 * Read the latest logged enquiry note for a subscriber.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return array{source:string,message:string,logged_at:string}|null
	 */
	public static function get_latest_note( $subscriber_id ) {
		if ( ! class_exists( '\FluentCrm\App\Models\SubscriberMeta' ) ) {
			return null;
		}

		$meta = \FluentCrm\App\Models\SubscriberMeta::where( 'subscriber_id', (int) $subscriber_id )
			->where( 'object_type', 'custom_field' )
			->where( 'key', self::NOTE_META_KEY )
			->first();

		if ( ! $meta ) {
			return null;
		}

		$value = maybe_unserialize( $meta->value );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Split a full name into first/last.
	 *
	 * @param string $name Full name.
	 * @return array{0:string,1:string}
	 */
	protected static function split_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return array( '', '' );
		}
		$parts = preg_split( '/\s+/', $name, 2 );
		return array(
			$parts[0],
			isset( $parts[1] ) ? $parts[1] : '',
		);
	}
}
