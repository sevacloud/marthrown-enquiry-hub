<?php
/**
 * The registered REST surface, read back off the server rather than off the
 * source.
 *
 * `RestEnquiries::register_routes()` and `IntakeEndpoint::register_routes()` are
 * run through `rest_api_init` and the result is then inspected through
 * `rest_get_server()->get_routes()`, so what is asserted here is what WordPress
 * ended up holding — the paths, the method sets, the permission callbacks and the
 * declared args as core normalised them — rather than what the two files appear
 * to say.
 *
 * Four things are established:
 *
 * - **Every route exists, on the path and with the methods the hub calls.** The
 *   twelve enquiry registrations sit on eleven paths, because `/enquiries` carries
 *   both the list and manual creation (Requirements 18.1, 18.21) and
 *   `/enquiries/{id}` carries both the read and the edit (Requirements 19.1,
 *   19.19). The re-raise, note, migration and rejections routes are asserted by
 *   name as well as in the sweep, those four being the ones Requirements 9.3,
 *   10.1, 15.13 and 4.8 name individually.
 * - **Permission callbacks.** Every enquiry route uses `Auth::rest_permission`
 *   (Requirement 16.2), except the two administrative ones, which use
 *   `RestEnquiries::admin_permission` — a composition that runs
 *   `Auth::rest_permission` first and then asks for `manage_options`
 *   (Requirements 15.13, 16.6), so Auth still gates them.
 * - **The intake route is the documented exception.** `POST /intake` carries
 *   `IntakeEndpoint::authenticate` and not `Auth::rest_permission`, which is what
 *   lets a webhook carrying no WordPress user create an enquiry
 *   (Requirements 16.8, 16.9).
 * - **Every declared arg carries a `sanitize_callback`** (Requirement 16.7), and
 *   one that is actually callable, so no request value reaches the store
 *   unsanitised.
 *
 * No enquiry tables and no fixtures: nothing here dispatches a request, so
 * nothing here needs a store. `RestEnquiryWriteTest`, `RestEnquirySingleTest` and
 * `IntakeEndpointTest` cover what the callbacks behind these routes do.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Auth;
use MarthrownEnquiryHub\IntakeEndpoint;
use MarthrownEnquiryHub\RestEnquiries;

/**
 * Class RestRouteRegistrationTest
 */
class RestRouteRegistrationTest extends WP_UnitTestCase {

	/**
	 * The REST server in force outside this test.
	 *
	 * @var \WP_REST_Server|null
	 */
	private $original_server = null;

	/**
	 * Load the classes under test.
	 *
	 * Only what registration itself touches: the route declarations read
	 * `EnquiryQuery`'s paging constants, and nothing else is reached because no
	 * callback is ever called.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-clock.php';
		require_once MEH_INCLUDES_DIR . 'class-auth.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-query.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-endpoint.php';
	}

	public function set_up() {
		parent::set_up();

		global $wp_rest_server;

		$this->original_server = $wp_rest_server;
		$wp_rest_server        = null;

		add_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( IntakeEndpoint::class, 'register_routes' ) );

		rest_get_server();
	}

	public function tear_down() {
		global $wp_rest_server;

		remove_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );
		remove_action( 'rest_api_init', array( IntakeEndpoint::class, 'register_routes' ) );

		$wp_rest_server        = $this->original_server;
		$this->original_server = null;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * The paths and their methods
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 16.1: every route the plugin registers sits under
	 * `marthrown-enquiry-hub/v1`, on the path and with the methods the hub calls.
	 *
	 * @return void
	 */
	public function test_every_route_is_registered_with_the_expected_methods() {
		$routes = $this->server_routes();

		foreach ( $this->expected_methods() as $route => $methods ) {
			$path = $this->path( $route );

			$this->assertArrayHasKey( $path, $routes, $path . ' should be registered.' );
			$this->assertSame( $methods, $this->methods_of( $route ), $path . ' should answer exactly these methods.' );
		}
	}

	/**
	 * The twelve registrations sit on eleven paths, and no thirteenth route has
	 * crept into the namespace.
	 *
	 * Counted from the server so a route added to one of the two files without
	 * being accounted for here fails rather than passing unnoticed. The namespace
	 * index route core registers for `/marthrown-enquiry-hub/v1` itself is not one
	 * of the plugin's, so it is excluded.
	 *
	 * The sweep is over the routes the two classes under test declare, identified
	 * by the class each handler's callback belongs to, rather than over everything
	 * sitting in the namespace. `RestBookings` shares the namespace and registers
	 * its own three routes on `rest_api_init` once the plugin has booted, and those
	 * are no part of the enquiry REST surface this spec declares — so counting them
	 * here would be counting somebody else's routes. Ownership is what is filtered
	 * on rather than a path prefix, so a new enquiry or intake route still shows up
	 * in the count and still fails until it is declared below.
	 *
	 * @return void
	 */
	public function test_the_namespace_holds_exactly_the_declared_routes() {
		$expected = array_map( array( $this, 'path' ), array_keys( $this->expected_methods() ) );
		$found    = array();
		$index    = '/' . RestEnquiries::NAMESPACE;

		foreach ( $this->server_routes() as $path => $handlers ) {
			if ( 0 !== strpos( (string) $path, $index . '/' ) ) {
				continue;
			}

			if ( $this->is_declared_by_the_classes_under_test( (array) $handlers ) ) {
				$found[] = (string) $path;
			}
		}

		sort( $expected );
		sort( $found );

		$this->assertSame( $expected, $found );

		$enquiry_handlers = 0;

		foreach ( array_keys( $this->expected_methods() ) as $route ) {
			if ( IntakeEndpoint::ROUTE === $route ) {
				continue;
			}

			$enquiry_handlers += count( $this->handlers_of( $route ) );
		}

		$this->assertSame(
			12,
			$enquiry_handlers,
			'Twelve enquiry registrations across ten paths, two of those paths carrying two methods each.'
		);
		$this->assertCount( 1, $this->handlers_of( IntakeEndpoint::ROUTE ), 'One intake registration.' );
	}

	/**
	 * Requirements 9.3, 10.1, 15.13, 4.8: the four routes those requirements name
	 * individually exist, each answering the one method it is called with.
	 *
	 * @return void
	 */
	public function test_the_duplicate_note_migration_and_rejection_routes_exist() {
		$expected = array(
			RestEnquiries::ROUTE_DUPLICATE  => array( 'POST' => true ),
			RestEnquiries::ROUTE_NOTES      => array( 'POST' => true ),
			RestEnquiries::ROUTE_MIGRATION  => array( 'POST' => true ),
			RestEnquiries::ROUTE_REJECTIONS => array( 'GET' => true ),
		);

		foreach ( $expected as $route => $methods ) {
			$this->assertArrayHasKey( $this->path( $route ), $this->server_routes() );
			$this->assertSame( $methods, $this->methods_of( $route ), $route . ' should answer exactly these methods.' );
		}

		$this->assertSame(
			array( RestEnquiries::class, 'duplicate_enquiry' ),
			$this->handler_for( RestEnquiries::ROUTE_DUPLICATE, 'POST' )['callback']
		);
		$this->assertSame(
			array( RestEnquiries::class, 'add_note' ),
			$this->handler_for( RestEnquiries::ROUTE_NOTES, 'POST' )['callback']
		);
		$this->assertSame(
			array( RestEnquiries::class, 'run_migration' ),
			$this->handler_for( RestEnquiries::ROUTE_MIGRATION, 'POST' )['callback']
		);
		$this->assertSame(
			array( RestEnquiries::class, 'get_rejections' ),
			$this->handler_for( RestEnquiries::ROUTE_REJECTIONS, 'GET' )['callback']
		);
	}

	/* ---------------------------------------------------------------------
	 * Manual creation and the edit
	 * ------------------------------------------------------------------ */

	/**
	 * Requirements 18.1, 18.21: manual creation is `POST /enquiries`, sharing the
	 * list's path, and Auth gates it exactly as it gates the list.
	 *
	 * @return void
	 */
	public function test_manual_creation_is_post_enquiries_behind_auth() {
		$handler = $this->handler_for( RestEnquiries::ROUTE_COLLECTION, 'POST' );

		$this->assertSame( '/enquiries', RestEnquiries::ROUTE_COLLECTION );
		$this->assertSame( array( RestEnquiries::class, 'create_enquiry' ), $handler['callback'] );
		$this->assertSame( array( Auth::class, 'rest_permission' ), $handler['permission_callback'] );

		// The list handler is still there, on the same path and its own method.
		$list = $this->handler_for( RestEnquiries::ROUTE_COLLECTION, 'GET' );

		$this->assertSame( array( RestEnquiries::class, 'get_enquiries' ), $list['callback'] );
		$this->assertSame( array( Auth::class, 'rest_permission' ), $list['permission_callback'] );
	}

	/**
	 * Requirements 19.1, 19.19: the edit is `PATCH /enquiries/{id}` rather than a
	 * POST sub-route, it sits on the single-enquiry path, and Auth gates it.
	 *
	 * @return void
	 */
	public function test_the_edit_route_is_patch_on_the_single_enquiry_path() {
		$handler = $this->handler_for( RestEnquiries::ROUTE_SINGLE, 'PATCH' );

		$this->assertSame( array( RestEnquiries::class, 'edit_enquiry' ), $handler['callback'] );
		$this->assertSame( array( Auth::class, 'rest_permission' ), $handler['permission_callback'] );

		// The read handler is still there, on the same path and its own method.
		$read = $this->handler_for( RestEnquiries::ROUTE_SINGLE, 'GET' );

		$this->assertSame( array( RestEnquiries::class, 'get_enquiry' ), $read['callback'] );
		$this->assertSame( array( Auth::class, 'rest_permission' ), $read['permission_callback'] );
	}

	/* ---------------------------------------------------------------------
	 * Permission callbacks
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 16.2: every enquiry route uses `Auth::rest_permission`, bar the
	 * two administrative ones, which compose it.
	 *
	 * @return void
	 */
	public function test_every_enquiry_route_is_gated_by_auth() {
		$administrative = array( RestEnquiries::ROUTE_MIGRATION, RestEnquiries::ROUTE_TEST_RECORDS );

		foreach ( array_keys( $this->expected_methods() ) as $route ) {
			if ( IntakeEndpoint::ROUTE === $route || in_array( $route, $administrative, true ) ) {
				continue;
			}

			foreach ( $this->handlers_of( $route ) as $handler ) {
				$this->assertSame(
					array( Auth::class, 'rest_permission' ),
					$handler['permission_callback'],
					$route . ' should be gated by Auth::rest_permission.'
				);
			}
		}
	}

	/**
	 * Requirements 15.13, 16.6, 17.7: the migration run and the test-record delete
	 * go through `admin_permission`, which is the composition rather than a
	 * replacement — it calls `Auth::rest_permission` before it asks for
	 * `manage_options`.
	 *
	 * @return void
	 */
	public function test_the_administrative_routes_require_the_admin_permission() {
		$routes = array(
			RestEnquiries::ROUTE_MIGRATION    => 'POST',
			RestEnquiries::ROUTE_TEST_RECORDS => 'DELETE',
		);

		foreach ( $routes as $route => $method ) {
			$handler = $this->handler_for( $route, $method );

			$this->assertSame(
				array( RestEnquiries::class, 'admin_permission' ),
				$handler['permission_callback'],
				$route . ' should require the composed administrative permission.'
			);
			$this->assertNotSame(
				array( Auth::class, 'rest_permission' ),
				$handler['permission_callback'],
				$route . ' should ask for more than Auth alone.'
			);
		}

		$this->assertSame( 'manage_options', RestEnquiries::ADMIN_CAPABILITY );
	}

	/**
	 * Requirements 16.8, 16.9: the intake route is `POST /intake`, its permission
	 * callback authenticates the Intake Secret, and it is the only route in the
	 * namespace whose permission callback does not run Auth at all.
	 *
	 * @return void
	 */
	public function test_the_intake_route_authenticates_the_secret_instead_of_auth() {
		$handler = $this->handler_for( IntakeEndpoint::ROUTE, 'POST' );

		$this->assertSame( '/intake', IntakeEndpoint::ROUTE );
		$this->assertSame( RestEnquiries::NAMESPACE, IntakeEndpoint::NAMESPACE );
		$this->assertSame( array( 'POST' => true ), $this->methods_of( IntakeEndpoint::ROUTE ) );

		$this->assertSame( array( IntakeEndpoint::class, 'handle' ), $handler['callback'] );
		$this->assertSame( array( IntakeEndpoint::class, 'authenticate' ), $handler['permission_callback'] );
		$this->assertNotSame( array( Auth::class, 'rest_permission' ), $handler['permission_callback'] );

		$others = array();

		foreach ( array_keys( $this->expected_methods() ) as $route ) {
			if ( IntakeEndpoint::ROUTE === $route ) {
				continue;
			}

			foreach ( $this->handlers_of( $route ) as $other ) {
				if ( array( IntakeEndpoint::class, 'authenticate' ) === $other['permission_callback'] ) {
					$others[] = $route;
				}
			}
		}

		$this->assertSame( array(), $others, 'No other route may authenticate the Intake Secret.' );
	}

	/* ---------------------------------------------------------------------
	 * Sanitisation
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 16.7: every declared arg on every route carries a
	 * `sanitize_callback`, and one that can actually be called.
	 *
	 * @return void
	 */
	public function test_every_declared_arg_carries_a_sanitize_callback() {
		$checked = 0;

		foreach ( array_keys( $this->expected_methods() ) as $route ) {
			foreach ( $this->handlers_of( $route ) as $handler ) {
				foreach ( (array) $handler['args'] as $name => $arg ) {
					$where = $route . ' arg `' . $name . '`';

					$this->assertArrayHasKey( 'sanitize_callback', (array) $arg, $where . ' should declare a sanitize_callback.' );
					$this->assertNotEmpty( $arg['sanitize_callback'], $where . ' should declare a sanitize_callback.' );
					$this->assertIsCallable( $arg['sanitize_callback'], $where . ' should declare a callable sanitize_callback.' );

					++$checked;
				}
			}
		}

		$this->assertGreaterThan( 0, $checked, 'The sweep should have found declared args to check.' );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * The routes the plugin registers, each with the method set it answers.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function expected_methods() {
		return array(
			RestEnquiries::ROUTE_COLLECTION   => array(
				'GET'  => true,
				'POST' => true,
			),
			RestEnquiries::ROUTE_SINGLE       => array(
				'GET'   => true,
				'PATCH' => true,
			),
			RestEnquiries::ROUTE_STATUS       => array( 'POST' => true ),
			RestEnquiries::ROUTE_NOTES        => array( 'POST' => true ),
			RestEnquiries::ROUTE_DUPLICATE    => array( 'POST' => true ),
			RestEnquiries::ROUTE_CONVERT      => array( 'POST' => true ),
			RestEnquiries::ROUTE_RETRY_CRM    => array( 'POST' => true ),
			RestEnquiries::ROUTE_REJECTIONS   => array( 'GET' => true ),
			RestEnquiries::ROUTE_MIGRATION    => array( 'POST' => true ),
			RestEnquiries::ROUTE_TEST_RECORDS => array( 'DELETE' => true ),
			IntakeEndpoint::ROUTE             => array( 'POST' => true ),
		);
	}

	/**
	 * Whether a registered path belongs to `RestEnquiries` or `IntakeEndpoint`.
	 *
	 * Read off the callbacks rather than off the path, so a route the two classes
	 * add later is picked up wherever it is put, and a route another class puts in
	 * the shared namespace is left to that class's own test.
	 *
	 * @param array $handlers Everything the server holds against one path.
	 * @return bool
	 */
	private function is_declared_by_the_classes_under_test( array $handlers ) {
		$ours = array( RestEnquiries::class, IntakeEndpoint::class );

		foreach ( $handlers as $key => $handler ) {
			if ( ! is_int( $key ) || ! is_array( $handler ) || ! isset( $handler['callback'] ) ) {
				continue;
			}

			$callback = $handler['callback'];

			if ( is_array( $callback ) && isset( $callback[0] ) && in_array( $callback[0], $ours, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Everything the REST server holds.
	 *
	 * @return array<string,array>
	 */
	private function server_routes() {
		return (array) rest_get_server()->get_routes();
	}

	/**
	 * The full registered path of a route declared relative to the namespace.
	 *
	 * @param string $route Route, as the class constant states it.
	 * @return string
	 */
	private function path( $route ) {
		return '/' . RestEnquiries::NAMESPACE . $route;
	}

	/**
	 * The handlers registered against one route.
	 *
	 * Core keeps the handlers under numeric keys alongside a `namespace` string
	 * key, so the string keys are dropped rather than mistaken for handlers.
	 *
	 * @param string $route Route, as the class constant states it.
	 * @return array<int,array>
	 */
	private function handlers_of( $route ) {
		$routes = $this->server_routes();
		$path   = $this->path( $route );

		$this->assertArrayHasKey( $path, $routes, $path . ' should be registered.' );

		$handlers = array();

		foreach ( (array) $routes[ $path ] as $key => $handler ) {
			if ( is_int( $key ) && is_array( $handler ) && isset( $handler['methods'] ) ) {
				$handlers[] = $handler;
			}
		}

		$this->assertNotEmpty( $handlers, $path . ' should hold at least one handler.' );

		return $handlers;
	}

	/**
	 * The union of the methods every handler on a route answers.
	 *
	 * @param string $route Route, as the class constant states it.
	 * @return array<string,bool> Method name to true, sorted by name.
	 */
	private function methods_of( $route ) {
		$methods = array();

		foreach ( $this->handlers_of( $route ) as $handler ) {
			foreach ( array_keys( (array) $handler['methods'] ) as $method ) {
				$methods[ (string) $method ] = true;
			}
		}

		ksort( $methods );

		return $methods;
	}

	/**
	 * The one handler on a route that answers a given method.
	 *
	 * @param string $route  Route, as the class constant states it.
	 * @param string $method HTTP method.
	 * @return array
	 */
	private function handler_for( $route, $method ) {
		$found = array();

		foreach ( $this->handlers_of( $route ) as $handler ) {
			if ( ! empty( $handler['methods'][ $method ] ) ) {
				$found[] = $handler;
			}
		}

		$this->assertCount( 1, $found, $method . ' ' . $this->path( $route ) . ' should have exactly one handler.' );

		return $found[0];
	}
}
