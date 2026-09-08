/**
 * App component tests.
 *
 * The nav does two things a plain view switch would not, and both are asserted
 * here because neither is visible from the components underneath.
 *
 * *Overview means the list.* An open enquiry is a state inside the Overview, not
 * a view of its own, so clicking Overview while one is open has to return to the
 * list. Before `homeSignal`, it appeared to do nothing — the button was already
 * lit, so the click had no view to change.
 *
 * *The nav asks before discarding a note.* Every nav click either returns to the
 * list or unmounts the panel, and both throw a half-written note away. The panel
 * is the only thing that knows there is one, so it reports upward and the
 * question is put here, where the navigation actually happens — which means the
 * question has to be put *before* anything moves, so that cancelling leaves both
 * the view and the open panel exactly as they were.
 */
import { act, render, screen, fireEvent, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { respondWith } from './testing/apiFetchMock';
import App from './App';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

/**
 * One enquiry, listed.
 */
const LIST = {
	items: [
		{
			id: 1,
			first_name: 'Ada',
			last_name: 'Lovelace',
			email: 'ada@example.com',
			phone: '',
			selected_dates: [ '2026-05-01' ],
			status: 'new',
			is_test: false,
			created_at: '2026-01-02 09:00:00',
		},
	],
	counts: { all: 1, new: 1 },
	warnings: [],
	page: 1,
	per_page: 25,
	total: 1,
	total_pages: 1,
};

/**
 * The same enquiry as the single-enquiry route returns it.
 */
const DETAIL = {
	...LIST.items[ 0 ],
	total_guests: 12,
	event_type: [],
	site_exclusivity: [],
	message: '',
	source: 'kadence',
	booking_id: 0,
	crm_sync_state: 'synced',
	allowed_transitions: [ 'contacted' ],
	notes: [],
	history: [],
	siblings: [],
	updated_at: '2026-01-02 09:00:00',
	status_changed_at: '2026-01-02 09:00:00',
};

/**
 * What the bookings list and the calendar answer. Neither is under test; they are
 * here because the Overview mounts the bookings manager beside the enquiry list
 * and the Calendar view reads a month on open.
 */
const HANDLERS = {
	'GET enquiries': () => LIST,
	'GET enquiries/1': () => DETAIL,
	'GET bookings': () => ( {
		items: [],
		counts: {},
		page: 1,
		per_page: 25,
		total: 0,
		total_pages: 0,
	} ),
	'GET calendars': () => ( { available: true, calendars: [] } ),
	'GET bookings/calendar': () => ( {
		month: '2026-01',
		days: 31,
		calendars: [],
		available: true,
	} ),
};

let originalConfirm;

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation( respondWith( HANDLERS ) );

	originalConfirm = window.confirm;

	window.mehData = { wpbsUrl: '', settingsUrl: '', isAdmin: false };
} );

afterEach( () => {
	window.confirm = originalConfirm;
} );

/**
 * Render the app and open the listed enquiry.
 *
 * @return {Promise<void>} Resolves with the panel open.
 */
async function openEnquiry() {
	render( <App /> );

	await waitFor( () =>
		expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy()
	);

	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name: 'Ada Lovelace' } ) );
	} );

	expect( screen.getByRole( 'button', { name: 'Back to list' } ) ).toBeTruthy();
}

/**
 * Click a nav item.
 *
 * @param {string} label Nav label.
 * @return {Promise<void>} Resolves once the click has settled.
 */
async function navigate( label ) {
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name: label } ) );
	} );
}

describe( 'App navigation', () => {
	it( 'returns to the list when Overview is clicked with an enquiry open', async () => {
		window.confirm = jest.fn( () => true );

		await openEnquiry();
		await navigate( 'Overview' );

		// Overview was already the selected view, so nothing about the view
		// changed — and the click still has to be honoured.
		expect(
			screen.queryByRole( 'button', { name: 'Back to list' } )
		).toBeNull();
		expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy();
		expect( window.confirm ).not.toHaveBeenCalled();
	} );

	it( 'returns to the list a second time, so a repeat click is a repeat request', async () => {
		await openEnquiry();
		await navigate( 'Overview' );

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Ada Lovelace' } )
			);
		} );

		expect(
			screen.getByRole( 'button', { name: 'Back to list' } )
		).toBeTruthy();

		await navigate( 'Overview' );

		// A boolean flag would have stayed set and left this click with nothing to
		// say; the signal is a rising number for exactly this case.
		expect(
			screen.queryByRole( 'button', { name: 'Back to list' } )
		).toBeNull();
	} );
} );

describe( 'App unsaved-note guard', () => {
	it( 'asks before Overview discards a note, and stays put when refused', async () => {
		window.confirm = jest.fn( () => false );

		await openEnquiry();

		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Add a note' ), {
				target: { value: 'Rang, no answer.' },
			} );
		} );

		await navigate( 'Overview' );

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );

		// Cancelling leaves the panel open and the note in it: the click cost
		// nothing.
		expect(
			screen.getByRole( 'button', { name: 'Back to list' } )
		).toBeTruthy();
		expect( screen.getByLabelText( 'Add a note' ).value ).toBe(
			'Rang, no answer.'
		);
	} );

	it( 'asks before the Calendar view unmounts a note, and stays put when refused', async () => {
		window.confirm = jest.fn( () => false );

		await openEnquiry();

		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Add a note' ), {
				target: { value: 'Rang, no answer.' },
			} );
		} );

		await navigate( 'Calendar View' );

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.getByRole( 'button', { name: 'Back to list' } )
		).toBeTruthy();
	} );

	it( 'switches away when the warning is accepted', async () => {
		window.confirm = jest.fn( () => true );

		await openEnquiry();

		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Add a note' ), {
				target: { value: 'Rang, no answer.' },
			} );
		} );

		await navigate( 'Calendar View' );

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
		expect( screen.queryByLabelText( 'Add a note' ) ).toBeNull();

		// Switching to the Calendar unmounted the panel, so it never got to report
		// the note gone. The nav clears the flag itself for that reason, and the
		// next click must not ask about a note that no longer exists.
		await navigate( 'Overview' );

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
	} );
} );
