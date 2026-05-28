/**
 * Imported Posts page entry point.
 *
 * Mounts the ImportedPostsDataView component into the Imported Posts
 * container when the DOM is ready. The component fetches the listing from
 * the destination-side `safe_publish_list_imported_posts` AJAX action.
 *
 * @file This file is the entry point for the Imported Posts admin page.
 */
import { createRoot } from 'react-dom/client';

import { ImportedPostsDataView } from './components/ImportedPostsDataView';

import './style.scss';

document.addEventListener( 'DOMContentLoaded', (): void => {
	const container = document.getElementById( 'safe-publish-imported-container' );

	if ( ! container ) {
		return;
	}

	container.innerHTML = '';

	createRoot( container ).render( <ImportedPostsDataView /> );
} );
