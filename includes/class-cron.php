<?php
/**
 * WP-Cron scheduling.
 *
 * Registers a single recurring event every 15 minutes to poll the mailbox via
 * Microsoft Graph. Bookings are read from WPBS live (not synced), so there is
 * no WPBS cron.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cron
 */
class Cron {

	const HOOK_EMAIL = 'meh_cron_email_poll';
	const HOOK_WPBS  = 'meh_cron_wpbs_poll'; // Legacy; cleared on deactivation.
	const SCHEDULE   = 'meh_every_15_minutes';

	/**
	 * Wire cron hooks and custom schedules.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedules' ) );
		add_action( self::HOOK_EMAIL, array( __CLASS__, 'run_email_poll' ) );

		// Retire the legacy WPBS sync event if it lingers from an older version.
		if ( wp_next_scheduled( self::HOOK_WPBS ) ) {
			wp_clear_scheduled_hook( self::HOOK_WPBS );
		}

		if ( ! wp_next_scheduled( self::HOOK_EMAIL ) ) {
			self::schedule_events();
		}
	}

	/**
	 * Add a 15-minute schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function register_schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Marthrown Enquiry Hub)', 'marthrown-enquiry-hub' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the recurring event. Called on activation.
	 */
	public static function schedule_events() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedules' ) );

		if ( ! wp_next_scheduled( self::HOOK_EMAIL ) ) {
			wp_schedule_event( time() + 120, self::SCHEDULE, self::HOOK_EMAIL );
		}
	}

	/**
	 * Clear scheduled events. Called on deactivation.
	 */
	public static function clear_events() {
		wp_clear_scheduled_hook( self::HOOK_EMAIL );
		wp_clear_scheduled_hook( self::HOOK_WPBS );
	}

	/**
	 * Cron callback: poll the mailbox via Graph.
	 */
	public static function run_email_poll() {
		if ( class_exists( __NAMESPACE__ . '\\SourceEmail' ) ) {
			SourceEmail::poll();
		}
	}
}
