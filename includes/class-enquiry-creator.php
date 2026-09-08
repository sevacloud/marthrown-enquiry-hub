<?php
/**
 * Enquiry Creator: the one store-then-link sequence every creation path shares.
 *
 * Two callers create enquiries — the Intake Webhook Request handled by
 * `IntakeHandler`, and the manual enquiry creation route handled by
 * `RestEnquiries` — and both of them reach the store through here. Requirement
 * 18 asks a manually created enquiry to behave exactly as a webhook one does:
 * the same resilience ordering, the same staging marking, the same history
 * entry. Writing those three behaviours twice would have them drift apart at
 * the first change, so they exist once, here.
 *
 * Order of work, and the reasons for it:
 *
 * 1. **Resolve** the nine enquiry fields out of the submitted map through
 *    `FieldMapper`, so a webhook payload keyed by a form's own labels and a
 *    manual request keyed by the field names themselves are the same input by
 *    the time anything looks at a value (Requirements 2.7, 2.10, 2.11).
 * 2. **Validate** under the profile the caller named: `PROFILE_WEBHOOK`
 *    requires all nine fields, `PROFILE_MANUAL` requires four
 *    (Requirements 3.14, 3.15, 18.2). The profile is the *only* thing that
 *    differs; every per-field value rule is identical on both paths.
 * 3. **Store** the enquiry row, its candidate dates, its terms and the verbatim
 *    payload snapshot, all-or-nothing (Requirements 2.5, 2.6, 18.10, 18.11).
 * 4. **Record `created` history**, attributed to `$actor` — 0 for the webhook,
 *    the submitting user for a manual request (Requirements 2.13, 18.18).
 * 5. **Link the contact**, last (Requirements 5.1, 5.2, 18.16). The store write
 *    precedes every CRM call, so a CRM fault can only downgrade the enquiry to
 *    `crm_sync_state = pending`, never lose it.
 *
 * What this class deliberately does **not** do:
 *
 * - **No duplicate check and no rate limit.** Both guards absorb a public form
 *   being submitted twice by an impatient visitor, or hammered. A manual request
 *   comes from a named, authenticated staff member who has decided this is a
 *   real enquiry, so a second identical creation inside the window is a
 *   legitimate second enquiry (Requirements 18.12, 18.13, 18.14). Keeping the
 *   guards in `IntakeHandler` is what confines them to intake.
 * - **No rejected intake attempt.** Rejection rows exist to make an unattended,
 *   non-retried webhook recoverable; an authenticated caller gets the failing
 *   field names in a 400 instead (Requirement 18.15). The webhook's rejection
 *   row is `IntakeHandler`'s to write, from the outcome returned here.
 * - **No status code and no `try`/`catch`.** The outcome is reported, not
 *   answered: the REST layer chooses 201 against 400, and `IntakeEndpoint` owns
 *   the throwable catch that answers 500 (Requirement 5.7).
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EnquiryCreator
 */
class EnquiryCreator {

	/**
	 * Status every created enquiry starts at (Requirements 2.1, 18.7).
	 */
	const INITIAL_STATUS = 'new';

	/**
	 * The history entry type a created enquiry appends (Requirements 2.13, 18.18).
	 */
	const HISTORY_TYPE = 'created';

	/**
	 * Action fired once per created enquiry: `( $enquiry_id, $payload )`.
	 */
	const CREATED_ACTION = 'meh_enquiry_created';

	/**
	 * Outcome reason: the submission failed validation (Requirements 3.8, 18.3).
	 */
	const REASON_VALIDATION = 'validation';

	/**
	 * Outcome reason: the store did not accept the enquiry (Requirement 3.13).
	 */
	const REASON_STORAGE = 'storage';

	/**
	 * Create one enquiry from a submitted field map.
	 *
	 * @param array  $fields  Raw submitted field map, keyed however the sender
	 *                        keyed it. Stored verbatim as the Enquiry Payload
	 *                        Snapshot (Requirements 2.6, 18.11).
	 * @param string $profile Validator::PROFILE_WEBHOOK or
	 *                        Validator::PROFILE_MANUAL (Requirement 18.2).
	 * @param string $source  `webhook:{form id}`, `webhook:unidentified` or
	 *                        `manual:{user id}` (Requirements 2.3, 18.8).
	 * @param string $at      Time the request was received, site timezone. Sets
	 *                        all three timestamps (Requirements 2.2, 18.9).
	 * @param int    $actor   History attribution: 0 for the system, the user
	 *                        identifier for a manual request (Requirement 18.18).
	 * @return array{created:bool, enquiry_id:int, reason:string, errors:array<string,string>, crm_sync_state:string}
	 */
	public static function create( array $fields, $profile = Validator::PROFILE_WEBHOOK, $source = '', $at = '', $actor = HistoryRecorder::SYSTEM_ACTOR ) {
		$payload = $fields;
		$source  = is_scalar( $source ) ? trim( (string) $source ) : '';
		$at      = Clock::mysql( $at );
		$actor   = (int) $actor > 0 ? (int) $actor : HistoryRecorder::SYSTEM_ACTOR;

		// Requirements 3.8, 18.3, 18.6: every failing field is named, and nothing
		// is written.
		$checked = Validator::validate( self::resolve( $fields ), $profile, Validator::MODE_FULL );

		if ( empty( $checked['ok'] ) ) {
			return self::outcome( 0, self::REASON_VALIDATION, $checked['errors'], '' );
		}

		$id = EnquiryStore::create(
			self::row( $checked['values'], $source, $at ),
			$checked['ranges'],
			$checked['terms'],
			$payload
		);

		// Requirements 3.13, 5.6: nothing was stored, so nothing is linked and no
		// `crm_sync_state` is written. The payload and the reason go to the error
		// log because the caller may have nowhere else to put them: the manual
		// route writes no rejection row at all (Requirement 18.15), and a store
		// refusing writes may equally refuse the webhook's rejection row.
		if ( is_wp_error( $id ) ) {
			Log::write(
				'create: enquiry not stored',
				array(
					'reason'  => $id->get_error_message(),
					'code'    => $id->get_error_code(),
					'source'  => $source,
					'at'      => $at,
					'payload' => $payload,
				)
			);

			return self::outcome( 0, self::REASON_STORAGE, array( 'store' => $id->get_error_code() ), '' );
		}

		$id = (int) $id;

		// Requirements 2.13, 18.18. Written before the CRM call, so the entry
		// exists whatever FluentCRM does next.
		HistoryRecorder::record(
			$id,
			self::HISTORY_TYPE,
			sprintf( 'Enquiry created from %s.', '' === $source ? 'an unidentified source' : $source ),
			array(
				'source'      => $source,
				'received_at' => $at,
			),
			$actor
		);

		// Requirements 5.1, 5.2, 5.5, 18.16: the enquiry row already exists, so a
		// CRM failure leaves `crm_sync_state` at `pending` and the enquiry itself
		// intact. The linker writes both the identifier and the state, and records
		// its own history entry; all that is wanted back here is which happened.
		$linked = ContactLinker::link( $id, $actor );

		$crm_sync_state = is_wp_error( $linked )
			? ContactLinker::STATE_PENDING
			: ContactLinker::STATE_SYNCED;

		// Fired once per created enquiry, whichever caller invoked it, and fired
		// last so a listener sees the finished enquiry: dates, terms, history and
		// CRM state all settled.
		if ( function_exists( 'do_action' ) ) {
			do_action( self::CREATED_ACTION, $id, $payload );
		}

		return self::outcome( $id, '', array(), $crm_sync_state );
	}

	/**
	 * The nine enquiry fields a submission supplied.
	 *
	 * A field is present in the result only where `FieldMapper` resolved a value
	 * for it, so "not submitted" stays distinguishable from "submitted empty" and
	 * the Validator's presence check keeps its meaning (Requirement 2.11). A
	 * manual request keyed by the field names themselves resolves through the
	 * exact-key match, so the same resolution serves both callers without either
	 * needing configuration.
	 *
	 * @param array $fields Raw submitted field map.
	 * @return array<string,mixed>
	 */
	protected static function resolve( array $fields ) {
		$submitted = array();

		foreach ( FieldMapper::FIELDS as $field ) {
			$value = FieldMapper::resolve( $fields, $field );

			if ( null !== $value ) {
				$submitted[ $field ] = $value;
			}
		}

		return $submitted;
	}

	/**
	 * The enquiry row to write, from the accepted values.
	 *
	 * `status`, the three timestamps, `source` and `is_test` are set here rather
	 * than taken from the submission: none of them is the submitter's to decide
	 * (Requirements 2.1, 2.2, 18.7, 18.9, 18.19, 18.20). `crm_sync_state` is
	 * deliberately absent — `ContactLinker` is its only writer, so an enquiry
	 * that has not reached the linker yet holds the column default rather than a
	 * state no CRM call has earned.
	 *
	 * @param array  $values Accepted scalar values from the Validator.
	 * @param string $source Resolved source.
	 * @param string $at     Receipt time as a MySQL `DATETIME`, site timezone.
	 * @return array
	 */
	protected static function row( array $values, $source, $at ) {
		return array_merge(
			$values,
			array(
				'status'            => self::INITIAL_STATUS,
				'created_at'        => $at,
				'updated_at'        => $at,
				'status_changed_at' => $at,
				'source'            => $source,
				'is_test'           => StagingMarker::is_staging() ? 1 : 0,
			)
		);
	}

	/**
	 * Shape one outcome for the caller.
	 *
	 * `reason` is what lets `IntakeHandler` write the right rejection reason
	 * without inspecting the error map, and it is empty on success. The manual
	 * route ignores it: a non-empty `errors` map is all a 400 needs.
	 *
	 * @param int    $id             Created enquiry identifier, 0 when none was created.
	 * @param string $reason         self::REASON_VALIDATION, self::REASON_STORAGE, or ''.
	 * @param array  $errors         Per-field failures.
	 * @param string $crm_sync_state `synced`, `pending`, or '' when nothing was linked.
	 * @return array{created:bool, enquiry_id:int, reason:string, errors:array<string,string>, crm_sync_state:string}
	 */
	protected static function outcome( $id, $reason, array $errors, $crm_sync_state ) {
		return array(
			'created'        => (int) $id > 0,
			'enquiry_id'     => (int) $id,
			'reason'         => (string) $reason,
			'errors'         => $errors,
			'crm_sync_state' => (string) $crm_sync_state,
		);
	}
}
