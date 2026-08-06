/**
 * REST API client for the Enquiry Hub.
 *
 * Thin wrappers around @wordpress/api-fetch. The root URL and nonce middleware
 * are configured once in index.js.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Fetch enquiries with optional filters, returning items + total pages.
 *
 * @param {Object} params { source, status, from, to, page, per_page }
 * @return {Promise<{items: Array, totalPages: number, total: number}>}
 */
export async function getEnquiries( params = {} ) {
	return fetchWithTotals( addQueryArgs( 'enquiries', params ) );
}

/**
 * Update an enquiry's status.
 *
 * @param {number} id     Subscriber ID.
 * @param {string} status new|replied|resolved.
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
		total: parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 ),
		totalPages: parseInt(
			response.headers.get( 'X-WP-TotalPages' ) || '1',
			10
		),
	};
}

/**
 * Convert an enquiry booking into a real booking on a target calendar.
 *
 * @param {number} id               Source booking id.
 * @param {number} targetCalendarId Target calendar id.
 * @param {string} status           pending|accepted.
 * @return {Promise<{id: number, edit_url: string}>}
 */
export function convertBooking( id, targetCalendarId, status = 'accepted' ) {
	return apiFetch( {
		path: `bookings/${ id }/convert`,
		method: 'POST',
		data: { target_calendar_id: targetCalendarId, status },
	} );
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
 * Run an apiFetch that also reads pagination headers (for endpoints returning
 * a bare array of items).
 *
 * @param {string} path Request path.
 * @return {Promise<{items: Array, totalPages: number, total: number}>}
 */
async function fetchWithTotals( path ) {
	const response = await apiFetch( { path, parse: false } );
	const items = await response.json();
	return {
		items,
		total: parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 ),
		totalPages: parseInt(
			response.headers.get( 'X-WP-TotalPages' ) || '1',
			10
		),
	};
}
