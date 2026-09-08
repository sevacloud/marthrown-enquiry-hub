/**
 * StatusBadge component tests.
 *
 * The badge answers one question the status alone cannot: a `closed` enquiry is
 * finished, but won or lost is the part anyone scanning a list of closed
 * enquiries wants. `closed_from` carries it, and the rules for showing it are
 * asserted here because they are the whole of the component's logic — the pair
 * is rendered only when the enquiry is closed *and* the API derived an outcome
 * for it, so neither a closed enquiry with no recorded closure nor an open
 * enquiry that happens to carry the field can produce a second badge.
 *
 * That second condition is not hypothetical. `closed_from` is derived from the
 * `status_changed` trail, so an enquiry closed before the trail existed has
 * none, and the badge has to degrade to the status alone rather than to an
 * invented outcome.
 */
import { render } from '@testing-library/react';
import StatusBadge from './StatusBadge';

/**
 * The badges one render produced, as `[ text, className ]` pairs in order.
 *
 * @param {HTMLElement} container Render container.
 * @return {Array[]} Rendered badges.
 */
function badges( container ) {
	return [ ...container.querySelectorAll( '.meh-badge' ) ].map( ( badge ) => [
		badge.textContent,
		badge.className,
	] );
}

describe( 'StatusBadge', () => {
	it( 'renders the status alone for an open enquiry', () => {
		const { container } = render( <StatusBadge status="quoted" /> );

		expect( badges( container ) ).toEqual( [
			[ 'quoted', 'meh-badge meh-badge--quoted' ],
		] );
	} );

	it( 'renders the outcome beside the status for a closed enquiry', () => {
		const { container } = render(
			<StatusBadge status="closed" closedFrom="converted" />
		);

		// The status stays first and stays `closed`: that is what the enquiry
		// holds. The outcome is additional, and carries `--outcome` so it reads
		// as an outline rather than as a second status.
		expect( badges( container ) ).toEqual( [
			[ 'closed', 'meh-badge meh-badge--closed' ],
			[ 'converted', 'meh-badge meh-badge--converted meh-badge--outcome' ],
		] );
	} );

	it( 'renders the status alone for a closed enquiry with no recorded outcome', () => {
		const { container } = render(
			<StatusBadge status="closed" closedFrom="" />
		);

		expect( badges( container ) ).toEqual( [
			[ 'closed', 'meh-badge meh-badge--closed' ],
		] );
	} );

	it( 'ignores an outcome on an enquiry that is not closed', () => {
		// The API returns `''` for an open enquiry, so this is a payload that
		// should not arise — which is exactly why the component should not
		// render a closure outcome for an enquiry that has not closed.
		const { container } = render(
			<StatusBadge status="lost" closedFrom="quoted" />
		);

		expect( badges( container ) ).toEqual( [
			[ 'lost', 'meh-badge meh-badge--lost' ],
		] );
	} );

	it( 'falls back to new when no status was supplied', () => {
		const { container } = render( <StatusBadge status="" /> );

		expect( badges( container ) ).toEqual( [
			[ 'new', 'meh-badge meh-badge--new' ],
		] );
	} );
} );
