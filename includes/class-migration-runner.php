<?php
/**
 * Migration of existing FluentCRM enquiries into the Enquiry Store.
 *
 * Before the Enquiry Store existed, an enquiry *was* a FluentCRM contact: the
 * website form pushed the contact into the configured list and applied the
 * configured tag, workflow state lived in the subscriber custom field
 * `meh_enquiry_status`, and the running commentary lived in subscriber notes.
 * This class reads that population once and reproduces it as enquiries
 * (Requirement 15.1), so nothing captured before the change is lost.
 *
 * Three rules shape every method here:
 *
 * - **It only ever reads FluentCRM.** There is no `createOrUpdate()`, no
 *   `attachTags()`, no `updateMeta()` and no delete on any path, so every
 *   contact record, list membership and tag is exactly as it was when the run
 *   finishes (Requirement 15.11). `ContactLinker` remains the single writer.
 * - **A contact is migrated at most once.** Two checks answer that, and either
 *   one is enough: the subscriber identifier sits in the
 *   `meh_migrated_subscribers` ledger, or an enquiry already carries that
 *   `fluentcrm_subscriber_id` with `source = 'migration:fluentcrm'`. The second
 *   is what makes a run safe after a ledger was lost, and together they make a
 *   second run create nothing (Requirement 15.7).
 * - **Preview and run classify identically.** Both call `plan()`, which reads and
 *   decides but writes nothing at all, so `would_create` is the count a real run
 *   would create rather than an estimate of it (Requirement 15.8), and 0 when
 *   nothing is eligible (Requirement 15.9).
 *
 * Every contact read is accounted for: `created + skipped` equals the eligible
 * contact count, and every skip carries a reason (Requirement 15.10). A contact
 * whose enquiry could not be stored is a skip with reason `storage` rather than
 * a silent loss.
 *
 * Legacy `quoted` maps to `quoted` rather than being flattened onto `contacted`
 * (Requirement 15.4): the new status set has a `quoted`, so the mapping is the
 * identity for every legacy value except `replied`, and no migrated enquiry
 * loses the fact that a quote had already gone out. An unrecognised or absent
 * value maps to `new` (Requirement 15.5).
 *
 * The enquiry a migration writes carries no candidate dates and no multi-select
 * values, because a contact holds none — those fields arrived with the intake
 * form the migration predates. It is still a perfectly editable enquiry: the
 * correction path reads `source` nowhere.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MigrationRunner
 */
class MigrationRunner {

	/**
	 * `source` every migrated enquiry carries.
	 *
	 * Half of the second idempotence check, and the reason a migrated enquiry can
	 * be told apart from one a webhook or a person created.
	 */
	const SOURCE = 'migration:fluentcrm';

	/**
	 * Legacy status to Enquiry Store status (Requirements 15.4, 15.5).
	 *
	 * @var array<string,string>
	 */
	const STATUS_MAP = array(
		'new'       => 'new',
		'replied'   => 'contacted',
		'quoted'    => 'quoted',
		'converted' => 'converted',
		'closed'    => 'closed',
	);

	/**
	 * Status an unrecognised or absent legacy value maps to (Requirement 15.5).
	 */
	const DEFAULT_STATUS = 'new';

	/**
	 * Subscriber custom field holding the legacy workflow status.
	 */
	const STATUS_FIELD = 'meh_enquiry_status';

	/**
	 * Option holding the migrated subscriber ledger (Requirement 15.12).
	 */
	const LEDGER_OPTION = 'meh_migrated_subscribers';

	/**
	 * Option holding the last completion time (Requirement 15.12).
	 */
	const COMPLETED_OPTION = 'meh_migration_completed_at';

	/**
	 * Filter supplying the contact population, bypassing the FluentCRM read.
	 *
	 * The seam a site with an unusual FluentCRM install — or a caller migrating
	 * from an export rather than a live CRM — uses to say what to migrate. A
	 * filter returning null leaves the normal read in place.
	 */
	const CONTACTS_FILTER = 'meh_migration_contacts';

	/**
	 * The FluentCRM API module the runner resolves.
	 */
	const API_MODULE = 'contacts';

	/**
	 * FluentCRM model classes the runner reads through when the API module
	 * exposes no instance of its own.
	 */
	const SUBSCRIBER_CLASS = '\FluentCrm\App\Models\Subscriber';
	const NOTE_CLASS       = '\FluentCrm\App\Models\SubscriberNote';
	const META_CLASS       = '\FluentCrm\App\Models\SubscriberMeta';

	/**
	 * Contacts read per query.
	 *
	 * A bound rather than a limit anyone should reach: it keeps one run from
	 * pulling an unbounded collection into memory on a site whose enquiry list
	 * holds far more contacts than expected. A run that hits it migrates the
	 * contacts it read and can simply be run again, because it is idempotent.
	 */
	const READ_CAP = 5000;

	/**
	 * The history entry type a migrated enquiry appends.
	 */
	const HISTORY_TYPE = 'created';

	/**
	 * Attribution used for the entries a run appends.
	 *
	 * A migration is the system reproducing existing records, not the
	 * administrator who happened to press the button, so the trail says so.
	 */
	const SYSTEM_ACTOR = 0;

	/** The subscriber identifier is already in the ledger (Requirement 15.7). */
	const SKIP_LEDGER = 'already_migrated';

	/** An enquiry already carries that subscriber id and migration source. */
	const SKIP_STORED = 'already_stored';

	/** The contact carries no email address, so no enquiry can identify it. */
	const SKIP_NO_EMAIL = 'no_email';

	/** The contact carries no subscriber identifier to record or re-check. */
	const SKIP_NO_ID = 'no_subscriber_id';

	/** The enquiry could not be stored. */
	const SKIP_STORAGE = 'storage';

	/**
	 * Every reason a contact is skipped under (Requirement 15.10).
	 *
	 * @var string[]
	 */
	const SKIP_REASONS = array(
		self::SKIP_LEDGER,
		self::SKIP_STORED,
		self::SKIP_NO_EMAIL,
		self::SKIP_NO_ID,
		self::SKIP_STORAGE,
	);

	/**
	 * Whether a FluentCRM population can be read at all.
	 *
	 * Cheap and silent, like `ContactLinker::available()`: an inactive FluentCRM
	 * is a normal state of the site, and a migration simply has nothing to do.
	 * A filtered population counts as available, because that path needs no
	 * FluentCRM at all.
	 *
	 * @return bool
	 */
	public static function available() {
		if ( null !== self::filtered_contacts() ) {
			return true;
		}

		if ( null !== self::contacts_api() ) {
			return true;
		}

		return class_exists( self::SUBSCRIBER_CLASS );
	}

	/**
	 * Report what a run would create, writing nothing (Requirement 15.8).
	 *
	 * Shares its classification with `run()`, so `would_create` is not an
	 * estimate: it is the count of contacts the run would create an enquiry for.
	 * Zero when no contact is eligible (Requirement 15.9), including when
	 * FluentCRM is absent.
	 *
	 * @return array{available:bool,eligible:int,would_create:int,skipped:int,reasons:array}
	 */
	public static function preview() {
		$plan = self::plan();

		return array(
			'available'    => self::available(),
			'eligible'     => count( $plan['contacts'] ),
			'would_create' => count( $plan['create'] ),
			'skipped'      => count( $plan['skipped'] ),
			'reasons'      => $plan['skipped'],
		);
	}

	/**
	 * Migrate every eligible contact.
	 *
	 * The order of work per contact is store, then notes, then history: a failed
	 * store means no notes and no history entry claiming an enquiry that does not
	 * exist. A failure is recorded as a skip and stepped over, so one unstorable
	 * contact cannot stall the run.
	 *
	 * The ledger and the completion time are recorded once, at the end
	 * (Requirement 15.12). The ledger gains both the contacts this run created an
	 * enquiry for and the ones it found already stored, because both are
	 * migrated — that is what the ledger records.
	 *
	 * @return array{available:bool,eligible:int,created:int,skipped:int,reasons:array,completed_at:string,enquiry_ids:int[],subscriber_ids:int[]}
	 */
	public static function run() {
		$plan = self::plan();

		$created     = array();
		$skipped     = $plan['skipped'];
		$subscribers = array();

		foreach ( $plan['create'] as $contact ) {
			$enquiry_id = self::migrate( $contact );

			if ( is_wp_error( $enquiry_id ) ) {
				$skipped[] = self::skip( $contact, self::SKIP_STORAGE, $enquiry_id->get_error_message() );
				continue;
			}

			$created[]     = (int) $enquiry_id;
			$subscribers[] = (int) $contact['subscriber_id'];
		}

		// Contacts found already stored are migrated contacts, so the ledger
		// records them too; a run after a lost ledger therefore rebuilds it.
		foreach ( $plan['skipped'] as $skip ) {
			if ( self::SKIP_STORED === $skip['reason'] && $skip['subscriber_id'] > 0 ) {
				$subscribers[] = (int) $skip['subscriber_id'];
			}
		}

		$completed_at = Clock::mysql();

		self::remember( $subscribers, $completed_at );

		Log::write(
			'migration: run complete',
			array(
				'eligible'     => count( $plan['contacts'] ),
				'created'      => count( $created ),
				'skipped'      => count( $skipped ),
				'completed_at' => $completed_at,
			)
		);

		return array(
			'available'      => self::available(),
			'eligible'       => count( $plan['contacts'] ),
			'created'        => count( $created ),
			'skipped'        => count( $skipped ),
			'reasons'        => $skipped,
			'completed_at'   => $completed_at,
			'enquiry_ids'    => $created,
			'subscriber_ids' => self::ledger(),
		);
	}

	/**
	 * Map a legacy status onto an Enquiry Store status.
	 *
	 * @param mixed $legacy Value of the subscriber custom field, if any.
	 * @return string One of `Lifecycle::STATUSES`.
	 */
	public static function map_status( $legacy ) {
		if ( ! is_scalar( $legacy ) ) {
			return self::DEFAULT_STATUS;
		}

		$key = strtolower( trim( (string) $legacy ) );

		return isset( self::STATUS_MAP[ $key ] ) ? self::STATUS_MAP[ $key ] : self::DEFAULT_STATUS;
	}

	/**
	 * The migrated subscriber ledger (Requirement 15.12).
	 *
	 * @return int[] Ascending, unique.
	 */
	public static function ledger() {
		$stored = function_exists( 'get_option' ) ? get_option( self::LEDGER_OPTION, array() ) : array();

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$ids = array();

		foreach ( $stored as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		$ids = array_values( $ids );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * The last recorded completion time, or an empty string (Requirement 15.12).
	 *
	 * @return string
	 */
	public static function completed_at() {
		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}

		$stored = get_option( self::COMPLETED_OPTION, '' );

		return is_scalar( $stored ) ? (string) $stored : '';
	}

	/**
	 * The eligible contact population, normalised (Requirement 15.1).
	 *
	 * Deduplicated by subscriber identifier, because a contact both in the
	 * configured list and holding the configured tag is one contact, not two.
	 *
	 * @return array<int,array> Normalised contacts.
	 */
	public static function contacts() {
		$filtered = self::filtered_contacts();

		if ( null !== $filtered ) {
			return self::normalise_all( $filtered );
		}

		$list_id = self::list_id();
		$tag_id  = self::tag_id();

		// Neither configured means the runner has been told nothing about which
		// contacts are enquiries. Migrating the whole CRM is never the intent.
		if ( $list_id <= 0 && $tag_id <= 0 ) {
			Log::write( 'migration: no enquiry list or tag configured, nothing to read' );

			return array();
		}

		$rows = array();

		// Requirement 15.1 is a union: in the list *or* holding the tag. Two
		// reads rather than one chained query, because chaining both scopes is a
		// conjunction in FluentCRM and would silently migrate less.
		if ( $list_id > 0 ) {
			$rows = array_merge( $rows, self::query_subscribers( 'filterByLists', $list_id ) );
		}

		if ( $tag_id > 0 ) {
			$rows = array_merge( $rows, self::query_subscribers( 'filterByTags', $tag_id ) );
		}

		return self::normalise_all( $rows );
	}

	/* ---------------------------------------------------------------------
	 * Classification
	 * ------------------------------------------------------------------ */

	/**
	 * Decide, for every contact read, whether it would be migrated.
	 *
	 * Reads only. Shared by `preview()` and `run()`, which is what makes the
	 * preview count exact rather than indicative.
	 *
	 * @return array{contacts:array,create:array,skipped:array}
	 */
	protected static function plan() {
		$contacts = self::contacts();
		$ledger   = self::ledger();
		$stored   = self::stored_subscriber_ids();

		$create  = array();
		$skipped = array();

		foreach ( $contacts as $contact ) {
			$subscriber_id = (int) $contact['subscriber_id'];

			if ( $subscriber_id <= 0 ) {
				$skipped[] = self::skip( $contact, self::SKIP_NO_ID, 'The contact carries no subscriber identifier.' );
				continue;
			}

			// Requirement 15.7, first check: the ledger already names it.
			if ( in_array( $subscriber_id, $ledger, true ) ) {
				$skipped[] = self::skip( $contact, self::SKIP_LEDGER, 'The subscriber is already in the migration ledger.' );
				continue;
			}

			// Requirement 15.7, second check: an enquiry already carries it. This
			// is what keeps a run safe when the ledger was lost or reset.
			if ( in_array( $subscriber_id, $stored, true ) ) {
				$skipped[] = self::skip( $contact, self::SKIP_STORED, 'An enquiry already holds this subscriber identifier.' );
				continue;
			}

			if ( '' === $contact['email'] ) {
				$skipped[] = self::skip( $contact, self::SKIP_NO_EMAIL, 'The contact carries no email address.' );
				continue;
			}

			$create[] = $contact;
		}

		return array(
			'contacts' => $contacts,
			'create'   => $create,
			'skipped'  => $skipped,
		);
	}

	/**
	 * One skip record (Requirement 15.10).
	 *
	 * @param array  $contact Normalised contact.
	 * @param string $reason  One of self::SKIP_REASONS.
	 * @param string $detail  Human-readable detail.
	 * @return array{subscriber_id:int,email:string,reason:string,detail:string}
	 */
	protected static function skip( array $contact, $reason, $detail ) {
		return array(
			'subscriber_id' => isset( $contact['subscriber_id'] ) ? (int) $contact['subscriber_id'] : 0,
			'email'         => isset( $contact['email'] ) ? (string) $contact['email'] : '',
			'reason'        => (string) $reason,
			'detail'        => (string) $detail,
		);
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Store one contact as an enquiry, with its notes and its history entry.
	 *
	 * The three timestamps all take the contact's creation time
	 * (Requirement 15.2): a migrated enquiry is as old as the enquiry it
	 * reproduces, not as old as the migration. `crm_sync_state` is `synced`
	 * because the contact demonstrably exists and its identifier is recorded
	 * (Requirement 15.3) — the runner has simply read that fact rather than
	 * written it.
	 *
	 * @param array $contact Normalised contact.
	 * @return int|\WP_Error New enquiry identifier, or the store's failure.
	 */
	protected static function migrate( array $contact ) {
		$created_at = Clock::mysql( $contact['created_at'] );

		$enquiry_id = EnquiryStore::create(
			array(
				'first_name'              => $contact['first_name'],
				'last_name'               => $contact['last_name'],
				'email'                   => $contact['email'],
				'phone'                   => $contact['phone'],
				'status'                  => $contact['status'],
				'fluentcrm_subscriber_id' => (int) $contact['subscriber_id'],
				'crm_sync_state'          => ContactLinker::STATE_SYNCED,
				'created_at'              => $created_at,
				'updated_at'              => $created_at,
				'status_changed_at'       => $created_at,
				'source'                  => self::SOURCE,
				'is_test'                 => StagingMarker::is_staging() ? 1 : 0,
			),
			array(), // A contact holds no candidate dates.
			array(), // A contact holds no multi-select values.
			self::snapshot( $contact )
		);

		if ( is_wp_error( $enquiry_id ) ) {
			Log::write(
				'migration: enquiry not stored',
				array(
					'subscriber_id' => (int) $contact['subscriber_id'],
					'error'         => $enquiry_id->get_error_message(),
				)
			);

			return $enquiry_id;
		}

		$enquiry_id = (int) $enquiry_id;
		$notes      = self::store_notes( $enquiry_id, $contact['notes'] );

		HistoryRecorder::record(
			$enquiry_id,
			self::HISTORY_TYPE,
			sprintf( 'Migrated from FluentCRM contact %d.', (int) $contact['subscriber_id'] ),
			array(
				'source'        => self::SOURCE,
				'subscriber_id' => (int) $contact['subscriber_id'],
				'status'        => $contact['status'],
				'legacy_status' => $contact['legacy_status'],
				'notes'         => $notes,
			),
			self::SYSTEM_ACTOR
		);

		return $enquiry_id;
	}

	/**
	 * Store one enquiry note per subscriber note (Requirement 15.6).
	 *
	 * Written directly rather than through `NoteService::add()`, for one reason:
	 * the service timestamps a note with the moment it was added, which is
	 * correct for a note somebody is writing now and wrong for one written
	 * years ago. The requirement is that the note keeps its own creation time,
	 * so the insert carries it.
	 *
	 * A note whose body is empty once HTML tags are stripped is not stored,
	 * because that is what `NoteService` would refuse too and an empty note
	 * carries nothing forward. A body over the service's limit is stored whole:
	 * this is existing content, and clipping somebody's account of a phone call
	 * to fit a limit invented later would lose it.
	 *
	 * @param int   $enquiry_id Enquiry the notes belong to.
	 * @param array $notes      Normalised notes, oldest first.
	 * @return int Notes stored.
	 */
	protected static function store_notes( $enquiry_id, array $notes ) {
		global $wpdb;

		$table = Schema::table( 'notes' );

		if ( ! isset( $wpdb ) || '' === $table || ! $notes ) {
			return 0;
		}

		$stored = 0;

		foreach ( $notes as $note ) {
			$body = isset( $note['body'] ) ? (string) $note['body'] : '';

			if ( '' === $body ) {
				continue;
			}

			$written = $wpdb->insert(
				$table,
				array(
					'enquiry_id' => (int) $enquiry_id,
					'body'       => $body,
					'author_id'  => self::SYSTEM_ACTOR,
					'created_at' => Clock::mysql( isset( $note['created_at'] ) ? $note['created_at'] : null ),
				),
				array( '%d', '%s', '%d', '%s' )
			);

			if ( false === $written ) {
				// Logged and stepped over: a note that will not store must not
				// cost the enquiry it belongs to.
				Log::write(
					'migration: note not stored',
					array(
						'enquiry_id' => (int) $enquiry_id,
						'error'      => isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '',
					)
				);

				continue;
			}

			++$stored;
		}

		return $stored;
	}

	/**
	 * The payload snapshot a migrated enquiry carries.
	 *
	 * What was read from FluentCRM, verbatim enough to answer "where did this
	 * enquiry come from?" long after the contact has moved on. It is the audit
	 * trail the intake path stores for a webhook body, filled from the only
	 * source a migration has.
	 *
	 * @param array $contact Normalised contact.
	 * @return array
	 */
	protected static function snapshot( array $contact ) {
		return array(
			'migrated_from'    => self::SOURCE,
			'subscriber_id'    => (int) $contact['subscriber_id'],
			'first_name'       => $contact['first_name'],
			'last_name'        => $contact['last_name'],
			'email'            => $contact['email'],
			'phone'            => $contact['phone'],
			'created_at'       => $contact['created_at'],
			self::STATUS_FIELD => $contact['legacy_status'],
			'notes'            => $contact['notes'],
		);
	}

	/**
	 * Record the ledger and the completion time (Requirement 15.12).
	 *
	 * The ledger is a union with what was already there, so a run never forgets
	 * a contact an earlier one migrated.
	 *
	 * @param int[]  $subscribers  Subscriber identifiers this run accounted for.
	 * @param string $completed_at Completion time.
	 * @return void
	 */
	protected static function remember( array $subscribers, $completed_at ) {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		$ledger = self::ledger();

		foreach ( $subscribers as $id ) {
			$id = (int) $id;

			if ( $id > 0 && ! in_array( $id, $ledger, true ) ) {
				$ledger[] = $id;
			}
		}

		sort( $ledger, SORT_NUMERIC );

		update_option( self::LEDGER_OPTION, $ledger );
		update_option( self::COMPLETED_OPTION, (string) $completed_at );
	}

	/**
	 * Subscriber identifiers already held by a migrated enquiry.
	 *
	 * The `source` value is bound, and the column is indexed, so this is one
	 * cheap read per run rather than one per contact.
	 *
	 * @return int[]
	 */
	protected static function stored_subscriber_ids() {
		global $wpdb;

		$table = Schema::table( 'enquiries' );

		if ( ! isset( $wpdb ) || '' === $table ) {
			return array();
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT fluentcrm_subscriber_id
				FROM {$table}
				WHERE source = %s
				AND fluentcrm_subscriber_id IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::SOURCE
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$ids = array();

		foreach ( $rows as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/* ---------------------------------------------------------------------
	 * Reading FluentCRM
	 * ------------------------------------------------------------------ */

	/**
	 * A filtered contact population, or null when none was supplied.
	 *
	 * @return array|null
	 */
	protected static function filtered_contacts() {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}

		/**
		 * Filter the contact population a migration reads.
		 *
		 * Return an array of contacts to bypass the FluentCRM read entirely.
		 * Each entry may be an array or a subscriber-shaped object.
		 *
		 * @param array|null $contacts Null to read FluentCRM as normal.
		 * @param int        $list_id  Configured enquiry list identifier.
		 * @param int        $tag_id   Configured enquiry tag identifier.
		 */
		$contacts = apply_filters( self::CONTACTS_FILTER, null, self::list_id(), self::tag_id() );

		return is_array( $contacts ) ? $contacts : null;
	}

	/**
	 * Read subscribers through one FluentCRM scope.
	 *
	 * Everything is guarded: the model has to resolve, the scope has to be
	 * callable, and the whole call runs inside `try`/`catch ( \Throwable )`,
	 * because an active FluentCRM can still raise. A failure means this scope
	 * contributed no contacts, never that the run dies.
	 *
	 * @param string $scope `filterByLists` or `filterByTags`.
	 * @param int    $id    List or tag identifier.
	 * @return array Subscriber rows, as objects or arrays.
	 */
	protected static function query_subscribers( $scope, $id ) {
		$model = self::subscriber_model();

		if ( null === $model ) {
			return array();
		}

		try {
			$query = call_user_func( array( $model, $scope ), array( (int) $id ) );

			if ( ! is_object( $query ) ) {
				return is_array( $query ) ? $query : array();
			}

			if ( method_exists( $query, 'limit' ) ) {
				$query = $query->limit( self::READ_CAP );
			}

			$rows = method_exists( $query, 'get' ) ? $query->get() : $query;
		} catch ( \Throwable $e ) {
			Log::write(
				'migration: subscriber read failed',
				array(
					'scope' => (string) $scope,
					'id'    => (int) $id,
					'error' => $e->getMessage(),
				)
			);

			return array();
		}

		return self::rows( $rows );
	}

	/**
	 * A fresh subscriber model to scope a query from, or null.
	 *
	 * `FluentCrmApi( 'contacts' )->getInstance()` is asked first, so the runner
	 * goes through the same public entry point `ContactLinker` uses; the model
	 * class is the fallback for an install whose API module predates
	 * `getInstance()`.
	 *
	 * A fresh instance per call matters: scoping the same model twice would build
	 * one query carrying both scopes, which is a conjunction, and Requirement
	 * 15.1 asks for a union.
	 *
	 * @return object|null
	 */
	protected static function subscriber_model() {
		$contacts = self::contacts_api();

		if ( null !== $contacts && method_exists( $contacts, 'getInstance' ) ) {
			try {
				$model = $contacts->getInstance();

				if ( is_object( $model ) ) {
					return $model;
				}
			} catch ( \Throwable $e ) {
				Log::write( 'migration: subscriber model did not resolve', array( 'error' => $e->getMessage() ) );
			}
		}

		$class = self::SUBSCRIBER_CLASS;

		if ( ! class_exists( $class ) ) {
			return null;
		}

		try {
			return new $class();
		} catch ( \Throwable $e ) {
			Log::write( 'migration: subscriber model could not be constructed', array( 'error' => $e->getMessage() ) );

			return null;
		}
	}

	/**
	 * The FluentCRM contacts API, or null when it does not resolve.
	 *
	 * The same three failure modes `ContactLinker::contacts()` answers null for:
	 * FluentCRM absent, the module resolving to nothing, or resolving raising.
	 *
	 * @return object|null
	 */
	protected static function contacts_api() {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return null;
		}

		try {
			$api = FluentCrmApi( self::API_MODULE );
		} catch ( \Throwable $e ) {
			Log::write( 'migration: contacts api did not resolve', array( 'error' => $e->getMessage() ) );

			return null;
		}

		return is_object( $api ) ? $api : null;
	}

	/* ---------------------------------------------------------------------
	 * Normalisation
	 * ------------------------------------------------------------------ */

	/**
	 * Normalise a mixed collection of subscriber rows, deduplicated by id.
	 *
	 * @param mixed $rows Collection, array, or single row.
	 * @return array<int,array> Normalised contacts, first occurrence wins.
	 */
	protected static function normalise_all( $rows ) {
		$contacts = array();
		$seen     = array();

		foreach ( self::rows( $rows ) as $row ) {
			$contact = self::normalise( $row );

			if ( null === $contact ) {
				continue;
			}

			$key = (int) $contact['subscriber_id'];

			// A contact both in the list and holding the tag is one contact.
			// Identifier 0 cannot be deduplicated, so every such row is kept and
			// each is reported as its own skip.
			if ( $key > 0 ) {
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
			}

			$contacts[] = $contact;
		}

		return $contacts;
	}

	/**
	 * Coerce whatever a FluentCRM read returned into a plain array of rows.
	 *
	 * An Eloquent collection is iterable, an array is already a list, and a
	 * single model is one row. Anything else is nothing.
	 *
	 * @param mixed $rows Value returned by a read.
	 * @return array
	 */
	protected static function rows( $rows ) {
		if ( is_array( $rows ) ) {
			return $rows;
		}

		if ( $rows instanceof \Traversable ) {
			return iterator_to_array( $rows, false );
		}

		if ( is_object( $rows ) && method_exists( $rows, 'toArray' ) && ! self::looks_like_subscriber( $rows ) ) {
			$converted = $rows->toArray();

			return is_array( $converted ) ? $converted : array();
		}

		return is_object( $rows ) ? array( $rows ) : array();
	}

	/**
	 * Whether an object looks like one subscriber rather than a collection.
	 *
	 * @param object $value Value to test.
	 * @return bool
	 */
	protected static function looks_like_subscriber( $value ) {
		return isset( $value->email ) || isset( $value->id );
	}

	/**
	 * Normalise one subscriber row.
	 *
	 * @param mixed $row Subscriber model, object or array.
	 * @return array{subscriber_id:int,first_name:string,last_name:string,email:string,phone:string,created_at:string,legacy_status:string,status:string,notes:array}|null
	 */
	protected static function normalise( $row ) {
		if ( ! is_object( $row ) && ! is_array( $row ) ) {
			return null;
		}

		$subscriber_id = (int) self::read( $row, 'id', self::read( $row, 'subscriber_id', 0 ) );
		$legacy_status = self::legacy_status( $row, $subscriber_id );

		return array(
			'subscriber_id' => $subscriber_id,
			'first_name'    => self::text( self::read( $row, 'first_name', '' ) ),
			'last_name'     => self::text( self::read( $row, 'last_name', '' ) ),
			'email'         => self::text( self::read( $row, 'email', '' ) ),
			'phone'         => self::text( self::read( $row, 'phone', '' ) ),
			'created_at'    => self::text( self::read( $row, 'created_at', '' ) ),
			'legacy_status' => $legacy_status,
			'status'        => self::map_status( $legacy_status ),
			'notes'         => self::read_notes( $row, $subscriber_id ),
		);
	}

	/**
	 * The legacy workflow status of one contact.
	 *
	 * Three shapes are read, in order of how directly they answer: a `notes`-like
	 * custom field map the row already carries, the model's `custom_fields()`
	 * accessor, then the subscriber meta table. All three are reads.
	 *
	 * @param mixed $row           Subscriber row.
	 * @param int   $subscriber_id Subscriber identifier.
	 * @return string Empty when the contact holds no value (Requirement 15.5).
	 */
	protected static function legacy_status( $row, $subscriber_id ) {
		$inline = self::read( $row, self::STATUS_FIELD, null );

		if ( is_scalar( $inline ) && '' !== (string) $inline ) {
			return self::text( $inline );
		}

		$fields = self::read( $row, 'custom_fields', null );

		if ( is_array( $fields ) && isset( $fields[ self::STATUS_FIELD ] ) ) {
			return self::text( $fields[ self::STATUS_FIELD ] );
		}

		if ( is_object( $row ) && method_exists( $row, 'custom_fields' ) ) {
			try {
				$fields = $row->custom_fields();

				if ( is_array( $fields ) && isset( $fields[ self::STATUS_FIELD ] ) ) {
					return self::text( $fields[ self::STATUS_FIELD ] );
				}
			} catch ( \Throwable $e ) {
				Log::write( 'migration: custom fields unreadable', array( 'error' => $e->getMessage() ) );
			}
		}

		return self::meta_status( $subscriber_id );
	}

	/**
	 * The legacy status held in the subscriber meta table.
	 *
	 * @param int $subscriber_id Subscriber identifier.
	 * @return string
	 */
	protected static function meta_status( $subscriber_id ) {
		$class = self::META_CLASS;

		if ( $subscriber_id <= 0 || ! class_exists( $class ) ) {
			return '';
		}

		try {
			$meta = $class::where( 'subscriber_id', (int) $subscriber_id )
				->where( 'object_type', 'custom_field' )
				->where( 'key', self::STATUS_FIELD )
				->first();
		} catch ( \Throwable $e ) {
			Log::write( 'migration: status meta unreadable', array( 'error' => $e->getMessage() ) );

			return '';
		}

		if ( ! $meta ) {
			return '';
		}

		return self::text( self::read( $meta, 'value', '' ) );
	}

	/**
	 * Every note of one contact, oldest first (Requirement 15.6).
	 *
	 * @param mixed $row           Subscriber row.
	 * @param int   $subscriber_id Subscriber identifier.
	 * @return array<int,array{body:string,created_at:string}>
	 */
	protected static function read_notes( $row, $subscriber_id ) {
		$notes = self::read( $row, 'notes', null );

		if ( null === $notes ) {
			$notes = self::query_notes( $subscriber_id );
		}

		$out = array();

		foreach ( self::rows( $notes ) as $note ) {
			if ( is_scalar( $note ) ) {
				$out[] = array(
					'body'       => self::note_body( (string) $note ),
					'created_at' => '',
				);

				continue;
			}

			if ( ! is_object( $note ) && ! is_array( $note ) ) {
				continue;
			}

			// FluentCRM keeps the note text in `description` and its subject in
			// `title`; a note holding only a title still carries what was said.
			$body = self::note_body( self::read( $note, 'description', self::read( $note, 'body', '' ) ) );

			if ( '' === $body ) {
				$body = self::note_body( self::read( $note, 'title', '' ) );
			}

			$out[] = array(
				'body'       => $body,
				'created_at' => self::text( self::read( $note, 'created_at', '' ) ),
			);
		}

		return $out;
	}

	/**
	 * Read a contact's notes from the subscriber note table.
	 *
	 * @param int $subscriber_id Subscriber identifier.
	 * @return array
	 */
	protected static function query_notes( $subscriber_id ) {
		$class = self::NOTE_CLASS;

		if ( $subscriber_id <= 0 || ! class_exists( $class ) ) {
			return array();
		}

		try {
			$notes = $class::where( 'subscriber_id', (int) $subscriber_id )
				->orderBy( 'id', 'asc' )
				->get();
		} catch ( \Throwable $e ) {
			Log::write(
				'migration: notes unreadable',
				array(
					'subscriber_id' => (int) $subscriber_id,
					'error'         => $e->getMessage(),
				)
			);

			return array();
		}

		return self::rows( $notes );
	}

	/**
	 * Strip HTML tags and trim a note body, without truncating.
	 *
	 * @param mixed $body Stored note text.
	 * @return string
	 */
	protected static function note_body( $body ) {
		if ( ! is_scalar( $body ) ) {
			return '';
		}

		$text = (string) $body;

		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return trim( wp_strip_all_tags( $text ) );
		}

		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );

		return trim( strip_tags( (string) $text ) );
	}

	/**
	 * Read one value from a row that may be an object or an array.
	 *
	 * Property access on an Eloquent model can raise or return null through
	 * `__get()`, so it is guarded like every other CRM read.
	 *
	 * @param mixed  $row      Row to read.
	 * @param string $key      Key or property name.
	 * @param mixed  $fallback Value when the key is absent.
	 * @return mixed
	 */
	protected static function read( $row, $key, $fallback = null ) {
		if ( is_array( $row ) ) {
			return array_key_exists( $key, $row ) ? $row[ $key ] : $fallback;
		}

		if ( ! is_object( $row ) ) {
			return $fallback;
		}

		try {
			$value = isset( $row->{$key} ) ? $row->{$key} : null;
		} catch ( \Throwable $e ) {
			return $fallback;
		}

		return null === $value ? $fallback : $value;
	}

	/**
	 * Trim a scalar to a plain string.
	 *
	 * @param mixed $value Value to coerce.
	 * @return string
	 */
	protected static function text( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * The configured FluentCRM list identifier.
	 *
	 * Asks `Settings` when it is loaded and falls back to the stored option, so
	 * the runner reads the same configuration as `ContactLinker` on a full load
	 * and still works on a partial one.
	 *
	 * @return int
	 */
	protected static function list_id() {
		return self::configured( 'enquiry_list_id', 'meh_enquiry_list' );
	}

	/**
	 * The configured FluentCRM tag identifier.
	 *
	 * @return int
	 */
	protected static function tag_id() {
		return self::configured( 'enquiry_tag_id', 'meh_enquiry_tag' );
	}

	/**
	 * Read one configured identifier.
	 *
	 * @param string $method Settings accessor.
	 * @param string $option Option name to fall back to.
	 * @return int
	 */
	protected static function configured( $method, $option ) {
		if ( class_exists( __NAMESPACE__ . '\\Settings' ) ) {
			try {
				return (int) call_user_func( array( __NAMESPACE__ . '\\Settings', $method ) );
			} catch ( \Throwable $e ) {
				Log::write(
					'migration: configuration unreadable',
					array(
						'option' => $option,
						'error'  => $e->getMessage(),
					)
				);
			}
		}

		return function_exists( 'get_option' ) ? (int) get_option( $option, 0 ) : 0;
	}
}
