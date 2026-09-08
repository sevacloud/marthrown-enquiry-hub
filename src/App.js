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
 * The nav does two things a plain view switch would not:
 *
 * - *Overview means the list.* An open enquiry is a state inside the Overview,
 *   not a view of its own, so clicking Overview while one is open has to return
 *   to the list rather than do nothing — which is what it appeared to do, since
 *   the button was already lit. `homeSignal` is how that reaches
 *   `EnquiryManager`: a rising number rather than a boolean, so a second click is
 *   a second request and there is no flag here to reset.
 * - *It asks before discarding a note.* Every nav click either returns to the
 *   list or unmounts the panel, and both throw away a half-written note. Only
 *   the panel knows there is one, so it reports upward and the warning is asked
 *   here, where the navigation actually happens.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import EnquiryManager from './components/EnquiryManager';
import BookingsManager from './components/BookingsManager';
import CalendarView from './components/CalendarView';
import { confirmDiscardNote } from './leaveGuard';

const NAV = [
	{ key: 'overview', label: __( 'Overview', 'marthrown-enquiry-hub' ) },
	{ key: 'calendar', label: __( 'Calendar View', 'marthrown-enquiry-hub' ) },
];

export default function App() {
	const [ view, setView ] = useState( 'overview' );
	const [ homeSignal, setHomeSignal ] = useState( 0 );
	const [ noteDirty, setNoteDirty ] = useState( false );
	const data = window.mehData || {};

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

		// Whatever note there was is gone the moment this click is honoured —
		// either the panel closes or it unmounts with the view. Clearing the flag
		// here rather than waiting to be told keeps the next click from asking
		// about a note that no longer exists: switching to the Calendar unmounts
		// the reporter along with the panel, so it never gets to say so itself.
		setNoteDirty( false );
		setView( key );

		if ( 'overview' === key ) {
			setHomeSignal( ( previous ) => previous + 1 );
		}
	};

	return (
		<div className="meh-app">
			<div className="meh-layout">
				<nav className="meh-sidenav">
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
									homeSignal={ homeSignal }
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
