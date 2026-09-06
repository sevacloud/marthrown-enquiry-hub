/**
 * Test support: a routing stand-in for `@wordpress/api-fetch`.
 *
 * The component tests mock the transport rather than `../api`, so the real
 * client code runs: the paths and methods the components cause, the request
 * bodies they build, and the `WP_Error` envelope unwrapping a 400 goes through
 * are all exercised rather than assumed. Nothing reaches the network.
 */

/**
 * The `Response`-like object `parse: false` callers unwrap.
 *
 * The list wrappers read the body with `response.json()` and the totals from
 * the pagination headers, falling back to the body's own totals when a header
 * is absent — which is what a missing header returns here.
 *
 * @param {Object} body Response body.
 * @return {Object} Response stand-in.
 */
function rawResponse( body ) {
	return {
		json: async () => body,
		headers: { get: () => null },
	};
}

/**
 * An `apiFetch` implementation answering a `"METHOD path"` map.
 *
 * A request no handler covers is a rejection naming it, so a component reaching
 * for a route the test did not intend fails loudly instead of receiving
 * `undefined`.
 *
 * @param {Object} handlers `"GET enquiries"` => ( options ) => body.
 * @return {Function} apiFetch implementation.
 */
export function respondWith( handlers ) {
	return async ( options = {} ) => {
		const path = String( options.path || '' ).split( '?' )[ 0 ];
		const method = String( options.method || 'GET' ).toUpperCase();
		const key = `${ method } ${ path }`;
		const handler = handlers[ key ];

		if ( ! handler ) {
			throw new Error( `Unexpected request: ${ key }` );
		}

		const body = await handler( options );

		return options.parse === false ? rawResponse( body ) : body;
	};
}

/**
 * The rejection a validation failure arrives as.
 *
 * WordPress renders a `WP_Error` as `{ code, message, data: { status, errors } }`,
 * so a test 400 has to carry the per-field map at that nesting for the client's
 * unwrapping to be the thing under test.
 *
 * @param {Object} errors field => code.
 * @return {Object} Rejection value.
 */
export function validationFailure( errors ) {
	return {
		code: 'meh_invalid_fields',
		message: 'Some fields were invalid.',
		data: { status: 400, errors },
	};
}
