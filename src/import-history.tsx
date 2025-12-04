/**
 * Import History React application entry point.
 *
 * Initializes the Import History React component that displays a history of all
 * content import sessions.
 *
 * @file This file is the entry point for the Import History page.
 */
import React from 'react';
import { createRoot } from 'react-dom/client';

import { ImportHistory } from './components/ImportHistory';

import './style.scss';

/**
 * Initializes the Import History React application.
 *
 * Mounts the ImportHistory component to the container element when the DOM
 * content is loaded.
 */
document.addEventListener( 'DOMContentLoaded', (): void => {
	const container = document.getElementById( 'ccp-import-history-container' );

	if ( ! container ) {
		return;
	}

	// Clear any loading placeholder
	container.innerHTML = '';

	// Render the Import History component
	createRoot( container ).render( <ImportHistory /> );
} );
