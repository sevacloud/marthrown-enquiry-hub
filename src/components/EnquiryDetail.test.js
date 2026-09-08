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
 * The transition path is asserted at the transport too, and for a sharper
 * reason: a status change is irreversible, so a Mark button that applied one on
 * the click would be a control with no way back. The tests below pin the
 * sequence — the click asks, the dialog applies — and pin what reaches the route
 * either way, because the comment being optional means the body has to differ
 * between a transition with one and a transition without.
 *
 * **Validates: Requirements 9.1, 13.6, 19.12, 19.20**
 */
import {
	act,
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { respondWith } from '../testing/apiFetchMock';
import EnquiryDetail from './EnquiryDetail';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

// EnquiryForm's two taxonomy dropdowns read their options from
// window.mehData, exactly as AdminPage::enqueue_app() populates it in
// production. Values here match what ENQUIRY stores, so the edit form below
// can pre-select them the way a real vocabulary containing "wedding" would.
window.mehData = {
	eventTypes: [ 'wedding', 'party' ],
	siteExclusivity: [ 'whole site', 'shared' ],
};

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
	date_ranges: [
		{ start: '2026-05-01', end: '2026-05-02' },
		{ start: '2026-06-14', end: '2026-06-14' },
	],
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
 * The same enquiry as a list row.
 *
 * `present_single()` is the only thing that adds the notes, the history, the
 * siblings and the permitted transitions, so a row from the list route carries
 * none of them — which is what the panel is handed when a row is clicked.
 */
const LIST_ROW = { ...ENQUIRY };
delete LIST_ROW.notes;
delete LIST_ROW.history;
delete LIST_ROW.siblings;
delete LIST_ROW.allowed_transitions;

/**
 * What the single-enquiry route answers for that row.
 */
const HYDRATED = {
	...ENQUIRY,
	notes: [
		{
			id: 1,
			body: 'Rang about the marquee.',
			author: 'Lee',
			created_at: '2026-01-03 10:00:00',
		},
	],
	history: [
		{
			id: 1,
			entry_type: 'created',
			description: 'Enquiry received.',
			actor: '',
			created_at: '2026-01-02 09:00:00',
		},
	],
};

/**
 * The same enquiry once it holds `closed`: no transition is permitted out of it,
 * which is why the payload's list is empty.
 *
 * `closed_from` is the status it held when it closed, which the API derives from
 * the history trail rather than storing.
 */
const CLOSED = {
	...ENQUIRY,
	id: 8,
	status: 'closed',
	closed_from: 'converted',
	allowed_transitions: [],
};

/**
 * The calendars WP Booking System holds, as the picker reads them.
 *
 * Named the way the real ones are: the exclusivity a calendar is for, then what
 * it is for, which is why "Top Site" has to find "Top Site (Festival)" rather
 * than needing the two names to be identical.
 */
const CALENDARS = [
	{ id: 3, name: 'Full Site' },
	{ id: 5, name: 'Top Site (Festival)' },
	{ id: 9, name: 'Meadow' },
];

/**
 * The same enquiry, with a Site Exclusivity that names one of those calendars.
 */
const TOP_SITE = { ...ENQUIRY, site_exclusivity: [ 'Top Site' ] };

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
 * Render the panel with calendars to convert onto, and a convert route to reach.
 *
 * The conversion answers with the enquiry it was made for, which is what the
 * panel reads back afterwards; the tests below assert what was posted, so the
 * answer only has to be an enquiry.
 *
 * @param {Object} enquiry The enquiry to show.
 * @return {Promise<Object>} Render result.
 */
async function renderConvertible( enquiry ) {
	return renderPanel( enquiry, {
		'GET calendars': () => ( { available: true, calendars: CALENDARS } ),
		[ `POST enquiries/${ enquiry.id }/convert` ]: () => ( {
			...enquiry,
			booking_id: 41,
			booking: { booking_id: 41, edit_url: '' },
		} ),
	} );
}

/**
 * The booking range as the two date fields hold it.
 *
 * @return {string[]} [ start, end ].
 */
function bookingBounds() {
	return [
		screen.getByLabelText( 'Start date' ).value,
		screen.getByLabelText( 'End date' ).value,
	];
}

/**
 * Press Create booking and return the body that reached the convert route.
 *
 * @return {Promise<Object>} Posted request body.
 */
async function createBooking() {
	await act( async () => {
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create booking' } )
		);
	} );

	const posted = apiFetch.mock.calls
		.map( ( [ options ] ) => options )
		.filter( ( options ) => /\/convert$/.test( String( options.path ) ) );

	expect( posted ).toHaveLength( 1 );

	return posted[ 0 ].data;
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

describe( 'EnquiryDetail on open', () => {
	// The panel used to skip its read whenever the caller handed a row over, and
	// render from the row alone. A list row has no notes and no history, so the
	// panel reported "No notes yet" and "No history recorded" for an enquiry that
	// had both, until some write happened to read the enquiry back. Reading on
	// open is what the offered transitions, the siblings and the CRM link depend
	// on too, all of which the row is equally missing.
	it( 'reads the enquiry so the notes and history show without a write', async () => {
		apiFetch.mockImplementation(
			respondWith( {
				'GET calendars': () => ( { available: true, calendars: [] } ),
				'GET enquiries/7': () => HYDRATED,
			} )
		);

		render( <EnquiryDetail id={ LIST_ROW.id } enquiry={ LIST_ROW } /> );

		expect(
			await screen.findByText( 'Rang about the marquee.' )
		).toBeTruthy();

		expect( screen.getByText( 'Enquiry received.' ) ).toBeTruthy();
		expect( screen.queryByText( 'No notes yet.' ) ).toBeNull();
		expect( screen.queryByText( 'No history recorded.' ) ).toBeNull();

		// Read on open, not as a side effect of a write.
		expect(
			apiFetch.mock.calls.every(
				( [ options ] ) =>
					! options.method || 'GET' === options.method
			)
		).toBe( true );
	} );

	it( 'offers the transitions the read returned, not the ones the row lacked', async () => {
		apiFetch.mockImplementation(
			respondWith( {
				'GET calendars': () => ( { available: true, calendars: [] } ),
				'GET enquiries/7': () => HYDRATED,
			} )
		);

		const { container } = render(
			<EnquiryDetail id={ LIST_ROW.id } enquiry={ LIST_ROW } />
		);

		await waitFor( () =>
			expect(
				actionLabels( container ).filter( ( label ) =>
					label.startsWith( 'Mark ' )
				)
			).toEqual( [ 'Mark Quoted', 'Mark Lost' ] )
		);
	} );
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

describe( 'EnquiryDetail candidate ranges', () => {
	it( 'lists the ranges in stored order, ranked, a single day written once', async () => {
		const { container } = await renderPanel( ENQUIRY );

		const listed = [
			...container.querySelectorAll( '.meh-detail__ranges li' ),
		].map( ( node ) => node.textContent.replace( /\s+/g, ' ' ).trim() );

		expect( listed ).toEqual( [
			'Ideal 2026-05-01 – 2026-05-02',
			// A range whose bounds match is the one day, not the date twice.
			'Alternative 1 2026-06-14',
		] );
	} );

} );

describe( 'EnquiryDetail convert control', () => {
	it( 'offers the candidate ranges and a custom one, opening on the ideal range', async () => {
		await renderConvertible( TOP_SITE );

		const options = [
			...screen
				.getByLabelText( 'Booking dates' )
				.querySelectorAll( 'option' ),
		].map( ( option ) => `${ option.value }|${ option.textContent }` );

		// Ranges, not days: the booking is the range, so the choice is which
		// range rather than which day of one.
		expect( options ).toEqual( [
			'0|Ideal: 2026-05-01 – 2026-05-02',
			'1|Alternative 1: 2026-06-14',
			'custom|Custom range…',
		] );

		// The ideal range's bounds are already in the fields the booking is made
		// from, so converting on the dates the enquirer would rather have needs
		// no interaction at all.
		expect( bookingBounds() ).toEqual( [ '2026-05-01', '2026-05-02' ] );
	} );

	it( 'maps a chosen range onto the booking start and end dates', async () => {
		await renderConvertible( TOP_SITE );

		fireEvent.change( screen.getByLabelText( 'Booking dates' ), {
			target: { value: '1' },
		} );

		// A single-day range is both bounds, not a start with the end left over
		// from the range before it.
		expect( bookingBounds() ).toEqual( [ '2026-06-14', '2026-06-14' ] );
	} );

	it( 'offers only the calendar the site exclusivity names, already chosen', async () => {
		await renderConvertible( TOP_SITE );

		const calendar = screen.getByLabelText( 'Calendar' );

		expect(
			[ ...calendar.querySelectorAll( 'option' ) ].map(
				( option ) => option.textContent
			)
		).toEqual( [ 'Top Site (Festival)' ] );

		// Named by the enquiry, so there is nothing to choose and nothing to get
		// wrong: the control says which calendar and refuses to be changed.
		expect( calendar.value ).toBe( '5' );
		expect( calendar.disabled ).toBe( true );

		const posted = await createBooking();

		expect( posted.calendar_id ).toBe( 5 );
	} );

	it( 'offers every calendar when the exclusivity matches none, and says so', async () => {
		// "whole site" is what this enquiry stores, and no calendar is named
		// anything like it — the state a renamed calendar leaves behind.
		await renderConvertible( ENQUIRY );

		expect(
			[
				...screen
					.getByLabelText( 'Calendar' )
					.querySelectorAll( 'option' ),
			].map( ( option ) => option.textContent )
		).toEqual( [ 'Full Site', 'Top Site (Festival)', 'Meadow' ] );

		expect(
			screen.getByText(
				'No calendar matches the site exclusivity asked for, so every calendar is offered.'
			)
		).toBeTruthy();
	} );

	it( 'sends a manually edited range, and reports it as a custom one', async () => {
		await renderConvertible( TOP_SITE );

		// A day added at the end by agreement: the range is edited rather than
		// re-chosen, and the control stops claiming to be the ideal range.
		fireEvent.change( screen.getByLabelText( 'End date' ), {
			target: { value: '2026-05-03' },
		} );

		expect( screen.getByLabelText( 'Booking dates' ).value ).toBe(
			'custom'
		);

		const posted = await createBooking();

		expect( posted.date ).toBe( '2026-05-01' );
		expect( posted.end_date ).toBe( '2026-05-03' );
	} );

	it( 'names the candidate range again when an edit lands back on one', async () => {
		await renderConvertible( TOP_SITE );

		fireEvent.change( screen.getByLabelText( 'End date' ), {
			target: { value: '2026-05-03' },
		} );
		fireEvent.change( screen.getByLabelText( 'End date' ), {
			target: { value: '2026-05-02' },
		} );

		// The dates are the ideal range's, so calling them custom would be the
		// control disagreeing with what it is showing.
		expect( screen.getByLabelText( 'Booking dates' ).value ).toBe( '0' );
	} );

	it( 'sends a single-day booking as one date', async () => {
		await renderConvertible( TOP_SITE );

		fireEvent.change( screen.getByLabelText( 'Booking dates' ), {
			target: { value: '1' },
		} );

		const posted = await createBooking();

		// Both bounds are the same day, and the route reads an absent end as
		// exactly that, so the day is not sent twice.
		expect( posted.date ).toBe( '2026-06-14' );
		expect( posted.end_date ).toBeUndefined();
	} );

	it( 'refuses to convert on a range that ends before it starts', async () => {
		await renderConvertible( TOP_SITE );

		fireEvent.change( screen.getByLabelText( 'End date' ), {
			target: { value: '2026-04-01' },
		} );

		expect(
			screen.getByText( 'The end date falls before the start date.' )
		).toBeTruthy();

		// Said here rather than left to the route's 400: the control that would
		// fix it is on screen.
		expect(
			screen.getByRole( 'button', { name: 'Create booking' } ).disabled
		).toBe( true );
	} );

	it( 'offers the conversion to an enquiry holding no candidate ranges', async () => {
		// Nothing to choose from, so the custom range is the whole of the offer.
		// The alternative — withholding the control — leaves an enquiry agreed
		// over the telephone with no way to become the booking it is.
		await renderConvertible( { ...TOP_SITE, date_ranges: [] } );

		expect(
			[
				...screen
					.getByLabelText( 'Booking dates' )
					.querySelectorAll( 'option' ),
			].map( ( option ) => option.value )
		).toEqual( [ 'custom' ] );

		expect( bookingBounds() ).toEqual( [ '', '' ] );
		expect(
			screen.getByRole( 'button', { name: 'Create booking' } ).disabled
		).toBe( true );
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
		// The two stored ranges fill the first two ranked slots, in order, and
		// the third is left blank.
		expect(
			screen.getAllByLabelText( 'Start date' ).map( ( f ) => f.value )
		).toEqual( [ '2026-05-01', '2026-06-14', '' ] );
		expect(
			screen.getAllByLabelText( 'End date' ).map( ( f ) => f.value )
		).toEqual( [ '2026-05-02', '2026-06-14', '' ] );
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

describe( 'EnquiryDetail transition dialog', () => {
	it( 'asks for a comment instead of applying the transition on the click', async () => {
		await renderPanel( ENQUIRY );

		fireEvent.click( screen.getByRole( 'button', { name: 'Mark Quoted' } ) );

		// The dialog is named for the transition it will apply, so the answer to
		// "which one am I confirming" is in the title rather than only in the
		// button that was clicked behind it.
		expect(
			screen.getByRole( 'dialog', { name: 'Mark Quoted' } )
		).toBeTruthy();

		// Nothing has been applied yet: this is the whole point of the dialog.
		expect( statusBody() ).toBeNull();
	} );

	it( 'sends the comment with the transition', async () => {
		const { container } = await renderPanel( ENQUIRY, {
			'POST enquiries/7/status': ( options ) => ( {
				...ENQUIRY,
				status: options.data.status,
				note_id: 4,
			} ),
		} );

		await confirmTransition( 'Mark Quoted', 'Sent the quote for the marquee.' );

		expect( statusBody() ).toEqual( {
			status: 'quoted',
			note: 'Sent the quote for the marquee.',
		} );

		expect( noticeText( container ) ).toBe( 'Status set to Quoted.' );
	} );

	it( 'sends the transition alone when no comment was written', async () => {
		await renderPanel( ENQUIRY, {
			'POST enquiries/7/status': ( options ) => ( {
				...ENQUIRY,
				status: options.data.status,
			} ),
		} );

		await confirmTransition( 'Mark Quoted' );

		// No `note` key at all rather than an empty one: the comment is optional,
		// and a blank string would be a note the route had to refuse.
		expect( statusBody() ).toEqual( { status: 'quoted' } );
	} );

	it( 'applies nothing when the dialog is cancelled', async () => {
		await renderPanel( ENQUIRY );

		fireEvent.click( screen.getByRole( 'button', { name: 'Mark Lost' } ) );

		fireEvent.change( screen.getByLabelText( 'Comment (optional)' ), {
			target: { value: 'Typed, then thought better of it.' },
		} );

		cancelDialog();

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( statusBody() ).toBeNull();
	} );

	it( 'forgets a cancelled comment rather than offering it to the next transition', async () => {
		await renderPanel( ENQUIRY );

		fireEvent.click( screen.getByRole( 'button', { name: 'Mark Lost' } ) );

		fireEvent.change( screen.getByLabelText( 'Comment (optional)' ), {
			target: { value: 'Wrong button.' },
		} );

		cancelDialog();

		fireEvent.click( screen.getByRole( 'button', { name: 'Mark Quoted' } ) );

		// A comment written for a transition that was abandoned has nothing to do
		// with the next one, and carrying it over would attach a reason to a status
		// change it was not written about.
		expect( screen.getByLabelText( 'Comment (optional)' ).value ).toBe( '' );
	} );

	it( 'reports a comment the route refused alongside the status that did change', async () => {
		// The route applies the transition first and the comment second, so the
		// two can land separately. A user told only "Status set to Lost" would have
		// no way to know the reason they wrote went nowhere.
		const { container } = await renderPanel( ENQUIRY, {
			'POST enquiries/7/status': ( options ) => ( {
				...ENQUIRY,
				status: options.data.status,
				note_error: 'The note was too long.',
			} ),
		} );

		await confirmTransition( 'Mark Lost', 'Went with another venue.' );

		expect( noticeText( container ) ).toBe(
			'Status set to Lost. The comment was not saved: The note was too long.'
		);
	} );
} );

describe( 'EnquiryDetail closure outcome', () => {
	it( 'shows the status the enquiry closed from beside closed', async () => {
		const { container } = await renderPanel( CLOSED );

		const badges = [
			...container.querySelectorAll( '.meh-detail__head .meh-badge' ),
		].map( ( badge ) => badge.textContent );

		expect( badges ).toEqual( [ 'closed', 'converted' ] );
	} );
} );

describe( 'EnquiryDetail history trail', () => {
	// The trail is the longest thing on the panel and the least often wanted, so
	// it is shut on arrival. Asserted through the element's own `open` attribute
	// rather than through what is on screen, because a closed `details` still
	// holds its children in the DOM: a test written against visibility would pass
	// on a trail that was never collapsed at all.
	it( 'arrives collapsed, with the number of entries showing', async () => {
		const { container } = await renderPanel( HYDRATED );

		const trail = container.querySelector( '.meh-detail__trail' );

		expect( trail ).toBeTruthy();
		expect( trail.tagName ).toBe( 'DETAILS' );
		expect( trail.hasAttribute( 'open' ) ).toBe( false );

		// The count is on the summary, so how much is in there is legible without
		// opening it.
		expect(
			within( trail.querySelector( 'summary' ) ).getByText( '1' )
		).toBeTruthy();
	} );

	it( 'shows the entries once it is opened', async () => {
		const { container } = await renderPanel( HYDRATED );

		const trail = container.querySelector( '.meh-detail__trail' );

		fireEvent.click( trail.querySelector( 'summary' ) );

		expect( trail.hasAttribute( 'open' ) ).toBe( true );
		expect(
			within( trail ).getByText( 'Enquiry received.' )
		).toBeTruthy();
	} );
} );

describe( 'EnquiryDetail siblings', () => {
	it( 'shows what each earlier enquiry from the same email was for', async () => {
		const { container } = await renderPanel( {
			...ENQUIRY,
			siblings: [
				{
					id: 3,
					created_at: '2025-11-04 08:00:00',
					status: 'closed',
					closed_from: 'lost',
					event_type: [ 'party' ],
					site_exclusivity: [ 'shared' ],
				},
				{
					id: 4,
					created_at: '2025-09-01 08:00:00',
					status: 'new',
					closed_from: '',
					event_type: [],
					site_exclusivity: [],
				},
			],
		} );

		const rows = [
			...container.querySelectorAll( '.meh-detail__sibling-terms' ),
		].map( ( terms ) =>
			[ ...terms.querySelectorAll( 'dt, dd' ) ].map(
				( cell ) => cell.textContent
			)
		);

		expect( rows ).toEqual( [
			[ 'Event type', 'party', 'Site exclusivity', 'shared' ],
			// An enquiry holding no term for a taxonomy reads as nothing selected,
			// which is a legitimate state, not a missing value.
			[
				'Event type',
				'None selected',
				'Site exclusivity',
				'None selected',
			],
		] );

		// The closure outcome travels with a sibling too, so a list of earlier
		// enquiries says which ones were won.
		expect(
			[
				...container.querySelectorAll( '.meh-badge--outcome' ),
			].map( ( badge ) => badge.textContent )
		).toEqual( [ 'lost' ] );
	} );
} );

describe( 'EnquiryDetail unsaved note', () => {
	it( 'reports an unfinished note upward, and reports it gone once cleared', async () => {
		const onDirtyChange = jest.fn();

		apiFetch.mockImplementation(
			respondWith( {
				'GET calendars': () => ( { available: true, calendars: [] } ),
				'GET enquiries/7': () => HYDRATED,
			} )
		);

		render(
			<EnquiryDetail
				id={ ENQUIRY.id }
				enquiry={ ENQUIRY }
				onDirtyChange={ onDirtyChange }
			/>
		);

		await act( async () => {} );

		// Nothing typed yet, so nothing to lose.
		expect( onDirtyChange ).toHaveBeenLastCalledWith( false );

		fireEvent.change( screen.getByLabelText( 'Add a note' ), {
			target: { value: 'Half a thought' },
		} );

		expect( onDirtyChange ).toHaveBeenLastCalledWith( true );

		// Whitespace is not a note: it cannot be added, so warning about losing it
		// would be warning about nothing.
		fireEvent.change( screen.getByLabelText( 'Add a note' ), {
			target: { value: '   ' },
		} );

		expect( onDirtyChange ).toHaveBeenLastCalledWith( false );
	} );

	it( 'reports the note gone when the panel unmounts', async () => {
		const onDirtyChange = jest.fn();

		apiFetch.mockImplementation(
			respondWith( {
				'GET calendars': () => ( { available: true, calendars: [] } ),
				'GET enquiries/7': () => HYDRATED,
			} )
		);

		const { unmount } = render(
			<EnquiryDetail
				id={ ENQUIRY.id }
				enquiry={ ENQUIRY }
				onDirtyChange={ onDirtyChange }
			/>
		);

		await act( async () => {} );

		fireEvent.change( screen.getByLabelText( 'Add a note' ), {
			target: { value: 'Half a thought' },
		} );

		expect( onDirtyChange ).toHaveBeenLastCalledWith( true );

		unmount();

		// A panel that has gone is holding nothing. Leaving the flag set would make
		// the next navigation warn about a note that no longer exists.
		expect( onDirtyChange ).toHaveBeenLastCalledWith( false );
	} );
} );

/**
 * Open a transition's dialog, optionally write a comment, and confirm it.
 *
 * The confirm button carries the same label as the button that opened the dialog,
 * so it is looked up inside the dialog rather than on the page.
 *
 * @param {string} label   The Mark button's label.
 * @param {string} comment Comment to write, or nothing for none.
 * @return {Promise<void>} Resolves once the write and its read-back have settled.
 */
async function confirmTransition( label, comment = '' ) {
	fireEvent.click( screen.getByRole( 'button', { name: label } ) );

	if ( '' !== comment ) {
		fireEvent.change( screen.getByLabelText( 'Comment (optional)' ), {
			target: { value: comment },
		} );
	}

	await act( async () => {
		fireEvent.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: label,
			} )
		);
	} );
}

/**
 * The panel's success notice as text.
 *
 * Read from the render container rather than through `screen`, because `Notice`
 * also announces itself into an `aria-live` region that WordPress appends to the
 * document body — so the same sentence is on the page twice, and only one of the
 * two is the notice.
 *
 * @param {HTMLElement} container Render container.
 * @return {string} Notice text, or the empty string when none is shown.
 */
function noticeText( container ) {
	const notice = container.querySelector(
		'.components-notice.is-success .components-notice__content'
	);

	return notice ? notice.textContent : '';
}

/**
 * Dismiss the open transition dialog. No flush: cancelling starts no request.
 *
 * @return {void}
 */
function cancelDialog() {
	fireEvent.click(
		within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
			name: 'Cancel',
		} )
	);
}

/**
 * The body of the status request, or null when none was made.
 *
 * @return {Object|null} Request body.
 */
function statusBody() {
	const call = apiFetch.mock.calls.find(
		( [ options ] ) =>
			'enquiries/7/status' === options.path && 'POST' === options.method
	);

	return call ? call[ 0 ].data : null;
}

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
