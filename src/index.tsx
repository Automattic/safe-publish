/**
 * Source posts DataViews entry point.
 *
 * Mounts the SourcePostsDataView component into the dashboard container
 * when the DOM is ready, seeding it from the localized admin data
 * exposed on `window.safePublishAdminData`.
 *
 * @file This file is the entry point for the source posts dashboard.
 */
import { createRoot } from 'react-dom/client';

import { SourcePostsDataView } from './components/SourcePostsDataView';
import { sanitizePosts } from './utils';
import { __ } from '@wordpress/i18n';

import type { Post } from './types';

import './style.scss';

/**
 * Initializes the source posts DataViews on page load.
 */
document.addEventListener( 'DOMContentLoaded', (): void => {
	const dataviewContainer = document.getElementById( 'safe-publish-dataviews-container' );

	if ( ! dataviewContainer ) {
		return;
	}

	const sourceSiteUrl = window.safePublishAdminData?.sourceSiteUrl || '';
	const numberPosts = window.safePublishAdminData?.numPosts ?? 0;

	// Get posts data from localized script.
	let initialPosts: Post[] = [];
	try {
		if ( window.safePublishAdminData && window.safePublishAdminData.postsData ) {
			initialPosts = sanitizePosts( window.safePublishAdminData.postsData );
		}
	} catch ( error ) {
		dataviewContainer.innerHTML = `<p class="safe-publish-error-message">${ __( 'Failed to load posts data.', 'safe-publish' ) }</p>`;
		return;
	}

	// Clear container and render DataViews.
	dataviewContainer.innerHTML = '';

	createRoot( dataviewContainer ).render(
		<SourcePostsDataView
			initialPosts={ initialPosts }
			sourceSiteUrl={ sourceSiteUrl }
			numberPosts={ numberPosts }
		/>
	);
} );
