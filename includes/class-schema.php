<?php
/**
 * Enquiry Store schema ownership.
 *
 * Creates and upgrades the six `{$wpdb->prefix}meh_` tables that hold
 * enquiries, candidate dates, multi-select values, notes, history and rejected
 * intake attempts (Requirements 1.1, 1.2, 1.3, 1.7, 1.9, 1.13, 1.15, 1.18).
 *
 * Two rules shape this class:
 *
 * - Deployment is an SFTP file mirror, so the plugin is never re-activated on
 *   deploy and activation alone would never run a migration. `maybe_upgrade()`
 *   therefore runs on load, guarded by an option comparison that returns before
 *   any `$wpdb` call when the stored version already matches
 *   (Requirement 1.17).
 * - Enquiry data is never destroyed. `install()` is `dbDelta()`-based, upgrades
 *   record the version only after every step succeeds, and the class exposes no
 *   drop method at all, so uninstalling the plugin retains every table, every
 *   row and the stored version (Requirements 1.12, 1.14, 1.16).
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Schema
 */
class Schema {

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = 'meh_db_version';

	/**
	 * Schema version this build of the plugin expects.
	 *
	 * Version 2 turned the candidate date table from one row per day into one
	 * row per date *range* — see `migrate_dates_to_ranges()`.
	 */
	const CURRENT_VERSION = 2;

	/**
	 * Component prefix applied after the WordPress table prefix.
	 */
	const PREFIX = 'meh_';

	/**
	 * Maximum length MySQL allows for a table name.
	 */
	const MAX_TABLE_NAME = 64;

	/**
	 * Table keys mapped to their unprefixed names.
	 *
	 * `event_type` and `site_exclusivity` share the `enquiry_terms` table with a
	 * `taxonomy` discriminator: both are "one row per selected value, related to
	 * one enquiry" (Requirement 1.3).
	 *
	 * @var array<string,string>
	 */
	const TABLES = array(
		'enquiries'  => 'enquiries',
		'dates'      => 'enquiry_dates',
		'terms'      => 'enquiry_terms',
		'notes'      => 'enquiry_notes',
		'history'    => 'enquiry_history',
		'rejections' => 'enquiry_rejections',
	);

	/**
	 * Whether the upgrade guard has already run this request.
	 *
	 * @var bool
	 */
	protected static $checked = false;

	/**
	 * Boot the schema layer.
	 *
	 * Registers nothing beyond the upgrade guard, and runs that guard at most
	 * once per request however many callers ask for it.
	 *
	 * @return bool Whether the schema is at the current version.
	 */
	public static function init() {
		if ( self::$checked ) {
			return self::stored_version() === self::CURRENT_VERSION;
		}

		return self::maybe_upgrade();
	}

	/**
	 * Fully qualified name of one Enquiry Store table.
	 *
	 * Every name carries the WordPress table prefix followed by the `meh_`
	 * component prefix. A prefix long enough to push the result past MySQL's
	 * 64-character limit is shortened deterministically rather than truncated
	 * blindly, so two tables can never collapse onto one name
	 * (Requirement 1.13).
	 *
	 * @param string $key Table key, one of the keys of self::TABLES.
	 * @return string Table name, or an empty string for an unknown key.
	 */
	public static function table( $key ) {
		global $wpdb;

		$key = (string) $key;

		if ( ! isset( self::TABLES[ $key ] ) ) {
			return '';
		}

		$prefix = isset( $wpdb ) ? $wpdb->prefix : 'wp_';
		$name   = $prefix . self::PREFIX . self::TABLES[ $key ];

		if ( strlen( $name ) > self::MAX_TABLE_NAME ) {
			$hash = substr( md5( $name ), 0, 8 );
			$name = substr( $name, 0, self::MAX_TABLE_NAME - 9 ) . '_' . $hash;
		}

		return $name;
	}

	/**
	 * Every Enquiry Store table name, keyed by table key.
	 *
	 * @return array<string,string>
	 */
	public static function tables() {
		$tables = array();

		foreach ( array_keys( self::TABLES ) as $key ) {
			$tables[ $key ] = self::table( $key );
		}

		return $tables;
	}

	/**
	 * The installed schema version, 0 when nothing is recorded.
	 *
	 * @return int
	 */
	public static function stored_version() {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Create every absent table and leave every present table alone.
	 *
	 * `dbDelta()` issues no destructive statement, so re-running this against an
	 * existing schema leaves all tables and all rows within them unchanged
	 * (Requirements 1.9, 1.12).
	 *
	 * @return bool Whether every table is present afterwards.
	 */
	public static function install() {
		global $wpdb;

		if ( ! self::load_dbdelta() ) {
			self::fail( 'all', 'dbDelta() unavailable' );

			return false;
		}

		$charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

		foreach ( self::schemas( $charset_collate ) as $key => $sql ) {
			$table = self::table( $key );

			$wpdb->last_error = '';

			dbDelta( $sql );

			if ( $wpdb->last_error ) {
				self::fail( $table, $wpdb->last_error );

				return false;
			}

			if ( ! self::table_exists( $table ) ) {
				self::fail( $table, 'table missing after dbDelta()' );

				return false;
			}
		}

		return true;
	}

	/**
	 * Bring the schema up to the current version, doing nothing when it is.
	 *
	 * The stored version is read first and compared before anything touches
	 * `$wpdb`, so a matched version executes no table creation and no table
	 * alteration statement (Requirement 1.17).
	 *
	 * @return bool Whether the schema is at the current version afterwards.
	 */
	public static function maybe_upgrade() {
		$stored = self::stored_version();

		if ( self::CURRENT_VERSION === $stored ) {
			self::$checked = true;

			return true;
		}

		self::$checked = true;

		// An absent or zeroed version means the schema was never installed.
		if ( $stored <= 0 ) {
			if ( ! self::install() ) {
				return false;
			}

			return self::record_version();
		}

		// A version ahead of this build is left alone: a newer copy of the
		// plugin owns it, and downgrading would be destructive.
		if ( $stored > self::CURRENT_VERSION ) {
			return false;
		}

		foreach ( self::pending( $stored ) as $version => $step ) {
			if ( ! self::apply_step( $version, $step ) ) {
				// The version option stays where it was, so the same steps are
				// retried on the next load rather than being skipped
				// (Requirement 1.16).
				return false;
			}
		}

		/*
		 * The table definitions are re-applied after the steps, not before: a
		 * step renaming a column has to run while the old name is still there,
		 * and `dbDelta()` adds the new column without removing the old one, so
		 * installing first would leave the step looking at a table that already
		 * carries both and skipping the rename — with the data still in the
		 * column nothing reads any more. Afterwards it is the ordinary
		 * idempotent pass, and it is what creates a table that went missing.
		 */
		if ( ! self::install() ) {
			return false;
		}

		// Recorded only once every step has succeeded (Requirement 1.11).
		return self::record_version();
	}

	/**
	 * Migration steps keyed by the version they produce.
	 *
	 * Each value is a callable returning true on success, or an array holding a
	 * `table` key naming the table it touches and a `callback` key holding that
	 * callable, which makes for a more useful failure log line.
	 *
	 * Version 1 is the initial install, handled by `install()`, so it has no step
	 * of its own. The `meh_schema_migrations` filter exists so a later version —
	 * or a test — can add steps without changing this method.
	 *
	 * @return array<int,callable|array> Steps keyed by target version.
	 */
	protected static function migrations() {
		$migrations = array(
			2 => array(
				'table'    => 'dates',
				'callback' => array( __CLASS__, 'migrate_dates_to_ranges' ),
			),
		);

		/**
		 * Filter the schema migration steps.
		 *
		 * @param array $migrations Steps keyed by the version each one produces.
		 */
		return (array) apply_filters( 'meh_schema_migrations', $migrations );
	}

	/**
	 * Migration steps between the stored version and the current one.
	 *
	 * @param int $stored Installed version.
	 * @return array<int,callable|array> Steps in ascending version order.
	 */
	protected static function pending( $stored ) {
		$pending = array();

		foreach ( self::migrations() as $version => $step ) {
			$version = (int) $version;

			if ( $version > (int) $stored && $version <= self::CURRENT_VERSION ) {
				$pending[ $version ] = $step;
			}
		}

		ksort( $pending, SORT_NUMERIC );

		return $pending;
	}

	/**
	 * Run one migration step and report whether it succeeded.
	 *
	 * A step that returns false, leaves a database error behind or throws is a
	 * failure: the caller then leaves the stored version untouched and every
	 * existing row in place.
	 *
	 * @param int            $version Version the step produces.
	 * @param callable|array $step    Step definition.
	 * @return bool
	 */
	protected static function apply_step( $version, $step ) {
		global $wpdb;

		$table    = 'migration ' . (int) $version;
		$callback = $step;

		if ( is_array( $step ) ) {
			$callback = isset( $step['callback'] ) ? $step['callback'] : null;

			if ( isset( $step['table'] ) ) {
				$named = self::table( $step['table'] );
				$table = '' !== $named ? $named : (string) $step['table'];
			}
		}

		if ( ! is_callable( $callback ) ) {
			self::fail( $table, 'migration step is not callable' );

			return false;
		}

		if ( isset( $wpdb ) ) {
			$wpdb->last_error = '';
		}

		try {
			$result = call_user_func( $callback, (int) $version );
		} catch ( \Throwable $e ) {
			self::fail( $table, $e->getMessage() );

			return false;
		}

		if ( is_wp_error( $result ) ) {
			self::fail( $table, $result->get_error_message() );

			return false;
		}

		if ( false === $result ) {
			self::fail( $table, 'migration step reported failure' );

			return false;
		}

		if ( isset( $wpdb ) && $wpdb->last_error ) {
			self::fail( $table, $wpdb->last_error );

			return false;
		}

		return true;
	}

	/**
	 * Version 2: turn one row per candidate day into one row per date range.
	 *
	 * The old table held a day list, which is what a sending form's start/end
	 * pair was expanded into on the way in. This undoes that expansion:
	 * consecutive days collapse back into the range they came from, and a gap
	 * starts a new one, so a submission of the 1st to the 3rd and the 10th
	 * becomes two ranges rather than four days or one eleven-day span. A lone day
	 * becomes a range whose start and end are the same date.
	 *
	 * Nothing is discarded. An enquiry whose days collapse into more than three
	 * ranges keeps all of them, even though three is the most a new submission may
	 * carry: the limit governs what may be entered, not what was already
	 * recorded, and dropping the fourth would be destroying enquiry data to
	 * satisfy a rule that did not exist when it was taken.
	 *
	 * Idempotent by construction. The rename is skipped when `start_date` is
	 * already present, and collapsing an already-collapsed table is a no-op:
	 * every row is its own range, and two ranges are only merged when they
	 * genuinely abut.
	 *
	 * A table that is not there is nothing to migrate rather than a failure: the
	 * install pass that follows the steps creates it in its range shape, and
	 * there are no days in it to collapse.
	 *
	 * @return bool
	 */
	public static function migrate_dates_to_ranges() {
		global $wpdb;

		$table = self::table( 'dates' );

		if ( ! isset( $wpdb ) || '' === $table ) {
			self::fail( $table, 'candidate date table missing' );

			return false;
		}

		if ( ! self::table_exists( $table ) ) {
			return true;
		}

		if ( ! self::widen_dates_table( $table ) ) {
			return false;
		}

		return self::collapse_days_into_ranges( $table );
	}

	/**
	 * Give the candidate date table its range columns.
	 *
	 * Each step is skipped when its column is already there, so this can run
	 * against a half-migrated table — which is the state a failure part-way
	 * through leaves behind, the version option having stayed where it was.
	 *
	 * @param string $table Fully qualified table name.
	 * @return bool
	 */
	protected static function widen_dates_table( $table ) {
		global $wpdb;

		$columns = self::columns_of( $table );

		if ( in_array( 'event_date', $columns, true ) && ! in_array( 'start_date', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $wpdb->query( "ALTER TABLE {$table} CHANGE event_date start_date date NOT NULL DEFAULT '0000-00-00'" ) ) {
				self::fail( $table, 'could not rename event_date to start_date' );

				return false;
			}

			$columns = self::columns_of( $table );
		}

		if ( ! in_array( 'end_date', $columns, true ) ) {
			// A day list has no end, so every migrated row starts as the single
			// day it already was; the collapse below joins the runs up.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $wpdb->query( "ALTER TABLE {$table} ADD end_date date NOT NULL DEFAULT '0000-00-00' AFTER start_date, ADD KEY end_date (end_date)" ) ) {
				self::fail( $table, 'could not add end_date' );

				return false;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$table} SET end_date = start_date WHERE end_date = '0000-00-00'" );
		}

		if ( ! in_array( 'position', $columns, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false === $wpdb->query( "ALTER TABLE {$table} ADD position tinyint(3) unsigned NOT NULL DEFAULT '0' AFTER end_date" ) ) {
				self::fail( $table, 'could not add position' );

				return false;
			}
		}

		return true;
	}

	/**
	 * Rewrite each enquiry's rows as the ranges its days describe.
	 *
	 * One enquiry at a time, so a table too large to hold in memory is still
	 * migratable and a failure part-way through leaves the enquiries already done
	 * in their new shape and the rest in their old one — both of which this method
	 * reads correctly on the retry.
	 *
	 * @param string $table Fully qualified table name.
	 * @return bool
	 */
	protected static function collapse_days_into_ranges( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT DISTINCT enquiry_id FROM {$table} ORDER BY enquiry_id ASC" );

		if ( ! is_array( $ids ) ) {
			return true;
		}

		foreach ( $ids as $enquiry_id ) {
			$enquiry_id = (int) $enquiry_id;

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT start_date, end_date, position FROM {$table} WHERE enquiry_id = %d ORDER BY start_date ASC, end_date ASC, id ASC",
					$enquiry_id
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || array() === $rows ) {
				continue;
			}

			$ranges = self::merge_adjacent( $rows );

			if ( self::already_ranges( $rows, $ranges ) ) {
				// Nothing to collapse and nothing to renumber, so the rows are
				// left where they are: rewriting them would change no date and
				// no rank, only every row's identifier.
				continue;
			}

			$wpdb->delete( $table, array( 'enquiry_id' => $enquiry_id ), array( '%d' ) );

			foreach ( $ranges as $position => $range ) {
				$inserted = $wpdb->insert(
					$table,
					array(
						'enquiry_id' => $enquiry_id,
						'start_date' => $range['start'],
						'end_date'   => $range['end'],
						'position'   => $position,
					),
					array( '%d', '%s', '%s', '%d' )
				);

				if ( false === $inserted ) {
					self::fail( $table, 'could not write range for enquiry ' . $enquiry_id );

					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Whether an enquiry's rows already hold exactly the ranges they describe.
	 *
	 * Two things have to be true: one row per merged range, bound for bound, and
	 * ranks that are already the numbering this migration would assign — the
	 * whole of 0 to n-1, in any order. The second is what keeps a table whose
	 * ideal range is not its earliest one from being renumbered chronologically:
	 * rank is the enquirer's preference, and reordering it would change what the
	 * record says.
	 *
	 * @param array $rows   Rows for one enquiry, in start order.
	 * @param array $ranges Merged ranges, in start order.
	 * @return bool
	 */
	protected static function already_ranges( array $rows, array $ranges ) {
		if ( count( $rows ) !== count( $ranges ) ) {
			return false;
		}

		$positions = array();

		foreach ( array_values( $rows ) as $index => $row ) {
			$start = isset( $row['start_date'] ) ? (string) $row['start_date'] : '';
			$end   = isset( $row['end_date'] ) ? (string) $row['end_date'] : '';

			if ( $start !== $ranges[ $index ]['start'] || $end !== $ranges[ $index ]['end'] ) {
				return false;
			}

			$positions[] = isset( $row['position'] ) ? (int) $row['position'] : -1;
		}

		sort( $positions, SORT_NUMERIC );

		return range( 0, count( $rows ) - 1 ) === $positions;
	}

	/**
	 * Join rows that touch or overlap into single ranges, oldest first.
	 *
	 * Two ranges are joined when the second begins no later than the day after
	 * the first ends, which is what makes a run of consecutive days one range and
	 * leaves a gap of a day or more as two.
	 *
	 * @param array $rows Rows carrying `start_date` and `end_date`, in start order.
	 * @return array<int,array{start:string,end:string}>
	 */
	protected static function merge_adjacent( array $rows ) {
		$ranges = array();

		foreach ( $rows as $row ) {
			$start = isset( $row['start_date'] ) ? (string) $row['start_date'] : '';
			$end   = isset( $row['end_date'] ) ? (string) $row['end_date'] : '';

			if ( '' === $start || '0000-00-00' === $start ) {
				continue;
			}

			if ( '' === $end || '0000-00-00' === $end || $end < $start ) {
				$end = $start;
			}

			$last = array() === $ranges ? null : count( $ranges ) - 1;

			if ( null !== $last && $start <= self::day_after( $ranges[ $last ]['end'] ) ) {
				if ( $end > $ranges[ $last ]['end'] ) {
					$ranges[ $last ]['end'] = $end;
				}

				continue;
			}

			$ranges[] = array(
				'start' => $start,
				'end'   => $end,
			);
		}

		return $ranges;
	}

	/**
	 * The day after a `Y-m-d` date.
	 *
	 * @param string $date Date as `Y-m-d`.
	 * @return string
	 */
	protected static function day_after( $date ) {
		$stamp = strtotime( (string) $date . ' +1 day' );

		return false === $stamp ? (string) $date : gmdate( 'Y-m-d', $stamp );
	}

	/**
	 * The column names of a table.
	 *
	 * @param string $table Fully qualified table name.
	 * @return string[]
	 */
	protected static function columns_of( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		return is_array( $columns ) ? array_map( 'strval', $columns ) : array();
	}

	/**
	 * Record the current schema version.
	 *
	 * @return bool
	 */
	protected static function record_version() {
		update_option( self::VERSION_OPTION, self::CURRENT_VERSION, false );

		return self::stored_version() === self::CURRENT_VERSION;
	}

	/**
	 * Log a schema failure naming the table and the reason (Requirement 1.16).
	 *
	 * @param string $table  Table the failure concerns.
	 * @param string $reason Failure reason.
	 * @return void
	 */
	protected static function fail( $table, $reason ) {
		Log::write( sprintf( 'schema: %s — %s', $table, trim( (string) $reason ) ) );
	}

	/**
	 * Whether a table is present in the database.
	 *
	 * @param string $table Fully qualified table name.
	 * @return bool
	 */
	protected static function table_exists( $table ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || '' === (string) $table ) {
			return false;
		}

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		return (string) $table === (string) $found;
	}

	/**
	 * Make `dbDelta()` available.
	 *
	 * @return bool
	 */
	protected static function load_dbdelta() {
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		return function_exists( 'dbDelta' );
	}

	/**
	 * `CREATE TABLE` statement for every Enquiry Store table, keyed by table key.
	 *
	 * Column notes worth keeping in view:
	 *
	 * - `phone` is `VARCHAR(32) NOT NULL DEFAULT ''` and `message` is
	 *   `TEXT NULL`: an unsupplied value stores and reads back as the empty
	 *   string, because `''` already sits outside the meaningful domain of both
	 *   (Requirement 1.19).
	 * - `total_guests` is nullable with no default rather than
	 *   `NOT NULL DEFAULT 0`. Zero is outside the valid 1–10000 range
	 *   (Requirement 1.18), so it is not a guest count, and it would be
	 *   indistinguishable from a value someone actually submitted. `NULL` is the
	 *   only value in the column's domain that can never be mistaken for a
	 *   count, which is what makes "not supplied" readable back out.
	 * - `payload` is `LONGTEXT`, not `TEXT`: `TEXT` caps at 65,535 *bytes*,
	 *   fewer than the 65,535 characters Requirement 1.7 asks for under
	 *   `utf8mb4`.
	 * - The indexes on `email`, `status`, `created_at`,
	 *   `fluentcrm_subscriber_id` and `is_test`, and on the candidate date, are
	 *   the ones the list queries of Requirement 12 need (Requirement 1.15).
	 *   None of them is unique: at least 100 enquiries may share an `email` or a
	 *   `fluentcrm_subscriber_id` (Requirements 1.4, 1.5).
	 *
	 * @param string $charset_collate Charset and collation clause.
	 * @return array<string,string>
	 */
	protected static function schemas( $charset_collate ) {
		$enquiries  = self::table( 'enquiries' );
		$dates      = self::table( 'dates' );
		$terms      = self::table( 'terms' );
		$notes      = self::table( 'notes' );
		$history    = self::table( 'history' );
		$rejections = self::table( 'rejections' );

		$schemas = array();

		$schemas['enquiries'] = "CREATE TABLE {$enquiries} (
	id bigint(20) unsigned NOT NULL auto_increment,
	first_name varchar(100) NOT NULL DEFAULT '',
	last_name varchar(100) NOT NULL DEFAULT '',
	email varchar(254) NOT NULL DEFAULT '',
	phone varchar(32) NOT NULL DEFAULT '',
	total_guests smallint(5) unsigned DEFAULT NULL,
	message text NULL,
	status varchar(20) NOT NULL DEFAULT 'new',
	fluentcrm_subscriber_id bigint(20) unsigned DEFAULT NULL,
	booking_id bigint(20) unsigned DEFAULT NULL,
	crm_sync_state varchar(20) NOT NULL DEFAULT '',
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	status_changed_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	source varchar(191) NOT NULL DEFAULT '',
	is_test tinyint(1) NOT NULL DEFAULT '0',
	duplicated_from_id bigint(20) unsigned DEFAULT NULL,
	duplicated_to_id bigint(20) unsigned DEFAULT NULL,
	payload longtext NULL,
	PRIMARY KEY  (id),
	KEY email (email),
	KEY status (status),
	KEY created_at (created_at),
	KEY fluentcrm_subscriber_id (fluentcrm_subscriber_id),
	KEY is_test (is_test)
) {$charset_collate};";

		// One row per candidate date *range*, day precision, up to three per
		// enquiry: `position` 0 is the ideal range every enquiry must carry, and
		// 1 and 2 are the optional alternatives (Requirement 1.2, as revised).
		// A single day is a range whose start and end are the same date, which is
		// why there is no separate shape for one.
		//
		// Both bounds are indexed: the list filter of Requirement 12.8 asks which
		// enquiries have a range overlapping a window, which reads both ends.
		$schemas['dates'] = "CREATE TABLE {$dates} (
	id bigint(20) unsigned NOT NULL auto_increment,
	enquiry_id bigint(20) unsigned NOT NULL DEFAULT '0',
	start_date date NOT NULL DEFAULT '0000-00-00',
	end_date date NOT NULL DEFAULT '0000-00-00',
	position tinyint(3) unsigned NOT NULL DEFAULT '0',
	PRIMARY KEY  (id),
	KEY enquiry_id (enquiry_id),
	KEY start_date (start_date),
	KEY end_date (end_date)
) {$charset_collate};";

		// `event_type` and `site_exclusivity` share this table, discriminated by
		// `taxonomy`. 0 to 20 rows per taxonomy per enquiry (Requirement 1.3):
		// zero rows is a valid state, reached when a manual creation omits the
		// field, and a read returns an empty array for it rather than an error
		// or a null.
		$schemas['terms'] = "CREATE TABLE {$terms} (
	id bigint(20) unsigned NOT NULL auto_increment,
	enquiry_id bigint(20) unsigned NOT NULL DEFAULT '0',
	taxonomy varchar(32) NOT NULL DEFAULT '',
	term varchar(100) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	KEY enquiry_taxonomy (enquiry_id,taxonomy)
) {$charset_collate};";

		$schemas['notes'] = "CREATE TABLE {$notes} (
	id bigint(20) unsigned NOT NULL auto_increment,
	enquiry_id bigint(20) unsigned NOT NULL DEFAULT '0',
	body text NULL,
	author_id bigint(20) unsigned NOT NULL DEFAULT '0',
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY enquiry_id (enquiry_id)
) {$charset_collate};";

		// Insert-only audit trail. `context` is JSON and already LONGTEXT, so a
		// `fields_edited` entry carrying the previous and new value of every
		// changed field needs no schema change. `actor_id` 0 means the system
		// acted rather than a user.
		$schemas['history'] = "CREATE TABLE {$history} (
	id bigint(20) unsigned NOT NULL auto_increment,
	enquiry_id bigint(20) unsigned NOT NULL DEFAULT '0',
	entry_type varchar(32) NOT NULL DEFAULT '',
	description varchar(255) NOT NULL DEFAULT '',
	context longtext NULL,
	actor_id bigint(20) unsigned NOT NULL DEFAULT '0',
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY enquiry_id (enquiry_id),
	KEY created_at (created_at)
) {$charset_collate};";

		// Every authenticated intake request that produced no enquiry lands
		// here, so a genuine enquiry rejected as a duplicate, rate limited or
		// invalid can still be found and recreated.
		$schemas['rejections'] = "CREATE TABLE {$rejections} (
	id bigint(20) unsigned NOT NULL auto_increment,
	reason varchar(32) NOT NULL DEFAULT '',
	detail longtext NULL,
	payload longtext NULL,
	email varchar(254) NOT NULL DEFAULT '',
	source varchar(191) NOT NULL DEFAULT '',
	is_test tinyint(1) NOT NULL DEFAULT '0',
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY created_at (created_at),
	KEY email (email)
) {$charset_collate};";

		return $schemas;
	}
}
