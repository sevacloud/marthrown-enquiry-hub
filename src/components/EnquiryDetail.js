/**
 * EnquiryDetail — the whole of one enquiry, and everything that can be done to
 * it.
 *
 * The single-enquiry route returns the panel's entire contents in one response
 * (Requirement 13.2): the stored fields, the candidate date ranges, both multi-select
 * value sets, the message, the notes, the history, the `crm_sync_state` in
 * whichever state it holds (Requirement 5.3), the linked booking identifier,
 * the FluentCRM contact URL, the same-email siblings and the permitted
 * transitions. Nothing here derives any of that a second time.
 *
 * Two rules shape the controls:
 *
 * - *Action buttons come solely from `allowed_transitions`* (Requirement 13.6).
 *   The array the API returns is the whole of the offer: `Lifecycle` decides
 *   what is legal, so a local status list would be a second opinion that could
 *   drift, and would offer an illegal transition the moment the transition table
 *   changed. `STATUS_LABELS` translates a status the payload named; it never
 *   adds one. A `closed` enquiry lists none, so it is offered none, through the
 *   same code path as every other status rather than a special case.
 * - *A `closed` enquiry gets no edit control at all* (Requirements 9.1, 19.12).
 *   The edit route answers 409 for a closed enquiry, and that 409 is a backstop
 *   against a stale view — a panel opened before the auto-close job ran — not
 *   the normal way a user finds out. The same reasoning removes the note,
 *   convert and CRM-retry controls, each of which the API refuses with 409 while
 *   the enquiry holds `closed`. Re-raising stays: creating a fresh enquiry from
 *   a closed one is the supported way forward (Requirement 9.3), and reading
 *   stays whole (Requirement 9.2).
 *
 * The panel reads by `id` on open and again after any write, so the displayed
 * values, the history and the offered transitions all come from the same
 * response rather than from a guess about what the write changed. An `enquiry`
 * the caller already holds — the list row it was opened from, say — is accepted
 * as something to render while that first read is in flight; it is never a
 * substitute for it, because a list row carries none of what the single-enquiry
 * route adds.
 *
 * A transition asks for a comment before it is applied. A status change is the
 * one write here that is irreversible — the table is forward-only — and "why"
 * is the part a trail of `new → contacted → lost` never captures on its own, so
 * the modal is where it gets captured, at the moment the person who knows is
 * still looking at the enquiry. The comment is optional: the transition is what
 * the control exists to apply, and refusing a legal move for want of a note
 * would put record-keeping ahead of the lifecycle.
 *
 * An unfinished note is reported upward through `onDirtyChange`, because the
 * control that would discard it is not in this panel — it is the list's own
 * close and the side nav's view switch. The panel knows the note is unsaved and
 * nothing else can, so it says so and leaves the warning to whoever owns the
 * navigation.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import {
	getEnquiry,
	setEnquiryStatus,
	addEnquiryNote,
	duplicateEnquiry,
	convertEnquiry,
	retryEnquiryCrm,
	getCalendars,
} from '../api';
import EnquiryForm from './EnquiryForm';
import StatusBadge from './StatusBadge';

/**
 * Readable labels for the six statuses.
 *
 * Display only. A transition is offered because `allowed_transitions` named it,
 * not because this map holds it, and a status the map has not caught up with is
 * still offered — under its own name (Requirement 13.6).
 */
const STATUS_LABELS = {
	new: __( 'New', 'marthrown-enquiry-hub' ),
	contacted: __( 'Contacted', 'marthrown-enquiry-hub' ),
	quoted: __( 'Quoted', 'marthrown-enquiry-hub' ),
	converted: __( 'Converted', 'marthrown-enquiry-hub' ),
	lost: __( 'Lost', 'marthrown-enquiry-hub' ),
	closed: __( 'Closed', 'marthrown-enquiry-hub' ),
};

/**
 * The status whose immutability the panel honours before the API has to.
 */
const CLOSED = 'closed';

/**
 * The booking-dates choice standing for "not one of the candidate ranges".
 */
const CUSTOM_RANGE = 'custom';

/**
 * A booking range with neither bound set, which is what the custom choice starts
 * from before anything is typed into it.
 */
const EMPTY_RANGE = { start: '', end: '' };

/**
 * The Site Exclusivity term that names no calendar.
 *
 * "None" is the absence of exclusivity rather than a part of the site, so an
 * enquiry holding it constrains the calendar choice to nothing and every
 * calendar stays offered. Matched on the normalised term, so it catches "none"
 * however it was cased or spaced.
 */
const EXCLUSIVITY_NONE = 'none';

/**
 * Labels for the stored values the summary lists.
 */
const LABELS = {
	email: __( 'Email', 'marthrown-enquiry-hub' ),
	phone: __( 'Phone', 'marthrown-enquiry-hub' ),
	total_guests: __( 'Total guests', 'marthrown-enquiry-hub' ),
	date_ranges: __( 'Candidate dates', 'marthrown-enquiry-hub' ),
	event_type: __( 'Event type', 'marthrown-enquiry-hub' ),
	site_exclusivity: __( 'Site exclusivity', 'marthrown-enquiry-hub' ),
	source: __( 'Source', 'marthrown-enquiry-hub' ),
	created_at: __( 'Received', 'marthrown-enquiry-hub' ),
	updated_at: __( 'Last updated', 'marthrown-enquiry-hub' ),
	status_changed_at: __( 'Status changed', 'marthrown-enquiry-hub' ),
};

export default function EnquiryDetail( {
	id = 0,
	enquiry: selected = null,
	onClose,
	onChanged,
	onDirtyChange,
} ) {
	const enquiryId =
		Number( id ) || ( selected && Number( selected.id ) ) || 0;

	// What a read returned, used only while it is the read of the enquiry now
	// being shown: a caller switching to another row falls back to the row it
	// handed over, without a stale response briefly standing in for it.
	const [ fetched, setFetched ] = useState( null );
	const enquiry =
		fetched && Number( fetched.id ) === enquiryId ? fetched : selected;

	const [ loading, setLoading ] = useState( ! selected && !! enquiryId );
	const [ loadFailed, setLoadFailed ] = useState( false );
	const [ busy, setBusy ] = useState( '' );
	const [ failure, setFailure ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ editing, setEditing ] = useState( false );
	const [ note, setNote ] = useState( '' );
	const [ calendars, setCalendars ] = useState( [] );
	const [ calendar, setCalendar ] = useState( '' );

	// Which booking range the convert control is on, and the range being typed
	// when that choice is the custom one. `picked` empty means nothing has been
	// chosen yet, which is not the same as having chosen the ideal range: the
	// default follows whatever the enquiry's ideal range currently is, including
	// after an edit changes it, and only an actual choice overrides it.
	const [ picked, setPicked ] = useState( '' );
	const [ custom, setCustom ] = useState( EMPTY_RANGE );

	// The transition awaiting its comment, and the comment being written. The
	// target status doubles as the modal's open flag: there is no state in which
	// the modal is open without one, so a separate boolean could only ever
	// contradict it.
	const [ pending, setPending ] = useState( '' );
	const [ comment, setComment ] = useState( '' );

	const read = useCallback( async () => {
		const fresh = await getEnquiry( enquiryId );

		if ( fresh && 'object' === typeof fresh ) {
			setFetched( fresh );
		}

		return fresh;
	}, [ enquiryId ] );

	// Always read on open, whether or not the caller handed a row over. A list
	// row is not a substitute for the single-enquiry representation: `notes`,
	// `history`, `siblings`, `crm_url` and the `allowed_transitions` the action
	// buttons come from are all added by `present_single()` alone, so rendering
	// from the row and skipping the read showed an enquiry with no notes, no
	// history and no transitions until some write happened to read it back. The
	// handed-over row is what the panel renders *while* this read is in flight,
	// not instead of it.
	useEffect( () => {
		if ( ! enquiryId ) {
			return undefined;
		}

		let live = true;
		setLoading( true );
		// A failure warning belongs to the read that raised it, not to the panel:
		// leaving it up would report the previous enquiry's failed read against
		// this one.
		setLoadFailed( false );

		Promise.resolve()
			.then( () => getEnquiry( enquiryId ) )
			.then( ( fresh ) => {
				if ( live && fresh && 'object' === typeof fresh ) {
					setFetched( fresh );
				}
			} )
			.catch( () => {
				if ( live ) {
					setLoadFailed( true );
				}
			} )
			.finally( () => {
				if ( live ) {
					setLoading( false );
				}
			} );

		return () => {
			live = false;
		};
	}, [ enquiryId ] );

	// Whether the note box holds something not yet added. Reported on every
	// change, and reported false on unmount: a panel that has gone is holding
	// nothing, and leaving the flag set would make the next navigation warn about
	// a note that no longer exists.
	useEffect( () => {
		if ( ! onDirtyChange ) {
			return undefined;
		}

		onDirtyChange( '' !== note.trim() );

		return () => onDirtyChange( false );
	}, [ note, onDirtyChange ] );

	const status = enquiry ? String( enquiry.status || '' ) : '';
	const isClosed = CLOSED === status;
	const transitions = list( enquiry && enquiry.allowed_transitions );
	const ranges = list( enquiry && enquiry.date_ranges );
	const bookingId = enquiry ? Number( enquiry.booking_id ) || 0 : 0;
	// Candidate ranges are not a condition of converting. They are where the
	// booking dates come from by default, but the range can be typed instead, and
	// an enquiry that reached agreement without naming dates on the form is
	// exactly the one a custom range exists for.
	const convertible = !! enquiry && ! isClosed && ! bookingId;

	// Which booking range is in force: the choice made, or the ideal candidate
	// range until one is. A choice naming a range the enquiry no longer holds —
	// an edit dropped it — falls back to the custom range, so the control shows
	// blank bounds and refuses to convert rather than booking a range that has
	// gone.
	const choice =
		'' !== picked ? picked : ranges.length > 0 ? '0' : CUSTOM_RANGE;
	const booking =
		CUSTOM_RANGE === choice ? custom : ranges[ Number( choice ) ] || custom;
	const bookingStart = text( booking && booking.start );
	const bookingEnd = text( booking && booking.end );
	const rangeFault = bookingFault( bookingStart, bookingEnd );

	// The calendars this enquiry may be booked onto: the one its Site Exclusivity
	// names, and no other. Reduced to a string so the effect that keeps the
	// selection valid depends on the identifiers rather than on a fresh array.
	const bookable = bookableCalendars(
		calendars,
		list( enquiry && enquiry.site_exclusivity )
	);
	const bookableIds = bookable.map( ( item ) => String( item.id ) ).join( ',' );

	// The convert control needs the calendars, and nothing else here does, so
	// they are read only when it is offered.
	useEffect( () => {
		if ( ! convertible ) {
			return undefined;
		}

		let live = true;

		Promise.resolve()
			.then( () => getCalendars() )
			.then( ( response ) => {
				if ( ! live ) {
					return;
				}

				// Selecting one is left to the effect below: which calendar this
				// enquiry may use depends on its Site Exclusivity, not on the
				// order WPBS happens to return them in.
				setCalendars( list( response && response.calendars ) );
			} )
			.catch( () => {
				if ( live ) {
					setCalendars( [] );
				}
			} );

		return () => {
			live = false;
		};
	}, [ convertible ] );

	// Hold the selection on a calendar the enquiry's Site Exclusivity allows.
	// Nothing else selects one, so this is also what preselects it: with a single
	// allowed calendar — the ordinary case — the choice is made and the control
	// has nothing to ask.
	useEffect( () => {
		const ids = '' === bookableIds ? [] : bookableIds.split( ',' );

		setCalendar( ( current ) =>
			ids.includes( current ) ? current : ids[ 0 ] || ''
		);
	}, [ bookableIds ] );

	// A booking choice belongs to the enquiry it was made on. Moving the panel to
	// another enquiry starts from that enquiry's own ideal range rather than
	// carrying the previous one's dates into a conversion.
	useEffect( () => {
		setPicked( '' );
		setCustom( EMPTY_RANGE );
	}, [ enquiryId ] );

	/**
	 * Run one write, then read the enquiry back.
	 *
	 * The read is what keeps the panel honest: a transition changes the offered
	 * transitions, a note changes the history, a conversion changes the status
	 * and the booking. Reading the representation back is the only way all of
	 * those agree with the server rather than with an assumption made here. A
	 * read that fails leaves the write reported as it happened and the display
	 * as it was — the write did land, and inventing a failure for it would be
	 * worse than a moment of staleness.
	 *
	 * @param {string}   key     Which control is running, for its own spinner.
	 * @param {Function} action  The write to run.
	 * @param {Function} [after] Notice text built from the write's result.
	 * @return {Function} Click handler.
	 */
	const run = ( key, action, after ) => async () => {
		if ( busy ) {
			return;
		}

		setBusy( key );
		setFailure( '' );
		setNotice( '' );

		try {
			const result = await action();

			if ( after ) {
				setNotice( after( result ) );
			}

			try {
				await read();
			} catch ( readError ) {
				setLoadFailed( true );
			}

			if ( onChanged ) {
				onChanged( result );
			}
		} catch ( error ) {
			setFailure( failureText( error ) );
		} finally {
			setBusy( '' );
		}
	};

	/**
	 * Apply the transition the modal was opened for, with whatever comment was
	 * written for it.
	 *
	 * The modal closes first, before the write starts: the panel's own spinner and
	 * its notices are where the outcome is reported, so keeping the dialog up over
	 * them would hide the answer behind the question. A failure therefore lands on
	 * a closed modal, which is right — the transition was refused, and reopening
	 * the comment box would invite the user to write the comment again for a move
	 * that is not going to happen.
	 */
	const cancelPending = () => {
		setPending( '' );
		setComment( '' );
	};

	const applyPending = async () => {
		const target = pending;
		const written = comment;

		cancelPending();

		await run(
			`status:${ target }`,
			() => setEnquiryStatus( enquiryId, target, written ),
			( result ) => statusNotice( target, result )
		)();
	};

	/**
	 * Take the booking dates from one candidate range, or start editing them.
	 *
	 * Choosing the custom entry carries the dates already showing into the
	 * editable pair rather than blanking them: a custom range is usually a
	 * candidate range with a day moved, so it starts from the one on screen.
	 *
	 * @param {string} value Candidate range index, or `CUSTOM_RANGE`.
	 */
	const chooseRange = ( value ) => {
		if ( CUSTOM_RANGE === value ) {
			setCustom( { start: bookingStart, end: bookingEnd } );
		}

		setPicked( value );
	};

	/**
	 * Edit one bound of the booking range.
	 *
	 * The edited pair is matched back against the candidate ranges, so typing a
	 * candidate range's dates by hand leaves the control naming that candidate
	 * rather than reporting a custom range identical to one.
	 *
	 * @param {string} bound `start` or `end`.
	 * @param {string} value The date typed, `YYYY-MM-DD`, or empty.
	 */
	const editBound = ( bound, value ) => {
		const edited = { start: bookingStart, end: bookingEnd, [ bound ]: value };

		setCustom( edited );
		setPicked( rangeChoice( ranges, edited ) );
	};

	const onSaved = ( saved ) => {
		if ( saved && 'object' === typeof saved && Number( saved.id ) === enquiryId ) {
			setFetched( saved );
		}

		setEditing( false );
		setNotice( __( 'The enquiry was updated.', 'marthrown-enquiry-hub' ) );

		// The stored values changed, so a list showing this row is now stale.
		if ( onChanged ) {
			onChanged( saved );
		}

		// The edit response carries the updated enquiry, but not necessarily the
		// history entry the edit recorded, so read it back.
		read().catch( () => setLoadFailed( true ) );
	};

	if ( loading && ! enquiry ) {
		return (
			<section className="meh-detail">
				<Spinner />
			</section>
		);
	}

	if ( ! enquiry ) {
		return (
			<section className="meh-detail">
				<Notice status="error" isDismissible={ false }>
					{ __(
						'That enquiry could not be loaded.',
						'marthrown-enquiry-hub'
					) }
				</Notice>
				{ onClose && <CloseButton onClose={ onClose } /> }
			</section>
		);
	}

	const name = `${ text( enquiry.first_name ) } ${ text(
		enquiry.last_name
	) }`.trim();

	return (
		<section className="meh-detail">
			<header className="meh-detail__head">
				<h3>
					{ name ||
						__( '(no name stored)', 'marthrown-enquiry-hub' ) }
					<span className="meh-detail__id">
						{ sprintf(
							/* translators: %d: enquiry identifier. */
							__( '#%d', 'marthrown-enquiry-hub' ),
							enquiryId
						) }
					</span>
					<StatusBadge
						status={ status }
						closedFrom={ enquiry.closed_from }
					/>
					{ !! enquiry.is_test && (
						<span className="meh-badge meh-badge--test">
							{ __( 'Test', 'marthrown-enquiry-hub' ) }
						</span>
					) }
				</h3>
				{ onClose && <CloseButton onClose={ onClose } /> }
			</header>

			{ loadFailed && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The enquiry could not be re-read, so what is shown may be out of date.',
						'marthrown-enquiry-hub'
					) }
				</Notice>
			) }

			{ failure && (
				<Notice status="error" isDismissible={ false }>
					{ failure }
				</Notice>
			) }

			{ notice && (
				<Notice status="success" isDismissible={ false }>
					{ notice }
				</Notice>
			) }

			{ editing ? (
				<EnquiryForm
					enquiry={ enquiry }
					onSaved={ onSaved }
					onCancel={ () => setEditing( false ) }
				/>
			) : (
				<>
					<div className="meh-detail__actions">
						{ /* Solely what `allowed_transitions` named: a closed
						     enquiry lists nothing, so nothing is offered. */ }
						{ transitions.map( ( target ) => (
							<button
								key={ target }
								type="button"
								className="button button-primary"
								disabled={ !! busy }
								onClick={ () => {
									setComment( '' );
									setPending( target );
								} }
							>
								{ transitionLabel( target ) }
							</button>
						) ) }

						{ /* No edit control at all while the enquiry holds
						     `closed` (Requirements 9.1, 19.12). */ }
						{ ! isClosed && (
							<button
								type="button"
								className="button"
								onClick={ () => setEditing( true ) }
							>
								{ __(
									'Edit details',
									'marthrown-enquiry-hub'
								) }
							</button>
						) }

						<button
							type="button"
							className="button"
							disabled={ !! busy }
							onClick={ run(
								'duplicate',
								() => duplicateEnquiry( enquiryId ),
								( result ) =>
									result && result.id
										? sprintf(
												/* translators: %d: the new enquiry identifier. */
												__(
													'Re-raised as enquiry #%d.',
													'marthrown-enquiry-hub'
												),
												Number( result.id )
										  )
										: __(
												'The enquiry was re-raised.',
												'marthrown-enquiry-hub'
										  )
							) }
						>
							{ __( 'Re-raise', 'marthrown-enquiry-hub' ) }
						</button>

						{ busy && <Spinner /> }
					</div>

					{ /* One dialog for whichever transition was clicked, rather
					     than one per button: the target status is the only thing
					     that differs between them. */ }
					{ pending && (
						<Modal
							title={ transitionLabel( pending ) }
							className="meh-status-modal"
							onRequestClose={ cancelPending }
						>
							<p className="meh-status-modal__lead">
								{ sprintf(
									/* translators: %s: the status the enquiry is moving to. */
									__(
										'This enquiry will move to %s. Add a comment saying why — it is kept as a note on the enquiry.',
										'marthrown-enquiry-hub'
									),
									statusLabel( pending )
								) }
							</p>

							<TextareaControl
								label={ __(
									'Comment (optional)',
									'marthrown-enquiry-hub'
								) }
								value={ comment }
								onChange={ setComment }
								rows={ 4 }
								__nextHasNoMarginBottom
							/>

							<div className="meh-status-modal__actions">
								<button
									type="button"
									className="button"
									onClick={ cancelPending }
								>
									{ __( 'Cancel', 'marthrown-enquiry-hub' ) }
								</button>
								<button
									type="button"
									className="button button-primary"
									onClick={ applyPending }
								>
									{ transitionLabel( pending ) }
								</button>
							</div>
						</Modal>
					) }

					{ convertible && (
						<div className="meh-detail__convert">
							<SelectControl
								label={ __(
									'Calendar',
									'marthrown-enquiry-hub'
								) }
								value={ calendar }
								options={ bookable.map( ( item ) => ( {
									label: item.name,
									value: String( item.id ),
								} ) ) }
								onChange={ setCalendar }
								// One allowed calendar is not a choice, so it is
								// shown rather than asked. The disabled control
								// still names the calendar the booking will be
								// made on, which a bare line of text beside a
								// hidden decision would not.
								disabled={ bookable.length < 2 }
								help={ calendarHelp(
									calendars,
									list( enquiry.site_exclusivity )
								) }
								__nextHasNoMarginBottom
							/>
							<SelectControl
								label={ __(
									'Booking dates',
									'marthrown-enquiry-hub'
								) }
								value={ choice }
								options={ [
									...ranges.map( ( range, index ) => ( {
										label: sprintf(
											/* translators: 1: rank of the candidate range, 2: the range itself. */
											__(
												'%1$s: %2$s',
												'marthrown-enquiry-hub'
											),
											rankLabel( index ),
											rangeText( range )
										),
										value: String( index ),
									} ) ),
									{
										label: __(
											'Custom range…',
											'marthrown-enquiry-hub'
										),
										value: CUSTOM_RANGE,
									},
								] }
								onChange={ chooseRange }
								__nextHasNoMarginBottom
							/>
							{ /* The bounds the chosen range maps to, and the one
							     place they are edited. Shown for a candidate range
							     as well as a custom one: the dates that will be
							     booked are worth seeing before the button is
							     pressed, and a day moved by agreement is edited
							     here rather than by re-typing the whole range. */ }
							<div className="meh-detail__convert-dates">
								<label className="meh-date-label">
									{ __(
										'Start date',
										'marthrown-enquiry-hub'
									) }
									<input
										type="date"
										value={ bookingStart }
										onChange={ ( event ) =>
											editBound(
												'start',
												event.target.value
											)
										}
									/>
								</label>
								<label className="meh-date-label">
									{ __(
										'End date',
										'marthrown-enquiry-hub'
									) }
									<input
										type="date"
										value={ bookingEnd }
										onChange={ ( event ) =>
											editBound(
												'end',
												event.target.value
											)
										}
									/>
								</label>
							</div>
							{ '' !== rangeFault && (
								<p className="meh-detail__convert-fault">
									{ rangeFault }
								</p>
							) }
							<button
								type="button"
								className="button"
								disabled={
									!! busy || ! calendar || '' !== rangeFault
								}
								onClick={ run(
									'convert',
									() =>
										convertEnquiry(
											enquiryId,
											Number( calendar ),
											bookingStart,
											bookingEnd || bookingStart
										),
									() =>
										__(
											'The booking was created.',
											'marthrown-enquiry-hub'
										)
								) }
							>
								{ __(
									'Create booking',
									'marthrown-enquiry-hub'
								) }
							</button>
						</div>
					) }

					<dl className="meh-detail__grid">
						<Row label={ LABELS.email }>
							{ text( enquiry.email ) || '—' }
						</Row>
						<Row label={ LABELS.phone }>
							{ text( enquiry.phone ) || '—' }
						</Row>
						<Row label={ LABELS.total_guests }>
							{ text( enquiry.total_guests ) || '—' }
						</Row>
						<Row label={ LABELS.date_ranges }>
							{ ranges.length > 0 ? (
								<ul className="meh-detail__ranges">
									{ ranges.map( ( range, index ) => (
										<li key={ index }>
											<span className="meh-detail__range-rank">
												{ rankLabel( index ) }
											</span>{ ' ' }
											{ rangeText( range ) }
										</li>
									) ) }
								</ul>
							) : (
								'—'
							) }
						</Row>
						<Row label={ LABELS.event_type }>
							{ terms( enquiry.event_type ) }
						</Row>
						<Row label={ LABELS.site_exclusivity }>
							{ terms( enquiry.site_exclusivity ) }
						</Row>
						<Row label={ LABELS.source }>
							{ text( enquiry.source ) || '—' }
						</Row>
						<Row label={ LABELS.created_at }>
							{ text( enquiry.created_at ) || '—' }
						</Row>
						<Row label={ LABELS.updated_at }>
							{ text( enquiry.updated_at ) || '—' }
						</Row>
						<Row label={ LABELS.status_changed_at }>
							{ text( enquiry.status_changed_at ) || '—' }
						</Row>

						<Row label={ __( 'CRM sync', 'marthrown-enquiry-hub' ) }>
							<span className="meh-detail__crm-state">
								{ crmStateLabel( enquiry.crm_sync_state ) }
							</span>
							{ 'synced' !== enquiry.crm_sync_state &&
								! isClosed && (
									/* An underlined link, not a button: it sits
									   inline in a summary row beside the sync
									   state and the CRM link, and WordPress's
									   own `button-link` is defined only in
									   wp-admin's CSS — on the front end it fell
									   back to the browser's default button, a
									   full-size grey control in the middle of a
									   sentence. `meh-link-button` is the same
									   idea, declared here so it holds wherever
									   the panel is rendered. */
									<button
										type="button"
										className="meh-link-button"
										disabled={ !! busy }
										onClick={ run(
											'retry-crm',
											() =>
												retryEnquiryCrm( enquiryId ),
											() =>
												__(
													'Contact linkage was retried.',
													'marthrown-enquiry-hub'
												)
										) }
									>
										{ __(
											'Retry contact linkage',
											'marthrown-enquiry-hub'
										) }
									</button>
								) }
							{ enquiry.crm_url && (
								<a
									className="meh-detail__link"
									href={ enquiry.crm_url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __(
										'Open in CRM',
										'marthrown-enquiry-hub'
									) }
								</a>
							) }
						</Row>

						<Row label={ __( 'Booking', 'marthrown-enquiry-hub' ) }>
							{ bookingId ? (
								<BookingLink
									bookingId={ bookingId }
									url={ bookingUrl( enquiry ) }
								/>
							) : (
								__( 'Not converted', 'marthrown-enquiry-hub' )
							) }
						</Row>
					</dl>

					<div className="meh-detail__section">
						<h4>{ __( 'Message', 'marthrown-enquiry-hub' ) }</h4>
						<p className="meh-detail__message">
							{ text( enquiry.message ) ||
								__(
									'No message was supplied.',
									'marthrown-enquiry-hub'
								) }
						</p>
					</div>

					{ list( enquiry.siblings ).length > 0 && (
						<div className="meh-detail__section">
							<h4>
								{ __(
									'Other enquiries from this email',
									'marthrown-enquiry-hub'
								) }
							</h4>
							<ul className="meh-detail__list">
								{ list( enquiry.siblings ).map( ( sibling ) => (
									<li key={ sibling.id }>
										<span className="meh-detail__id">
											{ sprintf(
												/* translators: %d: enquiry identifier. */
												__(
													'#%d',
													'marthrown-enquiry-hub'
												),
												Number( sibling.id )
											) }
										</span>
										<StatusBadge
											status={ sibling.status }
											closedFrom={
												sibling.closed_from
											}
										/>
										<span className="meh-muted">
											{ text( sibling.created_at ) }
										</span>

										{ /* What the enquiry was for, which is
										     what makes a repeat address worth
										     looking at: the same person asking
										     again about the same kind of event
										     reads very differently from two
										     unrelated enquiries. Labelled,
										     because "wedding" and "whole site"
										     are not self-describing side by
										     side. */ }
										<dl className="meh-detail__sibling-terms">
											<dt>{ LABELS.event_type }</dt>
											<dd>
												{ terms(
													sibling.event_type
												) }
											</dd>
											<dt>
												{ LABELS.site_exclusivity }
											</dt>
											<dd>
												{ terms(
													sibling.site_exclusivity
												) }
											</dd>
										</dl>
									</li>
								) ) }
							</ul>
						</div>
					) }

					<div className="meh-detail__section">
						<h4>{ __( 'Notes', 'marthrown-enquiry-hub' ) }</h4>

						{ ! isClosed && (
							<div className="meh-detail__note-add">
								<TextareaControl
									label={ __(
										'Add a note',
										'marthrown-enquiry-hub'
									) }
									value={ note }
									onChange={ setNote }
									__nextHasNoMarginBottom
								/>
								<button
									type="button"
									className="button"
									disabled={ !! busy || '' === note.trim() }
									onClick={ run(
										'note',
										async () => {
											const added = await addEnquiryNote(
												enquiryId,
												note
											);
											setNote( '' );
											return added;
										},
										() =>
											__(
												'The note was added.',
												'marthrown-enquiry-hub'
											)
									) }
								>
									{ __(
										'Add note',
										'marthrown-enquiry-hub'
									) }
								</button>
							</div>
						) }

						{ list( enquiry.notes ).length > 0 ? (
							<ul className="meh-detail__list">
								{ list( enquiry.notes ).map( ( item ) => (
									<li key={ item.id }>
										<span className="meh-muted">
											{ text( item.created_at ) }
											{ item.author
												? ` — ${ text( item.author ) }`
												: '' }
										</span>
										<p>{ text( item.body ) }</p>
									</li>
								) ) }
							</ul>
						) : (
							<p className="meh-muted">
								{ __(
									'No notes yet.',
									'marthrown-enquiry-hub'
								) }
							</p>
						) }
					</div>

					{ /* Collapsed by default: the trail is the longest thing on
					     the panel and the least often wanted, so it would
					     otherwise push the notes — which are read on every visit
					     — off the bottom of the screen. A native `details` rather
					     than a state flag and a button, so it opens with the
					     keyboard, survives a re-render and is findable by the
					     browser's own in-page search. */ }
					<details className="meh-detail__section meh-detail__trail">
						<summary>
							<h4>
								{ __( 'History', 'marthrown-enquiry-hub' ) }
								<span className="meh-muted meh-detail__trail-count">
									{ list( enquiry.history ).length }
								</span>
							</h4>
						</summary>
						{ list( enquiry.history ).length > 0 ? (
							<ul className="meh-detail__list meh-detail__history">
								{ list( enquiry.history ).map( ( entry ) => (
									<li key={ entry.id }>
										<span className="meh-muted">
											{ text( entry.created_at ) }
											{ entry.actor
												? ` — ${ text( entry.actor ) }`
												: '' }
										</span>
										<span className="meh-detail__entry-type">
											{ text( entry.entry_type ) }
										</span>
										<p>{ text( entry.description ) }</p>
										<ChangedFields
											context={ entry.context }
										/>
									</li>
								) ) }
							</ul>
						) : (
							<p className="meh-muted">
								{ __(
									'No history recorded.',
									'marthrown-enquiry-hub'
								) }
							</p>
						) }
					</details>
				</>
			) }
		</section>
	);
}

/**
 * One labelled row of the summary.
 *
 * @param {Object} props          Component props.
 * @param {string} props.label    Row label.
 * @param {*}      props.children Row value.
 * @return {JSX.Element} The rendered row.
 */
function Row( { label, children } ) {
	return (
		<>
			<dt>{ label }</dt>
			<dd>{ children }</dd>
		</>
	);
}

/**
 * The control that closes the panel.
 *
 * Labelled "Back to list" rather than "Close": `closed` is a status this panel
 * offers as a transition, and a control sharing that word would read as one.
 *
 * @param {Object}   props         Component props.
 * @param {Function} props.onClose Close handler.
 * @return {JSX.Element} The rendered button.
 */
function CloseButton( { onClose } ) {
	return (
		<button type="button" className="button" onClick={ onClose }>
			{ __( 'Back to list', 'marthrown-enquiry-hub' ) }
		</button>
	);
}

/**
 * The linked booking, as a link when a URL is known and as its identifier
 * otherwise.
 *
 * The single-enquiry representation carries `booking_id` but no calendar, and
 * the WP Booking System edit screen needs both, so a URL is only available when
 * the conversion that made the booking reported one.
 *
 * @param {Object} props           Component props.
 * @param {number} props.bookingId Booking identifier.
 * @param {string} props.url       Booking edit URL, when known.
 * @return {JSX.Element} The rendered booking reference.
 */
function BookingLink( { bookingId, url } ) {
	const label = sprintf(
		/* translators: %d: booking identifier. */
		__( 'Booking #%d', 'marthrown-enquiry-hub' ),
		bookingId
	);

	if ( ! url ) {
		return <span>{ label }</span>;
	}

	return (
		<a
			className="meh-detail__link"
			href={ url }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ label }
		</a>
	);
}

/**
 * The fields a `fields_edited` history entry changed, previous value to new
 * (Requirement 19.13).
 *
 * Rendered from whatever the context holds rather than from a list of fields
 * kept here, so an entry naming a field this panel does not otherwise show is
 * still legible.
 *
 * @param {Object} props         Component props.
 * @param {Object} props.context Decoded history context.
 * @return {JSX.Element|null} The rendered change list.
 */
function ChangedFields( { context } ) {
	const changed = context && 'object' === typeof context ? context : {};
	const fields = Object.keys( changed ).filter( ( field ) => {
		const change = changed[ field ];
		return (
			change &&
			'object' === typeof change &&
			( 'from' in change || 'to' in change )
		);
	} );

	if ( 0 === fields.length ) {
		return null;
	}

	return (
		<ul className="meh-detail__changes">
			{ fields.map( ( field ) => (
				<li key={ field }>
					{ sprintf(
						/* translators: 1: field name, 2: previous value, 3: new value. */
						__( '%1$s: %2$s → %3$s', 'marthrown-enquiry-hub' ),
						field,
						value( changed[ field ].from ),
						value( changed[ field ].to )
					) }
				</li>
			) ) }
		</ul>
	);
}

/**
 * What a completed transition is reported as.
 *
 * The route applies the transition first and the comment second, so the two can
 * land separately: a comment `NoteService` refused leaves a status change that
 * did happen and a note that did not. That is reported as one sentence about each
 * rather than as a bare success, because a user who wrote a comment and is told
 * only "Status set to Lost" has no way to know the comment went nowhere.
 *
 * @param {string} target Status the transition led to.
 * @param {Object} result The route's response.
 * @return {string} Notice text.
 */
function statusNotice( target, result ) {
	const applied = sprintf(
		/* translators: %s: the new status. */
		__( 'Status set to %s.', 'marthrown-enquiry-hub' ),
		statusLabel( target )
	);

	const noteError = result && result.note_error ? String( result.note_error ) : '';

	if ( '' === noteError ) {
		return applied;
	}

	return sprintf(
		/* translators: 1: the "Status set to …" sentence, 2: why the note was refused. */
		__(
			'%1$s The comment was not saved: %2$s',
			'marthrown-enquiry-hub'
		),
		applied,
		noteError
	);
}

/**
 * The label a transition button carries.
 *
 * @param {string} target Status the transition leads to.
 * @return {string} Button label.
 */
function transitionLabel( target ) {
	return sprintf(
		/* translators: %s: the status the transition leads to. */
		__( 'Mark %s', 'marthrown-enquiry-hub' ),
		statusLabel( target )
	);
}

/**
 * A status under its readable name, or under its own when unrecognised.
 *
 * @param {string} status Status value.
 * @return {string} Status label.
 */
function statusLabel( status ) {
	return STATUS_LABELS[ status ] || String( status );
}

/**
 * The `crm_sync_state` in words. Every state is reported, `pending` and
 * `synced` alike (Requirement 5.3), including the empty state a storage failure
 * leaves behind.
 *
 * @param {string} state Stored sync state.
 * @return {string} Readable state.
 */
function crmStateLabel( state ) {
	if ( 'synced' === state ) {
		return __( 'Synced', 'marthrown-enquiry-hub' );
	}

	if ( 'pending' === state ) {
		return __( 'Pending', 'marthrown-enquiry-hub' );
	}

	return __( 'Not linked', 'marthrown-enquiry-hub' );
}

/**
 * A multi-select value set as text.
 *
 * Zero rows is a legitimate state for either taxonomy, so an empty set reads as
 * nothing selected rather than as a missing value.
 *
 * @param {*} values Stored values.
 * @return {string} Readable list.
 */
function terms( values ) {
	const selected = list( values );

	return selected.length > 0
		? selected.join( ', ' )
		: __( 'None selected', 'marthrown-enquiry-hub' );
}

/**
 * The URL of the booking an enquiry was converted into, when one is known.
 *
 * A conversion made in this session reports the edit URL, and the payload may
 * carry one; neither is guaranteed, and the caller renders the identifier alone
 * when neither is there.
 *
 * @param {Object} enquiry Displayed enquiry.
 * @return {string} Booking URL, or the empty string.
 */
function bookingUrl( enquiry ) {
	if ( enquiry.booking_url ) {
		return String( enquiry.booking_url );
	}

	if ( enquiry.booking && enquiry.booking.edit_url ) {
		return String( enquiry.booking.edit_url );
	}

	return '';
}

/**
 * The message a failed write earns.
 *
 * The API's own message is the useful one — 409 for a closed enquiry, 400 for a
 * note over the limit, 503 when WP Booking System is inactive — so it is shown
 * as it came, with a fallback for a transport failure that carried none.
 *
 * @param {*} error Rejection from the write.
 * @return {string} Notice text.
 */
function failureText( error ) {
	return (
		( error && error.message ) ||
		__( 'That action could not be completed.', 'marthrown-enquiry-hub' )
	);
}

/**
 * A stored value as text, with `null` and absent alike reading as empty.
 *
 * @param {*} raw Stored value.
 * @return {string} Text.
 */
function text( raw ) {
	return raw === null || raw === undefined ? '' : String( raw );
}

/**
 * A history context value as text, joining a set rather than rendering `[object
 * Object]` for it.
 *
 * @param {*} raw Context value.
 * @return {string} Text.
 */
function value( raw ) {
	if ( Array.isArray( raw ) ) {
		return raw
			.map( ( item ) => ( isRange( item ) ? rangeText( item ) : text( item ) ) )
			.join( ', ' );
	}

	const rendered = text( raw );

	return '' === rendered
		? __( '(empty)', 'marthrown-enquiry-hub' )
		: rendered;
}

/**
 * A payload value as an array, whatever it arrived as.
 *
 * @param {*} raw Payload value.
 * @return {Array} List.
 */
function list( raw ) {
	return Array.isArray( raw ) ? raw : [];
}

/**
 * Whether a value is a candidate date range as the API returns them.
 *
 * @param {*} raw Value.
 * @return {boolean} True for a `{ start, end }` object.
 */
function isRange( raw ) {
	return (
		!! raw &&
		'object' === typeof raw &&
		! Array.isArray( raw ) &&
		( 'start' in raw || 'end' in raw )
	);
}

/**
 * One candidate range as text.
 *
 * A range covering a single day reads as that day alone rather than as the same
 * date twice.
 *
 * @param {Object} range Range as the API returns it.
 * @return {string} Text.
 */
function rangeText( range ) {
	const start = text( range && range.start );
	const end = text( range && range.end );

	if ( start === end ) {
		return start;
	}

	return sprintf(
		/* translators: 1: range start date, 2: range end date. */
		__( '%1$s – %2$s', 'marthrown-enquiry-hub' ),
		start,
		end
	);
}

/**
 * What one range's position in the list means.
 *
 * The order the API returns is the enquirer's own ranking, so the first range is
 * labelled as the ideal one and the rest as alternatives in preference order.
 *
 * @param {number} index Position in the stored list.
 * @return {string} Label.
 */
function rankLabel( index ) {
	if ( 0 === index ) {
		return __( 'Ideal', 'marthrown-enquiry-hub' );
	}

	return sprintf(
		/* translators: %d: which alternative, counting from one. */
		__( 'Alternative %d', 'marthrown-enquiry-hub' ),
		index
	);
}

/**
 * Which entry of the booking-dates control a pair of bounds is.
 *
 * A pair matching a candidate range is that candidate, however it came to be
 * typed. Anything else is the custom range.
 *
 * @param {Array}  ranges Candidate ranges as the API returns them.
 * @param {Object} range  The bounds in force.
 * @return {string} Range index as a string, or `CUSTOM_RANGE`.
 */
function rangeChoice( ranges, range ) {
	const found = ranges.findIndex(
		( item ) =>
			text( item && item.start ) === range.start &&
			text( item && item.end ) === range.end
	);

	return found < 0 ? CUSTOM_RANGE : String( found );
}

/**
 * What is wrong with the booking range, if anything.
 *
 * The route refuses all three of these itself, so this is not the check that
 * protects the data — it is the one that says so before the request rather than
 * after it, next to the control that would fix it. An empty end date is not a
 * fault: it is the single-day booking, and the start date stands for both bounds.
 *
 * @param {string} start First day.
 * @param {string} end   Last day, possibly empty.
 * @return {string} Fault text, empty when the range is bookable.
 */
function bookingFault( start, end ) {
	if ( ! parseIsoDate( start ) ) {
		return __(
			'A booking needs a start date.',
			'marthrown-enquiry-hub'
		);
	}

	if ( '' !== end && ! parseIsoDate( end ) ) {
		return __(
			'The end date is not a calendar date.',
			'marthrown-enquiry-hub'
		);
	}

	if ( '' !== end && end < start ) {
		return __(
			'The end date falls before the start date.',
			'marthrown-enquiry-hub'
		);
	}

	return '';
}

/**
 * A calendar or exclusivity name reduced to what a comparison should turn on.
 *
 * Case, spacing and punctuation are all dropped, because the two vocabularies are
 * maintained in different places — the exclusivity terms in the plugin, the
 * calendar names in WP Booking System — and neither is going to be typed to match
 * the other exactly.
 *
 * @param {*} raw Name.
 * @return {string} Comparison key.
 */
function calendarKey( raw ) {
	return text( raw )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '' );
}

/**
 * The exclusivity terms that name a part of the site.
 *
 * @param {Array} exclusivity Stored `site_exclusivity`.
 * @return {string[]} Comparison keys.
 */
function exclusivityTerms( exclusivity ) {
	return exclusivity
		.map( calendarKey )
		.filter( ( term ) => '' !== term && EXCLUSIVITY_NONE !== term );
}

/**
 * The calendars the enquiry's Site Exclusivity names.
 *
 * Matched on the calendar name beginning with the term, which is what lets "Top
 * Site" find the calendar called "Top Site (Festival)": the calendar name says
 * which part of the site it is and then what it is for, and only the first half
 * is the exclusivity. Nothing here works the other way round — a term is never
 * matched against part of itself — so "Full Site" cannot be answered by a
 * calendar called "Full".
 *
 * @param {Array} calendars   Calendars as the API returns them.
 * @param {Array} exclusivity Stored `site_exclusivity`.
 * @return {Array} The matching calendars, empty when none match.
 */
function matchedCalendars( calendars, exclusivity ) {
	const wanted = exclusivityTerms( exclusivity );

	if ( 0 === wanted.length ) {
		return [];
	}

	return calendars.filter( ( item ) => {
		const name = calendarKey( item && item.name );

		return (
			'' !== name && wanted.some( ( term ) => name.startsWith( term ) )
		);
	} );
}

/**
 * The calendars a booking for this enquiry may be created on.
 *
 * The enquiry's Site Exclusivity decides it: an enquiry for the top site is
 * booked on the top site's calendar and on no other, so the control offers that
 * one alone rather than leaving the match to be made by hand every time.
 *
 * Where the exclusivity names no calendar — it is "None", it is unset, or no
 * calendar carries a matching name — every calendar stays offered. A conversion
 * is a booking the team has already agreed with the guest; the alternative to
 * offering all of them is offering none, which would leave the enquiry
 * unconvertible over a naming mismatch nobody looking at this panel can fix.
 *
 * @param {Array} calendars   Calendars as the API returns them.
 * @param {Array} exclusivity Stored `site_exclusivity`.
 * @return {Array} Calendars to offer.
 */
function bookableCalendars( calendars, exclusivity ) {
	const matched = matchedCalendars( calendars, exclusivity );

	return matched.length > 0 ? matched : calendars;
}

/**
 * Why the calendar control offers what it offers.
 *
 * Said out loud in both of the cases where the offer is not simply "the
 * calendars": one calendar because the exclusivity fixed it, and all of them
 * because the exclusivity matched nothing. The second is the one worth reading —
 * it is the only sign a calendar has been renamed out of reach of the match.
 *
 * @param {Array} calendars   Calendars as the API returns them.
 * @param {Array} exclusivity Stored `site_exclusivity`.
 * @return {string} Help text, empty when there is nothing to explain.
 */
function calendarHelp( calendars, exclusivity ) {
	if ( 0 === calendars.length || 0 === exclusivityTerms( exclusivity ).length ) {
		return '';
	}

	if ( 0 === matchedCalendars( calendars, exclusivity ).length ) {
		return __(
			'No calendar matches the site exclusivity asked for, so every calendar is offered.',
			'marthrown-enquiry-hub'
		);
	}

	return sprintf(
		/* translators: %s: the enquiry's site exclusivity. */
		__( 'Set by the site exclusivity: %s.', 'marthrown-enquiry-hub' ),
		exclusivity.map( text ).join( ', ' )
	);
}

/**
 * A `YYYY-MM-DD` string as a UTC `Date`, or null when it does not parse.
 *
 * @param {*} raw Stored bound.
 * @return {Date|null} Date.
 */
function parseIsoDate( raw ) {
	if ( ! /^\d{4}-\d{2}-\d{2}$/.test( text( raw ) ) ) {
		return null;
	}

	const date = new Date( `${ text( raw ) }T00:00:00Z` );

	return Number.isNaN( date.getTime() ) ? null : date;
}
