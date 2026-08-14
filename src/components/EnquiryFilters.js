/**
 * EnquiryFilters — search / date range controls for event enquiries.
 *
 * Status is chosen via the tabs in EnquiryManager, so it isn't duplicated here.
 */
import { __ } from '@wordpress/i18n';
import { Flex, FlexItem } from '@wordpress/components';

export default function EnquiryFilters( { filters, onChange } ) {
	const update = ( key ) => ( value ) =>
		onChange( { ...filters, [ key ]: value, page: 1 } );

	return (
		<Flex className="meh-filters" align="flex-end" justify="flex-start" wrap>
			<FlexItem>
				<label className="meh-date-label">
					{ __( 'Search', 'marthrown-enquiry-hub' ) }
					<input
						type="search"
						placeholder={ __(
							'Name, email, phone, note',
							'marthrown-enquiry-hub'
						) }
						value={ filters.s || '' }
						onChange={ ( e ) => update( 's' )( e.target.value ) }
					/>
				</label>
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
