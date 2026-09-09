/**
 * EnquiryManager component tests.
 *
 * The list screen carries three things the API cannot be asked about: the tab
 * set and its counts, the marker a staging record wears, and the state the
 * hide-test toggle opens in. It also owns the entry point to manual creation,
 * so the request that entry point produces is asserted here at the transport —
 * the path, the method, and the exact set of keys in the body.
 *
 * It also owns both ways out of an open enquiry — its own "Back to list" and the
 * side nav's request for the list, which arrives as `selection` — and so it owns
 * the warning that stands between an unfinished note and either of them. That is
 * asserted here rather than in the panel, because the panel is not where the
 * decision is made: it only reports that there is something to lose.
 *
 * **Validates: Requirements 12.9, 17.4, 17.6, 18.23**
 */
import { act, render, screen, fireEvent, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { respondWith } from '../testing/apiFetchMock';
import EnquiryManager from './EnquiryManager';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

/**
 * The seven tabs, in order, with the counts the payload below implies.
 *
 * `contacted`, `converted`, `lost` and `closed` are absent from the payload's
 * `counts`, so each has to read as zero rather than as nothing.
 */
const EXPECTED_TABS = [
	[ 'All', '2' ],
	[ 'New', '1' ],
	[ 'Contacted', '0' ],
	[ 'Quoted', '1' ],
	[ 'Converted', '0' ],
	[ 'Lost', '0' ],
	[ 'Closed', '0' ],
];

/**
 * One live enquiry and one staging enquiry.
 */
const LIST = {
	items: [
		{
			id: 1,
			first_name: 'Ada',
			last_name: 'Lovelace',
			email: 'ada@example.com',
			phone: '',
			date_ranges: [ { start: '2026-05-01', end: '2026-05-03' } ],
			status: 'new',
			is_test: false,
			created_at: '2026-01-02 09:00:00',
		},
		{
			id: 2,
			first_name: 'Staging',
			last_name: 'Rig',
			email: 'rig@example.com',
			phone: '',
			date_ranges: [],
			status: 'quoted',
			is_test: true,
			created_at: '2026-01-03 09:00:00',
		},
	],
	counts: { all: 2, new: 1, quoted: 1 },
	warnings: [],
	page: 1,
	per_page: 25,
	total: 2,
	total_pages: 1,
};

/**
 * Render the list and wait for its first read to land.
 *
 * @param {Object} handlers Extra apiFetch handlers.
 * @param {Object} props    Props for the component under test.
 * @return {Promise<Object>} Render result.
 */
async function renderList( handlers = {}, props = {} ) {
	apiFetch.mockImplementation(
		respondWith( {
			'GET enquiries': () => LIST,
			...handlers,
		} )
	);

	const rendered = render( <EnquiryManager { ...props } /> );

	await waitFor( () =>
		expect( screen.getByText( 'Ada Lovelace' ) ).toBeTruthy()
	);

	return rendered;
}

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'EnquiryManager', () => {
	it( 'renders the six lifecycle statuses plus all, each with its count', async () => {
		const { container } = await renderList();

		const tabs = [ ...container.querySelectorAll( '.meh-period-tab' ) ].map(
			( tab ) => [
				tab.childNodes[ 0 ].textContent,
				tab.querySelector( '.count' ).textContent,
			]
		);

		expect( tabs ).toEqual( EXPECTED_TABS );
	} );

	it( 'badges the staging row and only the staging row', async () => {
		const { container } = await renderList();

		const badges = [
			...container.querySelectorAll( '.meh-badge--test' ),
		];

		expect( badges ).toHaveLength( 1 );
		expect( badges[ 0 ].textContent ).toBe( 'Test' );
		expect( badges[ 0 ].closest( 'tr' ).textContent ).toContain(
			'Staging Rig'
		);
	} );

	it( 'shows a candidate range as a range and an empty set as a dash', async () => {
		await renderList();

		expect( screen.getByText( '2026-05-01 – 2026-05-03' ) ).toBeTruthy();

		// The second row holds no ranges at all.
		const cells = [ ...document.querySelectorAll( 'tbody tr' ) ].map(
			( row ) => row.cells[ 2 ].textContent
		);

		expect( cells[ 1 ] ).toBe( '—' );
	} );

	it( 'opens with the hide-test toggle off, so staging rows are listed', async () => {
		await renderList();

		expect( screen.getByLabelText( 'Hide test enquiries' ).checked ).toBe(
			false
		);
	} );

	it( 'opens an empty create form from the new-enquiry control', async () => {
		await renderList();

		fireEvent.click( screen.getByRole( 'button', { name: 'New enquiry' } ) );

		expect(
			screen.getByRole( 'heading', { name: 'New enquiry' } )
		).toBeTruthy();
		expect(
			screen.getByRole( 'button', { name: 'Create enquiry' } )
		).toBeTruthy();

		[
			'First name',
			'Last name',
			'Email',
			'Phone',
			'Total guests',
			'Event type',
			'Site exclusivity',
			'Message',
		].forEach( ( label ) => {
			expect( screen.getByLabelText( label ).value ).toBe( '' );
		} );

		// The three ranked range slots, all six bounds empty.
		[ 'Start date', 'End date' ].forEach( ( label ) => {
			expect(
				screen.getAllByLabelText( label ).map( ( f ) => f.value )
			).toEqual( [ '', '', '' ] );
		} );
	} );

	it( 'posts the entered values to POST /enquiries, with no key for a blank optional field', async () => {
		await renderList( {
			'POST enquiries': ( options ) => ( { id: 3, ...options.data } ),
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'New enquiry' } ) );

		fireEvent.change( screen.getByLabelText( 'First name' ), {
			target: { value: 'Grace' },
		} );
		fireEvent.change( screen.getByLabelText( 'Last name' ), {
			target: { value: 'Hopper' },
		} );
		fireEvent.change( screen.getByLabelText( 'Email' ), {
			target: { value: 'grace@example.com' },
		} );
		fireEvent.change( screen.getAllByLabelText( 'Start date' )[ 0 ], {
			target: { value: '2026-06-01' },
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create enquiry' } )
		);

		await waitFor( () => expect( postedBody() ).not.toBeNull() );

		// The four fields that were filled in, and nothing standing in for the
		// five that were left blank.
		expect( postedBody() ).toEqual( {
			first_name: 'Grace',
			last_name: 'Hopper',
			email: 'grace@example.com',
			date_ranges: [ { start: '2026-06-01', end: '2026-06-01' } ],
		} );
	} );
} );

describe( 'EnquiryManager unsaved-note guard', () => {
	let original;

	beforeEach( () => {
		original = window.confirm;
	} );

	afterEach( () => {
		window.confirm = original;
	} );

	it( 'leaves the panel and the note alone when the warning is refused', async () => {
		window.confirm = jest.fn( () => false );

		await openEnquiry();
		await writeNote( 'Rang, no answer.' );

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Back to list' } )
			);
		} );

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );

		// Cancelling has to lose nothing: the panel is still open and the note is
		// still in the box, so a mis-click costs the user nothing at all.
		expect(
			screen.getByRole( 'button', { name: 'Back to list' } )
		).toBeTruthy();
		expect( screen.getByLabelText( 'Add a note' ).value ).toBe(
			'Rang, no answer.'
		);
	} );

	it( 'returns to the list when the warning is accepted', async () => {
		window.confirm = jest.fn( () => true );

		await openEnquiry();
		await writeNote( 'Rang, no answer.' );

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Back to list' } )
			);
		} );

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.queryByRole( 'button', { name: 'Back to list' } )
		).toBeNull();
		expect( screen.getByText( 'Staging Rig' ) ).toBeTruthy();
	} );

	it( 'does not ask when there is no note to lose', async () => {
		window.confirm = jest.fn( () => true );

		await openEnquiry();

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Back to list' } )
			);
		} );

		expect( window.confirm ).not.toHaveBeenCalled();
		expect( screen.getByText( 'Staging Rig' ) ).toBeTruthy();
	} );

	it( 'does not ask again when the nav has already asked', async () => {
		window.confirm = jest.fn( () => true );

		const { rerender } = await openEnquiry();
		await writeNote( 'Rang, no answer.' );

		// The side nav puts the question before it sends the request, so honouring
		// the request must not put it a second time. `seq` is a rising number
		// rather than a boolean, so a second Overview click is a second request.
		await act( async () => {
			rerender( <EnquiryManager selection={ { id: 0, seq: 1 } } /> );
		} );

		expect( window.confirm ).not.toHaveBeenCalled();
		expect(
			screen.queryByRole( 'button', { name: 'Back to list' } )
		).toBeNull();
	} );
} );

describe( 'EnquiryManager selection contract', () => {
	it( 'opens the enquiry it was handed, which is how a pasted URL arrives', async () => {
		apiFetch.mockImplementation(
			respondWith( {
				'GET enquiries': () => LIST,
				'GET enquiries/1': () => DETAIL,
				'GET calendars': () => ( { available: true, calendars: [] } ),
			} )
		);

		render( <EnquiryManager selection={ { id: 1, seq: 0 } } /> );

		// Nothing was clicked: the id came in with the first render, and the panel
		// is what the first paint shows.
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Back to list' } )
			).toBeTruthy()
		);
	} );

	it( 'reports opening and closing, so the URL can say which enquiry is open', async () => {
		const onSelect = jest.fn();

		await openEnquiry( {}, { onSelect } );

		expect( onSelect ).toHaveBeenLastCalledWith( 1 );

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Back to list' } )
			);
		} );

		expect( onSelect ).toHaveBeenLastCalledWith( 0 );
	} );

	it( 'honours a request for an enquiry without reporting it back', async () => {
		const onSelect = jest.fn();
		const { rerender } = await renderList(
			{
				'GET enquiries/1': () => DETAIL,
				'GET calendars': () => ( { available: true, calendars: [] } ),
			},
			{ onSelect, selection: { id: 0, seq: 0 } }
		);

		await act( async () => {
			rerender(
				<EnquiryManager
					onSelect={ onSelect }
					selection={ { id: 1, seq: 1 } }
				/>
			);
		} );

		expect(
			screen.getByRole( 'button', { name: 'Back to list' } )
		).toBeTruthy();

		// The nav asked for this one — telling it would be repeating what it
		// already knows, and on a Back it would push the step being undone.
		expect( onSelect ).not.toHaveBeenCalled();
	} );

	it( 'closes the panel when the create form is opened, so the URL stops naming it', async () => {
		const onSelect = jest.fn();

		await openEnquiry( {}, { onSelect } );

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'New enquiry' } )
			);
		} );

		expect( onSelect ).toHaveBeenLastCalledWith( 0 );
	} );
} );

/**
 * The single-enquiry representation for the first listed row.
 *
 * The panel reads it on open, and it is the list row plus everything only
 * `present_single()` adds.
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
 * Render the list and open the first row's panel.
 *
 * @param {Object} handlers Extra apiFetch handlers.
 * @return {Promise<Object>} Render result.
 */
async function openEnquiry( handlers = {}, props = {} ) {
	const rendered = await renderList(
		{
			'GET enquiries/1': () => DETAIL,
			// The first row has a candidate date and no booking, so the convert
			// control is offered and reads the calendars.
			'GET calendars': () => ( { available: true, calendars: [] } ),
			...handlers,
		},
		props
	);

	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name: 'Ada Lovelace' } ) );
	} );

	return rendered;
}

/**
 * Type a note into the open panel without adding it.
 *
 * @param {string} body Note text.
 * @return {Promise<void>} Resolves once the panel has reported itself dirty.
 */
async function writeNote( body ) {
	await act( async () => {
		fireEvent.change( screen.getByLabelText( 'Add a note' ), {
			target: { value: body },
		} );
	} );
}

/**
 * The body of the create request, or null when none was made.
 *
 * @return {Object|null} Request body.
 */
function postedBody() {
	const call = apiFetch.mock.calls.find(
		( [ options ] ) =>
			'enquiries' === options.path && 'POST' === options.method
	);

	return call ? call[ 0 ].data : null;
}
