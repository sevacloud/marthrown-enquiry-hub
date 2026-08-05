/**
 * Entry point: configures api-fetch and mounts the React app.
 */
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './App';
import './index.scss';

const data = window.mehData || {};

// Route relative paths through our REST namespace and attach the nonce so the
// same bundle works in wp-admin and on the front-end /bookings page.
if ( data.root ) {
	const root = data.root.endsWith( '/' ) ? data.root : data.root + '/';
	apiFetch.use( apiFetch.createRootURLMiddleware( root ) );
}
if ( data.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( data.nonce ) );
}

const mount = document.getElementById( 'enquiry-hub-root' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
