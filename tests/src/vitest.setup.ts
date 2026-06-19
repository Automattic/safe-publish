import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// Attach the admin-data shim to happy-dom's window rather than replacing
// `global.window` outright — replacing it wipes happy-dom's Element/Node
// prototypes and breaks anything that renders React.
( global as any ).window = ( global as any ).window || {};
( global as any ).window.safePublishAdminData = {
	ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
	nonce: 'test-nonce',
	sourceSiteUrl: 'https://example.com',
	settingsUrl: 'https://example.com/wp-admin/admin.php?page=safe-publish-settings',
	containerId: 'safe-publish-posts-container',
};

afterEach( () => {
	cleanup();
} );
