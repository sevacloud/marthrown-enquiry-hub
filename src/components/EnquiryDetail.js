/**
 * EnquiryDetail — the whole of one enquiry, and everything that can be done to
 * it.
 *
 * The single-enquiry route returns the panel's entire contents in one response
 * (Requirement 13.2): the stored fields, the candidate dates, both multi-select
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
 */
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
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
 * Labels for the stored values the summary lists.
 */
const LABELS = {
	email: __( 'Email', 'marthrown-enquiry-hub' ),
	phone: __( 'Phone', 'marthrown-enquiry-hub' ),
	total_guests: __( 'Total guests', 'marthrown-enquiry-hub' ),
	selected_dates: __( 'Candidate dates', 'marthrown-enquiry-hub' ),
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
	const [ chosen, setChosen ] = useState( '' );

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

	const status = enquiry ? String( enquiry.status || '' ) : '';
	const isClosed = CLOSED === status;
	const transitions = list( enquiry && enquiry.allowed_transitions );
	const dates = list( enquiry && enquiry.selected_dates );
	const bookingId = enquiry ? Number( enquiry.booking_id ) || 0 : 0;
	const convertible = !! enquiry && ! isClosed && ! bookingId && dates.length > 0;

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

				const available = list( response && response.calendars );
				setCalendars( available );

				if ( available.length > 0 ) {
					setCalendar( String( available[ 0 ].id ) );
				}
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
					<StatusBadge status={ status } />
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
								onClick={ run(
									`status:${ target }`,
									() => setEnquiryStatus( enquiryId, target ),
									() =>
										sprintf(
											/* translators: %s: the new status. */
											__(
												'Status set to %s.',
												'marthrown-enquiry-hub'
											),
											statusLabel( target )
										)
								) }
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

					{ convertible && (
						<div className="meh-detail__convert">
							<SelectControl
								label={ __(
									'Calendar',
									'marthrown-enquiry-hub'
								) }
								value={ calendar }
								options={ calendars.map( ( item ) => ( {
									label: item.name,
									value: String( item.id ),
								} ) ) }
								onChange={ setCalendar }
								__nextHasNoMarginBottom
							/>
							<SelectControl
								label={ __(
									'Booking date',
									'marthrown-enquiry-hub'
								) }
								value={ chosen || dates[ 0 ] }
								options={ dates.map( ( date ) => ( {
									label: date,
									value: date,
								} ) ) }
								onChange={ setChosen }
								__nextHasNoMarginBottom
							/>
							<button
								type="button"
								className="button"
								disabled={ !! busy || ! calendar }
								onClick={ run(
									'convert',
									() =>
										convertEnquiry(
											enquiryId,
											Number( calendar ),
											chosen || dates[ 0 ]
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
						<Row label={ LABELS.selected_dates }>
							{ dates.length > 0 ? dates.join( ', ' ) : '—' }
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
									<button
										type="button"
										className="button-link"
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
										/>
										<span className="meh-muted">
											{ text( sibling.created_at ) }
										</span>
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

					<div className="meh-detail__section">
						<h4>{ __( 'History', 'marthrown-enquiry-hub' ) }</h4>
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
					</div>
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
		return raw.map( ( item ) => text( item ) ).join( ', ' );
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
