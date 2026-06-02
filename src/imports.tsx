/**
 * Imports page entry point.
 *
 * Mounts the tabbed ImportsApp into the Imports container when the DOM is
 * ready. The Posts tab lists imported posts via `safe_publish_list_imported_posts`;
 * the Failures tab lists items that errored via `safe_publish_list_failed_imports`.
 *
 * @file This file is the entry point for the Imports admin page.
 */
import { createRoot } from 'react-dom/client';

import { ImportsApp } from './components/ImportsApp';

import './style.scss';

document.addEventListener( 'DOMContentLoaded', (): void => {
	const container = document.getElementById( 'safe-publish-imports-container' );

	if ( ! container ) {
		return;
	}

	container.innerHTML = '';

	createRoot( container ).render( <ImportsApp /> );
} );
