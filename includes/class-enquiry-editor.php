<?php
/**
 * Enquiry Editor: the one correction path for stored enquiry field values.
 *
 * Requirement 19 asks for a narrow thing — let a misheard email address or a
 * revised guest count be corrected without losing the enquiry, its history or
 * its payload snapshot — and the narrowness is the design. Nine fields are
 * editable: the six scalars, the candidate dates and the two multi-selects
 * (Requirement 19.1). Everything else about an enquiry is somebody else's to
 * write: `status` and `status_changed_at` are `Lifecycle`'s, `booking_id` is
 * `BookingCreator`'s, `fluentcrm_subscriber_id` and `crm_sync_state` are
 * `ContactLinker`'s, and `created_at`, `source`, `is_test` and the payload
 * snapshot are nobody's after creation (Requirements 19.9, 19.11).
 *
 * Order of work, and the reasons for it:
 *
 * 1. **Guard.** `RestEnquiries::guard_writable()` first — 404 for an unknown
 *    identifier, 409 for a closed enquiry — before anything is read and before
 *    anything is validated, so a closed enquiry is answered 409 whether the
 *    submitted body would have validated or not (Requirements 9.1, 19.12).
 * 2. **Validate** the fields the request carried, under the Manual Validation
 *    Profile in partial mode: an omitted required field is simply not being
 *    changed, while a required field submitted empty is a failure
 *    (Requirements 19.3, 19.4). Every failing field is named in one 400 and
 *    nothing is written (Requirement 19.5). Accepted values arrive HTML-stripped
 *    and truncated (Requirement 19.6).
 * 3. **Store**, partially and transactionally, through `EnquiryStore::update()`
 *    (Requirements 19.7, 19.9).
 * 4. **Decide from `changed`.** Three decisions key off the one map the store
 *    returned: `updated_at` only when it is non-empty (Requirement 19.8), one
 *    `fields_edited` history entry carrying that map and the acting user
 *    (Requirement 19.13), and `ContactLinker::relink()` (Requirements
 *    19.14–19.17). An empty map means the submission matched what was already
 *    stored, so all three are skipped and the edit writes nothing at all:
 *    `updated_at` untouched, no history entry, no FluentCRM call
 *    (Requirement 19.18).
 *
 * Two things this class deliberately does not do:
 *
 * - **It never reads `source`.** A webhook enquiry, a manually created one and
 *   a migrated one are equally editable, and the way to guarantee that is for
 *   nothing on this path to be able to tell them apart (Requirement 19.2).
 * - **It never touches an existing history entry.** `HistoryRecorder` exposes
 *   no update and no delete, so appending is the only thing available here
 *   (Requirement 19.10).
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EnquiryEditor
 */
class EnquiryEditor {

	/**
	 * The history entry type an applied edit appends (Requirement 19.13).
	 */
	const HISTORY_TYPE = 'fields_edited';

	/**
	 * Error code answered with 400 when a submitted value fails validation.
	 *
	 * The error data carries `fields`, a field => code map naming every failing
	 * field rather than the first one, so one rejected submission reports all of
	 * its problems (Requirement 19.5).
	 */
	const INVALID_CODE = 'meh_enquiry_invalid_fields';

	/**
	 * The nine fields an edit may carry (Requirement 19.1).
	 *
	 * Anything else in the submitted map is ignored here rather than passed on:
	 * the store's own whitelist would refuse it anyway, but dropping it before
	 * the validator keeps "the fields present in the request" meaning the fields
	 * an edit is allowed to name.
	 *
	 * @var string[]
	 */
	const EDITABLE_FIELDS = array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'total_guests',
		'message',
		'selected_dates',
		'event_type',
		'site_exclusivity',
	);

	/**
	 * Apply one correction to a stored enquiry.
	 *
	 * @param int   $enquiry_id Enquiry to correct.
	 * @param array $fields     Submitted field map, keyed by enquiry field name.
	 *                          Only the keys present are considered, so a field
	 *                          absent from the map keeps its stored value
	 *                          (Requirement 19.7).
	 * @param int   $actor      Identifier of the WordPress user that submitted
	 *                          the request, for the history entry
	 *                          (Requirement 19.13).
	 * @return array{changed:array<string,array{from:mixed,to:mixed}>, crm_sync_state:string}|\WP_Error
	 *         `changed` names every field whose stored value changed, with its
	 *         previous and new value, and is empty for a submission that changed
	 *         nothing. `crm_sync_state` is the state the enquiry holds after the
	 *         edit, which is the state it held before wherever no linked field
	 *         changed (Requirement 19.17). A `WP_Error` carries the HTTP status
	 *         in its data: 404, 409 or 400.
	 */
	public static function apply( $enquiry_id, array $fields, $actor = HistoryRecorder::SYSTEM_ACTOR ) {
		$enquiry_id = (int) $enquiry_id;
		$actor      = (int) $actor > 0 ? (int) $actor : HistoryRecorder::SYSTEM_ACTOR;

		// Requirements 9.1, 19.12: before anything is read and before anything is
		// validated, so a closed enquiry cannot be answered 400 by a body that
		// would also have failed.
		$enquiry = RestEnquiries::guard_writable( $enquiry_id );

		if ( is_wp_error( $enquiry ) ) {
			return $enquiry;
		}

		$submitted = self::submitted( $fields );

		// Requirements 19.3, 19.4, 19.5: MODE_PARTIAL is what makes an omitted
		// field "not being changed" while a submitted-but-empty required field
		// still fails.
		$checked = Validator::validate( $submitted, Validator::PROFILE_MANUAL, Validator::MODE_PARTIAL );

		if ( empty( $checked['ok'] ) ) {
			return self::error(
				self::INVALID_CODE,
				__( 'One or more submitted values could not be accepted.', 'marthrown-enquiry-hub' ),
				400,
				array( 'fields' => (array) $checked['errors'] )
			);
		}

		$at      = Clock::mysql();
		$updated = EnquiryStore::update(
			$enquiry_id,
			(array) $checked['values'],
			self::dates( $submitted, $checked ),
			self::terms( $checked )
		);

		// The store rolled back, so every stored value of the enquiry is as it
		// was (Requirements 19.4, 19.5).
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$changed = isset( $updated['changed'] ) ? (array) $updated['changed'] : array();
		$stored  = isset( $enquiry['crm_sync_state'] ) ? (string) $enquiry['crm_sync_state'] : '';

		// Requirement 19.18: the submission matched what was already stored, so
		// there is no timestamp to move, nothing to record and nothing to tell
		// FluentCRM.
		if ( array() === $changed ) {
			return self::outcome( array(), $stored );
		}

		self::touch( $enquiry_id, $at );

		// Requirement 19.13. Written before the CRM call, so the entry exists
		// whatever FluentCRM does next, and holding the store's own `changed` map
		// so it can neither name a field that did not change nor omit one that
		// did.
		HistoryRecorder::record(
			$enquiry_id,
			self::HISTORY_TYPE,
			sprintf( 'Fields corrected: %s.', implode( ', ', array_keys( $changed ) ) ),
			$changed,
			$actor
		);

		// Requirements 19.14–19.17: the linker decides from the `changed` map
		// whether the CRM hears about this at all, and writes both
		// `fluentcrm_subscriber_id` and `crm_sync_state` itself.
		$linked = ContactLinker::relink( $enquiry_id, $changed, $actor );

		return self::outcome( $changed, self::crm_sync_state( $linked, $stored ) );
	}

	/**
	 * The editable fields a submission carried.
	 *
	 * Presence is `array_key_exists()` rather than a truth test, so a field
	 * submitted empty stays distinguishable from a field left alone: the first is
	 * a 400 for a required field and a cleared value for an optional one, the
	 * second is not being changed at all (Requirements 19.4, 19.7).
	 *
	 * @param array $fields Submitted field map.
	 * @return array<string,mixed>
	 */
	protected static function submitted( array $fields ) {
		$submitted = array();

		foreach ( self::EDITABLE_FIELDS as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$submitted[ $field ] = $fields[ $field ];
			}
		}

		return $submitted;
	}

	/**
	 * The replacement candidate-date set, or null when the request left it alone.
	 *
	 * `null` is the store's "not submitted"; an array replaces the set wholesale.
	 * A submitted-but-empty set never reaches here — it is a presence failure
	 * under both profiles (Requirement 19.4).
	 *
	 * @param array $submitted Editable fields the request carried.
	 * @param array $checked   Validator result.
	 * @return array|null
	 */
	protected static function dates( array $submitted, array $checked ) {
		if ( ! array_key_exists( 'selected_dates', $submitted ) ) {
			return null;
		}

		return isset( $checked['dates'] ) ? (array) $checked['dates'] : array();
	}

	/**
	 * The replacement term sets, or null when the request named no taxonomy.
	 *
	 * The Validator returns a taxonomy only where the submission carried it, so
	 * this map already says "replace these, leave the others" without any
	 * further filtering. An empty map means neither multi-select was submitted.
	 *
	 * @param array $checked Validator result.
	 * @return array<string,array>|null
	 */
	protected static function terms( array $checked ) {
		$terms = isset( $checked['terms'] ) ? (array) $checked['terms'] : array();

		return array() === $terms ? null : $terms;
	}

	/**
	 * Set `updated_at` to the time the request was received (Requirement 19.8).
	 *
	 * Reached only when something actually changed, which is what keeps a
	 * no-change submission from moving the timestamp (Requirement 19.18).
	 *
	 * A failure here is logged and does not fail the edit: the corrected values
	 * are already stored and the history entry is still worth writing, so
	 * reporting an error for an applied edit would be the less honest answer.
	 *
	 * @param int    $enquiry_id Enquiry that was corrected.
	 * @param string $at         Receipt time as a MySQL `DATETIME`, site timezone.
	 * @return void
	 */
	protected static function touch( $enquiry_id, $at ) {
		$written = EnquiryStore::update_fields( $enquiry_id, array( 'updated_at' => $at ) );

		if ( is_wp_error( $written ) ) {
			Log::write(
				'edit: updated_at not written',
				array(
					'enquiry_id' => (int) $enquiry_id,
					'at'         => (string) $at,
					'error'      => $written->get_error_message(),
				)
			);
		}
	}

	/**
	 * The `crm_sync_state` the enquiry holds after the re-link.
	 *
	 * Three outcomes, one for each thing `relink()` can return: `null` means no
	 * linked field changed, so no FluentCRM call was made and the stored state is
	 * untouched (Requirement 19.17); a `WP_Error` means the upsert failed, and
	 * the linker has already written `pending` (Requirement 19.16); an array
	 * means it succeeded, and the linker has already written `synced`
	 * (Requirement 19.15).
	 *
	 * @param array|\WP_Error|null $linked Result of `ContactLinker::relink()`.
	 * @param string               $stored State the enquiry held before the edit.
	 * @return string
	 */
	protected static function crm_sync_state( $linked, $stored ) {
		if ( null === $linked ) {
			return (string) $stored;
		}

		return is_wp_error( $linked ) ? ContactLinker::STATE_PENDING : ContactLinker::STATE_SYNCED;
	}

	/**
	 * Shape one outcome for the caller.
	 *
	 * @param array  $changed        The store's `changed` map.
	 * @param string $crm_sync_state State the enquiry holds after the edit.
	 * @return array{changed:array<string,array{from:mixed,to:mixed}>, crm_sync_state:string}
	 */
	protected static function outcome( array $changed, $crm_sync_state ) {
		return array(
			'changed'        => $changed,
			'crm_sync_state' => (string) $crm_sync_state,
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
