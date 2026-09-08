<?php
/**
 * Worked examples for the outbound half of the Intake Secret transport:
 * `IntakeEndpoint::attach_secret_header()`.
 *
 * The sending form's webhook action offers a destination URL and field mappings
 * and nothing else, so it cannot be configured to send the
 * `X-MEH-Intake-Secret` header Requirement 16.14 prefers. Because the form and
 * the plugin sit on the same site, the webhook POST is a loopback request
 * WordPress makes, so the plugin attaches that header itself through
 * `http_request_args` — which is how the site runs on the stronger transport
 * with the vendor supporting only the weaker one (Requirement 16.15).
 *
 * `http_request_args` fires for every outbound HTTP request WordPress makes, so
 * most of what is asserted here is about what the filter does *not* touch: a
 * foreign host carrying the intake path, another route on this host, and a site
 * with no secret stored all come back unchanged, and a request already carrying
 * the header keeps the value it came with.
 *
 * The last example is the one that matters most. It takes the header the filter
 * produced and puts it through the registered route, so the two halves of the
 * transport are shown to agree: what this filter attaches is what
 * `authenticate()` accepts, and an intake request still creates an enquiry end
 * to end.
 *
 * Like the other store tests, this one works against real tables at its own
 * prefix, because `Schema::install()` verifies each table through `SHOW TABLES`,
 * which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\FieldMapper;
use MarthrownEnquiryHub\IntakeEndpoint;
use MarthrownEnquiryHub\Schema;

/**
 * Class IntakeSecretHeaderTest
 */
class IntakeSecretHeaderTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table or with another test's fixture in the same database.
	 */
	const PREFIX_SEGMENT = 'mehht_';

	/**
	 * The instant every example receives its webhook at.
	 */
	const AT = '2025-07-04 14:30:00';

	/**
	 * The Intake Secret held in Settings for the length of each example.
	 */
	const SECRET = 'Rd8vK2pQm5zTx7wLn3Yc';

	/**
	 * The route under test, as the REST server addresses it.
	 */
	const ROUTE = '/marthrown-enquiry-hub/v1/intake';

	/**
	 * The payload field naming the sending form, as configured in Settings.
	 */
	const SOURCE_FIELD = 'form_id';

	/**
	 * The email address the end-to-end example submits.
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
	 * Load the classes under test.
	 *
	 * `class-enquiry-creator.php` before `class-intake-handler.php`: the handler
	 * reads the creator's constants as its own class body is evaluated.
	 *
	 * @param mixed $factory WordPress fixture factory (unused).
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory = null ) {
		unset( $factory );

		require_once MEH_INCLUDES_DIR . 'class-log.php';
		require_once MEH_INCLUDES_DIR . 'class-clock.php';
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

		// A sending form has no single field for a date range, so the two payload
		// fields holding the ideal range are named in Settings and the endpoint
		// gathers them into `date_ranges`.
		update_option( IntakeEndpoint::START_DATE_FIELD_OPTION, 'Start Date' );
		update_option( IntakeEndpoint::END_DATE_FIELD_OPTION, 'End Date' );
		delete_option( FieldMapper::OPTION );

		// A server of this test's own, so the route table holds what this test
		// registered and nothing a previous test left behind.
		$this->original_server = $wp_rest_server;
		$wp_rest_server        = new WP_REST_Server();

		IntakeEndpoint::init();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		remove_filter( 'http_request_args', array( IntakeEndpoint::class, 'attach_secret_header' ), 10 );
		DuplicateDetector::reset( self::EMAIL );

		$wp_rest_server = $this->original_server;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * What the filter attaches
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 16.14: an outbound request to the intake endpoint carries the
	 * stored secret in the `X-MEH-Intake-Secret` header.
	 *
	 * @return void
	 */
	public function test_the_header_is_attached_to_the_intake_url() {
		$args = $this->filter( IntakeEndpoint::url() );

		$this->assertArrayHasKey( IntakeEndpoint::SECRET_HEADER, $args['headers'] );
		$this->assertSame( self::SECRET, $args['headers'][ IntakeEndpoint::SECRET_HEADER ] );
	}

	/**
	 * A destination URL already carrying the weaker query-parameter transport is
	 * still recognised as the intake endpoint, so a site part-way through the
	 * switch to the header transport is not left on neither.
	 *
	 * @return void
	 */
	public function test_the_header_is_attached_when_the_url_also_carries_the_query_parameter() {
		$url = add_query_arg( IntakeEndpoint::SECRET_QUERY, self::SECRET, IntakeEndpoint::url() );

		$args = $this->filter( $url );

		$this->assertSame( self::SECRET, $args['headers'][ IntakeEndpoint::SECRET_HEADER ] );
	}

	/* ---------------------------------------------------------------------
	 * What the filter leaves alone
	 * ------------------------------------------------------------------ */

	/**
	 * A foreign host carrying the intake path gets nothing.
	 *
	 * This is the assertion that keeps `http_request_args` from handing the
	 * secret to a third party: the filter fires for every outbound request, so
	 * matching on the path alone would be enough to leak it.
	 *
	 * @return void
	 */
	public function test_no_header_is_attached_to_a_foreign_host_carrying_the_same_path() {
		$own   = wp_parse_url( IntakeEndpoint::url() );
		$path  = isset( $own['path'] ) ? $own['path'] : self::ROUTE;
		$query = isset( $own['query'] ) ? '?' . $own['query'] : '';

		foreach ( array( 'https://attacker.example', 'https://api.wordpress.org' ) as $host ) {
			$args = $this->filter( $host . $path . $query );

			$this->assertSame( array(), $args['headers'], $host . ' should receive no secret.' );
		}
	}

	/**
	 * Another route on this site's own host gets nothing.
	 *
	 * @return void
	 */
	public function test_no_header_is_attached_to_an_unrelated_url() {
		foreach ( array( rest_url( 'wp/v2/posts' ), home_url( '/' ), admin_url( 'admin-ajax.php' ) ) as $url ) {
			$args = $this->filter( $url );

			$this->assertSame( array(), $args['headers'], $url . ' should receive no secret.' );
		}
	}

	/**
	 * With no secret stored there is nothing to attach, and the arguments come
	 * back exactly as they went in.
	 *
	 * @return void
	 */
	public function test_no_header_is_attached_when_no_secret_is_stored() {
		delete_option( IntakeEndpoint::SECRET_OPTION );

		$args = $this->filter( IntakeEndpoint::url() );

		$this->assertSame( array(), $args['headers'] );
	}

	/**
	 * A request already carrying the header keeps the value it came with,
	 * whatever the case of the header name it used.
	 *
	 * @return void
	 */
	public function test_an_existing_header_is_not_overwritten() {
		$existing = 'a-value-of-its-own';

		$args = $this->filter(
			IntakeEndpoint::url(),
			array( IntakeEndpoint::SECRET_HEADER => $existing )
		);

		$this->assertSame( $existing, $args['headers'][ IntakeEndpoint::SECRET_HEADER ] );

		$lower = $this->filter(
			IntakeEndpoint::url(),
			array( strtolower( IntakeEndpoint::SECRET_HEADER ) => $existing )
		);

		$this->assertSame( array( strtolower( IntakeEndpoint::SECRET_HEADER ) => $existing ), $lower['headers'] );
	}

	/* ---------------------------------------------------------------------
	 * The two halves agree
	 * ------------------------------------------------------------------ */

	/**
	 * Requirements 16.14, 2.1: the header this filter attaches authenticates the
	 * intake route, and the enquiry is created.
	 *
	 * The outbound half and the inbound half are wired together here rather than
	 * asserted separately: the arguments the filter produced supply the headers
	 * the request is dispatched with, so a change to either side that broke the
	 * agreement between them fails this test.
	 *
	 * @return void
	 */
	public function test_an_authenticated_intake_request_still_succeeds_end_to_end() {
		$args = $this->filter( IntakeEndpoint::url() );

		$response = rest_get_server()->dispatch( $this->request( $args['headers'] ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['created'] );
		$this->assertGreaterThan( 0, $response->get_data()['enquiry_id'] );

		$enquiry = EnquiryStore::find( (int) $response->get_data()['enquiry_id'] );

		$this->assertIsArray( $enquiry );
		$this->assertSame( self::EMAIL, $enquiry['email'] );
		$this->assertSame( 'new', $enquiry['status'] );
		$this->assertSame( IntakeEndpoint::SOURCE_PREFIX . 'kadence-enquiry', $enquiry['source'] );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Put one set of request arguments through `http_request_args`.
	 *
	 * The filter is applied through `apply_filters` rather than called directly,
	 * so the registration `IntakeEndpoint::init()` performs is exercised too.
	 *
	 * @param string $url     Outbound request URL.
	 * @param array  $headers Headers the request already carries.
	 * @return array Filtered arguments.
	 */
	private function filter( $url, array $headers = array() ) {
		$args = apply_filters(
			'http_request_args',
			array(
				'method'  => 'POST',
				'headers' => $headers,
				'body'    => array(),
			),
			$url
		);

		$this->assertIsArray( $args );
		$this->assertIsArray( $args['headers'] );

		return $args;
	}

	/**
	 * One intake request carrying a Kadence-shaped body.
	 *
	 * @param array $headers Request headers.
	 * @return \WP_REST_Request
	 */
	private function request( array $headers ) {
		$request = new WP_REST_Request( 'POST', self::ROUTE );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $this->body() ) );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	/**
	 * A Kadence-shaped webhook body: a wrapper of `{ label, value }` nodes, with
	 * the multi-value fields delivered as delimited strings.
	 *
	 * All nine fields, because the Webhook Validation Profile requires all nine
	 * (Requirement 3.14) and a short body would be rejected on validation rather
	 * than on the secret — which would make this example silent about the thing
	 * it is here to assert.
	 *
	 * @return array
	 */
	private function body() {
		return array(
			self::SOURCE_FIELD => 'kadence-enquiry',
			'fields'           => array(
				array(
					'label' => 'First Name',
					'value' => 'Ada',
				),
				array(
					'label' => 'Last Name',
					'value' => 'Lovelace',
				),
				array(
					'label' => 'Email',
					'value' => self::EMAIL,
				),
				array(
					'label' => 'Phone',
					'value' => '0114 496 0000',
				),
				array(
					'label' => 'Total Guests',
					'value' => '40',
				),
				array(
					'label' => 'Start Date',
					'value' => '2025-10-04',
				),
				array(
					'label' => 'End Date',
					'value' => '2025-10-11',
				),
				array(
					'label' => 'Event Type',
					'value' => 'wedding',
				),
				array(
					'label' => 'Site Exclusivity',
					'value' => 'full site',
				),
				array(
					'label' => 'Message',
					'value' => 'Asking about the barn for an October wedding.',
				),
			),
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
