/**
 * App — side nav switching between the Overview (New Enquiries + Bookings
 * Manager) and the site-wide Calendar. The Calendar view mounts only when
 * selected, so its heavier data loads lazily and never blocks the overview.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import EnquiryManager from './components/EnquiryManager';
import BookingsManager from './components/BookingsManager';
import CalendarView from './components/CalendarView';

const NAV = [
	{ key: 'overview', label: __( 'Overview', 'marthrown-enquiry-hub' ) },
	{ key: 'calendar', label: __( 'Calendar', 'marthrown-enquiry-hub' ) },
];

export default function App() {
	const [ view, setView ] = useState( 'overview' );
	const isStaging = !! ( window.mehData && window.mehData.isStaging );

	return (
		<div className="meh-app">
			{ isStaging && (
				<div className="meh-staging-banner">
					{ __(
						'Marthrown Enquiry Hub — STAGING / development build. Records created here are test data.',
						'marthrown-enquiry-hub'
					) }
				</div>
			) }

			<div className="meh-layout">
				<nav className="meh-sidenav">
					<h2 className="meh-sidenav__title">
						{ __( 'Enquiry Hub', 'marthrown-enquiry-hub' ) }
					</h2>
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
					</ul>
				</nav>

				<main className="meh-main">
					{ view === 'overview' && (
						<>
							<div className="meh-section">
								<EnquiryManager
									title={ __( 'New enquiries', 'marthrown-enquiry-hub' ) }
									defaultStatus="new"
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
