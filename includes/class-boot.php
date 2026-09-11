<?php
/**
 * Whether WordPress has finished building the globals a callback wants to read.
 *
 * The plugin boots on `plugins_loaded`, and `wp-settings.php` creates
 * `$wp_the_query`, `$wp_query`, `$wp_rewrite` and `$wp` *after* that action has
 * finished. So there is a window, early in every request, in which those globals
 * are still null while our filters are already registered — and a filter that
 * reads one of them in that window brings the whole site down with
 * "Call to a member function … on null".
 *
 * That is not a theoretical window. Two callbacks fell into it in production:
 *
 * - `http_request_args` fires for *every* outbound HTTP request, including the
 *   licence check another plugin makes from its own `plugins_loaded` handler. Ours
 *   answered by composing `rest_url()`, which reads `$wp_rewrite`.
 * - `wp_robots` fires from `wp_die()`, which is how WordPress renders its own
 *   fatal-error page. Ours answered by reading `get_query_var()`, which reads
 *   `$wp_query`. A fatal inside the fatal handler is a blank white page: no
 *   "critical error" message, no recovery-mode email, and no way back except
 *   deactivating the plugin by hand.
 *
 * The second one is why this class is worth having rather than two inline
 * checks. Any hook this plugin adds may be called before WordPress is ready, and
 * one that crashes while WordPress is *already* crashing costs the site its only
 * means of telling anybody. So the rule is: a callback that reads one of these
 * globals asks here first, and does nothing when the answer is no.
 *
 * `instanceof` rather than `isset()` alone, because the globals are declared and
 * null in the window rather than absent, and `null instanceof` is simply false.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Boot
 */
class Boot {

	/**
	 * Whether `$wp_rewrite` exists yet.
	 *
	 * Anything that builds a URL through `rest_url()`, `get_rest_url()`,
	 * `home_url()` with a path under a permalink structure, or `get_permalink()`
	 * needs this: they all ask the rewrite object how the site's URLs are shaped.
	 *
	 * @return bool
	 */
	public static function has_rewrite() {
		return isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite;
	}

	/**
	 * Whether `$wp_query` exists yet.
	 *
	 * Anything that reads the current request — `get_query_var()`,
	 * `is_singular()`, `get_queried_object()` — needs this.
	 *
	 * @return bool
	 */
	public static function has_query() {
		return isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query;
	}
}
