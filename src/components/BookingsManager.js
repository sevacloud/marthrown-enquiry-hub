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
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Spinner, Notice, CheckboxControl } from '@wordpress/components';
import usePolling from '../hooks/usePolling';
import {
	getBookings,
	getCalendars,
	convertBooking,
	bookingsExportUrl,
} from '../api';

/**
 * Per-row "Convert" control: creates a real booking on the chosen target
 * calendar (copying the enquiry's dates + fields and blocking availability),
 * then offers to open the new booking in WP Booking System.
 */
function ConvertControl( { booking, targets, onConverted } ) {
	const [ busy, setBusy ] = useState( false );

	if ( ! targets.length ) {
		return null;
	}

	const onSelect = async ( e ) => {
		const targetId = e.target.value;
		e.target.value = '';
		if ( ! targetId || busy ) {
			return;
		}
		setBusy( true );
		try {
			const result = await convertBooking( booking.id, Number( targetId ) );
			onConverted( result );
		} catch ( err ) {
			onConverted( null, err );
		} finally {
			setBusy( false );
		}
	};

	return (
		<select
			className="meh-convert-select"
			defaultValue=""
			disabled={ busy }
			onChange={ onSelect }
		>
			<option value="">
				{ busy
					? __( 'Converting…', 'marthrown-enquiry-hub' )
					: __( 'Convert to…', 'marthrown-enquiry-hub' ) }
			</option>
			{ targets.map( ( t ) => (
				<option key={ t.id } value={ t.id }>
					{ t.name }
				</option>
			) ) }
		</select>
	);
}

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
	const [ calendars, setCalendars ] = useState( [] );
	const [ newBookingCal, setNewBookingCal ] = useState( '' );
	const [ convertMsg, setConvertMsg ] = useState( null );

	// Load calendars once for the new-booking + convert pickers.
	useEffect( () => {
		getCalendars()
			.then( ( r ) => setCalendars( r.calendars || [] ) )
			.catch( () => setCalendars( [] ) );
	}, [] );

	// Convert targets = every calendar that isn't the Event Enquiry one.
	const convertTargets = calendars.filter( ( c ) => ! c.is_enquiry );

	const onConverted = ( result, err ) => {
		if ( err || ! result ) {
			setConvertMsg( {
				type: 'error',
				text: __(
					'Could not convert the enquiry.',
					'marthrown-enquiry-hub'
				),
			} );
			return;
		}
		setConvertMsg( {
			type: 'success',
			text: __(
				'Booking created from enquiry.',
				'marthrown-enquiry-hub'
			),
			url: result.edit_url,
		} );
		refetch();
	};

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
				<div className="meh-header-actions">
					{ calendars.length > 0 && (
						<span className="meh-newbooking">
							<select
								value={ newBookingCal }
								onChange={ ( e ) =>
									setNewBookingCal( e.target.value )
								}
							>
								<option value="">
									{ __(
										'Calendar…',
										'marthrown-enquiry-hub'
									) }
								</option>
								{ calendars.map( ( c ) => (
									<option key={ c.id } value={ c.id }>
										{ c.name }
									</option>
								) ) }
							</select>
							<a
								className="button button-primary"
								href={
									(
										calendars.find(
											( c ) =>
												String( c.id ) ===
												newBookingCal
										) || {}
									).add_url || '#'
								}
								target="_blank"
								rel="noopener noreferrer"
								aria-disabled={ ! newBookingCal }
								onClick={ ( e ) => {
									if ( ! newBookingCal ) {
										e.preventDefault();
									}
								} }
							>
								{ __(
									'Add booking',
									'marthrown-enquiry-hub'
								) }
							</a>
						</span>
					) }
					<button className="button" onClick={ refetch }>
						{ __( 'Refresh', 'marthrown-enquiry-hub' ) }
					</button>
				</div>
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

			{ convertMsg && (
				<Notice
					status={ convertMsg.type }
					onRemove={ () => setConvertMsg( null ) }
				>
					{ convertMsg.text }{ ' ' }
					{ convertMsg.url && (
						<a
							href={ convertMsg.url }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Open booking', 'marthrown-enquiry-hub' ) }
						</a>
					) }
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
				<a
					className="button meh-export-button"
					href={ bookingsExportUrl( {
						status,
						s: search,
						from,
						to,
						hide_past: hidePast ? 1 : 0,
					} ) }
				>
					{ __( 'Export CSV', 'marthrown-enquiry-hub' ) }
				</a>
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
							<td className="meh-row-actions">
								<a
									className="button"
									href={ b.view_url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __( 'View', 'marthrown-enquiry-hub' ) }
								</a>
								{ b.is_enquiry && (
									<ConvertControl
										booking={ b }
										targets={ convertTargets }
										onConverted={ onConverted }
									/>
								) }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</section>
	);
}
