/**
 * EnquiryManager — the enquiry list, read from the Enquiry Store's own
 * `GET /enquiries` route rather than from FluentCRM.
 *
 * Three status-driven pieces of UI live here and all three cover the same six
 * lifecycle statuses — `new`, `contacted`, `quoted`, `converted`, `lost` and
 * `closed`: the tab set, the per-tab counts the payload carries alongside the
 * page (Requirement 12.9), and the badge each row's status renders through.
 * `all` is a seventh tab rather than a seventh status, and its count is the sum
 * of the rest, which is what the store returns.
 *
 * Applying a transition is not done from a row. `Lifecycle` permits only some
 * pairs, and the permitted set for one enquiry is knowable only from the
 * `allowed_transitions` the single-enquiry route returns, so the controls that
 * change a status belong in `EnquiryDetail`, which has that list. Selecting a
 * row opens that panel (Requirement 13.2); a row itself only reads.
 *
 * A row whose `is_test` is true carries a visible marker, so a staging record
 * is never mistaken for a live enquiry (Requirement 17.4). The control that
 * hides them lives in `EnquiryFilters` and defaults to showing them
 * (Requirement 17.6), which is why `hide_test` is absent from the initial
 * filters rather than present and false.
 *
 * The toolbar's new-enquiry control opens `EnquiryForm` with no enquiry, which
 * is the form's create mode: empty fields, `POST` to `/enquiries`
 * (Requirement 18.23).
 */
import { __ } from '@wordpress/i18n';
import { useState, useCallback, useEffect } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import usePolling from '../hooks/usePolling';
import { getEnquiries, enquiriesExportUrl } from '../api';
import { confirmDiscardNote } from '../leaveGuard';
import EnquiryFilters from './EnquiryFilters';
import EnquiryDetail from './EnquiryDetail';
import EnquiryForm from './EnquiryForm';
import HubTable from './HubTable';
import StatusBadge from './StatusBadge';

/**
 * The six lifecycle statuses, in lifecycle order, with their labels.
 *
 * One list, read by the tabs and by nothing else that needs its own: a status
 * added to `Lifecycle` is added here and every tab, count and badge follows.
 */
const STATUSES = [
	{ value: 'new', label: __( 'New', 'marthrown-enquiry-hub' ) },
	{ value: 'contacted', label: __( 'Contacted', 'marthrown-enquiry-hub' ) },
	{ value: 'quoted', label: __( 'Quoted', 'marthrown-enquiry-hub' ) },
	{ value: 'converted', label: __( 'Converted', 'marthrown-enquiry-hub' ) },
	{ value: 'lost', label: __( 'Lost', 'marthrown-enquiry-hub' ) },
	{ value: 'closed', label: __( 'Closed', 'marthrown-enquiry-hub' ) },
];

const TABS = [
	{ key: 'all', label: __( 'All', 'marthrown-enquiry-hub' ) },
	...STATUSES.map( ( status ) => ( {
		key: status.value,
		label: status.label,
	} ) ),
];

/**
 * The sentences the list route's warning codes stand for (Requirement 12.8).
 */
const WARNINGS = {
	'date_from missing': __(
		'The candidate date filter was not applied: no start date was supplied.',
		'marthrown-enquiry-hub'
	),
	'date_to missing': __(
		'The candidate date filter was not applied: no end date was supplied.',
		'marthrown-enquiry-hub'
	),
};

/**
 * The list table's columns, in display order. Shared between the header row
 * and each row's cell renderer, so the two cannot drift out of step.
 */
const COLUMNS = [
	{ key: 'name', label: __( 'Name', 'marthrown-enquiry-hub' ) },
	{ key: 'contact', label: __( 'Contact', 'marthrown-enquiry-hub' ) },
	{
		key: 'dates',
		label: __( 'Candidate dates', 'marthrown-enquiry-hub' ),
	},
	{ key: 'status', label: __( 'Status', 'marthrown-enquiry-hub' ) },
	{ key: 'received', label: __( 'Received', 'marthrown-enquiry-hub' ) },
];

export default function EnquiryManager( {
	title,
	defaultStatus = 'all',
	headingLevel = 2,
	homeSignal = 0,
	onDirtyChange,
} ) {
	const heading = title || __( 'Event Enquiries', 'marthrown-enquiry-hub' );

	// The visible page title is this section's head, so the level is the
	// caller's to choose: the primary section of a view passes 1 and becomes the
	// document's one h1. Clamped, because an out-of-range level would emit a tag
	// that is not a heading at all.
	const Heading = `h${ Math.min(
		Math.max( parseInt( headingLevel, 10 ) || 2, 1 ),
		6
	) }`;
	const [ filters, setFilters ] = useState( {
		status: defaultStatus,
		s: '',
		from: '',
		to: '',
		date_from: '',
		date_to: '',
		page: 1,
		per_page: 25,
	} );
	const [ selectedId, setSelectedId ] = useState( 0 );
	const [ creating, setCreating ] = useState( false );

	// Whether the open detail panel holds a note that has not been added. Kept
	// here rather than in the panel because it is this component and the side nav
	// that own the controls which would throw it away.
	const [ noteDirty, setNoteDirty ] = useState( false );

	const fetcher = useCallback( () => getEnquiries( filters ), [ filters ] );
	const { data, loading, error, refetch } = usePolling( fetcher, 60000, [
		filters,
	] );

	const items = data && data.items ? data.items : [];
	const counts = data && data.counts ? data.counts : {};
	const warnings = data && data.warnings ? data.warnings : [];
	const selectedRow =
		items.find( ( row ) => Number( row.id ) === selectedId ) || null;

	// Both panels close back to the list and reread it: a created enquiry is a
	// new row, and a transition, note or edit applied in the panel changes one.
	const closePanels = useCallback( () => {
		setCreating( false );
		setSelectedId( 0 );
		setNoteDirty( false );
		refetch();
	}, [ refetch ] );

	// Closing on the user's own initiative, which is the only case that can lose
	// work. `closePanels` itself stays unguarded, because the paths that call it
	// directly — a created enquiry, a saved edit — have already written what the
	// user typed.
	const requestClose = useCallback( () => {
		if ( noteDirty && ! confirmDiscardNote() ) {
			return;
		}

		closePanels();
	}, [ noteDirty, closePanels ] );

	// The side nav asking for the list back. It arrives as a rising number rather
	// than a boolean so that a second click of an already-selected Overview is a
	// second request rather than a no-op, and the nav has nothing to reset.
	//
	// Unguarded on purpose: the nav asked about the unsaved note before it sent
	// the signal, so asking again here would be the same question twice.
	useEffect( () => {
		if ( homeSignal > 0 ) {
			closePanels();
		}
		// Only the signal should trigger this. Including `closePanels` would
		// return the user to the list every time the list refetched.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ homeSignal ] );

	// Upward, so the nav can ask before it switches away and unmounts the panel.
	useEffect( () => {
		if ( onDirtyChange ) {
			onDirtyChange( noteDirty );
		}
	}, [ noteDirty, onDirtyChange ] );

	// A created enquiry returns the user to the list, where the new row is: the
	// panel that would open on it reads a route of its own, and nothing about a
	// just-created enquiry needs reading back before its list row is seen.
	const onCreated = () => closePanels();

	return (
		<section>
			<header className="meh-section__head">
				<Heading>{ heading }</Heading>
				<div className="meh-header-actions">
					<button
						type="button"
						className="button"
						onClick={ () => {
							setSelectedId( 0 );
							setCreating( true );
						} }
					>
						{ __( 'New enquiry', 'marthrown-enquiry-hub' ) }
					</button>
					<button type="button" className="button" onClick={ refetch }>
						{ __( 'Refresh', 'marthrown-enquiry-hub' ) }
					</button>
				</div>
			</header>

			{ /* A half-open candidate-date range is ignored rather than guessed
			     at, and the API says which end is missing (Requirement 12.8). */ }
			{ warnings.map( ( warning ) => (
				<Notice
					key={ warningKey( warning ) }
					status="warning"
					isDismissible={ false }
				>
					{ warningText( warning ) }
				</Notice>
			) ) }

			{ creating && (
				<EnquiryForm onSaved={ onCreated } onCancel={ closePanels } />
			) }

			{ /* The row is handed over alongside its identifier so the panel
			     has something to render while it reads the rest. */ }
			{ ! creating && selectedId > 0 && (
				<EnquiryDetail
					id={ selectedId }
					enquiry={ selectedRow }
					onClose={ requestClose }
					onChanged={ refetch }
					onDirtyChange={ setNoteDirty }
				/>
			) }

			{ ! creating && 0 === selectedId && (
				<HubTable
					tabs={ TABS.map( ( tab ) => ( {
						...tab,
						count: counts[ tab.key ] || 0,
					} ) ) }
					activeTab={ filters.status }
					onTabChange={ ( status ) =>
						setFilters( { ...filters, status, page: 1 } )
					}
					toolbar={
						<EnquiryFilters
							filters={ filters }
							onChange={ setFilters }
						/>
					}
					exportUrl={ enquiriesExportUrl( filters ) }
					columns={ COLUMNS }
					rows={ items }
					rowKey={ ( row ) => row.id }
					renderCell={ ( row, column ) =>
						renderEnquiryCell( row, column, setSelectedId )
					}
					loading={ loading && ! data }
					error={ error }
					errorText={ __(
						'Could not load enquiries.',
						'marthrown-enquiry-hub'
					) }
					emptyText={ __(
						'No enquiries found.',
						'marthrown-enquiry-hub'
					) }
				/>
			) }
		</section>
	);
}

/**
 * One cell of the enquiry list, by column key.
 *
 * @param {Object}   row           Listed enquiry.
 * @param {Object}   column        `{ key, label }` from COLUMNS.
 * @param {Function} setSelectedId Opens the detail panel on this row's id.
 * @return {*} Cell content.
 */
function renderEnquiryCell( row, column, setSelectedId ) {
	switch ( column.key ) {
		case 'name':
			return (
				<>
					{ /* Still a real <button> — a native control keeps click,
					     Enter/Space and screen-reader semantics for free — but
					     `meh-row-open` now strips every trace of button chrome,
					     so it reads as text rather than as a control. */ }
					<button
						type="button"
						className="meh-row-open"
						onClick={ () =>
							setSelectedId( Number.parseInt( row.id, 10 ) )
						}
					>
						{ fullName( row ) }
					</button>
					{ row.is_test && (
						<span className="meh-badge meh-badge--test meh-badge--spaced">
							{ __( 'Test', 'marthrown-enquiry-hub' ) }
						</span>
					) }
				</>
			);

		case 'contact':
			return (
				<>
					{ row.email }
					{ row.phone ? (
						<>
							<br />
							<span className="meh-muted">{ row.phone }</span>
						</>
					) : null }
				</>
			);

		case 'dates':
			return dateList( row.selected_dates );

		case 'status':
			return (
				<StatusBadge
					status={ row.status }
					closedFrom={ row.closed_from }
				/>
			);

		case 'received':
			return day( row.created_at );

		default:
			return null;
	}
}

/**
 * An enquirer's name as one string, falling back to their email and then to a
 * dash: a row always has something clickable, whatever the intake supplied.
 *
 * @param {Object} row Listed enquiry.
 * @return {string} Display name.
 */
function fullName( row ) {
	const name = [ row.first_name, row.last_name ]
		.map( ( part ) => ( part ? String( part ).trim() : '' ) )
		.filter( ( part ) => '' !== part )
		.join( ' ' );

	return name || row.email || '—';
}

/**
 * The candidate dates of one row, comma-separated.
 *
 * @param {*} dates Candidate dates as the store hydrated them.
 * @return {string} Display text.
 */
function dateList( dates ) {
	return Array.isArray( dates ) && dates.length ? dates.join( ', ' ) : '—';
}

/**
 * The date half of a stored `DATETIME`.
 *
 * @param {*} value Stored value.
 * @return {string} `Y-m-d`, or an em dash when nothing is stored.
 */
function day( value ) {
	return value ? String( value ).substring( 0, 10 ) : '—';
}

/**
 * A list warning's key, stable enough for a render list.
 *
 * @param {*} warning One entry of the payload's `warnings`.
 * @return {string} Key.
 */
function warningKey( warning ) {
	if ( warning && 'object' === typeof warning ) {
		return String( warning.code || warning.message || 'warning' );
	}

	return String( warning );
}

/**
 * A list warning as text.
 *
 * The query builder returns each warning as a short code naming the filter
 * parameter it is missing, so a recognised one is rendered as the sentence it
 * stands for and anything else is rendered as it arrived rather than dropped.
 *
 * @param {*} warning One entry of the payload's `warnings`.
 * @return {string} Notice text.
 */
function warningText( warning ) {
	return WARNINGS[ warningKey( warning ) ] || warningKey( warning );
}
