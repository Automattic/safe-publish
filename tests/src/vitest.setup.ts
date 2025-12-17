import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// Mock WordPress global objects.
( global as any ).window = {
	ccpAdminData: {
		ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		restNonce: 'test-rest-nonce',
		siteUrl: 'https://example.com',
		numPosts: 10,
		containerId: 'ccp-admin-posts',
		postsData: [],
		strings: {
			loading: 'Loading...',
			error: 'Error',
			noResults: 'No results found',
			title: 'Title',
			lastModified: 'Last Modified',
			link: 'Link',
		},
	},
};

afterEach( () => {
	cleanup();
} );
