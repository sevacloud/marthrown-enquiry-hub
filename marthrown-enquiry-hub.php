<?php
/**
 * Plugin Name:       Marthrown Enquiry Hub
 * Plugin URI:        https://github.com/marthrown/marthrown-enquiry-hub
 * Description:        Unified hub to manage WP Booking System bookings (current, upcoming, past) and event enquiries. The plugin owns its enquiry tables: the website form posts to the intake webhook, the hub stores and works the enquiry, and FluentCRM Pro — when present — is linked to as the contact record.
 * Version:           0.3.2
 * Author:            Liamarjit @ Seva Cloud
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       marthrown-enquiry-hub
 * Requires PHP:      7.4
 * Requires at least: 6.0
 *
 * @package MarthrownEnquiryHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/*
 * -------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------
 */
define( 'MEH_VERSION', '0.3.2' );
define( 'MEH_PLUGIN_FILE', __FILE__ );
define( 'MEH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MEH_INCLUDES_DIR', MEH_PLUGIN_DIR . 'includes/' );

/*
 * Enquiry Store schema version this build expects. Declared here so the version
 * is readable before includes/ loads; `Schema::CURRENT_VERSION` is the value the
 * schema manager itself compares against and the two are kept in step.
 */
define( 'MEH_DB_VERSION', 1 );

// Prefix applied to FluentCRM records created by the staging copy so they can
// be identified and removed before go-live.
if ( ! defined( 'MEH_TEST_PREFIX' ) ) {
	define( 'MEH_TEST_PREFIX', 'TEST_' );
}

// Custom login slug (this site hides wp-login.php behind /admin-console).
// Override in wp-config with define('MEH_LOGIN_SLUG', 'your-slug') or the
// `meh_login_url` filter. Set to '' to fall back to wp_login_url().
if ( ! defined( 'MEH_LOGIN_SLUG' ) ) {
	define( 'MEH_LOGIN_SLUG', 'admin-console' );
}

/*
 * Optional environment marker written by CI at deploy time (see the staging
 * GitHub Actions workflow). When present it defines MEH_ENVIRONMENT, making
 * staging detection explicit. Not committed to the repo.
 */
if ( file_exists( MEH_PLUGIN_DIR . 'meh-environment.php' ) ) {
	require_once MEH_PLUGIN_DIR . 'meh-environment.php';
}

/*
 * -------------------------------------------------------------------------
 * Dependency check: FluentCRM Pro is optional.
 * -------------------------------------------------------------------------
 *
 * The Enquiry Store owns enquiries, so intake, storage, the lifecycle and the
 * REST surface all load whether or not FluentCRM Pro is present
 * (Requirement 16.8). FluentCRM is used for one thing only — linking an enquiry
 * to a CRM contact — so its absence disables contact linkage and nothing else.
 */

/**
 * Determine whether FluentCRM Pro is active.
 *
 * FluentCRM (free) defines FLUENTCRM. The Pro add-on defines FLUENTCAMPAIGN
 * (Fluent CRM Pro / Fluent Campaign Pro) and exposes fluentcrm_get_option().
 * We check for the Pro marker to satisfy the "Pro" requirement.
 *
 * @return bool
 */
function meh_is_fluentcrm_pro_active() {
	// Free core marker.
	$core_active = defined( 'FLUENTCRM' ) || defined( 'FLUENTCRM_PLUGIN_VERSION' );

	// Pro add-on marker (Fluent Campaign Pro ships FluentCRM Pro features).
	$pro_active = defined( 'FLUENTCAMPAIGN' )
		|| defined( 'FLUENTCAMPAIGN_PLUGIN_VERSION' )
		|| function_exists( 'FluentCampaign' );

	return $core_active && $pro_active;
}

/**
 * Determine whether this is the staging copy of the plugin.
 *
 * Staging deploys to a `marthrown-enquiry-hub-staging` folder on the same
 * WordPress site as production, so wp_get_environment_type() cannot tell them
 * apart (shared install/DB). Detection order:
 *
 *   1. Explicit MEH_ENVIRONMENT constant ('staging' / 'production').
 *   2. Native wp_get_environment_type() === 'staging' (separate staging site).
 *   3. The plugin running from a "-staging" directory (same-site staging folder).
 *
 * The final result is filterable via `meh_is_staging`.
 *
 * @return bool
 */
function meh_is_staging() {
	$staging = false;

	if ( defined( 'MEH_ENVIRONMENT' ) ) {
		$staging = ( 'staging' === MEH_ENVIRONMENT );
	} elseif ( function_exists( 'wp_get_environment_type' ) && 'staging' === wp_get_environment_type() ) {
		$staging = true;
	} elseif ( false !== strpos( wp_normalize_path( MEH_PLUGIN_DIR ), '-staging/' ) ) {
		$staging = true;
	}

	/**
	 * Filter whether the plugin is running in staging mode.
	 *
	 * @param bool $staging Whether staging mode is active.
	 */
	return (bool) apply_filters( 'meh_is_staging', $staging );
}

/**
 * Render an admin notice when FluentCRM Pro is missing.
 *
 * A warning rather than an error, and scoped to what is actually unavailable:
 * enquiries are still received, stored, listed and worked; only linkage of an
 * enquiry to a CRM contact is disabled until FluentCRM Pro is back.
 */
function meh_missing_dependency_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e( 'Marthrown Enquiry Hub', 'marthrown-enquiry-hub' ); ?></strong>
			<?php esc_html_e( 'cannot find FluentCRM Pro, so contact linkage is disabled. Enquiries are still received and stored, and they will be linked to CRM contacts once FluentCRM Pro is active again.', 'marthrown-enquiry-hub' ); ?>
		</p>
	</div>
	<?php
}

/*
 * -------------------------------------------------------------------------
 * Class loading
 * -------------------------------------------------------------------------
 */

/**
 * Load every plugin class.
 *
 * Shared by the bootstrap and by the activation and deactivation hooks, which
 * run in requests where `plugins_loaded` has already been and gone.
 *
 * The order is not alphabetical and is not arbitrary. `class-enquiry-creator.php`
 * has to be loaded before `class-intake-handler.php`, because the handler
 * resolves its rejection-reason and history-entry constants from
 * `EnquiryCreator` as its own class body is evaluated; loading it first would
 * fatal on every intake request. Everything else resolves its collaborators at
 * call time, so the remaining order is grouped for reading rather than forced.
 *
 * @return void
 */
function meh_require_includes() {
	$files = array(
		// Primitives every other class leans on.
		'class-log.php',
		'class-clock.php',
		'class-auth.php',
		'class-staging-marker.php',

		// Storage.
		'class-schema.php',
		'class-enquiry-query.php',
		'class-enquiry-store.php',

		// Enquiry services.
		'class-enquiry-validator.php',
		'class-enquiry-taxonomies.php',
		'class-field-mapper.php',
		'class-history-recorder.php',
		'class-note-service.php',
		'class-lifecycle.php',
		'class-duplicate-detector.php',
		'class-contact-linker.php',

		// Intake. EnquiryCreator first: IntakeHandler reads its constants as the
		// handler class body is evaluated.
		'class-enquiry-creator.php',
		'class-intake-handler.php',
		'class-intake-endpoint.php',
		'class-enquiry-editor.php',

		// Scheduled work, booking creation and the one-off CRM migration.
		'class-auto-close-job.php',
		'class-booking-creator.php',
		'class-migration-runner.php',

		// Bookings data layer.
		'class-source-wpbs.php',
		'class-calendar-reader.php',

		// REST API layer (the contract the React app consumes).
		'class-rest-enquiries.php',
		'class-rest-bookings.php',
		'class-export-bookings.php',
		'class-export-enquiries.php',

		// UI.
		'class-settings.php',
		'class-admin-page.php',
		'class-wpbs-banner.php',
		'class-frontend-bookings.php',
	);

	foreach ( $files as $file ) {
		require_once MEH_INCLUDES_DIR . $file;
	}
}

/*
 * -------------------------------------------------------------------------
 * Activation / Deactivation hooks
 * -------------------------------------------------------------------------
 */

/**
 * Runs on plugin activation.
 *
 * Installs the Enquiry Store schema, schedules the daily auto-closure event and
 * registers the /bookings rewrite. Nothing here depends on FluentCRM, so
 * activation succeeds and the enquiry layer works with the dependency absent.
 */
function meh_activate() {
	// Retire cron events from earlier versions (email polling / WPBS sync).
	meh_clear_legacy_cron();

	meh_require_includes();

	/*
	 * Requirement 1.9: create every absent table, leave every present table and
	 * every row within it alone, and record the current schema version.
	 * `install()` is dbDelta()-based so it is safe over an existing schema;
	 * `maybe_upgrade()` is what records the version, and returns immediately when
	 * the stored version already matches.
	 */
	\MarthrownEnquiryHub\Schema::install();
	\MarthrownEnquiryHub\Schema::maybe_upgrade();

	// Requirement 8.1: one daily auto-closure event, not a second one.
	\MarthrownEnquiryHub\AutoCloseJob::schedule();

	// Register the /bookings rewrite rule before flushing so the pretty URL
	// works immediately after activation.
	\MarthrownEnquiryHub\FrontendBookings::add_rewrite();

	// Store the version so we can run upgrade routines later.
	update_option( 'meh_version', MEH_VERSION );

	flush_rewrite_rules();
}

/**
 * Runs on plugin deactivation.
 *
 * Clears every scheduled cron event the plugin owns — the daily auto-closure
 * event and the two retired polling hooks — so nothing lingers. Every Enquiry
 * Store table and every row within it is retained: deactivating, and
 * uninstalling, destroys no enquiry data, which is why the plugin ships no
 * `uninstall.php` (Requirement 1.14).
 */
function meh_deactivate() {
	meh_clear_legacy_cron();

	meh_require_includes();

	// Requirement 8.2.
	\MarthrownEnquiryHub\AutoCloseJob::unschedule();

	flush_rewrite_rules();
}

/**
 * Clear scheduled events from earlier versions.
 *
 * The email-polling and WPBS-sync events are gone for good, so both hooks are
 * cleared on activation and on deactivation and any schedule left over from an
 * earlier version of the plugin goes with them. The daily auto-closure event is
 * not this function's business: `AutoCloseJob::unschedule()` owns it.
 */
function meh_clear_legacy_cron() {
	foreach ( array( 'meh_cron_email_poll', 'meh_cron_wpbs_poll' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}

register_activation_hook( __FILE__, 'meh_activate' );
register_deactivation_hook( __FILE__, 'meh_deactivate' );

/*
 * -------------------------------------------------------------------------
 * Bootstrap
 * -------------------------------------------------------------------------
 */

/**
 * Load plugin includes and wire everything together.
 *
 * Runs whether or not FluentCRM Pro is active: the enquiry layer owns its own
 * storage, so intake, the lifecycle and the REST surface must work with the
 * dependency absent (Requirement 16.8). A missing dependency only adds the
 * admin notice.
 *
 * The schema upgrade runs here rather than only on activation because deployment
 * is an SFTP file mirror — the plugin is never re-activated on deploy, so an
 * activation-only migration would never run. `maybe_upgrade()` compares the
 * stored version before touching `$wpdb` and returns without issuing a statement
 * when it matches (Requirements 1.11, 1.17).
 */
function meh_bootstrap() {
	meh_require_includes();

	\MarthrownEnquiryHub\Schema::maybe_upgrade();

	// Boot the pieces that register hooks.
	\MarthrownEnquiryHub\IntakeEndpoint::init();
	\MarthrownEnquiryHub\EnquiryTaxonomies::init();
	\MarthrownEnquiryHub\AutoCloseJob::init();
	\MarthrownEnquiryHub\Settings::init();
	\MarthrownEnquiryHub\RestEnquiries::init();
	\MarthrownEnquiryHub\RestBookings::init();
	\MarthrownEnquiryHub\ExportBookings::init();
	\MarthrownEnquiryHub\ExportEnquiries::init();
	\MarthrownEnquiryHub\AdminPage::init();
	\MarthrownEnquiryHub\WpbsBanner::init();
	\MarthrownEnquiryHub\FrontendBookings::init();

	if ( ! meh_is_fluentcrm_pro_active() ) {
		add_action( 'admin_notices', 'meh_missing_dependency_notice' );
	}
}
add_action( 'plugins_loaded', 'meh_bootstrap' );
