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
 *
 * *The URL is where the app is.* The view and the open enquiry are both in it, so
 * a view can be linked to, a reload comes back to it, and an enquiry can be handed
 * over as a URL. Back and Forward are navigation like any other click, except that
 * they have already happened by the time the app hears — so the note warning is
 * put afterwards there, and refusing it puts the URL back.
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
			date_ranges: [ { start: '2026-05-01', end: '2026-05-01' } ],
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

	window.mehData = {
		wpbsUrl: '',
		settingsUrl: '',
		isAdmin: false,
		// Where the front-end hub lives, which is what tells the routing module
		// that the path is ours to write views into.
		hubUrl: 'http://localhost/bookings/',
	};

	// jsdom keeps one location for the whole file, so every test starts from the
	// hub root rather than from wherever the last one navigated to.
	window.history.replaceState( {}, '', '/bookings/' );
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

describe( 'App routing', () => {
	/**
	 * Where the address bar is.
	 *
	 * @return {string} Path and query string.
	 */
	const url = () => window.location.pathname + window.location.search;

	/**
	 * A Back or Forward step, as the app sees one.
	 *
	 * The browser moves the location and then says so; `history.back()` does the
	 * same thing but asynchronously, and what is under test is the reaction rather
	 * than jsdom's session history. So the location is put where the step lands and
	 * the event the app listens for is fired.
	 *
	 * @param {string} to Path and query string the step lands on.
	 * @return {Promise<void>} Resolves once the app has reacted.
	 */
	async function step( to ) {
		await act( async () => {
			window.history.replaceState( {}, '', to );
			window.dispatchEvent( new PopStateEvent( 'popstate' ) );
		} );
	}

	it( 'gives the Calendar a URL of its own', async () => {
		render( <App /> );

		await waitFor( () =>
			expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy()
		);

		expect( url() ).toBe( '/bookings/' );

		await navigate( 'Calendar View' );

		expect( url() ).toBe( '/bookings/calendar/' );

		await navigate( 'Overview' );

		expect( url() ).toBe( '/bookings/' );
	} );

	it( 'opens at the view its URL asked for', async () => {
		window.history.replaceState( {}, '', '/bookings/calendar/' );

		render( <App /> );

		// The calendar's own month control, so this is the calendar and not a list
		// that happens to have rendered.
		await waitFor( () =>
			expect( screen.getByLabelText( 'Month' ) ).toBeTruthy()
		);

		expect( screen.queryByText( 'Ada Lovelace' ) ).toBeNull();
	} );

	it( 'names the open enquiry in the URL, and stops naming it on the way back', async () => {
		await openEnquiry();

		expect( url() ).toBe( '/bookings/?enquiry=1' );

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Back to list' } )
			);
		} );

		expect( url() ).toBe( '/bookings/' );
	} );

	it( 'opens the enquiry a pasted URL names', async () => {
		window.history.replaceState( {}, '', '/bookings/?enquiry=1' );

		render( <App /> );

		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Back to list' } )
			).toBeTruthy()
		);
	} );

	it( 'follows Back out of an enquiry and Forward into it again', async () => {
		await openEnquiry();

		await step( '/bookings/' );

		expect(
			screen.queryByRole( 'button', { name: 'Back to list' } )
		).toBeNull();

		await step( '/bookings/?enquiry=1' );

		expect(
			screen.getByRole( 'button', { name: 'Back to list' } )
		).toBeTruthy();
	} );

	it( 'follows Back out of the Calendar', async () => {
		render( <App /> );

		await waitFor( () =>
			expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy()
		);

		await navigate( 'Calendar View' );
		await step( '/bookings/' );

		expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy();
	} );

	it( 'puts the URL back when Back is refused over an unsaved note', async () => {
		window.confirm = jest.fn( () => false );

		await openEnquiry();

		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Add a note' ), {
				target: { value: 'Rang, no answer.' },
			} );
		} );

		await step( '/bookings/' );

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );

		// The step had already been taken, so refusing it means undoing it: the
		// panel stays, the note stays, and the address bar says so again.
		expect(
			screen.getByRole( 'button', { name: 'Back to list' } )
		).toBeTruthy();
		expect( screen.getByLabelText( 'Add a note' ).value ).toBe(
			'Rang, no answer.'
		);
		expect( url() ).toBe( '/bookings/?enquiry=1' );
	} );

	it( 'writes the view as a query arg where the path is not ours', async () => {
		// wp-admin: the screen is `admin.php?page=…`, and that arg has to survive
		// every navigation the app makes.
		window.mehData = { ...window.mehData, hubUrl: '' };
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=marthrown-enquiry-hub'
		);

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy()
		);

		await navigate( 'Calendar View' );

		expect( url() ).toBe(
			'/wp-admin/admin.php?page=marthrown-enquiry-hub&view=calendar'
		);
	} );
} );

describe( 'App nav drawer', () => {
	/**
	 * Whether the nav is open, as the stylesheet reads it.
	 *
	 * The drawer is a CSS state on the layout — there is no width measured in
	 * JavaScript — so the class is what there is to assert.
	 *
	 * @return {boolean}
	 */
	const isOpen = () =>
		!! document.querySelector( '.meh-layout.is-nav-open' );

	it( 'opens on the burger and closes when a destination is chosen', async () => {
		render( <App /> );

		await waitFor( () =>
			expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy()
		);

		expect( isOpen() ).toBe( false );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Menu' } ) );
		} );

		expect( isOpen() ).toBe( true );
		expect( screen.getByRole( 'button', { name: 'Menu' } ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );

		await navigate( 'Calendar View' );

		// The drawer covers the view it just navigated to, so choosing is also
		// dismissing.
		expect( isOpen() ).toBe( false );
	} );

	it( 'stays open when the note warning is refused', async () => {
		window.confirm = jest.fn( () => false );

		await openEnquiry();

		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Add a note' ), {
				target: { value: 'Rang, no answer.' },
			} );
		} );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Menu' } ) );
		} );

		await navigate( 'Calendar View' );

		// Nothing moved, so the nav is still on screen to choose again from.
		expect( isOpen() ).toBe( true );
		expect(
			screen.getByRole( 'button', { name: 'Back to list' } )
		).toBeTruthy();
	} );

	it( 'closes on the backdrop and on Escape', async () => {
		render( <App /> );

		await waitFor( () =>
			expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy()
		);

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Menu' } ) );
		} );

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Close menu' } )
			);
		} );

		expect( isOpen() ).toBe( false );

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Menu' } ) );
		} );

		await act( async () => {
			fireEvent.keyDown( document, { key: 'Escape' } );
		} );

		expect( isOpen() ).toBe( false );
	} );

	it( 'carries the signed-in line and its links for the narrow layout', async () => {
		window.mehData = {
			...window.mehData,
			userName: 'Ada Lovelace',
			dashboardUrl: 'https://example.test/wp-admin/',
			logoutUrl: 'https://example.test/logout',
		};

		render( <App /> );

		await waitFor( () =>
			expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy()
		);

		const account = document.querySelector( '.meh-sidenav__account' );

		expect( account.textContent ).toContain( 'Signed in as Ada Lovelace' );
		expect(
			[ ...account.querySelectorAll( 'a' ) ].map( ( a ) => a.href )
		).toEqual( [
			'https://example.test/wp-admin/',
			'https://example.test/logout',
		] );
	} );

	it( 'says nothing about an account it was told nothing about', async () => {
		render( <App /> );

		await waitFor( () =>
			expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy()
		);

		// The admin screen localises no user block, and an empty bordered strip at
		// the foot of the drawer would be all this rendered there.
		expect( document.querySelector( '.meh-sidenav__account' ) ).toBeNull();
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
