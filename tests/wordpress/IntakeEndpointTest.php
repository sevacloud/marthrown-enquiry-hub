<?php
/**
 * Worked examples for `IntakeEndpoint`: the two secret transports, and one
 * sender's body against another's.
 *
 * Two things are asserted here, both of them about the route rather than about
 * the handler behind it.
 *
 * **The secret transports.** The route is dispatched four times: the correct
 * secret in the `X-MEH-Intake-Secret` header, the correct secret in the
 * `meh_secret` query parameter, no secret at all, and a secret differing from
 * the stored one. The first two create an enquiry; the last two are answered 401
 * and leave the Enquiry Store exactly as it was — no enquiry and, because
 * `authenticate()` is a permission callback and so runs before `handle()`, no
 * rejected intake attempt either (Requirements 16.11, 16.14, 16.15). The
 * comparison being timing-independent is Requirement 16.10, which no
 * behavioural test can establish; `IntakeSecretComparisonTest` in the `pure`
 * suite covers it by reading the source.
 *
 * **Sender-agnostic normalisation.** A Kadence-shaped body — a `fields` wrapper
 * holding `{ label, value }` nodes, with the multi-value fields delivered as
 * comma-delimited strings — and a plain flat JSON body carrying the same values
 * are put through `normalise()` and then through the route. They produce one
 * field map and one stored enquiry, differing only where the two senders
 * genuinely differ: the form identifier, and therefore `source`. Nothing about
 * either sender is configured: `meh_field_map` is unset throughout, so every
 * field is resolved by label matching alone (Requirements 2.12, 2.10).
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
 * Class IntakeEndpointTest
 */
class IntakeEndpointTest extends WP_UnitTestCase {

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table or with another test's fixture in the same database.
	 */
	const PREFIX_SEGMENT = 'mehie_';

	/**
	 * The instant every example receives its webhook at.
	 */
	const AT = '2025-07-04 14:30:00';

	/**
	 * The Intake Secret held in Settings for the length of each example.
	 */
	const SECRET = 'PWjK7sQ2vh4mZbLr9tXn';

	/**
	 * A secret differing from the stored one only in its final character, so the
	 * rejection cannot be attributed to a length check.
	 */
	const WRONG_SECRET = 'PWjK7sQ2vh4mZbLr9tXm';

	/**
	 * The route under test.
	 */
	const ROUTE = '/marthrown-enquiry-hub/v1/intake';

	/**
	 * The payload field naming the sending form, as configured in Settings.
	 */
	const SOURCE_FIELD = 'form_id';

	/**
	 * The email address every accepted example submits.
	 */
	const EMAIL = 'grace@example.com';

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

		// A sending form has no single field for a date range, so each range
		// arrives as two payload fields named in Settings. Both senders below fill
		// in the ideal pair and one alternative, so the gathering the endpoint does
		// is part of what the two are compared on.
		update_option( IntakeEndpoint::START_DATE_FIELD_OPTION, 'Start Date' );
		update_option( IntakeEndpoint::END_DATE_FIELD_OPTION, 'End Date' );
		update_option( 'meh_intake_start_date_field_2', 'Alternative Start' );
		update_option( 'meh_intake_end_date_field_2', 'Alternative End' );

		// Requirement 2.12: no mapping control is set, so nothing here is
		// configured for either of the two senders below.
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

		remove_filter( 'meh_duplicate_window', array( $this, 'no_duplicate_window' ) );
		DuplicateDetector::reset( self::EMAIL );

		$wp_rest_server = $this->original_server;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * Secret transports
	 * ------------------------------------------------------------------ */

	/**
	 * Requirement 16.14: the correct secret in the `X-MEH-Intake-Secret` header
	 * authenticates the request, and the enquiry is created.
	 *
	 * @return void
	 */
	public function test_the_correct_secret_in_the_header_is_accepted() {
		$response = $this->dispatch( $this->generic_body(), array( IntakeEndpoint::SECRET_HEADER => self::SECRET ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['created'] );
		$this->assertGreaterThan( 0, $response->get_data()['enquiry_id'] );
		$this->assertSame( 1, EnquiryStore::query( array() )['total'] );
	}

	/**
	 * Requirement 16.15: the correct secret in the `meh_secret` query parameter
	 * authenticates the request, for a sender whose webhook action supports no
	 * custom header.
	 *
	 * @return void
	 */
	public function test_the_correct_secret_in_the_query_parameter_is_accepted() {
		$response = $this->dispatch(
			$this->generic_body(),
			array(),
			array( IntakeEndpoint::SECRET_QUERY => self::SECRET )
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['created'] );
		$this->assertGreaterThan( 0, $response->get_data()['enquiry_id'] );
		$this->assertSame( 1, EnquiryStore::query( array() )['total'] );
	}

	/**
	 * Requirement 16.11: a request presenting no secret at all is answered 401,
	 * creates no enquiry and records no rejected intake attempt.
	 *
	 * @return void
	 */
	public function test_a_request_presenting_no_secret_is_answered_401() {
		$response = $this->dispatch( $this->generic_body() );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( IntakeEndpoint::UNAUTHORIZED_CODE, $response->get_data()['code'] );
		$this->assertStoreUntouched();
	}

	/**
	 * Requirement 16.11: a request presenting a secret that differs from the
	 * stored one is answered 401, whichever transport carries it, and leaves the
	 * store untouched.
	 *
	 * @return void
	 */
	public function test_a_request_presenting_a_mismatched_secret_is_answered_401() {
		$header = $this->dispatch(
			$this->generic_body(),
			array( IntakeEndpoint::SECRET_HEADER => self::WRONG_SECRET )
		);

		$this->assertSame( 401, $header->get_status() );
		$this->assertSame( IntakeEndpoint::UNAUTHORIZED_CODE, $header->get_data()['code'] );

		$query = $this->dispatch(
			$this->generic_body(),
			array(),
			array( IntakeEndpoint::SECRET_QUERY => self::WRONG_SECRET )
		);

		$this->assertSame( 401, $query->get_status() );
		$this->assertSame( IntakeEndpoint::UNAUTHORIZED_CODE, $query->get_data()['code'] );

		$this->assertStoreUntouched();
	}

	/* ---------------------------------------------------------------------
	 * Sender-agnostic normalisation
	 * ------------------------------------------------------------------ */

	/**
	 * Requirements 2.12, 2.10: a Kadence-shaped body and a flat JSON body
	 * carrying the same values normalise to the same field map, apart from the
	 * form identifier, with no sender-specific configuration.
	 *
	 * @return void
	 */
	public function test_two_senders_carrying_the_same_values_normalise_identically() {
		$this->assertSame( array(), FieldMapper::mapping(), 'No mapping control is configured.' );

		$kadence = IntakeEndpoint::normalise( $this->request( $this->kadence_body() ) );
		$generic = IntakeEndpoint::normalise( $this->request( $this->generic_body() ) );

		// The one legitimate difference: the two senders name themselves.
		$this->assertSame( 'kadence-enquiry', $kadence[ self::SOURCE_FIELD ] );
		$this->assertSame( 'generic-enquiry', $generic[ self::SOURCE_FIELD ] );

		// The delimited strings the Kadence-shaped body delivered arrive as the
		// same lists the flat body delivered directly.
		$this->assertSame( array( 'wedding' ), $kadence['Event Type'] );
		$this->assertSame( array( 'full site' ), $kadence['Site Exclusivity'] );

		// The four configured date fields arrive as the two ranges they name,
		// ideal range first, and are gone from the map under their own names: a
		// field read as one bound of a range has no second reading.
		$this->assertSame(
			array(
				array(
					'start' => '2025-09-06',
					'end'   => '2025-09-07',
				),
				array(
					'start' => '2025-09-13',
					'end'   => '2025-09-13',
				),
			),
			$kadence['date_ranges'],
			'The configured start and end fields arrive as `date_ranges`.'
		);

		foreach ( array( 'Start Date', 'End Date', 'Alternative Start', 'Alternative End' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $kadence, $field . ' is consumed by the range it supplies.' );
		}

		$this->assertSame( $this->comparable( $generic ), $this->comparable( $kadence ) );
	}

	/**
	 * Requirement 2.12: the two bodies store the same enquiry, apart from
	 * `source` and the form identifier inside the payload snapshot.
	 *
	 * The two submissions are deliberately identical, which is the whole point of
	 * the assertion, so the duplicate guard is switched off for the length of
	 * this test rather than the payloads being made to differ.
	 *
	 * @return void
	 */
	public function test_two_senders_carrying_the_same_values_store_the_same_enquiry() {
		add_filter( 'meh_duplicate_window', array( $this, 'no_duplicate_window' ) );

		$secret = array( IntakeEndpoint::SECRET_HEADER => self::SECRET );

		$first  = $this->dispatch( $this->kadence_body(), $secret );
		$second = $this->dispatch( $this->generic_body(), $secret );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 201, $second->get_status() );

		$kadence = EnquiryStore::find( $first->get_data()['enquiry_id'] );
		$generic = EnquiryStore::find( $second->get_data()['enquiry_id'] );

		$this->assertIsArray( $kadence );
		$this->assertIsArray( $generic );

		// Requirement 2.3: `source` holds the form identifier each sender carried.
		$this->assertSame( 'webhook:kadence-enquiry', $kadence['source'] );
		$this->assertSame( 'webhook:generic-enquiry', $generic['source'] );

		$this->assertSame(
			$this->comparable( $generic['payload'] ),
			$this->comparable( $kadence['payload'] ),
			'The stored payload snapshots agree apart from the form identifier.'
		);

		foreach ( array( 'id', 'source', 'payload' ) as $key ) {
			unset( $kadence[ $key ], $generic[ $key ] );
		}

		$this->assertSame( $generic, $kadence, 'The stored enquiries agree in every remaining field.' );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Switch duplicate detection off (Requirement 4.4's filter).
	 *
	 * @return int
	 */
	public function no_duplicate_window() {
		return 0;
	}

	/**
	 * A field map or payload with the form identifier removed and keys sorted, so
	 * two senders are compared on the values they carry rather than on the order
	 * they happened to send them in.
	 *
	 * @param array $fields Field map.
	 * @return array
	 */
	private function comparable( array $fields ) {
		unset( $fields[ self::SOURCE_FIELD ] );
		ksort( $fields );

		return $fields;
	}

	/**
	 * No enquiry and no rejected intake attempt exists (Requirement 16.11).
	 *
	 * @return void
	 */
	private function assertStoreUntouched() {
		$this->assertSame( 0, EnquiryStore::query( array() )['total'], 'No enquiry was created.' );
		$this->assertSame( 0, EnquiryStore::rejections()['total'], 'No rejected intake attempt was recorded.' );
	}

	/**
	 * One intake request.
	 *
	 * @param array $body    JSON body to post.
	 * @param array $headers Request headers.
	 * @param array $query   Query parameters.
	 * @return \WP_REST_Request
	 */
	private function request( array $body, array $headers = array(), array $query = array() ) {
		$request = new WP_REST_Request( 'POST', self::ROUTE );

		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		if ( array() !== $query ) {
			$request->set_query_params( $query );
		}

		return $request;
	}

	/**
	 * Dispatch one intake request through the REST server.
	 *
	 * @param array $body    JSON body to post.
	 * @param array $headers Request headers.
	 * @param array $query   Query parameters.
	 * @return \WP_REST_Response
	 */
	private function dispatch( array $body, array $headers = array(), array $query = array() ) {
		return rest_get_server()->dispatch( $this->request( $body, $headers, $query ) );
	}

	/**
	 * A Kadence-shaped webhook body: a wrapper holding `{ label, value }` nodes,
	 * with the multi-value fields delivered as delimited strings.
	 *
	 * @return array
	 */
	private function kadence_body() {
		return array(
			self::SOURCE_FIELD => 'kadence-enquiry',
			'fields'           => array(
				array(
					'label' => 'First Name',
					'value' => 'Grace',
				),
				array(
					'label' => 'Last Name',
					'value' => 'Hopper',
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
					'value' => '2025-09-06',
				),
				array(
					'label' => 'End Date',
					'value' => '2025-09-07',
				),
				array(
					'label' => 'Alternative Start',
					'value' => '2025-09-13',
				),
				array(
					'label' => 'Alternative End',
					'value' => '2025-09-13',
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
					'value' => 'Looking at the barn for a September wedding.',
				),
			),
		);
	}

	/**
	 * A generic flat JSON body carrying the same values, multi-values as arrays.
	 *
	 * @return array
	 */
	private function generic_body() {
		return array(
			self::SOURCE_FIELD  => 'generic-enquiry',
			'First Name'        => 'Grace',
			'Last Name'         => 'Hopper',
			'Email'             => self::EMAIL,
			'Phone'             => '0114 496 0000',
			'Total Guests'      => '40',
			'Start Date'        => '2025-09-06',
			'End Date'          => '2025-09-07',
			'Alternative Start' => '2025-09-13',
			'Alternative End'   => '2025-09-13',
			'Event Type'        => array( 'wedding' ),
			'Site Exclusivity'  => array( 'full site' ),
			'Message'           => 'Looking at the barn for a September wedding.',
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
