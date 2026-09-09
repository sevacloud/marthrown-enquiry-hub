/**
 * CalendarView component test.
 *
 * Covers the month ruler and the month picker: the weekday labels the columns
 * carry, today's column being marked, and the two selects moving the view.
 *
 * The expectations are derived from the real clock rather than a frozen one, so
 * "today" is whatever day the suite runs on and the weekday labels are checked
 * against the calendar arithmetic for that month — a test that pins a date
 * passes for the wrong reason on every other date.
 */
import { act, render, screen, fireEvent, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { respondWith } from '../testing/apiFetchMock';
import CalendarView from './CalendarView';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const ABBR = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thurs', 'Fri', 'Sat' ];

const NOW = new Date();
const THIS_MONTH = `${ NOW.getFullYear() }-${ String(
	NOW.getMonth() + 1
).padStart( 2, '0' ) }`;

/**
 * Days in a month, the way the REST route reports them.
 *
 * @param {string} ym 'YYYY-MM'.
 * @return {number}
 */
function daysIn( ym ) {
	const [ y, m ] = ym.split( '-' ).map( Number );
	return new Date( y, m, 0 ).getDate();
}

/**
 * The overview for a month, with one booking on one calendar.
 *
 * @param {string} ym 'YYYY-MM'.
 * @return {Object} Response body.
 */
function overview( ym ) {
	return {
		month: ym,
		days: daysIn( ym ),
		available: true,
		calendars: [
			{
				id: 7,
				name: 'Bunkhouse',
				color: '#000000',
				bookings: [
					{
						id: 41,
						guest: 'Ada Lovelace',
						status: 'accepted',
						start_date: `${ ym }-02`,
						end_date: `${ ym }-04`,
						view_url: 'https://example.test/booking=41',
					},
				],
				placeholders: [],
			},
		],
	};
}

beforeEach( () => {
	apiFetch.mockReset();
	apiFetch.mockImplementation(
		respondWith( {
			'GET bookings/calendar': ( { path } ) => {
				const asked = /month=([\d-]+)/.exec( path );
				return overview( asked ? asked[ 1 ] : THIS_MONTH );
			},
		} )
	);
} );

/**
 * Render and wait for the first month to arrive.
 *
 * @return {Promise<Object>} The render result.
 */
async function open() {
	const result = render( <CalendarView /> );
	await waitFor( () =>
		expect( screen.getByText( 'Bunkhouse' ) ).toBeTruthy()
	);
	return result;
}

describe( 'CalendarView', () => {
	it( 'labels every column with its own weekday', async () => {
		const { container } = await open();

		const [ y, m ] = THIS_MONTH.split( '-' ).map( Number );
		const expected = Array.from(
			{ length: daysIn( THIS_MONTH ) },
			( _, i ) => ABBR[ new Date( y, m - 1, i + 1 ).getDay() ]
		);

		expect(
			[ ...container.querySelectorAll( '.meh-calendar-dow' ) ].map(
				( el ) => el.textContent
			)
		).toEqual( expected );
	} );

	it( "marks only today's column, and only in this month", async () => {
		const { container } = await open();

		const marked = () => [
			...container.querySelectorAll( '.meh-calendar-daycell.is-today' ),
		];

		// The header ruler and the one calendar row, both on today's date.
		expect( marked().length ).toBe( 2 );
		marked().forEach( ( cell ) =>
			expect( cell.textContent.endsWith( String( NOW.getDate() ) ) ).toBe(
				true
			)
		);

		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Year' ), {
				target: { value: String( NOW.getFullYear() - 2 ) },
			} );
		} );

		expect( marked() ).toEqual( [] );
	} );

	it( 'moves to the month and year the selects name', async () => {
		await open();

		expect( screen.getByLabelText( 'Month' ).value ).toBe(
			String( NOW.getMonth() + 1 )
		);
		expect( screen.getByLabelText( 'Year' ).value ).toBe(
			String( NOW.getFullYear() )
		);

		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Month' ), {
				target: { value: '2' },
			} );
		} );
		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Year' ), {
				target: { value: '2025' },
			} );
		} );

		expect(
			apiFetch.mock.calls.some( ( [ options ] ) =>
				options.path.includes( 'month=2025-02' )
			)
		).toBe( true );
		// February, so the ruler is 28 or 29 columns rather than 30 or 31.
		expect(
			document.querySelectorAll( '.meh-calendar-dow' ).length
		).toBe( 28 );
	} );

	it( 'steps a month at a time with the arrows, into the year before', async () => {
		await open();

		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Month' ), {
				target: { value: '1' },
			} );
		} );
		await act( async () => {
			fireEvent.click( screen.getByLabelText( 'Previous month' ) );
		} );

		// December of the previous year is reachable with the arrows, and the
		// year picker still offers the year it landed on.
		expect( screen.getByLabelText( 'Month' ).value ).toBe( '12' );
		expect( screen.getByLabelText( 'Year' ).value ).toBe(
			String( NOW.getFullYear() - 1 )
		);
	} );
} );
