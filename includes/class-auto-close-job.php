<?php
/**
 * Automatic closure of settled enquiries.
 *
 * The daily scheduled task of Requirement 8, and the only component in the
 * plugin that closes an enquiry because an interval has elapsed
 * (Requirement 8.4). Everything else that writes `closed` does so because a
 * person asked for it.
 *
 * Three deliberate choices:
 *
 * - **It closes through `Lifecycle::transition()`, never by writing rows.**
 *   The lifecycle stays the single writer of `status` and `status_changed_at`,
 *   so an auto-closure gets the same transition check, the same
 *   `status_changed` entry and the same `meh_enquiry_status_changed` hook as a
 *   manual change, and nothing downstream has to care which one happened.
 * - **It asks `EnquiryStore::settled_before()` what is due**, and that read
 *   derives its status list from `Lifecycle::SETTLED`, so the job and the
 *   lifecycle cannot hold different opinions about what settled means. An
 *   enquiry holding `new`, `contacted` or `quoted` is therefore never closed
 *   here however long it has sat (Requirement 7.12).
 * - **The cutoff is strict.** An enquiry that has held `converted` or `lost` for
 *   exactly the interval stays open until the next run (Requirements 8.6, 8.7);
 *   only a dwell of strictly more than the interval closes it (Requirement 8.3).
 *
 * A second run with no intervening status change closes nothing, because a
 * closed enquiry is no longer settled and so is no longer selected
 * (Requirement 8.9). Work is done in batches and an individual failure is logged
 * and stepped over, so one bad row cannot stall the whole run; the count
 * returned reflects successful closures only.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AutoCloseJob
 */
class AutoCloseJob {

	/**
	 * The cron hook the daily event fires (Requirement 8.1).
	 */
	const HOOK = 'meh_cron_auto_close';

	/**
	 * The WP-Cron recurrence of that event.
	 */
	const RECURRENCE = 'daily';

	/**
	 * Filter exposing the closure interval, in days (Requirement 8.10).
	 */
	const INTERVAL_FILTER = 'meh_auto_close_days';

	/**
	 * Closure interval default, in days (Requirement 8.10).
	 */
	const DEFAULT_DAYS = 7;

	/**
	 * Enquiries transitioned per batch.
	 */
	const BATCH = 50;

	/**
	 * The status a due enquiry is moved to.
	 */
	const TARGET_STATUS = 'closed';

	/**
	 * The history entry type an automatic closure appends (Requirement 8.5).
	 */
	const HISTORY_TYPE = 'auto_closed';

	/**
	 * Bind the job to its cron hook.
	 *
	 * Scheduling is not done here: `schedule()` belongs to activation, so a
	 * plugin load never creates an event as a side effect of registering the
	 * listener.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Register the single daily event (Requirement 8.1).
	 *
	 * Guarded by `wp_next_scheduled()`, so activating the plugin over an already
	 * scheduled event leaves the one event in place rather than adding a second.
	 *
	 * @return bool Whether an event is scheduled when this returns.
	 */
	public static function schedule() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return false;
		}

		if ( wp_next_scheduled( self::HOOK ) ) {
			return true;
		}

		$scheduled = wp_schedule_event( Clock::timestamp(), self::RECURRENCE, self::HOOK );

		if ( false === $scheduled ) {
			Log::write( 'auto-close: daily event could not be scheduled' );

			return false;
		}

		return true;
	}

	/**
	 * Clear the daily event (Requirement 8.2).
	 *
	 * Safe to call when nothing is scheduled.
	 *
	 * @return int Events cleared.
	 */
	public static function unschedule() {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return 0;
		}

		$cleared = wp_clear_scheduled_hook( self::HOOK );

		return is_numeric( $cleared ) ? (int) $cleared : 0;
	}

	/**
	 * Whether the daily event is currently scheduled.
	 *
	 * @return bool
	 */
	public static function is_scheduled() {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return false;
		}

		return false !== wp_next_scheduled( self::HOOK );
	}

	/**
	 * The closure interval in days (Requirement 8.10).
	 *
	 * A filter returning a non-numeric or negative value falls back to the
	 * default, so a mistaken filter cannot turn the job into one that closes
	 * enquiries settled in the future. Zero is honoured: it means "close as soon
	 * as any measurable time has passed", which is the only sensible reading of
	 * an interval of no days.
	 *
	 * @return int Days, never negative.
	 */
	public static function days() {
		$days = self::DEFAULT_DAYS;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the automatic closure interval, in days.
			 *
			 * @param int $days Defaults to self::DEFAULT_DAYS.
			 */
			$days = apply_filters( self::INTERVAL_FILTER, $days );
		}

		if ( ! is_numeric( $days ) ) {
			Log::write( 'auto-close: non-numeric interval filtered, using the default' );

			return self::DEFAULT_DAYS;
		}

		$days = (int) $days;

		if ( $days < 0 ) {
			Log::write( sprintf( 'auto-close: negative interval %d filtered, using the default', $days ) );

			return self::DEFAULT_DAYS;
		}

		return $days;
	}

	/**
	 * The settlement time an enquiry must predate to be closed.
	 *
	 * Computed in the site timezone with a calendar-day interval rather than a
	 * multiple of 86,400 seconds, so a daylight-saving change inside the window
	 * does not move the boundary by an hour. `EnquiryStore::settled_before()`
	 * compares strictly, so an enquiry settled exactly on this instant is not
	 * yet due (Requirements 8.6, 8.7).
	 *
	 * @param \DateTimeInterface|int|string|null $at   Run time. Null means "now".
	 * @param int|null                           $days Interval to subtract. Null
	 *        reads self::days(), so a caller that has already resolved the
	 *        interval does not make the filter run a second time.
	 * @return string MySQL `DATETIME` in the site timezone.
	 */
	public static function cutoff( $at = null, $days = null ) {
		$run    = Clock::at( $at );
		$days   = null === $days ? self::days() : max( 0, (int) $days );
		$cutoff = $run->modify( sprintf( '-%d days', $days ) );

		if ( ! $cutoff instanceof \DateTimeInterface ) {
			// Unreachable for a whole number of days; a fallback rather than a
			// run that silently closes nothing.
			$cutoff = Clock::offset( -1 * $days * 86400, $run );
		}

		return $cutoff->format( Clock::MYSQL_FORMAT );
	}

	/**
	 * Close every settled enquiry whose dwell exceeds the interval.
	 *
	 * @param \DateTimeInterface|int|string|null $at Run time. Null means "now".
	 * @return int Enquiries closed. Zero when nothing was due (Requirement 8.8).
	 */
	public static function run( $at = null ) {
		// The interval is resolved once per run, so the filter cannot return a
		// different value part-way through and cannot log a bad value per row.
		$days   = self::days();
		$cutoff = self::cutoff( $at, $days );
		$due    = EnquiryStore::settled_before( $cutoff );

		if ( ! $due ) {
			return 0;
		}

		$closed  = 0;
		$failed  = 0;
		$skipped = 0;

		foreach ( array_chunk( $due, self::BATCH ) as $batch ) {
			foreach ( $batch as $enquiry_id ) {
				$outcome = self::close( (int) $enquiry_id, $cutoff, $days );

				if ( true === $outcome ) {
					++$closed;
					continue;
				}

				if ( null === $outcome ) {
					// Already closed, so nothing was written; not a failure.
					++$skipped;
					continue;
				}

				++$failed;
			}
		}

		Log::write(
			'auto-close: run complete',
			array(
				'cutoff'  => $cutoff,
				'days'    => $days,
				'due'     => count( $due ),
				'closed'  => $closed,
				'skipped' => $skipped,
				'failed'  => $failed,
			)
		);

		return $closed;
	}

	/**
	 * Close one enquiry, attributing the change to the system.
	 *
	 * The actor is passed explicitly rather than left to resolve from the
	 * current user, because a cron run triggered by a page load from a logged-in
	 * administrator would otherwise be attributed to them (Requirements 8.5, 11.5).
	 *
	 * The `auto_closed` entry is appended only after the transition succeeded, so
	 * the trail never claims a closure that did not happen. It sits alongside the
	 * `status_changed` entry the lifecycle records, which is what distinguishes
	 * an elapsed-interval closure from a hand-made one.
	 *
	 * @param int      $enquiry_id Enquiry to close.
	 * @param string   $cutoff     Cutoff the run used, recorded as context.
	 * @param int|null $days       Interval the run used. Null reads self::days().
	 * @return bool|null True when this call closed the enquiry, null when the
	 *                   enquiry already held the status and nothing was written,
	 *                   false when the transition failed.
	 */
	protected static function close( $enquiry_id, $cutoff, $days = null ) {
		$result = Lifecycle::transition( $enquiry_id, self::TARGET_STATUS, HistoryRecorder::SYSTEM_ACTOR );

		if ( is_wp_error( $result ) ) {
			// Logged and stepped over: one unclosable enquiry must not stall the
			// rest of the run.
			Log::write(
				'auto-close: enquiry not closed',
				array(
					'enquiry_id' => $enquiry_id,
					'error'      => $result->get_error_message(),
				)
			);

			return false;
		}

		if ( empty( $result['changed'] ) ) {
			// Already closed, so nothing was written and nothing is recorded.
			// Not a failure: a second run over the same enquiry is a no-op
			// (Requirement 8.9).
			return null;
		}

		$from = isset( $result['from'] ) ? (string) $result['from'] : '';
		$days = null === $days ? self::days() : max( 0, (int) $days );
		$held = '' !== $from ? $from : 'a settled status';

		HistoryRecorder::record(
			$enquiry_id,
			self::HISTORY_TYPE,
			sprintf( 'Closed automatically after %d days in %s.', $days, $held ),
			array(
				'from'   => $from,
				'to'     => self::TARGET_STATUS,
				'days'   => $days,
				'cutoff' => (string) $cutoff,
				'at'     => isset( $result['at'] ) ? (string) $result['at'] : '',
			),
			HistoryRecorder::SYSTEM_ACTOR
		);

		return true;
	}
}
