/**
 * StatusBadge — small coloured label for an enquiry status.
 *
 * A closed enquiry renders two badges rather than one. `closed` says the enquiry
 * is finished but not how it finished, and won or lost is the distinction anyone
 * scanning a list of closed enquiries actually wants; the API derives it as
 * `closed_from`, and it is shown beside the status rather than instead of it,
 * because the status the enquiry holds is still `closed`. So the pair reads as
 * one fact in two parts: the stage it reached, and that it is now shut.
 *
 * The outcome is rendered only for a closed enquiry with one to show. An enquiry
 * closed before the trail recorded its closure carries no `closed_from`, and the
 * badge falls back to the status alone rather than to an invented outcome.
 */
import { __ } from '@wordpress/i18n';

export default function StatusBadge( { status, closedFrom = '' } ) {
	const value = status || 'new';
	const outcome = 'closed' === value ? String( closedFrom || '' ) : '';

	return (
		<>
			<span className={ `meh-badge meh-badge--${ value }` }>{ value }</span>
			{ outcome && (
				<span
					className={ `meh-badge meh-badge--${ outcome } meh-badge--outcome` }
					title={ __(
						'The status this enquiry held when it was closed',
						'marthrown-enquiry-hub'
					) }
				>
					{ outcome }
				</span>
			) }
		</>
	);
}
