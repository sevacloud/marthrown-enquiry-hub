/**
 * BookingsManager component test.
 *
 * Converting an enquiry into a booking is initiated from the enquiry, not from
 * the bookings list, so the list carries no convert control. Asserted as an
 * absence over the whole rendered screen rather than over one region, since a
 * convert control reappearing anywhere on it would be the regression.
 *
 * **Validates: Requirement 14.12**
 */
import { render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { respondWith } from '../testing/apiFetchMock';
import BookingsManager from './BookingsManager';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const BOOKINGS = {
	items: [
		{
			id: 41,
			calendar: 'Bunkhouse',
			guest: 'Ada Lovelace',
			start_date: '2026-05-01',
			end_date: '2026-05-03',
			stay_length: '2 nights',
			status: 'accepted',
			view_url: 'https://example.test/wp-admin/booking=41',
		},
	],
	counts: { all: 1, accepted: 1 },
	available: true,
	total: 1,
	total_pages: 1,
};

beforeEach( () => {
	apiFetch.mockReset();

	// The export link is built from the values the admin page localises.
	window.mehData = {
		exportBase: 'https://example.test/wp-admin/admin-post.php',
		exportAction: 'meh_export_bookings',
		exportNonce: 'nonce',
	};
} );

describe( 'BookingsManager', () => {
	it( 'renders no convert control', async () => {
		apiFetch.mockImplementation(
			respondWith( {
				'GET bookings': () => BOOKINGS,
				'GET calendars': () => ( { available: true, calendars: [] } ),
			} )
		);

		const { container } = render( <BookingsManager /> );

		await waitFor( () =>
			expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy()
		);

		const controls = [
			...container.querySelectorAll( 'button, a, select, input' ),
		].map( ( control ) => control.textContent + ' ' + control.className );

		expect(
			controls.filter( ( label ) => /convert/i.test( label ) )
		).toEqual( [] );

		expect( screen.queryByText( /convert/i ) ).toBeNull();
	} );
} );
