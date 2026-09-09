<?php
/**
 * Enquiry Store: writes and hydrated reads.
 *
 * The source of record for enquiries. One parent row in
 * `{prefix}meh_enquiries`, one child row per candidate date in
 * `{prefix}meh_enquiry_dates`, one child row per selected multi-select value in
 * `{prefix}meh_enquiry_terms`, and the verbatim submitted payload serialized as
 * JSON into the parent row's `LONGTEXT` column (Requirements 1.1, 1.2, 1.3,
 * 1.7).
 *
 * Three rules shape this class:
 *
 * - **A partial enquiry is never visible.** `create()` writes the parent row
 *   and every child row inside one transaction where the storage engine
 *   supports it, and on any failure rolls back *and* deletes whatever was
 *   written before returning a `WP_Error`. The compensating delete runs in both
 *   cases because it is harmless after a rollback and is the only recovery on a
 *   non-transactional engine (Requirement 3.13).
 * - **Every value is bound, never interpolated.** Table names come from
 *   `Schema::table()` and every other value in every statement travels as a
 *   `$wpdb->prepare()` placeholder or through `$wpdb->insert()`/`update()`,
 *   which prepare internally. No submitted value is concatenated into SQL
 *   (Requirement 3.10).
 * - **What was written is what reads back.** `find()` returns the hydrated
 *   shape in the design: scalars as written, `event_type` and
 *   `site_exclusivity` as arrays, `date_ranges` as a list of `start`/`end`
 *   pairs with the ideal range first, an unsupplied `total_guests` as `null` and
 *   an unsupplied `phone` or `message` as `''`, and `payload` decoded
 *   (Requirements 1.6, 1.8, 1.19).
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EnquiryStore
 */
class EnquiryStore {

	/**
	 * Writable enquiry columns mapped to their `$wpdb` format.
	 *
	 * The map doubles as the whitelist: a key absent from here is never written,
	 * so a caller passing an unknown or misspelled column silently writes
	 * nothing rather than producing a SQL error.
	 *
	 * @var array<string,string>
	 */
	const COLUMNS = array(
		'first_name'              => '%s',
		'last_name'               => '%s',
		'email'                   => '%s',
		'phone'                   => '%s',
		'total_guests'            => '%d',
		'message'                 => '%s',
		'status'                  => '%s',
		'fluentcrm_subscriber_id' => '%d',
		'booking_id'              => '%d',
		'crm_sync_state'          => '%s',
		'created_at'              => '%s',
		'updated_at'              => '%s',
		'status_changed_at'       => '%s',
		'source'                  => '%s',
		'is_test'                 => '%d',
		'duplicated_from_id'      => '%d',
		'duplicated_to_id'        => '%d',
		'payload'                 => '%s',
	);

	/**
	 * Columns whose "not supplied" value is `NULL` rather than `''` or `0`.
	 *
	 * `total_guests` is the reason this list exists: `0` is outside the valid
	 * 1–10000 range, so it is not a guest count, and storing it would be
	 * indistinguishable from a value someone submitted (Requirement 1.19).
	 *
	 * @var string[]
	 */
	const NULLABLE_COLUMNS = array(
		'total_guests',
		'fluentcrm_subscriber_id',
		'booking_id',
		'duplicated_from_id',
		'duplicated_to_id',
	);

	/**
	 * Columns holding a MySQL `DATETIME`.
	 *
	 * @var string[]
	 */
	const DATETIME_COLUMNS = array(
		'created_at',
		'updated_at',
		'status_changed_at',
	);

	/**
	 * Columns `update_fields()` will not write.
	 *
	 * `payload` is the audit trail: it is what arrived, verbatim, and its value
	 * comes entirely from being unalterable, so it is written once by `create()`
	 * and never again (Requirement 19.11).
	 *
	 * @var string[]
	 */
	const IMMUTABLE_COLUMNS = array(
		'id',
		'payload',
	);

	/**
	 * Scalar columns a correction may write (Requirement 19.1).
	 *
	 * The whitelist `update()` filters submitted fields through, and the reason
	 * it needs no separate guard against the columns Requirement 19.9 protects:
	 * a key absent from here is never written, whether it was submitted by
	 * mistake or on purpose.
	 *
	 * @var string[]
	 */
	const EDITABLE_COLUMNS = array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'total_guests',
		'message',
	);

	/**
	 * Columns a correction leaves alone, whatever it was handed (Requirement 19.9).
	 *
	 * Documentation rather than a second gate: `update()` writes only
	 * self::EDITABLE_COLUMNS, so every column here is already unreachable. It is
	 * declared so the guarantee is stated where the code states it, and
	 * `EnquiryStoreUpdateTest` asserts the two lists do not overlap.
	 *
	 * `fluentcrm_subscriber_id` and `crm_sync_state` are absent from both lists:
	 * an edit does not write them, but `ContactLinker::relink()` does after the
	 * edit, through `update_fields()` (Requirements 19.15, 19.16).
	 *
	 * @var string[]
	 */
	const CORRECTION_PROTECTED_COLUMNS = array(
		'status',
		'status_changed_at',
		'created_at',
		'source',
		'is_test',
		'booking_id',
		'payload',
	);

	/**
	 * The two multi-select fields sharing the terms table.
	 *
	 * @var string[]
	 */
	const TAXONOMIES = array(
		'event_type',
		'site_exclusivity',
	);

	/**
	 * The `DATETIME` value MySQL writes when nothing was supplied.
	 */
	const ZERO_DATETIME = '0000-00-00 00:00:00';

	/**
	 * Scalar columns a duplicate copies from its source (Requirement 9.4).
	 *
	 * `status`, `booking_id` and the three timestamps are deliberately absent:
	 * Requirement 9.5 fixes them on the copy rather than carrying them over.
	 * `fluentcrm_subscriber_id` is absent too, because reusing the source's
	 * subscriber id is `ContactLinker`'s decision (Requirement 9.8) and it reads
	 * `duplicated_from_id` to make it — copying the id here would set
	 * `fluentcrm_subscriber_id` on an enquiry that no CRM call has yet touched,
	 * with `crm_sync_state` still empty, which is a state no other path can
	 * produce.
	 *
	 * @var string[]
	 */
	const DUPLICATED_COLUMNS = array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'total_guests',
		'message',
		'source',
		'is_test',
	);

	/**
	 * Reasons a rejected intake attempt is recorded under (Requirement 4.1).
	 *
	 * There is no `spam` reason: a webhook body carries no spam verdict, so that
	 * branch does not exist.
	 *
	 * @var string[]
	 */
	const REJECTION_REASONS = array(
		'duplicate',
		'rate_limited',
		'validation',
		'storage',
	);

	/**
	 * Rejection detail keys that are also columns of the rejections table.
	 *
	 * The caller knows the resolved `source`, the receipt time and the staging
	 * verdict; none of them is in the raw payload. They travel in `$detail`
	 * alongside the per-field failures and are lifted out into their columns so
	 * the rejections list can filter on them.
	 *
	 * @var array<string,string>
	 */
	const REJECTION_DETAIL_COLUMNS = array(
		'email'       => 'email',
		'source'      => 'source',
		'is_test'     => 'is_test',
		'received_at' => 'created_at',
	);

	/**
	 * Statuses counted as settled when `Lifecycle` is not loaded.
	 *
	 * self::settled_statuses() prefers `Lifecycle::SETTLED`, so the auto-closure
	 * job and the lifecycle cannot hold different opinions about what settled
	 * means (Requirement 7.12). `Lifecycle` now ships, so this is the documented
	 * default rather than the operative list: it is what answers on a partial
	 * load, where the store is required but the lifecycle is not, and removing it
	 * would make `settled_before()` return nothing at all in that case instead of
	 * the intended set. `LifecycleTransitionTableTest` asserts the two lists are
	 * identical, so the duplication cannot drift unnoticed.
	 *
	 * @var string[]
	 */
	const SETTLED_STATUSES = array( 'converted', 'lost' );

	/**
	 * Tables holding rows related to one enquiry by `enquiry_id`.
	 *
	 * @var string[]
	 */
	const CHILD_TABLES = array(
		'dates',
		'terms',
		'notes',
		'history',
	);

	/**
	 * Identifiers named per statement when deleting test records.
	 *
	 * An `IN` list is bounded so a staging site holding thousands of test
	 * enquiries does not build one statement longer than `max_allowed_packet`.
	 */
	const DELETE_BATCH = 200;

	/**
	 * Key holding the unfiltered total in a per-status count map.
	 */
	const COUNT_ALL = 'all';

	/**
	 * Whether the enquiry table's storage engine supports transactions.
	 *
	 * Resolved once per request; null until asked.
	 *
	 * @var bool|null
	 */
	protected static $transactional = null;

	/**
	 * Store one enquiry, its candidate date ranges, its terms and its payload.
	 *
	 * All-or-nothing: a failure anywhere leaves no enquiry row, no candidate
	 * date row and no term row behind, and returns a `WP_Error` so the caller
	 * can report that storage did not complete (Requirement 3.13).
	 *
	 * Term sets are stored as sets: duplicate entries collapse to one row,
	 * because two identical terms are one term (Requirement 1.6 compares them as
	 * sets). Candidate date ranges collapse the same way, but keep the order they
	 * arrived in rather than being sorted: the first range is the enquirer's ideal
	 * one and the rest are alternatives, so their order is part of what they mean.
	 *
	 * @param array $enquiry Scalar column values; unknown keys are ignored.
	 * @param array $ranges  Candidate date ranges, each `array{start,end}` or a
	 *                       bare date standing for a single-day range.
	 * @param array $terms   Term lists keyed by taxonomy: `event_type`, `site_exclusivity`.
	 * @param array $payload Enquiry Payload Snapshot, stored as JSON.
	 * @return int|\WP_Error The new enquiry identifier, or a failure.
	 */
	public static function create( array $enquiry, array $ranges = array(), array $terms = array(), array $payload = array() ) {
		global $wpdb;

		// Pre-flight before anything is written, so an unusable candidate date
		// fails without leaving a parent row to clean up.
		$ranges = self::normalise_ranges( $ranges );

		if ( is_wp_error( $ranges ) ) {
			return $ranges;
		}

		$terms = self::normalise_terms( $terms );
		$row   = self::row_for_create( $enquiry, $payload );
		$table = Schema::table( 'enquiries' );

		$started          = self::begin();
		$wpdb->last_error = '';

		$inserted = $wpdb->insert( $table, $row, self::formats_for( $row ) );
		$id       = (int) $wpdb->insert_id;

		if ( false === $inserted || $id <= 0 ) {
			return self::abandon( $started, 0, 'enquiry row', $wpdb->last_error, $payload );
		}

		if ( $ranges && ! self::insert_ranges( $id, $ranges ) ) {
			return self::abandon( $started, $id, 'candidate dates', $wpdb->last_error, $payload );
		}

		foreach ( $terms as $taxonomy => $values ) {
			if ( $values && ! self::insert_terms( $id, $taxonomy, $values ) ) {
				return self::abandon( $started, $id, $taxonomy . ' terms', $wpdb->last_error, $payload );
			}
		}

		if ( ! self::commit( $started ) ) {
			return self::abandon( $started, $id, 'commit', $wpdb->last_error, $payload );
		}

		return $id;
	}

	/**
	 * Write named columns of one enquiry.
	 *
	 * The single-column write primitive behind `booking_id`,
	 * `crm_sync_state`, `fluentcrm_subscriber_id` and the timestamps. It is not
	 * the field-correction path: `update()` owns that, because a correction has
	 * to be partial, transactional across the child rows and has to report what
	 * actually changed.
	 *
	 * @param int   $id     Enquiry identifier.
	 * @param array $fields Column => value; unknown and immutable columns are ignored.
	 * @return true|\WP_Error
	 */
	public static function update_fields( $id, array $fields ) {
		global $wpdb;

		$id = (int) $id;

		if ( $id <= 0 ) {
			return self::error( 'meh_store_invalid_id', 'An enquiry identifier is required.', 400 );
		}

		$data    = array();
		$formats = array();

		foreach ( $fields as $column => $value ) {
			if ( ! isset( self::COLUMNS[ $column ] ) || in_array( $column, self::IMMUTABLE_COLUMNS, true ) ) {
				continue;
			}

			$data[ $column ] = self::coerce( $column, $value );
			$formats[]       = self::COLUMNS[ $column ];
		}

		if ( ! $data ) {
			return self::error( 'meh_store_no_fields', 'No writable enquiry field was supplied.', 400 );
		}

		$wpdb->last_error = '';

		$result = $wpdb->update( Schema::table( 'enquiries' ), $data, array( 'id' => $id ), $formats, array( '%d' ) );

		// `0` is success: it means every submitted value already equalled the
		// stored value, so MySQL changed no row.
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			Log::write(
				'store: update failed',
				array(
					'enquiry_id' => $id,
					'columns'    => array_keys( $data ),
					'error'      => (string) $wpdb->last_error,
				)
			);

			return self::error( 'meh_store_update_failed', 'The enquiry could not be updated.', 500 );
		}

		return true;
	}

	/**
	 * Correct the stored field values of one enquiry.
	 *
	 * The correction primitive behind Requirement 19, and three things separate
	 * it from `update_fields()`:
	 *
	 * - **Partial.** Only the keys present in `$fields` are written; a key absent
	 *   from `$fields` keeps its stored value (Requirement 19.7). `$dates` and
	 *   `$terms` are nullable for the same reason: `null` means "not submitted,
	 *   leave that set alone", an array means "replace the set with exactly
	 *   this". A `null` value for one taxonomy inside `$terms` leaves that
	 *   taxonomy alone while the other is replaced. Both sets are replaced
	 *   wholesale rather than diffed, because neither has any identity of its own
	 *   beyond its membership.
	 * - **Transactional and all-or-nothing.** The scalar write, the date
	 *   replacement and each taxonomy's term replacement run in one transaction.
	 *   Any failure rolls back — or, on a non-transactional engine, restores the
	 *   scalar values and the child rows read before the write — and returns a
	 *   `WP_Error`, so a failed edit leaves every stored value of the enquiry as
	 *   it was (Requirements 19.4, 19.5).
	 * - **Reports what actually changed.** The return value is a `changed` map,
	 *   `field => [ from, to ]`, comparing each submitted value against the
	 *   stored one, with each term list compared as a set and the candidate date
	 *   ranges compared in order.
	 *   Three separate decisions key off it — whether to touch `updated_at`
	 *   (Requirement 19.8), what the `fields_edited` history entry holds
	 *   (Requirement 19.13), and whether the contact needs re-linking
	 *   (Requirement 19.14) — so computing it once here is what keeps them
	 *   consistent.
	 *
	 * Nothing here writes `status`, `status_changed_at`, `created_at`, `source`,
	 * `is_test`, `booking_id` or `payload`: they are not in
	 * self::EDITABLE_COLUMNS, so a caller submitting one writes nothing
	 * (Requirement 19.9). `updated_at` is not written either — the caller sets it
	 * from the request time, and only when `changed` came back non-empty
	 * (Requirement 19.8).
	 *
	 * When every submitted value already equals the stored value, `changed` is
	 * empty and no write is issued at all — not an `UPDATE` setting a column to
	 * its own value — so `updated_at` and every other stored value are untouched
	 * (Requirement 19.18).
	 *
	 * @param int        $id     Enquiry identifier.
	 * @param array      $fields Subset of self::EDITABLE_COLUMNS; other keys are ignored.
	 * @param array|null $ranges Replacement candidate date ranges, or null to leave them alone.
	 * @param array|null $terms  Replacement term sets keyed by taxonomy; a null
	 *                           value, or an absent taxonomy, leaves that set alone.
	 * @return array{changed:array<string,array{from:mixed,to:mixed}>}|\WP_Error
	 */
	public static function update( $id, array $fields, ?array $ranges = null, ?array $terms = null ) {
		global $wpdb;

		$id = (int) $id;

		if ( $id <= 0 ) {
			return self::error( 'meh_store_invalid_id', 'An enquiry identifier is required.', 400 );
		}

		$stored = self::find( $id );

		if ( null === $stored ) {
			return self::error( 'meh_store_not_found', 'The enquiry to correct does not exist.', 404 );
		}

		$changed = array();
		$data    = array();
		$formats = array();

		// Iterating the whitelist rather than the submission is what makes an
		// unknown or protected key unwritable rather than merely unwanted.
		foreach ( self::EDITABLE_COLUMNS as $column ) {
			if ( ! array_key_exists( $column, $fields ) ) {
				continue;
			}

			$to   = self::coerce( $column, $fields[ $column ] );
			$from = array_key_exists( $column, $stored ) ? $stored[ $column ] : null;

			if ( $to === $from ) {
				continue;
			}

			$data[ $column ]    = $to;
			$formats[]          = self::COLUMNS[ $column ];
			$changed[ $column ] = array(
				'from' => $from,
				'to'   => $to,
			);
		}

		$stored_ranges = isset( $stored['date_ranges'] ) ? array_values( (array) $stored['date_ranges'] ) : array();
		$new_ranges    = null;

		if ( null !== $ranges ) {
			// Pre-flight before anything is written, so an unusable candidate date
			// fails with every stored value still in place (Requirement 19.5).
			$normalised = self::normalise_ranges( $ranges );

			if ( is_wp_error( $normalised ) ) {
				return $normalised;
			}

			// Compared in order, not as a set: promoting the second range to the
			// ideal one changes nothing about which days were named and everything
			// about which of them the enquirer would rather have.
			if ( $stored_ranges !== $normalised ) {
				$new_ranges = $normalised;

				$changed['date_ranges'] = array(
					'from' => $stored_ranges,
					'to'   => $normalised,
				);
			}
		}

		$new_terms = array();

		if ( null !== $terms ) {
			$submitted = array();

			foreach ( $terms as $taxonomy => $values ) {
				// A null taxonomy was not submitted; an empty array was, and means
				// "clear this set".
				if ( null !== $values ) {
					$submitted[ $taxonomy ] = $values;
				}
			}

			foreach ( self::normalise_terms( $submitted ) as $taxonomy => $values ) {
				$stored_values = isset( $stored[ $taxonomy ] ) ? array_values( (array) $stored[ $taxonomy ] ) : array();

				if ( self::same_set( $stored_values, $values ) ) {
					continue;
				}

				$new_terms[ $taxonomy ] = $values;

				$changed[ $taxonomy ] = array(
					'from' => $stored_values,
					'to'   => $values,
				);
			}
		}

		// Requirement 19.18: nothing changed, so nothing is written.
		if ( ! $changed ) {
			return array( 'changed' => array() );
		}

		$started          = self::begin();
		$wpdb->last_error = '';

		if ( $data ) {
			$written = $wpdb->update( Schema::table( 'enquiries' ), $data, array( 'id' => $id ), $formats, array( '%d' ) );

			if ( false === $written || '' !== (string) $wpdb->last_error ) {
				return self::abandon_update( $started, $id, 'enquiry row', $wpdb->last_error, $stored, $changed );
			}
		}

		if ( null !== $new_ranges && ! self::replace_ranges( $id, $new_ranges ) ) {
			return self::abandon_update( $started, $id, 'candidate dates', $wpdb->last_error, $stored, $changed );
		}

		foreach ( $new_terms as $taxonomy => $values ) {
			if ( ! self::replace_terms( $id, $taxonomy, $values ) ) {
				return self::abandon_update( $started, $id, $taxonomy . ' terms', $wpdb->last_error, $stored, $changed );
			}
		}

		if ( ! self::commit( $started ) ) {
			return self::abandon_update( $started, $id, 'commit', $wpdb->last_error, $stored, $changed );
		}

		return array( 'changed' => $changed );
	}

	/**
	 * Create one enquiry as a copy of another, and relate the two.
	 *
	 * The re-raising primitive of Requirement 9: a closed enquiry is frozen, so
	 * a returning enquirer gets a new enquiry carrying the same details rather
	 * than an edit to the old one.
	 *
	 * The copy goes through `create()` rather than issuing its own inserts, for
	 * one reason worth stating: MySQL has no nested transactions, so a second
	 * `START TRANSACTION` inside one already open commits it. Reusing `create()`
	 * means the copy's own all-or-nothing guarantee is the one already proven
	 * (Requirement 9.7), and this method adds only the two-column relationship
	 * on top of it.
	 *
	 * The relationship is written last, and only once every copy step has
	 * succeeded (Requirement 9.6). If either half of it fails, the new enquiry
	 * and all of its child rows are deleted and the source keeps no relationship
	 * at all (Requirement 9.7) — which is why the source's `duplicated_to_id` is
	 * the very last write: a failure before it leaves the source untouched
	 * without needing to be undone.
	 *
	 * The source's status, notes and history are never read for writing and
	 * never written (Requirement 9.9): `duplicated_to_id` is the only column of
	 * the source row this touches.
	 *
	 * @param int $source_id Enquiry to copy.
	 * @return int|\WP_Error The new enquiry identifier, or a failure.
	 */
	public static function duplicate( $source_id ) {
		$source_id = (int) $source_id;
		$source    = self::find( $source_id );

		if ( null === $source ) {
			return self::error( 'meh_store_source_not_found', 'The enquiry to copy does not exist.', 404 );
		}

		$now     = Clock::mysql();
		$enquiry = array();

		foreach ( self::DUPLICATED_COLUMNS as $column ) {
			$enquiry[ $column ] = array_key_exists( $column, $source ) ? $source[ $column ] : null;
		}

		// Requirement 9.5: a copy starts its own lifecycle at `new`, carries no
		// booking, and is created now rather than when its source was.
		$enquiry['status']            = 'new';
		$enquiry['booking_id']        = null;
		$enquiry['created_at']        = $now;
		$enquiry['updated_at']        = $now;
		$enquiry['status_changed_at'] = $now;
		$enquiry['crm_sync_state']    = '';

		$terms = array();

		foreach ( self::TAXONOMIES as $taxonomy ) {
			$terms[ $taxonomy ] = isset( $source[ $taxonomy ] ) ? (array) $source[ $taxonomy ] : array();
		}

		/*
		 * The payload column is the record of what arrived. Nothing arrived here,
		 * so copying the source's snapshot verbatim would claim a submission that
		 * never happened. The copy records its own provenance instead, and the
		 * source's snapshot stays reachable through `duplicated_from_id`.
		 */
		$payload = array(
			'duplicated_from_id' => $source_id,
			'duplicated_at'      => $now,
		);

		$new_id = self::create( $enquiry, isset( $source['date_ranges'] ) ? (array) $source['date_ranges'] : array(), $terms, $payload );

		if ( is_wp_error( $new_id ) ) {
			// `create()` has already rolled back and deleted whatever it wrote.
			return $new_id;
		}

		$new_id = (int) $new_id;
		$linked = self::update_fields( $new_id, array( 'duplicated_from_id' => $source_id ) );

		if ( is_wp_error( $linked ) ) {
			return self::abandon_duplicate( $new_id, $source_id, 'copy relationship on the new enquiry' );
		}

		$linked = self::update_fields( $source_id, array( 'duplicated_to_id' => $new_id ) );

		if ( is_wp_error( $linked ) ) {
			return self::abandon_duplicate( $new_id, $source_id, 'copy relationship on the source enquiry' );
		}

		return $new_id;
	}

	/**
	 * Record one rejected intake attempt.
	 *
	 * Every authenticated intake request that produced no enquiry lands here, so
	 * a genuine enquiry rejected as a duplicate, rate limited or invalid can
	 * still be found and recreated by hand (Requirements 3.8, 4.3, 5.8).
	 *
	 * The reason is expected to be one of self::REJECTION_REASONS. An
	 * unrecognised value is logged and still recorded rather than dropped: a
	 * rejection row carrying an odd reason is findable, whereas a missing one is
	 * a lost enquiry, and the whole point of the table is that nothing goes
	 * untraced.
	 *
	 * @param array  $payload Enquiry Payload Snapshot, stored as JSON.
	 * @param string $reason  Rejection reason.
	 * @param array  $detail  Per-field failures, matched enquiry identifier, and
	 *                        optionally `email`, `source`, `is_test` and
	 *                        `received_at`, which are lifted into their columns.
	 * @return int The new rejection identifier, or 0 when nothing was recorded.
	 */
	public static function record_rejection( array $payload, $reason, array $detail = array() ) {
		global $wpdb;

		$reason = trim( (string) $reason );

		if ( ! in_array( $reason, self::REJECTION_REASONS, true ) ) {
			Log::write( 'store: unrecognised rejection reason recorded', array( 'reason' => $reason ) );
		}

		$row = array(
			'reason'     => $reason,
			'detail'     => self::encode_payload( $detail ),
			'payload'    => self::encode_payload( $payload ),
			'email'      => '',
			'source'     => '',
			'is_test'    => 0,
			'created_at' => Clock::mysql(),
		);

		foreach ( self::REJECTION_DETAIL_COLUMNS as $key => $column ) {
			if ( ! array_key_exists( $key, $detail ) ) {
				continue;
			}

			$value = $detail[ $key ];

			if ( 'is_test' === $column ) {
				$row[ $column ] = $value ? 1 : 0;
			} elseif ( 'created_at' === $column ) {
				$row[ $column ] = Clock::mysql( $value );
			} else {
				$row[ $column ] = is_scalar( $value ) ? (string) $value : '';
			}
		}

		// A submitted email is what the rejections list is searched by, so it is
		// read from the payload when the caller did not resolve one itself.
		if ( '' === $row['email'] && isset( $payload['email'] ) && is_scalar( $payload['email'] ) ) {
			$row['email'] = (string) $payload['email'];
		}

		$wpdb->last_error = '';

		$inserted = $wpdb->insert(
			Schema::table( 'rejections' ),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			Log::write(
				'store: rejection could not be recorded',
				array(
					'reason'  => $reason,
					'error'   => (string) $wpdb->last_error,
					'payload' => $payload,
				)
			);

			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete every test enquiry and all of its child rows.
	 *
	 * Requirement 17.7 asks for the staging clean-up; Requirement 17.8 makes the
	 * sparing of live records the harder half of the guarantee, so every
	 * statement here names identifiers read from `is_test = 1` and nothing else.
	 * A live enquiry is never named, so neither it nor any of its child rows can
	 * be reached.
	 *
	 * All-or-nothing where the storage engine allows it: a failure part-way
	 * through rolls back rather than leaving child rows pointing at enquiries
	 * that are gone.
	 *
	 * Rejected intake attempts are left alone. They are not enquiries, they
	 * carry no enquiry identifier, and they are the trace of what did not get
	 * stored — deleting them would remove exactly the record a staging run was
	 * there to produce.
	 *
	 * @return int The number of enquiries deleted.
	 */
	public static function delete_test_records() {
		global $wpdb;

		$table = Schema::table( 'enquiries' );

		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE is_test = 1 ORDER BY id ASC" ); // phpcs:ignore WordPress.DB

		$ids = is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();

		if ( ! $ids ) {
			return 0;
		}

		$started          = self::begin();
		$wpdb->last_error = '';

		foreach ( array_chunk( $ids, self::DELETE_BATCH ) as $batch ) {
			$placeholders = implode( ', ', array_fill( 0, count( $batch ), '%d' ) );

			// Children first, so an interrupted delete never leaves a child row
			// pointing at an enquiry that no longer exists.
			foreach ( self::CHILD_TABLES as $key ) {
				$child = Schema::table( $key );

				if ( ! self::write( "DELETE FROM {$child} WHERE enquiry_id IN ( {$placeholders} )", $batch ) ) {
					return self::abandon_delete( $started, $key, $wpdb->last_error );
				}
			}

			if ( ! self::write( "DELETE FROM {$table} WHERE id IN ( {$placeholders} ) AND is_test = 1", $batch ) ) {
				return self::abandon_delete( $started, 'enquiries', $wpdb->last_error );
			}
		}

		if ( ! self::commit( $started ) ) {
			return self::abandon_delete( $started, 'commit', $wpdb->last_error );
		}

		return count( $ids );
	}

	/**
	 * Read one enquiry in the hydrated shape, or null when it does not exist.
	 *
	 * @param int $id Enquiry identifier.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$id = (int) $id;

		if ( $id <= 0 ) {
			return null;
		}

		$table = Schema::table( 'enquiries' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $row ) || ! $row ) {
			return null;
		}

		return self::hydrate( $row, self::ranges_for( $id ), self::terms_for( $id ) );
	}

	/**
	 * Shape one enquiry row, plus its child rows, into the hydrated array.
	 *
	 * Public because the list query composes its own statement and hydrates the
	 * rows it read in bulk; this is the one place the shape is defined, so the
	 * list and the single-enquiry view cannot disagree about it.
	 *
	 * A column absent from `$row` hydrates to its empty value rather than to a
	 * substitute: `''` for text, `null` for a nullable number, `''` for a
	 * timestamp. The zero `DATETIME` hydrates to `''` for the same reason — it
	 * is the column default meaning "never set", not a time, and passing it on
	 * as though it were would put an impossible date in the API. That is what
	 * lets the read layer detect an incomplete stored row and fail loudly.
	 *
	 * @param array $row    Raw enquiry row, keyed by column name.
	 * @param array $ranges Candidate date ranges, ideal first.
	 * @param array $terms  Term lists keyed by taxonomy.
	 * @return array
	 */
	public static function hydrate( array $row, array $ranges = array(), array $terms = array() ) {
		$hydrated = array(
			'id'                      => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'first_name'              => self::text( $row, 'first_name' ),
			'last_name'               => self::text( $row, 'last_name' ),
			'email'                   => self::text( $row, 'email' ),
			'phone'                   => self::text( $row, 'phone' ),
			'total_guests'            => self::number( $row, 'total_guests' ),
			'message'                 => self::text( $row, 'message' ),
			'status'                  => self::text( $row, 'status' ),
			'crm_sync_state'          => self::text( $row, 'crm_sync_state' ),
			'fluentcrm_subscriber_id' => self::number( $row, 'fluentcrm_subscriber_id' ),
			'booking_id'              => self::number( $row, 'booking_id' ),
			'created_at'              => self::datetime( $row, 'created_at' ),
			'updated_at'              => self::datetime( $row, 'updated_at' ),
			'status_changed_at'       => self::datetime( $row, 'status_changed_at' ),
			'source'                  => self::text( $row, 'source' ),
			'is_test'                 => isset( $row['is_test'] ) ? (bool) (int) $row['is_test'] : false,
			'duplicated_from_id'      => self::number( $row, 'duplicated_from_id' ),
			'duplicated_to_id'        => self::number( $row, 'duplicated_to_id' ),
			'date_ranges'             => array_values( $ranges ),
		);

		// An empty multi-select reads back as an empty array rather than as a
		// null or an error (Requirement 1.3).
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$hydrated[ $taxonomy ] = isset( $terms[ $taxonomy ] ) ? array_values( (array) $terms[ $taxonomy ] ) : array();
		}

		$hydrated['payload'] = self::decode_payload( isset( $row['payload'] ) ? $row['payload'] : null );

		return $hydrated;
	}

	/**
	 * Candidate date ranges of one enquiry, ideal range first.
	 *
	 * Ordered by `position` rather than by date, because position 0 is the range
	 * the enquirer would rather have and the alternatives that follow are ranked,
	 * not chronological — an enquirer may well prefer a later week.
	 *
	 * @param int $id Enquiry identifier.
	 * @return array<int,array{start:string,end:string}>
	 */
	public static function ranges_for( $id ) {
		global $wpdb;

		$table = Schema::table( 'dates' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT start_date, end_date FROM {$table} WHERE enquiry_id = %d ORDER BY position ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( __CLASS__, 'range_of' ), $rows ) : array();
	}

	/**
	 * One stored row as a range.
	 *
	 * @param array $row Row carrying `start_date` and `end_date`.
	 * @return array{start:string,end:string}
	 */
	protected static function range_of( array $row ) {
		return array(
			'start' => isset( $row['start_date'] ) ? (string) $row['start_date'] : '',
			'end'   => isset( $row['end_date'] ) ? (string) $row['end_date'] : '',
		);
	}

	/**
	 * Term lists of one enquiry, keyed by taxonomy.
	 *
	 * Both taxonomies are always present in the result, empty when the enquiry
	 * holds no row for them.
	 *
	 * @param int $id Enquiry identifier.
	 * @return array<string,string[]>
	 */
	public static function terms_for( $id ) {
		global $wpdb;

		$terms = array_fill_keys( self::TAXONOMIES, array() );
		$table = Schema::table( 'terms' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT taxonomy, term FROM {$table} WHERE enquiry_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $terms;
		}

		foreach ( $rows as $row ) {
			$taxonomy = isset( $row['taxonomy'] ) ? (string) $row['taxonomy'] : '';

			if ( ! isset( $terms[ $taxonomy ] ) ) {
				continue;
			}

			$terms[ $taxonomy ][] = isset( $row['term'] ) ? (string) $row['term'] : '';
		}

		return $terms;
	}

	/**
	 * Read one page of enquiries matching a filter set, with its counts.
	 *
	 * The composition `EnquiryQuery` deliberately leaves to this class: the
	 * builder's `where` fragment with its bindings handed to
	 * `$wpdb->prepare()`, the enquiry table aliased as the builder expects, and
	 * the dates-table token replaced with the real, prefixed name
	 * (Requirement 12.1).
	 *
	 * `total` is the count of every matching enquiry, not of the page, so the
	 * caller can report `X-WP-Total` and `X-WP-TotalPages` (Requirement 12.11).
	 * `counts` holds one entry per status plus `all` (Requirement 12.9).
	 * `warnings` carries the lone-candidate-date-bound warning through
	 * (Requirement 12.8).
	 *
	 * Child rows are read for the whole page in two statements rather than two
	 * per row, so a 200-row page costs three queries in total rather than 401.
	 *
	 * @param array $args Filter arguments; see `EnquiryQuery::normalise()`.
	 * @return array{items:array,total:int,counts:array,warnings:array,page:int,per_page:int,total_pages:int}
	 */
	public static function query( array $args ) {
		global $wpdb;

		$normalised = EnquiryQuery::normalise( $args );
		$built      = EnquiryQuery::build( $normalised, array( 'dates' => Schema::table( 'dates' ) ) );

		$table = Schema::table( 'enquiries' );
		$alias = EnquiryQuery::ALIAS;

		$sql = "SELECT {$alias}.* FROM {$table} {$alias} WHERE " . $built['where']
			. ' ' . $built['order']
			. ' ' . $built['limit'];

		$rows = $wpdb->get_results( self::prepared( $sql, $built['bindings'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$rows = is_array( $rows ) ? $rows : array();

		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = isset( $row['id'] ) ? (int) $row['id'] : 0;
		}

		$ranges = self::ranges_for_many( $ids );
		$terms  = self::terms_for_many( $ids );
		$items  = array();

		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;

			$items[] = self::hydrate(
				$row,
				isset( $ranges[ $id ] ) ? $ranges[ $id ] : array(),
				isset( $terms[ $id ] ) ? $terms[ $id ] : array()
			);
		}

		$total    = self::count_matching( $built['where'], $built['bindings'] );
		$per_page = (int) $normalised['per_page'];

		return array(
			'items'       => $items,
			'total'       => $total,
			'counts'      => self::status_counts( $normalised ),
			'warnings'    => $built['warnings'],
			'page'        => (int) $normalised['page'],
			'per_page'    => $per_page,
			'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Count enquiries per status under a filter set (Requirement 12.9).
	 *
	 * The `status` filter itself is ignored: the counts are what the status tabs
	 * are labelled with, so each one has to answer "how many enquiries would
	 * this tab show", which is the count under every *other* supplied filter.
	 * Counting under the status filter as well would make every tab but the
	 * selected one read zero.
	 *
	 * Every recognised status is present in the result, at 0 when nothing holds
	 * it. A stored status the lifecycle does not recognise is reported too
	 * rather than dropped, so `all` always equals the sum of the rest.
	 *
	 * @param array $args Filter arguments; `status` is ignored.
	 * @return array<string,int> Counts keyed by status, plus `all`.
	 */
	public static function status_counts( array $args ) {
		global $wpdb;

		$args['status'] = EnquiryQuery::STATUS_ALL;

		$built = EnquiryQuery::build( $args, array( 'dates' => Schema::table( 'dates' ) ) );
		$table = Schema::table( 'enquiries' );
		$alias = EnquiryQuery::ALIAS;

		$sql = "SELECT {$alias}.status AS status, COUNT(*) AS total FROM {$table} {$alias} WHERE "
			. $built['where']
			. " GROUP BY {$alias}.status";

		$rows = $wpdb->get_results( self::prepared( $sql, $built['bindings'] ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$counts = array( self::COUNT_ALL => 0 );

		foreach ( EnquiryQuery::statuses() as $status ) {
			$counts[ $status ] = 0;
		}

		if ( ! is_array( $rows ) ) {
			return $counts;
		}

		foreach ( $rows as $row ) {
			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			$total  = isset( $row['total'] ) ? (int) $row['total'] : 0;

			$counts[ $status ]         = isset( $counts[ $status ] ) ? $counts[ $status ] + $total : $total;
			$counts[ self::COUNT_ALL ] = $counts[ self::COUNT_ALL ] + $total;
		}

		return $counts;
	}

	/**
	 * Other enquiries sharing an email address (Requirement 13.3).
	 *
	 * A summary rather than whole enquiries: what the single-enquiry view shows
	 * of a sibling, and no more. That is the identifier, the creation time, the
	 * status and both multi-select sets — the last two because "another enquiry
	 * from this address" is only useful once you can see whether it was the same
	 * kind of event, which the identifier and date alone never told you. The
	 * candidate dates, the message and the payload are still left out: nothing
	 * renders them here, and a frequent enquirer would pay for them on every read.
	 *
	 * The term sets cost one query for the whole list, the same batch read the
	 * list route uses, rather than one per sibling.
	 *
	 * An empty email returns nothing. `email` carries `''` for a row that was
	 * stored without one, so matching on the empty string would relate every
	 * such enquiry to every other, which is not a shared identity.
	 *
	 * @param string $email      Email address to match, exactly.
	 * @param int    $exclude_id Enquiry to leave out, normally the one being viewed.
	 * @return array<int,array{id:int,created_at:string,status:string,event_type:string[],site_exclusivity:string[]}> Most recent first.
	 */
	public static function siblings_by_email( $email, $exclude_id = 0 ) {
		global $wpdb;

		$email = trim( (string) $email );

		if ( '' === $email ) {
			return array();
		}

		$table = Schema::table( 'enquiries' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, status FROM {$table} WHERE email = %s AND id <> %d ORDER BY created_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email,
				(int) $exclude_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$ids = array();

		foreach ( $rows as $row ) {
			$ids[] = isset( $row['id'] ) ? (int) $row['id'] : 0;
		}

		$terms    = self::terms_for_many( $ids );
		$siblings = array();

		foreach ( $rows as $row ) {
			$id      = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$sibling = array(
				'id'         => $id,
				'created_at' => self::datetime( $row, 'created_at' ),
				'status'     => self::text( $row, 'status' ),
			);

			// Both taxonomies are always present, empty when the sibling holds no
			// term for one, so a reader never has to test for the key — the same
			// guarantee `hydrate()` gives on a whole enquiry.
			foreach ( self::TAXONOMIES as $taxonomy ) {
				$sibling[ $taxonomy ] = isset( $terms[ $id ][ $taxonomy ] )
					? array_values( (array) $terms[ $id ][ $taxonomy ] )
					: array();
			}

			$siblings[] = $sibling;
		}

		return $siblings;
	}

	/**
	 * Event types of the enquiries a set of bookings was converted from.
	 *
	 * What the calendar overview hovers over: a booking carries a guest name and
	 * dates of its own, but the kind of event is the enquiry's, so it can only be
	 * reached back through `booking_id`.
	 *
	 * Two queries for the whole month rather than two per booking — the same
	 * batch read `siblings_by_email()` uses. A booking with no enquiry behind it,
	 * which is any booking entered straight into WP Booking System, is simply
	 * absent from the result rather than present and empty: the caller adds the
	 * key either way, and an absent booking is not a mistake to report.
	 *
	 * Should two enquiries somehow name the same booking, the lower identifier
	 * wins — the enquiry that was converted, since `BookingCreator` refuses a
	 * second conversion and `duplicate()` does not copy `booking_id`.
	 *
	 * @param int[] $booking_ids WP Booking System booking identifiers.
	 * @return array<int,string[]> booking_id => event types, in stored order.
	 */
	public static function event_types_by_booking( array $booking_ids ) {
		global $wpdb;

		$booking_ids = self::identifiers( $booking_ids );

		if ( ! $booking_ids ) {
			return array();
		}

		$table        = Schema::table( 'enquiries' );
		$placeholders = implode( ', ', array_fill( 0, count( $booking_ids ), '%d' ) );

		$sql = "SELECT id, booking_id FROM {$table} WHERE booking_id IN ( {$placeholders} )"
			. ' ORDER BY id ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $booking_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB

		if ( ! is_array( $rows ) || ! $rows ) {
			return array();
		}

		$enquiry_of = array();

		foreach ( $rows as $row ) {
			$booking = isset( $row['booking_id'] ) ? (int) $row['booking_id'] : 0;
			$enquiry = isset( $row['id'] ) ? (int) $row['id'] : 0;

			if ( $booking > 0 && $enquiry > 0 && ! isset( $enquiry_of[ $booking ] ) ) {
				$enquiry_of[ $booking ] = $enquiry;
			}
		}

		$terms = self::terms_for_many( array_values( $enquiry_of ) );
		$types = array();

		foreach ( $enquiry_of as $booking => $enquiry ) {
			$of_enquiry = isset( $terms[ $enquiry ]['event_type'] )
				? array_values( array_filter( (array) $terms[ $enquiry ]['event_type'], 'strlen' ) )
				: array();

			if ( $of_enquiry ) {
				$types[ $booking ] = $of_enquiry;
			}
		}

		return $types;
	}

	/**
	 * Identifiers of settled enquiries that settled before a given time.
	 *
	 * The read behind the auto-closure job. The status list comes from
	 * `Lifecycle::SETTLED` through self::settled_statuses(), so the job and the
	 * lifecycle cannot disagree about what settled means (Requirement 7.12).
	 *
	 * The comparison is strict, so an enquiry that settled exactly on the cutoff
	 * has not yet passed it. An enquiry whose `status_changed_at` was never set
	 * is skipped: the zero `DATETIME` sorts before every real time, so treating
	 * it as a settlement time would auto-close an enquiry that has no measurable
	 * dwell at all.
	 *
	 * An empty cutoff returns nothing rather than defaulting to now, which would
	 * close every settled enquiry the moment a caller passed a missing value.
	 *
	 * @param string $datetime Cutoff, MySQL `DATETIME` in the site timezone.
	 * @return int[] Identifiers, oldest settlement first.
	 */
	public static function settled_before( $datetime ) {
		global $wpdb;

		if ( null === $datetime || '' === trim( (string) $datetime ) ) {
			Log::write( 'store: settled_before called with no cutoff' );

			return array();
		}

		$statuses = self::settled_statuses();

		if ( ! $statuses ) {
			return array();
		}

		$table        = Schema::table( 'enquiries' );
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$bindings   = array_values( $statuses );
		$bindings[] = Clock::mysql( $datetime );
		$bindings[] = self::ZERO_DATETIME;

		$sql = "SELECT id FROM {$table} WHERE status IN ( {$placeholders} )"
			. ' AND status_changed_at < %s AND status_changed_at > %s'
			. ' ORDER BY status_changed_at ASC, id ASC';

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $bindings ) ); // phpcs:ignore WordPress.DB

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * The statuses counted as settled.
	 *
	 * Prefers `Lifecycle::SETTLED` and falls back to self::SETTLED_STATUSES only
	 * while the lifecycle is not loaded, which mirrors how
	 * `EnquiryQuery::statuses()` reads `Lifecycle::STATUSES`.
	 *
	 * @return string[]
	 */
	public static function settled_statuses() {
		$class = __NAMESPACE__ . '\Lifecycle';

		if ( class_exists( $class ) && defined( $class . '::SETTLED' ) ) {
			$settled = constant( $class . '::SETTLED' );

			if ( is_array( $settled ) && $settled ) {
				return array_values( array_map( 'strval', $settled ) );
			}
		}

		return self::SETTLED_STATUSES;
	}

	/**
	 * Read one page of rejected intake attempts (Requirements 4.3, 4.8, 5.8).
	 *
	 * Supported arguments, every one of them optional: `reason` restricted to
	 * self::REJECTION_REASONS, `s` matched as a literal substring of `email`,
	 * `from` and `to` bounding `created_at` inclusively by day, `hide_test`,
	 * `page` and `per_page`. The page size default and cap are the list's own
	 * (Requirement 12.10), so the two paginated reads behave alike.
	 *
	 * @param array $args Filter arguments.
	 * @return array{items:array,total:int,page:int,per_page:int,total_pages:int}
	 */
	public static function rejections( array $args = array() ) {
		global $wpdb;

		$where    = array();
		$bindings = array();

		$reason = isset( $args['reason'] ) && is_scalar( $args['reason'] ) ? trim( (string) $args['reason'] ) : '';

		if ( '' !== $reason && in_array( $reason, self::REJECTION_REASONS, true ) ) {
			$where[]    = 'reason = %s';
			$bindings[] = $reason;
		}

		$search = isset( $args['s'] ) && is_scalar( $args['s'] ) ? trim( (string) $args['s'] ) : '';

		if ( '' !== $search ) {
			$where[]    = 'email LIKE %s';
			$bindings[] = '%' . EnquiryQuery::esc_like( $search ) . '%';
		}

		$from = self::to_date( isset( $args['from'] ) ? $args['from'] : null );
		$to   = self::to_date( isset( $args['to'] ) ? $args['to'] : null );

		if ( null !== $from ) {
			$where[]    = 'created_at >= %s';
			$bindings[] = $from . ' 00:00:00';
		}

		if ( null !== $to ) {
			$where[]    = 'created_at <= %s';
			$bindings[] = $to . ' 23:59:59';
		}

		if ( ! empty( $args['hide_test'] ) ) {
			$where[] = 'is_test = 0';
		}

		$clause   = $where ? implode( ' AND ', $where ) : '1 = 1';
		$page     = isset( $args['page'] ) && is_numeric( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$per_page = self::rejections_per_page( isset( $args['per_page'] ) ? $args['per_page'] : null );
		$table    = Schema::table( 'rejections' );

		$sql = "SELECT * FROM {$table} WHERE {$clause}"
			. ' ORDER BY created_at DESC, id DESC'
			. sprintf( ' LIMIT %d OFFSET %d', $per_page, ( $page - 1 ) * $per_page );

		$rows = $wpdb->get_results( self::prepared( $sql, $bindings ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$rows = is_array( $rows ) ? $rows : array();

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'reason'     => self::text( $row, 'reason' ),
				'email'      => self::text( $row, 'email' ),
				'source'     => self::text( $row, 'source' ),
				'is_test'    => isset( $row['is_test'] ) ? (bool) (int) $row['is_test'] : false,
				'created_at' => self::datetime( $row, 'created_at' ),
				'detail'     => self::decode_payload( isset( $row['detail'] ) ? $row['detail'] : null ),
				'payload'    => self::decode_payload( isset( $row['payload'] ) ? $row['payload'] : null ),
			);
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) $wpdb->get_var( self::prepared( $count_sql, $bindings ) ); // phpcs:ignore WordPress.DB

		return array(
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Build the parent row for a create, defaults applied.
	 *
	 * `status` starts at `new` and the three timestamps default to now, so a
	 * caller that supplies neither still writes a readable row; every caller in
	 * the plugin passes the receipt time explicitly (Requirements 2.1, 2.2).
	 *
	 * @param array $enquiry Submitted column values.
	 * @param array $payload Enquiry Payload Snapshot.
	 * @return array<string,mixed> Column => value, ready for `$wpdb->insert()`.
	 */
	protected static function row_for_create( array $enquiry, array $payload ) {
		$now = Clock::mysql();

		$defaults = array(
			'first_name'              => '',
			'last_name'               => '',
			'email'                   => '',
			'phone'                   => '',
			'total_guests'            => null,
			'message'                 => '',
			'status'                  => 'new',
			'fluentcrm_subscriber_id' => null,
			'booking_id'              => null,
			'crm_sync_state'          => '',
			'created_at'              => $now,
			'updated_at'              => $now,
			'status_changed_at'       => $now,
			'source'                  => '',
			'is_test'                 => 0,
			'duplicated_from_id'      => null,
			'duplicated_to_id'        => null,
		);

		$row = array();

		foreach ( $defaults as $column => $default ) {
			$value = array_key_exists( $column, $enquiry ) ? $enquiry[ $column ] : $default;

			$row[ $column ] = self::coerce( $column, $value );
		}

		// The payload argument owns the snapshot column, so a `payload` key in
		// the enquiry array can never overwrite it with a different value.
		$row['payload'] = self::encode_payload( $payload );

		return $row;
	}

	/**
	 * `$wpdb` formats for a row, in the row's own key order.
	 *
	 * @param array $row Column => value.
	 * @return string[]
	 */
	protected static function formats_for( array $row ) {
		$formats = array();

		foreach ( array_keys( $row ) as $column ) {
			$formats[] = isset( self::COLUMNS[ $column ] ) ? self::COLUMNS[ $column ] : '%s';
		}

		return $formats;
	}

	/**
	 * Coerce one submitted value to what its column holds.
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Submitted value.
	 * @return string|int|null
	 */
	protected static function coerce( $column, $value ) {
		if ( in_array( $column, self::NULLABLE_COLUMNS, true ) ) {
			if ( null === $value || '' === $value || array() === $value ) {
				return null;
			}

			// The columns are `UNSIGNED`, so a negative value is not a smaller
			// identifier or count — it is not one at all.
			return max( 0, (int) $value );
		}

		if ( in_array( $column, self::DATETIME_COLUMNS, true ) ) {
			return Clock::mysql( $value );
		}

		if ( 'is_test' === $column ) {
			return $value ? 1 : 0;
		}

		return null === $value ? '' : (string) $value;
	}

	/**
	 * Reduce submitted candidate date ranges to a set, in the order given.
	 *
	 * An entry may be `array{start,end}` or a bare date, which stands for the
	 * single-day range starting and ending on it — the shape a one-night booking
	 * takes, and the shape every row had before ranges existed.
	 *
	 * A lone range object handed in where a list was expected is read as the one
	 * range it is, rather than as two bare dates one per bound: a caller with a
	 * single range to store writes it the obvious way often enough that guessing
	 * wrong there would corrupt the record silently.
	 *
	 * An unparseable entry is a failure rather than a skipped row: silently
	 * dropping a candidate range would store an enquiry the enquirer did not
	 * make, and the zero date the column would otherwise take is not a date. A
	 * range ending before it starts is the same kind of failure — it is not a
	 * range, and quietly swapping the bounds would guess at which of the two the
	 * enquirer got wrong.
	 *
	 * Duplicates collapse, because naming the same fortnight twice names it once.
	 * The order survives: the first range is the ideal one.
	 *
	 * @param array $ranges Submitted candidate date ranges.
	 * @return array<int,array{start:string,end:string}>|\WP_Error
	 */
	protected static function normalise_ranges( array $ranges ) {
		if ( array_key_exists( 'start', $ranges ) || array_key_exists( 'end', $ranges ) ) {
			$ranges = array( $ranges );
		}

		$normalised = array();

		foreach ( $ranges as $entry ) {
			$range = self::to_range( $entry );

			if ( is_wp_error( $range ) ) {
				return $range;
			}

			if ( ! in_array( $range, $normalised, true ) ) {
				$normalised[] = $range;
			}
		}

		return $normalised;
	}

	/**
	 * Read one submitted entry as a range.
	 *
	 * @param mixed $entry `array{start,end}`, or a date standing for a single day.
	 * @return array{start:string,end:string}|\WP_Error
	 */
	protected static function to_range( $entry ) {
		if ( is_array( $entry ) ) {
			$start = self::to_date( isset( $entry['start'] ) ? $entry['start'] : null );
			$end   = self::to_date( isset( $entry['end'] ) ? $entry['end'] : null );

			// An entry carrying only one bound is a half-drawn range, and which
			// half is missing decides nothing: the other bound is unknown, so
			// there is no range to store.
			if ( null === $start || null === $end ) {
				return self::error(
					'meh_store_invalid_date',
					'A candidate date range needs both a start and an end.',
					400
				);
			}

			if ( $end < $start ) {
				return self::error(
					'meh_store_invalid_range',
					'A candidate date range cannot end before it starts.',
					400
				);
			}

			return array(
				'start' => $start,
				'end'   => $end,
			);
		}

		$date = self::to_date( $entry );

		if ( null === $date ) {
			return self::error(
				'meh_store_invalid_date',
				'A candidate date could not be read as a calendar date.',
				400
			);
		}

		return array(
			'start' => $date,
			'end'   => $date,
		);
	}

	/**
	 * Reduce submitted term lists to sets, keyed by known taxonomy.
	 *
	 * A taxonomy the store does not own is skipped and logged: writing it would
	 * put rows in the terms table that no read path ever returns.
	 *
	 * @param array $terms Term lists keyed by taxonomy.
	 * @return array<string,string[]>
	 */
	protected static function normalise_terms( array $terms ) {
		$normalised = array();

		foreach ( $terms as $taxonomy => $values ) {
			$taxonomy = (string) $taxonomy;

			if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
				Log::write( 'store: unknown taxonomy skipped', array( 'taxonomy' => $taxonomy ) );

				continue;
			}

			$normalised[ $taxonomy ] = array();

			foreach ( (array) $values as $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$value = (string) $value;

				if ( '' !== $value && ! in_array( $value, $normalised[ $taxonomy ], true ) ) {
					$normalised[ $taxonomy ][] = $value;
				}
			}
		}

		return $normalised;
	}

	/**
	 * Insert every candidate date range of one enquiry in one statement.
	 *
	 * The position each range is written at is its index in the list, so what
	 * comes back out is what went in: the ideal range at 0, the alternatives
	 * after it.
	 *
	 * @param int   $id     Enquiry identifier.
	 * @param array $ranges Ranges, already a normalised list.
	 * @return bool
	 */
	protected static function insert_ranges( $id, array $ranges ) {
		$table    = Schema::table( 'dates' );
		$rows     = array_fill( 0, count( $ranges ), '( %d, %s, %s, %d )' );
		$bindings = array();
		$position = 0;

		foreach ( $ranges as $range ) {
			$bindings[] = (int) $id;
			$bindings[] = $range['start'];
			$bindings[] = $range['end'];
			$bindings[] = $position;

			++$position;
		}

		$sql = "INSERT INTO {$table} ( enquiry_id, start_date, end_date, position ) VALUES " . implode( ', ', $rows );

		return self::write( $sql, $bindings );
	}

	/**
	 * Insert every term row of one taxonomy of one enquiry in one statement.
	 *
	 * @param int      $id       Enquiry identifier.
	 * @param string   $taxonomy Taxonomy name.
	 * @param string[] $values   Term values.
	 * @return bool
	 */
	protected static function insert_terms( $id, $taxonomy, array $values ) {
		$table    = Schema::table( 'terms' );
		$rows     = array_fill( 0, count( $values ), '( %d, %s, %s )' );
		$bindings = array();

		foreach ( $values as $value ) {
			$bindings[] = (int) $id;
			$bindings[] = (string) $taxonomy;
			$bindings[] = $value;
		}

		$sql = "INSERT INTO {$table} ( enquiry_id, taxonomy, term ) VALUES " . implode( ', ', $rows );

		return self::write( $sql, $bindings );
	}

	/**
	 * Replace the whole candidate date range list of one enquiry.
	 *
	 * Delete then insert rather than diff: a range has no identity beyond the
	 * days it spans and the place it holds in the list, so there is no row to
	 * update.
	 *
	 * @param int   $id     Enquiry identifier.
	 * @param array $ranges Ranges, already a normalised list.
	 * @return bool
	 */
	protected static function replace_ranges( $id, array $ranges ) {
		$table = Schema::table( 'dates' );

		if ( ! self::write( "DELETE FROM {$table} WHERE enquiry_id = %d", array( (int) $id ) ) ) {
			return false;
		}

		if ( ! $ranges ) {
			return true;
		}

		return self::insert_ranges( $id, $ranges );
	}

	/**
	 * Replace the whole term set of one taxonomy of one enquiry.
	 *
	 * The delete names the taxonomy, so replacing `event_type` cannot disturb
	 * `site_exclusivity`.
	 *
	 * @param int      $id       Enquiry identifier.
	 * @param string   $taxonomy Taxonomy name.
	 * @param string[] $values   Term values, already a normalised set.
	 * @return bool
	 */
	protected static function replace_terms( $id, $taxonomy, array $values ) {
		$table = Schema::table( 'terms' );

		$deleted = self::write(
			"DELETE FROM {$table} WHERE enquiry_id = %d AND taxonomy = %s",
			array( (int) $id, (string) $taxonomy )
		);

		if ( ! $deleted ) {
			return false;
		}

		if ( ! $values ) {
			return true;
		}

		return self::insert_terms( $id, $taxonomy, $values );
	}

	/**
	 * Whether two collections hold the same members, order and repeats aside.
	 *
	 * Candidate dates and term lists are sets, so `[ 'a', 'b' ]` and
	 * `[ 'b', 'a', 'b' ]` are the same set and an edit submitting one over the
	 * other has changed nothing (Requirement 19.18).
	 *
	 * @param array $left  One collection.
	 * @param array $right The other.
	 * @return bool
	 */
	protected static function same_set( array $left, array $right ) {
		$left  = array_values( array_unique( array_map( 'strval', $left ) ) );
		$right = array_values( array_unique( array_map( 'strval', $right ) ) );

		sort( $left );
		sort( $right );

		return $left === $right;
	}

	/**
	 * Abandon a failed correction: undo whatever it managed, report, leave the
	 * enquiry as it was.
	 *
	 * The rollback is the recovery on a transactional engine. Without one there
	 * is nothing to roll back, so the previously stored scalar values and child
	 * rows — read into `$stored` before the first write — are written back
	 * instead. Either way the enquiry ends where it started, which is what
	 * Requirements 19.4 and 19.5 ask for.
	 *
	 * @param bool   $started Whether a transaction was opened.
	 * @param int    $id      Enquiry identifier.
	 * @param string $stage   Stage that failed, for the log line.
	 * @param string $error   Database error, when there was one.
	 * @param array  $stored  Hydrated enquiry as it was before the write.
	 * @param array  $changed The `changed` map the write was applying.
	 * @return \WP_Error
	 */
	protected static function abandon_update( $started, $id, $stage, $error, array $stored, array $changed ) {
		self::rollback( $started );

		if ( ! $started ) {
			self::restore( (int) $id, $stored, $changed );
		}

		Log::write(
			sprintf( 'store: update failed at %s', (string) $stage ),
			array(
				'enquiry_id' => (int) $id,
				'fields'     => array_keys( $changed ),
				'error'      => trim( (string) $error ),
			)
		);

		return self::error( 'meh_store_update_failed', 'The enquiry could not be updated.', 500 );
	}

	/**
	 * Write one enquiry's previously stored values back over a failed correction.
	 *
	 * The compensating path for a non-transactional engine. Only the fields the
	 * correction was applying are restored: nothing else was touched, so
	 * rewriting more would be a write the failed edit did not cause.
	 *
	 * @param int   $id      Enquiry identifier.
	 * @param array $stored  Hydrated enquiry as it was before the write.
	 * @param array $changed The `changed` map the write was applying.
	 * @return void
	 */
	protected static function restore( $id, array $stored, array $changed ) {
		global $wpdb;

		$data    = array();
		$formats = array();

		foreach ( self::EDITABLE_COLUMNS as $column ) {
			if ( ! isset( $changed[ $column ] ) ) {
				continue;
			}

			$data[ $column ] = self::coerce( $column, array_key_exists( $column, $stored ) ? $stored[ $column ] : null );
			$formats[]       = self::COLUMNS[ $column ];
		}

		if ( $data ) {
			$wpdb->update( Schema::table( 'enquiries' ), $data, array( 'id' => (int) $id ), $formats, array( '%d' ) );
		}

		if ( isset( $changed['date_ranges'] ) ) {
			self::replace_ranges( $id, isset( $stored['date_ranges'] ) ? (array) $stored['date_ranges'] : array() );
		}

		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( ! isset( $changed[ $taxonomy ] ) ) {
				continue;
			}

			self::replace_terms( $id, $taxonomy, isset( $stored[ $taxonomy ] ) ? (array) $stored[ $taxonomy ] : array() );
		}
	}

	/**
	 * Run one prepared write and report whether it succeeded.
	 *
	 * Every value travels as a binding; the only interpolated parts are the
	 * table name from `Schema::table()` and the placeholder list this class
	 * built itself (Requirement 3.10).
	 *
	 * @param string $sql      Statement carrying `$wpdb->prepare()` placeholders.
	 * @param array  $bindings Values for those placeholders, in order.
	 * @return bool
	 */
	protected static function write( $sql, array $bindings ) {
		global $wpdb;

		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $wpdb->prepare( $sql, $bindings ) );

		return false !== $result && '' === (string) $wpdb->last_error;
	}

	/**
	 * Prepare a statement, or return it unchanged when it carries no bindings.
	 *
	 * `$wpdb->prepare()` with an empty binding list is an error in current
	 * WordPress, and a filter set that supplies nothing produces exactly that:
	 * `WHERE 1 = 1` with no values. Skipping the call is the correct handling,
	 * not a shortcut — there is nothing to bind.
	 *
	 * @param string $sql      Statement, possibly carrying placeholders.
	 * @param array  $bindings Values for those placeholders, in order.
	 * @return string
	 */
	protected static function prepared( $sql, array $bindings ) {
		global $wpdb;

		if ( ! $bindings ) {
			return (string) $sql;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (string) $wpdb->prepare( $sql, $bindings );
	}

	/**
	 * Count the enquiries one `where` fragment matches, ignoring pagination.
	 *
	 * The same fragment and the same bindings as the page query, so the total
	 * and the page can never describe different result sets.
	 *
	 * @param string $where    `WHERE` fragment from `EnquiryQuery::build()`.
	 * @param array  $bindings Its bindings, in order.
	 * @return int
	 */
	protected static function count_matching( $where, array $bindings ) {
		global $wpdb;

		$table = Schema::table( 'enquiries' );
		$alias = EnquiryQuery::ALIAS;

		$sql = "SELECT COUNT(*) FROM {$table} {$alias} WHERE " . $where;

		return (int) $wpdb->get_var( self::prepared( $sql, $bindings ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Candidate date ranges of several enquiries, keyed by enquiry identifier.
	 *
	 * One statement for a whole page rather than one per row. Each list comes
	 * back in position order, as `ranges_for()` returns one.
	 *
	 * @param int[] $ids Enquiry identifiers.
	 * @return array<int,array<int,array{start:string,end:string}>>
	 */
	protected static function ranges_for_many( array $ids ) {
		global $wpdb;

		$ids = self::identifiers( $ids );

		if ( ! $ids ) {
			return array();
		}

		$table        = Schema::table( 'dates' );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$sql = "SELECT enquiry_id, start_date, end_date FROM {$table} WHERE enquiry_id IN ( {$placeholders} )"
			. ' ORDER BY position ASC, id ASC';

		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$ranges = array();

		if ( ! is_array( $rows ) ) {
			return $ranges;
		}

		foreach ( $rows as $row ) {
			$id = isset( $row['enquiry_id'] ) ? (int) $row['enquiry_id'] : 0;

			if ( ! isset( $ranges[ $id ] ) ) {
				$ranges[ $id ] = array();
			}

			$ranges[ $id ][] = self::range_of( $row );
		}

		return $ranges;
	}

	/**
	 * Term lists of several enquiries, keyed by enquiry identifier then taxonomy.
	 *
	 * @param int[] $ids Enquiry identifiers.
	 * @return array<int,array<string,string[]>>
	 */
	protected static function terms_for_many( array $ids ) {
		global $wpdb;

		$ids = self::identifiers( $ids );

		if ( ! $ids ) {
			return array();
		}

		$table        = Schema::table( 'terms' );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$sql = "SELECT enquiry_id, taxonomy, term FROM {$table} WHERE enquiry_id IN ( {$placeholders} )"
			. ' ORDER BY id ASC';

		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$terms = array();

		if ( ! is_array( $rows ) ) {
			return $terms;
		}

		foreach ( $rows as $row ) {
			$id       = isset( $row['enquiry_id'] ) ? (int) $row['enquiry_id'] : 0;
			$taxonomy = isset( $row['taxonomy'] ) ? (string) $row['taxonomy'] : '';

			if ( ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
				continue;
			}

			if ( ! isset( $terms[ $id ] ) ) {
				$terms[ $id ] = array_fill_keys( self::TAXONOMIES, array() );
			}

			$terms[ $id ][ $taxonomy ][] = isset( $row['term'] ) ? (string) $row['term'] : '';
		}

		return $terms;
	}

	/**
	 * Reduce a list of identifiers to positive whole numbers, deduplicated.
	 *
	 * @param array $ids Identifiers.
	 * @return int[]
	 */
	protected static function identifiers( array $ids ) {
		$clean = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 && ! in_array( $id, $clean, true ) ) {
				$clean[] = $id;
			}
		}

		return $clean;
	}

	/**
	 * Abandon a failed duplicate: delete the copy, report, leave the source be.
	 *
	 * The relationship is written after every copy step, so reaching here means
	 * the source's `duplicated_to_id` either was never written or was written and
	 * failed — in both cases the source holds no relationship, which is what
	 * Requirement 9.7 asks for.
	 *
	 * @param int    $new_id    Copy written so far.
	 * @param int    $source_id Enquiry that was being copied.
	 * @param string $stage     Stage that failed, for the log line.
	 * @return \WP_Error
	 */
	protected static function abandon_duplicate( $new_id, $source_id, $stage ) {
		self::purge( (int) $new_id );

		Log::write(
			sprintf( 'store: duplicate failed at %s', (string) $stage ),
			array(
				'source_id' => (int) $source_id,
				'new_id'    => (int) $new_id,
			)
		);

		return self::error( 'meh_store_duplicate_failed', 'The enquiry could not be copied.', 500 );
	}

	/**
	 * Abandon a failed test-record delete: roll back and report.
	 *
	 * @param bool   $started Whether a transaction was opened.
	 * @param string $stage   Table or stage that failed, for the log line.
	 * @param string $error   Database error, when there was one.
	 * @return int Always 0: nothing is reported as deleted.
	 */
	protected static function abandon_delete( $started, $stage, $error ) {
		self::rollback( $started );

		Log::write(
			sprintf( 'store: test-record delete failed at %s', (string) $stage ),
			array( 'error' => trim( (string) $error ) )
		);

		return 0;
	}

	/**
	 * Page size for the rejections list: default 25, cap 200.
	 *
	 * Mirrors `EnquiryQuery::to_per_page()` through its constants rather than
	 * repeating the numbers, so raising the cap raises it for both lists.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	protected static function rejections_per_page( $value ) {
		if ( ! is_numeric( $value ) ) {
			return EnquiryQuery::PER_PAGE_DEFAULT;
		}

		$per_page = (int) $value;

		if ( $per_page < 1 ) {
			return EnquiryQuery::PER_PAGE_DEFAULT;
		}

		return min( EnquiryQuery::PER_PAGE_MAX, $per_page );
	}

	/**
	 * Abandon a failed create: roll back, delete anything written, report.
	 *
	 * The rollback and the delete are both run deliberately. On a transactional
	 * engine the rollback does the work and the delete is a no-op against rows
	 * that no longer exist; on a non-transactional one the rollback does nothing
	 * and the delete is the whole of the recovery. Running both means the
	 * all-or-nothing guarantee does not depend on the storage engine
	 * (Requirement 3.13).
	 *
	 * @param bool   $started Whether a transaction was opened.
	 * @param int    $id      Enquiry identifier written so far, 0 when none was.
	 * @param string $stage   Stage that failed, for the log line.
	 * @param string $error   Database error, when there was one.
	 * @param array  $payload Payload snapshot, logged so the enquiry can be recreated.
	 * @return \WP_Error
	 */
	protected static function abandon( $started, $id, $stage, $error, array $payload ) {
		self::rollback( $started );
		self::purge( (int) $id );

		Log::write(
			sprintf( 'store: create failed at %s', (string) $stage ),
			array(
				'enquiry_id' => (int) $id,
				'error'      => trim( (string) $error ),
				'payload'    => $payload,
			)
		);

		return self::error( 'meh_store_create_failed', 'The enquiry could not be stored.', 500 );
	}

	/**
	 * Delete one enquiry and all of its child rows.
	 *
	 * Children first, so an interrupted purge never leaves child rows pointing
	 * at an enquiry that is gone.
	 *
	 * @param int $id Enquiry identifier.
	 * @return void
	 */
	protected static function purge( $id ) {
		global $wpdb;

		$id = (int) $id;

		if ( $id <= 0 ) {
			return;
		}

		$wpdb->delete( Schema::table( 'dates' ), array( 'enquiry_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table( 'terms' ), array( 'enquiry_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table( 'enquiries' ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Open a transaction, when the storage engine supports one.
	 *
	 * @return bool Whether a transaction was opened.
	 */
	protected static function begin() {
		global $wpdb;

		if ( ! self::transactional() ) {
			return false;
		}

		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Commit an open transaction.
	 *
	 * @param bool $started Whether a transaction was opened.
	 * @return bool
	 */
	protected static function commit( $started ) {
		global $wpdb;

		if ( ! $started ) {
			return true;
		}

		$wpdb->last_error = '';

		return false !== $wpdb->query( 'COMMIT' ) && '' === (string) $wpdb->last_error;
	}

	/**
	 * Roll back an open transaction.
	 *
	 * @param bool $started Whether a transaction was opened.
	 * @return void
	 */
	protected static function rollback( $started ) {
		global $wpdb;

		if ( ! $started ) {
			return;
		}

		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Whether the enquiry table's storage engine supports transactions.
	 *
	 * Read once per request from `information_schema`. An unreadable engine
	 * answers false, which costs only the transaction: the compensating delete
	 * in `abandon()` still cleans up.
	 *
	 * @return bool
	 */
	protected static function transactional() {
		global $wpdb;

		if ( null !== self::$transactional ) {
			return self::$transactional;
		}

		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				Schema::table( 'enquiries' )
			)
		);

		$transactional = in_array( strtolower( (string) $engine ), array( 'innodb' ), true );

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter whether enquiry writes are wrapped in a transaction.
			 *
			 * @param bool   $transactional Whether the storage engine supports transactions.
			 * @param string $engine        Engine name reported by the database.
			 */
			$transactional = (bool) apply_filters( 'meh_store_transactional', $transactional, (string) $engine );
		}

		self::$transactional = (bool) $transactional;

		return self::$transactional;
	}

	/**
	 * Serialize the payload snapshot for the `LONGTEXT` column.
	 *
	 * Unicode is left escaped as `\uXXXX` rather than written raw: the escaped
	 * form is plain ASCII, so a four-byte character round-trips whatever charset
	 * the column was created with, and decoding restores it exactly
	 * (Requirement 1.8).
	 *
	 * @param array $payload Payload snapshot.
	 * @return string JSON, or `[]` when the structure will not encode.
	 */
	protected static function encode_payload( array $payload ) {
		$flags = JSON_UNESCAPED_SLASHES;

		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $payload, $flags )
			: json_encode( $payload, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		if ( is_string( $json ) && '' !== $json ) {
			return $json;
		}

		// Invalid UTF-8 is the realistic cause. Substituting the offending
		// characters keeps the rest of the snapshot rather than losing all of it.
		if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
			$json = json_encode( $payload, $flags | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

			if ( is_string( $json ) && '' !== $json ) {
				Log::write( 'store: payload snapshot re-encoded with substituted characters' );

				return $json;
			}
		}

		Log::write( 'store: payload snapshot could not be encoded' );

		return '[]';
	}

	/**
	 * Decode the stored payload snapshot.
	 *
	 * @param mixed $raw Stored column value.
	 * @return array
	 */
	protected static function decode_payload( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		Log::write( 'store: payload snapshot could not be decoded' );

		return array();
	}

	/**
	 * A text column of a raw row, empty when absent or null.
	 *
	 * @param array  $row    Raw row.
	 * @param string $column Column name.
	 * @return string
	 */
	protected static function text( array $row, $column ) {
		if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] ) {
			return '';
		}

		return (string) $row[ $column ];
	}

	/**
	 * A nullable numeric column of a raw row.
	 *
	 * Null stays null rather than becoming `0`, which is what keeps "no guest
	 * count was supplied" distinguishable from a stored `0` (Requirement 1.19).
	 *
	 * @param array  $row    Raw row.
	 * @param string $column Column name.
	 * @return int|null
	 */
	protected static function number( array $row, $column ) {
		if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] || '' === $row[ $column ] ) {
			return null;
		}

		return (int) $row[ $column ];
	}

	/**
	 * A `DATETIME` column of a raw row, empty when absent or never set.
	 *
	 * @param array  $row    Raw row.
	 * @param string $column Column name.
	 * @return string
	 */
	protected static function datetime( array $row, $column ) {
		$value = self::text( $row, $column );

		return self::ZERO_DATETIME === $value ? '' : $value;
	}

	/**
	 * Read a value as a calendar date at day precision.
	 *
	 * @param mixed $value Submitted value.
	 * @return string|null `Y-m-d`, or null when it is not a date.
	 */
	protected static function to_date( $value ) {
		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'Y-m-d' );
		}

		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		// A leading `Y-m-d` covers both a plain date and a full DATETIME, and
		// checkdate() rejects the impossible ones — 2025-02-30 — that a general
		// parser would otherwise roll forward into the next month.
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $parts ) ) {
			$year  = (int) $parts[1];
			$month = (int) $parts[2];
			$day   = (int) $parts[3];

			if ( ! checkdate( $month, $day, $year ) ) {
				return null;
			}

			return sprintf( '%04d-%02d-%02d', $year, $month, $day );
		}

		// Anything else — "16 August 2025", say — is parsed and formatted in one
		// timezone and never converted between two, so the day cannot shift.
		try {
			$parsed = new \DateTimeImmutable( $value );
		} catch ( \Exception $e ) {
			return null;
		}

		return $parsed->format( 'Y-m-d' );
	}

	/**
	 * Build a failure carrying an HTTP status, matching the rest of the plugin.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	protected static function error( $code, $message, $status ) {
		return new \WP_Error( $code, $message, array( 'status' => (int) $status ) );
	}
}
