<?php
/**
 * Cron wiring for automatic closure (Requirements 8.1, 8.2).
 *
 * One daily event, `meh_cron_auto_close`, and nothing else. The two hooks from
 * the email-polling era, `meh_cron_email_poll` and `meh_cron_wpbs_poll`, are
 * gone for good, so the assertions here cover both halves of the contract: the
 * event that must exist after activation, and the events that must not exist
 * after either activation or deactivation.
 *
 * Scheduling is asserted twice over: directly through `AutoCloseJob::schedule()`
 * and `AutoCloseJob::unschedule()`, and through the `meh_activate()` and
 * `meh_deactivate()` hooks that call them, which is where the plugin's cron
 * ownership actually shows. Activation leaves exactly one plugin cron event,
 * `meh_cron_auto_close`; deactivation leaves none.
 *
 * No enquiry tables are needed: nothing here runs the job, only the scheduling
 * around it.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\AutoCloseJob;
use MarthrownEnquiryHub\Clock;

/**
 * Class CronWiringTest
 */
class CronWiringTest extends WP_UnitTestCase {

	/**
	 * Hooks retired with the email-polling era, which must stay cleared.
	 */
	const LEGACY_HOOKS = array( 'meh_cron_email_poll', 'meh_cron_wpbs_poll' );

	/**
	 * Frozen run time the scheduling tests activate at.
	 */
	const RUN_AT = '2025-07-04 14:30:00';

	/**
	 * Load the classes under test.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-clock.php';
		require_once MEH_INCLUDES_DIR . 'class-auto-close-job.php';
	}

	public function set_up() {
		parent::set_up();

		$this->clear_all();
	}

	public function tear_down() {
		$this->clear_all();
		Clock::unfreeze();

		parent::tear_down();
	}

	/**
	 * Requirement 8.1: a single daily event, registered at the current time.
	 *
	 * @return void
	 */
	public function test_scheduling_registers_one_daily_event() {
		Clock::freeze( self::RUN_AT );

		$this->assertTrue( AutoCloseJob::schedule() );

		$this->assertSame( 'meh_cron_auto_close', AutoCloseJob::HOOK );
		$this->assertTrue( AutoCloseJob::is_scheduled() );
		$this->assertSame( 1, $this->event_count( AutoCloseJob::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( AutoCloseJob::HOOK ) );
		$this->assertSame( Clock::timestamp(), wp_next_scheduled( AutoCloseJob::HOOK ) );
	}

	/**
	 * Requirement 8.1: activating over an already scheduled event leaves the one
	 * event in place rather than adding a second.
	 *
	 * @return void
	 */
	public function test_scheduling_twice_leaves_exactly_one_event() {
		Clock::freeze( self::RUN_AT );
		$this->assertTrue( AutoCloseJob::schedule() );

		$first = wp_next_scheduled( AutoCloseJob::HOOK );

		// A later activation must not move the event or duplicate it.
		Clock::freeze( '2025-07-09 09:00:00' );
		$this->assertTrue( AutoCloseJob::schedule() );

		$this->assertSame( 1, $this->event_count( AutoCloseJob::HOOK ) );
		$this->assertSame( $first, wp_next_scheduled( AutoCloseJob::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( AutoCloseJob::HOOK ) );
	}

	/**
	 * Requirement 8.2: unscheduling clears the event.
	 *
	 * @return void
	 */
	public function test_unscheduling_clears_the_event() {
		Clock::freeze( self::RUN_AT );
		$this->assertTrue( AutoCloseJob::schedule() );

		$this->assertSame( 1, AutoCloseJob::unschedule() );

		$this->assertFalse( AutoCloseJob::is_scheduled() );
		$this->assertSame( 0, $this->event_count( AutoCloseJob::HOOK ) );
		$this->assertFalse( wp_next_scheduled( AutoCloseJob::HOOK ) );
	}

	/**
	 * Requirement 8.2: clearing when nothing is scheduled is a no-op, not an
	 * error, so a second deactivation cannot fail.
	 *
	 * @return void
	 */
	public function test_unscheduling_nothing_is_harmless() {
		$this->assertFalse( AutoCloseJob::is_scheduled() );
		$this->assertSame( 0, AutoCloseJob::unschedule() );
		$this->assertSame( 0, $this->event_count( AutoCloseJob::HOOK ) );
	}

	/**
	 * Requirement 8.1: activation schedules the daily auto-closure event, and the
	 * retired hooks stay cleared through it.
	 *
	 * `meh_cron_auto_close` is asserted to be the only plugin cron event
	 * activation leaves behind, so nothing from the polling era can come back
	 * under a new name.
	 *
	 * @return void
	 */
	public function test_activation_schedules_the_daily_event_and_clears_the_legacy_hooks() {
		$this->schedule_legacy_hooks();

		meh_activate();

		foreach ( self::LEGACY_HOOKS as $hook ) {
			$this->assertSame( 0, $this->event_count( $hook ), $hook . ' should be cleared by activation.' );
			$this->assertFalse( wp_next_scheduled( $hook ) );
		}

		$this->assertSame(
			array( AutoCloseJob::HOOK ),
			$this->scheduled_plugin_hooks(),
			'Activation should schedule the daily auto-closure event and no other plugin event.'
		);

		$this->assertSame( 1, $this->event_count( AutoCloseJob::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( AutoCloseJob::HOOK ) );
	}

	/**
	 * Requirement 8.2: deactivation clears the auto-closure event alongside the
	 * retired hooks, leaving the plugin owning no cron event at all.
	 *
	 * @return void
	 */
	public function test_deactivation_clears_the_daily_event_and_the_legacy_hooks() {
		Clock::freeze( self::RUN_AT );
		$this->assertTrue( AutoCloseJob::schedule() );
		$this->schedule_legacy_hooks();

		meh_deactivate();

		foreach ( self::LEGACY_HOOKS as $hook ) {
			$this->assertSame( 0, $this->event_count( $hook ), $hook . ' should be cleared by deactivation.' );
			$this->assertFalse( wp_next_scheduled( $hook ) );
		}

		$this->assertFalse( AutoCloseJob::is_scheduled() );
		$this->assertSame( array(), $this->scheduled_plugin_hooks() );
	}

	/**
	 * The legacy clearer touches the legacy hooks only, so it cannot be relied on
	 * to remove the auto-closure event: `AutoCloseJob::unschedule()` is what
	 * deactivation must call for that (Requirement 8.2).
	 *
	 * @return void
	 */
	public function test_the_legacy_clearer_and_the_job_own_different_hooks() {
		Clock::freeze( self::RUN_AT );
		$this->assertTrue( AutoCloseJob::schedule() );
		$this->schedule_legacy_hooks();

		meh_clear_legacy_cron();

		foreach ( self::LEGACY_HOOKS as $hook ) {
			$this->assertSame( 0, $this->event_count( $hook ) );
		}

		$this->assertSame(
			1,
			$this->event_count( AutoCloseJob::HOOK ),
			'The legacy clearer should leave the auto-closure event to the job.'
		);

		$this->assertSame( 1, AutoCloseJob::unschedule() );
		$this->assertSame( array(), $this->scheduled_plugin_hooks() );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Number of scheduled events registered against one hook.
	 *
	 * Counted from the cron array rather than inferred from
	 * `wp_next_scheduled()`, which cannot tell one event from several.
	 *
	 * @param string $hook Cron hook.
	 * @return int
	 */
	private function event_count( $hook ) {
		$cron  = _get_cron_array();
		$count = 0;

		if ( ! is_array( $cron ) ) {
			return 0;
		}

		foreach ( $cron as $events ) {
			if ( isset( $events[ $hook ] ) && is_array( $events[ $hook ] ) ) {
				$count += count( $events[ $hook ] );
			}
		}

		return $count;
	}

	/**
	 * Every scheduled cron hook belonging to this plugin.
	 *
	 * @return array Hook names, sorted.
	 */
	private function scheduled_plugin_hooks() {
		$cron  = _get_cron_array();
		$hooks = array();

		if ( is_array( $cron ) ) {
			foreach ( $cron as $events ) {
				foreach ( array_keys( (array) $events ) as $hook ) {
					if ( 0 === strpos( (string) $hook, 'meh_' ) ) {
						$hooks[] = (string) $hook;
					}
				}
			}
		}

		$hooks = array_values( array_unique( $hooks ) );
		sort( $hooks );

		return $hooks;
	}

	/**
	 * Put both retired hooks back on the schedule, as an upgrade from an earlier
	 * version of the plugin would leave them.
	 *
	 * @return void
	 */
	private function schedule_legacy_hooks() {
		foreach ( self::LEGACY_HOOKS as $hook ) {
			wp_schedule_event( time() + 3600, 'hourly', $hook );

			$this->assertSame( 1, $this->event_count( $hook ), 'The fixture should schedule ' . $hook . '.' );
		}
	}

	/**
	 * Clear every hook this test touches.
	 *
	 * @return void
	 */
	private function clear_all() {
		$hooks = self::LEGACY_HOOKS;

		$hooks[] = AutoCloseJob::HOOK;

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
