/**
 * The one warning shown before an unfinished note is discarded.
 *
 * Two controls can throw a half-written note away — the detail panel's own "Back
 * to list" and the side nav's view switch — and they live in different
 * components, so the question they ask lives here instead of being written twice
 * and drifting. The wording says what will be lost rather than asking "are you
 * sure": a user who has typed a note knows they typed it, and needs to be told
 * that leaving is what loses it.
 *
 * `window.confirm` rather than a modal on purpose. The decision has to be made
 * *before* the navigation happens, and a native confirm is the one dialog that
 * blocks until it is answered; a React modal would need the navigation held in
 * state and resumed from the callback, which is a lot of machinery for one
 * yes/no. It is also what a browser's own unload prompt looks like, which is the
 * thing users already recognise as "you are about to lose something".
 *
 * An environment with no `window.confirm` — a test renderer, a server render —
 * answers yes. Blocking navigation in a place that cannot ask would be a worse
 * failure than losing a note nobody typed.
 */
import { __ } from '@wordpress/i18n';

/**
 * Ask whether an unsaved note may be discarded.
 *
 * @return {boolean} True to proceed with leaving.
 */
export function confirmDiscardNote() {
	if ( typeof window === 'undefined' || 'function' !== typeof window.confirm ) {
		return true;
	}

	// eslint-disable-next-line no-alert
	return window.confirm(
		__(
			'This enquiry has a note you have not added yet. Leaving now will discard it.',
			'marthrown-enquiry-hub'
		)
	);
}
