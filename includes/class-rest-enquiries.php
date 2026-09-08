<?php
/**
 * REST: Event Enquiries, read from the Enquiry Store.
 *
 * The Enquiry Store, not FluentCRM, is the source of record. This controller
 * reads it and writes nothing of its own: filter translation belongs to
 * `EnquiryQuery`, the statements belong to `EnquiryStore`, the transition table
 * belongs to `Lifecycle`. What is left here is the HTTP surface — route
 * registration, parameter sanitisation, the writable guard and the mapping from
 * a store result to a response.
 *
 * Routes live under `marthrown-enquiry-hub/v1`, and every one of them uses
 * `Auth::rest_permission` as its `permission_callback` (Requirements 16.1,
 * 16.2). The intake route registered by `IntakeEndpoint` is the single
 * documented exception in the namespace, and authenticates the Intake Secret
 * instead (Requirement 16.9). Every declared arg carries a `sanitize_callback`,
 * so no request value reaches the store unsanitised (Requirement 16.7).
 *
 * Registered here:
 *
 *   GET   /enquiries                 — paginated, filtered list with counts
 *   POST  /enquiries                 — create one enquiry by hand
 *   GET   /enquiries/{id}            — one enquiry with everything known about it
 *   PATCH /enquiries/{id}            — correct stored field values
 *   POST /enquiries/{id}/status      — apply a lifecycle transition
 *   POST /enquiries/{id}/notes       — add an internal note
 *   POST /enquiries/{id}/duplicate   — re-raise as a new enquiry
 *   POST /enquiries/{id}/convert     — create a WP Booking System booking
 *   POST /enquiries/{id}/retry-crm   — retry contact linkage
 *   GET  /enquiries/rejections       — rejected intake attempts, with reason and detail
 *   POST /enquiries/migration        — run or preview the FluentCRM migration
 *   DELETE /enquiries/test-records   — delete every staging enquiry
 *
 * Every write route that acts on an existing enquiry resolves it through
 * `guard_writable()` before it does anything else, so closed-enquiry
 * immutability is stated once rather than repeated per route (Requirement 9.1).
 * Manual creation names no enquiry, so there is nothing for it to guard, and the
 * re-raise route deliberately skips the closed check for the reason its own
 * docblock gives. Each of them then makes exactly one call
 * to the component that owns the work — `Lifecycle`, `NoteService`,
 * `EnquiryStore::duplicate()`, `BookingCreator`, `ContactLinker` — and returns
 * that component's own `WP_Error` untouched when it fails, because those errors
 * already carry the HTTP status the requirement asks for. Nothing here decides
 * whether a transition is legal, how long a note may be, or what a booking looks
 * like.
 *
 * Core's cookie authentication supplies the `X-WP-Nonce` check every one of
 * these routes depends on as a state-changing request (Requirement 16.5); there
 * is nothing for the controller to add.
 *
 * The two administrative routes — the migration run and the test-record delete —
 * are the one place a route asks for more than `Auth::rest_permission`. They go
 * through `admin_permission()`, which requires that callback *and*
 * `current_user_can( 'manage_options' )` (Requirements 15.13, 16.6). The
 * rejections list is not administrative: Requirement 4.8 exposes it to every user
 * Auth authorizes, because finding a genuine enquiry that was turned away is
 * ordinary hub work rather than site administration.
 *
 * The edit route is `PATCH` rather than `POST` on the single-enquiry path,
 * because its body is a partial representation of an existing resource: the
 * method carries the partial-update semantics of Requirement 19.7 rather than
 * leaving them to a route name.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RestEnquiries
 */
class RestEnquiries {

	/**
	 * REST namespace, shared with every other route the plugin registers
	 * (Requirement 16.1).
	 */
	const NAMESPACE = 'marthrown-enquiry-hub/v1';

	/**
	 * The collection route.
	 */
	const ROUTE_COLLECTION = '/enquiries';

	/**
	 * The single-enquiry route.
	 *
	 * The identifier is constrained to digits so this pattern claims only
	 * numeric paths, leaving `/enquiries/rejections` and `/enquiries/migration`
	 * to match their own routes rather than arriving here as an identifier
	 * nothing could resolve.
	 */
	const ROUTE_SINGLE = '/enquiries/(?P<id>[\d]+)';

	/**
	 * The status-transition route.
	 *
	 * Every sub-route is built from self::ROUTE_SINGLE, so the digits-only
	 * identifier pattern is written once and no sub-route can drift into
	 * claiming a path the single-enquiry route would not.
	 */
	const ROUTE_STATUS = self::ROUTE_SINGLE . '/status';

	/**
	 * The note route.
	 */
	const ROUTE_NOTES = self::ROUTE_SINGLE . '/notes';

	/**
	 * The re-raise route (Requirement 9.3).
	 */
	const ROUTE_DUPLICATE = self::ROUTE_SINGLE . '/duplicate';

	/**
	 * The conversion route (Requirement 14.1).
	 */
	const ROUTE_CONVERT = self::ROUTE_SINGLE . '/convert';

	/**
	 * The contact-linkage retry route (Requirement 5.4).
	 */
	const ROUTE_RETRY_CRM = self::ROUTE_SINGLE . '/retry-crm';

	/**
	 * The rejected-intake-attempt route (Requirement 4.8).
	 *
	 * A literal path under `/enquiries`, which it can be because
	 * self::ROUTE_SINGLE claims digits only: `rejections` is not a number, so a
	 * request for this path reaches this route rather than arriving at the
	 * single-enquiry callback as an identifier nothing could resolve.
	 */
	const ROUTE_REJECTIONS = '/enquiries/rejections';

	/**
	 * The migration route (Requirement 15.13).
	 */
	const ROUTE_MIGRATION = '/enquiries/migration';

	/**
	 * The staging clean-up route (Requirement 17.7).
	 */
	const ROUTE_TEST_RECORDS = '/enquiries/test-records';

	/**
	 * The capability the two administrative routes require on top of Auth
	 * (Requirements 15.13, 16.6, 17.7).
	 */
	const ADMIN_CAPABILITY = 'manage_options';

	/**
	 * Error code answered when a user Auth authorized lacks
	 * self::ADMIN_CAPABILITY.
	 */
	const FORBIDDEN_CODE = 'meh_forbidden';

	/**
	 * The history entry type a re-raise appends to both enquiries.
	 */
	const DUPLICATED_HISTORY_TYPE = 'duplicated';

	/**
	 * Reported when a copy was made but its contact could not be linked.
	 *
	 * The copy stands: `ContactLinker` has left it `pending` and the retry route
	 * exists precisely so a linkage failure is recoverable (Requirement 5.4), so
	 * discarding a stored enquiry over a FluentCRM fault would lose business for
	 * no gain.
	 */
	const CRM_PENDING_WARNING = 'meh_enquiry_crm_pending';

	/**
	 * Recognised enquiry statuses.
	 *
	 * A constant because the admin bootstrap hands the status set to the React
	 * app before any request is made. `statuses()` is the reader every runtime
	 * decision goes through, and it prefers `Lifecycle::STATUSES` so the two
	 * cannot drift.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'new', 'contacted', 'quoted', 'converted', 'lost', 'closed' );

	/**
	 * The status no write route may act on (Requirement 9.1).
	 */
	const STATUS_CLOSED = 'closed';

	/**
	 * Error code answered with 404 when an identifier matches no enquiry.
	 */
	const NOT_FOUND_CODE = 'meh_enquiry_not_found';

	/**
	 * Error code answered with 409 when an enquiry is closed.
	 */
	const CLOSED_CODE = 'meh_enquiry_closed';

	/**
	 * Error code answered with 500 when a stored row is missing a field the
	 * representation cannot be built without (Requirement 13.5).
	 */
	const INCOMPLETE_CODE = 'meh_enquiry_incomplete';

	/**
	 * Error code answered with 400 when a submission fails validation
	 * (Requirements 18.3, 18.6).
	 *
	 * The per-field failures travel in the error data under `errors`, so one
	 * response names every field that has to be corrected rather than the first.
	 */
	const INVALID_CODE = 'meh_invalid_enquiry';

	/**
	 * Error code answered with 500 when the store refused a write that had
	 * already passed validation.
	 */
	const STORE_FAILURE_CODE = 'meh_store_failure';

	/**
	 * Prefix of the `source` a manually created enquiry carries, completed with
	 * the identifier of the submitting user (Requirement 18.8).
	 */
	const MANUAL_SOURCE_PREFIX = 'manual:';

	/**
	 * The stored fields a single-enquiry representation cannot be built without
	 * (Requirement 13.5).
	 *
	 * `email` is the contact identity every linkage and sibling lookup keys on,
	 * `status` decides what the hub may do with the enquiry, and `created_at` is
	 * what orders it. A row missing any of them is a fault in the data rather
	 * than an enquiry with a blank field, so it is reported rather than rendered.
	 *
	 * @var string[]
	 */
	const REQUIRED_FIELDS = array( 'email', 'status', 'created_at' );

	/**
	 * Attribution used for a note or history entry carrying no user.
	 */
	const SYSTEM_ACTOR_NAME = 'System';

	/**
	 * Header carrying the total matching enquiry count (Requirement 12.11).
	 */
	const TOTAL_HEADER = 'X-WP-Total';

	/**
	 * Header carrying the total page count (Requirement 12.11).
	 */
	const TOTAL_PAGES_HEADER = 'X-WP-TotalPages';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register every enquiry route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_COLLECTION,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_enquiries' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => self::list_args(),
			)
		);

		// The same path as the list, registered separately so each method
		// declares its own args: core keeps both handlers on `/enquiries` and
		// picks by method (Requirements 18.1, 18.21).
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_COLLECTION,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_enquiry' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => self::create_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_SINGLE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_enquiry' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => self::single_args(),
			)
		);

		// The same path as the single-enquiry read, registered separately so each
		// method declares its own args: core keeps both handlers on the route and
		// picks by method (Requirements 19.1, 19.19).
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_SINGLE,
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'edit_enquiry' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => self::edit_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_STATUS,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_status' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => self::status_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_NOTES,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'add_note' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => self::note_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_DUPLICATE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'duplicate_enquiry' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => self::single_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_CONVERT,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'convert_enquiry' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => self::convert_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_RETRY_CRM,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'retry_crm' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => self::single_args(),
			)
		);

		// Registered after the single-enquiry route, though the order is
		// immaterial: the identifier pattern matches digits only, so no literal
		// path under `/enquiries` competes with it.
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_REJECTIONS,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_rejections' ),
				'permission_callback' => array( Auth::class, 'rest_permission' ),
				'args'                => self::rejection_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_MIGRATION,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'run_migration' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
				'args'                => self::migration_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_TEST_RECORDS,
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_test_records' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * Permission callback of the two administrative routes.
	 *
	 * Composed rather than replaced: `Auth::rest_permission` runs first and
	 * decides hub access exactly as it does for every other route in the
	 * namespace, and only then is `manage_options` asked for (Requirements 15.13,
	 * 16.6, 17.7). Both have to hold.
	 *
	 * The first check returns `false` rather than an error, so core answers 401
	 * for a request carrying no user and 403 for one carrying a user Auth does
	 * not authorize — the same two answers the rest of the namespace gives
	 * (Requirements 16.3, 16.4). A user who cleared Auth but holds no
	 * `manage_options` is logged in by definition, so that refusal is a 403.
	 *
	 * @return bool|\WP_Error True, false, or 403.
	 */
	public static function admin_permission() {
		if ( ! Auth::rest_permission() ) {
			return false;
		}

		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			return new \WP_Error(
				self::FORBIDDEN_CODE,
				__( 'You are not allowed to do that.', 'marthrown-enquiry-hub' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Recognised statuses, taken from the lifecycle when it is loaded.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		if ( class_exists( __NAMESPACE__ . '\Lifecycle' ) ) {
			$statuses = Lifecycle::STATUSES;

			if ( is_array( $statuses ) && array() !== $statuses ) {
				return array_values( $statuses );
			}
		}

		return self::STATUSES;
	}

	/**
	 * The declared args of the list route, each with a `sanitize_callback`.
	 *
	 * Values are sanitised here and normalised by `EnquiryQuery::normalise()`,
	 * which is where an unrecognised status, an unparseable date or an
	 * out-of-range page size is resolved. The two are complementary rather than
	 * redundant: sanitisation makes each value the type its filter accepts
	 * (Requirement 16.7), normalisation decides what that value means.
	 *
	 * No `validate_callback` rejects an unrecognised filter value. A list route
	 * answering 400 for a stale bookmark would be less useful than one applying
	 * the filters it understood, and Requirement 12 asks for no such refusal.
	 *
	 * @return array<string,array>
	 */
	protected static function list_args() {
		return array(
			'status'    => array(
				'description'       => 'A recognised status, or `all`.',
				'type'              => 'string',
				'default'           => EnquiryQuery::STATUS_ALL,
				'sanitize_callback' => 'sanitize_key',
			),
			's'         => array(
				'description'       => 'Search term matched against name, email, phone and message.',
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'from'      => array(
				'description'       => 'Earliest creation date, inclusive (Y-m-d).',
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'to'        => array(
				'description'       => 'Latest creation date, inclusive (Y-m-d).',
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_from' => array(
				'description'       => 'Earliest candidate date, inclusive (Y-m-d). Applies only with `date_to`.',
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_to'   => array(
				'description'       => 'Latest candidate date, inclusive (Y-m-d). Applies only with `date_from`.',
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'hide_test' => array(
				'description'       => 'Exclude test enquiries. Test enquiries are included by default.',
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ),
			),
			'orderby'   => array(
				'description'       => 'Sort column. Defaults to `created_at`.',
				'type'              => 'string',
				'default'           => 'created_at',
				'sanitize_callback' => 'sanitize_key',
			),
			'order'     => array(
				'description'       => 'Sort direction. Defaults to `desc`.',
				'type'              => 'string',
				'default'           => 'desc',
				'sanitize_callback' => 'sanitize_key',
			),
			'page'      => array(
				'description'       => 'Page number, from 1.',
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'  => array(
				'description'       => 'Page size. Defaults to 25, capped at 200.',
				'type'              => 'integer',
				'default'           => EnquiryQuery::PER_PAGE_DEFAULT,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * `GET /enquiries` — one page of enquiries, with the per-status counts.
	 *
	 * Thin by design: the request's filter parameters go to the store as they
	 * are, and the store returns the page, the total, the counts and the
	 * lone-candidate-date-bound warnings in one call (Requirements 12.1, 12.8,
	 * 12.9). Ordering and paging are the builder's, so two requests supplying
	 * the same filters in a different order return the same rows in the same
	 * order (Requirements 12.13, 12.14).
	 *
	 * `X-WP-Total` and `X-WP-TotalPages` carry the totals of the whole matching
	 * set rather than of the page (Requirement 12.11), and are set even when the
	 * page is empty so a client reading them never has to guess.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_enquiries( $request ) {
		$result = EnquiryStore::query( self::list_params( $request ) );

		$response = new \WP_REST_Response(
			array(
				'items'       => self::present_many( (array) $result['items'] ),
				'counts'      => (array) $result['counts'],
				'warnings'    => array_values( (array) $result['warnings'] ),
				'total'       => (int) $result['total'],
				'total_pages' => (int) $result['total_pages'],
				'page'        => (int) $result['page'],
				'per_page'    => (int) $result['per_page'],
			),
			200
		);

		$response->header( self::TOTAL_HEADER, (string) (int) $result['total'] );
		$response->header( self::TOTAL_PAGES_HEADER, (string) (int) $result['total_pages'] );

		return $response;
	}

	/**
	 * The declared args of the manual creation route: the nine enquiry fields,
	 * each with a `sanitize_callback` (Requirement 16.7).
	 *
	 * Three decisions are worth stating, because each of them is what keeps a
	 * requirement in Requirement 18 reachable:
	 *
	 * - **Nothing is declared `required` and nothing carries a `default`.** Core
	 *   answers a missing required arg with its own 400 naming the first one it
	 *   noticed, which is not the answer Requirement 18.3 asks for: every failing
	 *   field, in one `errors` map. Leaving presence to the Validator is what
	 *   produces that map. A `default` would be worse still — it would make an
	 *   omitted field indistinguishable from a submitted one, and Requirements
	 *   18.4 and 18.5 turn on exactly that distinction.
	 * - **`email` is sanitised as text, not with `sanitize_email()`.** That
	 *   function strips the characters that make an address invalid, so
	 *   `ada@@example.com` would arrive looking like a valid address and the 400
	 *   Requirement 18.3 asks for would never be answered. Deciding whether an
	 *   address is an address is the Validator's, and it needs the value as it was
	 *   typed.
	 * - **`total_guests` is not coerced to an integer.** `absint( 'twelve' )` is 0,
	 *   which the Validator would report as out of range rather than as not a
	 *   whole number, and `absint( '-4' )` is 4, which it would accept. Passing the
	 *   submitted text through keeps `not_whole_number` and `out_of_range` as the
	 *   separate answers Requirement 3.4 distinguishes.
	 *
	 * The three multi-value fields are sanitised to lists of non-empty strings, so
	 * the field map handed to `EnquiryCreator` has the shape the Validator's
	 * candidate-date and term rules read whether the client sent an array or a
	 * single value.
	 *
	 * @return array<string,array>
	 */
	protected static function create_args() {
		return array(
			'first_name'       => array(
				'description'       => 'Enquirer first name.',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'last_name'        => array(
				'description'       => 'Enquirer last name.',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'email'            => array(
				'description'       => 'Enquirer email address.',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'phone'            => array(
				'description'       => 'Enquirer telephone number. Optional.',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'total_guests'     => array(
				'description'       => 'Expected guest count, a whole number from 1 to 10000. Optional.',
				'type'              => array( 'integer', 'string' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_ranges'      => array(
				'description'       => 'Candidate date ranges, the ideal one first, up to three of them.',
				'type'              => 'array',
				'items'             => array(
					'type'       => 'object',
					'properties' => array(
						'start' => array( 'type' => 'string' ),
						'end'   => array( 'type' => 'string' ),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_range_list' ),
			),
			'event_type'       => array(
				'description'       => 'Event types. Optional.',
				'type'              => 'array',
				'items'             => array( 'type' => 'string' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_list' ),
			),
			'site_exclusivity' => array(
				'description'       => 'Site exclusivity choices. Optional.',
				'type'              => 'array',
				'items'             => array( 'type' => 'string' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_text_list' ),
			),
			'message'          => array(
				'description'       => 'What the enquirer said. Optional.',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * `POST /enquiries` — create one enquiry by hand (Requirement 18.1).
	 *
	 * Thin, and thin in a specific way: the submitted fields are read and one
	 * `EnquiryCreator::create()` call does the rest. Everything Requirement 18
	 * asks of a manually created enquiry beyond the four arguments passed here —
	 * `status` of `new`, the three timestamps, the candidate date and term rows,
	 * the payload snapshot, `is_test`, the `created` history entry and the contact
	 * link — comes from the write path the intake webhook uses, which is what
	 * makes a manual enquiry behave like a webhook one (Requirements 18.7, 18.9,
	 * 18.10, 18.11, 18.16, 18.17, 18.18, 18.19, 18.20).
	 *
	 * The four arguments are the whole of what manual creation decides:
	 * `PROFILE_MANUAL`, so `phone`, `total_guests`, `message`, `event_type` and
	 * `site_exclusivity` are optional while their value rules still apply
	 * (Requirements 18.2, 18.4, 18.5, 18.6); `manual:{user id}` as the source
	 * (Requirement 18.8); the time the request arrived, read before any work so
	 * the enquiry records when it was received rather than when the store write
	 * happened (Requirement 18.9); and the submitting user as the history actor
	 * (Requirement 18.18).
	 *
	 * `DuplicateDetector` is not called here and neither is
	 * `EnquiryStore::record_rejection()`, and their absence is the design rather
	 * than an omission. Both guards exist to absorb an unattended public form
	 * being submitted twice or hammered; a named, authenticated member of staff
	 * entering a telephone enquiry has decided this is a real enquiry, so a second
	 * identical creation inside the duplicate window is a legitimate second
	 * enquiry and the sixth in the window is too (Requirements 18.12, 18.13,
	 * 18.14). Nor is a rejection row written for a failure: the caller is a person
	 * who can read the 400 and correct the form, which is what the rejections
	 * table exists to substitute for on the unattended path (Requirement 18.15).
	 *
	 * A validation failure is 400 with every failing field named
	 * (Requirements 18.3, 18.6). A store refusal after validation passed is a
	 * fault rather than a malformed submission, so it is 500: answering 400 would
	 * tell the user to correct a form that was already correct.
	 *
	 * Authentication and the nonce are `Auth::rest_permission`'s and core's, as on
	 * every other route in the namespace, so a request presenting the Intake
	 * Secret and no WordPress user is refused before this callback is reached and
	 * creates nothing (Requirements 18.21, 18.22).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_enquiry( $request ) {
		$received_at = Clock::mysql();
		$actor       = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		$result = EnquiryCreator::create(
			self::submitted_fields( $request ),
			Validator::PROFILE_MANUAL,
			self::MANUAL_SOURCE_PREFIX . $actor,
			$received_at,
			$actor
		);

		if ( empty( $result['created'] ) ) {
			return self::creation_error( (array) $result );
		}

		$id      = (int) $result['enquiry_id'];
		$enquiry = EnquiryStore::find( $id );

		if ( ! is_array( $enquiry ) ) {
			// The enquiry exists — the creator returned its identifier — so the
			// request succeeded and is answered as such. Only the representation
			// is missing, which is a fault worth logging rather than one worth
			// inviting the caller to create the enquiry a second time over.
			Log::write( 'enquiries: created enquiry could not be read back', array( 'enquiry_id' => $id ) );

			return new \WP_REST_Response( array( 'id' => $id ), 201 );
		}

		return new \WP_REST_Response( self::present_single( $enquiry ), 201 );
	}

	/**
	 * The refusal a failed creation earns.
	 *
	 * Two outcomes reach here and they deserve different answers. A validation
	 * failure is the submitter's to fix, so it is 400 carrying the per-field map
	 * (Requirements 18.3, 18.6). A store refusal happened after validation
	 * passed, so there is nothing in the submission to correct and it is 500.
	 *
	 * @param array $result Outcome returned by `EnquiryCreator::create()`.
	 * @return \WP_Error
	 */
	protected static function creation_error( array $result ) {
		$errors = isset( $result['errors'] ) ? (array) $result['errors'] : array();

		if ( EnquiryCreator::REASON_STORAGE === ( isset( $result['reason'] ) ? (string) $result['reason'] : '' ) ) {
			return new \WP_Error(
				self::STORE_FAILURE_CODE,
				__( 'The enquiry could not be stored.', 'marthrown-enquiry-hub' ),
				array(
					'status' => 500,
					'errors' => $errors,
				)
			);
		}

		return new \WP_Error(
			self::INVALID_CODE,
			__( 'Some of the enquiry details need correcting.', 'marthrown-enquiry-hub' ),
			array(
				'status' => 400,
				'errors' => $errors,
			)
		);
	}

	/**
	 * The declared args of the single-enquiry route.
	 *
	 * The identifier is already constrained to digits by the route pattern;
	 * `absint()` is declared anyway so the value the callback reads has been
	 * through a `sanitize_callback` like every other request value the
	 * controller accepts (Requirement 16.7).
	 *
	 * @return array<string,array>
	 */
	protected static function single_args() {
		return array(
			'id' => array(
				'description'       => 'Enquiry identifier.',
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * `GET /enquiries/{id}` — one enquiry, with everything known about it.
	 *
	 * Deliberately not routed through `guard_writable()`. A closed enquiry is
	 * frozen against writes and still readable (Requirement 9.2), so the read
	 * path resolves the enquiry itself and answers 404 only for an identifier
	 * matching nothing (Requirement 13.4).
	 *
	 * Completeness is checked before the representation is assembled, so a row
	 * missing `email`, `status` or `created_at` produces a 500 naming the missing
	 * field and no partial representation at all (Requirement 13.5) — not a
	 * response a client could mistake for an enquiry whose contact details
	 * happen to be blank.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_enquiry( $request ) {
		$id      = $request instanceof \WP_REST_Request ? absint( $request->get_param( 'id' ) ) : 0;
		$enquiry = $id > 0 ? EnquiryStore::find( $id ) : null;

		if ( ! is_array( $enquiry ) ) {
			return new \WP_Error(
				self::NOT_FOUND_CODE,
				__( 'The enquiry does not exist.', 'marthrown-enquiry-hub' ),
				array( 'status' => 404 )
			);
		}

		$missing = self::missing_fields( $enquiry );

		if ( array() !== $missing ) {
			Log::write(
				'enquiries: stored row incomplete',
				array(
					'enquiry_id' => $id,
					'missing'    => $missing,
				)
			);

			return new \WP_Error(
				self::INCOMPLETE_CODE,
				sprintf(
					/* translators: 1: enquiry identifier, 2: comma-separated field names. */
					__( 'Enquiry %1$d is missing a value for %2$s.', 'marthrown-enquiry-hub' ),
					$id,
					implode( ', ', $missing )
				),
				array(
					'status'     => 500,
					'enquiry_id' => $id,
					'missing'    => $missing,
				)
			);
		}

		return new \WP_REST_Response( self::present_single( $enquiry ), 200 );
	}

	/**
	 * The declared args of the edit route: the identifier, plus the same nine
	 * fields manual creation accepts (Requirements 19.1, 16.7).
	 *
	 * `create_args()` is reused rather than restated, and the reuse is the point:
	 * an edit corrects the very fields creation sets, so a sanitiser that drifted
	 * between the two routes would mean the same value stored two different ways
	 * depending on how the enquiry got here. The three decisions that docblock
	 * explains — nothing `required`, nothing carrying a `default`, `email` and
	 * `total_guests` passed through as submitted text — are exactly what an edit
	 * needs too, and the first two are what make a partial body expressible at
	 * all: only `id` is required, and every field absent from the request stays
	 * absent, so `submitted_fields()` can tell "leave this alone" from "set this
	 * to empty" (Requirements 19.4, 19.7).
	 *
	 * @return array<string,array>
	 */
	protected static function edit_args() {
		return array_merge( self::single_args(), self::create_args() );
	}

	/**
	 * `PATCH /enquiries/{id}` — correct stored field values (Requirement 19.1).
	 *
	 * Thin, like every other write route: the fields the request actually carried
	 * are read by name, and one `EnquiryEditor::apply()` call does the work.
	 * `submitted_fields()` is what makes the body partial — it reads only the
	 * declared args `has_param()` reports, so an omitted field never reaches the
	 * editor and therefore never reaches the store (Requirement 19.7).
	 *
	 * **This route does not call `guard_writable()` itself, because the editor
	 * calls it as its own first step**, before it validates and before it reads
	 * any stored value. Calling it here as well would satisfy Requirement 19.12
	 * just as truthfully but would read the row twice for one request, and would
	 * leave two places able to disagree about what a closed enquiry earns. The
	 * ordering the requirement asks for is the editor's and is asserted there: an
	 * unknown identifier is 404 and a closed enquiry is 409, whether the submitted
	 * body would have validated or not.
	 *
	 * Nothing else is decided here. Which fields are editable, the Manual profile
	 * in partial mode, `updated_at`, the single `fields_edited` history entry, the
	 * FluentCRM re-link and the untouched payload snapshot are all
	 * `EnquiryEditor`'s (Requirements 19.3, 19.6 to 19.18), and `source` is never
	 * consulted on that path, which is what makes a webhook enquiry, a manual one
	 * and a migrated one equally editable (Requirement 19.2).
	 *
	 * The response reports `changed` — the store's own field => {from, to} map,
	 * empty for a submission matching what was already stored — so a client can
	 * tell an applied correction from a no-op without diffing the representation
	 * itself (Requirement 19.18).
	 *
	 * Authentication is `Auth::rest_permission`'s and the nonce is core's, as on
	 * every other route in the namespace (Requirement 19.19).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function edit_enquiry( $request ) {
		$id    = self::requested_id( $request );
		$actor = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		$applied = EnquiryEditor::apply( $id, self::submitted_fields( $request ), $actor );

		if ( is_wp_error( $applied ) ) {
			return self::edit_error( $applied );
		}

		$applied = (array) $applied;
		$changed = isset( $applied['changed'] ) ? (array) $applied['changed'] : array();
		$state   = isset( $applied['crm_sync_state'] ) ? (string) $applied['crm_sync_state'] : '';

		return self::respond_with_enquiry(
			$id,
			array(
				'changed'  => $changed,
				'warnings' => self::crm_warnings( $changed, $state ),
			),
			200
		);
	}

	/**
	 * The refusal a failed edit earns.
	 *
	 * The editor's 404 and 409 already carry the status and the words this
	 * controller would have used — they come from `guard_writable()` — and a store
	 * refusal already carries its own, so all three are returned untouched.
	 *
	 * A validation failure is the one that is rewritten, and only in its
	 * envelope: the editor names it `meh_enquiry_invalid_fields` and carries the
	 * per-field map under `fields`, while the HTTP surface answers a rejected
	 * submission as self::INVALID_CODE carrying `errors`. Restating it here means
	 * the hub's form renders a rejected edit from the same code and the same map
	 * as a rejected creation, rather than needing a second code path for the same
	 * kind of answer (Requirements 19.4, 19.5).
	 *
	 * @param \WP_Error $error Failure returned by `EnquiryEditor::apply()`.
	 * @return \WP_Error
	 */
	protected static function edit_error( $error ) {
		if ( EnquiryEditor::INVALID_CODE !== $error->get_error_code() ) {
			return $error;
		}

		$data = (array) $error->get_error_data();

		return new \WP_Error(
			self::INVALID_CODE,
			__( 'Some of the enquiry details need correcting.', 'marthrown-enquiry-hub' ),
			array(
				'status' => 400,
				'errors' => isset( $data['fields'] ) ? (array) $data['fields'] : array(),
			)
		);
	}

	/**
	 * The warnings an applied edit carries.
	 *
	 * One case, and it is a warning rather than a failure: a correction to a name,
	 * an address or a telephone number was stored, and the FluentCRM upsert that
	 * followed it did not land, leaving the enquiry `pending` (Requirement 19.16).
	 * The correction itself is stored and the retry route exists precisely so that
	 * linkage is recoverable (Requirement 5.4), so answering an error would tell
	 * the user their correction failed when it did not.
	 *
	 * Both halves have to hold before this is a re-link failure. `pending` alone
	 * is not: an enquiry whose contact never linked in the first place stays
	 * `pending` through an edit that changed only `message`, and no FluentCRM call
	 * was made for this request at all (Requirement 19.17). So the changed set is
	 * intersected with the fields the linker actually acts on.
	 *
	 * @param array  $changed        The store's `changed` map, keyed by field.
	 * @param string $crm_sync_state State the enquiry holds after the edit.
	 * @return string[]
	 */
	protected static function crm_warnings( array $changed, $crm_sync_state ) {
		if ( ContactLinker::STATE_PENDING !== (string) $crm_sync_state ) {
			return array();
		}

		if ( array() === array_intersect( array_keys( $changed ), ContactLinker::LINKED_FIELDS ) ) {
			return array();
		}

		return array( self::CRM_PENDING_WARNING );
	}

	/**
	 * Resolve an enquiry for a write, or explain why it cannot be written to.
	 *
	 * The one place closed-enquiry immutability lives: every write route calls
	 * this first, so the rule is stated once rather than repeated per route
	 * (Requirement 9.1). The edit route calls it before validating and before
	 * reading any stored value, so a closed enquiry is answered 409 whether the
	 * submitted body would have validated or not (Requirement 19.12).
	 *
	 * The hydrated enquiry is returned on success, so a caller that needs the
	 * stored row — the status route needs the current status, the convert route
	 * needs `booking_id` — has it without a second read.
	 *
	 * @param int $id Enquiry identifier.
	 * @return array|\WP_Error The hydrated enquiry, or 404 / 409.
	 */
	public static function guard_writable( $id ) {
		$enquiry = self::resolve( $id );

		if ( is_wp_error( $enquiry ) ) {
			return $enquiry;
		}

		if ( self::STATUS_CLOSED === ( isset( $enquiry['status'] ) ? (string) $enquiry['status'] : '' ) ) {
			return new \WP_Error(
				self::CLOSED_CODE,
				__( 'This enquiry is closed and cannot be changed.', 'marthrown-enquiry-hub' ),
				array(
					'status'     => 409,
					'enquiry_id' => (int) $enquiry['id'],
				)
			);
		}

		return $enquiry;
	}

	/**
	 * Resolve an enquiry by identifier, or explain that it does not exist.
	 *
	 * The read half of `guard_writable()`, split out so the two callers that need
	 * an enquiry but not the closed check — the read route's sibling in spirit,
	 * and the re-raise route — answer 404 in exactly the same words rather than
	 * in their own.
	 *
	 * @param int $id Enquiry identifier.
	 * @return array|\WP_Error The hydrated enquiry, or 404.
	 */
	protected static function resolve( $id ) {
		$id      = absint( $id );
		$enquiry = $id > 0 ? EnquiryStore::find( $id ) : null;

		if ( ! is_array( $enquiry ) ) {
			return new \WP_Error(
				self::NOT_FOUND_CODE,
				__( 'The enquiry does not exist.', 'marthrown-enquiry-hub' ),
				array( 'status' => 404 )
			);
		}

		return $enquiry;
	}

	/**
	 * The declared args of the status route.
	 *
	 * `sanitize_key` is the right sanitiser for a status: the six recognised
	 * values are lower-case ASCII words, and anything else is refused by
	 * `Lifecycle::transition()` with a 400 naming it rather than being coerced
	 * here into something that happens to be recognised.
	 *
	 * `note` records why the move was made. It is optional, not required: the
	 * transition is the thing this route exists to apply, and refusing a legal
	 * move for want of a comment would put a reporting nicety ahead of the
	 * lifecycle. Sanitised as a textarea, and left for `NoteService` to judge,
	 * exactly as the note route does — the two must not disagree about what a
	 * note may contain.
	 *
	 * @return array<string,array>
	 */
	protected static function status_args() {
		return array_merge(
			self::single_args(),
			array(
				'status' => array(
					'description'       => 'The status to move the enquiry to.',
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
				'note'   => array(
					'description'       => 'Optional note recording why the status was changed.',
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			)
		);
	}

	/**
	 * The declared args of the note route.
	 *
	 * `sanitize_textarea_field` rather than `sanitize_text_field`, so the line
	 * breaks in an account of a phone call survive. `NoteService` applies its own
	 * strip-and-trim and owns both rejections — an empty body and one over the
	 * character limit (Requirements 10.4, 10.5) — so nothing is decided here
	 * beyond making the value safe to pass on (Requirement 16.7).
	 *
	 * @return array<string,array>
	 */
	protected static function note_args() {
		return array_merge(
			self::single_args(),
			array(
				'body' => array(
					'description'       => 'The note body.',
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			)
		);
	}

	/**
	 * The declared args of the conversion route.
	 *
	 * Both are required, because Requirement 14.1 has the route accept a target
	 * calendar identifier and a chosen candidate date: neither has a defensible
	 * default. A calendar WP Booking System does not hold, and a date the
	 * enquirer never offered, are refused by `BookingCreator` with a 400 rather
	 * than guessed at here.
	 *
	 * @return array<string,array>
	 */
	protected static function convert_args() {
		return array_merge(
			self::single_args(),
			array(
				'calendar_id' => array(
					'description'       => 'Identifier of the target WP Booking System calendar.',
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'date'        => array(
					'description'       => 'The chosen candidate date (Y-m-d).',
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
	}

	/**
	 * `POST /enquiries/{id}/status` — apply a lifecycle transition.
	 *
	 * Two guards, in this order: the enquiry has to exist and be writable
	 * (Requirement 9.1), and the move has to be one the transition table permits
	 * (Requirement 7.5). Only the first belongs here; the second is
	 * `Lifecycle`'s, and its 409 is passed through as it comes.
	 *
	 * A repeat of a transition already applied is a success that wrote nothing
	 * (Requirement 7.9), which is why the response reports `changed` rather than
	 * leaving a client to infer it from the status alone.
	 *
	 * An accompanying `note` is added after the transition, never before: a note
	 * explaining a move that was then refused would be a record of something that
	 * did not happen. It is added only when the transition changed something, for
	 * the same reason — a repeat wrote nothing to explain.
	 *
	 * A note that `NoteService` refuses does not undo the transition, which has
	 * already been written and recorded. The refusal is reported as `note_error`
	 * beside the successful transition rather than as the response's status, so a
	 * client is told exactly what landed and what did not instead of being left to
	 * assume from a 400 that neither did.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function set_status( $request ) {
		$id      = self::requested_id( $request );
		$enquiry = self::guard_writable( $id );

		if ( is_wp_error( $enquiry ) ) {
			return $enquiry;
		}

		$transition = Lifecycle::transition( $id, (string) $request->get_param( 'status' ) );

		if ( is_wp_error( $transition ) ) {
			return $transition;
		}

		$payload = array( 'transition' => $transition );
		$note    = trim( (string) $request->get_param( 'note' ) );

		if ( '' !== $note && ! empty( $transition['changed'] ) ) {
			$note_id = NoteService::add( $id, $note );

			if ( is_wp_error( $note_id ) ) {
				$payload['note_error'] = $note_id->get_error_message();

				Log::write(
					'rest: status note not added',
					array(
						'enquiry_id' => $id,
						'error'      => $note_id->get_error_message(),
					)
				);
			} else {
				$payload['note_id'] = (int) $note_id;
			}
		}

		return self::respond_with_enquiry( $id, $payload, 200 );
	}

	/**
	 * `POST /enquiries/{id}/notes` — add an internal note (Requirement 10.1).
	 *
	 * 201, because a note is a resource the request created. The refreshed
	 * enquiry carries the notes most recent first (Requirement 10.7) and the
	 * `note_added` history entry the service appended (Requirement 10.6), so the
	 * detail panel needs no second read to show either.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_note( $request ) {
		$id      = self::requested_id( $request );
		$enquiry = self::guard_writable( $id );

		if ( is_wp_error( $enquiry ) ) {
			return $enquiry;
		}

		$note_id = NoteService::add( $id, (string) $request->get_param( 'body' ) );

		if ( is_wp_error( $note_id ) ) {
			return $note_id;
		}

		return self::respond_with_enquiry( $id, array( 'note_id' => (int) $note_id ), 201 );
	}

	/**
	 * `POST /enquiries/{id}/duplicate` — re-raise an enquiry as a new one
	 * (Requirement 9.3).
	 *
	 * The one write route that does *not* go through `guard_writable()`, and
	 * deliberately so. Re-raising a closed enquiry is the case Requirement 9
	 * exists for — a past enquirer coming back, with the original left frozen —
	 * so answering 409 for a closed source would refuse the very request the
	 * requirement is about. Nothing the route does contradicts the freeze either:
	 * the source keeps its status, its notes, its field values and its booking
	 * (Requirement 9.9), and Requirement 9.6 has it record the new identifier,
	 * which a closed source could not do if it were frozen against every write.
	 * The route still resolves the source, so an identifier matching nothing is a
	 * 404 rather than a copy of nothing. The design's own route table marks this
	 * route as carrying no extra guard, and Property 24 names the status, note,
	 * edit, conversion and retry routes as the frozen writes, leaving this one
	 * out.
	 *
	 * The copy itself is `EnquiryStore::duplicate()`'s: which columns come across,
	 * what the copy's status and `created_at` are, and the two-way relationship
	 * are all decided there, transactionally, and a partial copy is discarded
	 * there too (Requirements 9.4 to 9.7).
	 *
	 * Three things are left for the route, because they sit outside the store:
	 *
	 * - A `duplicated` history entry on *both* enquiries. The source's trail
	 *   should say where the re-raise went and the copy's should say where it came
	 *   from; a single entry would leave one of the two silent. This is the only
	 *   change the source sees, which is what "otherwise unchanged" in
	 *   Requirement 9.9 leaves room for.
	 * - `ContactLinker::link()` on the copy, which is what makes the copy reuse
	 *   the source's subscriber identifier (Requirement 9.8) — the store leaves
	 *   `fluentcrm_subscriber_id` alone precisely so this call, reading
	 *   `duplicated_from_id`, decides it.
	 * - A warning rather than a failure when that linkage fails, since the copy is
	 *   stored and the retry route exists for exactly this state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function duplicate_enquiry( $request ) {
		$id     = self::requested_id( $request );
		$source = self::resolve( $id );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$new_id = EnquiryStore::duplicate( $id );

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$new_id = (int) $new_id;

		HistoryRecorder::record(
			$id,
			self::DUPLICATED_HISTORY_TYPE,
			sprintf( 'Re-raised as enquiry %d.', $new_id ),
			array( 'duplicated_to_id' => $new_id )
		);

		HistoryRecorder::record(
			$new_id,
			self::DUPLICATED_HISTORY_TYPE,
			sprintf( 'Copied from enquiry %d.', $id ),
			array( 'duplicated_from_id' => $id )
		);

		$warnings = array();
		$linked   = ContactLinker::link( $new_id );

		if ( is_wp_error( $linked ) ) {
			$warnings[] = self::CRM_PENDING_WARNING;
		}

		return self::respond_with_enquiry(
			$new_id,
			array(
				'source_id' => $id,
				'warnings'  => $warnings,
			),
			201
		);
	}

	/**
	 * `POST /enquiries/{id}/convert` — create a booking from the enquiry
	 * (Requirement 14.1).
	 *
	 * `guard_writable()` first, then one call to `BookingCreator`, which owns
	 * every other guard the conversion has: WP Booking System being inactive
	 * (503), an unknown calendar (400), a date the enquirer never offered (400)
	 * and an enquiry already holding a booking (409). Each of those errors already
	 * carries its status, so each is returned exactly as it came.
	 *
	 * The creator's `warnings` are lifted out of the booking payload and reported
	 * alongside it, because a booking that was made but whose day could not be
	 * blocked is a success the hub has to be able to tell its user about.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function convert_enquiry( $request ) {
		$id      = self::requested_id( $request );
		$enquiry = self::guard_writable( $id );

		if ( is_wp_error( $enquiry ) ) {
			return $enquiry;
		}

		$booking = BookingCreator::create_from_enquiry(
			$id,
			absint( $request->get_param( 'calendar_id' ) ),
			(string) $request->get_param( 'date' )
		);

		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$booking  = (array) $booking;
		$warnings = isset( $booking['warnings'] ) ? array_values( (array) $booking['warnings'] ) : array();

		unset( $booking['warnings'] );

		return self::respond_with_enquiry(
			$id,
			array(
				'booking'  => $booking,
				'warnings' => $warnings,
			),
			200
		);
	}

	/**
	 * `POST /enquiries/{id}/retry-crm` — retry contact linkage (Requirement 5.4).
	 *
	 * No `crm_sync_state` check of its own. The retry is an idempotent upsert on
	 * the enquiry's email address, so running it against an enquiry that is
	 * already `synced` re-states what FluentCRM already holds rather than doing
	 * damage — and a state check here would refuse the one case worth allowing,
	 * an enquiry whose stored state and whose contact have drifted apart.
	 *
	 * A failure is passed through with the status `ContactLinker` gave it, and the
	 * enquiry is left `pending` by the linker itself, so the route can be tried
	 * again once FluentCRM is back.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function retry_crm( $request ) {
		$id      = self::requested_id( $request );
		$enquiry = self::guard_writable( $id );

		if ( is_wp_error( $enquiry ) ) {
			return $enquiry;
		}

		$linked = ContactLinker::retry( $id );

		if ( is_wp_error( $linked ) ) {
			return $linked;
		}

		return self::respond_with_enquiry( $id, array( 'crm' => (array) $linked ), 200 );
	}

	/**
	 * The declared args of the rejections route, each with a `sanitize_callback`.
	 *
	 * The keys `EnquiryStore::rejections()` reads, and no others: an unrecognised
	 * `reason` is ignored by the store rather than refused here, for the same
	 * reason the enquiry list applies the filters it understood — a stale
	 * bookmark should still show the list.
	 *
	 * @return array<string,array>
	 */
	protected static function rejection_args() {
		return array(
			'reason'    => array(
				'description'       => 'A recognised rejection reason: duplicate, rate_limited, validation or storage.',
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
			's'         => array(
				'description'       => 'Search term matched against the submitted email address.',
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'from'      => array(
				'description'       => 'Earliest receipt date, inclusive (Y-m-d).',
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'to'        => array(
				'description'       => 'Latest receipt date, inclusive (Y-m-d).',
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'hide_test' => array(
				'description'       => 'Exclude attempts recorded in staging. They are included by default.',
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ),
			),
			'page'      => array(
				'description'       => 'Page number, from 1.',
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'  => array(
				'description'       => 'Page size. Defaults to 25, capped at 200.',
				'type'              => 'integer',
				'default'           => EnquiryQuery::PER_PAGE_DEFAULT,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * `GET /enquiries/rejections` — the intake attempts that produced no enquiry
	 * (Requirement 4.8).
	 *
	 * The point of the route is recovery: a genuine enquiry turned away as a
	 * duplicate, as rate limited or as invalid has to be findable, so each row
	 * carries its reason, the submitted address, the resolved source, the receipt
	 * time, the per-field detail and the payload as it arrived. The payload is
	 * kept here, unlike in the enquiry list, because re-entering the enquiry by
	 * hand is exactly what a reader of this list is about to do.
	 *
	 * `Auth::rest_permission` alone, deliberately: Requirement 4.8 exposes these
	 * attempts to the users Auth authorizes, and adding `manage_options` would
	 * hide a lost enquiry from the very people whose job it is to chase it.
	 *
	 * Paged and totalled like the enquiry list, headers included, so a client can
	 * page through a long staging run with the code it already has.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_rejections( $request ) {
		$result = EnquiryStore::rejections( self::declared_params( $request, self::rejection_args() ) );

		$response = new \WP_REST_Response(
			array(
				'items'       => array_values( (array) $result['items'] ),
				'total'       => (int) $result['total'],
				'total_pages' => (int) $result['total_pages'],
				'page'        => (int) $result['page'],
				'per_page'    => (int) $result['per_page'],
				'reasons'     => array_values( EnquiryStore::REJECTION_REASONS ),
			),
			200
		);

		$response->header( self::TOTAL_HEADER, (string) (int) $result['total'] );
		$response->header( self::TOTAL_PAGES_HEADER, (string) (int) $result['total_pages'] );

		return $response;
	}

	/**
	 * The declared args of the migration route.
	 *
	 * One flag, defaulting to the safe half of the pair. A request that omits it
	 * — or carries a value that is not recognisably true — previews rather than
	 * runs, so a malformed call from the settings screen cannot import a CRM by
	 * accident.
	 *
	 * @return array<string,array>
	 */
	protected static function migration_args() {
		return array(
			'preview' => array(
				'description'       => 'Report what a run would create without creating anything. Defaults to true.',
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_bool' ),
			),
		);
	}

	/**
	 * `POST /enquiries/migration` — run or preview the migration
	 * (Requirement 15.13).
	 *
	 * Thin: the flag chooses between `MigrationRunner::preview()` and
	 * `MigrationRunner::run()`, and the runner's own report is returned as it
	 * comes. What counts as eligible, what a skip reason is, the ledger and the
	 * completion time all belong there (Requirements 15.7 to 15.12), and a preview
	 * writes nothing because the runner's preview writes nothing — not because the
	 * route withholds anything.
	 *
	 * 200 rather than 201 even for a run that created enquiries: the response
	 * describes a report about a batch, not a single created resource, and there
	 * is no URL to point a `Location` header at.
	 *
	 * An unavailable FluentCRM is not an error here. The runner reports
	 * `available` as false with a zero count, which is the truthful answer to "how
	 * many would this create?" on a site whose CRM is inactive, and the settings
	 * screen can say so rather than showing a failure.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function run_migration( $request ) {
		$preview = ! ( $request instanceof \WP_REST_Request ) || self::sanitize_bool( $request->get_param( 'preview' ) );

		$report = $preview ? MigrationRunner::preview() : MigrationRunner::run();

		return new \WP_REST_Response(
			array_merge( array( 'preview' => $preview ), (array) $report ),
			200
		);
	}

	/**
	 * `DELETE /enquiries/test-records` — remove every staging enquiry
	 * (Requirement 17.7).
	 *
	 * Destructive by design, and narrowly so: `manage_options` gates it, and the
	 * store names identifiers read from `is_test = 1` and nothing else, so a live
	 * enquiry is never reachable from any statement the delete issues
	 * (Requirement 17.8). Rejected intake attempts survive too — they are the
	 * trace of what a staging run failed to store, which is the record the run
	 * existed to produce.
	 *
	 * Logged whatever the count, because "how many did that remove?" is a question
	 * worth being able to answer after the fact about the one route that removes
	 * anything.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function delete_test_records( $request ) {
		unset( $request );

		$deleted = (int) EnquiryStore::delete_test_records();

		Log::write( 'enquiries: test records deleted', array( 'deleted' => $deleted ) );

		return new \WP_REST_Response( array( 'deleted' => $deleted ), 200 );
	}

	/**
	 * The enquiry identifier a request names.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int 0 when the request names none.
	 */
	protected static function requested_id( $request ) {
		return $request instanceof \WP_REST_Request ? absint( $request->get_param( 'id' ) ) : 0;
	}

	/**
	 * Answer a completed write with the enquiry as it now stands.
	 *
	 * Every write route re-reads its enquiry rather than patching the copy it
	 * guarded, because the component that did the work may have changed more than
	 * the route asked for — a conversion writes `booking_id` and the status, a
	 * note adds a history entry — and a response assembled from a stale copy would
	 * quietly disagree with the store.
	 *
	 * A row that cannot be read back after a successful write is a fault worth
	 * logging, but not one worth failing the request over: the write happened, and
	 * reporting it as a 500 would invite a client to repeat it.
	 *
	 * @param int   $id      Enquiry to represent.
	 * @param array $payload What the route has to say about the write itself.
	 * @param int   $status  HTTP status.
	 * @return \WP_REST_Response
	 */
	protected static function respond_with_enquiry( $id, array $payload, $status ) {
		$id      = (int) $id;
		$enquiry = EnquiryStore::find( $id );

		if ( ! is_array( $enquiry ) ) {
			Log::write( 'enquiries: written enquiry could not be read back', array( 'enquiry_id' => $id ) );
		}

		$payload['enquiry'] = is_array( $enquiry ) ? self::present_single( $enquiry ) : null;

		return new \WP_REST_Response( $payload, (int) $status );
	}

	/**
	 * The filter parameters of a list request.
	 *
	 * Read by name rather than with `get_params()`, so a parameter the route did
	 * not declare — and therefore did not sanitise — cannot reach the store.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	protected static function list_params( $request ) {
		return self::declared_params( $request, self::list_args() );
	}

	/**
	 * The values a request supplied for a declared arg set.
	 *
	 * Read by name from the route's own arg declaration, so a parameter the route
	 * did not declare — and therefore did not sanitise — cannot reach the store
	 * (Requirement 16.7). Shared by the enquiry list and the rejections list
	 * because both hand a filter map straight to the store.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param array            $args    Declared args, keyed by parameter name.
	 * @return array<string,mixed>
	 */
	protected static function declared_params( $request, array $args ) {
		$params = array();

		if ( ! $request instanceof \WP_REST_Request ) {
			return $params;
		}

		foreach ( array_keys( $args ) as $key ) {
			$params[ $key ] = $request->get_param( $key );
		}

		return $params;
	}

	/**
	 * The enquiry fields a creation or edit request actually submitted.
	 *
	 * A field appears only where the request carried it, which is why this cannot
	 * be `declared_params()`: that reader returns every declared key, filling an
	 * absent one with null, and an absent field has to stay absent here. The
	 * Validator's presence check is what turns absence into `required` under the
	 * Manual profile, and Requirements 18.4 and 18.5 turn on the same
	 * distinction — a `phone` that was never submitted and a `phone` submitted
	 * empty both store an empty value, but only the second is a value the
	 * submitter chose. The edit route turns on it harder still: an absent field
	 * keeps its stored value while a submitted empty one clears it or is refused
	 * (Requirements 19.4, 19.7).
	 *
	 * Read by name from the route's own arg declaration, so a parameter the route
	 * did not declare — and therefore did not sanitise — reaches neither the
	 * Validator nor the payload snapshot (Requirement 16.7).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	protected static function submitted_fields( $request ) {
		$fields = array();

		if ( ! $request instanceof \WP_REST_Request ) {
			return $fields;
		}

		foreach ( array_keys( self::create_args() ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$fields[ $field ] = $request->get_param( $field );
			}
		}

		return $fields;
	}

	/**
	 * Sanitise a submitted multi-value field to a list of non-empty strings.
	 *
	 * A scalar becomes a one-entry list, so the shape does not vary with how many
	 * values the client picked, and empty entries are dropped, because a form
	 * padding a multi-select with blanks has not submitted those values. A field
	 * submitted holding nothing but blanks therefore arrives as an empty list,
	 * which is a cleared set for `event_type` and `site_exclusivity`
	 * (Requirement 18.5) — the Validator decides that, not this method.
	 *
	 * @param mixed $value Submitted value.
	 * @return string[]
	 */
	public static function sanitize_text_list( $value ) {
		$items = is_array( $value ) ? $value : array( $value );
		$list  = array();

		foreach ( $items as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$text = function_exists( 'sanitize_text_field' )
				? sanitize_text_field( (string) $item )
				: trim( strip_tags( (string) $item ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( '' !== $text ) {
				$list[] = $text;
			}
		}

		return $list;
	}

	/**
	 * Sanitise a submitted candidate date list to a list of `start`/`end` pairs.
	 *
	 * Three submitted shapes are accepted, because three clients send them: a
	 * list of `{ start, end }` objects from the hub's own form, a single such
	 * object where only the ideal range was named, and a bare date string, which
	 * is a range of one day. Nothing here rejects a malformed range or clips a
	 * fourth one — an entry keeps whatever bounds it was given, empty ones
	 * included, so the Validator is the single place that reports
	 * `incomplete_range`, `unparseable_date`, `ends_before_start` and
	 * `too_many_ranges` (Requirement 18.3).
	 *
	 * @param mixed $value Submitted value.
	 * @return array<int,array{start:string,end:string}>
	 */
	public static function sanitize_range_list( $value ) {
		// A lone range object would otherwise be read as two entries, one per
		// bound, and both of them half-drawn.
		if ( is_array( $value ) && ( isset( $value['start'] ) || isset( $value['end'] ) ) ) {
			$value = array( $value );
		}

		$items  = is_array( $value ) ? $value : array( $value );
		$ranges = array();

		foreach ( $items as $item ) {
			if ( is_scalar( $item ) ) {
				$day = self::sanitize_date_text( $item );

				if ( '' !== $day ) {
					$ranges[] = array(
						'start' => $day,
						'end'   => $day,
					);
				}

				continue;
			}

			if ( ! is_array( $item ) ) {
				continue;
			}

			$start = isset( $item['start'] ) ? self::sanitize_date_text( $item['start'] ) : '';
			$end   = isset( $item['end'] ) ? self::sanitize_date_text( $item['end'] ) : '';

			if ( '' === $start && '' === $end ) {
				continue;
			}

			$ranges[] = array(
				'start' => $start,
				'end'   => $end,
			);
		}

		return $ranges;
	}

	/**
	 * Sanitise one submitted date bound to text.
	 *
	 * @param mixed $value Submitted bound.
	 * @return string Empty when the value is not text at all.
	 */
	protected static function sanitize_date_text( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return function_exists( 'sanitize_text_field' )
			? sanitize_text_field( (string) $value )
			: trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Present a page of hydrated enquiries.
	 *
	 * The closure outcomes are resolved for the whole page in one read rather than
	 * per row, so a 200-row page costs one extra query however many of its rows
	 * are closed.
	 *
	 * @param array $enquiries Hydrated enquiries.
	 * @return array<int,array>
	 */
	protected static function present_many( array $enquiries ) {
		$items = array();
		$ids   = array();

		foreach ( $enquiries as $enquiry ) {
			if ( is_array( $enquiry ) ) {
				$ids[] = isset( $enquiry['id'] ) ? (int) $enquiry['id'] : 0;
			}
		}

		$outcomes = Lifecycle::closed_from_many( $ids );

		foreach ( $enquiries as $enquiry ) {
			if ( ! is_array( $enquiry ) ) {
				continue;
			}

			$row                = self::present( $enquiry );
			$row['closed_from'] = self::closed_from( $row, $outcomes );

			$items[] = $row;
		}

		return $items;
	}

	/**
	 * The status a row held before it was closed, as the API reports it.
	 *
	 * Always present and always a string, `''` for an enquiry that is not closed,
	 * so a client reading the field never has to test for the key and a falsy
	 * value is what tells it there is no outcome to show — the same shape
	 * `crm_url` carries for the same reason.
	 *
	 * Guarded on the row's own status rather than on the map alone: a stored status
	 * that moved on without recording it would otherwise report an outcome for an
	 * enquiry that is not closed.
	 *
	 * @param array             $enquiry  Presented enquiry.
	 * @param array<int,string> $outcomes Outcomes by identifier, from `Lifecycle`.
	 * @return string
	 */
	protected static function closed_from( array $enquiry, array $outcomes ) {
		$id     = isset( $enquiry['id'] ) ? (int) $enquiry['id'] : 0;
		$status = isset( $enquiry['status'] ) ? (string) $enquiry['status'] : '';

		if ( self::STATUS_CLOSED !== $status || ! isset( $outcomes[ $id ] ) ) {
			return '';
		}

		return (string) $outcomes[ $id ];
	}

	/**
	 * One hydrated enquiry as the API represents it.
	 *
	 * The stored shape, minus the payload snapshot: the snapshot is the verbatim
	 * submission kept for tracing a bad intake, it can run to the whole of a
	 * form's body, and nothing in the list renders it. Sending it on every row
	 * of a 200-row page would cost far more than it tells a client.
	 *
	 * `total_guests` is `null` and `phone` / `message` are `''` when nothing was
	 * supplied, exactly as stored, so an unsupplied value is distinguishable
	 * from a supplied one (Requirement 1.19).
	 *
	 * @param array $enquiry Hydrated enquiry.
	 * @return array
	 */
	protected static function present( array $enquiry ) {
		unset( $enquiry['payload'] );

		return $enquiry;
	}

	/**
	 * One hydrated enquiry as the single-enquiry route represents it.
	 *
	 * Everything the detail panel needs in one response (Requirement 13.2): the
	 * stored fields, the candidate date ranges and both multi-selects as the store
	 * hydrated them, the payload snapshot — kept here, unlike in the list, because
	 * this is where a questionable intake is traced — the notes, the history, the
	 * `crm_sync_state` in whichever state it holds (Requirement 5.3), the linked
	 * booking identifier, the FluentCRM contact URL, the permitted transitions and
	 * the same-email siblings.
	 *
	 * `closed_from` is derived rather than stored: it is the status the enquiry
	 * held before it was closed, which `Lifecycle` recovers from the trail, and it
	 * is what lets a closed enquiry be shown as won or lost rather than only as
	 * finished. Every sibling carries it for the same reason.
	 *
	 * `allowed_transitions` comes from `Lifecycle` rather than from a list held
	 * here, so the hub's action buttons can never offer an illegal transition and
	 * need no update when the transition table changes (Requirement 13.6).
	 *
	 * @param array $enquiry Hydrated enquiry.
	 * @return array
	 */
	protected static function present_single( array $enquiry ) {
		$id    = isset( $enquiry['id'] ) ? (int) $enquiry['id'] : 0;
		$email = isset( $enquiry['email'] ) ? (string) $enquiry['email'] : '';

		$status = isset( $enquiry['status'] ) ? (string) $enquiry['status'] : '';

		$enquiry['notes']               = self::present_notes( NoteService::for_enquiry( $id ) );
		$enquiry['history']             = self::present_history( HistoryRecorder::for_enquiry( $id ) );
		$enquiry['crm_url']             = self::crm_url( $enquiry );
		$enquiry['allowed_transitions'] = array_values( Lifecycle::allowed_from( $status ) );
		$enquiry['siblings']            = self::present_siblings(
			EnquiryStore::siblings_by_email( $email, $id )
		);

		$enquiry['closed_from'] = self::closed_from(
			$enquiry,
			Lifecycle::closed_from_many( array( $id ) )
		);

		return $enquiry;
	}

	/**
	 * The same-email siblings as the API represents them.
	 *
	 * The store's summary plus each sibling's closure outcome, so a list of
	 * closed siblings says how each one finished rather than only that it did.
	 * One read for the whole list, whatever its length.
	 *
	 * @param array $siblings Sibling summaries from the store.
	 * @return array<int,array>
	 */
	protected static function present_siblings( array $siblings ) {
		$ids = array();

		foreach ( $siblings as $sibling ) {
			if ( is_array( $sibling ) ) {
				$ids[] = isset( $sibling['id'] ) ? (int) $sibling['id'] : 0;
			}
		}

		$outcomes  = Lifecycle::closed_from_many( $ids );
		$presented = array();

		foreach ( $siblings as $sibling ) {
			if ( ! is_array( $sibling ) ) {
				continue;
			}

			$sibling['closed_from'] = self::closed_from( $sibling, $outcomes );

			$presented[] = $sibling;
		}

		return $presented;
	}

	/**
	 * The stored fields a representation cannot be built without, that this row
	 * does not hold (Requirement 13.5).
	 *
	 * The store hydrates an absent, null or zero `DATETIME` to the empty string
	 * and an absent text column to the empty string, so emptiness after
	 * hydration is exactly "no value stored".
	 *
	 * @param array $enquiry Hydrated enquiry.
	 * @return string[] Field names, in the order the constant declares them.
	 */
	protected static function missing_fields( array $enquiry ) {
		$missing = array();

		foreach ( self::REQUIRED_FIELDS as $field ) {
			$value = isset( $enquiry[ $field ] ) ? $enquiry[ $field ] : '';

			if ( '' === trim( (string) $value ) ) {
				$missing[] = $field;
			}
		}

		return $missing;
	}

	/**
	 * The FluentCRM contact URL of an enquiry, empty when it has no contact.
	 *
	 * Empty rather than absent when `fluentcrm_subscriber_id` is null: a client
	 * reading the field always finds it, and a falsy value is what tells it there
	 * is no contact to link to yet — the same shape the list route's rows carry.
	 *
	 * @param array $enquiry Hydrated enquiry.
	 * @return string
	 */
	protected static function crm_url( array $enquiry ) {
		$subscriber = isset( $enquiry['fluentcrm_subscriber_id'] ) ? (int) $enquiry['fluentcrm_subscriber_id'] : 0;

		if ( $subscriber <= 0 ) {
			return '';
		}

		return admin_url( 'admin.php?page=fluentcrm-admin#/subscribers/' . $subscriber );
	}

	/**
	 * Present the notes of one enquiry, most recent first as the service read
	 * them (Requirement 10.7).
	 *
	 * The authoring user's display name is added alongside the identifier the
	 * service stores, so the panel does not have to resolve a user per note.
	 *
	 * @param array $notes Stored notes.
	 * @return array<int,array>
	 */
	protected static function present_notes( array $notes ) {
		$presented = array();

		foreach ( $notes as $note ) {
			if ( ! is_array( $note ) ) {
				continue;
			}

			$note['author'] = self::actor_name( isset( $note['author_id'] ) ? $note['author_id'] : 0 );

			$presented[] = $note;
		}

		return $presented;
	}

	/**
	 * Present the history of one enquiry, oldest first as the recorder read it
	 * (Requirement 11.4).
	 *
	 * @param array $entries Stored history entries.
	 * @return array<int,array>
	 */
	protected static function present_history( array $entries ) {
		$presented = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry['actor'] = self::actor_name( isset( $entry['actor_id'] ) ? $entry['actor_id'] : 0 );

			$presented[] = $entry;
		}

		return $presented;
	}

	/**
	 * The display name behind an actor identifier.
	 *
	 * Zero is the system attribution the recorder writes when no user is
	 * authenticated (Requirement 11.5). A non-zero identifier no longer matching
	 * a user is named rather than blanked, because a deleted account is still
	 * more informative than nothing.
	 *
	 * @param int $actor Actor identifier.
	 * @return string
	 */
	protected static function actor_name( $actor ) {
		$actor = (int) $actor;

		if ( $actor <= 0 ) {
			return self::SYSTEM_ACTOR_NAME;
		}

		$user = function_exists( 'get_userdata' ) ? get_userdata( $actor ) : null;

		if ( $user && '' !== (string) $user->display_name ) {
			return (string) $user->display_name;
		}

		/* translators: %d: user identifier. */
		return sprintf( __( 'User #%d', 'marthrown-enquiry-hub' ), $actor );
	}

	/**
	 * Sanitise a submitted boolean.
	 *
	 * A query string carries booleans as text, so `1`, `true`, `yes` and `on`
	 * all mean true. `rest_sanitize_boolean()` handles the same shapes and is
	 * used where it is available; the fallback keeps the route sanitised when it
	 * is not, which is what the pure test suite runs under.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public static function sanitize_bool( $value ) {
		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return (bool) rest_sanitize_boolean( $value );
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return (bool) $value;
	}
}
