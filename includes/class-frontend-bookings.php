<?php
/**
 * Front-end /bookings route.
 *
 * Exposes the Enquiry Hub React app at the site URL /bookings without needing
 * a WordPress page. Login-protected and restricted to the allowed roles (see
 * Auth). Renders a self-contained, noindex page hosting the same React bundle
 * used in wp-admin.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FrontendBookings
 */
class FrontendBookings {

	const ROUTE     = 'bookings';
	const QUERY_VAR = 'meh_bookings';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );

		// Keep the hub out of search engines.
		add_filter( 'wp_robots', array( __CLASS__, 'filter_robots' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 10, 1 );
	}

	/**
	 * Force noindex/nofollow on the hub via the wp_robots filter.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function filter_robots( $robots ) {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return $robots;
		}
		// wp_robots_no_robots() sets noindex + follow; be explicit instead.
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['index'], $robots['follow'] );
		return $robots;
	}

	/**
	 * Disallow the hub in robots.txt.
	 *
	 * @param string $output Existing robots.txt content.
	 * @return string
	 */
	public static function filter_robots_txt( $output ) {
		return $output . "\nDisallow: /" . self::ROUTE . "/\n";
	}

	/**
	 * Register the /bookings rewrite rule.
	 */
	public static function add_rewrite() {
		add_rewrite_rule( '^' . self::ROUTE . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Whitelist our query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Intercept the /bookings request and render the hub.
	 */
	public static function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Not logged in -> redirect to the (custom) login page, returning here.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( Auth::login_url( home_url( '/' . self::ROUTE . '/' ) ) );
			exit;
		}

		// Logged in but not authorised.
		if ( ! Auth::current_user_can_access() ) {
			wp_die(
				esc_html__( 'You do not have permission to access the bookings hub.', 'marthrown-enquiry-hub' ),
				esc_html__( 'Access denied', 'marthrown-enquiry-hub' ),
				array( 'response' => 403 )
			);
		}

		/*
		 * The hub is a full-height shell: the window is the app, and only the
		 * main column scrolls. Core adds `html { margin-top: 32px !important }`
		 * when the admin bar shows, which would push 32px of that shell off the
		 * bottom of the screen — including the bottom of the side nav. The header
		 * already carries Dashboard and Log out links, so the bar earns nothing
		 * here that is worth that.
		 *
		 * Filtered rather than dequeued: this runs on `template_redirect`, before
		 * `wp_head`, so `is_admin_bar_showing()` never returns true for this
		 * request and neither the styles nor the markup are emitted.
		 */
		add_filter( 'show_admin_bar', '__return_false' );

		self::render_page();
		exit;
	}

	/**
	 * Render a self-contained page hosting the React app.
	 */
	protected static function render_page() {
		nocache_headers();
		// Belt-and-braces: header directive as well as the meta tag, so the page
		// stays out of search indexes even if something strips the markup.
		header( 'X-Robots-Tag: noindex, nofollow', true );

		// Enqueue the compiled React bundle + localized data (shared path).
		AdminPage::enqueue_app();

		// Site stylesheet so the hub inherits the theme's look. Themes enqueue
		// their CSS on wp_enqueue_scripts, which wp_head() triggers below.
		wp_enqueue_style( 'meh-frontend', MEH_PLUGIN_URL . 'assets/frontend.css', array(), MEH_VERSION );

		$is_staging = function_exists( 'meh_is_staging' ) && meh_is_staging();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php echo esc_html( self::page_title() ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="meh-frontend<?php echo $is_staging ? ' meh-frontend-staging' : ''; ?>">
	<?php if ( $is_staging ) : ?>
		<div class="meh-staging-banner">
			<?php esc_html_e( 'Marthrown Enquiry Hub — STAGING / development build. Records created here are test data.', 'marthrown-enquiry-hub' ); ?>
		</div>
	<?php endif; ?>

	<div class="meh-frontend-wrap">
		<header class="meh-frontend-header">
			<div class="meh-frontend-brand">
				<?php
				/*
				 * Logo only. The page's heading lives in the hub's own section
				 * head, where the title sits next to the controls that act on
				 * it, so repeating it here left the page naming itself twice
				 * and carrying two competing candidates for its h1.
				 */
				echo self::site_logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe markup from core/escaped below.
				?>
			</div>
			<p class="meh-frontend-user">
				<?php
				printf(
					/* translators: %s: display name */
					esc_html__( 'Signed in as %s', 'marthrown-enquiry-hub' ),
					esc_html( wp_get_current_user()->display_name )
				);
				?>
				&middot; <a href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'Dashboard', 'marthrown-enquiry-hub' ); ?></a>
				&middot; <a href="<?php echo esc_url( wp_logout_url( home_url( '/' . self::ROUTE ) ) ); ?>"><?php esc_html_e( 'Log out', 'marthrown-enquiry-hub' ); ?></a>
			</p>
		</header>

		<div id="enquiry-hub-root"></div>
	</div>

	<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	/**
	 * Page title: site name + section.
	 *
	 * @return string
	 */
	protected static function page_title() {
		return sprintf(
			/* translators: %s: site name */
			__( 'Bookings & Enquiries — %s', 'marthrown-enquiry-hub' ),
			get_bloginfo( 'name' )
		);
	}

	/**
	 * The site's custom logo, linked home; falls back to the site name.
	 *
	 * @return string HTML.
	 */
	protected static function site_logo() {
		if ( function_exists( 'has_custom_logo' ) && has_custom_logo() ) {
			$logo = get_custom_logo();
			if ( $logo ) {
				return '<span class="meh-frontend-logo">' . $logo . '</span>';
			}
		}

		return '<a class="meh-frontend-sitename" href="' . esc_url( home_url( '/' ) ) . '">'
			. esc_html( get_bloginfo( 'name' ) ) . '</a>';
	}
}
