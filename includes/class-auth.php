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
}
