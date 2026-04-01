/**
 * History React application entry point.
 *
 * Initializes the History React component that displays import and/or export
 * history in tabs. Which tabs are shown depends on the site's sync mode and
 * whether historical data exists from a previous mode.
 *
 * @file This file is the entry point for the History page.
 */
import { createRoot } from 'react-dom/client';


import { ExportHistory } from './components/ExportHistory';
import { ImportHistory } from './components/ImportHistory';
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import './style.scss';

/**
 * Initializes the History React application.
 *
 * Mounts the history tab panel to the container element when the DOM
 * content is loaded.
 */
document.addEventListener( 'DOMContentLoaded', (): void => {
	const container = document.getElementById( 'safe-publish-history-container' );

	if ( ! container ) {
		return;
	}

	// Clear any loading placeholder.
	container.innerHTML = '';

	const showImport = window.safePublishAdminData.showImportHistory !== false;
	const showExport = window.safePublishAdminData.showExportHistory !== false;

	if ( showImport && showExport ) {
		createRoot( container ).render(
			<TabPanel
				tabs={ [
					{ name: 'import', title: __( 'Import History', 'safe-publish' ) },
					{ name: 'export', title: __( 'Export History', 'safe-publish' ) },
				] }
			>
				{ ( tab ) => (
					'import' === tab.name ? <ImportHistory /> : <ExportHistory />
				) }
			</TabPanel>
		);
	} else if ( showImport ) {
		createRoot( container ).render( <ImportHistory /> );
	} else {
		createRoot( container ).render( <ExportHistory /> );
	}
} );
