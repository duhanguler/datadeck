import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import './styles/rowcraft.css';
import { App } from './workspace/App';

const boot = window.rowcraftBootstrap;
if ( boot?.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( boot.nonce ) );
}

const container = document.getElementById( 'rowcraft-root' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
