<?php
/**
 * Front-end /bookings route.
 *
 * Exposes the Enquiry Hub at the site URL /bookings without needing a
 * WordPress page. The route is login-protected and restricted to the
 * administrator, manager and operations roles.
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
	 * Roles allowed to view the bookings hub.
	 *
	 * @return string[]
	 */
	public static function allowed_roles() {
		/**
		 * Filter the roles allowed to access the /bookings hub.
		 *
		 * @param string[] $roles Role slugs.
		 */
		return (array) apply_filters(
			'meh_bookings_allowed_roles',
			array( 'administrator', 'manager', 'operations' )
		);
	}

	/**
	 * Whether the current user may view the hub.
	 *
	 * @return bool
	 */
	public static function current_user_can_view() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Administrators always pass, regardless of role slug naming.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user = wp_get_current_user();
		return (bool) array_intersect( self::allowed_roles(), (array) $user->roles );
	}

	/**
	 * Intercept the /bookings request and render the hub.
	 */
	public static function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Not logged in -> redirect to the login page, returning to /bookings
		// after a successful sign-in.
		if ( ! is_user_logged_in() ) {
			$redirect_to = home_url( '/' . self::ROUTE . '/' );
			wp_safe_redirect( wp_login_url( $redirect_to ) );
			exit;
		}

		// Logged in but not authorised.
		if ( ! self::current_user_can_view() ) {
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
	 * Render a self-contained page hosting the Enquiry Hub content.
	 */
	protected static function render_page() {
		nocache_headers();

		$is_staging = function_exists( 'meh_is_staging' ) && meh_is_staging();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php esc_html_e( 'Bookings & Enquiries', 'marthrown-enquiry-hub' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( MEH_PLUGIN_URL . 'assets/admin.css' ); ?>?v=<?php echo esc_attr( MEH_VERSION ); ?>" />
	<link rel="stylesheet" href="<?php echo esc_url( MEH_PLUGIN_URL . 'assets/frontend.css' ); ?>?v=<?php echo esc_attr( MEH_VERSION ); ?>" />
</head>
<body class="meh-frontend<?php echo $is_staging ? ' meh-frontend-staging' : ''; ?>">
	<?php if ( $is_staging ) : ?>
		<div class="meh-staging-banner">
			<?php esc_html_e( 'Marthrown Enquiry Hub — STAGING / development build. Records created here are test data.', 'marthrown-enquiry-hub' ); ?>
		</div>
	<?php endif; ?>

	<div class="meh-frontend-wrap meh-wrap">
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

		<?php
		if ( class_exists( __NAMESPACE__ . '\\AdminDashboard' ) ) {
			AdminDashboard::render_dashboard_content();
		} else {
			echo '<p>' . esc_html__( 'The Enquiry Hub is unavailable.', 'marthrown-enquiry-hub' ) . '</p>';
		}
		?>
	</div>
</body>
</html>
		<?php
	}
}
