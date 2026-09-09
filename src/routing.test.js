/**
 * Routing tests.
 *
 * Two URL shapes, because the same bundle runs in two places, and the whole point
 * of the module is that neither of them is anyone else's problem. What matters is
 * that reading and writing are inverses — a URL read into a route and written back
 * out is the URL it started as — and that writing never loses a query arg that is
 * nothing to do with us. `page` is the arg that proves it: drop that in wp-admin
 * and the URL no longer names the screen the app is on.
 */
import { readLocation, urlFor, initialRoute, currentUrl } from './routing';

const HUB = 'http://localhost/bookings/';

/**
 * Put the browser somewhere.
 *
 * @param {string} url Path and query string.
 */
function at( url ) {
	window.history.replaceState( {}, '', url );
}

beforeEach( () => {
	window.mehData = { hubUrl: HUB };
	at( '/bookings/' );
} );

describe( 'routing on the front-end hub', () => {
	it( 'reads the Overview from the hub root', () => {
		at( '/bookings/' );

		expect( readLocation() ).toEqual( { view: 'overview', enquiryId: 0 } );
	} );

	it( 'reads a view from the path, with or without a trailing slash', () => {
		at( '/bookings/calendar/' );
		expect( readLocation().view ).toBe( 'calendar' );

		at( '/bookings/calendar' );
		expect( readLocation().view ).toBe( 'calendar' );
	} );

	it( 'reads the open enquiry from the query string', () => {
		at( '/bookings/?enquiry=42' );

		expect( readLocation() ).toEqual( { view: 'overview', enquiryId: 42 } );
	} );

	it( 'treats a segment that is not a view as the Overview', () => {
		// The server would have 404ed this URL, so it is only reachable by a
		// pushState we did not make — and the Overview is the safe reading.
		at( '/bookings/nonsense/' );

		expect( readLocation().view ).toBe( 'overview' );
	} );

	it( 'ignores an enquiry id outside the view that can show one', () => {
		at( '/bookings/calendar/?enquiry=42' );

		expect( readLocation() ).toEqual( { view: 'calendar', enquiryId: 0 } );
	} );

	it.each( [
		[ 'nothing', '', 0, '/bookings/' ],
		[ 'a rubbish id', '', -1, '/bookings/' ],
		[ 'an open enquiry', 'overview', 42, '/bookings/?enquiry=42' ],
		[ 'a view', 'calendar', 0, '/bookings/calendar/' ],
		[ 'a view, ignoring the id', 'calendar', 42, '/bookings/calendar/' ],
	] )( 'writes a URL for %s', ( _label, view, enquiryId, expected ) => {
		expect( urlFor( { view, enquiryId } ) ).toBe( expected );
	} );

	it( 'drops the enquiry arg on the way back to the list', () => {
		at( '/bookings/?enquiry=42' );

		expect( urlFor( { view: 'overview', enquiryId: 0 } ) ).toBe(
			'/bookings/'
		);
	} );

	it( 'keeps query args that are none of its business', () => {
		at( '/bookings/?utm_source=email&enquiry=1' );

		expect( urlFor( { view: 'calendar' } ) ).toBe(
			'/bookings/calendar/?utm_source=email'
		);
	} );

	it( 'round-trips every URL it writes', () => {
		[
			{ view: 'overview', enquiryId: 0 },
			{ view: 'overview', enquiryId: 42 },
			{ view: 'calendar', enquiryId: 0 },
		].forEach( ( route ) => {
			at( urlFor( route ) );

			expect( readLocation() ).toEqual( route );
		} );
	} );
} );

describe( 'routing in wp-admin', () => {
	beforeEach( () => {
		at( '/wp-admin/admin.php?page=marthrown-enquiry-hub' );
	} );

	it( 'carries the view as a query arg, since the path is not ours', () => {
		expect( urlFor( { view: 'calendar' } ) ).toBe(
			'/wp-admin/admin.php?page=marthrown-enquiry-hub&view=calendar'
		);
	} );

	it( 'keeps the arg naming the screen it is on', () => {
		at( '/wp-admin/admin.php?page=marthrown-enquiry-hub&view=calendar' );

		expect( urlFor( { view: 'overview', enquiryId: 7 } ) ).toBe(
			'/wp-admin/admin.php?page=marthrown-enquiry-hub&enquiry=7'
		);
	} );

	it( 'reads the view back out of the query arg', () => {
		at( '/wp-admin/admin.php?page=marthrown-enquiry-hub&view=calendar' );

		expect( readLocation().view ).toBe( 'calendar' );
	} );

	it( 'reads no view from a path that only looks like the hub', () => {
		// The hub is /bookings/; this is a post that happens to start the same way,
		// and its second segment is not a view of ours to read.
		at( '/bookings-and-enquiries/calendar/' );

		expect( readLocation().view ).toBe( 'overview' );
	} );
} );

describe( 'the route the page opened at', () => {
	it( 'takes the view the server routed when the location does not say', () => {
		// A front-end view URL this page could not recognise as one: the server
		// routed it, so it is the authority left standing.
		at( '/somewhere-else/' );
		window.mehData = { hubUrl: HUB, view: 'calendar' };

		expect( initialRoute() ).toEqual( { view: 'calendar', enquiryId: 0 } );
	} );

	it( 'lets the URL win, which is the only thing that asks in wp-admin', () => {
		at( '/wp-admin/admin.php?page=meh&view=calendar' );
		window.mehData = { hubUrl: HUB, view: 'overview' };

		expect( initialRoute().view ).toBe( 'calendar' );
	} );

	it( 'keeps the open enquiry the URL arrived with', () => {
		at( '/bookings/?enquiry=42' );

		expect( initialRoute() ).toEqual( { view: 'overview', enquiryId: 42 } );
	} );
} );

describe( 'currentUrl', () => {
	it( 'answers in the same terms urlFor does', () => {
		at( '/bookings/calendar/?enquiry=1' );

		expect( currentUrl() ).toBe( '/bookings/calendar/?enquiry=1' );
	} );
} );
