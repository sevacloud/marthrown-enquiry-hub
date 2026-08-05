/**
 * BookingsManager — recreates the WP Booking System "Booking Manager" list
 * view: status tabs (All/Pending/Accepted/Trash) with counts, a search + date
 * range + "hide past" toolbar, and the bookings table. Data is read live from
 * WPBS via the REST API.
 *
 * The calendar view and per-booking editing remain in the WP Booking System
 * plugin; the "View" link opens the booking there.
 */
import { __ } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import { Spinner, Notice, CheckboxControl } from '@wordpress/components';
import usePolling from '../hooks/usePolling';
import { getBookings } from '../api';

const TABS = [
	{ key: 'all', label: __( 'All', 'marthrown-enquiry-hub' ) },
	{ key: 'pending', label: __( 'Pending', 'marthrown-enquiry-hub' ) },
	{ key: 'accepted', label: __( 'Accepted', 'marthrown-enquiry-hub' ) },
	{ key: 'trash', label: __( 'Trash', 'marthrown-enquiry-hub' ) },
];

function StatusPill( { status } ) {
	const icons = {
		pending: 'marker',
		accepted: 'yes-alt',
		trash: 'dismiss',
	};
	return (
		<span className={ `wpbs-bm-status wpbs-bm-status-${ status }` }>
			<span className={ `dashicons dashicons-${ icons[ status ] || 'marker' }` }></span>
			{ status }
		</span>
	);
}

export default function BookingsManager() {
	const [ status, setStatus ] = useState( 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ from, setFrom ] = useState( '' );
	const [ to, setTo ] = useState( '' );
	const [ hidePast, setHidePast ] = useState( false );

	const fetcher = useCallback(
		() =>
			getBookings( {
				status,
				s: search,
				from,
				to,
				hide_past: hidePast ? 1 : 0,
				per_page: 100,
			} ),
		[ status, search, from, to, hidePast ]
	);

	const { data, loading, error, refetch } = usePolling( fetcher, 60000, [
		status,
		search,
		from,
		to,
		hidePast,
	] );

	const items = data && data.items ? data.items : [];
	const counts = data && data.counts ? data.counts : {};
	const available = ! data || data.available !== false;

	return (
		<section className="wpbs-bm">
			<div className="wpbs-plugin-sticky-header">
				<span className="wpbs-page-heading">
					{ __( 'Bookings Manager', 'marthrown-enquiry-hub' ) }
				</span>
				<button className="button" onClick={ refetch }>
					{ __( 'Refresh', 'marthrown-enquiry-hub' ) }
				</button>
			</div>

			{ ! available && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'WP Booking System was not detected on this site, so no bookings can be shown.',
						'marthrown-enquiry-hub'
					) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load bookings.', 'marthrown-enquiry-hub' ) }
				</Notice>
			) }

			<ul className="subsubsub wpbs-bm-tabs">
				{ TABS.map( ( tab, i ) => (
					<li key={ tab.key }>
						<a
							href="#"
							className={ status === tab.key ? 'current' : '' }
							onClick={ ( e ) => {
								e.preventDefault();
								setStatus( tab.key );
							} }
						>
							{ tab.label }{ ' ' }
							<span className="count">
								({ counts[ tab.key ] || 0 })
							</span>
						</a>
						{ i < TABS.length - 1 && ' | ' }
					</li>
				) ) }
			</ul>

			<div className="wpbs-bm-toolbar">
				<input
					type="search"
					placeholder={ __( 'Search bookings', 'marthrown-enquiry-hub' ) }
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
				/>
				<label className="meh-date-label">
					{ __( 'From', 'marthrown-enquiry-hub' ) }
					<input
						type="date"
						value={ from }
						onChange={ ( e ) => setFrom( e.target.value ) }
					/>
				</label>
				<label className="meh-date-label">
					{ __( 'To', 'marthrown-enquiry-hub' ) }
					<input
						type="date"
						value={ to }
						onChange={ ( e ) => setTo( e.target.value ) }
					/>
				</label>
				<CheckboxControl
					label={ __( 'Hide past bookings', 'marthrown-enquiry-hub' ) }
					checked={ hidePast }
					onChange={ setHidePast }
					__nextHasNoMarginBottom
				/>
			</div>

			<table className="widefat striped meh-table wpbs-bm-table">
				<thead>
					<tr>
						<th>{ __( 'ID', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Calendar', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Guest', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Start date', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'End date', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Stay length', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Status', 'marthrown-enquiry-hub' ) }</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					{ loading && ! data && (
						<tr>
							<td colSpan="8">
								<Spinner />
							</td>
						</tr>
					) }
					{ ! loading && items.length === 0 && (
						<tr>
							<td colSpan="8">
								{ __( 'No bookings found.', 'marthrown-enquiry-hub' ) }
							</td>
						</tr>
					) }
					{ items.map( ( b ) => (
						<tr key={ b.id }>
							<td>
								<span
									className={ `wpbs-list-table-id wpbs-booking-color-${ b.id % 10 }` }
								>
									#{ b.id }
								</span>
							</td>
							<td>{ b.calendar }</td>
							<td>{ b.guest || '—' }</td>
							<td>{ b.start_date || '—' }</td>
							<td>{ b.end_date || '—' }</td>
							<td>{ b.stay_length || '—' }</td>
							<td>
								<StatusPill status={ b.status } />
							</td>
							<td>
								<a
									className="button"
									href={ b.view_url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __( 'View', 'marthrown-enquiry-hub' ) }
								</a>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</section>
	);
}
