/**
 * The hub's URL, read and written.
 *
 * The app has two things worth putting in a URL: which view is showing, and
 * which enquiry is open. Both were state and nothing else, so a view could not be
 * linked to, a reload lost it, and an enquiry someone wanted to hand to a
 * colleague had to be described rather than sent. Here they are a location.
 *
 * There are two shapes of URL because the same bundle runs in two places:
 *
 * - *The front-end route.* `/bookings` is the Overview and each other view has a
 *   path of its own, `/bookings/calendar`, which is the URL the rewrite rules in
 *   `FrontendBookings` answer. The open enquiry is `?enquiry=<id>` — a query arg
 *   rather than a path segment, because an enquiry is a thing the Overview is
 *   showing rather than a place of its own, and because the id is not something
 *   the server routes on.
 * - *wp-admin.* The page is `admin.php?page=…` and its path is not ours to
 *   change, so the view travels as `?view=calendar` beside it. Every other query
 *   arg on the URL is left exactly as it was found, `page` included: rewriting a
 *   URL here must not lose the arg that decides which screen this is.
 *
 * Which shape is in force is decided by where the page actually is — `hubUrl`
 * localised by PHP, compared against the current path — rather than by a flag
 * someone has to remember to set.
 *
 * Nothing here touches history: these functions only read the location and build
 * URLs from state. `App` owns the pushing, because it owns the state.
 */

/**
 * The views, by the name the URL and `FrontendBookings::VIEWS` both use.
 *
 * The Overview leads and is the default because it is the hub's root: a URL
 * naming no view is asking for it, and so is a URL naming a view that no longer
 * exists.
 */
export const VIEWS = [ 'overview', 'calendar' ];

export const DEFAULT_VIEW = 'overview';

const ENQUIRY_PARAM = 'enquiry';
const VIEW_PARAM = 'view';

/**
 * The path the hub is served from, with a trailing slash, or `''` when this page
 * is not the front-end route.
 *
 * @return {string} Path, e.g. `/bookings/`.
 */
function hubPath() {
	const configured = ( window.mehData || {} ).hubUrl;

	if ( ! configured ) {
		return '';
	}

	let path;

	try {
		path = new URL( configured, window.location.href ).pathname;
	} catch ( error ) {
		// A hub URL that will not parse is one we cannot compare paths against,
		// so the query-arg shape is what is left — and it works anywhere.
		return '';
	}

	return withSlash( path );
}

/**
 * Whether the view belongs in the path on this page.
 *
 * @return {boolean} True on the front-end hub route.
 */
function pathCarriesView() {
	const base = hubPath();

	return '' !== base && withSlash( window.location.pathname ).startsWith( base );
}

/**
 * A path with exactly one trailing slash.
 *
 * @param {string} path Path.
 * @return {string} Path ending in a slash.
 */
function withSlash( path ) {
	return path.endsWith( '/' ) ? path : path + '/';
}

/**
 * A view name, or the default when it is not one.
 *
 * @param {*} value Candidate name.
 * @return {string} One of VIEWS.
 */
function knownView( value ) {
	return VIEWS.includes( value ) ? value : DEFAULT_VIEW;
}

/**
 * An enquiry id as a positive integer, or 0 for "none open".
 *
 * @param {*} value Candidate id.
 * @return {number} Id, or 0.
 */
function toId( value ) {
	const id = Number.parseInt( value, 10 );

	return Number.isFinite( id ) && id > 0 ? id : 0;
}

/**
 * What the current URL is asking for.
 *
 * An enquiry is only ever open in the Overview, so an id alongside any other view
 * is dropped rather than carried into a view that has nowhere to show it.
 *
 * @return {{view: string, enquiryId: number}} The route.
 */
export function readLocation() {
	const params = new URLSearchParams( window.location.search );
	const view = pathCarriesView()
		? knownView(
				withSlash( window.location.pathname )
					.slice( hubPath().length )
					.replace( /\/+$/, '' )
		  )
		: knownView( params.get( VIEW_PARAM ) );

	return {
		view,
		enquiryId:
			DEFAULT_VIEW === view ? toId( params.get( ENQUIRY_PARAM ) ) : 0,
	};
}

/**
 * The URL a route should be shown at, relative to the host.
 *
 * Built from the location it is replacing, so query args that are nothing to do
 * with us survive being navigated over.
 *
 * @param {Object} route             The route.
 * @param {string} [route.view]      View name.
 * @param {number} [route.enquiryId] Open enquiry, or 0.
 * @return {string} Path and query string.
 */
export function urlFor( { view, enquiryId } = {} ) {
	const wanted = knownView( view );
	const id = DEFAULT_VIEW === wanted ? toId( enquiryId ) : 0;
	const params = new URLSearchParams( window.location.search );
	let path = window.location.pathname;

	if ( id > 0 ) {
		params.set( ENQUIRY_PARAM, String( id ) );
	} else {
		params.delete( ENQUIRY_PARAM );
	}

	if ( pathCarriesView() ) {
		// The path says which view it is, so the arg would be a second answer to
		// the same question — and one the server does not read.
		params.delete( VIEW_PARAM );
		path =
			DEFAULT_VIEW === wanted ? hubPath() : hubPath() + wanted + '/';
	} else if ( DEFAULT_VIEW === wanted ) {
		params.delete( VIEW_PARAM );
	} else {
		params.set( VIEW_PARAM, wanted );
	}

	const query = params.toString();

	return query ? `${ path }?${ query }` : path;
}

/**
 * Where the browser is now, in the same terms `urlFor()` answers in.
 *
 * @return {string} Path and query string.
 */
export function currentUrl() {
	return window.location.pathname + window.location.search;
}

/**
 * The route the page was opened at.
 *
 * The server has already decided this once — it routed the request and localised
 * `view` — and it is the authority on the front end, where an unknown segment
 * would have 404ed rather than reached us. The location is read as well because
 * wp-admin localises no view of its own and because the enquiry id never reaches
 * the server at all.
 *
 * @return {{view: string, enquiryId: number}} The route.
 */
export function initialRoute() {
	const fromLocation = readLocation();
	const fromServer = knownView( ( window.mehData || {} ).view );

	// The URL wins when it named a view, which is the only thing that could have
	// asked for one in wp-admin — where the server says Overview whatever screen
	// this is. The server is left to answer for the case the location cannot:
	// a front-end view URL this page could not recognise as one.
	if ( DEFAULT_VIEW !== fromLocation.view || DEFAULT_VIEW === fromServer ) {
		return fromLocation;
	}

	// A view that is not the Overview has no enquiry open in it.
	return { view: fromServer, enquiryId: 0 };
}
