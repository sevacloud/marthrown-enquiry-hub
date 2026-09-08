/**
 * Leave guard tests.
 *
 * The guard's whole job is to be a question, so what is asserted is that it asks
 * one and returns the answer unchanged — and, more importantly, what it does
 * where it cannot ask. An environment with no `window.confirm` answers yes,
 * because a guard that blocked navigation somewhere it could not put the question
 * would strand a user with no way forward, which is a worse failure than losing a
 * note nobody typed.
 */
import { confirmDiscardNote } from './leaveGuard';

describe( 'confirmDiscardNote', () => {
	let original;

	beforeEach( () => {
		original = window.confirm;
	} );

	afterEach( () => {
		window.confirm = original;
	} );

	it( 'passes on the answer when it can ask', () => {
		window.confirm = jest.fn( () => true );

		expect( confirmDiscardNote() ).toBe( true );

		// The wording says what will be lost rather than asking "are you sure": a
		// user who has typed a note knows they typed it, and needs to be told that
		// leaving is what loses it.
		expect( window.confirm ).toHaveBeenCalledWith(
			'This enquiry has a note you have not added yet. Leaving now will discard it.'
		);
	} );

	it( 'passes on a refusal too', () => {
		window.confirm = jest.fn( () => false );

		expect( confirmDiscardNote() ).toBe( false );
	} );

	it( 'answers yes where there is nothing to ask with', () => {
		window.confirm = undefined;

		expect( confirmDiscardNote() ).toBe( true );
	} );
} );
