<?php
/**
 * Property 7: Intake endpoint authentication.
 *
 * Feature: enquiry-data-layer, Property 7: For any intake webhook request whose
 * presented secret is absent, or differs from the stored intake secret in any
 * way (including a prefix, a suffix, a case change, added whitespace or a
 * truncation of it), and for either secret transport, the response status is
 * 401, no enquiry row and no rejected-intake-attempt row is added, and stored
 * data is unchanged; for any request presenting the stored secret exactly, via
 * either the request header or the request query parameter, the request is
 * accepted and proceeds to intake processing; and for any route in the namespace
 * and any request to it, successful or failed, the intake secret value appears
 * in neither the response body nor any response header.
 *
 * **Validates: Requirements 16.10, 16.11, 16.12, 16.14, 16.15**
 *
 * How the property is instantiated, and why:
 *
 * - **Every request goes through the REST server, not through `authenticate()`.**
 *   Each iteration builds a real `WP_REST_Request` and dispatches it with
 *   `rest_do_request()`, so the permission callback runs where WordPress runs it:
 *   ahead of the route callback. That ordering is the whole reason Requirement
 *   16.11 holds — "no enquiry, no rejection row" is a consequence of the callback
 *   never being reached — and calling `authenticate()` directly would assert the
 *   comparison while assuming the ordering the requirement depends on.
 * - **The verdict is decided by an oracle, not by the mutation's name.** The
 *   expectation is computed as "the request presented a value, and that value
 *   equals the stored secret character for character". A mutation that happened
 *   to reproduce the stored secret would therefore be expected to be accepted
 *   rather than silently mis-asserted; an assertion in the body confirms each
 *   non-exact mutation genuinely differs, so no iteration is vacuous.
 * - **The mutation set is the one the property enumerates**, each shape being
 *   something a misconfigured sender genuinely produces: no secret at all, an
 *   empty value, a whitespace-only value, a character added at the front or the
 *   back, the whole value cased differently, whitespace added around it,
 *   whitespace added inside it, the last character dropped, and an unrelated
 *   value.
 * - **Surrounding whitespace is presented on the query transport only.** A
 *   request header cannot carry it: leading and trailing whitespace in a header
 *   field value is stripped by the receiving server before PHP ever sees it, so
 *   asserting a 401 for a padded header would assert something no sender can
 *   send. A query parameter carries `%20` faithfully, which is where "added
 *   whitespace" is a difference the endpoint can actually observe.
 * - **Each case presents its secret on exactly one transport.** Requirement
 *   16.14 and 16.15 ask that either transport be accepted, which is what
 *   quantifying over the two establishes. Which transport wins when a request
 *   carries both is a design detail rather than part of this property, and mixing
 *   them would make the oracle depend on that precedence rule instead of on the
 *   requirement.
 * - **Both body encodings are drawn**, JSON and form-encoded, because
 *   authentication must not depend on how the sender framed its payload.
 * - **"Stored data is unchanged" is asserted as a snapshot**, not as an absence
 *   of new enquiries: the row counts of all six Enquiry Store tables, taken
 *   before the request and compared after it, plus the full hydrated state of a
 *   seeded enquiry. A seeded enquiry and a seeded rejection row make those counts
 *   non-zero, so a stray write shows as a change rather than as a zero matching a
 *   zero.
 * - **Acceptance is asserted as 201 and a stored enquiry**, which is the strongest
 *   available reading of "proceeds to intake processing". Each iteration submits a
 *   unique email address so neither the duplicate guard nor the rate limiter can
 *   turn an authenticated request away for an unrelated reason and leave the
 *   acceptance half of the property untested.
 * - **The no-leak claim is quantified over the routes the namespace actually
 *   holds**, read back from the REST server rather than listed here, so a route
 *   added later is covered without this test being edited. Each iteration
 *   dispatches one of them with the secret presented in both the header and the
 *   query string — the shape most likely to be echoed back — and the response
 *   body, every response header and every nested value are scanned for it. The
 *   namespace index route is among them, which is what covers the secret
 *   appearing in route metadata rather than in a handler's own output.
 *
 * The `hash_equals()` half of Requirement 16.10 is a source-level fact rather
 * than a behavioural one, and belongs to the unit tests in task 8.11: a timing
 * measurement inside a property test is not a reliable oracle.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix: the WordPress test case rewrites `CREATE TABLE` into its
 * `TEMPORARY` form, and `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see a temporary table.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\DuplicateDetector;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\IntakeEndpoint;
use MarthrownEnquiryHub\RestBookings;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class IntakeAuthenticationPropertyTest
 */
class IntakeAuthenticationPropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables cannot collide with a real Enquiry
	 * Store table sitting in the same test database.
	 */
	const PREFIX_SEGMENT = 'mehia_';

	/**
	 * The instant every request arrives at.
	 */
	const NOW = '2025-08-11 09:15:00';

	/**
	 * Presented-secret shapes that must be refused, as the property enumerates
	 * them.
	 *
	 * @var string[]
	 */
	const REFUSED = array(
		'absent',
		'empty',
		'whitespace_only',
		'prefix',
		'suffix',
		'case_change',
		'surrounding_whitespace',
		'interior_whitespace',
		'truncated',
		'unrelated',
	);

	/**
	 * The two secret transports (Requirements 16.14, 16.15).
	 *
	 * @var string[]
	 */
	const TRANSPORTS = array( 'header', 'query' );

	/**
	 * The body encodings a sender may use.
	 *
	 * @var string[]
	 */
	const ENCODINGS = array( 'json', 'form' );

	/**
	 * Secret shapes, all ASCII, all at least twelve characters, every one holding
	 * a lower-case segment so a case change is always a change.
	 *
	 * @var string[]
	 */
	const SECRET_SHAPES = array( 'hex', 'mixed_case', 'punctuated', 'long', 'digits' );

	/**
	 * The WordPress table prefix in force outside this test.
	 *
	 * @var string
	 */
	private $original_prefix = '';

	/**
	 * Email addresses whose rate-limit counters need forgetting.
	 *
	 * @var string[]
	 */
	private $counted = array();

	/**
	 * Iteration counter, used to keep every submitted address unique.
	 *
	 * @var int
	 */
	private $iteration = 0;

	/**
	 * The seeded enquiry's identifier.
	 *
	 * @var int
	 */
	private $seeded_id = 0;

	/**
	 * The seeded enquiry as stored, for the "stored data is unchanged" claim.
	 *
	 * @var array
	 */
	private $seeded_row = array();

	/**
	 * Every route the namespace holds, as route plus method pairs.
	 *
	 * @var array<int,array{route:string,method:string}>
	 */
	private $namespace_routes = array();

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

		// The rest of the namespace, so the no-leak claim is quantified over every
		// route the plugin registers rather than over the intake route alone.
		require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-bookings.php';
	}

	public function set_up() {
		parent::set_up();

		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->original_prefix = $wpdb->prefix;
		$wpdb->prefix          = $this->original_prefix . self::PREFIX_SEGMENT;

		$this->drop_tables();
		$this->assertTrue( Schema::install(), 'The fixture install should succeed.' );

		Clock::freeze( self::NOW );

		$this->seed_existing_rows();
		$this->boot_rest_server();
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		foreach ( $this->counted as $email ) {
			DuplicateDetector::reset( $email );
		}

		$this->counted = array();

		delete_option( IntakeEndpoint::SECRET_OPTION );

		$wp_rest_server = null;

		Clock::unfreeze();
		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 7: Intake endpoint authentication.
	 *
	 * **Validates: Requirements 16.10, 16.11, 16.12, 16.14, 16.15**
	 */
	public function test_intake_endpoint_authentication() {
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
	 * One request, dispatched and judged.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check( array $case ) {
		$stored = (string) $case['secret'];

		update_option( IntakeEndpoint::SECRET_OPTION, $stored );

		$payload   = $this->payload( (array) $case['payload'] );
		$mutation  = (string) $case['mutation'];
		$presented = self::presented( $stored, $mutation );
		$transport = self::transport_for( $mutation, (string) $case['transport'] );
		$accepted  = null !== $presented && $presented === $stored;
		$label     = self::label( $case, $transport, $accepted );

		if ( 'exact' !== $mutation ) {
			$this->assertNotSame(
				$stored,
				(string) $presented,
				'A refusing case must genuinely differ from the stored secret. ' . $label
			);
		}

		$before   = $this->row_counts();
		$response = $this->dispatch_intake( $payload, $presented, $transport, (string) $case['encoding'] );
		$data     = (array) $response->get_data();

		if ( $accepted ) {
			$this->assertSame( 201, $response->get_status(), 'An exact secret is accepted. ' . $label );
			$this->assertTrue( ! empty( $data['created'] ), 'An accepted request creates an enquiry. ' . $label );

			$enquiry = EnquiryStore::find( (int) $data['enquiry_id'] );

			$this->assertIsArray( $enquiry, 'The created enquiry should be readable. ' . $label );
			$this->assertSame( $payload['email'], $enquiry['email'], 'The submission reached the store. ' . $label );

			$after = $this->row_counts();

			$this->assertSame(
				$before['enquiries'] + 1,
				$after['enquiries'],
				'An accepted request adds exactly one enquiry. ' . $label
			);
		} else {
			$this->assertSame( 401, $response->get_status(), 'A missing or differing secret is refused. ' . $label );
			$this->assertSame(
				IntakeEndpoint::UNAUTHORIZED_CODE,
				isset( $data['code'] ) ? $data['code'] : '',
				'The refusal names the unauthorized code. ' . $label
			);

			$this->assertSame(
				$before,
				$this->row_counts(),
				'A refused request adds no enquiry and no rejection row. ' . $label
			);
			$this->assertSame(
				$this->seeded_row,
				EnquiryStore::find( $this->seeded_id ),
				'A refused request leaves stored data unchanged. ' . $label
			);
		}

		$this->assert_no_leak( $response, $stored, 'Intake response. ' . $label );

		// The third clause: any route in the namespace, presented the secret on
		// both transports at once, must not echo it back.
		$this->assert_no_leak(
			$this->dispatch_namespace_route( (int) $case['route'], $stored, $payload ),
			$stored,
			'Namespace route. ' . $label
		);
	}

	/**
	 * Assert a response carries the secret in neither its body nor a header.
	 *
	 * The body is checked twice: once as the JSON a client receives, and once by
	 * walking the response data so a value the encoder would have escaped cannot
	 * hide a leak.
	 *
	 * @param mixed  $response Dispatched response.
	 * @param string $secret   Secret in force.
	 * @param string $label    Failure context.
	 * @return void
	 */
	private function assert_no_leak( $response, $secret, $label ) {
		$this->assertInstanceOf( 'WP_REST_Response', $response, 'The route answered. ' . $label );

		$data = rest_get_server()->response_to_data( $response, false );

		$this->assertStringNotContainsString(
			$secret,
			(string) wp_json_encode( $data ),
			'The secret must not appear in the response body. ' . $label
		);

		$this->assertFalse(
			self::holds( $data, $secret ),
			'The secret must not appear in any response value. ' . $label
		);

		foreach ( (array) $response->get_headers() as $name => $value ) {
			$this->assertFalse(
				self::holds( array( $name, $value ), $secret ),
				'The secret must not appear in a response header. ' . $label
			);
		}
	}

	/**
	 * Whether a value, or anything nested inside it, holds a needle.
	 *
	 * @param mixed  $value  Value to search.
	 * @param string $needle Needle.
	 * @return bool
	 */
	private static function holds( $value, $needle ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $entry ) {
				if ( self::holds( (string) $key, $needle ) || self::holds( $entry, $needle ) ) {
					return true;
				}
			}

			return false;
		}

		if ( ! is_scalar( $value ) ) {
			return false;
		}

		return false !== strpos( (string) $value, (string) $needle );
	}

	/* ---------------------------------------------------------------------
	 * Dispatch
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch one intake request through the REST server.
	 *
	 * @param array       $payload   Field map to submit.
	 * @param string|null $presented Secret to present, or null to present none.
	 * @param string      $transport 'header' or 'query'.
	 * @param string      $encoding  'json' or 'form'.
	 * @return mixed WP_REST_Response.
	 */
	private function dispatch_intake( array $payload, $presented, $transport, $encoding ) {
		$request = new WP_REST_Request( 'POST', '/' . IntakeEndpoint::NAMESPACE . IntakeEndpoint::ROUTE );

		if ( 'json' === $encoding ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $payload ) );
		} else {
			$request->set_header( 'content-type', 'application/x-www-form-urlencoded' );
			$request->set_body( http_build_query( $payload ) );
		}

		if ( null !== $presented ) {
			if ( 'header' === $transport ) {
				$request->set_header( IntakeEndpoint::SECRET_HEADER, $presented );
			} else {
				$request->set_query_params( array( IntakeEndpoint::SECRET_QUERY => $presented ) );
			}
		}

		return rest_do_request( $request );
	}

	/**
	 * Dispatch one route from the namespace, presenting the secret both ways.
	 *
	 * @param int    $index   Which route, taken modulo the route count.
	 * @param string $secret  Stored secret.
	 * @param array  $payload Body to send.
	 * @return mixed WP_REST_Response.
	 */
	private function dispatch_namespace_route( $index, $secret, array $payload ) {
		$routes = $this->namespace_routes();
		$chosen = $routes[ abs( (int) $index ) % count( $routes ) ];

		// A named capture group stands for a concrete identifier in the path.
		$path = preg_replace( '/\(\?P<[^>]+>[^)]*\)/', '1', $chosen['route'] );

		$request = new WP_REST_Request( $chosen['method'], $path );

		$request->set_header( IntakeEndpoint::SECRET_HEADER, $secret );
		$request->set_query_params( array( IntakeEndpoint::SECRET_QUERY => $secret ) );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $payload ) );

		return rest_do_request( $request );
	}

	/**
	 * Every route the plugin's namespace holds, read back from the REST server.
	 *
	 * @return array<int,array{route:string,method:string}>
	 */
	private function namespace_routes() {
		if ( array() !== $this->namespace_routes ) {
			return $this->namespace_routes;
		}

		$prefix = '/' . IntakeEndpoint::NAMESPACE;
		$found  = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( 0 !== strpos( (string) $route, $prefix ) ) {
				continue;
			}

			foreach ( (array) $handlers as $handler ) {
				$methods = array_keys( array_filter( (array) $handler['methods'] ) );

				if ( array() === $methods ) {
					continue;
				}

				$found[] = array(
					'route'  => (string) $route,
					'method' => (string) $methods[0],
				);
			}
		}

		$this->assertNotEmpty( $found, 'The namespace should hold at least the intake route.' );

		$this->namespace_routes = $found;

		return $this->namespace_routes;
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One case: the stored secret, how the request presents it, on which
	 * transport, in which body encoding, plus the payload and the namespace route
	 * the no-leak claim is checked against.
	 *
	 * `oneOf` over two branches makes roughly half the run present the secret
	 * exactly, so both halves of the property get comparable coverage.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'secret'    => self::secret(),
				'mutation'  => \Eris\Generators::oneOf(
					\Eris\Generators::constant( 'exact' ),
					\Eris\Generators::elements( self::REFUSED )
				),
				'transport' => \Eris\Generators::elements( self::TRANSPORTS ),
				'encoding'  => \Eris\Generators::elements( self::ENCODINGS ),
				'payload'   => Generators::enquiry(),
				'route'     => \Eris\Generators::choose( 0, 99 ),
			)
		);
	}

	/**
	 * A stored intake secret.
	 *
	 * Every shape is ASCII, at least twelve characters long, holds a lower-case
	 * segment and carries no quote or backslash. The length and the distinctive
	 * `sk-` opening are what make the no-leak scan meaningful: a short or common
	 * value would match somewhere in every response by chance. The lower-case
	 * segment is what makes the case-change mutation a genuine change whatever
	 * else was drawn.
	 *
	 * @return \Eris\Generator
	 */
	protected static function secret() {
		return \Eris\Generators::map(
			static function ( array $parts ) {
				list( $seed, $shape ) = $parts;

				$seed = (string) $seed;

				switch ( $shape ) {
					case 'mixed_case':
						return 'sk-' . strtoupper( substr( md5( $seed ), 0, 10 ) ) . '-' . substr( md5( $seed . 'b' ), 0, 10 );

					case 'punctuated':
						return 'sk-' . substr( md5( $seed ), 0, 10 ) . '!%*' . substr( md5( $seed . 'c' ), 0, 8 );

					case 'long':
						return 'sk-' . str_repeat( substr( sha1( $seed ), 0, 8 ), 8 );

					case 'digits':
						return 'sk-' . str_pad( $seed, 24, '7', STR_PAD_LEFT );

					default:
						return 'sk-' . sha1( $seed );
				}
			},
			\Eris\Generators::tuple(
				\Eris\Generators::choose( 1, 999999999 ),
				\Eris\Generators::elements( self::SECRET_SHAPES )
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a wide structure by taking the cartesian product of every
	 * element's shrink options, so the cost of one shrink step grows
	 * exponentially with the number of generated values. A case here draws a
	 * secret, four choices and a nine-field enquiry, two of whose fields are
	 * sets, which puts that product beyond what fits in memory: a failing
	 * iteration would report an out-of-memory fatal instead of the
	 * counterexample.
	 *
	 * Binding the drawn value to a constant generator makes shrinking a no-op, so
	 * a failure reports the case as generated, together with the assertion's own
	 * diff and the `ERIS_SEED` line that reproduces the run exactly.
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
	 * The value the request presents, for one mutation of the stored secret.
	 *
	 * @param string $stored   Stored secret.
	 * @param string $mutation One of self::REFUSED, or 'exact'.
	 * @return string|null Null presents no secret at all.
	 */
	private static function presented( $stored, $mutation ) {
		switch ( $mutation ) {
			case 'absent':
				return null;

			case 'empty':
				return '';

			case 'whitespace_only':
				return '   ';

			case 'prefix':
				return 'x' . $stored;

			case 'suffix':
				return $stored . 'x';

			case 'case_change':
				return strtoupper( $stored ) === $stored ? strtolower( $stored ) : strtoupper( $stored );

			case 'surrounding_whitespace':
				return ' ' . $stored . ' ';

			case 'interior_whitespace':
				return substr( $stored, 0, 4 ) . ' ' . substr( $stored, 4 );

			case 'truncated':
				return substr( $stored, 0, -1 );

			case 'unrelated':
				return 'sk-' . strrev( $stored ) . '-other';

			default:
				return $stored;
		}
	}

	/**
	 * The transport a mutation is presented on.
	 *
	 * Surrounding whitespace is pinned to the query parameter: a request header
	 * cannot carry leading or trailing whitespace, because the receiving server
	 * strips it before PHP sees the value, so presenting a padded header would
	 * test something no sender can send.
	 *
	 * @param string $mutation Mutation in play.
	 * @param string $drawn    Transport drawn for this case.
	 * @return string
	 */
	private static function transport_for( $mutation, $drawn ) {
		return 'surrounding_whitespace' === $mutation ? 'query' : $drawn;
	}

	/**
	 * A one-line description of the case, for failure messages.
	 *
	 * @param array  $case      Generated case.
	 * @param string $transport Transport in use.
	 * @param bool   $accepted  Whether the oracle expects acceptance.
	 * @return string
	 */
	private static function label( array $case, $transport, $accepted ) {
		return sprintf(
			'[secret %s, presented %s via %s, %s body, expected %s]',
			(string) $case['secret'],
			(string) $case['mutation'],
			$transport,
			(string) $case['encoding'],
			$accepted ? '201' : '401'
		);
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * The submission, with an address unique to this iteration.
	 *
	 * Uniqueness keeps the duplicate guard and the rate limiter out of the way, so
	 * an authenticated request is refused only by the thing this property is
	 * about.
	 *
	 * @param array $fields Generated field map.
	 * @return array
	 */
	private function payload( array $fields ) {
		++$this->iteration;

		$fields['email'] = sprintf( 'intake-auth-%d@example.com', $this->iteration );

		$this->counted[] = $fields['email'];

		return $fields;
	}

	/**
	 * Register the plugin's routes and hand the REST server a fresh start.
	 *
	 * @return void
	 */
	private function boot_rest_server() {
		global $wp_rest_server;

		$wp_rest_server         = null;
		$this->namespace_routes = array();

		add_action( 'rest_api_init', array( IntakeEndpoint::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( RestEnquiries::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( RestBookings::class, 'register_routes' ) );

		rest_get_server();
	}

	/**
	 * One enquiry and one rejection row, so the row counts this property watches
	 * are non-zero and a stray write shows up as a change rather than as a zero
	 * matching a zero.
	 *
	 * @return void
	 */
	private function seed_existing_rows() {
		$now = Clock::mysql();

		$id = EnquiryStore::create(
			array(
				'first_name'        => 'Ada',
				'last_name'         => 'Lovelace',
				'email'             => 'seeded@example.com',
				'status'            => 'new',
				'created_at'        => $now,
				'updated_at'        => $now,
				'status_changed_at' => $now,
				'source'            => 'webhook:fixture',
			),
			array( Generators::date_at( 30 ) ),
			array( 'event_type' => array( 'wedding' ) ),
			array( 'seeded' => true )
		);

		$this->assertNotWPError( $id, 'Seeding an enquiry should succeed.' );

		$this->seeded_id  = (int) $id;
		$this->seeded_row = EnquiryStore::find( $this->seeded_id );

		$this->assertGreaterThan(
			0,
			EnquiryStore::record_rejection( array( 'seeded' => true ), 'validation' ),
			'Seeding a rejection row should succeed.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Database helpers
	 * ------------------------------------------------------------------ */

	/**
	 * The row count of every Enquiry Store table.
	 *
	 * @return array<string,int>
	 */
	private function row_counts() {
		global $wpdb;

		$counts = array();

		foreach ( array_keys( Schema::TABLES ) as $key ) {
			$table          = Schema::table( $key );
			$counts[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
		}

		return $counts;
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
