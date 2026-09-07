/**
 * HubTable — the list layout `EnquiryManager` and `BookingsManager` share:
 * tabs with counts, a search/filter row with an Export CSV link, and the
 * results table itself.
 *
 * Extracted rather than duplicated because the two lists had drifted apart on
 * details that were never meant to differ — a pill-shaped tab strip here, a
 * square one there, an export link on one list and none on the other. One
 * component makes future list screens (Requirement 4.8's rejections view is
 * the obvious next one) start from the same shape instead of a fresh copy.
 *
 * `columns` and `rows` are the only booking- or enquiry-specific things this
 * file knows about; everything else — tabs, the toolbar, the empty and
 * loading states — is generic over them.
 */
import { __ } from '@wordpress/i18n';
import { Spinner, Notice } from '@wordpress/components';

/**
 * @param {Object}   props
 * @param {Array}    props.tabs       `{ key, label, count }[]`, rendered as the
 *                                    status/period strip.
 * @param {string}   props.activeTab  The `key` of the selected tab.
 * @param {Function} props.onTabChange Called with the clicked tab's `key`.
 * @param {*}        props.toolbar    Filter controls rendered above the table
 *                                    (search, date ranges, hide-test/hide-past).
 * @param {string}   props.exportUrl  Export CSV link target. Omitted entirely
 *                                    when empty, rather than rendered disabled.
 * @param {Array}    props.columns    `{ key, label }[]`, in display order.
 * @param {Array}    props.rows       Row data; `renderCell` reads from it by
 *                                    the column's `key`.
 * @param {Function} props.rowKey     `( row ) => key`, for React's list key.
 * @param {Function} props.renderCell `( row, column ) => node`.
 * @param {boolean}  props.loading    True while the first page is in flight.
 * @param {*}        props.error      Truthy to show the load-error notice.
 * @param {string}   props.errorText  Notice text for `error`.
 * @param {string}   props.emptyText  Row text shown when `rows` is empty.
 * @return {JSX.Element}
 */
export default function HubTable( {
	tabs = [],
	activeTab = '',
	onTabChange,
	toolbar = null,
	exportUrl = '',
	columns = [],
	rows = [],
	rowKey,
	renderCell,
	loading = false,
	error = null,
	errorText = '',
	emptyText = '',
} ) {
	return (
		<>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ errorText }
				</Notice>
			) }

			{ tabs.length > 0 && (
				<div className="meh-period-tabs">
					{ tabs.map( ( tab ) => (
						<button
							key={ tab.key }
							type="button"
							className={ `meh-period-tab${
								activeTab === tab.key ? ' is-active' : ''
							}` }
							aria-pressed={ activeTab === tab.key }
							onClick={ () => onTabChange( tab.key ) }
						>
							{ tab.label }
							<span className="count">{ tab.count || 0 }</span>
						</button>
					) ) }
				</div>
			) }

			{ ( toolbar || exportUrl ) && (
				<div className="meh-list-toolbar">
					{ toolbar }
					{ exportUrl && (
						<a className="button meh-export-button" href={ exportUrl }>
							{ __( 'Export CSV', 'marthrown-enquiry-hub' ) }
						</a>
					) }
				</div>
			) }

			<table className="widefat striped meh-table">
				<thead>
					<tr>
						{ columns.map( ( column ) => (
							<th key={ column.key }>{ column.label }</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ loading && 0 === rows.length && (
						<tr>
							<td colSpan={ columns.length }>
								<Spinner />
							</td>
						</tr>
					) }
					{ ! loading && 0 === rows.length && (
						<tr>
							<td colSpan={ columns.length }>{ emptyText }</td>
						</tr>
					) }
					{ rows.map( ( row ) => (
						<tr key={ rowKey( row ) }>
							{ columns.map( ( column ) => (
								<td key={ column.key }>
									{ renderCell( row, column ) }
								</td>
							) ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</>
	);
}
