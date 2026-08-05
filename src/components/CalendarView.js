/**
 * CalendarView — site-wide month overview across all calendars.
 *
 * Loaded lazily (only when this view is opened) and one month at a time, so the
 * potentially large dataset never blocks the rest of the hub. Fetches on mount
 * and on month change; no background polling (it's heavy and rarely changes
 * minute-to-minute — there's a manual Refresh instead).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Spinner, Notice, Button } from '@wordpress/components';
import { getCalendar } from '../api';

/**
 * Greedy lane assignment so overlapping bookings stack in separate rows.
 *
 * @param {Array}  bookings Bookings with start_date/end_date.
 * @param {string} ym       Month 'YYYY-MM'.
 * @param {number} days     Days in month.
 * @return {Array} lanes — each lane is an array of { booking, startDay, endDay }.
 */
function assignLanes( bookings, ym, days ) {
	const placed = bookings
		.map( ( b ) => {
			const startDay = clampDay( b.start_date, ym, days, 1 );
			const endDay = clampDay( b.end_date, ym, days, days );
			return { booking: b, startDay, endDay };
		} )
		.sort( ( a, b ) => a.startDay - b.startDay );

	const lanes = [];
	placed.forEach( ( item ) => {
		let lane = lanes.find(
			( l ) => l[ l.length - 1 ].endDay < item.startDay
		);
		if ( ! lane ) {
			lane = [];
			lanes.push( lane );
		}
		lane.push( item );
	} );
	return lanes;
}

/**
 * Clamp a booking date to a day-of-month within the given month.
 *
 * @param {string} date     ISO date.
 * @param {string} ym       'YYYY-MM'.
 * @param {number} days     Days in month.
 * @param {number} fallback Day to use if the date is outside the month.
 * @return {number}
 */
function clampDay( date, ym, days, fallback ) {
	if ( ! date ) {
		return fallback;
	}
	const [ y, m ] = ym.split( '-' );
	const monthStart = new Date( `${ ym }-01T00:00:00` );
	const d = new Date( `${ date }T00:00:00` );
	if ( d < monthStart ) {
		return 1;
	}
	if ( d.getFullYear() === Number( y ) && d.getMonth() + 1 === Number( m ) ) {
		return Math.min( days, Math.max( 1, d.getDate() ) );
	}
	// Ends in a later month.
	return days;
}

function shiftMonth( ym, delta ) {
	const [ y, m ] = ym.split( '-' ).map( Number );
	const d = new Date( y, m - 1 + delta, 1 );
	return `${ d.getFullYear() }-${ String( d.getMonth() + 1 ).padStart( 2, '0' ) }`;
}

function currentMonth() {
	const d = new Date();
	return `${ d.getFullYear() }-${ String( d.getMonth() + 1 ).padStart( 2, '0' ) }`;
}

export default function CalendarView() {
	const [ month, setMonth ] = useState( currentMonth() );
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const load = useCallback( async ( ym ) => {
		setLoading( true );
		try {
			const result = await getCalendar( ym );
			setData( result );
			setError( null );
		} catch ( e ) {
			setError( e );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		load( month );
	}, [ month, load ] );

	const days = data ? data.days : 0;
	const calendars = data && data.calendars ? data.calendars : [];
	const available = ! data || data.available !== false;

	return (
		<section className="meh-calendar">
			<div className="wpbs-plugin-sticky-header">
				<span className="wpbs-page-heading">
					{ __( 'Calendar overview', 'marthrown-enquiry-hub' ) }
				</span>
				<div className="meh-calendar-nav">
					<Button onClick={ () => setMonth( shiftMonth( month, -1 ) ) }>
						‹
					</Button>
					<strong className="meh-calendar-month">{ month }</strong>
					<Button onClick={ () => setMonth( shiftMonth( month, 1 ) ) }>
						›
					</Button>
					<Button variant="secondary" onClick={ () => load( month ) }>
						{ __( 'Refresh', 'marthrown-enquiry-hub' ) }
					</Button>
				</div>
			</div>

			{ ! available && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'WP Booking System was not detected, so the calendar is empty.',
						'marthrown-enquiry-hub'
					) }
				</Notice>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load the calendar.', 'marthrown-enquiry-hub' ) }
				</Notice>
			) }

			{ loading && (
				<div className="meh-calendar-loading">
					<Spinner />
					{ __( 'Loading calendar…', 'marthrown-enquiry-hub' ) }
				</div>
			) }

			{ ! loading && available && (
				<div className="meh-calendar-grid-wrap">
					{ calendars.length === 0 && (
						<p>{ __( 'No calendars found.', 'marthrown-enquiry-hub' ) }</p>
					) }
					{ calendars.map( ( cal ) => {
						const lanes = assignLanes( cal.bookings, month, days );
						return (
							<div key={ cal.id } className="meh-calendar-row">
								<div className="meh-calendar-rowhead">
									{ cal.name }
									<span className="meh-count">
										({ cal.bookings.length })
									</span>
								</div>
								<div className="meh-calendar-lanes">
									<div
										className="meh-calendar-daygrid"
										style={ {
											gridTemplateColumns: `repeat(${ days }, 1fr)`,
										} }
									>
										{ Array.from( { length: days } ).map(
											( _, i ) => (
												<div
													key={ i }
													className="meh-calendar-daycell"
												>
													{ i + 1 }
												</div>
											)
										) }
									</div>
									{ lanes.length === 0 && (
										<div className="meh-calendar-empty">—</div>
									) }
									{ lanes.map( ( lane, li ) => (
										<div
											key={ li }
											className="meh-calendar-lane"
											style={ {
												gridTemplateColumns: `repeat(${ days }, 1fr)`,
											} }
										>
											{ lane.map( ( item ) => (
												<a
													key={ item.booking.id }
													href={ item.booking.view_url }
													target="_blank"
													rel="noopener noreferrer"
													className={ `meh-calendar-booking wpbs-booking-color-${
														item.booking.id % 10
													} meh-cal-status-${
														item.booking.status
													}` }
													style={ {
														gridColumn: `${ item.startDay } / ${
															item.endDay + 1
														}`,
														...( item.booking.color
															? {
																	backgroundColor:
																		item.booking
																			.color,
															  }
															: {} ),
													} }
													title={ sprintf(
														/* translators: 1: id 2: guest 3: start 4: end */
														__(
															'#%1$s %2$s (%3$s → %4$s)',
															'marthrown-enquiry-hub'
														),
														item.booking.id,
														item.booking.guest || '',
														item.booking.start_date,
														item.booking.end_date
													) }
												>
													#{ item.booking.id }
													{ item.booking.guest
														? ` ${ item.booking.guest }`
														: '' }
												</a>
											) ) }
										</div>
									) ) }
								</div>
							</div>
						);
					} ) }
				</div>
			) }
		</section>
	);
}
