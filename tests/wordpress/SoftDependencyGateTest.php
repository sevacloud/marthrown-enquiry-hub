<?php
/**
 * The soft FluentCRM dependency gate, asserted end to end (Requirements 5.2,
 * 6.7, 16.8).
 *
 * `meh_bootstrap()` used to return early when FluentCRM Pro was absent, so
 * nothing at all loaded. That cannot stand alongside Requirement 5, which says
 * an enquiry is captured even when contact linkage fails, or Requirement 6.7,
 * which makes an inactive FluentCRM a linkage failure rather than a lost
 * enquiry: neither holds if the intake handler never loads. So the gate is soft,
 * and this test is what says so from the outside.
 *
 * Three things are established, in order:
 *
 * - **The bootstrap registers the enquiry layer's hooks with the dependency
 *   absent.** The hooks the bootstrap adds are *removed* in `set_up()` and
 *   asserted absent before `meh_bootstrap()` is called, so their presence
 *   afterwards is something this test watched happen rather than something the
 *   test bootstrap's own `plugins_loaded` left lying around. The schema boot is
 *   asserted the same way — by the recorded version — and the missing-dependency
 *   admin notice is asserted to be the *only* consequence of the absence.
 * - **The intake route reaches the route table.** Registration is read back off
 *   a REST server of this test's own, after `rest_api_init` has fired, so what
 *   is asserted is what WordPress ended up holding: the path, the method, and
 *   the Intake Secret permission callback that lets a request carrying no
 *   WordPress user through (Requirements 16.8, 16.9).
 * - **An authenticated webhook still creates an enquiry.** The route is
 *   dispatched with the correct secret and no logged-in user, and the enquiry is
 *   read back out of the store: created, at status `new`, with
 *   `crm_sync_state` at `pending` and an empty `fluentcrm_subscriber_id`
 *   (Requirements 5.2, 6.7), and with no contact written to FluentCRM at all.
 *
 * FluentCRM absence is arranged the way the CRM properties arrange it: the
 * shared fake is installed in its unavailable mode, so `FluentCrmApi()` resolves
 * to nothing exactly as an inactive plugin makes it. The Pro marker constants are
 * asserted absent rather than assumed, and the test skips rather than lies if the
 * run happens inside a site that genuinely has FluentCRM Pro active.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the temporary tables the WordPress test case
 * rewrites `CREATE TABLE` into.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\AdminPage;
use MarthrownEnquiryHub\AutoCloseJob;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\ContactLinker;
use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\FieldMapper;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\IntakeEndpoint;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Settings;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;

/**
 * Class SoftDependencyGateTest
 */
class SoftDependencyGateTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table or with another test's fixture in the same database.
	 */
	const PREFIX_SEGMENT = 'mehsd_';

	/**
	 * The instant the webhook is received at.
	 */
	const AT = '2025-08-11 09:15:00';

	/**
	 * The Intake Secret held in Settings for the length of this test.
	 */
	const SECRET = 'Rk3vT8pQ2zLm6XbW9dHn';

	/**
	 * The payload field naming the sending form, as configured in Settings.
	 */
	const SOURCE_FIELD = 'form_id';

	/**
	 * The form identifier the webhook body carries.
	 */
	const FORM_ID = 'enquiry-form';

	/**
	 * The email address the webhook submits.
	 */
	const EMAIL = 'ada@example.com';

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
	 * The installed CRM fake, in its unavailable mode.
	 *
	 * @var FakeCrm|null
	 */
	private $crm = null;

	/**
	 * Load the classes the bootstrap boots and this test reads.
	 *
	 * `meh_bootstrap()` requires every include itself, so this is about the
	 * `use` statements above resolving before the bootstrap is reached rather
	 * than about the bootstrap needing help.
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
		require_once MEH_INCLUDES_DIR . 'class-enquiry-validator.php';
		require_once MEH_INCLUDES_DIR . 'class-field-mapper.php';
		require_once MEH_INCLUDES_DIR . 'class-duplicate-detector.php';
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-handler.php';
		require_once MEH_INCLUDES_DIR . 'class-intake-endpoint.php';
		require_once MEH_INCLUDES_DIR . 'class-auto-close-job.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';
		require_once MEH_INCLUDES_DIR . 'class-settings.php';
		require_once MEH_INCLUDES_DIR . 'class-admin-page.php';
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

		Clock::freeze( self::AT );

		update_option( IntakeEndpoint::SECRET_OPTION, self::SECRET );
		update_option( IntakeEndpoint::SOURCE_FIELD_OPTION, self::SOURCE_FIELD );
		delete_option( FieldMapper::OPTION );

		// Requirement 6.7: FluentCrmApi() resolves to nothing, as an inactive
		// FluentCRM makes it.
		$this->crm = FakeCrm::install()->will_be_unavailable();

		// Requirement 16.8: a webhook carries no WordPress user.
		wp_set_current_user( 0 );

		// A server of this test's own, so the route table holds what this test
		// watched get registered.
		$this->original_server = $wp_rest_server;
		$wp_rest_server        = new WP_REST_Server();

		// The bootstrap already ran on `plugins_loaded` when the test suite
		// booted the plugin, so its hooks are taken back off before anything is
		// asserted about them. The test case restores the whole hook table in
		// tear_down, so nothing here leaks into another test.
		$this->unregister_bootstrap_hooks();
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		FakeCrm::uninstall();
		$this->crm = null;

		DuplicateDetector::reset( self::EMAIL );

		delete_option( IntakeEndpoint::SECRET_OPTION );
		delete_option( IntakeEndpoint::SOURCE_FIELD_OPTION );

		$wp_rest_server        = $this->original_server;
		$this->original_server = null;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * The gate
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 16.8: the bootstrap loads and wires the enquiry layer with
	 * FluentCRM absent, and the absence costs nothing but the admin notice.
	 *
	 * @return void
	 */
	public function test_the_bootstrap_registers_the_enquiry_layer_with_fluentcrm_absent() {
		$this->assertDependencyAbsent();

		// The bootstrap itself is hooked unconditionally, at file scope, so an
		// absent dependency cannot stop it being reached.
		$this->assertNotFalse(
			has_action( 'plugins_loaded', 'meh_bootstrap' ),
			'meh_bootstrap() should be hooked to plugins_loaded whatever is installed.'
		);

		foreach ( self::bootstrap_hooks() as $label => $hook ) {
			$this->assertFalse(
				has_action( $hook[0], $hook[1] ),
				$label . ' should have been taken off before the bootstrap is called.'
			);
		}

		$this->assertFalse(
			has_action( 'admin_notices', 'meh_missing_dependency_notice' ),
			'The notice should have been taken off before the bootstrap is called.'
		);

		meh_bootstrap();

		foreach ( self::bootstrap_hooks() as $label => $hook ) {
			$this->assertNotFalse(
				has_action( $hook[0], $hook[1] ),
				$label . ' should be registered with FluentCRM absent.'
			);
		}

		// Requirements 1.11, 1.17: the schema boot runs on load, not only on
		// activation, and it runs with the dependency absent too.
		$this->assertSame(
			Schema::CURRENT_VERSION,
			Schema::stored_version(),
			'The bootstrap should leave the schema version recorded.'
		);

		// The one thing the absence does cost.
		$this->assertNotFalse(
			has_action( 'admin_notices', 'meh_missing_dependency_notice' ),
			'A missing dependency should add the admin notice.'
		);
	}

	/**
	 * Requirements 16.8, 16.9: the intake route is registered, on its path, with
	 * the Intake Secret as its permission callback, with FluentCRM absent.
	 *
	 * @return void
	 */
	public function test_the_intake_route_is_registered_with_fluentcrm_absent() {
		$this->boot();

		$path   = '/' . IntakeEndpoint::NAMESPACE . IntakeEndpoint::ROUTE;
		$routes = (array) rest_get_server()->get_routes();

		$this->assertArrayHasKey( $path, $routes, $path . ' should be registered.' );

		$handlers = array();

		foreach ( (array) $routes[ $path ] as $key => $handler ) {
			if ( is_int( $key ) && is_array( $handler ) && isset( $handler['methods'] ) ) {
				$handlers[] = $handler;
			}
		}

		$this->assertCount( 1, $handlers, 'One intake registration.' );
		$this->assertSame( array( 'POST' => true ), $handlers[0]['methods'] );
		$this->assertSame( array( IntakeEndpoint::class, 'handle' ), $handlers[0]['callback'] );
		$this->assertSame(
			array( IntakeEndpoint::class, 'authenticate' ),
			$handlers[0]['permission_callback'],
			'The intake route authenticates the Intake Secret rather than a WordPress user.'
		);
	}

	/**
	 * Requirements 16.8, 5.2, 6.7: an authenticated webhook carrying no WordPress
	 * user still creates an enquiry, and the linkage failure only downgrades
	 * `crm_sync_state`.
	 *
	 * @return void
	 */
	public function test_an_authenticated_intake_request_creates_an_enquiry_with_fluentcrm_absent() {
		$this->boot();
		$this->assertDependencyAbsent();
		$this->assertSame( 0, get_current_user_id(), 'A webhook carries no WordPress user.' );

		$response = $this->dispatch( array( IntakeEndpoint::SECRET_HEADER => self::SECRET ) );
		$data     = (array) $response->get_data();

		$this->assertSame(
			201,
			$response->get_status(),
			'An authenticated webhook should create an enquiry: ' . wp_json_encode( $data )
		);
		$this->assertTrue( ! empty( $data['created'] ), 'The endpoint should report the enquiry as created.' );
		$this->assertGreaterThan( 0, (int) $data['enquiry_id'], 'A created enquiry gets an identifier.' );

		$enquiry = EnquiryStore::find( (int) $data['enquiry_id'] );

		$this->assertIsArray( $enquiry, 'The created enquiry should be readable.' );
		$this->assertSame( 1, EnquiryStore::query( array() )['total'], 'Exactly one enquiry was created.' );
		$this->assertSame( 0, EnquiryStore::rejections()['total'], 'A created enquiry records no rejected attempt.' );

		// The submission was stored in full, not degraded by the missing CRM.
		$this->assertSame( 'Ada', $enquiry['first_name'] );
		$this->assertSame( 'Lovelace', $enquiry['last_name'] );
		$this->assertSame( self::EMAIL, $enquiry['email'] );
		$this->assertSame( 'new', $enquiry['status'] );
		$this->assertSame( IntakeEndpoint::SOURCE_PREFIX . self::FORM_ID, $enquiry['source'] );
		$this->assertSame( array( '2025-10-04', '2025-10-11' ), $enquiry['selected_dates'] );
		$this->assertSame( array( 'wedding' ), $enquiry['event_type'] );

		// Requirements 5.2, 6.7: the linkage failure downgrades the enquiry and
		// records no subscriber identifier.
		$this->assertSame( ContactLinker::STATE_PENDING, $enquiry['crm_sync_state'] );
		$this->assertEmpty( $enquiry['fluentcrm_subscriber_id'] );

		$types = array();

		foreach ( HistoryRecorder::for_enquiry( (int) $data['enquiry_id'] ) as $entry ) {
			$types[] = $entry['entry_type'];
		}

		$this->assertContains( 'created', $types, 'The creation is recorded in history.' );
		$this->assertNotContains( ContactLinker::HISTORY_TYPE, $types, 'No contact was linked.' );

		$this->assertSame(
			array(),
			$this->crm->create_or_update_calls(),
			'An unavailable FluentCRM should receive no contact write.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The bootstrap hooks this test takes off and then watches come back.
	 *
	 * The enquiry layer as the requirement names it: intake, the REST surface,
	 * the lifecycle's scheduled work, and the two admin screens the hub is worked
	 * through.
	 *
	 * @return array<string,array{0:string,1:callable}>
	 */
	private static function bootstrap_hooks() {
		return array(
			'The intake route registration'  => array( 'rest_api_init', array( IntakeEndpoint::class, 'register_routes' ) ),
			'The enquiry route registration' => array( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) ),
			'The automatic closure job'      => array( AutoCloseJob::HOOK, array( AutoCloseJob::class, 'run' ) ),
			'The settings screen'            => array( 'admin_init', array( Settings::class, 'register_settings' ) ),
			'The settings menu entry'        => array( 'admin_menu', array( Settings::class, 'register_menu' ) ),
			'The hub menu entry'             => array( 'admin_menu', array( AdminPage::class, 'register_menu' ) ),
		);
	}

	/**
	 * Take the bootstrap's hooks off, at whatever priority they were added at.
	 *
	 * @return void
	 */
	private function unregister_bootstrap_hooks() {
		$hooks = self::bootstrap_hooks();

		$hooks['The missing dependency notice'] = array( 'admin_notices', 'meh_missing_dependency_notice' );

		foreach ( $hooks as $hook ) {
			$priority = has_action( $hook[0], $hook[1] );

			while ( false !== $priority ) {
				remove_action( $hook[0], $hook[1], (int) $priority );

				$priority = has_action( $hook[0], $hook[1] );
			}
		}
	}

	/**
	 * Run the bootstrap and fire `rest_api_init`, as a request would.
	 *
	 * @return void
	 */
	private function boot() {
		global $wp_rest_server;

		meh_bootstrap();

		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * FluentCRM Pro is absent and its contacts API resolves to nothing.
	 *
	 * @return void
	 */
	private function assertDependencyAbsent() {
		if ( meh_is_fluentcrm_pro_active() ) {
			$this->markTestSkipped( 'FluentCRM Pro is active in this environment, so the absent case cannot be observed.' );
		}

		$this->assertFalse( meh_is_fluentcrm_pro_active(), 'FluentCRM Pro should be absent.' );
		$this->assertFalse( ContactLinker::available(), 'The contacts API should resolve to nothing.' );
	}

	/**
	 * Dispatch one intake request through the REST server.
	 *
	 * @param array $headers Request headers.
	 * @return \WP_REST_Response
	 */
	private function dispatch( array $headers = array() ) {
		$request = new WP_REST_Request( 'POST', '/' . IntakeEndpoint::NAMESPACE . IntakeEndpoint::ROUTE );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $this->body() ) );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * One webhook body, carrying all nine enquiry fields by label.
	 *
	 * @return array
	 */
	private function body() {
		return array(
			self::SOURCE_FIELD => self::FORM_ID,
			'First Name'       => 'Ada',
			'Last Name'        => 'Lovelace',
			'Email'            => self::EMAIL,
			'Phone'            => '0114 496 0001',
			'Total Guests'     => '60',
			'Selected Dates'   => array( '2025-10-04', '2025-10-11' ),
			'Event Type'       => array( 'wedding' ),
			'Site Exclusivity' => array( 'full site' ),
			'Message'          => 'Enquiring about the barn for an October wedding.',
		);
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
