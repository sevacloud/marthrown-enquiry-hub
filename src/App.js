/**
 * App — side nav switching between the Overview (New Enquiries + Bookings
 * Manager) and the site-wide Calendar. The Calendar view mounts only when
 * selected, so its heavier data loads lazily and never blocks the overview.
 *
 * The nav also links out to WP Booking System and, for administrators only,
 * the hub's Settings screen.
 *
 * The staging banner is not rendered here. `FrontendBookings::render_page()`
 * already emits one above the page header, and two said the same thing twice.
 * The server-rendered one is the survivor because it appears before the bundle
 * has loaded, which is when a staging warning is most worth having.
 *
 * Which view is showing, and which enquiry is open in it, are a location as well
 * as state: the Overview is `/bookings`, the Calendar is `/bookings/calendar`, and
 * an open enquiry adds `?enquiry=<id>`. So a view can be linked to and bookmarked,
 * a reload comes back to it, and an enquiry can be handed to a colleague as a URL.
 * `routing.js` does the reading and writing; this component owns the state, which
 * is why it owns the pushing.
 *
 * The nav does three things a plain view switch would not:
 *
 * - *Overview means the list.* An open enquiry is a state inside the Overview,
 *   not a view of its own, so clicking Overview while one is open has to return
 *   to the list rather than do nothing — which is what it appeared to do, since
 *   the button was already lit. `selection` is how that reaches `EnquiryManager`:
 *   the id to show plus a rising `seq`, so a second click is a second request and
 *   there is no flag here to reset.
 * - *It asks before discarding a note.* Every nav click either returns to the
 *   list or unmounts the panel, and both throw away a half-written note. Only
 *   the panel knows there is one, so it reports upward and the warning is asked
 *   here, where the navigation actually happens.
 * - *Back and forward are navigation too.* They move the URL without asking, so
 *   the note warning is put after the fact and a refusal puts the URL back.
 *
 * On a narrow screen the nav is a drawer instead of a column, opened by the
 * burger and carrying the header's signed-in line, which the header hides at that
 * width. Which layout is in force is the stylesheet's decision alone — this
 * component only tracks whether the drawer is open, so there is no viewport
 * measurement here to fall out of step with the breakpoint.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import EnquiryManager from './components/EnquiryManager';
import BookingsManager from './components/BookingsManager';
import CalendarView from './components/CalendarView';
import { confirmDiscardNote } from './leaveGuard';
import { initialRoute, readLocation, urlFor, currentUrl } from './routing';

const NAV = [
	{ key: 'overview', label: __( 'Overview', 'marthrown-enquiry-hub' ) },
	{ key: 'calendar', label: __( 'Calendar View', 'marthrown-enquiry-hub' ) },
];

export default function App() {
	/*
	 * The whole of the app's place in the world: the view, the enquiry open in it,
	 * and a count of how many times something other than the panel itself has
	 * asked for a different one. The count is what `EnquiryManager` acts on, so a
	 * repeat of a request it has already honoured is still a request; the panel
	 * reporting its own opens leaves it alone.
	 */
	const [ route, setRoute ] = useState( () => ( {
		...initialRoute(),
		seq: 0,
	} ) );
	const [ noteDirty, setNoteDirty ] = useState( false );
	const [ navOpen, setNavOpen ] = useState( false );
	const data = window.mehData || {};
	const view = route.view;

	// What the popstate listener needs to know, without being rebound every time
	// either of them changes: it is bound once, for the life of the app, and reads
	// these when the browser moves.
	const routeRef = useRef( route );
	const dirtyRef = useRef( noteDirty );

	useEffect( () => {
		routeRef.current = route;
		dirtyRef.current = noteDirty;
	}, [ route, noteDirty ] );

	/*
	 * The URL follows the state, in one place and one direction. Everything that
	 * navigates therefore only has to set the state, and the two cannot disagree —
	 * whereas a push beside each of those calls would be a second place to forget.
	 *
	 * The first run replaces rather than pushes: the state was read from this very
	 * URL, so anything it differs by is a tidying-up — a missing trailing slash, an
	 * `?enquiry=` for an enquiry that is not open — and a tidying-up is not a place
	 * the Back button should have to visit.
	 */
	const pushed = useRef( false );

	useEffect( () => {
		const url = urlFor( route );

		if ( url !== currentUrl() ) {
			if ( pushed.current ) {
				window.history.pushState( {}, '', url );
			} else {
				window.history.replaceState( {}, '', url );
			}
		}

		pushed.current = true;
		// `seq` is not part of the URL: a repeat request for the same place is not
		// a new place to go.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ route.view, route.enquiryId ] );

	// Back and forward. The step has already been taken by the time we hear about
	// it, so the note warning is asked afterwards and refusing it means putting the
	// URL back — the one navigation this app cannot ask about in advance.
	useEffect( () => {
		const onPopState = () => {
			const target = readLocation();
			const current = routeRef.current;

			if (
				current.view === target.view &&
				current.enquiryId === target.enquiryId
			) {
				return;
			}

			if ( dirtyRef.current && ! confirmDiscardNote() ) {
				window.history.pushState( {}, '', urlFor( current ) );
				return;
			}

			setNavOpen( false );
			setNoteDirty( false );
			setRoute( { ...target, seq: current.seq + 1 } );
		};

		window.addEventListener( 'popstate', onPopState );

		return () => window.removeEventListener( 'popstate', onPopState );
	}, [] );

	/**
	 * The panel saying which enquiry it has open, so the URL can say the same.
	 *
	 * `seq` does not rise: the panel is already showing this enquiry, and asking it
	 * to show what it is showing would throw away the read it is part-way through.
	 *
	 * @param {number} enquiryId Open enquiry, or 0 for the list.
	 */
	const onSelect = useCallback( ( enquiryId ) => {
		setRoute( ( current ) =>
			current.enquiryId === enquiryId
				? current
				: { ...current, enquiryId }
		);
	}, [] );

	// Escape closes the drawer, the way it closes any overlay. Only bound while
	// it is open, so nothing listens on the desktop layout — where the nav is a
	// column in the page and there is nothing to close.
	useEffect( () => {
		if ( ! navOpen ) {
			return;
		}

		const onKeyDown = ( event ) => {
			if ( 'Escape' === event.key ) {
				setNavOpen( false );
			}
		};

		document.addEventListener( 'keydown', onKeyDown );

		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ navOpen ] );

	/**
	 * Follow a nav item, unless an unsaved note says otherwise.
	 *
	 * The question is asked once, here, before anything moves: cancelling leaves
	 * both the view and the open panel exactly as they were, so a user who
	 * mis-clicks loses nothing.
	 *
	 * @param {string} key The nav item's key.
	 */
	const go = ( key ) => {
		if ( noteDirty && ! confirmDiscardNote() ) {
			return;
		}

		// The drawer has done its job the moment a destination is chosen, and it
		// covers the view it just navigated to. Closed here rather than in the
		// button's own handler so a refused warning above leaves it open, with the
		// nav still on screen to choose again from.
		setNavOpen( false );

		// Whatever note there was is gone the moment this click is honoured —
		// either the panel closes or it unmounts with the view. Clearing the flag
		// here rather than waiting to be told keeps the next click from asking
		// about a note that no longer exists: switching to the Calendar unmounts
		// the reporter along with the panel, so it never gets to say so itself.
		setNoteDirty( false );

		// A nav click asks for a view with nothing open in it — Overview means the
		// list, and the Calendar has no panel to keep. `seq` rises either way, so
		// clicking Overview while it is already the view still returns to the list.
		setRoute( ( current ) => ( {
			view: key,
			enquiryId: 0,
			seq: current.seq + 1,
		} ) );
	};

	return (
		<div className="meh-app">
			{ /* The narrow-screen way into the nav. Present in the markup at every
			     width and hidden by the stylesheet above the breakpoint, so which
			     layout is in force is a CSS question rather than a measurement
			     this component has to make and keep up to date. */ }
			<div className="meh-mobilebar">
				<button
					type="button"
					className="meh-burger"
					aria-expanded={ navOpen }
					aria-controls="meh-sidenav"
					onClick={ () => setNavOpen( ( open ) => ! open ) }
				>
					<span className="meh-burger__bars" aria-hidden="true" />
					{ __( 'Menu', 'marthrown-enquiry-hub' ) }
				</button>
			</div>

			<div className={ `meh-layout${ navOpen ? ' is-nav-open' : '' }` }>
				{ /* A button, not a click-handling div: dismissing the drawer is an
				     action, and this way it is reachable by keyboard and announced
				     as what it does. */ }
				{ navOpen && (
					<button
						type="button"
						className="meh-nav-backdrop"
						aria-label={ __(
							'Close menu',
							'marthrown-enquiry-hub'
						) }
						onClick={ () => setNavOpen( false ) }
					/>
				) }

				<nav className="meh-sidenav" id="meh-sidenav">
					{ /* The drawer's own way out. The burger is behind the
					     backdrop once the drawer is open, so without this the only
					     ways to close it are Escape and a tap outside — neither of
					     them visible. Hidden on the desktop layout, where the nav
					     is a column that is never closed. */ }
					<button
						type="button"
						className="meh-sidenav__close"
						onClick={ () => setNavOpen( false ) }
					>
						{ __( 'Close', 'marthrown-enquiry-hub' ) }
					</button>

					<ul>
						{ NAV.map( ( item ) => (
							<li key={ item.key }>
								<button
									type="button"
									className={
										view === item.key ? 'is-active' : ''
									}
									onClick={ () => go( item.key ) }
								>
									{ item.label }
								</button>
							</li>
						) ) }

						{ /* Sits with the view switcher rather than in the
						     bottom block: it is a destination like the others,
						     and it reads as one. It stays an anchor because it
						     leaves the app — a button would lose the middle
						     click and the open-in-new-tab that a link gives
						     for free. */ }
						{ data.wpbsUrl && (
							<li>
								<a
									className="meh-sidenav__external"
									href={ data.wpbsUrl }
								>
									{ __(
										'Booking System',
										'marthrown-enquiry-hub'
									) }
								</a>
							</li>
						) }
					</ul>

					{ /* Settings is administrator-only, and icon-only, so the
					     accessible name comes from aria-label and the glyph is
					     hidden from assistive technology. */ }
					{ data.isAdmin && data.settingsUrl && (
						<ul className="meh-sidenav__links">
							<li>
								<a
									className="meh-sidenav__settings"
									href={ data.settingsUrl }
									aria-label={ __(
										'Settings',
										'marthrown-enquiry-hub'
									) }
									title={ __(
										'Settings',
										'marthrown-enquiry-hub'
									) }
								>
									<span
										className="dashicons dashicons-admin-generic"
										aria-hidden="true"
									></span>
								</a>
							</li>
						</ul>
					) }

					{ /* Who is signed in, and the two ways out — the header's own
					     line, repeated here for the narrow layout, where the header
					     has no room for it beside the logo and hides it. Shown by
					     the stylesheet only below the breakpoint, so the desktop
					     header keeps saying it and nothing says it twice. */ }
					{ data.userName && (
						<div className="meh-sidenav__account">
							<p className="meh-sidenav__user">
								{ sprintf(
									/* translators: %s: display name */
									__(
										'Signed in as %s',
										'marthrown-enquiry-hub'
									),
									data.userName
								) }
							</p>
							{ data.dashboardUrl && (
								<a href={ data.dashboardUrl }>
									{ __(
										'Dashboard',
										'marthrown-enquiry-hub'
									) }
								</a>
							) }
							{ data.logoutUrl && (
								<a href={ data.logoutUrl }>
									{ __( 'Log out', 'marthrown-enquiry-hub' ) }
								</a>
							) }
						</div>
					) }
				</nav>

				<main className="meh-main">
					{ view === 'overview' && (
						<>
							<div className="meh-section">
								{ /* The page's one h1 lives in this section
								     head rather than in the site header, so the
								     visible title and the document outline are
								     the same thing. */ }
								{ /* No defaultStatus: the component's own
								     default is "all", which is the tab that
								     should be selected on open. */ }
								<EnquiryManager
									title={ __(
										'Event enquiries',
										'marthrown-enquiry-hub'
									) }
									headingLevel={ 1 }
									selection={ {
										id: route.enquiryId,
										seq: route.seq,
									} }
									onSelect={ onSelect }
									onDirtyChange={ setNoteDirty }
								/>
							</div>
							<div className="meh-section">
								<BookingsManager />
							</div>
						</>
					) }

					{ view === 'calendar' && (
						<div className="meh-section">
							<CalendarView />
						</div>
					) }
				</main>
			</div>
		</div>
	);
}
