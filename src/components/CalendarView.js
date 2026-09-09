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

const MONTH_NAMES = [
	__( 'January', 'marthrown-enquiry-hub' ),
	__( 'February', 'marthrown-enquiry-hub' ),
	__( 'March', 'marthrown-enquiry-hub' ),
	__( 'April', 'marthrown-enquiry-hub' ),
	__( 'May', 'marthrown-enquiry-hub' ),
	__( 'June', 'marthrown-enquiry-hub' ),
	__( 'July', 'marthrown-enquiry-hub' ),
	__( 'August', 'marthrown-enquiry-hub' ),
	__( 'September', 'marthrown-enquiry-hub' ),
	__( 'October', 'marthrown-enquiry-hub' ),
	__( 'November', 'marthrown-enquiry-hub' ),
	__( 'December', 'marthrown-enquiry-hub' ),
];

/* Indexed by `Date.getDay()`, so Sunday first — the order JavaScript reports,
   not the order the columns run in. The columns are days of the month, so each
   cell looks its own weekday up here rather than stepping through a fixed
   Monday-to-Sunday week. */
const WEEKDAY_ABBR = [
	__( 'Sun', 'marthrown-enquiry-hub' ),
	__( 'Mon', 'marthrown-enquiry-hub' ),
	__( 'Tue', 'marthrown-enquiry-hub' ),
	__( 'Wed', 'marthrown-enquiry-hub' ),
	__( 'Thurs', 'marthrown-enquiry-hub' ),
	__( 'Fri', 'marthrown-enquiry-hub' ),
	__( 'Sat', 'marthrown-enquiry-hub' ),
];

/**
 * The years the year picker offers: three either side of now, plus whichever
 * year is showing, so a month reached with the arrows is never missing from the
 * list it is displayed in.
 *
 * @param {number} shown Year currently displayed.
 * @return {Array<number>} Ascending, no duplicates.
 */
function yearOptions( shown ) {
	const now = new Date().getFullYear();
	const years = new Set( [ shown ] );
	for ( let y = now - 3; y <= now + 3; y++ ) {
		years.add( y );
	}
	return [ ...years ].sort( ( a, b ) => a - b );
}

/**
 * Day of the month that is today, or null when the shown month isn't this one.
 *
 * @param {string} ym 'YYYY-MM'.
 * @return {number|null}
 */
function todayInMonth( ym ) {
	return ym === currentMonth() ? new Date().getDate() : null;
}

/**
 * Tint one day column across a whole row.
 *
 * A background gradient rather than a positioned overlay: the lanes are
 * separate grids stacked in normal flow, so nothing spans them, and a
 * background is guaranteed to paint behind the booking bars instead of over
 * them. The stops are percentages of the row, which line up with the `1fr`
 * columns because the row is exactly as wide as its day grid.
 *
 * @param {number|null} day  Day of month to highlight, or null for none.
 * @param {number}      days Days in the month.
 * @return {Object|undefined} Style object, or undefined when there is nothing
 *                            to highlight.
 */
function todayColumnStyle( day, days ) {
	if ( ! day || ! days ) {
		return undefined;
	}
	const from = ( ( day - 1 ) / days ) * 100;
	const to = ( day / days ) * 100;
	const tint = 'rgba(9, 116, 100, 0.1)';
	return {
		backgroundImage: `linear-gradient(to right, transparent ${ from }%, ${ tint } ${ from }%, ${ tint } ${ to }%, transparent ${ to }%)`,
	};
}

/**
 * The weekday/day-number ruler, one column per day of the month.
 *
 * @param {Object}      props
 * @param {number}      props.days     Days in the month.
 * @param {string}      props.month    'YYYY-MM'.
 * @param {number|null} props.today    Day of month that is today, or null.
 * @param {boolean}     props.weekdays Whether to label each column's weekday.
 * @return {JSX.Element} The ruler.
 */
function DayGrid( { days, month, today, weekdays } ) {
	const [ y, m ] = month.split( '-' ).map( Number );
	return (
		<div
			className="meh-calendar-daygrid"
			style={ { gridTemplateColumns: `repeat(${ days }, 1fr)` } }
		>
			{ Array.from( { length: days } ).map( ( _, i ) => {
				const day = i + 1;
				return (
					<div
						key={ i }
						className={ `meh-calendar-daycell${
							day === today ? ' is-today' : ''
						}` }
					>
						{ weekdays && (
							<span className="meh-calendar-dow">
								{
									WEEKDAY_ABBR[
										new Date( y, m - 1, day ).getDay()
									]
								}
							</span>
						) }
						{ day }
					</div>
				);
			} ) }
		</div>
	);
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
	const [ shownYear, shownMonth ] = month.split( '-' ).map( Number );
	const today = todayInMonth( month );
	const todayStyle = todayColumnStyle( today, days );

	return (
		<section className="meh-calendar">
			<div className="wpbs-plugin-sticky-header">
				{ /* An h1 rather than a span: the site header no longer carries
				     the page title, so on this view this is the document's only
				     heading. */ }
				<h1 className="wpbs-page-heading">
					{ __( 'Calendar overview', 'marthrown-enquiry-hub' ) }
				</h1>
				<div className="meh-calendar-nav">
					<Button
						className="meh-calendar-nav__arrow"
						label={ __(
							'Previous month',
							'marthrown-enquiry-hub'
						) }
						onClick={ () => setMonth( shiftMonth( month, -1 ) ) }
					>
						‹
					</Button>
					<span className="meh-calendar-month">
						<select
							aria-label={ __( 'Month', 'marthrown-enquiry-hub' ) }
							value={ shownMonth }
							onChange={ ( e ) =>
								setMonth(
									`${ shownYear }-${ String(
										e.target.value
									).padStart( 2, '0' ) }`
								)
							}
						>
							{ MONTH_NAMES.map( ( name, i ) => (
								<option key={ i } value={ i + 1 }>
									{ name }
								</option>
							) ) }
						</select>
						<select
							aria-label={ __( 'Year', 'marthrown-enquiry-hub' ) }
							value={ shownYear }
							onChange={ ( e ) =>
								setMonth(
									`${ e.target.value }-${ String(
										shownMonth
									).padStart( 2, '0' ) }`
								)
							}
						>
							{ yearOptions( shownYear ).map( ( y ) => (
								<option key={ y } value={ y }>
									{ y }
								</option>
							) ) }
						</select>
					</span>
					<Button
						className="meh-calendar-nav__arrow"
						label={ __( 'Next month', 'marthrown-enquiry-hub' ) }
						onClick={ () => setMonth( shiftMonth( month, 1 ) ) }
					>
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
					{ calendars.length > 0 && (
						<div className="meh-calendar-row meh-calendar-headrow">
							<div className="meh-calendar-rowhead" />
							<div
								className="meh-calendar-lanes"
								style={ todayStyle }
							>
								<DayGrid
									days={ days }
									month={ month }
									today={ today }
									weekdays
								/>
							</div>
						</div>
					) }
					{ calendars.map( ( cal, calIndex ) => {
						const lanes = assignLanes( cal.bookings, month, days );
						const placeholders = cal.placeholders || [];
						// The same ten WPBS booking colours the Bookings Manager
						// uses, taken the same way: by the calendar's position in
						// the calendar list. `GET /calendar` and `GET /calendars`
						// both build that list from `SourceWpbs::calendar_names()`
						// in one order, so a calendar keeps its colour across the
						// two views. `cal.color` is not used: it is the calendar's
						// *default legend* colour, identical for every calendar
						// WPBS created with its stock legend, which is what left
						// every bar here the same shade.
						const colorClass = `wpbs-booking-color-${ calIndex % 10 }`;
						return (
							<div key={ cal.id } className="meh-calendar-row">
								<div className="meh-calendar-rowhead">
									{ cal.name }
									<span className="meh-count">
										({ cal.bookings.length })
									</span>
								</div>
								<div
									className="meh-calendar-lanes"
									style={ todayStyle }
								>
									<DayGrid
										days={ days }
										month={ month }
										today={ today }
										weekdays={ false }
									/>
									{ lanes.length === 0 &&
										placeholders.length === 0 && (
											<div className="meh-calendar-empty">
												—
											</div>
										) }
									{ placeholders.map( ( p, pi ) => (
										<div
											key={ `ph-${ pi }` }
											className="meh-calendar-lane"
											style={ {
												gridTemplateColumns: `repeat(${ days }, 1fr)`,
											} }
										>
											<span
												className={ `meh-calendar-booking meh-calendar-placeholder ${ colorClass }` }
												style={ {
													gridColumn: `${ p.start_day } / ${
														p.end_day + 1
													}`,
												} }
												title={ `${ __(
													'Placeholder',
													'marthrown-enquiry-hub'
												) }: ${ p.title }${
													p.note ? ' — ' + p.note : ''
												}` }
											>
												▦ { p.title }
												{ p.note ? ` — ${ p.note }` : '' }
											</span>
										</div>
									) ) }
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
													className={ `meh-calendar-booking meh-cal-status-${
														item.booking.status
													} ${ colorClass }` }
													style={ {
														gridColumn: `${ item.startDay } / ${
															item.endDay + 1
														}`,
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
