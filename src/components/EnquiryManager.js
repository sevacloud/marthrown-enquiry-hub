/**
 * EnquiryManager — event enquiries from FluentCRM (the "Event Enquiries" list
 * and "Event Enquiry" tag), with status tabs and per-row status updates.
 */
import { __ } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import { SelectControl, Spinner, Notice } from '@wordpress/components';
import usePolling from '../hooks/usePolling';
import { getEnquiries, setEnquiryStatus } from '../api';
import EnquiryFilters from './EnquiryFilters';
import StatusBadge from './StatusBadge';

const STATUS_CHOICES = [
	{ label: __( 'New', 'marthrown-enquiry-hub' ), value: 'new' },
	{ label: __( 'Replied', 'marthrown-enquiry-hub' ), value: 'replied' },
	{ label: __( 'Quoted', 'marthrown-enquiry-hub' ), value: 'quoted' },
	{ label: __( 'Converted', 'marthrown-enquiry-hub' ), value: 'converted' },
	{ label: __( 'Closed', 'marthrown-enquiry-hub' ), value: 'closed' },
];

const TABS = [
	{ key: 'all', label: __( 'All', 'marthrown-enquiry-hub' ) },
	...STATUS_CHOICES.map( ( s ) => ( { key: s.value, label: s.label } ) ),
];

export default function EnquiryManager( { title, defaultStatus = 'all' } ) {
	const heading = title || __( 'Event enquiries', 'marthrown-enquiry-hub' );
	const [ filters, setFilters ] = useState( {
		status: defaultStatus,
		s: '',
		from: '',
		to: '',
		page: 1,
		per_page: 25,
	} );
	const [ localStatus, setLocalStatus ] = useState( {} );

	const fetcher = useCallback( () => getEnquiries( filters ), [ filters ] );
	const { data, loading, error, refetch } = usePolling( fetcher, 60000, [
		filters,
	] );

	const onStatusChange = async ( id, status ) => {
		setLocalStatus( ( prev ) => ( { ...prev, [ id ]: status } ) );
		try {
			await setEnquiryStatus( id, status );
		} catch ( e ) {
			// Revert on failure; the next poll reconciles anyway.
			setLocalStatus( ( prev ) => {
				const next = { ...prev };
				delete next[ id ];
				return next;
			} );
		}
	};

	const items = data && data.items ? data.items : [];
	const counts = data && data.counts ? data.counts : {};
	const available = ! data || data.available !== false;
	const source = data && data.source ? data.source : {};
	const unconfigured = available && ! source.list && ! source.tag;

	return (
		<section>
			<header className="meh-section__head">
				<h2>{ heading }</h2>
				<button className="button" onClick={ refetch }>
					{ __( 'Refresh', 'marthrown-enquiry-hub' ) }
				</button>
			</header>

			{ ! available && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'FluentCRM was not detected, so enquiries cannot be shown.',
						'marthrown-enquiry-hub'
					) }
				</Notice>
			) }

			{ unconfigured && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'No Event Enquiries list or tag is configured yet — set one in Settings → Enquiry Hub.',
						'marthrown-enquiry-hub'
					) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load enquiries.', 'marthrown-enquiry-hub' ) }
				</Notice>
			) }

			<div className="meh-period-tabs">
				{ TABS.map( ( tab ) => (
					<button
						key={ tab.key }
						type="button"
						className={ `meh-period-tab${
							filters.status === tab.key ? ' is-active' : ''
						}` }
						onClick={ () =>
							setFilters( {
								...filters,
								status: tab.key,
								page: 1,
							} )
						}
					>
						{ tab.label }
						<span className="count">{ counts[ tab.key ] || 0 }</span>
					</button>
				) ) }
			</div>

			<EnquiryFilters filters={ filters } onChange={ setFilters } />

			<table className="widefat striped meh-table">
				<thead>
					<tr>
						<th>{ __( 'Name', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Contact', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Latest note', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Status', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Received', 'marthrown-enquiry-hub' ) }</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					{ loading && ! data && (
						<tr>
							<td colSpan="6">
								<Spinner />
							</td>
						</tr>
					) }
					{ ! loading && items.length === 0 && (
						<tr>
							<td colSpan="6">
								{ __(
									'No enquiries found.',
									'marthrown-enquiry-hub'
								) }
							</td>
						</tr>
					) }
					{ items.map( ( row ) => {
						const status = localStatus[ row.id ] || row.status;
						return (
							<tr key={ row.id }>
								<td>{ row.name || '—' }</td>
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
								<td className="meh-note">{ row.note }</td>
								<td>
									<StatusBadge status={ status } />
									<SelectControl
										value={ status }
										options={ STATUS_CHOICES }
										onChange={ ( value ) =>
											onStatusChange( row.id, value )
										}
										__nextHasNoMarginBottom
									/>
								</td>
								<td>
									{ ( row.date || '' ).substring( 0, 10 ) }
								</td>
								<td>
									{ row.crm_url && (
										<a
											className="button"
											href={ row.crm_url }
											target="_blank"
											rel="noopener noreferrer"
										>
											{ __(
												'Open in CRM',
												'marthrown-enquiry-hub'
											) }
										</a>
									) }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</section>
	);
}
