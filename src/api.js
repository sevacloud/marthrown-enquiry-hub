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
 * Fetch bookings for a bucket.
 *
 * @param {string} bucket new|upcoming|current|past.
 * @param {Object} params { page, per_page }
 * @return {Promise<{items: Array, totalPages: number, total: number}>}
 */
export async function getBookings( bucket, params = {} ) {
	return fetchWithTotals( addQueryArgs( 'bookings', { bucket, ...params } ) );
}

/**
 * Acknowledge a booking (moves it out of "New").
 *
 * @param {number} id Booking ID.
 * @return {Promise<Object>}
 */
export function acknowledgeBooking( id ) {
	return apiFetch( {
		path: `bookings/${ id }/acknowledge`,
		method: 'POST',
	} );
}

/**
 * Run an apiFetch that also reads pagination headers.
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
