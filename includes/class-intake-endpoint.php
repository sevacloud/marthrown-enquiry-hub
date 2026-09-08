<?php
/**
 * Intake Endpoint: the one public REST route an enquiry form posts to.
 *
 * `POST marthrown-enquiry-hub/v1/intake` is the only route in the namespace
 * whose `permission_callback` is not `Auth::rest_permission`: it authenticates
 * the Intake Secret instead, which is what lets a server-to-server webhook
 * carrying no WordPress user create an enquiry (Requirements 16.8, 16.9).
 *
 * Because authentication is a permission callback it runs before `handle()` is
 * ever reached, so a request presenting no secret or the wrong one creates no
 * enquiry, writes no rejected intake attempt and leaves no payload in the log
 * (Requirement 16.11). Nothing this class returns — body or header — carries the
 * secret value (Requirement 16.12).
 *
 * `normalise()` is the whole of the per-sender adaptation, done once for every
 * sender rather than once per form plugin (Requirement 2.12): it flattens the
 * request body, splits a delimited multi-value field into a list, and reads the
 * form identifier from the payload field named in Settings. Nothing in it names
 * a form plugin, and no configuration specific to one is required.
 *
 * The endpoint owns the outcome-to-status-code decision and the
 * `try`/`catch ( \Throwable )` (Requirement 5.7); `IntakeHandler::receive()`
 * owns the work and reports what happened. Because that catch is the one place
 * an authenticated request can end without the handler reporting an outcome, it
 * writes the rejected intake attempt itself, so no authenticated request that
 * created no enquiry goes unrecorded (Requirement 5.8).
 *
 * One outbound concern lives here too. The sending form's webhook action offers
 * a destination URL and field mappings and nothing else — it cannot be
 * configured to send a custom request header — so the header transport
 * Requirement 16.14 prefers would otherwise be unreachable and the site would be
 * left on the weaker query-parameter transport Requirement 16.15 describes.
 * `attach_secret_header()` closes that gap from this side: the form and this
 * plugin sit on the same site, so the webhook POST is a loopback request made by
 * WordPress, and an `http_request_args` filter can put the secret in the header
 * the form cannot. It is scoped as narrowly as the filter allows — see that
 * method — because `http_request_args` fires for every outbound request
 * WordPress makes, including requests to third parties.
 *
 * @package MarthrownEnquiryHub
 */

namespace MarthrownEnquiryHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IntakeEndpoint
 */
class IntakeEndpoint {

	/**
	 * REST namespace, shared with every other route the plugin registers.
	 */
	const NAMESPACE = 'marthrown-enquiry-hub/v1';

	/**
	 * The one route: `POST marthrown-enquiry-hub/v1/intake`.
	 */
	const ROUTE = '/intake';

	/**
	 * Option holding the Intake Secret (Requirement 16.8).
	 */
	const SECRET_OPTION = 'meh_intake_secret';

	/**
	 * Request header the secret is read from first (Requirement 16.14).
	 */
	const SECRET_HEADER = 'X-MEH-Intake-Secret';

	/**
	 * Query parameter the secret falls back to (Requirement 16.15).
	 */
	const SECRET_QUERY = 'meh_secret';

	/**
	 * Error code answered with 401 (Requirement 16.11).
	 */
	const UNAUTHORIZED_CODE = 'meh_intake_unauthorized';

	/**
	 * Error code answered with 500 (Requirement 5.7).
	 */
	const FAILED_CODE = 'meh_intake_failed';

	/**
	 * Option naming the payload field that holds the form identifier (Requirement 2.9).
	 */
	const SOURCE_FIELD_OPTION = 'meh_intake_source_field';

	/**
	 * Options naming the payload fields holding the ideal start/end date pair.
	 *
	 * A sending form's webhook action offers no way to submit a date range as one
	 * field, so a range arrives as two: a start date and an end date in separate
	 * payload fields. Both options have to hold a non-empty value, and both
	 * configured fields have to resolve to a parseable date, for a range to be
	 * read.
	 */
	const START_DATE_FIELD_OPTION = 'meh_intake_start_date_field';
	const END_DATE_FIELD_OPTION   = 'meh_intake_end_date_field';

	/**
	 * Options naming the payload fields holding the two optional alternatives.
	 *
	 * The ideal range keeps the original option names, so a site configured
	 * before alternatives existed keeps working with nothing to re-enter.
	 */
	const ALTERNATIVE_DATE_FIELD_OPTIONS = array(
		array( 'meh_intake_start_date_field_2', 'meh_intake_end_date_field_2' ),
		array( 'meh_intake_start_date_field_3', 'meh_intake_end_date_field_3' ),
	);

	/**
	 * Prefix applied to a resolved form identifier to form `source`.
	 */
	const SOURCE_PREFIX = 'webhook:';

	/**
	 * `source` when the payload carried no form identifier (Requirement 2.4).
	 *
	 * The same fixed value `IntakeHandler` falls back to, stated here because
	 * this class is the one that resolves the identifier.
	 */
	const SOURCE_UNIDENTIFIED = 'webhook:unidentified';

	/**
	 * Longest `source` the store's column holds.
	 */
	const SOURCE_LIMIT = 191;

	/**
	 * The enquiry fields a sender may deliver as a delimited string.
	 *
	 * Every other field is single-valued, so splitting it would corrupt a
	 * legitimate value — a `message` mentioning a comma, above all. `date_ranges`
	 * is not among them either, and cannot be: a delimited string has no way to
	 * say where one range ends and the next begins, which is why ranges arrive as
	 * configured pairs of fields instead.
	 */
	const MULTI_VALUE_FIELDS = array( 'event_type', 'site_exclusivity' );

	/**
	 * Delimiters a sender may use inside a multi-value field.
	 *
	 * Comma, semicolon, pipe, tab and newline. Space and slash are deliberately
	 * absent: they appear inside date formats a sender may legitimately use.
	 */
	const DELIMITERS = '/\s*(?:,|;|\||\t|\r\n|\r|\n)\s*/';

	/**
	 * Keys a nested node may carry to name the field it holds.
	 */
	const LABEL_KEYS = array( 'label', 'name', 'title', 'key' );

	/**
	 * Keys a nested node may carry to hold its value.
	 */
	const VALUE_KEYS = array( 'value', 'values', 'val' );

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Requirement 16.14: the sending form cannot be configured to send a
		// custom header, so the plugin attaches it to the loopback request the
		// form's webhook action makes.
		add_filter( 'http_request_args', array( __CLASS__, 'attach_secret_header' ), 10, 2 );
	}

	/**
	 * The intake endpoint's own URL.
	 *
	 * Composed from the namespace and route constants rather than written out,
	 * so the Settings screen, the outbound filter and the registered route
	 * cannot disagree about where intake lives.
	 *
	 * @return string The route path alone where `rest_url()` is unavailable.
	 */
	public static function url() {
		if ( ! function_exists( 'rest_url' ) ) {
			return self::NAMESPACE . self::ROUTE;
		}

		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/**
	 * Put the Intake Secret in the request header on an outbound intake request.
	 *
	 * The sending form's webhook action offers a destination URL and field
	 * mappings only, so it cannot send the `X-MEH-Intake-Secret` header itself.
	 * Because the form and this plugin are on the same site, the webhook POST is
	 * a loopback request WordPress makes while handling the form submission, and
	 * this filter is in place by then: the plugin boots on `plugins_loaded`, long
	 * before form processing. That is what makes the stronger transport
	 * Requirement 16.14 describes reachable without the vendor supporting it.
	 *
	 * `http_request_args` fires for every outbound HTTP request WordPress makes
	 * — update checks, licence checks, FluentCRM's own calls — so attaching the
	 * secret to the wrong one would hand it to a third party. Five conditions
	 * gate the attachment, and all five must hold:
	 *
	 *   1. The request is going to this site's own host, on the same port. A
	 *      foreign host carrying the same path gets nothing. The scheme is
	 *      deliberately not compared, because a site behind a TLS-terminating
	 *      proxy legitimately reaches itself over `http` while `rest_url()`
	 *      reports `https`, and refusing that would break intake rather than
	 *      protect anything the host check has not already protected.
	 *   2. The request resolves to the intake route itself, compared against
	 *      `self::url()` rather than by searching the URL for a substring.
	 *      Both REST URL shapes are handled: a pretty permalink path, and the
	 *      `rest_route` query parameter a site without pretty permalinks uses.
	 *      Query parameters other than `rest_route` are ignored, so a
	 *      destination URL still carrying `meh_secret` is matched.
	 *   3. A secret is stored. Nothing is attached when Settings holds none.
	 *   4. No `X-MEH-Intake-Secret` header is present already. A sender that
	 *      does send its own header keeps it; this filter never overrules the
	 *      request it is filtering.
	 *   5. `$args` is the array shape the filter documents.
	 *
	 * Nothing here is logged, so the secret value reaches no log (Requirement
	 * 16.12 in spirit: it is never written anywhere the endpoint can be read).
	 *
	 * @param mixed  $args Request arguments passed to `wp_remote_*`.
	 * @param string $url  Request URL.
	 * @return mixed The arguments, with the header added only when every
	 *               condition above holds.
	 */
	public static function attach_secret_header( $args, $url = '' ) {
		if ( ! is_array( $args ) || ! self::is_intake_url( $url ) ) {
			return $args;
		}

		$headers = isset( $args['headers'] ) ? $args['headers'] : array();

		if ( self::carries_secret_header( $headers ) ) {
			return $args;
		}

		$secret = self::stored_secret();

		if ( '' === $secret ) {
			return $args;
		}

		if ( is_string( $headers ) ) {
			// WordPress accepts a raw header block and converts it to an array
			// after this filter runs, so a line is appended rather than the
			// block being replaced with an array it would then mis-read.
			$block = rtrim( $headers, "\r\n" );

			$args['headers'] = ( '' === trim( $block ) ? '' : $block . "\r\n" )
				. self::SECRET_HEADER . ': ' . $secret;

			return $args;
		}

		if ( ! is_array( $headers ) ) {
			$headers = array();
		}

		$headers[ self::SECRET_HEADER ] = $secret;
		$args['headers']                = $headers;

		return $args;
	}

	/**
	 * Whether an outbound URL is this site's own intake endpoint.
	 *
	 * Compared against `self::url()` on two axes — the authority and the
	 * resolved route — so neither a foreign host on the intake path nor another
	 * route on this host matches.
	 *
	 * @param mixed $url Outbound request URL.
	 * @return bool
	 */
	protected static function is_intake_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return false;
		}

		$target = self::url_parts( $url );
		$intake = self::url_parts( self::url() );

		if ( array() === $target || array() === $intake ) {
			return false;
		}

		$own = self::authority( $intake );

		// An intake URL with no host means `rest_url()` was unavailable, which
		// is not a state in which any outbound request should be trusted.
		if ( '' === $own || $own !== self::authority( $target ) ) {
			return false;
		}

		return self::route_identity( $intake ) === self::route_identity( $target );
	}

	/**
	 * A URL's components, or an empty array when it cannot be parsed.
	 *
	 * @param string $url URL to parse.
	 * @return array
	 */
	protected static function url_parts( $url ) {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return is_array( $parts ) ? $parts : array();
	}

	/**
	 * A URL's host and port, lower-cased, with a default port dropped.
	 *
	 * `https://example.com` and `https://example.com:443` are the same
	 * authority, so the default port for the scheme is normalised away rather
	 * than counted as a difference.
	 *
	 * @param array $parts Parsed URL components.
	 * @return string Empty when the URL named no host.
	 */
	protected static function authority( array $parts ) {
		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if ( '' === $host ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}

		return $port > 0 ? $host . ':' . $port : $host;
	}

	/**
	 * The route a URL resolves to, whichever REST URL shape it uses.
	 *
	 * A site with pretty permalinks addresses a route by path; a site without
	 * them addresses it through the `rest_route` query parameter. Both are
	 * reduced to one comparable value, and every other query parameter is
	 * ignored so a destination URL still carrying `meh_secret` matches.
	 *
	 * @param array $parts Parsed URL components.
	 * @return string
	 */
	protected static function route_identity( array $parts ) {
		$query = array();

		if ( isset( $parts['query'] ) && is_string( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		if ( isset( $query['rest_route'] ) && is_string( $query['rest_route'] ) && '' !== trim( $query['rest_route'] ) ) {
			return 'route:' . trim( $query['rest_route'], '/' );
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';

		return 'path:' . trim( $path, '/' );
	}

	/**
	 * Whether request headers already carry the Intake Secret header.
	 *
	 * Header names are case-insensitive, and WordPress accepts them either as
	 * an array or as a raw block, so both shapes are inspected. A header already
	 * present is left alone whatever its value: overwriting it would make this
	 * filter the authority on a request it does not own.
	 *
	 * @param mixed $headers Header array or raw header block.
	 * @return bool
	 */
	protected static function carries_secret_header( $headers ) {
		$wanted = strtolower( self::SECRET_HEADER );

		if ( is_string( $headers ) ) {
			return false !== stripos( $headers, self::SECRET_HEADER . ':' );
		}

		if ( ! is_array( $headers ) ) {
			return false;
		}

		foreach ( array_keys( $headers ) as $name ) {
			if ( is_string( $name ) && strtolower( $name ) === $wanted ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register `POST marthrown-enquiry-hub/v1/intake`.
	 *
	 * One route, no declared `args`: the body is whatever the sending form
	 * chose to post, and declaring per-field args here would be exactly the
	 * sender-specific configuration Requirement 2.12 rules out. Sanitisation
	 * belongs to the Validator, which sees every field under one set of rules.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Requirement 16.9, and the reason 16.11 holds: a permission
				// callback runs before the callback, so an unauthenticated
				// request reaches no store write at all.
				'permission_callback' => array( __CLASS__, 'authenticate' ),
			)
		);
	}

	/**
	 * Authenticate the request against the stored Intake Secret.
	 *
	 * `hash_equals()` rather than `===`, so the comparison's execution time
	 * does not depend on the position of the first differing character
	 * (Requirement 16.10).
	 *
	 * The comparison is exact: the presented value is compared as the sender
	 * sent it, byte for byte, with no trimming, folding or other normalisation.
	 * A value differing from the stored secret in any way at all — a prefix, a
	 * suffix, a case change, added whitespace, a truncation — is a value that
	 * did not authenticate (Requirement 16.11). Trimming the presented value
	 * before comparing would let `%20SECRET%20` through, which is a real
	 * difference an attacker controls, not a typo worth forgiving.
	 *
	 * An unset stored secret denies every request. `hash_equals( '', '' )` is
	 * true, so treating an empty stored value as comparable would leave the
	 * route wide open on a site that has not configured Settings yet. The same
	 * guard covers a request presenting nothing: `presented_secret()` reports
	 * an absent, empty or whitespace-only value as the empty string, so such a
	 * request is refused before any comparison is attempted.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return true|\WP_Error True when authenticated, 401 otherwise.
	 */
	public static function authenticate( $request ) {
		$stored    = self::stored_secret();
		$presented = self::presented_secret( $request );

		if ( '' === $stored || '' === $presented || ! hash_equals( $stored, $presented ) ) {
			// The message names no value: neither the presented secret nor the
			// stored one appears in the response (Requirement 16.12).
			return new \WP_Error(
				self::UNAUTHORIZED_CODE,
				__( 'This request did not authenticate.', 'marthrown-enquiry-hub' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Turn one authenticated request into one enquiry.
	 *
	 * The receipt time is read here, before any work, so `created_at` records
	 * when the request arrived rather than when the store write happened
	 * (Requirement 2.2).
	 *
	 * 201 with the enquiry identifier when an enquiry was created; 200 with the
	 * reason when none was, because the sending form has already accepted the
	 * submission and will not retry — a rejected intake attempt is the recovery
	 * path, not an HTTP error (Requirement 4.1). 500 only for an unhandled
	 * throwable, which is recorded as a rejected intake attempt too before the
	 * error is answered (Requirements 5.7, 5.8).
	 *
	 * @param \WP_REST_Request $request Authenticated request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( $request ) {
		$received_at = Clock::mysql();
		$fields      = array();
		$source      = self::SOURCE_UNIDENTIFIED;

		try {
			$fields = self::normalise( $request );
			$source = self::source( $fields );
			$result = IntakeHandler::receive( $fields, $source, $received_at );
		} catch ( \Throwable $error ) {
			// Requirement 5.7. The store writes an enquiry and its child rows in
			// one transaction, so there is nothing partial to undo here; what is
			// needed is a trace, because the sender keeps no failed request.
			Log::write(
				'intake: unhandled error',
				array(
					'error'   => $error->getMessage(),
					'at'      => $received_at,
					'payload' => $fields,
				)
			);

			// Requirement 5.8. This request authenticated and created no enquiry,
			// so it earns a rejected intake attempt like every other uncreated
			// outcome — the log alone is not exposed by the route Requirement 4
			// defines, and the sending form does not retry.
			self::record_failure( $fields, $source, $received_at, $error );

			return new \WP_Error(
				self::FAILED_CODE,
				__( 'This enquiry could not be processed.', 'marthrown-enquiry-hub' ),
				array( 'status' => 500 )
			);
		}

		if ( ! empty( $result['created'] ) ) {
			return new \WP_REST_Response(
				array(
					'created'    => true,
					'enquiry_id' => (int) $result['enquiry_id'],
				),
				201
			);
		}

		return new \WP_REST_Response(
			array(
				'created' => false,
				'reason'  => isset( $result['reason'] ) ? (string) $result['reason'] : '',
				'errors'  => isset( $result['errors'] ) ? (array) $result['errors'] : array(),
			),
			200
		);
	}

	/**
	 * Record the one rejected intake attempt an unhandled failure earns.
	 *
	 * Requirement 5.8 covers every authenticated request that creates no
	 * enquiry, not only the outcomes `IntakeHandler` reports, so the throwable
	 * branch writes its own row. `storage` is the reason: of the four
	 * Requirement 4.1 permits, it is the only one that describes a request the
	 * plugin failed to carry through, and an unhandled throwable is not a
	 * duplicate, a rate limit or a validation failure.
	 *
	 * Exactly one row, because a throwable reaching here means `IntakeHandler`
	 * wrote none. Every path through `receive()` that records a rejection does
	 * so as its last act before returning — the row is written and the outcome
	 * returned with nothing left to fail in between — so a throwable escaping
	 * the handler escaped it before any rejection was recorded.
	 *
	 * The detail carries the same keys `IntakeHandler::reject()` uses, so this
	 * row reads like the ones the handler writes; `received_at` is what the
	 * store lifts onto `created_at`, which is how the row records the receipt
	 * time rather than the time of this write.
	 *
	 * The whole write is guarded. A throwable raised while recording the
	 * rejection — a store refusing writes is exactly the sort of fault that got
	 * here — must not escape and turn a 500 into a fatal, so it is swallowed and
	 * the error log stays the last-resort trace.
	 *
	 * @param array      $payload     Normalised payload, empty when the failure
	 *                                preceded normalisation.
	 * @param string     $source      Resolved source, self::SOURCE_UNIDENTIFIED
	 *                                when the failure preceded resolution.
	 * @param string     $received_at Time the request arrived (Requirement 2.2).
	 * @param \Throwable $error       The unhandled failure.
	 * @return void
	 */
	protected static function record_failure( array $payload, $source, $received_at, $error ) {
		try {
			EnquiryStore::record_rejection(
				$payload,
				IntakeHandler::REASON_STORAGE,
				array(
					'email'       => self::submitted_email( $payload ),
					'source'      => IntakeHandler::source( $source ),
					'received_at' => $received_at,
					'is_test'     => StagingMarker::is_staging() ? 1 : 0,
					'errors'      => array(),
					'error'       => $error->getMessage(),
				)
			);
		} catch ( \Throwable $failed ) {
			Log::write(
				'intake: rejected intake attempt could not be recorded',
				array(
					'error' => $failed->getMessage(),
					'at'    => $received_at,
				)
			);
		}
	}

	/**
	 * The submitted email address, read straight out of the payload.
	 *
	 * Deliberately not resolved through `FieldMapper`: the failure being
	 * recorded may be the one that field resolution raised, and reading the
	 * configured map again would raise it a second time. A submitted key or
	 * label of `email` is matched the same loose way, and where that finds
	 * nothing the store falls back to the payload itself.
	 *
	 * @param array $payload Normalised payload.
	 * @return string Empty when the payload carries no readable address.
	 */
	protected static function submitted_email( array $payload ) {
		$key = self::matching_key( $payload, 'email' );

		if ( null === $key || ! is_scalar( $payload[ $key ] ) ) {
			return '';
		}

		return trim( (string) $payload[ $key ] );
	}

	/**
	 * The request body as a flat field map.
	 *
	 * JSON or form-encoded, nested or flat, `label`/`value` pairs or plain
	 * key-value: every shape collapses to one map of submitted key or label =>
	 * value, the two multi-value fields arrive as lists whether the sender
	 * delivered an array or a delimited string, and the configured start/end
	 * field pairs arrive as `date_ranges`. That is what makes two senders carrying
	 * the same values produce the same enquiry (Requirement 2.12).
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return array<string,mixed>
	 */
	public static function normalise( $request ) {
		$flat = self::flatten( self::body( $request ) );
		$flat = self::collect_date_ranges( $flat );

		return self::split_multi_values( $flat );
	}

	/**
	 * Gather the configured start/end date pairs into `date_ranges`.
	 *
	 * A sending form's webhook action offers no way to submit a date range as a
	 * single field, so each range arrives as two payload fields named in Settings:
	 * the ideal pair, then up to two alternative pairs. This collects whichever of
	 * them the request carried into the one `date_ranges` list the Validator and
	 * the store speak in, ideal range first.
	 *
	 * A pair is read only when both its options are configured, both fields are
	 * present in the payload, and both parse as a date. Anything less is skipped
	 * rather than half-read: an alternative pair the visitor left blank is the
	 * ordinary case, and one where only the start arrived is a range whose end
	 * nobody named. Skipping every pair leaves no `date_ranges` at all, which the
	 * Validator reports as `too_few_ranges` — the same failure an omitted field
	 * produces, which is what it is.
	 *
	 * An explicit `date_ranges` in the payload wins outright: a sender populating
	 * it has said what it means more directly than a configured field name can,
	 * and preferring the field the sender actually filled in is the safer reading
	 * of an ambiguous payload.
	 *
	 * The source fields are removed from the flat map once read, so they cannot be
	 * mistaken for another field by the label matching `FieldMapper` falls back
	 * to.
	 *
	 * @param array $flat Flattened field map.
	 * @return array<string,mixed>
	 */
	protected static function collect_date_ranges( array $flat ) {
		$existing = self::matching_key( $flat, 'date_ranges' );

		if ( null !== $existing && ! self::is_empty_value( $flat[ $existing ] ) ) {
			return $flat;
		}

		$ranges = array();

		foreach ( self::date_field_pairs() as $pair ) {
			list( $start_option, $end_option ) = $pair;

			$start_field = self::configured_date_field( $start_option );
			$end_field   = self::configured_date_field( $end_option );

			if ( '' === $start_field || '' === $end_field ) {
				continue;
			}

			$start_key = self::matching_key( $flat, $start_field );
			$end_key   = self::matching_key( $flat, $end_field );

			if ( null === $start_key || null === $end_key ) {
				continue;
			}

			$range = self::range_between( $flat[ $start_key ], $flat[ $end_key ] );

			unset( $flat[ $start_key ], $flat[ $end_key ] );

			if ( null !== $range ) {
				$ranges[] = $range;
			}
		}

		if ( array() === $ranges ) {
			return $flat;
		}

		$flat['date_ranges'] = $ranges;

		return $flat;
	}

	/**
	 * The configured start/end option pairs, ideal pair first.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	protected static function date_field_pairs() {
		return array_merge(
			array( array( self::START_DATE_FIELD_OPTION, self::END_DATE_FIELD_OPTION ) ),
			self::ALTERNATIVE_DATE_FIELD_OPTIONS
		);
	}

	/**
	 * A configured start/end date field option, trimmed.
	 *
	 * @param string $option Option name.
	 * @return string Empty when unconfigured.
	 */
	protected static function configured_date_field( $option ) {
		$configured = function_exists( 'get_option' ) ? get_option( $option, '' ) : '';

		return is_scalar( $configured ) ? trim( (string) $configured ) : '';
	}

	/**
	 * Whether a flattened field's value counts as carrying nothing.
	 *
	 * @param mixed $value Field value.
	 * @return bool
	 */
	protected static function is_empty_value( $value ) {
		if ( is_array( $value ) ) {
			return array() === $value;
		}

		return ! is_scalar( $value ) || '' === trim( (string) $value );
	}

	/**
	 * One range from a submitted start value and end value.
	 *
	 * Uses the same accepted date formats the Validator itself parses, via
	 * `Validator::parse_date()`, so a range sent in any format a date may arrive
	 * in is understood the same way. A single day is a range whose bounds match,
	 * which is what a visitor picking one date produces.
	 *
	 * An end before the start, or either bound failing to parse, returns null
	 * rather than a guess about which of the two the sender meant.
	 *
	 * @param mixed $start Submitted start date value.
	 * @param mixed $end   Submitted end date value.
	 * @return array{start:string,end:string}|null Null when the pair does not
	 *                                             describe a usable range.
	 */
	protected static function range_between( $start, $end ) {
		$start_date = Validator::parse_date( $start );
		$end_date   = Validator::parse_date( $end );

		if ( null === $start_date || null === $end_date || $end_date < $start_date ) {
			return null;
		}

		return array(
			'start' => $start_date,
			'end'   => $end_date,
		);
	}

	/**
	 * The secret the request presented, header first (Requirements 16.14, 16.15).
	 *
	 * The header wins where the request carries one: the query parameter is the
	 * documented fallback for a sender whose webhook action supports no custom
	 * header, and it is weaker because a request URL is recorded in server
	 * access logs.
	 *
	 * Only the query string is consulted for the fallback, never the body, so a
	 * payload field happening to be named `meh_secret` authenticates nothing.
	 *
	 * The value is returned exactly as sent, untrimmed, because
	 * `authenticate()` compares it exactly (Requirement 16.11). Trimming is
	 * used for one decision only — whether the request presented anything at
	 * all — so an absent, empty or whitespace-only value reports as the empty
	 * string and is refused, while ` SECRET ` is returned padded and fails the
	 * comparison on its own merits.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return string Empty when the request presented no secret.
	 */
	protected static function presented_secret( $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return '';
		}

		$header = $request->get_header( self::SECRET_HEADER );

		if ( is_string( $header ) && '' !== trim( $header ) ) {
			return $header;
		}

		$query = $request->get_query_params();

		if ( isset( $query[ self::SECRET_QUERY ] ) && is_scalar( $query[ self::SECRET_QUERY ] ) ) {
			$presented = (string) $query[ self::SECRET_QUERY ];

			return '' === trim( $presented ) ? '' : $presented;
		}

		return '';
	}

	/**
	 * The Intake Secret held in Settings.
	 *
	 * Trimmed, unlike the presented value. The stored value is the site owner's
	 * own configured secret rather than attacker-controlled input: a space
	 * pasted into the Settings field and captured by `update_option` is a
	 * configuration slip, and forgiving it here costs nothing because the
	 * trimmed value is still the only value that authenticates. Trimming the
	 * presented value would instead widen what authenticates, which is what
	 * Requirement 16.11 rules out.
	 *
	 * @return string Empty when none is stored.
	 */
	protected static function stored_secret() {
		$stored = function_exists( 'get_option' ) ? get_option( self::SECRET_OPTION, '' ) : '';

		return is_scalar( $stored ) ? trim( (string) $stored ) : '';
	}

	/**
	 * The `source` to store, from the configured form identifier field.
	 *
	 * Read from the payload field named in Settings and nowhere else: guessing
	 * at other keys would make `source` depend on the sending form plugin. When
	 * that field is unconfigured, absent or empty, `source` is the fixed
	 * `webhook:unidentified` (Requirements 2.3, 2.4).
	 *
	 * @param array $fields Normalised field map.
	 * @return string
	 */
	protected static function source( array $fields ) {
		$configured = function_exists( 'get_option' ) ? get_option( self::SOURCE_FIELD_OPTION, '' ) : '';
		$configured = is_scalar( $configured ) ? trim( (string) $configured ) : '';

		if ( '' === $configured ) {
			return self::SOURCE_UNIDENTIFIED;
		}

		$key = self::matching_key( $fields, $configured );

		if ( null === $key || ! is_scalar( $fields[ $key ] ) ) {
			return self::SOURCE_UNIDENTIFIED;
		}

		$identifier = (string) $fields[ $key ];
		$identifier = function_exists( 'wp_strip_all_tags' )
			? wp_strip_all_tags( $identifier )
			: strip_tags( $identifier ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$identifier = trim( (string) preg_replace( '/\s+/', ' ', $identifier ) );

		if ( '' === $identifier ) {
			return self::SOURCE_UNIDENTIFIED;
		}

		$identifier = substr( $identifier, 0, self::SOURCE_LIMIT - strlen( self::SOURCE_PREFIX ) );

		return self::SOURCE_PREFIX . $identifier;
	}

	/**
	 * The decoded request body, whatever its content type.
	 *
	 * JSON parameters first, then form-encoded ones, then the raw body decoded
	 * by hand — the last covers a sender posting JSON under a content type
	 * WordPress does not recognise as JSON, which is common enough in webhook
	 * actions to be worth handling rather than rejecting.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return array
	 */
	protected static function body( $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return array();
		}

		$json = $request->get_json_params();

		if ( is_array( $json ) && array() !== $json ) {
			return $json;
		}

		$form = $request->get_body_params();

		if ( is_array( $form ) && array() !== $form ) {
			return $form;
		}

		$raw = (string) $request->get_body();

		if ( '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$parsed = array();
		parse_str( $raw, $parsed );

		return is_array( $parsed ) ? $parsed : array();
	}

	/**
	 * Flatten a decoded body to one level.
	 *
	 * Four shapes are recognised, which between them cover every sender seen:
	 * a scalar under a key is that key's value; a node carrying both a label
	 * key and a value key is that label's value, which is how a form plugin
	 * delivering `{ label, value }` objects collapses to the same map as a
	 * plain one; a candidate date range, or a list of them, is kept whole;
	 * anything else is walked recursively, so a wrapper object such as `fields`
	 * or `data` disappears rather than hiding the values inside it.
	 *
	 * The range shapes are recognised here rather than left to the recursion
	 * because a range is the one field whose value is itself structured: walked
	 * as a wrapper, a `date_ranges` list would come back as one unnamed entry
	 * per range, and the field a sender populated directly would be lost.
	 *
	 * A key already present is not overwritten, so a top-level value always
	 * beats one found deeper and the result does not depend on how deeply a
	 * sender nested a repeated key.
	 *
	 * @param array $data Decoded body, or a node within it.
	 * @return array<string,mixed>
	 */
	protected static function flatten( array $data ) {
		$flat = array();

		foreach ( $data as $key => $value ) {
			$key = is_string( $key ) ? trim( $key ) : (string) $key;

			if ( ! is_array( $value ) ) {
				self::put( $flat, $key, $value );
				continue;
			}

			$pair = self::labelled_pair( $value );

			if ( null !== $pair ) {
				self::put( $flat, '' === $pair[0] ? $key : $pair[0], $pair[1] );
				continue;
			}

			// Before the value-list test, which would otherwise read a lone
			// range's two bounds as two separate bare dates.
			if ( self::is_range_node( $value ) ) {
				self::put( $flat, $key, array( $value ) );
				continue;
			}

			if ( self::is_range_list( $value ) ) {
				self::put( $flat, $key, array_values( $value ) );
				continue;
			}

			if ( self::is_value_list( $value ) ) {
				self::put( $flat, $key, array_values( $value ) );
				continue;
			}

			foreach ( self::flatten( $value ) as $nested_key => $nested_value ) {
				self::put( $flat, $nested_key, $nested_value );
			}
		}

		return $flat;
	}

	/**
	 * Record a flattened key, leaving an already-recorded one alone.
	 *
	 * @param array  $flat  Flat map, by reference.
	 * @param string $key   Key or label.
	 * @param mixed  $value Value.
	 * @return void
	 */
	protected static function put( array &$flat, $key, $value ) {
		if ( '' === $key || array_key_exists( $key, $flat ) ) {
			return;
		}

		$flat[ $key ] = $value;
	}

	/**
	 * A node's label and value, where it carries both.
	 *
	 * Both are required. A node holding a `name` but no `value` is a plain map
	 * of fields — `{ name: 'Ada', email: '…' }` — and must be walked, not read
	 * as one label/value pair.
	 *
	 * @param array $node Node to inspect.
	 * @return array{0:string,1:mixed}|null
	 */
	protected static function labelled_pair( array $node ) {
		$label = null;

		foreach ( self::LABEL_KEYS as $candidate ) {
			if ( isset( $node[ $candidate ] ) && is_scalar( $node[ $candidate ] ) && '' !== trim( (string) $node[ $candidate ] ) ) {
				$label = trim( (string) $node[ $candidate ] );
				break;
			}
		}

		if ( null === $label ) {
			return null;
		}

		foreach ( self::VALUE_KEYS as $candidate ) {
			if ( ! array_key_exists( $candidate, $node ) ) {
				continue;
			}

			$value = $node[ $candidate ];

			if ( is_array( $value ) ) {
				$value = self::is_value_list( $value ) ? array_values( $value ) : null;

				if ( null === $value ) {
					return null;
				}
			}

			return array( $label, $value );
		}

		return null;
	}

	/**
	 * Whether a node is one candidate date range: a `start`/`end` pair.
	 *
	 * Both keys are optional — a half-drawn range is still a range, and the
	 * Validator is the one that says so — but no other key is allowed, so a
	 * wrapper object that merely happens to carry a `start` is still walked.
	 *
	 * @param array $node Node to inspect.
	 * @return bool
	 */
	protected static function is_range_node( array $node ) {
		if ( ! array_key_exists( 'start', $node ) && ! array_key_exists( 'end', $node ) ) {
			return false;
		}

		foreach ( $node as $key => $bound ) {
			if ( 'start' !== $key && 'end' !== $key ) {
				return false;
			}

			if ( ! is_scalar( $bound ) && null !== $bound ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a node is a list of candidate date ranges.
	 *
	 * At least one entry has to be a range object, because a list of nothing but
	 * bare dates is an ordinary value list and is read as one. Bare dates
	 * alongside range objects are allowed: a single day is a range whose bounds
	 * match, and the store accepts it written either way.
	 *
	 * @param array $value Node to inspect.
	 * @return bool
	 */
	protected static function is_range_list( array $value ) {
		$ranges = 0;

		foreach ( $value as $entry ) {
			if ( is_scalar( $entry ) || null === $entry ) {
				continue;
			}

			if ( ! is_array( $entry ) || ! self::is_range_node( $entry ) ) {
				return false;
			}

			++$ranges;
		}

		return $ranges > 0;
	}

	/**
	 * Whether an array is a plain list of scalars, as a multi-select delivers.
	 *
	 * @param array $value Array to inspect.
	 * @return bool
	 */
	protected static function is_value_list( array $value ) {
		if ( array() === $value ) {
			return true;
		}

		foreach ( $value as $entry ) {
			if ( is_array( $entry ) || is_object( $entry ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Decode the two multi-value fields to lists.
	 *
	 * Applied to whichever submitted key supplies each field — the key
	 * configured in `meh_field_map`, or the field name matched against a
	 * submitted label — so a sender delivering "2026-05-01, 2026-05-02" and one
	 * delivering `["2026-05-01","2026-05-02"]` produce the same field map.
	 *
	 * A single value becomes a one-entry list rather than staying a scalar, so
	 * the shape does not vary with how many values the visitor picked.
	 *
	 * @param array $flat Flattened field map.
	 * @return array<string,mixed>
	 */
	protected static function split_multi_values( array $flat ) {
		$mapping = FieldMapper::mapping();

		foreach ( self::MULTI_VALUE_FIELDS as $field ) {
			$wanted = array();

			if ( isset( $mapping[ $field ] ) ) {
				$wanted[] = $mapping[ $field ];
			}

			$wanted[] = $field;

			foreach ( $wanted as $candidate ) {
				$key = self::matching_key( $flat, $candidate );

				if ( null === $key ) {
					continue;
				}

				$flat[ $key ] = self::to_list( $flat[ $key ] );
				break;
			}
		}

		return $flat;
	}

	/**
	 * A submitted value as a list.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<int,mixed>
	 */
	protected static function to_list( $value ) {
		if ( is_array( $value ) ) {
			return array_values( $value );
		}

		if ( ! is_scalar( $value ) ) {
			return array();
		}

		$parts  = preg_split( self::DELIMITERS, trim( (string) $value ) );
		$parts  = is_array( $parts ) ? $parts : array();
		$values = array();

		foreach ( $parts as $part ) {
			$part = trim( (string) $part );

			if ( '' !== $part ) {
				$values[] = $part;
			}
		}

		return $values;
	}

	/**
	 * The key in a flat map supplying a wanted key or label, or null.
	 *
	 * Exact match first, then case- and separator-insensitive, matching how
	 * `FieldMapper` resolves a value. This returns the key rather than the
	 * value because the caller has to write back to it.
	 *
	 * @param array  $flat   Flat field map.
	 * @param string $wanted Key or label to find.
	 * @return string|null
	 */
	protected static function matching_key( array $flat, $wanted ) {
		if ( array_key_exists( $wanted, $flat ) ) {
			return $wanted;
		}

		$target = self::canonical( $wanted );

		if ( '' === $target ) {
			return null;
		}

		foreach ( $flat as $key => $ignored ) {
			if ( self::canonical( $key ) === $target ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Canonical form of a key or label: lower case, alphanumerics only.
	 *
	 * @param mixed $key Key or label.
	 * @return string
	 */
	protected static function canonical( $key ) {
		if ( ! is_scalar( $key ) ) {
			return '';
		}

		$key = preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $key ) );

		return null === $key ? '' : $key;
	}
}
