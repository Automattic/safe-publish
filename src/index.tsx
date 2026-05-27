/**
 * Source posts DataViews entry point.
 *
 * Mounts the SourcePostsDataView component into the dashboard container
 * when the DOM is ready. The component fetches the first page from the
 * source catalog endpoint itself; PHP no longer hydrates initial data.
 *
 * @file This file is the entry point for the source posts dashboard.
 */
import { createRoot } from 'react-dom/client';

import { SourcePostsDataView } from './components/SourcePostsDataView';

import './style.scss';

document.addEventListener( 'DOMContentLoaded', (): void => {
	const dataviewContainer = document.getElementById( 'safe-publish-dataviews-container' );

	if ( ! dataviewContainer ) {
		return;
	}

	const sourceSiteUrl = window.safePublishAdminData?.sourceSiteUrl || '';

	dataviewContainer.innerHTML = '';

	createRoot( dataviewContainer ).render(
		<SourcePostsDataView sourceSiteUrl={ sourceSiteUrl } />
	);
} );
