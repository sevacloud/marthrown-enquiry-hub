/**
 * EnquiryManager — filterable enquiries table with per-row status updates.
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
	{ label: __( 'Resolved', 'marthrown-enquiry-hub' ), value: 'resolved' },
];

export default function EnquiryManager( { title, defaultStatus = '', defaultSource = '' } ) {
	const heading = title || __( 'Enquiries', 'marthrown-enquiry-hub' );
	const [ filters, setFilters ] = useState( {
		source: defaultSource,
		status: defaultStatus,
		from: '',
		to: '',
		page: 1,
		per_page: 20,
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
			// Revert on failure; next poll reconciles anyway.
			setLocalStatus( ( prev ) => {
				const next = { ...prev };
				delete next[ id ];
				return next;
			} );
		}
	};

	const items = data ? data.items : [];

	return (
		<section>
			<header className="meh-section__head">
				<h2>{ heading }</h2>
				<button className="button" onClick={ refetch }>
					{ __( 'Refresh', 'marthrown-enquiry-hub' ) }
				</button>
			</header>

			<EnquiryFilters filters={ filters } onChange={ setFilters } />

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load enquiries.', 'marthrown-enquiry-hub' ) }
				</Notice>
			) }

			<table className="widefat striped meh-table">
				<thead>
					<tr>
						<th>{ __( 'Name', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Email', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Source', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Latest note', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Status', 'marthrown-enquiry-hub' ) }</th>
						<th>{ __( 'Date', 'marthrown-enquiry-hub' ) }</th>
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
								{ __( 'No enquiries found.', 'marthrown-enquiry-hub' ) }
							</td>
						</tr>
					) }
					{ items.map( ( row ) => {
						const status = localStatus[ row.id ] || row.status;
						return (
							<tr key={ row.id }>
								<td>{ row.name }</td>
								<td>{ row.email }</td>
								<td>{ ( row.sources || [] ).join( ', ' ) }</td>
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
								<td>{ row.date }</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</section>
	);
}
