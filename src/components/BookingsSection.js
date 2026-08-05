/**
 * BookingsSection — one polling booking bucket (new/upcoming/current/past).
 *
 * When showAcknowledge is set (the "new" bucket), each row has an Acknowledge
 * button that optimistically removes the row and lets the next poll place it
 * in Upcoming/Current.
 */
import { __ } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import usePolling from '../hooks/usePolling';
import { getBookings, acknowledgeBooking } from '../api';

export default function BookingsSection( { bucket, title, showAcknowledge = false } ) {
	const [ acknowledged, setAcknowledged ] = useState( [] );

	const fetcher = useCallback(
		() => getBookings( bucket, { per_page: 50 } ),
		[ bucket ]
	);
	const { data, loading, error, refetch } = usePolling( fetcher, 60000, [
		bucket,
	] );

	const onAcknowledge = async ( id ) => {
		// Optimistically hide from the New list; the next poll confirms placement.
		setAcknowledged( ( prev ) => [ ...prev, id ] );
		try {
			await acknowledgeBooking( id );
			refetch();
		} catch ( e ) {
			setAcknowledged( ( prev ) => prev.filter( ( x ) => x !== id ) );
		}
	};

	const items = ( data ? data.items : [] ).filter(
		( b ) => ! acknowledged.includes( b.id )
	);

	return (
		<section>
			<header className="meh-section__head">
				<h2>
					{ title }{ ' ' }
					<span className="meh-count">
						({ data ? data.total : 0 })
					</span>
				</h2>
				<button className="button" onClick={ refetch }>
					{ __( 'Refresh', 'marthrown-enquiry-hub' ) }
				</button>
			</header>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load bookings.', 'marthrown-enquiry-hub' ) }
				</Notice>
			) }

			<table className="widefat striped meh-table">
				<thead>
					<tr>
						<th>{ __( 'Guest', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Check-in', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Check-out', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Source', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Status', 'marthrown-enquiry-hub' ) }</th>
						{ showAcknowledge && (
							<th>{ __( 'Action', 'marthrown-enquiry-hub' ) }</th>
						) }
					</tr>
				</thead>
				<tbody>
					{ loading && ! data && (
						<tr>
							<td colSpan={ showAcknowledge ? 6 : 5 }>
								<Spinner />
							</td>
						</tr>
					) }
					{ ! loading && items.length === 0 && (
						<tr>
							<td colSpan={ showAcknowledge ? 6 : 5 }>
								{ __( 'Nothing here.', 'marthrown-enquiry-hub' ) }
							</td>
						</tr>
					) }
					{ items.map( ( b ) => (
						<tr key={ b.id }>
							<td>{ b.guest || '—' }</td>
							<td>{ b.check_in || '—' }</td>
							<td>{ b.check_out || '—' }</td>
							<td>{ b.source || '—' }</td>
							<td className="meh-booking-label">{ b.label }</td>
							{ showAcknowledge && (
								<td>
									<Button
										variant="primary"
										onClick={ () => onAcknowledge( b.id ) }
									>
										{ __( 'Acknowledge', 'marthrown-enquiry-hub' ) }
									</Button>
								</td>
							) }
						</tr>
					) ) }
				</tbody>
			</table>
		</section>
	);
}
