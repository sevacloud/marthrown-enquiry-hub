<?php
/**
 * Property 37: Access control holds for every enquiry route.
 *
 * Feature: enquiry-data-layer, Property 37: For any route registered by the
 * plugin — the quantification being over the whole registered route set, which
 * includes the manual enquiry creation route `POST /enquiries` and the enquiry
 * edit route `PATCH /enquiries/{id}` — that route is registered under the
 * `marthrown-enquiry-hub/v1` namespace and declares a sanitize callback for every
 * argument; every such route uses `Auth::rest_permission` as its permission
 * callback with exactly one exception, and that exception is the intake route,
 * whose permission callback authenticates the intake secret; for any route other
 * than the intake route, an unauthenticated request returns 401 without
 * evaluating role membership, including a request that presents the intake secret
 * and carries no WordPress user; for any authenticated user and any set of roles,
 * the response is 403 exactly when the user holds no role in
 * `Auth::allowed_roles` and lacks `manage_options`, including when the user holds
 * no role at all; for any state-changing route of the enquiry API — every
 * state-changing route except the intake route, which authenticates a shared
 * secret and no WordPress user — a request lacking a valid REST nonce is rejected
 * and stored data is unchanged; and the migration and test-record routes
 * additionally reject any user lacking `manage_options`.
 *
 * **Validates: Requirements 16.1, 16.2, 16.3, 16.4, 16.5, 16.6, 16.7, 16.9,
 * 18.21, 18.22, 19.19**
 *
 * How the property is instantiated, and why each choice was made:
 *
 * - **The route set is read off the REST server, never listed here.** Each
 *   iteration draws one of the handlers `rest_get_server()->get_routes()` holds
 *   under the plugin's namespace, so a route added to `RestEnquiries`,
 *   `RestBookings` or `IntakeEndpoint` later is quantified over without this file
 *   being touched — which is the whole point of a property rather than a table of
 *   examples. `RestRouteRegistrationTest` is the complementary example test that
 *   names the twelve enquiry registrations explicitly; this test deliberately
 *   knows none of them. The namespace index route core registers for
 *   `/marthrown-enquiry-hub/v1` itself is excluded, because it is core's route
 *   rather than one of the plugin's.
 * - **The namespace claim is checked the non-circular way round.** Filtering the
 *   route table by the namespace prefix and then asserting the results carry that
 *   prefix would establish nothing. Requirement 16.1 is instead asserted over the
 *   *whole* route table: every route whose handler callback belongs to a plugin
 *   class sits under `marthrown-enquiry-hub/v1`, so a route registered under some
 *   other namespace fails here.
 * - **Five caller kinds, and the verdict for each is decided from the
 *   requirement, not from the route.** No user at all; a user holding no role at
 *   all; a user holding a role outside `Auth::allowed_roles`; a user holding a
 *   role inside it; and an administrator. The first earns 401 (Requirement 16.3),
 *   the second and third 403 (Requirement 16.4), the last two are admitted —
 *   except on the two administrative routes, where a caller Auth admits but who
 *   lacks `manage_options` is refused 403 (Requirement 16.6), which the drawn
 *   route being administrative or not decides.
 * - **The allowed-role set is generated, not fixed.** Each iteration draws a
 *   configuration for `Auth::allowed_roles`: a set saved in the Access setting, or
 *   no saved setting at all so the defaults stand, or a set the
 *   `meh_allowed_roles` filter widens. The member and the outsider are then drawn
 *   from inside and outside whatever that configuration resolved to, and both
 *   memberships are confirmed against `Auth::allowed_roles()` before anything is
 *   dispatched, so no iteration can quietly test the wrong side of the line.
 * - **"Without evaluating role membership" is observed, not assumed.** A spy on
 *   the `meh_allowed_roles` filter counts every entry into
 *   `Auth::allowed_roles()`. The unauthenticated caller must be refused with that
 *   count still at zero (Requirement 16.3); the two forbidden callers must be
 *   refused with it above zero, which is what makes the contrast meaningful.
 * - **Refusals are dispatched; admissions are not.** A refused request runs
 *   through `rest_do_request()`, so the status asserted is the one core produced
 *   from the permission callback rather than the callback's own return value. An
 *   admitted request is judged by calling the route's registered permission
 *   callback instead, because dispatching an administrator into
 *   `POST /enquiries/migration` would run a FluentCRM migration and into
 *   `DELETE /enquiries/test-records` would delete rows — side effects that have
 *   nothing to do with access control. The admission half is not left resting on
 *   that alone: every iteration also creates an enquiry through
 *   `POST /enquiries` as the member, which is a real state-changing route
 *   answering 201 for an admitted caller.
 * - **Every dispatched request carries the args its route declares `required`.**
 *   Core validates those args before it reaches the permission callback, so a
 *   request omitting one is answered 400 and access control never runs. Leaving
 *   them out would make the property assert core's dispatch ordering rather than
 *   Requirement 16.3 — the first run drew `POST /enquiries/{id}/convert` with an
 *   empty body and was answered 400, which is that ordering and not a refusal.
 * - **Requirements 18.21 and 18.22 get their own dispatch.** `POST /enquiries`
 *   presenting the Intake Secret in both transports and carrying no WordPress
 *   user must answer 401 and store nothing. The body is a valid submission, and
 *   the same body is then submitted by the member and does create an enquiry, so
 *   "created nothing" is a refusal rather than a body that could never have been
 *   accepted.
 * - **The nonce claim reproduces the two steps `WP_REST_Server::serve_request()`
 *   takes.** `rest_do_request()` dispatches without authenticating, so the
 *   authentication filter chain is applied explicitly — with cookie
 *   authentication in force, which is the transport the nonce belongs to — and the
 *   request is then dispatched. A missing nonce leaves core acting as though the
 *   request were unauthenticated, so the route answers 401 and stored data is
 *   unchanged; a mismatched nonce is refused 403 before dispatch happens at all; a
 *   valid nonce is admitted, which is what keeps the first two from passing for
 *   the wrong reason.
 * - **"Stored data is unchanged" is a snapshot of every column of every row of
 *   all six Enquiry Store tables**, taken before the refused request and compared
 *   after it. A seeded enquiry with child rows and a seeded rejection row make
 *   that snapshot non-empty, so a stray write shows as a difference rather than as
 *   a zero matching a zero.
 * - **The case generator is wide, so it is drawn through `unshrunk()`.** Eris
 *   shrinks a composite generator by taking the cartesian product of every
 *   component's alternatives, which for a case this wide exhausts memory before a
 *   counterexample is reported. Shrinking is therefore a no-op and a failure
 *   reports the case exactly as generated, alongside the `ERIS_SEED` line that
 *   reproduces the run.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix: the WordPress test case rewrites `CREATE TABLE` into its
 * `TEMPORARY` form, and `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see a temporary table. The calling users are
 * created before that prefix switch, so they land in the real users table.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Auth;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\IntakeEndpoint;
use MarthrownEnquiryHub\RestBookings;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class AccessControlPropertyTest
 */
class AccessControlPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table, or with another test's fixture, in the same database.
	 */
	const PREFIX_SEGMENT = 'mehac_';

	/**
	 * The instant the clock is frozen at, so nothing depends on the wall clock.
	 */
	const NOW = '2025-06-10 14:20:00';

	/**
	 * The Intake Secret in force, long and distinctive so a request presenting it
	 * could not authenticate by accident.
	 */
	const SECRET = 'sk-access-control-77d3a1f9e5b04c62';

	/**
	 * Option holding the roles allowed to reach the hub.
	 *
	 * Named here rather than read from `Auth`, which exposes no constant for it,
	 * and deliberately the same string `Auth::allowed_roles()` reads.
	 */
	const ROLES_OPTION = 'meh_allowed_roles';

	/**
	 * Filter `Auth::allowed_roles()` passes its result through.
	 */
	const ROLES_FILTER = 'meh_allowed_roles';

	/**
	 * Existing WordPress roles the allowed set is drawn from.
	 *
	 * All four are roles a real site already has, and none of them holds
	 * `manage_options`, so a caller holding one is admitted or refused by the
	 * Access setting alone.
	 *
	 * @var string[]
	 */
	const ROLE_POOL = array( 'subscriber', 'contributor', 'author', 'editor' );

	/**
	 * The two hub roles `Auth::DEFAULT_ROLES` names beyond `administrator`.
	 *
	 * Registered by this test, because a stock WordPress has neither.
	 *
	 * @var string[]
	 */
	const HUB_ROLES = array( 'manager', 'operations' );

	/**
	 * The caller kinds every drawn route is tried with.
	 *
	 * @var string[]
	 */
	const CALLERS = array( 'none', 'roleless', 'outsider', 'member', 'administrator' );

	/**
	 * How `Auth::allowed_roles()` is configured for an iteration.
	 *
	 * @var string[]
	 */
	const CONFIGS = array( 'saved', 'default', 'filtered' );

	/**
	 * Error code core answers when a permission callback returns false.
	 */
	const CORE_FORBIDDEN_CODE = 'rest_forbidden';

	/**
	 * Error code core answers for a mismatched REST nonce.
	 */
	const INVALID_NONCE_CODE = 'rest_cookie_invalid_nonce';

	/**
	 * The users every caller kind is embodied by, keyed by role slug.
	 *
	 * The roleless user is keyed by the empty string.
	 *
	 * @var array<string,int>
	 */
	private static $users = array();

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * The REST server in force outside this test.
	 *
	 * @var \WP_REST_Server|null
	 */
	private $original_server = null;

	/**
	 * Times `Auth::allowed_roles()` has been entered since the counter was reset.
	 *
	 * @var int
	 */
	private $role_reads = 0;

	/**
	 * Role the `meh_allowed_roles` filter adds this iteration, if any.
	 *
	 * @var string
	 */
	private $filtered_role = '';

	/**
	 * The seeded enquiry, whose identifier stands in for `{id}` in a route path.
	 *
	 * @var int
	 */
	private $seeded_id = 0;

	/**
	 * Every plugin handler the namespace holds, as path, method and handler.
	 *
	 * @var array<int,array{path:string,method:string,handler:array}>
	 */
	private $handlers = array();

	/**
	 * Register the hub roles, create one user per caller kind, and load the
	 * classes under test.
	 *
	 * The users are created before any prefix switch, so they land in the real
	 * users table rather than in a fixture one.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-clock.php';
		require_once MEH_INCLUDES_DIR . 'class-auth.php';
		require_once MEH_INCLUDES_DIR . 'class-schema.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-query.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-store.php';
		require_once MEH_INCLUDES_DIR . 'class-history-recorder.php';
		require_once MEH_INCLUDES_DIR . 'class-note-service.php';
		require_once MEH_INCLUDES_DIR . 'class-lifecycle.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-validator.php';
		require_once MEH_INCLUDES_DIR . 'class-field-mapper.php';
		require_once MEH_INCLUDES_DIR . 'class-duplicate-detector.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-handler.php';
		require_once MEH_INCLUDES_DIR . 'class-source-wpbs.php';
		require_once MEH_INCLUDES_DIR . 'class-booking-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-bookings.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-endpoint.php';

		foreach ( self::HUB_ROLES as $role ) {
			// `Auth::DEFAULT_ROLES` names these two, and a stock WordPress has
			// neither, so the default configuration is only reachable once they
			// exist. No capabilities: hub access is the Access setting's to grant.
			add_role( $role, ucfirst( $role ), array() );
		}

		self::$users = array( '' => (int) self::factory()->user->create( array( 'role' => '' ) ) );

		foreach ( array_merge( self::ROLE_POOL, self::HUB_ROLES, array( 'administrator' ) ) as $role ) {
			self::$users[ $role ] = (int) self::factory()->user->create( array( 'role' => $role ) );
		}
	}

	/**
	 * Give the hub roles back, so they do not leak into another test class.
	 *
	 * @return void
	 */
	public static function wpTearDownAfterClass() {
		foreach ( self::HUB_ROLES as $role ) {
			remove_role( $role );
		}

		self::$users = array();
	}

	public function set_up() {
		parent::set_up();

		global $wpdb, $wp_rest_server;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		Clock::freeze( self::NOW );
		update_option( IntakeEndpoint::SECRET_OPTION, self::SECRET );

		add_filter( self::ROLES_FILTER, array( $this, 'watch_allowed_roles' ) );

		// A server of this test's own, so the route table holds what this test
		// registered and nothing a previous test left behind.
		$this->original_server = $wp_rest_server;
		$wp_rest_server        = null;

		add_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( RestBookings::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( IntakeEndpoint::class, 'register_routes' ) );

		rest_get_server();
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		remove_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );
		remove_action( 'rest_api_init', array( RestBookings::class, 'register_routes' ) );
		remove_action( 'rest_api_init', array( IntakeEndpoint::class, 'register_routes' ) );
		remove_filter( self::ROLES_FILTER, array( $this, 'watch_allowed_roles' ) );

		$wp_rest_server        = $this->original_server;
		$this->original_server = null;

		delete_option( IntakeEndpoint::SECRET_OPTION );
		delete_option( self::ROLES_OPTION );

		wp_set_current_user( 0 );
		Clock::unfreeze();

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 37: Access control holds for every
	 * enquiry route.
	 *
	 * **Validates: Requirements 16.1, 16.2, 16.3, 16.4, 16.5, 16.6, 16.7, 16.9,
	 * 18.21, 18.22, 19.19**
	 */
	public function test_access_control_holds_for_every_enquiry_route() {
		$this->limitTo( Iterations::count( 100 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $case ) {
					$this->check( $case );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * The claim
	 * ------------------------------------------------------------------ */

	/**
	 * One configuration, one drawn route, and every caller kind against it.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check( array $case ) {
		$this->reseed();

		$roles = $this->configure( $case );
		$route = $this->drawn_route( (int) $case['route'] );
		$label = self::label( $roles, $route );

		$this->assert_namespace_holds_every_plugin_route( $label );
		$this->assert_permission_callbacks( $label );
		$this->assert_args_are_sanitized( $label );

		foreach ( self::CALLERS as $caller ) {
			$this->assert_caller( $caller, $route, $roles, $label );
		}

		$this->assert_secret_alone_creates_nothing( $case, $label );
		$this->assert_member_can_create( $case, $roles, $label );
		$this->assert_nonce_is_required( $route, $label );
		$this->assert_intake_is_the_exception( $roles, $label );
	}

	/**
	 * Requirement 16.1: every route whose handler belongs to the plugin sits under
	 * `marthrown-enquiry-hub/v1`.
	 *
	 * Asserted over the whole route table rather than over the namespace's own
	 * slice, so a plugin route registered under some other namespace fails here
	 * instead of being filtered out of sight.
	 *
	 * @param string $label Failure context.
	 * @return void
	 */
	private function assert_namespace_holds_every_plugin_route( $label ) {
		$prefix = '/' . RestEnquiries::NAMESPACE;
		$found  = 0;

		foreach ( (array) rest_get_server()->get_routes() as $path => $handlers ) {
			foreach ( self::handlers_in( $handlers ) as $handler ) {
				if ( ! self::is_plugin_callback( $handler['callback'] ) ) {
					continue;
				}

				++$found;

				$this->assertStringStartsWith(
					$prefix . '/',
					(string) $path,
					'Every plugin route belongs to the namespace. ' . $label
				);
			}
		}

		$this->assertGreaterThan( 0, $found, 'The sweep should have found plugin routes. ' . $label );
	}

	/**
	 * Requirements 16.2 and 16.9: every route in the namespace is gated by
	 * `Auth::rest_permission`, either directly or through the administrative
	 * composition, with exactly one exception — the intake route, which
	 * authenticates the Intake Secret.
	 *
	 * @param string $label Failure context.
	 * @return void
	 */
	private function assert_permission_callbacks( $label ) {
		$auth       = array( Auth::class, 'rest_permission' );
		$admin      = array( RestEnquiries::class, 'admin_permission' );
		$secret     = array( IntakeEndpoint::class, 'authenticate' );
		$exceptions = array();
		$admins     = array();

		foreach ( $this->namespace_handlers() as $entry ) {
			$callback = $entry['handler']['permission_callback'];
			$where    = $entry['method'] . ' ' . $entry['path'] . '. ' . $label;

			$this->assertNotEmpty( $callback, 'Every route declares a permission callback. ' . $where );

			if ( $secret === $callback ) {
				$exceptions[] = $entry['path'];
				continue;
			}

			if ( $admin === $callback ) {
				$admins[] = $entry['path'];
				continue;
			}

			$this->assertSame( $auth, $callback, 'A route Auth does not gate. ' . $where );
		}

		$this->assertSame(
			array( '/' . IntakeEndpoint::NAMESPACE . IntakeEndpoint::ROUTE ),
			array_values( array_unique( $exceptions ) ),
			'The intake route is the one exception. ' . $label
		);

		sort( $admins );

		$this->assertSame(
			array(
				'/' . RestEnquiries::NAMESPACE . RestEnquiries::ROUTE_MIGRATION,
				'/' . RestEnquiries::NAMESPACE . RestEnquiries::ROUTE_TEST_RECORDS,
			),
			$admins,
			'Only the two administrative routes ask for more than Auth. ' . $label
		);
	}

	/**
	 * Requirement 16.7: every declared arg of every route in the namespace carries
	 * a `sanitize_callback`, and one that can actually be called.
	 *
	 * @param string $label Failure context.
	 * @return void
	 */
	private function assert_args_are_sanitized( $label ) {
		$checked = 0;

		foreach ( $this->namespace_handlers() as $entry ) {
			foreach ( (array) $entry['handler']['args'] as $name => $arg ) {
				$where = $entry['method'] . ' ' . $entry['path'] . ' arg `' . $name . '`. ' . $label;

				$this->assertArrayHasKey( 'sanitize_callback', (array) $arg, 'Declared without sanitisation. ' . $where );
				$this->assertNotEmpty( $arg['sanitize_callback'], 'Declared without sanitisation. ' . $where );
				$this->assertIsCallable( $arg['sanitize_callback'], 'Sanitisation that cannot be called. ' . $where );

				++$checked;
			}
		}

		$this->assertGreaterThan( 0, $checked, 'The sweep should have found declared args. ' . $label );
	}

	/**
	 * One caller kind against one route.
	 *
	 * A refused caller is dispatched, so the status asserted is core's own answer
	 * to the permission callback, and the store is compared before and after. An
	 * admitted caller is judged by the route's registered permission callback
	 * instead, because dispatching an administrator into the migration or
	 * test-record route would do administrative work this property is not about.
	 *
	 * @param string $caller One of self::CALLERS.
	 * @param array  $route  Drawn route entry.
	 * @param array  $roles  Resolved role configuration.
	 * @param string $label  Failure context.
	 * @return void
	 */
	private function assert_caller( $caller, array $route, array $roles, $label ) {
		$expected = self::expected_status( $caller, $route['administrative'] );
		$where    = $caller . ' on ' . $route['method'] . ' ' . $route['path'] . '. ' . $label;

		wp_set_current_user( $this->user_for( $caller, $roles ) );

		$this->role_reads = 0;

		if ( 0 === $expected ) {
			$this->assertTrue(
				true === call_user_func( $route['handler']['permission_callback'], $this->request_for( $route ) ),
				'An admitted caller is admitted. ' . $where
			);

			return;
		}

		$before   = $this->snapshot();
		$response = rest_do_request( $this->request_for( $route ) );
		$data     = (array) $response->get_data();

		// Requirements 16.3, 16.4, 16.6: the refusal, and its status.
		$this->assertSame( $expected, $response->get_status(), 'The refusal status. ' . $where );
		$this->assertTrue( $response->is_error(), 'A refusal is an error. ' . $where );
		$this->assertSame(
			self::expected_code( $caller, $route['administrative'] ),
			isset( $data['code'] ) ? (string) $data['code'] : '',
			'The refusal code. ' . $where
		);

		// Requirement 16.3: an unauthenticated request is refused without role
		// membership being looked at; the two forbidden callers are refused after
		// it was, which is what makes the distinction observable rather than
		// asserted of nothing.
		if ( 'none' === $caller ) {
			$this->assertSame( 0, $this->role_reads, 'No role membership is evaluated. ' . $where );
		} else {
			$this->assertGreaterThan( 0, $this->role_reads, 'Role membership is evaluated. ' . $where );
		}

		$this->assertSame( $before, $this->snapshot(), 'A refused request stores nothing. ' . $where );
	}

	/**
	 * Requirements 18.21 and 18.22: a manual creation request presenting the
	 * Intake Secret and carrying no WordPress user is refused 401 and creates
	 * nothing.
	 *
	 * The secret is presented on both transports at once, which is the strongest
	 * form of the request: neither the header nor the query parameter may stand in
	 * for a WordPress user on this route.
	 *
	 * @param array  $case  Generated case.
	 * @param string $label Failure context.
	 * @return void
	 */
	private function assert_secret_alone_creates_nothing( array $case, $label ) {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/' . RestEnquiries::NAMESPACE . RestEnquiries::ROUTE_COLLECTION );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( (array) $case['submission'] ) );
		$request->set_header( IntakeEndpoint::SECRET_HEADER, self::SECRET );
		$request->set_query_params( array( IntakeEndpoint::SECRET_QUERY => self::SECRET ) );

		$before   = $this->snapshot();
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status(), 'The Intake Secret authenticates no manual creation. ' . $label );
		$this->assertSame( $before, $this->snapshot(), 'That refusal creates no enquiry. ' . $label );
	}

	/**
	 * The admitted half, on a state-changing route: the same submission the
	 * secret-only request was refused for does create an enquiry when the member
	 * makes it.
	 *
	 * Without this the "creates nothing" claim above could be satisfied by a body
	 * no caller could ever have got accepted.
	 *
	 * @param array  $case  Generated case.
	 * @param array  $roles Resolved role configuration.
	 * @param string $label Failure context.
	 * @return void
	 */
	private function assert_member_can_create( array $case, array $roles, $label ) {
		wp_set_current_user( self::$users[ $roles['member'] ] );

		$request = new WP_REST_Request( 'POST', '/' . RestEnquiries::NAMESPACE . RestEnquiries::ROUTE_COLLECTION );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( (array) $case['submission'] ) );

		$before   = $this->row_count( 'enquiries' );
		$response = rest_do_request( $request );
		$data     = (array) $response->get_data();

		$this->assertSame(
			201,
			$response->get_status(),
			'An admitted member creates an enquiry: ' . wp_json_encode( $data ) . ' ' . $label
		);
		$this->assertSame( $before + 1, $this->row_count( 'enquiries' ), 'Exactly one enquiry was created. ' . $label );
	}

	/**
	 * Requirement 16.5: a state-changing request lacking a valid REST nonce is
	 * rejected, and stored data is unchanged.
	 *
	 * `rest_do_request()` dispatches without authenticating, so the two steps
	 * `WP_REST_Server::serve_request()` takes are reproduced here: the
	 * authentication filter chain is applied with cookie authentication in force,
	 * then the request is dispatched. Three nonce shapes are checked — absent,
	 * mismatched and valid — because the first two only mean something alongside
	 * the third.
	 *
	 * The intake route is exempt by the requirement's own terms: it authenticates
	 * a shared secret and no WordPress user, so there is no cookie session for a
	 * nonce to accompany.
	 *
	 * @param array  $route Drawn route entry.
	 * @param string $label Failure context.
	 * @return void
	 */
	private function assert_nonce_is_required( array $route, $label ) {
		if ( ! $route['state_changing'] || $route['intake'] ) {
			return;
		}

		$administrator = self::$users['administrator'];
		$where         = $route['method'] . ' ' . $route['path'] . '. ' . $label;

		// A valid nonce is admitted, so the two refusals below cannot pass by
		// refusing everything.
		wp_set_current_user( $administrator );

		$this->assertTrue(
			true === $this->authenticate( wp_create_nonce( 'wp_rest' ) ),
			'A valid nonce authenticates. ' . $where
		);
		$this->assertSame( $administrator, get_current_user_id(), 'A valid nonce keeps the user. ' . $where );

		// A mismatched nonce is refused before the route is reached at all.
		wp_set_current_user( $administrator );

		$mismatched = $this->authenticate( 'not-the-nonce' );

		$this->assertInstanceOf( 'WP_Error', $mismatched, 'A mismatched nonce is refused. ' . $where );
		$this->assertSame( self::INVALID_NONCE_CODE, $mismatched->get_error_code(), 'The refusal code. ' . $where );
		$this->assertSame( 403, (int) $mismatched->get_error_data()['status'], 'The refusal status. ' . $where );

		// No nonce at all: core drops the user, so the route refuses the request
		// and nothing is stored.
		wp_set_current_user( $administrator );

		$before = $this->snapshot();

		$this->assertTrue( true === $this->authenticate( null ), 'A missing nonce is treated as no user. ' . $where );
		$this->assertSame( 0, get_current_user_id(), 'A missing nonce drops the user. ' . $where );

		$response = rest_do_request( $this->request_for( $route ) );

		$this->assertSame( 401, $response->get_status(), 'A missing nonce is refused. ' . $where );
		$this->assertSame( $before, $this->snapshot(), 'That refusal stores nothing. ' . $where );

		wp_set_current_user( 0 );
	}

	/**
	 * Requirement 16.9: the intake route is the exception because its permission
	 * callback authenticates the Intake Secret rather than a WordPress user.
	 *
	 * Two facts establish that, and between them they show the callback consults
	 * the secret and nothing else: a request carrying the secret and no user at all
	 * is admitted, and a request carrying an admitted hub member but no secret is
	 * refused 401.
	 *
	 * @param array  $roles Resolved role configuration.
	 * @param string $label Failure context.
	 * @return void
	 */
	private function assert_intake_is_the_exception( array $roles, $label ) {
		$path     = '/' . IntakeEndpoint::NAMESPACE . IntakeEndpoint::ROUTE;
		$callback = $this->handler_at( $path, 'POST' )['permission_callback'];

		wp_set_current_user( 0 );

		$with_secret = new WP_REST_Request( 'POST', $path );
		$with_secret->set_header( IntakeEndpoint::SECRET_HEADER, self::SECRET );

		$this->assertTrue(
			true === call_user_func( $callback, $with_secret ),
			'The intake route admits a secret carrying no WordPress user. ' . $label
		);

		wp_set_current_user( self::$users[ $roles['member'] ] );

		$refused = call_user_func( $callback, new WP_REST_Request( 'POST', $path ) );

		$this->assertInstanceOf( 'WP_Error', $refused, 'The intake route authenticates the secret, not the user. ' . $label );
		$this->assertSame( 401, (int) $refused->get_error_data()['status'], 'The intake refusal status. ' . $label );

		wp_set_current_user( 0 );
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: how `Auth::allowed_roles` is configured, which role the admitted
	 * member and the refused outsider hold, which route is drawn, and the
	 * submission the manual creation claims use.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				// A bitmask over ROLE_POOL, never empty and never all four, so
				// there is always a role inside the allowed set and one outside it.
				'allowed'    => \Eris\Generators::choose( 1, ( 1 << count( self::ROLE_POOL ) ) - 2 ),
				'config'     => \Eris\Generators::elements( self::CONFIGS ),
				'list_admin' => \Eris\Generators::elements( array( true, false ) ),
				'member'     => \Eris\Generators::choose( 0, 99 ),
				'outsider'   => \Eris\Generators::choose( 0, 99 ),
				'route'      => \Eris\Generators::choose( 0, 999 ),
				'submission' => Generators::enquiry(),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a composite generator by taking the cartesian product of every
	 * component's alternatives, so the cost of one shrink step grows exponentially
	 * with the number of drawn values. A case here draws six choices and a
	 * nine-field enquiry, two of whose fields are sets, which puts that product
	 * beyond what fits in memory: a failing iteration would report an
	 * out-of-memory fatal instead of the counterexample.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the case as generated, together with the assertion's own
	 * message and the `ERIS_SEED` line that reproduces the run exactly.
	 *
	 * @param \Eris\Generator $generator Generator to draw from.
	 * @return \Eris\Generator
	 */
	protected static function unshrunk( \Eris\Generator $generator ) {
		return \Eris\Generators::bind(
			$generator,
			static function ( $drawn ) {
				return \Eris\Generators::constant( $drawn );
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * The case, resolved
	 * ------------------------------------------------------------------ */

	/**
	 * Put the drawn role configuration in force, and name the member and the
	 * outsider it implies.
	 *
	 * Both memberships are then confirmed against `Auth::allowed_roles()` itself,
	 * so an iteration cannot quietly place its "member" outside the allowed set or
	 * its "outsider" inside it and assert the wrong half of Requirement 16.4.
	 *
	 * @param array $case Generated case.
	 * @return array{config:string,member:string,outsider:string,allowed:string[]}
	 */
	private function configure( array $case ) {
		$config = (string) $case['config'];
		$saved  = self::role_subset( (int) $case['allowed'] );
		$rest   = array_values( array_diff( self::ROLE_POOL, $saved ) );

		$this->filtered_role = '';

		if ( 'default' === $config ) {
			// Nothing saved, so `Auth::DEFAULT_ROLES` stands.
			delete_option( self::ROLES_OPTION );

			$members   = self::HUB_ROLES;
			$outsiders = self::ROLE_POOL;
		} else {
			$option = ! empty( $case['list_admin'] ) ? array_merge( $saved, array( 'administrator' ) ) : $saved;

			update_option( self::ROLES_OPTION, $option );

			if ( 'filtered' === $config ) {
				// The member's role is absent from the saved set and added by the
				// `meh_allowed_roles` filter, so the filter is part of what the
				// property quantifies over rather than an untested extension point.
				$this->filtered_role = $rest[ (int) $case['member'] % count( $rest ) ];

				$members   = array( $this->filtered_role );
				$outsiders = array_merge( array_diff( $rest, array( $this->filtered_role ) ), self::HUB_ROLES );
			} else {
				$members   = $saved;
				$outsiders = array_merge( $rest, self::HUB_ROLES );
			}
		}

		$roles = array(
			'config'   => $config,
			'member'   => $members[ (int) $case['member'] % count( $members ) ],
			'outsider' => $outsiders[ (int) $case['outsider'] % count( $outsiders ) ],
		);

		$allowed = Auth::allowed_roles();

		$this->assertContains( $roles['member'], $allowed, 'The member should hold an allowed role.' );
		$this->assertNotContains( $roles['outsider'], $allowed, 'The outsider should hold no allowed role.' );
		$this->assertContains( 'administrator', $allowed, 'Administrators are always allowed.' );

		$roles['allowed'] = array_values( $allowed );

		return $roles;
	}

	/**
	 * The roles a bitmask selects out of ROLE_POOL.
	 *
	 * @param int $mask Bitmask, 1 to 14.
	 * @return string[] At least one role, never all four.
	 */
	private static function role_subset( $mask ) {
		$roles = array();

		foreach ( self::ROLE_POOL as $index => $role ) {
			if ( (int) $mask & ( 1 << $index ) ) {
				$roles[] = $role;
			}
		}

		return $roles;
	}

	/**
	 * The route one drawn index names, out of every gated route the namespace
	 * holds.
	 *
	 * The identifier pattern is replaced with the seeded enquiry's identifier, so
	 * a refusal is a refusal of a request that would otherwise have resolved
	 * something — a 404 dressed up as access control would not do.
	 *
	 * @param int $index Drawn index, taken modulo the route count.
	 * @return array{path:string,method:string,handler:array,administrative:bool,state_changing:bool,intake:bool}
	 */
	private function drawn_route( $index ) {
		$gated = array();

		foreach ( $this->namespace_handlers() as $entry ) {
			if ( self::intake_path() !== $entry['path'] ) {
				$gated[] = $entry;
			}
		}

		$this->assertNotEmpty( $gated, 'The namespace should hold gated routes.' );

		$entry  = $gated[ abs( (int) $index ) % count( $gated ) ];
		$method = (string) $entry['method'];

		return array(
			'path'           => (string) preg_replace( '/\(\?P<[^>]+>[^)]*\)/', (string) $this->seeded_id, $entry['path'] ),
			'method'         => $method,
			'handler'        => $entry['handler'],
			'administrative' => array( RestEnquiries::class, 'admin_permission' ) === $entry['handler']['permission_callback'],
			'state_changing' => ! in_array( $method, array( 'GET', 'HEAD' ), true ),
			'intake'         => false,
		);
	}

	/**
	 * A request against one resolved route, carrying every arg that route declares
	 * `required`.
	 *
	 * Supplying them is what makes the refusal an access-control refusal. Core
	 * validates declared required args in `WP_REST_Server::dispatch()` *before*
	 * `respond_to_request()` reaches the permission callback, so a request omitting
	 * one is answered 400 by core and access control is never consulted: the first
	 * run of this property drew `POST /enquiries/{id}/convert` with no user and no
	 * body and was answered 400 rather than 401, which said nothing about
	 * Requirement 16.3 and everything about `calendar_id` and `date` being
	 * `required`. The values are placeholders — for a refused caller the route
	 * callback never runs, so nothing reads them.
	 *
	 * @param array $route Drawn route entry.
	 * @return \WP_REST_Request
	 */
	private function request_for( array $route ) {
		$request = new WP_REST_Request( (string) $route['method'], (string) $route['path'] );

		foreach ( (array) $route['handler']['args'] as $name => $arg ) {
			// `id` is carried by the path, and core fills it from the route match.
			if ( empty( $arg['required'] ) || 'id' === $name ) {
				continue;
			}

			$request->set_param( (string) $name, self::placeholder_for( (string) $name, (array) $arg ) );
		}

		return $request;
	}

	/**
	 * A value of the declared type for one required arg.
	 *
	 * Typed rather than constant, because core's own type validation runs at the
	 * same point as the required check: an integer arg given `x` would be answered
	 * 400 for the type instead of 401 for the access control.
	 *
	 * @param string $name Arg name.
	 * @param array  $arg  Declared arg.
	 * @return mixed
	 */
	private static function placeholder_for( $name, array $arg ) {
		$type = isset( $arg['type'] ) ? $arg['type'] : 'string';

		if ( 'integer' === $type || 'number' === $type ) {
			return 1;
		}

		if ( 'boolean' === $type ) {
			return false;
		}

		return false === strpos( $name, 'date' ) ? 'placeholder' : '2026-05-01';
	}

	/**
	 * The user identifier one caller kind is made of.
	 *
	 * @param string $caller One of self::CALLERS.
	 * @param array  $roles  Resolved role configuration.
	 * @return int Zero for the unauthenticated caller.
	 */
	private function user_for( $caller, array $roles ) {
		switch ( $caller ) {
			case 'none':
				return 0;

			case 'roleless':
				return self::$users[''];

			case 'outsider':
				return self::$users[ $roles['outsider'] ];

			case 'member':
				return self::$users[ $roles['member'] ];

			default:
				return self::$users['administrator'];
		}
	}

	/**
	 * The status a caller kind earns on a route, or 0 when it is admitted.
	 *
	 * Written out from the requirements rather than read off the route: 401 for no
	 * user (16.3), 403 for a user Auth does not authorize (16.4), and 403 on the
	 * two administrative routes for a user Auth does authorize but who lacks
	 * `manage_options` (16.6).
	 *
	 * @param string $caller         One of self::CALLERS.
	 * @param bool   $administrative Whether the route is one of the two.
	 * @return int
	 */
	private static function expected_status( $caller, $administrative ) {
		if ( 'none' === $caller ) {
			return 401;
		}

		if ( 'roleless' === $caller || 'outsider' === $caller ) {
			return 403;
		}

		return ( 'member' === $caller && $administrative ) ? 403 : 0;
	}

	/**
	 * The error code a refusal carries.
	 *
	 * The administrative refusal is the plugin's own, which is what distinguishes
	 * Requirement 16.6's refusal from Auth's: a member reaching the migration route
	 * cleared Auth and was turned away for the capability.
	 *
	 * @param string $caller         One of self::CALLERS.
	 * @param bool   $administrative Whether the route is one of the two.
	 * @return string
	 */
	private static function expected_code( $caller, $administrative ) {
		return ( 'member' === $caller && $administrative )
			? RestEnquiries::FORBIDDEN_CODE
			: self::CORE_FORBIDDEN_CODE;
	}

	/**
	 * A one-line description of the case, for failure messages.
	 *
	 * @param array $roles Resolved role configuration.
	 * @param array $route Drawn route entry.
	 * @return string
	 */
	private static function label( array $roles, array $route ) {
		return sprintf(
			'[%s roles, member %s, outsider %s, allowed %s, route %s %s]',
			(string) $roles['config'],
			(string) $roles['member'],
			(string) $roles['outsider'],
			implode( '+', (array) $roles['allowed'] ),
			(string) $route['method'],
			(string) $route['path']
		);
	}

	/* ---------------------------------------------------------------------
	 * The route table
	 * ------------------------------------------------------------------ */

	/**
	 * Every handler the plugin registered in its namespace, read off the server.
	 *
	 * Cached for the life of the test case, because the server is built once in
	 * `set_up()` and the route table cannot change afterwards.
	 *
	 * @return array<int,array{path:string,method:string,handler:array}>
	 */
	private function namespace_handlers() {
		if ( array() !== $this->handlers ) {
			return $this->handlers;
		}

		// The trailing slash excludes the namespace index route core registers for
		// `/marthrown-enquiry-hub/v1` itself, which is core's rather than the
		// plugin's.
		$prefix = '/' . RestEnquiries::NAMESPACE . '/';
		$found  = array();

		foreach ( (array) rest_get_server()->get_routes() as $path => $handlers ) {
			if ( 0 !== strpos( (string) $path, $prefix ) ) {
				continue;
			}

			foreach ( self::handlers_in( $handlers ) as $handler ) {
				$methods = array_keys( array_filter( (array) $handler['methods'] ) );

				$this->assertNotEmpty( $methods, $path . ' should answer a method.' );

				$found[] = array(
					'path'    => (string) $path,
					'method'  => (string) $methods[0],
					'handler' => $handler,
				);
			}
		}

		$this->assertNotEmpty( $found, 'The namespace should hold the plugin routes.' );

		$this->handlers = $found;

		return $this->handlers;
	}

	/**
	 * The handlers inside one route's entry.
	 *
	 * Core keeps them under numeric keys alongside a `namespace` string key, so
	 * the string keys are dropped rather than mistaken for handlers.
	 *
	 * @param mixed $handlers Route entry as the server holds it.
	 * @return array<int,array>
	 */
	private static function handlers_in( $handlers ) {
		$found = array();

		foreach ( (array) $handlers as $key => $handler ) {
			if ( is_int( $key ) && is_array( $handler ) && isset( $handler['methods'] ) ) {
				$found[] = $handler;
			}
		}

		return $found;
	}

	/**
	 * The one handler on a path answering a method.
	 *
	 * @param string $path   Registered path.
	 * @param string $method HTTP method.
	 * @return array
	 */
	private function handler_at( $path, $method ) {
		foreach ( $this->namespace_handlers() as $entry ) {
			if ( $entry['path'] === $path && ! empty( $entry['handler']['methods'][ $method ] ) ) {
				return $entry['handler'];
			}
		}

		$this->fail( $method . ' ' . $path . ' should be registered.' );
	}

	/**
	 * Whether a route callback belongs to the plugin.
	 *
	 * @param mixed $callback Registered callback.
	 * @return bool
	 */
	private static function is_plugin_callback( $callback ) {
		if ( ! is_array( $callback ) || ! isset( $callback[0] ) ) {
			return false;
		}

		$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

		return 0 === strpos( $class, 'MarthrownEnquiryHub' );
	}

	/**
	 * The registered path of the intake route.
	 *
	 * @return string
	 */
	private static function intake_path() {
		return '/' . IntakeEndpoint::NAMESPACE . IntakeEndpoint::ROUTE;
	}

	/* ---------------------------------------------------------------------
	 * Authentication
	 * ------------------------------------------------------------------ */

	/**
	 * Run core's REST authentication filter chain with cookie authentication in
	 * force and one nonce shape presented.
	 *
	 * This is the step `WP_REST_Server::serve_request()` takes before it
	 * dispatches, and the step `rest_do_request()` skips. Cookie authentication is
	 * declared in force through the global core's own `rest_cookie_collect_status()`
	 * sets, because the nonce belongs to that transport: without it core would
	 * conclude some other authentication had been used and leave the nonce alone.
	 *
	 * Everything it touches is put back, so one iteration cannot leave a nonce or a
	 * header filter behind for the next.
	 *
	 * @param string|null $nonce Nonce to present, or null to present none.
	 * @return true|\WP_Error Core's verdict.
	 */
	private function authenticate( $nonce ) {
		global $wp_rest_auth_cookie;

		$previous_cookie = $wp_rest_auth_cookie;
		$previous_nonce  = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? $_SERVER['HTTP_X_WP_NONCE'] : null;

		$wp_rest_auth_cookie = true;

		unset( $_REQUEST['_wpnonce'] );

		if ( null === $nonce ) {
			unset( $_SERVER['HTTP_X_WP_NONCE'] );
		} else {
			$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
		}

		$verdict = apply_filters( 'rest_authentication_errors', null );

		// Core adds this on the mismatched-nonce path; it is nothing to do with
		// the next iteration.
		remove_filter( 'rest_send_nocache_headers', '__return_true', 20 );

		if ( null === $previous_nonce ) {
			unset( $_SERVER['HTTP_X_WP_NONCE'] );
		} else {
			$_SERVER['HTTP_X_WP_NONCE'] = $previous_nonce;
		}

		$wp_rest_auth_cookie = $previous_cookie;

		return $verdict;
	}

	/**
	 * Count one entry into `Auth::allowed_roles()`, and widen the set when the
	 * drawn configuration asks the filter to.
	 *
	 * The counter is what makes "without evaluating role membership" observable:
	 * this filter is the last thing `Auth::allowed_roles()` does, and nothing else
	 * calls it.
	 *
	 * @param mixed $roles Roles Auth resolved.
	 * @return array
	 */
	public function watch_allowed_roles( $roles ) {
		++$this->role_reads;

		$roles = (array) $roles;

		if ( '' !== $this->filtered_role && ! in_array( $this->filtered_role, $roles, true ) ) {
			$roles[] = $this->filtered_role;
		}

		return $roles;
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Empty the tables and write the fixture one iteration works against: an
	 * enquiry with candidate dates, multi-select values, a payload snapshot and a
	 * note, plus a rejected intake attempt.
	 *
	 * Non-empty on purpose. "Stored data is unchanged" compared against empty
	 * tables would be a zero matching a zero.
	 *
	 * @return void
	 */
	private function reseed() {
		$this->clear();

		$now = Clock::mysql();

		$id = EnquiryStore::create(
			array(
				'first_name'        => 'Ada',
				'last_name'         => 'Lovelace',
				'email'             => 'seeded@example.com',
				'phone'             => '01142 000000',
				'message'           => 'Fixture enquiry.',
				'status'            => 'new',
				'created_at'        => $now,
				'updated_at'        => $now,
				'status_changed_at' => $now,
				'source'            => 'webhook:fixture',
			),
			array( Generators::date_at( 30 ), Generators::date_at( 45 ) ),
			array(
				'event_type'       => array( 'wedding' ),
				'site_exclusivity' => array( 'exclusive-use' ),
			),
			array( 'seeded' => true )
		);

		$this->assertIsInt( $id, 'Seeding an enquiry should succeed.' );

		$this->seeded_id = (int) $id;

		$this->assertIsInt(
			\MarthrownEnquiryHub\NoteService::add( $this->seeded_id, 'Fixture note.', 0 ),
			'Seeding a note should succeed.'
		);

		$this->assertGreaterThan(
			0,
			EnquiryStore::record_rejection( array( 'seeded' => true ), 'validation' ),
			'Seeding a rejected intake attempt should succeed.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Every column of every row of every Enquiry Store table, keyed by table.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function snapshot() {
		global $wpdb;

		$snapshot = array();

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );

			$wpdb->last_error = '';

			// phpcs:ignore WordPress.DB
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

			$this->assertSame( '', (string) $wpdb->last_error, 'The snapshot read should run without error.' );

			$snapshot[ $key ] = is_array( $rows ) ? $rows : array();
		}

		return $snapshot;
	}

	/**
	 * The row count of one Enquiry Store table.
	 *
	 * @param string $key Table key.
	 * @return int
	 */
	private function row_count( $key ) {
		global $wpdb;

		$table = Schema::table( $key );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Empty every Enquiry Store table, between iterations.
	 *
	 * `DELETE` rather than `TRUNCATE`: the identifiers keep climbing, which is
	 * closer to a live table and means a stale identifier can never be mistaken
	 * for a fresh one.
	 *
	 * @return void
	 */
	private function clear() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Drop every Enquiry Store table at the test prefix.
	 *
	 * @return void
	 */
	private function drop_tables() {
		global $wpdb;

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table = Schema::table( $key );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		}
	}
}
