/**
 * Import History React application entry point
 */
import React from 'react';
import { createRoot } from 'react-dom/client';

import { ImportHistory } from './components/ImportHistory';

import './style.scss';

/**
 * Initialize the Import History React application
 */
document.addEventListener( 'DOMContentLoaded', (): void => {
	const container = document.getElementById( 'ccp-import-history-container' );

	if ( ! container ) {
		return;
	}

	// Clear any loading placeholder.
	container.innerHTML = '';

	// Render the Import History component.
	createRoot( container ).render( <ImportHistory /> );
} );
