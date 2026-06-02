/**
 * Exports page entry point.
 *
 * Mounts the ExportHistory component into the Exports container when the DOM
 * is ready. The component fetches export events from `safe_publish_get_export_events`.
 *
 * @file This file is the entry point for the Exports admin page.
 */
import { createRoot } from 'react-dom/client';

import { ExportHistory } from './components/ExportHistory';

import './style.scss';

document.addEventListener( 'DOMContentLoaded', (): void => {
	const container = document.getElementById( 'safe-publish-exports-container' );

	if ( ! container ) {
		return;
	}

	container.innerHTML = '';

	createRoot( container ).render( <ExportHistory /> );
} );
