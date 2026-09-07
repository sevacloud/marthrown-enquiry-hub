/**
 * EnquiryForm component tests.
 *
 * Two things are asserted here that no server-side test can see: which fields
 * the create form marks required and which it marks optional, and that a 400's
 * per-field `errors` map is rendered against the fields it names — all of them,
 * in one pass, rather than one failure per attempt.
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
 * The three text fields the form marks required, and the fourth it marks
 * required as the start date.
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
 * Fill in the four fields creation requires.
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
	fireEvent.change( screen.getByLabelText( 'Start date' ), {
		target: { value: date },
	} );
}

beforeEach( () => {
	apiFetch.mockReset();
} );

describe( 'EnquiryForm in create mode', () => {
	it( 'marks the three required text fields and the start date required', () => {
		render( <EnquiryForm /> );

		REQUIRED.forEach( ( label ) => {
			expect( screen.getByLabelText( label ).required ).toBe( true );
		} );

		expect( screen.getByLabelText( 'Start date' ).required ).toBe( true );
		expect( screen.getByLabelText( 'End date' ).required ).toBe( false );
	} );

	it( 'marks the remaining five fields optional and says so', () => {
		render( <EnquiryForm /> );

		OPTIONAL.forEach( ( label ) => {
			const field = screen.getByLabelText( label );

			expect( field.required ).toBe( false );
			expect( helpFor( field ) ).toMatch( /^Optional\./ );
		} );
	} );

	it( 'renders every field a 400 names, against the field it names, in one pass', async () => {
		apiFetch.mockImplementation(
			respondWith( {
				'POST enquiries': () => {
					throw validationFailure( {
						email: 'invalid_email',
						phone: 'no_digits',
						selected_dates: 'too_few_dates',
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
			'Choose at least one candidate date.',
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

		const dateError = screen.getByText(
			'Choose at least one candidate date.'
		);
		expect( dateError.closest( 'fieldset' ) ).toBe(
			screen.getByLabelText( 'Start date' ).closest( 'fieldset' )
		);
	} );
} );
