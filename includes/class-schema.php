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
	 */
	const CURRENT_VERSION = 1;

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
	 * Version 1 is the initial install, handled by `install()`, so there is
	 * nothing here yet. The `meh_schema_migrations` filter exists so a later
	 * version — or a test — can add steps without changing this method.
	 *
	 * @return array<int,callable|array> Steps keyed by target version.
	 */
	protected static function migrations() {
		$migrations = array();

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

		// One row per candidate date, 1 to 10 per enquiry, day precision
		// (Requirement 1.2). `event_date` is indexed for the candidate date
		// range filter.
		$schemas['dates'] = "CREATE TABLE {$dates} (
	id bigint(20) unsigned NOT NULL auto_increment,
	enquiry_id bigint(20) unsigned NOT NULL DEFAULT '0',
	event_date date NOT NULL DEFAULT '0000-00-00',
	PRIMARY KEY  (id),
	KEY enquiry_id (enquiry_id),
	KEY event_date (event_date)
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
