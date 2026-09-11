<?php
/**
 * The white screen: what happens when a hook of ours runs before WordPress has
 * built the globals it reads.
 *
 * The plugin boots on `plugins_loaded`, and `wp-settings.php` creates `$wp_query`
 * and `$wp_rewrite` only after that action has finished. Two of our callbacks
 * read them, and both were reachable inside that window:
 *
 * - `http_request_args` fires for every outbound HTTP request, including the
 *   licence check another plugin makes from its own `plugins_loaded` handler.
 *   `attach_secret_header()` answered by composing `rest_url()`, which reads
 *   `$wp_rewrite` — so an unrelated plugin's licence check took the site down
 *   with "Call to a member function using_index_permalinks() on null".
 * - `wp_robots` fires from `wp_die()`, which is how WordPress renders its own
 *   fatal-error page. `filter_robots()` answered by reading `get_query_var()`,
 *   which reads `$wp_query` — so the fatal handler fatalled too, and the site
 *   served a blank page: no critical-error message, no recovery-mode email, and
 *   no way back but deactivating the plugin by hand.
 *
 * The second is why these are asserted together. A crash in a hook is one bug; a
 * crash in a hook that WordPress calls *while already crashing* costs the site
 * its only way of saying so, and turns any future fatal from anywhere into the
 * same white screen.
 *
 * Each example nulls the global the way the boot window does, calls the hook, and
 * asserts it answered without touching it. The last two examples are the other
 * half: with the globals in place, both callbacks still do their job, so the
 * guards cannot pass by having switched the features off.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Boot;
use MarthrownEnquiryHub\FrontendBookings;
use MarthrownEnquiryHub\IntakeEndpoint;

/**
 * Class EarlyBootSafetyTest
 */
class EarlyBootSafetyTest extends WP_UnitTestCase {

	/**
	 * A secret in Settings, so the filter reaches the guard rather than stopping
	 * at its "nothing stored to attach" branch.
	 */
	const SECRET = 'Rd8vK2pQm5zTx7wLn3Yc';

	/**
	 * The outbound URL that brought the site down: another plugin's licence check.
	 */
	const FOREIGN_URL = 'https://smushpro.wpmudev.com/1.0/';

	/**
	 * Load the classes under test.
	 *
	 * The intake chain in the bootstrap's own order: `IntakeHandler` resolves
	 * constants from `EnquiryCreator` as its class body is evaluated.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-boot.php';
		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-clock.php';
		require_once MEH_INCLUDES_DIR . 'class-auth.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-query.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-store.php';
		require_once MEH_INCLUDES_DIR . 'class-history-recorder.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-validator.php';
		require_once MEH_INCLUDES_DIR . 'class-field-mapper.php';
		require_once MEH_INCLUDES_DIR . 'class-duplicate-detector.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-handler.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-endpoint.php';
		require_once MEH_INCLUDES_DIR . 'class-admin-page.php';
		require_once MEH_INCLUDES_DIR . 'class-frontend-bookings.php';
	}

	public function set_up() {
		parent::set_up();

		update_option( IntakeEndpoint::SECRET_OPTION, self::SECRET );
	}

	public function tear_down() {
		delete_option( IntakeEndpoint::SECRET_OPTION );

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * Before WordPress is ready
	 * ------------------------------------------------------------------ */

	/**
	 * The reported crash: an outbound request made from another plugin's
	 * `plugins_loaded` handler, filtered before `$wp_rewrite` exists.
	 *
	 * @return void
	 */
	public function test_an_outbound_request_before_the_rewrite_exists_is_left_alone() {
		$args = array(
			'method'  => 'GET',
			'headers' => array(),
		);

		$filtered = $this->without( 'wp_rewrite', static function () use ( $args ) {
			return IntakeEndpoint::attach_secret_header( $args, self::FOREIGN_URL );
		} );

		$this->assertSame( $args, $filtered, 'The arguments should come back untouched.' );
		$this->assertArrayNotHasKey(
			IntakeEndpoint::SECRET_HEADER,
			$filtered['headers'],
			'A request that cannot be identified as intake must not be given the secret.'
		);
	}

	/**
	 * Even a URL that *is* the intake path is left alone that early: the endpoint's
	 * own URL cannot be composed, so nothing can be confirmed as its own.
	 *
	 * @return void
	 */
	public function test_the_intake_path_itself_is_left_alone_before_the_rewrite_exists() {
		$url  = home_url( '/wp-json/' . IntakeEndpoint::NAMESPACE . IntakeEndpoint::ROUTE );
		$args = array(
			'method'  => 'POST',
			'headers' => array(),
		);

		$filtered = $this->without( 'wp_rewrite', static function () use ( $args, $url ) {
			return IntakeEndpoint::attach_secret_header( $args, $url );
		} );

		$this->assertSame( $args, $filtered );
	}

	/**
	 * What `url()` answers in that window: the route path, carrying no host, which
	 * is what `is_intake_url()` refuses to match anything against.
	 *
	 * @return void
	 */
	public function test_the_endpoint_url_falls_back_to_the_route_path() {
		$url = $this->without( 'wp_rewrite', static function () {
			return IntakeEndpoint::url();
		} );

		$this->assertSame( IntakeEndpoint::NAMESPACE . IntakeEndpoint::ROUTE, $url );

		$parts = wp_parse_url( $url );

		$this->assertArrayNotHasKey( 'host', $parts, 'A path with a host would be matched against.' );
	}

	/**
	 * The white screen itself: `wp_robots` running from the fatal-error handler,
	 * with no query object to read.
	 *
	 * WordPress has to be able to render its own error page while our filters are
	 * registered, or one fatal anywhere on the site becomes a blank page.
	 *
	 * @return void
	 */
	public function test_the_robots_filter_survives_the_fatal_error_handler() {
		$robots = array(
			'index'  => true,
			'follow' => true,
		);

		$filtered = $this->without( 'wp_query', static function () use ( $robots ) {
			return FrontendBookings::filter_robots( $robots );
		} );

		$this->assertSame( $robots, $filtered, 'With no request to inspect, the directives pass through.' );
	}

	/**
	 * The view a request with no query object is asking for: the Overview, which is
	 * what an absent view var means anyway.
	 *
	 * @return void
	 */
	public function test_the_current_view_is_the_overview_before_the_query_exists() {
		$view = $this->without( 'wp_query', static function () {
			return FrontendBookings::current_view();
		} );

		$this->assertSame( FrontendBookings::DEFAULT_VIEW, $view );
	}

	/**
	 * Both answers, from `Boot` itself.
	 *
	 * @return void
	 */
	public function test_boot_reports_the_globals_it_is_asked_about() {
		$this->assertTrue( Boot::has_rewrite() );
		$this->assertTrue( Boot::has_query() );

		$this->assertFalse( $this->without( 'wp_rewrite', array( Boot::class, 'has_rewrite' ) ) );
		$this->assertFalse( $this->without( 'wp_query', array( Boot::class, 'has_query' ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Once WordPress is ready
	 * ------------------------------------------------------------------ */

	/**
	 * The guards did not switch the features off: with the globals in place, the
	 * secret is still attached to the endpoint's own URL.
	 *
	 * @return void
	 */
	public function test_the_secret_is_still_attached_once_the_rewrite_exists() {
		$filtered = IntakeEndpoint::attach_secret_header(
			array(
				'method'  => 'POST',
				'headers' => array(),
			),
			IntakeEndpoint::url()
		);

		$this->assertSame( self::SECRET, $filtered['headers'][ IntakeEndpoint::SECRET_HEADER ] );
	}

	/**
	 * And the hub is still kept out of search engines.
	 *
	 * @return void
	 */
	public function test_the_hub_is_still_hidden_from_search_engines() {
		set_query_var( FrontendBookings::QUERY_VAR, 1 );

		$robots = FrontendBookings::filter_robots( array( 'index' => true ) );

		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['nofollow'] );
		$this->assertArrayNotHasKey( 'index', $robots );
	}

	/* ---------------------------------------------------------------------
	 * Helper
	 * ------------------------------------------------------------------ */

	/**
	 * Run a callback with one WordPress global nulled, as the boot window has it.
	 *
	 * Nulled rather than unset, because that is the state `wp-settings.php` leaves
	 * behind before it builds them — and restored in every case, so a failing
	 * assertion cannot take the rest of the suite down with it.
	 *
	 * @param string   $name     Global name.
	 * @param callable $callback Work to do while it is missing.
	 * @return mixed The callback's return value.
	 */
	private function without( $name, $callback ) {
		$original = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;

		$GLOBALS[ $name ] = null;

		try {
			return call_user_func( $callback );
		} finally {
			$GLOBALS[ $name ] = $original;
		}
	}
}
