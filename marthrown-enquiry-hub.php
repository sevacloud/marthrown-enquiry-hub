<?php
/**
 * Plugin Name:       Marthrown Enquiry Hub
 * Plugin URI:        https://github.com/marthrown/marthrown-enquiry-hub
 * Description:        Unified hub to manage WP Booking System bookings (current, upcoming, past) and event enquiries captured via contact forms. FluentCRM Pro is the source of record for enquiries.
 * Version:           0.2.0
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
define( 'MEH_VERSION', '0.2.0' );
define( 'MEH_PLUGIN_FILE', __FILE__ );
define( 'MEH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MEH_INCLUDES_DIR', MEH_PLUGIN_DIR . 'includes/' );

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
 * Dependency check: FluentCRM Pro must be active.
 * -------------------------------------------------------------------------
 *
 * FluentCRM Pro is the source of record for enquiries, so the plugin will
 * not load its features unless the dependency is present and active.
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
 */
function meh_missing_dependency_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'Marthrown Enquiry Hub', 'marthrown-enquiry-hub' ); ?></strong>
			<?php esc_html_e( 'requires FluentCRM Pro to be installed and active. The plugin features are disabled until FluentCRM Pro is available.', 'marthrown-enquiry-hub' ); ?>
		</p>
	</div>
	<?php
}

/*
 * -------------------------------------------------------------------------
 * Activation / Deactivation hooks
 * -------------------------------------------------------------------------
 */

/**
 * Runs on plugin activation.
 *
 * Registers cron schedules and flushes as needed. We guard against a missing
 * dependency so activation never fatals; the admin notice will guide the user.
 */
function meh_activate() {
	// Cron class must be available to (re)schedule events on activation.
	require_once MEH_INCLUDES_DIR . 'class-cron.php';
	if ( class_exists( '\MarthrownEnquiryHub\Cron' ) ) {
		\MarthrownEnquiryHub\Cron::schedule_events();
	}

	// Register the /bookings rewrite rule before flushing so the pretty URL
	// works immediately after activation.
	require_once MEH_INCLUDES_DIR . 'class-frontend-bookings.php';
	if ( class_exists( '\MarthrownEnquiryHub\FrontendBookings' ) ) {
		\MarthrownEnquiryHub\FrontendBookings::add_rewrite();
	}

	// Create the booking acknowledgment table.
	require_once MEH_INCLUDES_DIR . 'class-source-wpbs.php';
	if ( class_exists( '\MarthrownEnquiryHub\SourceWpbs' ) ) {
		\MarthrownEnquiryHub\SourceWpbs::create_ack_table();
	}

	// Store the version so we can run upgrade routines later.
	update_option( 'meh_version', MEH_VERSION );

	flush_rewrite_rules();
}

/**
 * Runs on plugin deactivation.
 *
 * Clears scheduled cron events so nothing lingers after deactivation.
 */
function meh_deactivate() {
	require_once MEH_INCLUDES_DIR . 'class-cron.php';
	if ( class_exists( '\MarthrownEnquiryHub\Cron' ) ) {
		\MarthrownEnquiryHub\Cron::clear_events();
	}

	flush_rewrite_rules();
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
 * Only runs when FluentCRM Pro is active. Loaded on `plugins_loaded` so that
 * FluentCRM (and its Pro add-on) have had a chance to define their markers.
 */
function meh_bootstrap() {
	if ( ! meh_is_fluentcrm_pro_active() ) {
		add_action( 'admin_notices', 'meh_missing_dependency_notice' );
		return;
	}

	// Shared services.
	require_once MEH_INCLUDES_DIR . 'class-auth.php';
	require_once MEH_INCLUDES_DIR . 'class-fluentcrm-writer.php';

	// Data sources.
	require_once MEH_INCLUDES_DIR . 'class-source-wpbs.php';
	require_once MEH_INCLUDES_DIR . 'class-source-email.php';
	require_once MEH_INCLUDES_DIR . 'class-source-webform.php';

	// REST API layer (the contract the React app consumes).
	require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';
	require_once MEH_INCLUDES_DIR . 'class-rest-bookings.php';

	// Scheduling + UI.
	require_once MEH_INCLUDES_DIR . 'class-cron.php';
	require_once MEH_INCLUDES_DIR . 'class-settings.php';
	require_once MEH_INCLUDES_DIR . 'class-admin-page.php';
	require_once MEH_INCLUDES_DIR . 'class-frontend-bookings.php';

	// Boot the pieces that register hooks.
	\MarthrownEnquiryHub\SourceWebform::init();
	\MarthrownEnquiryHub\Cron::init();
	\MarthrownEnquiryHub\Settings::init();
	\MarthrownEnquiryHub\RestEnquiries::init();
	\MarthrownEnquiryHub\RestBookings::init();
	\MarthrownEnquiryHub\AdminPage::init();
	\MarthrownEnquiryHub\FrontendBookings::init();
}
add_action( 'plugins_loaded', 'meh_bootstrap' );
