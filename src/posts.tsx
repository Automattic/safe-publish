/**
 * Unified Posts admin entry point.
 *
 * Mounts the Manage tabs (Posts listing and Needs attention inbox) into the
 * dashboard container when the DOM is ready.
 *
 * @file This file is the entry point for the unified Posts admin page.
 */
import { createRoot } from 'react-dom/client';

import { ManageTabs } from './components/ManageTabs';

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
		<ManageTabs sourceSiteUrl={ sourceSiteUrl } />
	);
} );
