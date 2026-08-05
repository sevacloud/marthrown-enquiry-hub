<?php
/**
 * Admin page host for the React app.
 *
 * Registers the top-level "Enquiry Hub" menu, outputs an empty mount point
 * (#enquiry-hub-root), and enqueues the compiled React bundle from build/,
 * localizing the REST root and nonce.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminPage
 */
class AdminPage {

	const MENU_SLUG = 'marthrown-enquiry-hub';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Register the menu page. Uses a custom capability check so managers and
	 * operations (not just administrators) can reach it.
	 */
	public static function register_menu() {
		// Menu registration needs a capability string; gate rendering with our
		// own check for the custom roles.
		$cap = current_user_can( 'manage_options' ) ? 'manage_options' : 'read';

		add_menu_page(
			__( 'Enquiry Hub', 'marthrown-enquiry-hub' ),
			__( 'Enquiry Hub', 'marthrown-enquiry-hub' ),
			$cap,
			self::MENU_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-email-alt',
			26
		);
	}

	/**
	 * Enqueue the built React app on our admin page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		self::enqueue_app();
	}

	/**
	 * Enqueue + localize the compiled bundle. Shared with the front-end route.
	 */
	public static function enqueue_app() {
		$asset_file = MEH_PLUGIN_DIR . 'build/index.asset.php';
		$deps       = array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' );
		$version    = MEH_VERSION;

		if ( file_exists( $asset_file ) ) {
			$asset   = require $asset_file;
			$deps    = isset( $asset['dependencies'] ) ? $asset['dependencies'] : $deps;
			$version = isset( $asset['version'] ) ? $asset['version'] : $version;
		}

		wp_enqueue_script(
			'meh-app',
			MEH_PLUGIN_URL . 'build/index.js',
			$deps,
			$version,
			true
		);

		// wp-scripts emits index.css when styles are imported.
		if ( file_exists( MEH_PLUGIN_DIR . 'build/index.css' ) ) {
			wp_enqueue_style(
				'meh-app',
				MEH_PLUGIN_URL . 'build/index.css',
				array( 'wp-components' ),
				$version
			);
		}

		// Base staging/banner styles.
		wp_enqueue_style( 'meh-admin', MEH_PLUGIN_URL . 'assets/admin.css', array(), $version );

		wp_localize_script(
			'meh-app',
			'mehData',
			array(
				'root'       => esc_url_raw( rest_url( RestEnquiries::NAMESPACE ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'isStaging'  => (bool) ( function_exists( 'meh_is_staging' ) && meh_is_staging() ),
				'statuses'   => RestEnquiries::STATUSES,
				'buckets'    => RestBookings::BUCKETS,
			)
		);
	}

	/**
	 * Render the mount point.
	 */
	public static function render() {
		if ( ! Auth::current_user_can_access() ) {
			wp_die( esc_html__( 'You do not have permission to access the Enquiry Hub.', 'marthrown-enquiry-hub' ) );
		}
		echo '<div class="wrap"><div id="enquiry-hub-root"></div></div>';
	}
}
