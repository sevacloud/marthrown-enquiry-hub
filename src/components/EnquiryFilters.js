/**
 * EnquiryFilters — source / status / date range controls.
 */
import { __ } from '@wordpress/i18n';
import { SelectControl, Flex, FlexItem } from '@wordpress/components';

const SOURCE_OPTIONS = [
	{ label: __( 'All sources', 'marthrown-enquiry-hub' ), value: '' },
	{ label: __( 'Web form', 'marthrown-enquiry-hub' ), value: 'webform' },
	{ label: __( 'Email', 'marthrown-enquiry-hub' ), value: 'email' },
	{ label: __( 'WP Booking System', 'marthrown-enquiry-hub' ), value: 'wpbs' },
];

const STATUS_OPTIONS = [
	{ label: __( 'All statuses', 'marthrown-enquiry-hub' ), value: '' },
	{ label: __( 'New', 'marthrown-enquiry-hub' ), value: 'new' },
	{ label: __( 'Replied', 'marthrown-enquiry-hub' ), value: 'replied' },
	{ label: __( 'Resolved', 'marthrown-enquiry-hub' ), value: 'resolved' },
];

export default function EnquiryFilters( { filters, onChange } ) {
	const update = ( key ) => ( value ) =>
		onChange( { ...filters, [ key ]: value, page: 1 } );

	return (
		<Flex className="meh-filters" align="flex-end" justify="flex-start" wrap>
			<FlexItem>
				<SelectControl
					label={ __( 'Source', 'marthrown-enquiry-hub' ) }
					value={ filters.source || '' }
					options={ SOURCE_OPTIONS }
					onChange={ update( 'source' ) }
					__nextHasNoMarginBottom
				/>
			</FlexItem>
			<FlexItem>
				<SelectControl
					label={ __( 'Status', 'marthrown-enquiry-hub' ) }
					value={ filters.status || '' }
					options={ STATUS_OPTIONS }
					onChange={ update( 'status' ) }
					__nextHasNoMarginBottom
				/>
			</FlexItem>
			<FlexItem>
				<label className="meh-date-label">
					{ __( 'From', 'marthrown-enquiry-hub' ) }
					<input
						type="date"
						value={ filters.from || '' }
						onChange={ ( e ) => update( 'from' )( e.target.value ) }
					/>
				</label>
			</FlexItem>
			<FlexItem>
				<label className="meh-date-label">
					{ __( 'To', 'marthrown-enquiry-hub' ) }
					<input
						type="date"
						value={ filters.to || '' }
						onChange={ ( e ) => update( 'to' )( e.target.value ) }
					/>
				</label>
			</FlexItem>
		</Flex>
	);
}
