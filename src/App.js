/**
 * App — New Enquiries pinned at the top, then the WPBS-style Bookings Manager.
 */
import { __ } from '@wordpress/i18n';
import EnquiryManager from './components/EnquiryManager';
import BookingsManager from './components/BookingsManager';

export default function App() {
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

			<h1 className="meh-app__title">
				{ __( 'Enquiry Hub', 'marthrown-enquiry-hub' ) }
			</h1>

			{ /* New enquiries first — the daily triage list. */ }
			<div className="meh-section">
				<EnquiryManager
					title={ __( 'New enquiries', 'marthrown-enquiry-hub' ) }
					defaultStatus="new"
				/>
			</div>

			{ /* Bookings Manager — mirrors the WP Booking System list view. */ }
			<div className="meh-section">
				<BookingsManager />
			</div>
		</div>
	);
}
