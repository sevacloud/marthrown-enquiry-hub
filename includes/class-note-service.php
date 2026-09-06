<?php
/**
 * Internal staff notes.
 *
 * A note is the running commentary on an enquiry: what was discussed, what was
 * promised, what is still outstanding. The service stores the body, the
 * authoring user and the creation time against the enquiry identifier
 * (Requirement 10.2), places no ceiling on how many notes one enquiry may carry
 * (Requirement 10.3), appends one `note_added` history entry per stored note
 * (Requirement 10.6), and reads back most recent first, which is the order the
 * detail panel shows (Requirement 10.7).
 *
 * Two rejections, both 400, both writing nothing:
 *
 * - A body holding nothing but whitespace once HTML tags are stripped
 *   (Requirement 10.4). Stripping first is what makes `<p></p>` and `<br>` empty
 *   rather than three and four characters of content.
 * - A body longer than `MAX_LENGTH` characters, with the limit named in the
 *   message so the author knows what to cut to (Requirement 10.5).
 *
 * The over-long body is *rejected*, deliberately unlike the enquiry field
 * values the Validator truncates (Requirement 3.7). A truncated `first_name` is
 * still the person's name; a truncated note is a sentence that stops mid-word
 * and reads as though the author said something they did not. Silently
 * discarding the end of somebody's account of a phone call is worse than asking
 * them to shorten it, so nothing here truncates.
 *
 * Length is counted on the stripped and trimmed body, in characters rather than
 * bytes, so a note of accented or non-Latin text is measured the same way as one
 * of plain ASCII and surrounding whitespace never pushes an otherwise acceptable
 * note over the limit.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NoteService
 */
class NoteService {

	/**
	 * Character limit of a note body (Requirement 10.5).
	 */
	const MAX_LENGTH = 5000;

	/**
	 * The history entry type a stored note appends (Requirement 10.6).
	 */
	const HISTORY_TYPE = 'note_added';

	/**
	 * Attribution used when no WordPress user is authenticated.
	 */
	const SYSTEM_AUTHOR = 0;

	/**
	 * Add one note to an enquiry.
	 *
	 * The order of work matters: both validations run before anything is read
	 * or written, so a rejected body leaves no note row and no history entry
	 * behind. The history entry is appended only after the note row is stored,
	 * so the trail can never claim a note that does not exist.
	 *
	 * @param int      $enquiry_id Enquiry the note concerns.
	 * @param string   $body       Note body as submitted.
	 * @param int|null $actor      Authoring user identifier. Null resolves the
	 *                             current user, which is 0 when none is
	 *                             authenticated.
	 * @return int|\WP_Error The new note identifier, or a failure carrying an
	 *                       HTTP status.
	 */
	public static function add( $enquiry_id, $body, $actor = null ) {
		global $wpdb;

		$enquiry_id = (int) $enquiry_id;

		if ( $enquiry_id <= 0 ) {
			return self::error( 'meh_note_invalid_id', 'An enquiry identifier is required.', 400 );
		}

		$body = self::sanitise( $body );

		// Requirement 10.4: whitespace-only once tags are gone is no note at all.
		if ( '' === $body ) {
			return self::error( 'meh_note_empty', 'A note needs a body.', 400 );
		}

		// Requirement 10.5: the message names the limit, because "too long" on
		// its own tells the author nothing about how much to cut.
		if ( self::length( $body ) > self::MAX_LENGTH ) {
			return self::error(
				'meh_note_too_long',
				sprintf( 'A note cannot exceed %d characters.', self::MAX_LENGTH ),
				400
			);
		}

		$table = Schema::table( 'notes' );

		if ( ! isset( $wpdb ) || '' === $table ) {
			return self::error( 'meh_note_store_unavailable', 'The note store is unavailable.', 500 );
		}

		if ( null === EnquiryStore::find( $enquiry_id ) ) {
			return self::error( 'meh_note_not_found', 'The enquiry does not exist.', 404 );
		}

		$wpdb->last_error = '';

		// Every value is bound: $wpdb->insert() prepares the statement from the
		// format list below, so no submitted value is interpolated into SQL.
		$written = $wpdb->insert(
			$table,
			array(
				'enquiry_id' => $enquiry_id,
				'body'       => $body,
				'author_id'  => self::author( $actor ),
				'created_at' => Clock::mysql(),
			),
			array( '%d', '%s', '%d', '%s' )
		);

		if ( false === $written ) {
			Log::write(
				'notes: note not written',
				array(
					'enquiry_id' => $enquiry_id,
					'error'      => '' !== (string) $wpdb->last_error ? $wpdb->last_error : 'insert reported failure',
				)
			);

			return self::error( 'meh_note_not_stored', 'The note could not be stored.', 500 );
		}

		$note_id = (int) $wpdb->insert_id;

		HistoryRecorder::record(
			$enquiry_id,
			self::HISTORY_TYPE,
			'Note added.',
			array( 'note_id' => $note_id ),
			$actor
		);

		return $note_id;
	}

	/**
	 * Every note of one enquiry, most recent first (Requirement 10.7).
	 *
	 * The identifier tie-break keeps notes written within the same second in the
	 * reverse of the order they were written, so "most recent first" holds even
	 * when the `DATETIME` values are equal.
	 *
	 * @param int $enquiry_id Enquiry to read.
	 * @return array<int,array{id:int,enquiry_id:int,body:string,author_id:int,created_at:string}>
	 */
	public static function for_enquiry( $enquiry_id ) {
		global $wpdb;

		$enquiry_id = (int) $enquiry_id;
		$table      = Schema::table( 'notes' );

		if ( $enquiry_id <= 0 || ! isset( $wpdb ) || '' === $table ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, enquiry_id, body, author_id, created_at
				FROM {$table}
				WHERE enquiry_id = %d
				ORDER BY created_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$enquiry_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$notes = array();

		foreach ( $rows as $row ) {
			$notes[] = self::hydrate( (array) $row );
		}

		return $notes;
	}

	/**
	 * Strip HTML tags and trim, without truncating.
	 *
	 * The same shape as the Validator's text sanitisation, minus the truncation
	 * step: a note is rejected on length rather than clipped.
	 *
	 * @param mixed $body Submitted body.
	 * @return string
	 */
	protected static function sanitise( $body ) {
		if ( ! is_scalar( $body ) ) {
			return '';
		}

		$text = (string) $body;

		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return trim( wp_strip_all_tags( $text ) );
		}

		// Same shape as wp_strip_all_tags(): drop script and style content
		// before dropping tags, so their bodies do not survive as text.
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );

		return trim( strip_tags( (string) $text ) );
	}

	/**
	 * Character count of a sanitised body, counting characters not bytes.
	 *
	 * @param string $body Sanitised body.
	 * @return int
	 */
	protected static function length( $body ) {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $body, 'UTF-8' ) : strlen( $body );
	}

	/**
	 * Resolve the authoring user identifier.
	 *
	 * Null asks for the current user, which is 0 when none is authenticated. A
	 * negative value is treated the same way, because the column is unsigned
	 * and no negative identifier could be attributed to anyone.
	 *
	 * @param int|null $actor Supplied author, or null to resolve the current user.
	 * @return int
	 */
	protected static function author( $actor ) {
		if ( null === $actor ) {
			$actor = function_exists( 'get_current_user_id' ) ? get_current_user_id() : self::SYSTEM_AUTHOR;
		}

		$actor = (int) $actor;

		return $actor > 0 ? $actor : self::SYSTEM_AUTHOR;
	}

	/**
	 * Shape one stored row as a note.
	 *
	 * @param array $row Stored row.
	 * @return array{id:int,enquiry_id:int,body:string,author_id:int,created_at:string}
	 */
	protected static function hydrate( array $row ) {
		return array(
			'id'         => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'enquiry_id' => isset( $row['enquiry_id'] ) ? (int) $row['enquiry_id'] : 0,
			'body'       => isset( $row['body'] ) ? (string) $row['body'] : '',
			'author_id'  => isset( $row['author_id'] ) ? (int) $row['author_id'] : self::SYSTEM_AUTHOR,
			'created_at' => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
		);
	}

	/**
	 * Build a failure carrying an HTTP status, matching the store's shape.
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
