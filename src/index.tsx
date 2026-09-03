/**
 * Cart Bridge JP admin app entry point.
 */
import { createRoot } from '@wordpress/element';
import { configureApiFetch } from './api';
import App from './App';
import './style.css';

configureApiFetch();

const container = document.getElementById( 'cbjp-admin-app' );

if ( container ) {
	createRoot( container ).render( <App /> );
}
