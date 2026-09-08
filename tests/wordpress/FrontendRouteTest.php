<?php
/**
 * The /bookings route: that it can be reached, and that only staff reach it.
 *
 * Two things are asserted, and they failed for different reasons.
 *
 * The route stopped answering because a registered rewrite rule is not a
 * working URL. `add_rewrite_rule()` runs on every request, but the rule only
 * answers anything once it has been written into the `rewrite_rules` option,
 * and only a flush writes it. The flush lived on activation alone, while
 * deployment is an SFTP file mirror that never re-activates the plugin — so
 * every environment the files were mirrored into held a rule set with no
 * /bookings in it, and the URL 404ed. `maybe_flush()` is what fixes that, and
 * what is asserted here is the whole of its contract: it flushes once for a
 * version, not again; it repairs a rule that has gone missing since, but only
 * once; and it does nothing at all under plain permalinks, where there are no
 * stored rules to repair and a rule check would ask for a flush forever.
 *
 * The gate is asserted through `gate()` rather than `maybe_render()`, because
 * that method ends in `exit` on every branch. A visitor who is not signed in is
 * sent to the login screen with the hub as the return URL; one who is signed in
 * without an allowed role is refused rather than redirected, since sending them
 * to a login screen they are already past would loop.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Auth;
use MarthrownEnquiryHub\FrontendBookings;

/**
 * Class FrontendRouteTest
 */
class FrontendRouteTest extends WP_UnitTestCase {

	/**
	 * A permalink structure, so the stored rule set is non-empty.
	 */
	const PERMALINKS = '/%postname%/';

	/**
	 * The permalink structure in force outside this test.
	 *
	 * @var string
	 */
	private $original_structure = '';

	/**
	 * Load the classes under test.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-auth.php';
		require_once MEH_INCLUDES_DIR . 'class-admin-page.php';
		require_once MEH_INCLUDES_DIR . 'class-frontend-bookings.php';
	}

	public function set_up() {
		parent::set_up();

		global $wp_rewrite;

		$this->original_structure = get_option( 'permalink_structure' );

		$wp_rewrite->set_permalink_structure( self::PERMALINKS );

		delete_option( FrontendBookings::FLUSH_OPTION );

		FrontendBookings::add_rewrite();
	}

	public function tear_down() {
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( $this->original_structure );

		delete_option( FrontendBookings::FLUSH_OPTION );

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * Reachability
	 * ------------------------------------------------------------------ */

	/**
	 * The registered rule is the one the flush check looks for. If these two
	 * ever drifted apart, the check would ask for a flush on every request and
	 * find the rule missing every time.
	 *
	 * @return void
	 */
	public function test_the_registered_rule_is_the_rule_that_is_looked_for() {
		global $wp_rewrite;

		$this->assertArrayHasKey(
			FrontendBookings::pattern(),
			$wp_rewrite->extra_rules_top,
			'The rule should be registered at the top of the rule set.'
		);

		FrontendBookings::maybe_flush();

		$this->assertTrue(
			FrontendBookings::rule_is_stored(),
			'A flush should leave the rule where a request can find it.'
		);
	}

	/**
	 * The bug itself: a rule set flushed without this plugin registered — which
	 * is the state an SFTP mirror leaves behind — cannot route /bookings, and
	 * the bootstrap flush is what repairs it.
	 *
	 * @return void
	 */
	public function test_a_rule_set_flushed_without_the_plugin_is_repaired() {
		global $wp_rewrite;

		// Flush with the rule un-registered: exactly what happens when anything
		// else on the site flushes while this plugin is inactive.
		$wp_rewrite->extra_rules_top = array();
		flush_rewrite_rules( false );

		$this->assertFalse(
			FrontendBookings::rule_is_stored(),
			'The fixture should start from the broken state.'
		);

		FrontendBookings::add_rewrite();

		$this->assertTrue( FrontendBookings::maybe_flush(), 'The missing rule should be flushed for.' );
		$this->assertTrue( FrontendBookings::rule_is_stored(), '/bookings should route again.' );
	}

	/**
	 * A flush is expensive — it regenerates every rule on the site and writes an
	 * option — so a working route must not pay for one on every request.
	 *
	 * @return void
	 */
	public function test_a_working_route_is_flushed_for_once_and_not_again() {
		$this->assertTrue( FrontendBookings::maybe_flush(), 'The first request of a version flushes.' );

		$this->assertSame(
			MEH_VERSION,
			get_option( FrontendBookings::FLUSH_OPTION ),
			'The version flushed for should be recorded.'
		);

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertFalse(
				FrontendBookings::maybe_flush(),
				'A route that already works should not be flushed for again.'
			);
		}
	}

	/**
	 * A rule that can never be stored — something else filtering it out of the
	 * rule set for good — must cost a bounded number of flushes rather than one
	 * per request. Two: one for the version, one repair.
	 *
	 * @return void
	 */
	public function test_a_rule_that_will_not_store_stops_being_flushed_for() {
		$drop = static function ( $rules ) {
			unset( $rules[ FrontendBookings::pattern() ] );

			return $rules;
		};

		add_filter( 'rewrite_rules_array', $drop );

		$flushes = 0;

		for ( $i = 0; $i < 6; $i++ ) {
			if ( FrontendBookings::maybe_flush() ) {
				$flushes++;
			}
		}

		remove_filter( 'rewrite_rules_array', $drop );

		$this->assertFalse( FrontendBookings::rule_is_stored(), 'The filter should have kept the rule out.' );
		$this->assertSame( 2, $flushes, 'One flush for the version and one repair, then no more.' );
	}

	/**
	 * Under plain permalinks there are no stored rules to hold the rule, so
	 * there is nothing to repair and a rule check would ask for a flush on
	 * every request forever.
	 *
	 * @return void
	 */
	public function test_plain_permalinks_are_left_alone() {
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( '' );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertFalse(
				FrontendBookings::maybe_flush(),
				'A site without pretty permalinks should never be flushed for.'
			);
		}

		$this->assertFalse(
			(bool) get_option( FrontendBookings::FLUSH_OPTION, false ),
			'Nothing should be stamped when nothing was done.'
		);
	}

	/* ---------------------------------------------------------------------
	 * The gate
	 * ------------------------------------------------------------------ */

	/**
	 * A visitor who is not signed in is sent to the login screen, and comes back
	 * to the hub once they are.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_is_sent_to_the_login_screen() {
		wp_set_current_user( 0 );

		$gate = FrontendBookings::gate();

		$this->assertSame( 'redirect', $gate['action'] );
		$this->assertSame( Auth::login_url( FrontendBookings::url() ), $gate['url'] );

		// The return URL is the hub, so signing in lands where they were going.
		$this->assertStringContainsString(
			rawurlencode( FrontendBookings::url() ),
			$gate['url'],
			'The login URL should carry the hub as its return URL.'
		);

		// And it is a URL a redirect will actually follow: `wp_safe_redirect()`
		// drops anything off-host, which would leave a blank page.
		$this->assertSame(
			$gate['url'],
			wp_validate_redirect( $gate['url'], '' ),
			'The login URL must survive the safe-redirect host check.'
		);
	}

	/**
	 * The hub is for staff, so a subscriber who is signed in is refused rather
	 * than sent to a login screen they are already past.
	 *
	 * @return void
	 */
	public function test_a_signed_in_visitor_without_a_role_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 'deny', FrontendBookings::gate()['action'] );
	}

	/**
	 * An administrator gets the hub.
	 *
	 * @return void
	 */
	public function test_an_allowed_user_gets_the_hub() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 'render', FrontendBookings::gate()['action'] );
	}

	/**
	 * Nothing is intercepted on a request that is not for the hub, whoever is
	 * making it: `maybe_render()` returns before the gate is consulted.
	 *
	 * @return void
	 */
	public function test_other_requests_are_not_intercepted() {
		wp_set_current_user( 0 );

		set_query_var( FrontendBookings::QUERY_VAR, '' );

		$this->assertNull(
			FrontendBookings::maybe_render(),
			'A request without the query var should be left alone.'
		);
	}
}
