<?php
/**
 * Back-link banner on WP Booking System admin pages.
 *
 * WPBS renders its own header (`.wpbs-plugin-header`) on every `wpbs-*` admin
 * page. Rather than filtering that markup (which the plugin owns and could
 * change on update), we output a thin sticky bar of our own just above it via
 * `in_admin_header`, linking back to the Enquiry Hub.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WpbsBanner
 */
class WpbsBanner {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'in_admin_header', array( __CLASS__, 'maybe_render' ) );
		add_action( 'admin_head', array( __CLASS__, 'maybe_styles' ) );
	}

	/**
	 * Whether the current admin screen is a WP Booking System page.
	 *
	 * @return bool
	 */
	protected static function is_wpbs_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the current screen only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return '' !== $page && 0 === strpos( $page, 'wpbs' );
	}

	/**
	 * Whether the banner should show for this request.
	 *
	 * @return bool
	 */
	protected static function should_render() {
		return self::is_wpbs_page() && Auth::current_user_can_access();
	}

	/**
	 * Output the sticky back-link bar.
	 */
	public static function maybe_render() {
		if ( ! self::should_render() ) {
			return;
		}

		$url        = home_url( '/' . FrontendBookings::ROUTE . '/' );
		$is_staging = function_exists( 'meh_is_staging' ) && meh_is_staging();
		?>
		<div class="meh-wpbs-backbar<?php echo $is_staging ? ' is-staging' : ''; ?>">
			<span class="meh-wpbs-backbar__label">
				<?php esc_html_e( 'WP Booking System', 'marthrown-enquiry-hub' ); ?>
			</span>
			<a class="meh-wpbs-backbar__link" href="<?php echo esc_url( $url ); ?>">
				<span class="dashicons dashicons-arrow-left-alt"></span>
				<?php esc_html_e( 'Back to Enquiry Hub', 'marthrown-enquiry-hub' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Inline styles for the bar (tiny, so no extra request).
	 */
	public static function maybe_styles() {
		if ( ! self::should_render() ) {
			return;
		}
		?>
		<style>
			.meh-wpbs-backbar {
				position: sticky;
				top: 32px;
				z-index: 9991;
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 12px;
				margin: 0 0 0 -20px;
				padding: 6px 16px;
				width: calc(100% + 20px);
				box-sizing: border-box;
				background: #1d2327;
				color: #f0f0f1;
				font-size: 12px;
				font-weight: 600;
			}
			.meh-wpbs-backbar.is-staging {
				background: #d63638;
			}
			.meh-wpbs-backbar__link {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				color: #72aee6;
				text-decoration: none;
			}
			.meh-wpbs-backbar.is-staging .meh-wpbs-backbar__link {
				color: #fff;
				text-decoration: underline;
			}
			.meh-wpbs-backbar__link .dashicons {
				font-size: 16px;
				width: 16px;
				height: 16px;
			}
			@media screen and (max-width: 782px) {
				.meh-wpbs-backbar { top: 46px; }
			}
		</style>
		<?php
	}
}
