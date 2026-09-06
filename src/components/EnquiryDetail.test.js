/**
 * EnquiryDetail component tests.
 *
 * Two rules the panel exists to honour are asserted here. The transition
 * controls are exactly what `allowed_transitions` named and nothing else, which
 * is what stops the UI offering a transition `Lifecycle` would refuse. And a
 * `closed` enquiry is offered no way to change it — no transition, because the
 * payload lists none, and no edit control, because the panel withholds it rather
 * than waiting for the route's 409.
 *
 * The edit path is asserted at the transport: the form opens on the values the
 * panel is showing, and the request that leaves carries only what the user
 * altered.
 *
 * **Validates: Requirements 9.1, 13.6, 19.12, 19.20**
 */
import { act, render, screen, fireEvent, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { respondWith } from '../testing/apiFetchMock';
import EnquiryDetail from './EnquiryDetail';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

/**
 * A `contacted` enquiry with everything the summary and the edit form read.
 */
const ENQUIRY = {
	id: 7,
	first_name: 'Ada',
	last_name: 'Lovelace',
	email: 'ada@example.com',
	phone: '0114 496 0000',
	total_guests: 12,
	selected_dates: [ '2026-05-01', '2026-05-02' ],
	event_type: [ 'wedding' ],
	site_exclusivity: [ 'whole site' ],
	message: 'Two nights, marquee on the lawn.',
	status: 'contacted',
	source: 'kadence',
	is_test: false,
	booking_id: 0,
	crm_sync_state: 'synced',
	allowed_transitions: [ 'quoted', 'lost' ],
	notes: [],
	history: [],
	siblings: [],
	created_at: '2026-01-02 09:00:00',
	updated_at: '2026-01-02 09:00:00',
	status_changed_at: '2026-01-02 09:00:00',
};

/**
 * The same enquiry once it holds `closed`: no transition is permitted out of it,
 * which is why the payload's list is empty.
 */
const CLOSED = {
	...ENQUIRY,
	id: 8,
	status: 'closed',
	allowed_transitions: [],
};

/**
 * Render the panel on an enquiry the caller already holds.
 *
 * An enquiry that can still be converted causes a calendar read on open, so the
 * render is followed by a flush: the state that read settles into belongs to the
 * opening of the panel, not to whatever a test does next.
 *
 * @param {Object} enquiry  The enquiry to show.
 * @param {Object} handlers Extra apiFetch handlers.
 * @return {Promise<Object>} Render result.
 */
async function renderPanel( enquiry, handlers = {} ) {
	apiFetch.mockImplementation(
		respondWith( {
			'GET calendars': () => ( { available: true, calendars: [] } ),
			[ `GET enquiries/${ enquiry.id }` ]: () => enquiry,
			...handlers,
		} )
	);

	const rendered = render(
		<EnquiryDetail id={ enquiry.id } enquiry={ enquiry } />
	);

	await act( async () => {} );

	return rendered;
}

/**
 * The labels of the panel's action buttons, in render order.
 *
 * @param {HTMLElement} container Render container.
 * @return {string[]} Button labels.
 */
function actionLabels( container ) {
	return [
		...container.querySelectorAll( '.meh-detail__actions button' ),
	].map( ( button ) => button.textContent );
}

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'EnquiryDetail action buttons', () => {
	it( 'offers exactly the transitions the payload named', async () => {
		const { container } = await renderPanel( ENQUIRY );

		const marks = actionLabels( container ).filter( ( label ) =>
			label.startsWith( 'Mark ' )
		);

		expect( marks ).toEqual( [ 'Mark Quoted', 'Mark Lost' ] );

		// Nothing beyond them: no button for a status `Lifecycle` did not offer.
		[ 'New', 'Contacted', 'Converted', 'Closed' ].forEach( ( status ) => {
			expect(
				screen.queryByRole( 'button', { name: `Mark ${ status }` } )
			).toBeNull();
		} );
	} );

	it( 'offers no transition at all for a closed enquiry', async () => {
		const { container } = await renderPanel( CLOSED );

		expect(
			actionLabels( container ).filter( ( label ) =>
				label.startsWith( 'Mark ' )
			)
		).toEqual( [] );

		// Re-raising is the supported way forward, so it stays.
		expect(
			screen.getByRole( 'button', { name: 'Re-raise' } )
		).toBeTruthy();
	} );

	it( 'offers no edit control for a closed enquiry', async () => {
		await renderPanel( CLOSED );

		expect(
			screen.queryByRole( 'button', { name: 'Edit details' } )
		).toBeNull();
	} );
} );

describe( 'EnquiryDetail edit control', () => {
	it( 'opens the form on the values the panel is showing', async () => {
		await renderPanel( ENQUIRY );

		fireEvent.click( screen.getByRole( 'button', { name: 'Edit details' } ) );

		expect( screen.getByLabelText( 'First name' ).value ).toBe( 'Ada' );
		expect( screen.getByLabelText( 'Last name' ).value ).toBe( 'Lovelace' );
		expect( screen.getByLabelText( 'Email' ).value ).toBe(
			'ada@example.com'
		);
		expect( screen.getByLabelText( 'Phone' ).value ).toBe( '0114 496 0000' );
		expect( screen.getByLabelText( 'Total guests' ).value ).toBe( '12' );
		expect( screen.getByLabelText( 'Event type' ).value ).toBe( 'wedding' );
		expect( screen.getByLabelText( 'Site exclusivity' ).value ).toBe(
			'whole site'
		);
		expect( screen.getByLabelText( 'Message' ).value ).toBe(
			'Two nights, marquee on the lawn.'
		);
		expect( screen.getByLabelText( 'Date 1' ).value ).toBe( '2026-05-01' );
		expect( screen.getByLabelText( 'Date 2' ).value ).toBe( '2026-05-02' );
	} );

	it( 'patches /enquiries/{id} with only the fields the user altered', async () => {
		await renderPanel( ENQUIRY, {
			'PATCH enquiries/7': ( options ) => ( {
				...ENQUIRY,
				...options.data,
				changed: options.data,
			} ),
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Edit details' } ) );

		fireEvent.change( screen.getByLabelText( 'Phone' ), {
			target: { value: '0114 496 9999' },
		} );
		fireEvent.change( screen.getByLabelText( 'Total guests' ), {
			target: { value: '14' },
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await waitFor( () => expect( patchedBody() ).not.toBeNull() );

		expect( patchedBody() ).toEqual( {
			phone: '0114 496 9999',
			total_guests: '14',
		} );
	} );
} );

/**
 * The body of the correction request, or null when none was made.
 *
 * @return {Object|null} Request body.
 */
function patchedBody() {
	const call = apiFetch.mock.calls.find(
		( [ options ] ) =>
			'enquiries/7' === options.path && 'PATCH' === options.method
	);

	return call ? call[ 0 ].data : null;
}
