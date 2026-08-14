import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'plugin activation', () => {
	test( 'should have the "Safe Publish" menu item in sidebar', async ( { admin, page } ) => {
		await admin.visitAdminPage( '/' );

		const safePublishMenu = page.locator(
			'#toplevel_page_safe-publish'
		);
		await expect( safePublishMenu ).toBeVisible();
	} );
} );
