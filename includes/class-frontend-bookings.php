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

		// Not logged in -> redirect to the login page, returning here after.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/' . self::ROUTE . '/' ) ) );
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

		self::render_page();
		exit;
	}

	/**
	 * Render a self-contained page hosting the React app.
	 */
	protected static function render_page() {
		nocache_headers();

		// Enqueue the compiled React bundle + localized data (shared path).
		AdminPage::enqueue_app();

		$is_staging = function_exists( 'meh_is_staging' ) && meh_is_staging();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php esc_html_e( 'Bookings & Enquiries', 'marthrown-enquiry-hub' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( MEH_PLUGIN_URL . 'assets/frontend.css' ); ?>?v=<?php echo esc_attr( MEH_VERSION ); ?>" />
	<?php wp_print_styles(); ?>
</head>
<body class="meh-frontend<?php echo $is_staging ? ' meh-frontend-staging' : ''; ?>">
	<?php if ( $is_staging ) : ?>
		<div class="meh-staging-banner">
			<?php esc_html_e( 'Marthrown Enquiry Hub — STAGING / development build. Records created here are test data.', 'marthrown-enquiry-hub' ); ?>
		</div>
	<?php endif; ?>

	<div class="meh-frontend-wrap">
		<header class="meh-frontend-header">
			<h1><?php esc_html_e( 'Bookings & Enquiries', 'marthrown-enquiry-hub' ); ?></h1>
			<p class="meh-frontend-user">
				<?php
				printf(
					/* translators: %s: display name */
					esc_html__( 'Signed in as %s', 'marthrown-enquiry-hub' ),
					esc_html( wp_get_current_user()->display_name )
				);
				?>
				&middot; <a href="<?php echo esc_url( wp_logout_url( home_url( '/' . self::ROUTE ) ) ); ?>"><?php esc_html_e( 'Log out', 'marthrown-enquiry-hub' ); ?></a>
			</p>
		</header>

		<div id="enquiry-hub-root"></div>
	</div>

	<?php wp_print_footer_scripts(); ?>
</body>
</html>
		<?php
	}
}
