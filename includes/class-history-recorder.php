<?php
/**
 * Enquiry audit history.
 *
 * An insert-only log. Every change worth explaining later — creation, a status
 * transition, an automatic closure, a note, contact linkage, a booking, a
 * duplication and a field correction — appends one row here and never touches a
 * row that is already there, which is what makes the trail trustworthy
 * (Requirements 11.1, 11.2). The class exposes no update and no delete at all,
 * so append-only is a property of the surface rather than a convention callers
 * are asked to respect.
 *
 * Three details carry the requirements:
 *
 * - `entry_type` is drawn from exactly the eight recognised types
 *   (Requirement 11.3). An unrecognised type is refused rather than stored,
 *   because a trail holding types nothing can render is worse than a missing
 *   entry, and the refusal is logged so the mistake is visible.
 * - `actor_id` is `0` when no WordPress user is authenticated, which is the
 *   system attribution (Requirement 11.5). That is how a cron-driven
 *   `auto_closed` entry and a public intake `created` entry are attributed,
 *   while a `created` entry from the manual route carries the submitting user's
 *   identifier instead.
 * - `context` is JSON in a `LONGTEXT` column, so a `fields_edited` entry stores
 *   every changed field with the previous and the new value of each without any
 *   schema change (Requirement 19.13). The map it stores is exactly the
 *   `changed` map `EnquiryStore::update()` returned, so an entry can neither
 *   name a field that did not change nor omit one that did.
 *
 * Reads come back oldest first, the order the single-enquiry route presents
 * (Requirement 11.4), with the identifier as a tie-break so entries written
 * within the same second still read back in the order they were written.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HistoryRecorder
 */
class HistoryRecorder {

	/**
	 * The eight recognised entry types (Requirement 11.3).
	 */
	const TYPES = array(
		'created',
		'status_changed',
		'auto_closed',
		'note_added',
		'crm_linked',
		'booking_linked',
		'duplicated',
		'fields_edited',
	);

	/**
	 * Attribution used when no WordPress user is authenticated (Requirement 11.5).
	 */
	const SYSTEM_ACTOR = 0;

	/**
	 * Character limit of the `description` column.
	 */
	const DESCRIPTION_LIMIT = 255;

	/**
	 * Append one history entry.
	 *
	 * @param int      $enquiry_id  Enquiry the entry concerns.
	 * @param string   $type        One of self::TYPES.
	 * @param string   $description Human-readable summary of the change.
	 * @param array    $context     Structured detail, stored as JSON. For a
	 *                              `fields_edited` entry this is the `changed`
	 *                              map, `field => array( 'from' => …, 'to' => … )`.
	 * @param int|null $actor       Acting user identifier. Null resolves the
	 *                              current user, which is 0 when none is
	 *                              authenticated.
	 * @return int The new entry identifier, or 0 when nothing was written.
	 */
	public static function record( $enquiry_id, $type, $description, array $context = array(), $actor = null ) {
		global $wpdb;

		$enquiry_id = (int) $enquiry_id;
		$type       = (string) $type;

		if ( $enquiry_id <= 0 ) {
			Log::write( sprintf( 'history: refused %s entry — enquiry id %d is not a positive whole number', $type, $enquiry_id ) );

			return 0;
		}

		if ( ! self::is_recognised( $type ) ) {
			Log::write( sprintf( 'history: refused entry for enquiry %d — unrecognised type %s', $enquiry_id, $type ) );

			return 0;
		}

		$table = Schema::table( 'history' );

		if ( ! isset( $wpdb ) || '' === $table ) {
			Log::write( sprintf( 'history: refused %s entry for enquiry %d — store unavailable', $type, $enquiry_id ) );

			return 0;
		}

		$wpdb->last_error = '';

		// Every value is bound: $wpdb->insert() prepares the statement from the
		// format list below, so no submitted value is interpolated into SQL.
		$written = $wpdb->insert(
			$table,
			array(
				'enquiry_id'  => $enquiry_id,
				'entry_type'  => $type,
				'description' => self::describe( $description ),
				'context'     => self::encode( $context ),
				'actor_id'    => self::actor( $actor ),
				'created_at'  => Clock::mysql(),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $written ) {
			Log::write(
				sprintf(
					'history: %s entry for enquiry %d not written — %s',
					$type,
					$enquiry_id,
					'' !== (string) $wpdb->last_error ? $wpdb->last_error : 'insert reported failure'
				)
			);

			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Every history entry of one enquiry, oldest first (Requirement 11.4).
	 *
	 * @param int $enquiry_id Enquiry to read.
	 * @return array<int,array{id:int,enquiry_id:int,entry_type:string,description:string,context:array,actor_id:int,created_at:string}>
	 */
	public static function for_enquiry( $enquiry_id ) {
		global $wpdb;

		$enquiry_id = (int) $enquiry_id;
		$table      = Schema::table( 'history' );

		if ( $enquiry_id <= 0 || ! isset( $wpdb ) || '' === $table ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// The identifier tie-break keeps entries written within the same
				// second in the order they were written.
				"SELECT id, enquiry_id, entry_type, description, context, actor_id, created_at
				FROM {$table}
				WHERE enquiry_id = %d
				ORDER BY created_at ASC, id ASC",
				$enquiry_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$entries = array();

		foreach ( $rows as $row ) {
			$entries[] = self::hydrate( (array) $row );
		}

		return $entries;
	}

	/**
	 * The context of each enquiry's most recent entry of one type.
	 *
	 * The batch read behind a derived field: a caller that needs one fact out of
	 * the trail for a whole page of enquiries pays one query rather than one per
	 * row, and never has to hydrate entries it will not show.
	 *
	 * Only the latest entry per enquiry survives, which is what the descending
	 * order and the `isset` guard together achieve — the first row seen for an
	 * identifier is its most recent, so later ones are stepped over. The
	 * identifier tie-break matches `for_enquiry()`, so "most recent" means the
	 * same thing in both, including for entries written in the same second.
	 *
	 * An enquiry with no entry of the type is absent from the result rather than
	 * present with an empty context, so a caller can tell "nothing recorded"
	 * from "recorded with nothing in it".
	 *
	 * @param int[]  $enquiry_ids Enquiries to read.
	 * @param string $type        One of self::TYPES.
	 * @return array<int,array> Decoded context, keyed by enquiry identifier.
	 */
	public static function latest_context_many( array $enquiry_ids, $type ) {
		global $wpdb;

		$type  = (string) $type;
		$table = Schema::table( 'history' );
		$ids   = array();

		foreach ( $enquiry_ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		if ( array() === $ids || ! self::is_recognised( $type ) || ! isset( $wpdb ) || '' === $table ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$sql = "SELECT enquiry_id, context FROM {$table}"
			. " WHERE entry_type = %s AND enquiry_id IN ( {$placeholders} )"
			. ' ORDER BY created_at DESC, id DESC';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( $sql, array_merge( array( $type ), $ids ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$contexts = array();

		foreach ( $rows as $row ) {
			$id = isset( $row['enquiry_id'] ) ? (int) $row['enquiry_id'] : 0;

			if ( $id <= 0 || isset( $contexts[ $id ] ) ) {
				continue;
			}

			$entry = self::hydrate( (array) $row );

			$contexts[ $id ] = $entry['context'];
		}

		return $contexts;
	}

	/**
	 * Whether a value is one of the eight recognised entry types.
	 *
	 * @param string $type Candidate type.
	 * @return bool
	 */
	public static function is_recognised( $type ) {
		return in_array( (string) $type, self::TYPES, true );
	}

	/**
	 * Resolve the acting user identifier.
	 *
	 * Null asks for the current user, which is 0 when none is authenticated;
	 * that 0 is the system attribution (Requirement 11.5). A negative value is
	 * treated the same way, because the column is unsigned and no negative
	 * identifier could be attributed to anyone.
	 *
	 * @param int|null $actor Supplied actor, or null to resolve the current user.
	 * @return int
	 */
	protected static function actor( $actor ) {
		if ( null === $actor ) {
			$actor = function_exists( 'get_current_user_id' ) ? get_current_user_id() : self::SYSTEM_ACTOR;
		}

		$actor = (int) $actor;

		return $actor > 0 ? $actor : self::SYSTEM_ACTOR;
	}

	/**
	 * Prepare a description for the `VARCHAR(255)` column.
	 *
	 * Tags are stripped and the value trimmed, then clipped to the column width
	 * counting characters rather than bytes, so an over-long or multi-byte
	 * description is stored shortened rather than rejected.
	 *
	 * @param string $description Supplied description.
	 * @return string
	 */
	protected static function describe( $description ) {
		$description = (string) $description;
		$description = function_exists( 'wp_strip_all_tags' )
			? wp_strip_all_tags( $description )
			: strip_tags( $description );

		// A value holding invalid UTF-8 makes the collapse return null; the
		// stripped value is then used as it stands rather than becoming empty.
		$collapsed   = preg_replace( '/\s+/u', ' ', $description );
		$description = trim( is_string( $collapsed ) ? $collapsed : $description );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $description, 'UTF-8' ) > self::DESCRIPTION_LIMIT ) {
			return mb_substr( $description, 0, self::DESCRIPTION_LIMIT, 'UTF-8' );
		}

		if ( ! function_exists( 'mb_strlen' ) && strlen( $description ) > self::DESCRIPTION_LIMIT ) {
			return substr( $description, 0, self::DESCRIPTION_LIMIT );
		}

		return $description;
	}

	/**
	 * Encode the context blob.
	 *
	 * An empty context stores as an empty string rather than as `{}` or `[]`, so
	 * a reader never has to distinguish the two, and a value that will not
	 * encode stores as an empty string rather than failing the insert: a history
	 * entry with a thin context is still worth having.
	 *
	 * @param array $context Structured detail.
	 * @return string
	 */
	protected static function encode( array $context ) {
		if ( array() === $context ) {
			return '';
		}

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$json  = function_exists( 'wp_json_encode' )
			? wp_json_encode( $context, $flags )
			: json_encode( $context, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Shape one stored row as a history entry.
	 *
	 * `context` always reads back as an array, empty when nothing was stored or
	 * when what was stored will not decode, so a caller never has to test it.
	 *
	 * @param array $row Stored row.
	 * @return array{id:int,enquiry_id:int,entry_type:string,description:string,context:array,actor_id:int,created_at:string}
	 */
	protected static function hydrate( array $row ) {
		$context = isset( $row['context'] ) ? (string) $row['context'] : '';
		$decoded = '' === $context ? array() : json_decode( $context, true );

		return array(
			'id'          => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'enquiry_id'  => isset( $row['enquiry_id'] ) ? (int) $row['enquiry_id'] : 0,
			'entry_type'  => isset( $row['entry_type'] ) ? (string) $row['entry_type'] : '',
			'description' => isset( $row['description'] ) ? (string) $row['description'] : '',
			'context'     => is_array( $decoded ) ? $decoded : array(),
			'actor_id'    => isset( $row['actor_id'] ) ? (int) $row['actor_id'] : self::SYSTEM_ACTOR,
			'created_at'  => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
		);
	}
}

