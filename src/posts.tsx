/**
 * Unified Posts admin entry point.
 *
 * Mounts the PostsDataView into the dashboard container when the DOM is
 * ready. The component owns its fetch lifecycle (chip-routed via
 * `safe_publish_list_posts`).
 *
 * @file This file is the entry point for the unified Posts admin page.
 */
import { createRoot } from 'react-dom/client';

import { PostsDataView } from './components/PostsDataView';

import './style.scss';

document.addEventListener( 'DOMContentLoaded', (): void => {
	const container = document.getElementById(
		window.safePublishAdminData?.containerId
			?? 'safe-publish-posts-container'
	);

	if ( ! container ) {
		return;
	}

	const sourceSiteUrl = window.safePublishAdminData?.sourceSiteUrl || '';

	container.innerHTML = '';

	createRoot( container ).render(
		<PostsDataView sourceSiteUrl={ sourceSiteUrl } />
	);
} );
