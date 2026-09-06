/**
 * REST API client for the Enquiry Hub.
 *
 * Thin wrappers around @wordpress/api-fetch. The root URL and nonce middleware
 * are configured once in index.js, so every call below carries `X-WP-Nonce`
 * (Requirements 16.5, 18.21, 19.19) without each wrapper restating it — there is
 * one transport here, not one per route.
 *
 * The enquiry routes are the ones the Enquiry Store exposes under
 * `marthrown-enquiry-hub/v1`: the list and single reads, manual creation, the
 * correction, the lifecycle and note writes, re-raise, conversion, the contact
 * linkage retry, the rejected intake attempts, the migration and the staging
 * clean-up.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Fetch enquiries with optional filters (Requirement 12.1).
 *
 * @param {Object} params { status, s, from, to, date_from, date_to, hide_test, orderby, order, page, per_page }
 * @return {Promise<{items: Array, counts: Object, warnings: Array, total: number, totalPages: number, page: number, perPage: number}>}
 */
export async function getEnquiries( params = {} ) {
	const { body, response } = await fetchRaw(
		addQueryArgs( 'enquiries', params )
	);
	return {
		items: body.items || [],
		counts: body.counts || {},
		warnings: body.warnings || [],
		...paging( body, response ),
	};
}

/**
 * Fetch one enquiry with its dates, terms, notes, history and allowed
 * transitions (Requirement 13.1).
 *
 * @param {number} id Enquiry id.
 * @return {Promise<Object>}
 */
export function getEnquiry( id ) {
	return apiFetch( { path: `enquiries/${ id }` } );
}

/**
 * Create one enquiry by hand (Requirement 18.1).
 *
 * Optional fields left blank should be omitted from `fields` rather than sent
 * empty: the Manual Validation Profile treats an absent optional field as not
 * supplied, and an empty one as a value that failed its rule.
 *
 * A 400 rejection carries a per-field `errors` map, reachable on the thrown
 * error as `error.errors` as well as at its `data.errors` origin.
 *
 * @param {Object} fields { first_name, last_name, email, selected_dates, … }
 * @return {Promise<Object>} The created enquiry.
 */
export function createEnquiry( fields = {} ) {
	return writeFields( 'enquiries', 'POST', fields );
}

/**
 * Correct an existing enquiry's stored values (Requirement 19.1).
 *
 * Partial by design: send only the fields the user altered. The response carries
 * a `changed` map, empty when the submission matched what was already stored.
 *
 * A 400 rejection carries a per-field `errors` map, as manual creation does.
 *
 * @param {number} id     Enquiry id.
 * @param {Object} fields The altered fields only.
 * @return {Promise<Object>} The updated enquiry, with `changed`.
 */
export function updateEnquiry( id, fields = {} ) {
	return writeFields( `enquiries/${ id }`, 'PATCH', fields );
}

/**
 * Apply a lifecycle transition.
 *
 * @param {number} id     Enquiry id.
 * @param {string} status new|contacted|quoted|converted|lost|closed.
 * @return {Promise<Object>}
 */
export function setEnquiryStatus( id, status ) {
	return apiFetch( {
		path: `enquiries/${ id }/status`,
		method: 'POST',
		data: { status },
	} );
}

/**
 * Add an internal note to an enquiry.
 *
 * @param {number} id   Enquiry id.
 * @param {string} body Note body.
 * @return {Promise<Object>}
 */
export function addEnquiryNote( id, body ) {
	return apiFetch( {
		path: `enquiries/${ id }/notes`,
		method: 'POST',
		data: { body },
	} );
}

/**
 * Re-raise an enquiry as a new one.
 *
 * @param {number} id Source enquiry id.
 * @return {Promise<Object>} The copy, with `source_id`.
 */
export function duplicateEnquiry( id ) {
	return apiFetch( {
		path: `enquiries/${ id }/duplicate`,
		method: 'POST',
	} );
}

/**
 * Create a WP Booking System booking from an enquiry (Requirement 14.1).
 *
 * @param {number} id         Enquiry id.
 * @param {number} calendarId Target calendar id.
 * @param {string} date       The chosen candidate date (Y-m-d).
 * @return {Promise<Object>} The enquiry, with `booking`.
 */
export function convertEnquiry( id, calendarId, date ) {
	return apiFetch( {
		path: `enquiries/${ id }/convert`,
		method: 'POST',
		data: { calendar_id: calendarId, date },
	} );
}

/**
 * Retry contact linkage for an enquiry left `pending`.
 *
 * @param {number} id Enquiry id.
 * @return {Promise<Object>}
 */
export function retryEnquiryCrm( id ) {
	return apiFetch( {
		path: `enquiries/${ id }/retry-crm`,
		method: 'POST',
	} );
}

/**
 * Fetch the intake attempts that produced no enquiry.
 *
 * @param {Object} params { reason, s, from, to, hide_test, page, per_page }
 * @return {Promise<{items: Array, reasons: Array, total: number, totalPages: number, page: number, perPage: number}>}
 */
export async function getRejections( params = {} ) {
	const { body, response } = await fetchRaw(
		addQueryArgs( 'enquiries/rejections', params )
	);
	return {
		items: body.items || [],
		reasons: body.reasons || [],
		...paging( body, response ),
	};
}

/**
 * Run or preview the FluentCRM migration.
 *
 * Previews unless told otherwise, matching the route's own default, so a
 * mis-wired control cannot import a CRM by accident.
 *
 * @param {boolean} preview Report what a run would create without creating it.
 * @return {Promise<Object>}
 */
export function runMigration( preview = true ) {
	return apiFetch( {
		path: 'enquiries/migration',
		method: 'POST',
		data: { preview: !! preview },
	} );
}

/**
 * Delete every staging enquiry.
 *
 * @return {Promise<{deleted: number}>}
 */
export function deleteTestRecords() {
	return apiFetch( {
		path: 'enquiries/test-records',
		method: 'DELETE',
	} );
}

/**
 * The per-field `errors` map a rejected write carries, or an empty map.
 *
 * WordPress renders a `WP_Error` as `{ code, message, data: { status, errors } }`,
 * so the map arrives nested under `data`. Reading it through here means a form
 * never has to know that, and never has to guard the several shapes a failure
 * that is not a validation failure can take.
 *
 * @param {*} error Whatever a write wrapper rejected with.
 * @return {Object} field => message.
 */
export function fieldErrors( error ) {
	if ( ! error || typeof error !== 'object' ) {
		return {};
	}

	const data = error.data && typeof error.data === 'object' ? error.data : {};
	const errors = data.errors || error.errors;

	return errors && typeof errors === 'object' ? errors : {};
}

/**
 * Fetch bookings (WPBS list view), returning items, status counts and totals.
 *
 * @param {Object} params { status, s, from, to, hide_past, page, per_page }
 * @return {Promise<{items: Array, counts: Object, available: boolean, total: number, totalPages: number}>}
 */
export async function getBookings( params = {} ) {
	const { body, response } = await fetchRaw( addQueryArgs( 'bookings', params ) );
	return {
		items: body.items || [],
		counts: body.counts || {},
		available: body.available !== false,
		total: intHeader( response, 'X-WP-Total', 0 ),
		totalPages: intHeader( response, 'X-WP-TotalPages', 1 ),
	};
}

/**
 * List calendars (for the new-booking and convert pickers).
 *
 * @return {Promise<{available: boolean, enquiry_id: number, calendars: Array}>}
 */
export function getCalendars() {
	return apiFetch( { path: 'calendars' } );
}

/**
 * Fetch the site-wide calendar overview for a month.
 *
 * @param {string} month 'YYYY-MM' (empty = current month).
 * @return {Promise<{month: string, days: number, calendars: Array, available: boolean}>}
 */
export function getCalendar( month = '' ) {
	return apiFetch( { path: addQueryArgs( 'bookings/calendar', { month } ) } );
}

/**
 * Build the CSV export download URL for the current booking filters.
 *
 * @param {Object} filters { status, s, from, to, hide_past }
 * @return {string}
 */
export function bookingsExportUrl( filters = {} ) {
	const data = window.mehData || {};
	return addQueryArgs( data.exportBase, {
		action: data.exportAction,
		_wpnonce: data.exportNonce,
		...filters,
	} );
}

/**
 * A write whose 400 names the fields that need correcting.
 *
 * The rejection is re-thrown as it came, with the per-field map lifted to
 * `errors` and the HTTP status to `status`, so a form can render both without
 * unpacking the `WP_Error` envelope itself.
 *
 * @param {string} path   Request path.
 * @param {string} method HTTP method.
 * @param {Object} data   Request body.
 * @return {Promise<Object>}
 */
async function writeFields( path, method, data ) {
	try {
		return await apiFetch( { path, method, data } );
	} catch ( error ) {
		if ( error && typeof error === 'object' ) {
			error.errors = fieldErrors( error );

			if ( ! error.status && error.data && error.data.status ) {
				error.status = error.data.status;
			}
		}

		throw error;
	}
}

/**
 * apiFetch returning both the parsed body (as { items, ... }) and the raw
 * response (for pagination headers). Used by list endpoints that return an
 * envelope object.
 *
 * @param {string} path Request path.
 * @return {Promise<{body: Object, response: Response}>}
 */
async function fetchRaw( path ) {
	const response = await apiFetch( { path, parse: false } );
	const body = await response.json();
	return { body, response };
}

/**
 * The paging a list envelope reports.
 *
 * The headers are authoritative — they carry the totals of the whole matching
 * set — and the body's own totals are the fallback for a response whose headers
 * a proxy stripped.
 *
 * @param {Object}   body     Parsed response body.
 * @param {Response} response Raw response.
 * @return {{total: number, totalPages: number, page: number, perPage: number}}
 */
function paging( body, response ) {
	return {
		total: intHeader( response, 'X-WP-Total', body.total ),
		totalPages: intHeader( response, 'X-WP-TotalPages', body.total_pages ),
		page: parseInt( body.page || 1, 10 ),
		perPage: parseInt( body.per_page || 0, 10 ),
	};
}

/**
 * An integer response header, falling back to a body value.
 *
 * @param {Response} response Raw response.
 * @param {string}   name     Header name.
 * @param {*}        fallback Value to read when the header is absent.
 * @return {number}
 */
function intHeader( response, name, fallback ) {
	const header = response.headers.get( name );

	return parseInt( ( header === null ? fallback : header ) || 0, 10 );
}
