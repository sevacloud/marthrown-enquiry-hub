<?php
/**
 * Shared authorization.
 *
 * Single source of truth for who may access the hub — used by both the REST
 * controllers (permission_callback) and the admin/front-end pages.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Auth
 */
class Auth {

	/**
	 * Roles allowed to access the hub (in addition to administrators).
	 *
	 * @return string[]
	 */
	public static function allowed_roles() {
		/**
		 * Filter the roles allowed to access the Enquiry Hub.
		 *
		 * @param string[] $roles Role slugs.
		 */
		return (array) apply_filters(
			'meh_allowed_roles',
			array( 'administrator', 'manager', 'operations' )
		);
	}

	/**
	 * Whether the current user may access the hub and its REST API.
	 *
	 * @return bool
	 */
	public static function current_user_can_access() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Administrators always pass, regardless of custom role naming.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user = wp_get_current_user();
		return (bool) array_intersect( self::allowed_roles(), (array) $user->roles );
	}

	/**
	 * REST permission callback.
	 *
	 * @return bool
	 */
	public static function rest_permission() {
		return self::current_user_can_access();
	}

	/**
	 * Build the login URL, honouring the site's custom login slug.
	 *
	 * Uses MEH_LOGIN_SLUG (e.g. `admin-console`) when set, otherwise falls back
	 * to wp_login_url() (which WPS Hide Login and similar plugins filter). The
	 * result is filterable via `meh_login_url`.
	 *
	 * @param string $redirect_to URL to return to after login.
	 * @return string
	 */
	public static function login_url( $redirect_to = '' ) {
		$slug = defined( 'MEH_LOGIN_SLUG' ) ? trim( (string) MEH_LOGIN_SLUG, '/' ) : '';

		if ( $slug ) {
			$url = home_url( '/' . $slug . '/' );
			if ( $redirect_to ) {
				// Match core's wp_login_url() encoding.
				$url = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url );
			}
		} else {
			$url = wp_login_url( $redirect_to );
		}

		/**
		 * Filter the login URL used by the hub's redirects.
		 *
		 * @param string $url         Login URL.
		 * @param string $redirect_to Post-login return URL.
		 */
		return apply_filters( 'meh_login_url', $url, $redirect_to );
	}
}
