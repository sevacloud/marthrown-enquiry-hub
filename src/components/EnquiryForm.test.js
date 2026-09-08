/**
 * EnquiryForm component tests.
 *
 * Three things are asserted here that no server-side test can see: which fields
 * the create form marks required and which it marks optional, that a 400's
 * per-field `errors` map is rendered against the fields it names — all of them,
 * in one pass, rather than one failure per attempt — and the shape of the
 * `date_ranges` list the form builds out of its three ranked range slots.
 *
 * The transport is mocked, not `../api`, so the client's `WP_Error` unwrapping
 * is part of what runs: the test supplies the envelope WordPress would send.
 *
 * **Validates: Requirements 18.23, 19.20**
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { respondWith, validationFailure } from '../testing/apiFetchMock';
import EnquiryForm from './EnquiryForm';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

/**
 * The three text fields the form marks required, alongside the ideal start date.
 */
const REQUIRED = [ 'First name', 'Last name', 'Email' ];

/**
 * The five fields the Manual Validation Profile treats as optional.
 */
const OPTIONAL = [
	'Phone',
	'Total guests',
	'Event type',
	'Site exclusivity',
	'Message',
];

/**
 * The help text a control carries, however the control associated it.
 *
 * @param {HTMLElement} field Input or textarea.
 * @return {string} Help text.
 */
function helpFor( field ) {
	const id = field.getAttribute( 'aria-describedby' );
	const described = id ? document.getElementById( id ) : null;

	if ( described ) {
		return described.textContent;
	}

	const wrapper = field.closest( '.components-base-control' );
	const help =
		wrapper && wrapper.querySelector( '.components-base-control__help' );

	return help ? help.textContent : '';
}

/**
 * One bound of one range slot, counting slots from zero.
 *
 * The three slots repeat the same two labels, so they are reached by position
 * rather than by name: slot 0 is the ideal range.
 *
 * @param {number} slot  Range slot.
 * @param {string} bound 'Start date' | 'End date'.
 * @return {HTMLElement} The date input.
 */
function dateInput( slot, bound ) {
	return screen.getAllByLabelText( bound )[ slot ];
}

/**
 * Fill in the fields creation requires: the three names and the ideal start.
 *
 * @param {Object} values first_name, last_name, email, date.
 */
function fillRequired( {
	firstName = 'Grace',
	lastName = 'Hopper',
	email = 'grace@example.com',
	date = '2026-06-01',
} = {} ) {
	fireEvent.change( screen.getByLabelText( 'First name' ), {
		target: { value: firstName },
	} );
	fireEvent.change( screen.getByLabelText( 'Last name' ), {
		target: { value: lastName },
	} );
	fireEvent.change( screen.getByLabelText( 'Email' ), {
		target: { value: email },
	} );
	fireEvent.change( dateInput( 0, 'Start date' ), {
		target: { value: date },
	} );
}

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'EnquiryForm in create mode', () => {
	it( 'marks the three required text fields and the ideal start date required', () => {
		render( <EnquiryForm /> );

		REQUIRED.forEach( ( label ) => {
			expect( screen.getByLabelText( label ).required ).toBe( true );
		} );

		expect( dateInput( 0, 'Start date' ).required ).toBe( true );
		expect( dateInput( 0, 'End date' ).required ).toBe( false );
	} );

	it( 'marks the remaining five fields optional and says so', () => {
		render( <EnquiryForm /> );

		OPTIONAL.forEach( ( label ) => {
			const field = screen.getByLabelText( label );

			expect( field.required ).toBe( false );
			expect( helpFor( field ) ).toMatch( /^Optional\./ );
		} );
	} );

	it( 'offers three ranked range slots, only the first of them required', () => {
		render( <EnquiryForm /> );

		expect( screen.getAllByLabelText( 'Start date' ) ).toHaveLength( 3 );
		expect( screen.getAllByLabelText( 'End date' ) ).toHaveLength( 3 );

		expect(
			screen
				.getAllByLabelText( 'Start date' )
				.map( ( field ) => field.required )
		).toEqual( [ true, false, false ] );
	} );

	it( 'submits the ranges in rank order, dropping the slots left blank', async () => {
		const sent = [];

		apiFetch.mockImplementation(
			respondWith( {
				'POST enquiries': ( options ) => {
					sent.push( options.data );

					return { id: 7 };
				},
			} )
		);

		render( <EnquiryForm /> );

		fillRequired( { date: '2026-06-01' } );
		fireEvent.change( dateInput( 0, 'End date' ), {
			target: { value: '2026-06-03' },
		} );

		// The middle slot is left blank and the last one filled, so the last one
		// is submitted as the second range rather than the third.
		fireEvent.change( dateInput( 2, 'Start date' ), {
			target: { value: '2026-07-10' },
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create enquiry' } )
		);

		await waitFor( () => expect( sent ).toHaveLength( 1 ) );

		expect( sent[ 0 ].date_ranges ).toEqual( [
			{ start: '2026-06-01', end: '2026-06-03' },
			// An end date left blank is the start date again: one day.
			{ start: '2026-07-10', end: '2026-07-10' },
		] );
	} );

	it( 'renders every field a 400 names, against the field it names, in one pass', async () => {
		apiFetch.mockImplementation(
			respondWith( {
				'POST enquiries': () => {
					throw validationFailure( {
						email: 'invalid_email',
						phone: 'no_digits',
						date_ranges: 'too_few_ranges',
					} );
				},
			} )
		);

		const { container } = render( <EnquiryForm /> );

		fillRequired();
		fireEvent.click( screen.getByRole( 'button', { name: 'Create enquiry' } ) );

		await waitFor( () =>
			expect(
				container.querySelectorAll( '.meh-form__error' )
			).toHaveLength( 3 )
		);

		// The three the server named, and nothing for the two it did not.
		const messages = [
			...container.querySelectorAll( '.meh-form__error' ),
		].map( ( node ) => node.textContent );

		expect( messages ).toEqual( [
			'Enter a valid email address.',
			'Give the ideal dates.',
			'Enter a telephone number containing at least one digit.',
		] );

		// Each message sits with its own field rather than in a list apart
		// from them: the email one directly after the email control, the
		// candidate-date one inside the date fieldset.
		const emailError = screen.getByText( 'Enter a valid email address.' );
		expect(
			emailError.previousElementSibling.contains(
				screen.getByLabelText( 'Email' )
			)
		).toBe( true );

		const dateError = screen.getByText( 'Give the ideal dates.' );
		expect( dateError.closest( 'fieldset' ) ).toBe(
			dateInput( 0, 'Start date' ).closest( 'fieldset' )
		);
	} );
} );

describe( 'EnquiryForm in edit mode', () => {
	it( 'opens on the stored ranges in their stored order', () => {
		render(
			<EnquiryForm
				enquiry={ {
					id: 12,
					first_name: 'Grace',
					last_name: 'Hopper',
					email: 'grace@example.com',
					date_ranges: [
						{ start: '2026-06-01', end: '2026-06-03' },
						{ start: '2026-05-09', end: '2026-05-09' },
					],
				} }
			/>
		);

		// Chronology does not reorder them: the first stored range is the ideal
		// one whether or not it falls first in the year.
		expect(
			screen
				.getAllByLabelText( 'Start date' )
				.map( ( field ) => field.value )
		).toEqual( [ '2026-06-01', '2026-05-09', '' ] );

		expect(
			screen.getAllByLabelText( 'End date' ).map( ( field ) => field.value )
		).toEqual( [ '2026-06-03', '2026-05-09', '' ] );
	} );

	it( 'submits the ranges when their order changes but their days do not', async () => {
		const sent = [];

		apiFetch.mockImplementation(
			respondWith( {
				'PATCH enquiries/12': ( options ) => {
					sent.push( options.data );

					return { id: 12, changed: {} };
				},
			} )
		);

		render(
			<EnquiryForm
				enquiry={ {
					id: 12,
					first_name: 'Grace',
					last_name: 'Hopper',
					email: 'grace@example.com',
					date_ranges: [
						{ start: '2026-06-01', end: '2026-06-01' },
						{ start: '2026-07-10', end: '2026-07-10' },
					],
				} }
			/>
		);

		// Swap the two: the same two days, the other way round.
		fireEvent.change( dateInput( 0, 'Start date' ), {
			target: { value: '2026-07-10' },
		} );
		fireEvent.change( dateInput( 0, 'End date' ), {
			target: { value: '2026-07-10' },
		} );
		fireEvent.change( dateInput( 1, 'Start date' ), {
			target: { value: '2026-06-01' },
		} );
		fireEvent.change( dateInput( 1, 'End date' ), {
			target: { value: '2026-06-01' },
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Save changes' } ) );

		await waitFor( () => expect( sent ).toHaveLength( 1 ) );

		expect( Object.keys( sent[ 0 ] ) ).toEqual( [ 'date_ranges' ] );
		expect( sent[ 0 ].date_ranges ).toEqual( [
			{ start: '2026-07-10', end: '2026-07-10' },
			{ start: '2026-06-01', end: '2026-06-01' },
		] );
	} );
} );
