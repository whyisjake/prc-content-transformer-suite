/**
 * Apple News Settings — admin page entry point.
 *
 * Mounts the React app into the #prc-apple-news-settings container
 * that is rendered by Settings::render_page() in PHP.
 */

import { createRoot } from '@wordpress/element';
import SettingsApp from './app';

document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'prc-apple-news-settings' );
	if ( container ) {
		createRoot( container ).render( <SettingsApp /> );
	}
} );
