<?php
/**
 * Admin menu + shared app enqueue.
 *
 * Registers the top-level "Enquiry Hub" menu that links to the front-end
 * /bookings hub, and provides enqueue_app() (used by the /bookings route) to
 * load the compiled React bundle from build/ with the REST root + nonce.
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
	}

	/**
	 * Register the top-level "Enquiry Hub" menu.
	 *
	 * The menu links directly to the front-end /bookings hub (not an admin
	 * page). Passing a full URL as the menu slug makes WordPress use it as the
	 * item href. Visible to administrators, managers and operations.
	 */
	public static function register_menu() {
		$cap = current_user_can( 'manage_options' ) ? 'manage_options' : 'read';

		add_menu_page(
			__( 'Enquiry Hub', 'marthrown-enquiry-hub' ),
			__( 'Enquiry Hub', 'marthrown-enquiry-hub' ),
			$cap,
			home_url( '/' . FrontendBookings::ROUTE . '/' ),
			'',
			'dashicons-email-alt',
			26
		);
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
				'root'            => esc_url_raw( rest_url( RestEnquiries::NAMESPACE ) ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'isStaging'       => (bool) ( function_exists( 'meh_is_staging' ) && meh_is_staging() ),
				'statuses'        => RestEnquiries::STATUSES,
				'bookingStatuses' => SourceWpbs::STATUSES,
				'exportBase'      => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'exportAction'    => ExportBookings::ACTION,
				'exportNonce'     => wp_create_nonce( ExportBookings::NONCE ),
				// Settings is administrator-only; the side nav hides it otherwise.
				'isAdmin'         => current_user_can( 'manage_options' ),
				'settingsUrl'     => esc_url_raw( Settings::url() ),
				'wpbsUrl'         => esc_url_raw( admin_url( 'admin.php?page=wpbs-bookings' ) ),
			)
		);
	}
}
