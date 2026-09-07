/**
 * BookingsManager — recreates the WP Booking System "Booking Manager" list
 * view: one tab strip (period and status together) with counts, a search +
 * date range + "hide past" toolbar with an Export CSV link, and the bookings
 * table — laid out through `HubTable`, the same list shape `EnquiryManager`
 * uses. Data is read live from WPBS via the REST API.
 *
 * The calendar view and per-booking editing remain in the WP Booking System
 * plugin; the "View" link opens the booking there.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Notice, CheckboxControl } from '@wordpress/components';
import usePolling from '../hooks/usePolling';
import { getBookings, getCalendars, bookingsExportUrl } from '../api';
import HubTable from './HubTable';

/**
 * One tab strip, not two. WPBS's native statuses and the date-based periods
 * were rendered as separate rows reading the same `counts` object — a status
 * count and a period count side by side, each labelled "(n)" its own way. That
 * duplicated the same information rather than offering two independent
 * filters, so they are one list here: whichever tab is active sets `status`
 * when it is a WPBS status and `period` otherwise, and the two still combine
 * server-side exactly as `SourceWpbs::get_bookings()` already allows.
 */
const TABS = [
	{ key: 'all', label: __( 'All', 'marthrown-enquiry-hub' ), filter: 'period' },
	{
		key: 'current',
		label: __( 'Current', 'marthrown-enquiry-hub' ),
		filter: 'period',
	},
	{
		key: 'upcoming',
		label: __( 'Upcoming', 'marthrown-enquiry-hub' ),
		filter: 'period',
	},
	{
		key: 'past',
		label: __( 'Past', 'marthrown-enquiry-hub' ),
		filter: 'period',
	},
	{
		key: 'pending',
		label: __( 'Pending', 'marthrown-enquiry-hub' ),
		filter: 'status',
	},
	{
		key: 'accepted',
		label: __( 'Accepted', 'marthrown-enquiry-hub' ),
		filter: 'status',
	},
	{
		key: 'trash',
		label: __( 'Trash', 'marthrown-enquiry-hub' ),
		filter: 'status',
	},
];

/**
 * The list table's columns, in display order.
 */
const COLUMNS = [
	{ key: 'id', label: __( 'ID', 'marthrown-enquiry-hub' ) },
	{ key: 'calendar', label: __( 'Calendar', 'marthrown-enquiry-hub' ) },
	{ key: 'guest', label: __( 'Guest', 'marthrown-enquiry-hub' ) },
	{ key: 'start_date', label: __( 'Start date', 'marthrown-enquiry-hub' ) },
	{ key: 'end_date', label: __( 'End date', 'marthrown-enquiry-hub' ) },
	{
		key: 'stay_length',
		label: __( 'Stay length', 'marthrown-enquiry-hub' ),
	},
	{ key: 'status', label: __( 'Status', 'marthrown-enquiry-hub' ) },
	{ key: 'actions', label: '' },
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
	// Two server-side filters behind one tab strip: the active tab is either a
	// status or a period, and the other stays at "all" until its own tab is
	// clicked. `activeTab` is derived from the pair rather than stored
	// separately, so the two can never disagree about which button is lit.
	const [ status, setStatus ] = useState( 'all' );
	const [ period, setPeriod ] = useState( 'all' );
	const activeTab = 'all' !== status ? status : period;

	const selectTab = ( tab ) => {
		const chosen = TABS.find( ( t ) => t.key === tab );

		if ( chosen && 'status' === chosen.filter ) {
			setStatus( tab );
			setPeriod( 'all' );
		} else {
			setStatus( 'all' );
			setPeriod( tab );
		}
	};

	const [ search, setSearch ] = useState( '' );
	const [ from, setFrom ] = useState( '' );
	const [ to, setTo ] = useState( '' );
	const [ hidePast, setHidePast ] = useState( false );
	const [ calendars, setCalendars ] = useState( [] );
	const [ newBookingCal, setNewBookingCal ] = useState( '' );

	// Load calendars once for the new-booking picker.
	useEffect( () => {
		getCalendars()
			.then( ( r ) => {
				const list = r.calendars || [];
				setCalendars( list );
				// Default the new-booking picker to the first calendar so the
				// button always has a target to open.
				if ( list.length ) {
					setNewBookingCal( String( list[ 0 ].id ) );
				}
			} )
			.catch( () => setCalendars( [] ) );
	}, [] );

	const openAddBooking = () => {
		const target = calendars.find(
			( c ) => String( c.id ) === newBookingCal
		);
		if ( target && target.add_url ) {
			window.open( target.add_url, '_blank', 'noopener' );
		}
	};

	const fetcher = useCallback(
		() =>
			getBookings( {
				status,
				period,
				s: search,
				from,
				to,
				hide_past: hidePast ? 1 : 0,
				per_page: 100,
			} ),
		[ status, period, search, from, to, hidePast ]
	);

	const { data, loading, error, refetch } = usePolling( fetcher, 60000, [
		status,
		period,
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
							<button
								type="button"
								className="button"
								disabled={ ! newBookingCal }
								onClick={ openAddBooking }
							>
								{ __(
									'Add booking',
									'marthrown-enquiry-hub'
								) }
							</button>
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

			{ /* The ID column's colour groups by calendar; this is the key to
			     that grouping, using the same calendar list already fetched for
			     the new-booking picker above. */ }
			{ calendars.length > 0 && (
				<ul className="wpbs-calendar-legend">
					{ calendars.map( ( c ) => (
						<li key={ c.id }>
							<span
								className="wpbs-calendar-legend__swatch"
								style={
									c.color
										? { backgroundColor: c.color }
										: undefined
								}
							></span>
							{ c.name }
						</li>
					) ) }
				</ul>
			) }

			<HubTable
				tabs={ TABS.map( ( tab ) => ( {
					...tab,
					count: counts[ tab.key ] || 0,
				} ) ) }
				activeTab={ activeTab }
				onTabChange={ selectTab }
				toolbar={
					<>
						<input
							type="search"
							placeholder={ __(
								'Search bookings',
								'marthrown-enquiry-hub'
							) }
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
							label={ __(
								'Hide past bookings',
								'marthrown-enquiry-hub'
							) }
							checked={ hidePast }
							onChange={ setHidePast }
							__nextHasNoMarginBottom
						/>
					</>
				}
				exportUrl={ bookingsExportUrl( {
					status,
					period,
					s: search,
					from,
					to,
					hide_past: hidePast ? 1 : 0,
				} ) }
				columns={ COLUMNS }
				rows={ items }
				rowKey={ ( row ) => row.id }
				renderCell={ renderBookingCell }
				loading={ loading && ! data }
				error={ error }
				errorText={ __(
					'Could not load bookings.',
					'marthrown-enquiry-hub'
				) }
				emptyText={ __(
					'No bookings found.',
					'marthrown-enquiry-hub'
				) }
			/>
		</section>
	);
}

/**
 * One cell of the bookings list, by column key.
 *
 * @param {Object} row    Listed booking.
 * @param {Object} column `{ key, label }` from COLUMNS.
 * @return {*} Cell content.
 */
function renderBookingCell( row, column ) {
	switch ( column.key ) {
		case 'id':
			// Coloured by calendar, not by id: `row.color` is the calendar's own
			// WPBS legend colour (`SourceWpbs::calendar_colors()`), the same value
			// `GET /calendars` exposes for the legend above the table. An id-based
			// hash colour (`id % 10`) carried no information — a booking's colour
			// changed depending on which id it happened to get, unrelated to
			// which calendar it was on. Grouping by calendar is what makes the
			// colour mean something at a glance.
			return (
				<span
					className="wpbs-list-table-id"
					style={
						row.color ? { backgroundColor: row.color } : undefined
					}
				>
					#{ row.id }
				</span>
			);

		case 'status':
			return <StatusPill status={ row.status } />;

		case 'actions':
			return (
				<span className="meh-row-actions">
					<a
						className="button"
						href={ row.view_url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'View', 'marthrown-enquiry-hub' ) }
					</a>
				</span>
			);

		default:
			return row[ column.key ] || '—';
	}
}
