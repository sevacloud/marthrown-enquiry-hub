/**
 * App — top-level layout with five polling sections.
 */
import { __ } from '@wordpress/i18n';
import EnquiryManager from './components/EnquiryManager';
import BookingsSection from './components/BookingsSection';

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

			<div className="meh-section">
				<EnquiryManager />
			</div>

			<div className="meh-section">
				<BookingsSection
					bucket="new"
					title={ __( 'New bookings', 'marthrown-enquiry-hub' ) }
					showAcknowledge
				/>
			</div>

			<div className="meh-section">
				<BookingsSection
					bucket="upcoming"
					title={ __( 'Upcoming bookings', 'marthrown-enquiry-hub' ) }
				/>
			</div>

			<div className="meh-section">
				<BookingsSection
					bucket="current"
					title={ __( 'Current bookings', 'marthrown-enquiry-hub' ) }
				/>
			</div>

			<div className="meh-section">
				<BookingsSection
					bucket="past"
					title={ __( 'Past bookings (last 30 days)', 'marthrown-enquiry-hub' ) }
				/>
			</div>
		</div>
	);
}
