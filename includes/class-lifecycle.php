<?php
/**
 * Enquiry lifecycle.
 *
 * The single writer of `status` and `status_changed_at` (Requirement 7.6). Every
 * status change in the plugin comes through `transition()` — a manual change
 * from the hub, a conversion writing `converted`, the auto-closure job writing
 * `closed` — so every one of them gets the same table check, the same history
 * entry and the same hook, and no caller can invent a state machine of its own.
 *
 * Three constants carry the whole of Requirement 7:
 *
 * - `STATUSES` is exactly the six recognised statuses (Requirement 7.1), and it
 *   is what `EnquiryQuery::statuses()` reads, so the list filter and the
 *   lifecycle cannot disagree about what a status is.
 * - `TRANSITIONS` is the forward-only table (Requirements 7.2, 7.3, 7.4, 7.10).
 *   No entry names a status appearing earlier in the order the constant declares
 *   them in, so no enquiry can return to a status it has left. `closed` maps to
 *   the empty array, which is the whole of what makes `closed` terminal: a
 *   request from `closed` is refused by the same lookup that refuses any other
 *   unpermitted pair, with no special case (Requirement 7.11).
 * - `SETTLED` is `converted` and `lost` only, and `is_settled()` is its single
 *   reader. `quoted` sits inside the lifecycle but outside `SETTLED`: the team
 *   has sent a quote and is waiting, which is the state most in need of
 *   attention, not least. So an enquiry holding `new`, `contacted` or `quoted`
 *   is never settled however long it sits (Requirement 7.12), and
 *   `EnquiryStore::settled_before()` derives its status list from this same
 *   constant so the auto-closure job cannot hold a different opinion.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lifecycle
 */
class Lifecycle {

	/**
	 * The six recognised statuses, in lifecycle order (Requirement 7.1).
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'new', 'contacted', 'quoted', 'converted', 'lost', 'closed' );

	/**
	 * Permitted transitions, current status => statuses it may move to.
	 *
	 * @var array<string,string[]>
	 */
	const TRANSITIONS = array(
		'new'       => array( 'contacted', 'quoted', 'converted', 'lost' ),
		'contacted' => array( 'quoted', 'converted', 'lost' ),
		'quoted'    => array( 'converted', 'lost' ),
		'converted' => array( 'closed' ),
		'lost'      => array( 'closed' ),
		'closed'    => array(),
	);

	/**
	 * Settled Enquiry: the only statuses the auto-close job considers.
	 *
	 * @var string[]
	 */
	const SETTLED = array( 'converted', 'lost' );

	/**
	 * The terminal status: `TRANSITIONS` gives it nowhere to go, and nothing else
	 * in the table leads anywhere but here from a settled status.
	 */
	const CLOSED = 'closed';

	/**
	 * The history entry type a status change appends.
	 */
	const HISTORY_TYPE = 'status_changed';

	/**
	 * The action fired after a status change (Requirement 7.8).
	 */
	const CHANGED_HOOK = 'meh_enquiry_status_changed';

	/**
	 * The statuses an enquiry holding a given status may move to.
	 *
	 * An unrecognised status answers with the empty array, the same answer
	 * `closed` gives, so a stored value nothing recognises is as immovable as a
	 * closed enquiry rather than being movable anywhere.
	 *
	 * @param string $status Current status.
	 * @return string[]
	 */
	public static function allowed_from( $status ) {
		$status = (string) $status;

		return isset( self::TRANSITIONS[ $status ] ) ? self::TRANSITIONS[ $status ] : array();
	}

	/**
	 * Whether one status may move to another (Requirement 7.5).
	 *
	 * @param string $from Current status.
	 * @param string $to   Requested status.
	 * @return bool
	 */
	public static function can( $from, $to ) {
		return in_array( (string) $to, self::allowed_from( $from ), true );
	}

	/**
	 * Whether a status is a Settled Enquiry (Requirement 7.12).
	 *
	 * The single reader of self::SETTLED.
	 *
	 * @param string $status Status to test.
	 * @return bool
	 */
	public static function is_settled( $status ) {
		return in_array( (string) $status, self::SETTLED, true );
	}

	/**
	 * Whether a value is one of the six recognised statuses.
	 *
	 * @param string $status Candidate status.
	 * @return bool
	 */
	public static function is_status( $status ) {
		return in_array( (string) $status, self::STATUSES, true );
	}

	/**
	 * The status each of several enquiries held immediately before it was closed.
	 *
	 * `closed` says an enquiry is finished but not how it finished, and the two
	 * ways it can finish — won or lost — are the distinction anyone reading a
	 * closed enquiry actually wants. This recovers it rather than storing it a
	 * second time: the outcome is already in the trail, as the `from` of the
	 * `status_changed` entry that recorded the closure, so a stored column would
	 * be a duplicate that could disagree with the history beside it.
	 *
	 * Reading the *latest* `status_changed` entry is sound because `closed` is
	 * terminal: `TRANSITIONS` gives it nowhere to go, so once an enquiry is closed
	 * its closure is necessarily its last status change. An enquiry whose last
	 * change was to anything else is not closed, and is absent from the result —
	 * which is also the answer for an enquiry closed before this trail existed,
	 * and for one that reached `closed` through a direct write that recorded
	 * nothing.
	 *
	 * @param int[] $enquiry_ids Enquiries to resolve.
	 * @return array<int,string> The status held before closing, keyed by enquiry
	 *                           identifier. Only closed enquiries appear.
	 */
	public static function closed_from_many( array $enquiry_ids ) {
		$outcomes = array();
		$contexts = HistoryRecorder::latest_context_many( $enquiry_ids, self::HISTORY_TYPE );

		foreach ( $contexts as $enquiry_id => $context ) {
			$to   = isset( $context['to'] ) ? (string) $context['to'] : '';
			$from = isset( $context['from'] ) ? (string) $context['from'] : '';

			if ( self::CLOSED === $to && '' !== $from ) {
				$outcomes[ (int) $enquiry_id ] = $from;
			}
		}

		return $outcomes;
	}

	/**
	 * The status one enquiry held immediately before it was closed.
	 *
	 * @param int $enquiry_id Enquiry to resolve.
	 * @return string The status held before closing, or `''` when the enquiry is
	 *                not closed or its closure was never recorded.
	 */
	public static function closed_from( $enquiry_id ) {
		$enquiry_id = (int) $enquiry_id;
		$outcomes   = self::closed_from_many( array( $enquiry_id ) );

		return isset( $outcomes[ $enquiry_id ] ) ? $outcomes[ $enquiry_id ] : '';
	}

	/**
	 * Move one enquiry to a new status.
	 *
	 * Idempotent by design: an enquiry already holding the requested status is
	 * reported as a success that wrote nothing, so a repeated request leaves
	 * `status_changed_at` where it was and appends no second history entry
	 * (Requirement 7.9). That is checked before the transition table, because
	 * `closed` → `closed` is a repeat rather than a rejected move.
	 *
	 * On a real change the write comes first, then the history entry, then the
	 * hook: a listener therefore always observes the new status as stored, and a
	 * failed write produces no entry and no hook at all (Requirement 7.5's
	 * "changing nothing" applies equally to a refusal and to a fault).
	 *
	 * @param int      $enquiry_id Enquiry to move.
	 * @param string   $to         Requested status.
	 * @param int|null $actor      Acting user identifier. Null resolves the
	 *                             current user, which is 0 when none is
	 *                             authenticated — the system attribution the
	 *                             auto-closure job relies on.
	 * @return array{id:int,from:string,to:string,changed:bool,at:string}|\WP_Error
	 */
	public static function transition( $enquiry_id, $to, $actor = null ) {
		$enquiry_id = (int) $enquiry_id;
		$to         = (string) $to;

		if ( $enquiry_id <= 0 ) {
			return self::error( 'meh_lifecycle_invalid_id', 'An enquiry identifier is required.', 400 );
		}

		if ( ! self::is_status( $to ) ) {
			return self::error(
				'meh_lifecycle_unknown_status',
				sprintf( 'Status "%s" is not a recognised enquiry status.', $to ),
				400
			);
		}

		$enquiry = EnquiryStore::find( $enquiry_id );

		if ( null === $enquiry ) {
			return self::error( 'meh_lifecycle_not_found', 'The enquiry does not exist.', 404 );
		}

		$from = isset( $enquiry['status'] ) ? (string) $enquiry['status'] : '';

		// Requirement 7.9: already there, so nothing is written and nothing is
		// recorded, and the caller still sees a success.
		if ( $from === $to ) {
			return array(
				'id'      => $enquiry_id,
				'from'    => $from,
				'to'      => $to,
				'changed' => false,
				'at'      => isset( $enquiry['status_changed_at'] ) ? (string) $enquiry['status_changed_at'] : '',
			);
		}

		if ( ! self::can( $from, $to ) ) {
			return self::error(
				'meh_lifecycle_transition_not_allowed',
				sprintf( 'An enquiry with status "%s" cannot move to status "%s".', $from, $to ),
				409,
				array(
					'from' => $from,
					'to'   => $to,
				)
			);
		}

		$at      = Clock::mysql();
		$written = EnquiryStore::update_fields(
			$enquiry_id,
			array(
				'status'            => $to,
				'status_changed_at' => $at,
			)
		);

		if ( is_wp_error( $written ) ) {
			Log::write(
				'lifecycle: status not written',
				array(
					'enquiry_id' => $enquiry_id,
					'from'       => $from,
					'to'         => $to,
					'error'      => $written->get_error_message(),
				)
			);

			return $written;
		}

		HistoryRecorder::record(
			$enquiry_id,
			self::HISTORY_TYPE,
			sprintf( 'Status changed from %s to %s.', $from, $to ),
			array(
				'from' => $from,
				'to'   => $to,
				'at'   => $at,
			),
			$actor
		);

		if ( function_exists( 'do_action' ) ) {
			do_action( self::CHANGED_HOOK, $enquiry_id, $from, $to );
		}

		return array(
			'id'      => $enquiry_id,
			'from'    => $from,
			'to'      => $to,
			'changed' => true,
			'at'      => $at,
		);
	}

	/**
	 * Build a failure carrying an HTTP status, matching the store's shape.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @param array  $detail  Extra error data.
	 * @return \WP_Error
	 */
	protected static function error( $code, $message, $status, array $detail = array() ) {
		return new \WP_Error( $code, $message, array_merge( $detail, array( 'status' => (int) $status ) ) );
	}
}
