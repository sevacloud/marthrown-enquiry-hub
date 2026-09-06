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
import { useState, useCallback } from '@wordpress/element';
import { Spinner, Notice } from '@wordpress/components';
import usePolling from '../hooks/usePolling';
import { getEnquiries } from '../api';
import EnquiryFilters from './EnquiryFilters';
import EnquiryDetail from './EnquiryDetail';
import EnquiryForm from './EnquiryForm';
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

const COLUMNS = 5;

export default function EnquiryManager( { title, defaultStatus = 'all' } ) {
	const heading = title || __( 'Event enquiries', 'marthrown-enquiry-hub' );
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
	const closePanels = () => {
		setCreating( false );
		setSelectedId( 0 );
		refetch();
	};

	// A created enquiry returns the user to the list, where the new row is: the
	// panel that would open on it reads a route of its own, and nothing about a
	// just-created enquiry needs reading back before its list row is seen.
	const onCreated = () => closePanels();

	return (
		<section>
			<header className="meh-section__head">
				<h2>{ heading }</h2>
				<div className="meh-header-actions">
					<button
						type="button"
						className="button button-primary"
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

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'Could not load enquiries.',
						'marthrown-enquiry-hub'
					) }
				</Notice>
			) }

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
					onClose={ closePanels }
					onChanged={ refetch }
				/>
			) }

			{ ! creating && 0 === selectedId && (
				<>
					<div className="meh-period-tabs">
						{ TABS.map( ( tab ) => (
							<button
								key={ tab.key }
								type="button"
								className={ `meh-period-tab${
									filters.status === tab.key
										? ' is-active'
										: ''
								}` }
								aria-pressed={ filters.status === tab.key }
								onClick={ () =>
									setFilters( {
										...filters,
										status: tab.key,
										page: 1,
									} )
								}
							>
								{ tab.label }
								<span className="count">
									{ counts[ tab.key ] || 0 }
								</span>
							</button>
						) ) }
					</div>

					<EnquiryFilters
						filters={ filters }
						onChange={ setFilters }
					/>

					<table className="widefat striped meh-table">
						<thead>
							<tr>
								<th>
									{ __( 'Name', 'marthrown-enquiry-hub' ) }
								</th>
								<th>
									{ __( 'Contact', 'marthrown-enquiry-hub' ) }
								</th>
								<th>
									{ __(
										'Candidate dates',
										'marthrown-enquiry-hub'
									) }
								</th>
								<th>
									{ __( 'Status', 'marthrown-enquiry-hub' ) }
								</th>
								<th>
									{ __( 'Received', 'marthrown-enquiry-hub' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ loading && ! data && (
								<tr>
									<td colSpan={ COLUMNS }>
										<Spinner />
									</td>
								</tr>
							) }
							{ ! loading && 0 === items.length && (
								<tr>
									<td colSpan={ COLUMNS }>
										{ __(
											'No enquiries found.',
											'marthrown-enquiry-hub'
										) }
									</td>
								</tr>
							) }
							{ items.map( ( row ) => (
								<tr key={ row.id }>
									<td>
										<button
											type="button"
											className="button-link meh-row-open"
											onClick={ () =>
												setSelectedId(
													parseInt( row.id, 10 )
												)
											}
										>
											{ fullName( row ) }
										</button>
										{ row.is_test && (
											<span className="meh-badge meh-badge--test">
												{ __(
													'Test',
													'marthrown-enquiry-hub'
												) }
											</span>
										) }
									</td>
									<td>
										{ row.email }
										{ row.phone ? (
											<>
												<br />
												<span className="meh-muted">
													{ row.phone }
												</span>
											</>
										) : null }
									</td>
									<td>{ dateList( row.selected_dates ) }</td>
									<td>
										<StatusBadge status={ row.status } />
									</td>
									<td>{ day( row.created_at ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</>
			) }
		</section>
	);
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
