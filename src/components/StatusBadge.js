/**
 * StatusBadge — small coloured label for an enquiry status.
 */
export default function StatusBadge( { status } ) {
	const value = status || 'new';
	return (
		<span className={ `meh-badge meh-badge--${ value }` }>{ value }</span>
	);
}
