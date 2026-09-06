<?php
/**
 * Property 24: Closed enquiries are frozen but readable.
 *
 * Feature: enquiry-data-layer, Property 24: For any enquiry holding status
 * `closed`, any write route — the status route, the note route, the enquiry edit
 * route `PATCH /enquiries/{id}`, the conversion route and the CRM retry route —
 * and any request body, valid or invalid, the API responds 409 and the enquiry's
 * stored fields, candidate dates, terms, notes and history are byte-identical
 * afterwards; and the list and single-enquiry read routes continue to return that
 * enquiry's stored values, notes and history in full.
 *
 * **Validates: Requirements 9.1, 9.2, 19.12**
 *
 * How the property is instantiated, and why:
 *
 * - **Five frozen routes, not six.** `POST /enquiries/{id}/duplicate` is
 *   deliberately absent from the quantification. Re-raising a closed enquiry is
 *   the case Requirement 9 exists for — a past enquirer coming back, with the
 *   original left frozen — so that route carries a 404-only guard by design and
 *   answering 409 there would refuse the very request the requirement is about.
 *   Property 25 owns it. What is quantified here is exactly the set Property 24
 *   names: the status route, the note route, the conversion route, the CRM retry
 *   route, and the edit route.
 * - **Every refusal goes through the real route.** Each iteration dispatches all
 *   five with `rest_do_request()` as an authenticated administrator, so the
 *   permission callback, the declared args, each route's digits-only identifier
 *   pattern and the method-based handler pick for `PATCH` all run where WordPress
 *   runs them. Calling the callbacks directly would leave the args — and
 *   therefore whether core's own 400 for a malformed arg pre-empts the guard —
 *   unquantified.
 * - **The refusal is pinned to the guard's own code, not to the status alone.**
 *   Two of these routes have a 409 of their own: the status route passes through
 *   `Lifecycle`'s refusal of an unpermitted move, and the conversion route
 *   answers 409 for an enquiry that already holds a booking. Asserting only the
 *   status would therefore be satisfied by a controller that never ran the closed
 *   guard at all, so every refusal is asserted to carry
 *   `RestEnquiries::CLOSED_CODE`.
 * - **Bodies are generated valid *and* invalid, and that is Requirement 19.12.**
 *   An invalid body is one each route would otherwise refuse with 400 on its own
 *   terms: an unrecognised status, a note holding nothing once tags are stripped,
 *   a date the enquirer never offered, and — the edit route's case — an
 *   unparseable email address with an out-of-range guest count. The assertion is
 *   409 rather than 400, which is what makes "the guard runs before the validator"
 *   a property of the HTTP surface rather than a claim about call order.
 * - **"Byte-identical" is asserted as a whole-row comparison**, not as a list of
 *   fields: the hydrated enquiry is read before the five requests and after them,
 *   and the two must be identical — which covers the candidate dates, both
 *   taxonomies, `updated_at`, `status_changed_at`, `booking_id` and
 *   `crm_sync_state` in one assertion, including a column this test did not think
 *   to name. Notes and history are compared verbatim as well, entries and all,
 *   rather than as counts.
 * - **The clock moves between seeding and the requests.** Every fixture is
 *   written at self::SEEDED_AT and every request is made at self::REQUEST_AT, so a
 *   write that slipped past the guard would move `updated_at` or
 *   `status_changed_at` to a value the row comparison cannot miss. A freeze at one
 *   instant throughout would let a stray `UPDATE` write the value that was already
 *   there.
 * - **Reading is asserted after the five refusals** (Requirement 9.2), on both
 *   read routes: `GET /enquiries/{id}` answers 200 and its representation carries
 *   the stored field values, the candidate dates, the terms, every note and every
 *   history entry, and `GET /enquiries` still lists the enquiry.
 * - **The case generator is wide**, and Eris shrinks a wide composite generator
 *   by building the cartesian product of every component's alternatives, which
 *   exhausts memory before it reports anything. Every case is therefore drawn
 *   through `unshrunk()`, so a failure is reported exactly as it was generated,
 *   with the `ERIS_SEED` line that reproduces the run.
 *
 * Like the other store-backed tests, this one works against real tables at its
 * own prefix, because `Schema::install()` verifies each table through
 * `SHOW TABLES`, which cannot see the test case's temporary tables.
 *
 * @package MarthrownEnquiryHub
 */

use Eris\TestTrait;
use MarthrownEnquiryHub\Auth;
use MarthrownEnquiryHub\Clock;
use MarthrownEnquiryHub\EnquiryStore;
use MarthrownEnquiryHub\HistoryRecorder;
use MarthrownEnquiryHub\NoteService;
use MarthrownEnquiryHub\RestEnquiries;
use MarthrownEnquiryHub\Schema;
use MarthrownEnquiryHub\Tests\Fakes\FakeCrm;
use MarthrownEnquiryHub\Tests\Generators;
use MarthrownEnquiryHub\Tests\Iterations;

/**
 * Class ClosedEnquiryFreezePropertyTest
 */
class ClosedEnquiryFreezePropertyTest extends WP_UnitTestCase {

	use TestTrait;

	/**
	 * Extra prefix segment, so these tables can never collide with a real
	 * Enquiry Store table, or with another test's fixture, in the same database.
	 */
	const PREFIX_SEGMENT = 'mehfr_';

	/**
	 * The instant every fixture is written at.
	 */
	const SEEDED_AT = '2025-04-02 11:20:00';

	/**
	 * The instant every request is made at.
	 *
	 * Later than self::SEEDED_AT on purpose: a write that slipped past the guard
	 * would move a timestamp to this value, which the whole-row comparison catches.
	 */
	const REQUEST_AT = '2025-10-06 16:45:00';

	/**
	 * The status the frozen enquiry holds (Requirements 9.1, 9.2).
	 */
	const CLOSED = 'closed';

	/**
	 * The `source` every seeded enquiry carries.
	 */
	const SOURCE = 'webhook:freeze-fixture';

	/**
	 * The value stored in every seeded payload snapshot.
	 */
	const PAYLOAD = 'freeze-payload-value';

	/**
	 * The subscriber identifier the CRM would resolve an address to.
	 *
	 * Never reached: the retry route is refused by the guard. Pinned anyway so a
	 * retry that did slip through would write a value this test can recognise
	 * rather than one that happened to match what was stored.
	 */
	const CRM_SUBSCRIBER = 909;

	/**
	 * The candidate date no generated enquiry offers.
	 *
	 * `Generators::candidate_dates()` counts forward from its own fixed base date,
	 * so a date well before it can never be among an enquiry's candidates. That is
	 * what makes the conversion route's invalid body genuinely invalid.
	 */
	const NON_CANDIDATE = '2019-12-25';

	/**
	 * The five write routes a closed enquiry freezes.
	 *
	 * `duplicate` is not among them, and its absence is the design: re-raising a
	 * closed enquiry is the case Requirement 9 exists for, so that route carries
	 * no closed check and Property 25 covers it.
	 *
	 * @var array<string,string>
	 */
	const FROZEN_ROUTES = array(
		'status'    => 'POST',
		'notes'     => 'POST',
		'convert'   => 'POST',
		'retry-crm' => 'POST',
		'edit'      => 'PATCH',
	);

	/** Most candidate dates one generated enquiry holds. */
	const DATES_MAX = 4;

	/** Most notes and history entries one generated enquiry holds. */
	const ENTRIES_MAX = 2;

	/**
	 * The administrator every request is made as.
	 *
	 * @var int
	 */
	private static $user_id = 0;

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
	 * The CRM fake, standing in for FluentCRM.
	 *
	 * @var FakeCrm|null
	 */
	private $crm = null;

	/**
	 * Load the classes under test, and create the calling user.
	 *
	 * The user is created before any prefix switch, so it lands in the real users
	 * table rather than in a fixture one.
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
		require_once MEH_INCLUDES_DIR . 'class-staging-marker.php';
		require_once MEH_INCLUDES_DIR . 'class-contact-linker.php';
		require_once MEH_INCLUDES_DIR . 'class-field-mapper.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-validator.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-enquiry-editor.php';
		require_once MEH_INCLUDES_DIR . 'class-source-wpbs.php';
		require_once MEH_INCLUDES_DIR . 'class-booking-creator.php';
		require_once MEH_INCLUDES_DIR . 'class-rest-enquiries.php';

		self::$user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
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

		update_option( 'meh_enquiry_list', 7 );
		update_option( 'meh_enquiry_tag', 12 );

		$this->crm = FakeCrm::install();

		Clock::freeze( self::SEEDED_AT );
		wp_set_current_user( self::$user_id );

		// A server of this test's own, so the route table holds what this test
		// registered and nothing a previous test left behind.
		$this->original_server = $wp_rest_server;
		$wp_rest_server        = new WP_REST_Server();

		RestEnquiries::init();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->assertTrue( Auth::rest_permission(), 'The calling user should be admitted by the routes.' );
	}

	public function tear_down() {
		global $wpdb, $wp_rest_server;

		FakeCrm::uninstall();
		$this->crm = null;

		delete_option( 'meh_enquiry_list' );
		delete_option( 'meh_enquiry_tag' );

		$wp_rest_server = $this->original_server;

		wp_set_current_user( 0 );
		Clock::unfreeze();

		$this->drop_tables();

		$wpdb->prefix = $this->original_prefix;

		parent::tear_down();
	}

	/**
	 * Feature: enquiry-data-layer, Property 24: Closed enquiries are frozen but
	 * readable.
	 *
	 * **Validates: Requirements 9.1, 9.2, 19.12**
	 */
	public function test_closed_enquiries_are_frozen_but_readable() {
		$this->limitTo( Iterations::count( 60 ) )
			->forAll( self::unshrunk( self::scenario() ) )
			->then(
				function ( array $case ) {
					$this->check_freeze( $case );
				}
			);
	}

	/* ---------------------------------------------------------------------
	 * The claim
	 * ------------------------------------------------------------------ */

	/**
	 * One closed enquiry, offered every frozen write and then read back.
	 *
	 * @param array $case Generated case.
	 * @return void
	 */
	private function check_freeze( array $case ) {
		$id    = $this->seed( $case );
		$label = $this->label( $case, $id );

		$before  = EnquiryStore::find( $id );
		$notes   = NoteService::for_enquiry( $id );
		$history = HistoryRecorder::for_enquiry( $id );

		$this->assertSame( self::CLOSED, $before['status'], 'The fixture should be closed. ' . $label );

		$this->crm->reset()->will_succeed()->assign_subscriber_id( (string) $before['email'], self::CRM_SUBSCRIBER );

		Clock::freeze( self::REQUEST_AT );

		// Requirements 9.1, 19.12: every frozen route refuses, with the guard's
		// own code, whether the body would have validated or not.
		foreach ( array_keys( self::FROZEN_ROUTES ) as $route ) {
			$response = $this->write( $id, $route, $this->body( $route, $case ) );
			$data     = (array) $response->get_data();

			$this->assertSame(
				409,
				$response->get_status(),
				$route . ' is refused with 409. ' . $label
			);
			$this->assertSame(
				RestEnquiries::CLOSED_CODE,
				isset( $data['code'] ) ? (string) $data['code'] : '',
				$route . ' is refused by the closed guard rather than on its own terms. ' . $label
			);
		}

		// Requirement 9.1: the enquiry, its dates, its terms, its notes and its
		// history are as they were, to the byte.
		$this->assertSame( $before, EnquiryStore::find( $id ), 'The closed enquiry is unchanged. ' . $label );
		$this->assertSame( $notes, NoteService::for_enquiry( $id ), 'The notes are unchanged. ' . $label );
		$this->assertSame( $history, HistoryRecorder::for_enquiry( $id ), 'The history is unchanged. ' . $label );

		// Requirement 9.2: reading still works, and returns everything.
		$this->check_readable( $id, $before, $notes, $history, $label );
	}

	/**
	 * The two read routes, against the enquiry the five writes could not touch.
	 *
	 * @param int    $id      Enquiry identifier.
	 * @param array  $stored  The hydrated enquiry as stored.
	 * @param array  $notes   The stored notes, most recent first.
	 * @param array  $history The stored history, oldest first.
	 * @param string $label   Case description.
	 * @return void
	 */
	private function check_readable( $id, array $stored, array $notes, array $history, $label ) {
		$response = $this->get( '/enquiries/' . (int) $id );
		$single   = (array) $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'A closed enquiry is still readable. ' . $label );

		foreach ( array( 'id', 'first_name', 'last_name', 'email', 'phone', 'total_guests', 'message', 'status', 'created_at', 'booking_id' ) as $field ) {
			$this->assertSame(
				$stored[ $field ],
				$single[ $field ],
				'The read route returns the stored ' . $field . '. ' . $label
			);
		}

		foreach ( array( 'selected_dates', 'event_type', 'site_exclusivity' ) as $field ) {
			$this->assertSame(
				self::as_set( $stored[ $field ] ),
				self::as_set( $single[ $field ] ),
				'The read route returns the stored ' . $field . '. ' . $label
			);
		}

		$this->assertSame(
			wp_list_pluck( $notes, 'body' ),
			wp_list_pluck( (array) $single['notes'], 'body' ),
			'The read route returns every note. ' . $label
		);
		$this->assertSame(
			wp_list_pluck( $history, 'entry_type' ),
			wp_list_pluck( (array) $single['history'], 'entry_type' ),
			'The read route returns every history entry. ' . $label
		);

		$response = $this->get( '/enquiries' );
		$listed   = (array) $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'The list route still answers. ' . $label );
		$this->assertContains(
			(int) $id,
			array_map( 'intval', wp_list_pluck( (array) $listed['items'], 'id' ) ),
			'The list route still lists the closed enquiry. ' . $label
		);
	}

	/* ---------------------------------------------------------------------
	 * Generators
	 * ------------------------------------------------------------------ */

	/**
	 * One closed enquiry: its field values, its dates and terms, its CRM state,
	 * its booking, how many notes and history entries it holds, and whether the
	 * bodies offered to the frozen routes are ones those routes would otherwise
	 * have accepted.
	 *
	 * @return \Eris\Generator
	 */
	protected static function scenario() {
		return \Eris\Generators::associative(
			array(
				'first_name'       => Generators::first_name(),
				'last_name'        => Generators::last_name(),
				'email'            => Generators::email(),
				'phone'            => Generators::phone_or_empty(),
				'total_guests'     => Generators::total_guests_or_unsupplied(),
				'message'          => Generators::message_or_empty(),
				'dates'            => Generators::candidate_dates( 1, self::DATES_MAX ),
				'event_type'       => Generators::term_set( 0, 3 ),
				'site_exclusivity' => Generators::term_set( 0, 2 ),
				'subscriber'       => \Eris\Generators::elements( array( 0, 501 ) ),
				'crm_state'        => \Eris\Generators::elements( array( '', 'pending', 'synced' ) ),
				'booking'          => \Eris\Generators::elements( array( 0, 9 ) ),
				'is_test'          => \Eris\Generators::elements( array( true, false ) ),
				'notes'            => \Eris\Generators::choose( 0, self::ENTRIES_MAX ),
				'entries'          => \Eris\Generators::choose( 0, self::ENTRIES_MAX ),
				'valid_body'       => \Eris\Generators::elements( array( true, false ) ),
				'new_name'         => Generators::first_name(),
			)
		);
	}

	/**
	 * The same generator, with shrinking turned off.
	 *
	 * Eris shrinks a composite generator by taking the cartesian product of every
	 * component's alternatives. For a case as wide as this one that product is
	 * large enough to exhaust the process' memory, so a genuine failure would be
	 * reported as an out-of-memory error rather than as a counterexample. Binding
	 * the drawn value to a constant generator makes shrinking a no-op, so the
	 * failing case is reported exactly as it was generated, alongside the
	 * `ERIS_SEED` line that reproduces it.
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
	 * The routes
	 * ------------------------------------------------------------------ */

	/**
	 * The body one frozen route is offered.
	 *
	 * A valid body is one the route would have accepted from an open enquiry; an
	 * invalid one is what that route refuses with 400 on its own terms — which is
	 * exactly why the answer has to be 409 instead (Requirement 19.12). The retry
	 * route takes no parameters, so it carries the same empty body either way.
	 *
	 * @param string $route Route key from self::FROZEN_ROUTES.
	 * @param array  $case  Generated case.
	 * @return array
	 */
	private function body( $route, array $case ) {
		$valid = (bool) $case['valid_body'];
		$dates = array_values( (array) $case['dates'] );

		switch ( $route ) {
			case 'status':
				// A recognised status, or one no vocabulary holds.
				return array( 'status' => $valid ? 'contacted' : 'not-a-status' );

			case 'notes':
				// A note, or a body holding nothing once its tags are stripped.
				return array( 'body' => $valid ? 'Rang back about next season.' : '<p>   </p>' );

			case 'convert':
				return array(
					'calendar_id' => 1,
					'date'        => $valid ? $dates[0] : self::NON_CANDIDATE,
				);

			case 'edit':
				// A correction the Manual profile accepts, or one it refuses on
				// two counts at once.
				return $valid
					? array( 'first_name' => (string) $case['new_name'] )
					: array(
						'email'        => 'not-an-email',
						'total_guests' => 0,
					);

			default:
				return array();
		}
	}

	/**
	 * Dispatch one write through the real route.
	 *
	 * @param int    $id    Enquiry identifier.
	 * @param string $route Route key from self::FROZEN_ROUTES.
	 * @param array  $body  JSON body to send.
	 * @return \WP_REST_Response
	 */
	private function write( $id, $route, array $body ) {
		$path = '/' . RestEnquiries::NAMESPACE . '/enquiries/' . (int) $id;

		if ( 'edit' !== $route ) {
			$path .= '/' . $route;
		}

		$request = new WP_REST_Request( self::FROZEN_ROUTES[ $route ], $path );

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/**
	 * Dispatch one read through the real route.
	 *
	 * @param string $path Path under the plugin namespace.
	 * @return \WP_REST_Response
	 */
	private function get( $path ) {
		return rest_do_request( new WP_REST_Request( 'GET', '/' . RestEnquiries::NAMESPACE . $path ) );
	}

	/* ---------------------------------------------------------------------
	 * Fixtures
	 * ------------------------------------------------------------------ */

	/**
	 * Write the generated closed enquiry and return its identifier.
	 *
	 * @param array $case Generated case.
	 * @return int
	 */
	private function seed( array $case ) {
		$this->clear();

		Clock::freeze( self::SEEDED_AT );

		$id = EnquiryStore::create(
			array(
				'first_name'              => $case['first_name'],
				'last_name'               => $case['last_name'],
				'email'                   => $case['email'],
				'phone'                   => $case['phone'],
				'total_guests'            => $case['total_guests'],
				'message'                 => $case['message'],
				'status'                  => self::CLOSED,
				'crm_sync_state'          => $case['crm_state'],
				'fluentcrm_subscriber_id' => $case['subscriber'] > 0 ? (int) $case['subscriber'] : null,
				'booking_id'              => $case['booking'] > 0 ? (int) $case['booking'] : null,
				'created_at'              => self::SEEDED_AT,
				'updated_at'              => self::SEEDED_AT,
				'status_changed_at'       => self::SEEDED_AT,
				'source'                  => self::SOURCE,
				'is_test'                 => $case['is_test'] ? 1 : 0,
			),
			(array) $case['dates'],
			array(
				'event_type'       => (array) $case['event_type'],
				'site_exclusivity' => (array) $case['site_exclusivity'],
			),
			array( 'submitted' => self::PAYLOAD )
		);

		$this->assertIsInt( $id, 'Seeding the closed enquiry should succeed.' );

		$id = (int) $id;

		for ( $index = 0; $index < (int) $case['notes']; $index++ ) {
			$this->assertIsInt(
				NoteService::add( $id, 'Spoke to the enquirer, round ' . $index . '.', self::$user_id ),
				'Seeding a note should succeed.'
			);
		}

		for ( $index = 0; $index < (int) $case['entries']; $index++ ) {
			HistoryRecorder::record( $id, 'created', 'Seeded entry ' . $index . '.', array(), self::$user_id );
		}

		return $id;
	}

	/**
	 * A description of the case, for failure messages.
	 *
	 * @param array $case Generated case.
	 * @param int   $id   Enquiry identifier.
	 * @return string
	 */
	private function label( array $case, $id ) {
		return 'Case: ' . (string) wp_json_encode(
			array(
				'enquiry_id' => (int) $id,
				'valid_body' => (bool) $case['valid_body'],
				'subscriber' => (int) $case['subscriber'],
				'crm_state'  => $case['crm_state'],
				'booking'    => (int) $case['booking'],
				'dates'      => count( (array) $case['dates'] ),
				'terms'      => count( (array) $case['event_type'] ) + count( (array) $case['site_exclusivity'] ),
				'notes'      => (int) $case['notes'],
				'entries'    => (int) $case['entries'],
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * A set-valued field as a comparable set: distinct values, sorted.
	 *
	 * @param mixed $values Stored values.
	 * @return array
	 */
	private static function as_set( $values ) {
		$set = array_values( array_unique( array_map( 'strval', (array) $values ) ) );

		sort( $set );

		return $set;
	}

	/**
	 * Empty the tables this property writes, between iterations.
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

			if ( '' === $table ) {
				continue;
			}

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
