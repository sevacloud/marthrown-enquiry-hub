<?php
/**
 * Intake Handler: one authenticated Intake Webhook Request becomes one Enquiry.
 *
 * Called by `IntakeEndpoint` once the request has authenticated against the
 * Intake Secret, and by nothing else. It binds no form hook and registers no
 * `init()` of its own: the plugin depends on no form plugin's in-process
 * submission hook, so there is nothing for this class to hook into
 * (Requirement 2.12).
 *
 * What is left in here is only the work that is webhook-specific. Everything
 * from validation onward belongs to `EnquiryCreator`, the single store-then-link
 * sequence both creation routes share (Requirement 18.12), so this class:
 *
 * 1. **Rate limits by submitted email** (Requirement 4.6). First, because the
 *    guard has to count every authenticated request holding that address,
 *    including the ones that would go on to fail validation. A burst of
 *    malformed resubmissions is exactly what the limit is for.
 * 2. **Checks for a duplicate** — email plus the exact candidate date set inside
 *    the window (Requirements 4.2, 4.3). Before validation, so an accidental
 *    resubmission of a good submission never reaches the store.
 * 3. **Names the Webhook Validation Profile**, which requires all nine fields,
 *    and derives `source` from the form identifier the endpoint resolved
 *    (Requirements 2.3, 2.4, 2.11, 3.14).
 * 4. **Hands the raw payload to `EnquiryCreator::create()`** with actor 0: no
 *    WordPress user sent this request. The store write, the `created` history
 *    entry, `ContactLinker::link()` and `meh_enquiry_created` all happen there
 *    and only there, so this class performs no enquiry insert of its own
 *    (Requirements 2.1, 18.12).
 * 5. **Writes the one rejection row** on every outcome other than a created
 *    enquiry (Requirements 4.1, 5.8).
 *
 * The guards read `email` and `selected_dates` out of a `FieldMapper` resolution
 * of the payload, because both run before anything else has looked at a value.
 * `EnquiryCreator` resolves the raw map again for itself, which is why the raw
 * payload rather than the resolved fields is what gets passed on: the snapshot
 * has to be what arrived (Requirement 2.6).
 *
 * There is no spam check. A webhook body carries no spam verdict — the form
 * plugin made that judgement before it sent the request — so that branch does
 * not exist and `spam` is not a rejection reason.
 *
 * Every outcome other than a created enquiry writes exactly one rejected intake
 * attempt, carrying the payload, the receipt time and a reason of `duplicate`,
 * `rate_limited`, `validation` or `storage`, and creates no enquiry
 * (Requirements 3.8, 4.1, 4.3, 5.8). That row is the recovery path: the sending
 * form does not retry, so a genuine enquiry rejected by either guard or by
 * validation has to stay findable.
 *
 * `receive()` reports which outcome happened rather than choosing a status code
 * or catching anything. The endpoint owns both: it decides 201 against 200, and
 * it owns the `try`/`catch ( \Throwable )` that answers 500 (Requirement 5.7).
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IntakeHandler
 */
class IntakeHandler {

	/**
	 * `source` when the payload carried no form identifier (Requirement 2.4).
	 */
	const SOURCE_UNIDENTIFIED = 'webhook:unidentified';

	/**
	 * The history entry type a created enquiry appends (Requirement 2.13).
	 *
	 * Aliased from the shared creation path, which is what writes it, so the two
	 * cannot come to disagree about the name.
	 */
	const HISTORY_TYPE = EnquiryCreator::HISTORY_TYPE;

	/**
	 * Rejection reason: the submission repeats a recent one (Requirement 4.3).
	 */
	const REASON_DUPLICATE = 'duplicate';

	/**
	 * Rejection reason: too many requests for one email address (Requirement 4.6).
	 */
	const REASON_RATE_LIMITED = 'rate_limited';

	/**
	 * Rejection reason: the submission failed validation (Requirement 3.8).
	 */
	const REASON_VALIDATION = EnquiryCreator::REASON_VALIDATION;

	/**
	 * Rejection reason: the store did not accept the enquiry (Requirement 3.13).
	 */
	const REASON_STORAGE = EnquiryCreator::REASON_STORAGE;

	/**
	 * Create one Enquiry from an authenticated Intake Webhook Request.
	 *
	 * @param array  $fields      Normalised payload: submitted key or label =>
	 *                            value, as `IntakeEndpoint::normalise()` returns
	 *                            it. Passed on unchanged, so the Enquiry Payload
	 *                            Snapshot is what arrived (Requirement 2.6).
	 * @param string $source      Resolved `source`: `webhook:{form id}`, or
	 *                            empty for self::SOURCE_UNIDENTIFIED
	 *                            (Requirements 2.3, 2.4).
	 * @param string $received_at Time the endpoint received the request. Read in
	 *                            the site timezone at whole-second precision
	 *                            (Requirement 2.2).
	 * @return array{created:bool, enquiry_id:int, reason:string, errors:array<string,string>}
	 */
	public static function receive( array $fields, $source = '', $received_at = '' ) {
		$payload = $fields;
		$source  = self::source( $source );
		$at      = Clock::mysql( $received_at );

		// The two guards run before anything else has looked at a value, so they
		// resolve the payload for themselves. `EnquiryCreator` resolves the raw map
		// again; the duplication is a resolution, not a write, and it is what lets
		// the snapshot stay verbatim (Requirements 2.7, 2.10).
		$submitted = self::resolve( $fields );
		$email     = self::email( $submitted );

		// Requirement 4.6. Counted before anything else, so every authenticated
		// request holding this address counts against it.
		if ( DuplicateDetector::is_rate_limited( $email ) ) {
			return self::reject( $payload, self::REASON_RATE_LIMITED, $email, $source, $at );
		}

		// Requirements 4.2, 4.3.
		$duplicate_of = DuplicateDetector::find_duplicate( $email, self::dates( $submitted ) );

		if ( $duplicate_of > 0 ) {
			return self::reject(
				$payload,
				self::REASON_DUPLICATE,
				$email,
				$source,
				$at,
				array(),
				array( 'duplicate_of' => $duplicate_of )
			);
		}

		// Requirements 2.1, 18.12: validation, the store write, the `created`
		// history entry, the contact link and `meh_enquiry_created` all happen in
		// the shared path. Actor 0: no WordPress user sent this request.
		$outcome = EnquiryCreator::create(
			$payload,
			Validator::PROFILE_WEBHOOK,
			$source,
			$at,
			HistoryRecorder::SYSTEM_ACTOR
		);

		// Requirements 3.8, 3.13, 5.8: the shared path created nothing, so the one
		// rejection row this request earns is written here, under the reason it
		// reported. The store failure is already logged there.
		if ( empty( $outcome['created'] ) ) {
			return self::reject(
				$payload,
				$outcome['reason'],
				$email,
				$source,
				$at,
				$outcome['errors']
			);
		}

		return array(
			'created'    => true,
			'enquiry_id' => (int) $outcome['enquiry_id'],
			'reason'     => '',
			'errors'     => array(),
		);
	}

	/**
	 * The `source` value to store (Requirements 2.3, 2.4).
	 *
	 * The endpoint resolves the form identifier from the payload; an empty
	 * result means the payload carried none, which is the fixed
	 * `webhook:unidentified` value rather than an empty column.
	 *
	 * @param mixed $source Resolved source.
	 * @return string
	 */
	public static function source( $source ) {
		$source = is_scalar( $source ) ? trim( (string) $source ) : '';

		return '' === $source ? self::SOURCE_UNIDENTIFIED : $source;
	}

	/**
	 * The nine enquiry fields a submission supplied, for the guards.
	 *
	 * A field is present in the result only where `FieldMapper` resolved a
	 * value for it, so "not submitted" stays distinguishable from "submitted
	 * empty" (Requirement 2.11).
	 *
	 * @param array $fields Normalised payload.
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
	 * The submitted email address, as the two guards need it.
	 *
	 * Both guards run before validation, so this has to cope with whatever
	 * arrived: a non-scalar value becomes an empty address, which neither guard
	 * buckets or matches on, and which validation then reports moments later.
	 *
	 * @param array $submitted Resolved enquiry fields.
	 * @return string
	 */
	protected static function email( array $submitted ) {
		if ( ! isset( $submitted['email'] ) || ! is_scalar( $submitted['email'] ) ) {
			return '';
		}

		return trim( (string) $submitted['email'] );
	}

	/**
	 * The submitted candidate dates as a list, for the duplicate check.
	 *
	 * Normalisation to `Y-m-d` and the set comparison belong to
	 * `DuplicateDetector`; this only guarantees it a list to work on, because a
	 * sender may deliver a single date as a bare scalar.
	 *
	 * @param array $submitted Resolved enquiry fields.
	 * @return array<int,mixed>
	 */
	protected static function dates( array $submitted ) {
		if ( ! isset( $submitted['selected_dates'] ) ) {
			return array();
		}

		$dates = $submitted['selected_dates'];

		return is_array( $dates ) ? array_values( $dates ) : array( $dates );
	}

	/**
	 * Record one rejected intake attempt and report the outcome.
	 *
	 * Exactly one row per rejected request, carrying the payload verbatim, the
	 * reason, the receipt time, the submitted address, the resolved source and
	 * the staging verdict, so the enquiry can be recreated by hand from the
	 * sending form's own stored entry (Requirements 4.1, 5.8, 17.1).
	 *
	 * @param array  $payload Enquiry Payload Snapshot.
	 * @param string $reason  One of the four rejection reasons.
	 * @param string $email   Submitted email address, where one was resolved.
	 * @param string $source  Resolved source.
	 * @param string $at      Receipt time as a MySQL `DATETIME`.
	 * @param array  $errors  Per-field failures, for a validation rejection.
	 * @param array  $extra   Further detail, e.g. the matched enquiry identifier.
	 * @return array{created:bool, enquiry_id:int, reason:string, errors:array<string,string>}
	 */
	protected static function reject( array $payload, $reason, $email, $source, $at, array $errors = array(), array $extra = array() ) {
		$detail = array_merge(
			array(
				'email'       => $email,
				'source'      => $source,
				'received_at' => $at,
				'is_test'     => StagingMarker::is_staging() ? 1 : 0,
				'errors'      => $errors,
			),
			$extra
		);

		EnquiryStore::record_rejection( $payload, $reason, $detail );

		return array(
			'created'    => false,
			'enquiry_id' => 0,
			'reason'     => (string) $reason,
			'errors'     => $errors,
		);
	}
}
