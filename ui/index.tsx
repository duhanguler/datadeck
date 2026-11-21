import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import './styles/datadeck.css';
import { App } from './workspace/App';

const boot = window.datadeckBootstrap;
if ( boot?.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( boot.nonce ) );
}

const container = document.getElementById( 'datadeck-root' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
