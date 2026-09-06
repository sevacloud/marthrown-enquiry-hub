/**
 * EnquiryFilters — search / date range / test-record controls for event enquiries.
 *
 * Status is chosen via the tabs in EnquiryManager, so it isn't duplicated here.
 *
 * Two independent date ranges live side by side: `from`/`to` bound when the
 * enquiry was received (Requirements 12.5, 12.6), while `date_from`/`date_to`
 * bound the candidate dates the enquirer asked for (Requirement 12.7). The
 * candidate-date filter applies only when both ends are supplied — the API
 * ignores a half-open range and returns a warning naming the missing end
 * (Requirement 12.8), so nothing here has to guess at the other end.
 *
 * The hide-test toggle starts off, leaving `hide_test` falsy so test enquiries
 * are listed until the user asks for them to be hidden (Requirement 17.6).
 */
import { __ } from '@wordpress/i18n';
import { Flex, FlexItem, ToggleControl } from '@wordpress/components';

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
			<FlexItem>
				<label className="meh-date-label">
					{ __( 'Candidate dates from', 'marthrown-enquiry-hub' ) }
					<input
						type="date"
						value={ filters.date_from || '' }
						onChange={ ( e ) =>
							update( 'date_from' )( e.target.value )
						}
					/>
				</label>
			</FlexItem>
			<FlexItem>
				<label className="meh-date-label">
					{ __( 'Candidate dates to', 'marthrown-enquiry-hub' ) }
					<input
						type="date"
						value={ filters.date_to || '' }
						onChange={ ( e ) =>
							update( 'date_to' )( e.target.value )
						}
					/>
				</label>
			</FlexItem>
			<FlexItem>
				<ToggleControl
					label={ __(
						'Hide test enquiries',
						'marthrown-enquiry-hub'
					) }
					checked={ !! filters.hide_test }
					onChange={ ( value ) => update( 'hide_test' )( !! value ) }
					__nextHasNoMarginBottom
				/>
			</FlexItem>
		</Flex>
	);
}
