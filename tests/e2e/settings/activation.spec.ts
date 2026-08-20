import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'plugin activation', () => {
	test( 'should have the "Safe Publish" menu item in sidebar', async ( { admin, page } ) => {
		await admin.visitAdminPage( '/' );

		const safePublishMenu = page
			.locator( '#adminmenu' )
			.getByRole( 'link', { name: 'Safe Publish', exact: true } )
			.first();
		await expect( safePublishMenu ).toBeVisible();
	} );

	test( 'should load the Manage screen without JavaScript errors', async ( {
		admin,
		page,
	} ) => {
		const pageErrors: string[] = [];
		page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

		await admin.visitAdminPage( 'admin.php', 'page=safe-publish' );

		await expect(
			page.getByRole( 'tab', { name: 'Posts', exact: true } )
		).toBeVisible();
		expect( pageErrors ).toEqual( [] );
	} );
} );
