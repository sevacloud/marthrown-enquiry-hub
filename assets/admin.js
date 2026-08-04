/**
 * Marthrown Enquiry Hub — admin dashboard behaviour.
 *
 * The dashboard is a filterable/sortable table rendered server-side. This
 * script only provides light client-side conveniences.
 */
( function () {
	'use strict';

	// Auto-submit the filter form when the source dropdown changes.
	document.addEventListener( 'DOMContentLoaded', function () {
		var sourceSelect = document.getElementById( 'meh-source' );
		if ( sourceSelect ) {
			sourceSelect.addEventListener( 'change', function () {
				if ( sourceSelect.form ) {
					sourceSelect.form.submit();
				}
			} );
		}
	} );
} )();
