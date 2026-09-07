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
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import EnquiryManager from './components/EnquiryManager';
import BookingsManager from './components/BookingsManager';
import CalendarView from './components/CalendarView';

const NAV = [
	{ key: 'overview', label: __( 'Overview', 'marthrown-enquiry-hub' ) },
	{ key: 'calendar', label: __( 'Calendar View', 'marthrown-enquiry-hub' ) },
];

export default function App() {
	const [ view, setView ] = useState( 'overview' );
	const data = window.mehData || {};

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
									onClick={ () => setView( item.key ) }
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
