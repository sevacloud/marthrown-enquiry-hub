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
	 * Option holding the version the rewrite rule was last flushed for.
	 */
	const FLUSH_OPTION = 'meh_rewrite_version';

	/**
	 * Stamp prefix marking the one repair flush allowed per version.
	 */
	const REPAIRED = 'repaired:';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		// After `add_rewrite`, so the rule is registered before a flush
		// regenerates the stored set from the registered rules.
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 20 );
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
		add_rewrite_rule( self::pattern(), 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * The rewrite pattern matching /bookings, with or without a trailing slash.
	 *
	 * @return string
	 */
	public static function pattern() {
		return '^' . self::ROUTE . '/?$';
	}

	/**
	 * Flush the rewrite rules when the stored set cannot serve /bookings.
	 *
	 * `add_rewrite()` registers the rule on every request, but a registered rule
	 * only answers a URL once it has been written into the `rewrite_rules`
	 * option, and only `flush_rewrite_rules()` writes it. Activation flushes —
	 * except that deployment here is an SFTP file mirror, so the plugin is never
	 * re-activated and the activation flush never runs again. An environment the
	 * files were mirrored into, or one where anything else flushed the rules
	 * while this plugin was inactive, therefore holds a rule set without
	 * /bookings in it, and the URL 404s. This is the same reason
	 * `Schema::maybe_upgrade()` runs on bootstrap rather than on activation
	 * alone.
	 *
	 * @return bool Whether a flush was issued.
	 */
	public static function maybe_flush() {
		if ( ! self::permalinks_in_use() ) {
			return false;
		}

		$stamp = self::flush_stamp();

		if ( '' === $stamp ) {
			return false;
		}

		/*
		 * Stamped before the flush rather than after it. If the rule cannot be
		 * stored at all — something else filtering it out of the rule set, say —
		 * then recording the attempt afterwards would leave the stamp unchanged
		 * and the flush would be issued again on the next request, and every
		 * request after that. A flush regenerates every rule on the site and
		 * writes an option, which is not a per-request cost worth paying to
		 * retry something that is not working.
		 */
		update_option( self::FLUSH_OPTION, $stamp, false );

		// Soft flush: the rule routes through index.php, so it needs no
		// .htaccess change and there is no reason to rewrite that file.
		flush_rewrite_rules( false );

		return true;
	}

	/**
	 * The stamp to record for this flush, or `''` when no flush is needed.
	 *
	 * Two things are worth a flush, and each gets a stamp of its own so that
	 * neither can repeat: the deployed version changed, and the rule has since
	 * gone missing from a rule set that was flushed for this version already.
	 * The second is allowed exactly once, because a rule that will never store
	 * must not cost a flush on every request.
	 *
	 * @return string
	 */
	protected static function flush_stamp() {
		$done     = (string) get_option( self::FLUSH_OPTION, '' );
		$repaired = self::REPAIRED . MEH_VERSION;

		if ( MEH_VERSION !== $done && $repaired !== $done ) {
			return MEH_VERSION;
		}

		if ( $repaired !== $done && ! self::rule_is_stored() ) {
			return $repaired;
		}

		return '';
	}

	/**
	 * Whether the stored rewrite rules can route /bookings.
	 *
	 * @return bool
	 */
	public static function rule_is_stored() {
		$rules = get_option( 'rewrite_rules' );

		return is_array( $rules ) && isset( $rules[ self::pattern() ] );
	}

	/**
	 * Whether the site uses pretty permalinks.
	 *
	 * Under plain permalinks WordPress stores no rewrite rules at all, so there
	 * is nothing to repair and nothing a flush could achieve: checking for the
	 * rule would fail forever and ask for a flush on every request. The query
	 * var is registered either way, so `/?meh_bookings=1` still reaches the hub.
	 *
	 * @return bool
	 */
	protected static function permalinks_in_use() {
		global $wp_rewrite;

		return $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks();
	}

	/**
	 * Allow our query var through to the main query.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * The hub's own URL, which is where login returns a visitor to.
	 *
	 * @return string
	 */
	public static function url() {
		return home_url( '/' . self::ROUTE . '/' );
	}

	/**
	 * What this request has earned: the hub, the login screen, or a refusal.
	 *
	 * The hub is for signed-in staff only, and the two ways of not being one of
	 * them want different answers. A visitor who is not signed in has done
	 * nothing wrong and is sent to the Marthrown login screen with the hub as
	 * the return URL, so signing in lands them where they were going. A visitor
	 * who *is* signed in but holds none of the allowed roles has arrived
	 * somewhere they may not go, and sending them to a login screen they are
	 * already past would be a loop; they are refused.
	 *
	 * Separated from `maybe_render()` because that method ends in `exit` on
	 * every branch, which is untestable. This one only decides.
	 *
	 * @return array{action:string, url:string} `action` is `redirect`, `deny` or `render`.
	 */
	public static function gate() {
		if ( ! is_user_logged_in() ) {
			return array(
				'action' => 'redirect',
				'url'    => Auth::login_url( self::url() ),
			);
		}

		if ( ! Auth::current_user_can_access() ) {
			return array(
				'action' => 'deny',
				'url'    => '',
			);
		}

		return array(
			'action' => 'render',
			'url'    => '',
		);
	}

	/**
	 * Intercept the /bookings request and render the hub.
	 */
	public static function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$gate = self::gate();

		if ( 'redirect' === $gate['action'] ) {
			wp_safe_redirect( $gate['url'] );
			exit;
		}

		if ( 'deny' === $gate['action'] ) {
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
				&middot; <a href="<?php echo esc_url( wp_logout_url( self::url() ) ); ?>"><?php esc_html_e( 'Log out', 'marthrown-enquiry-hub' ); ?></a>
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
