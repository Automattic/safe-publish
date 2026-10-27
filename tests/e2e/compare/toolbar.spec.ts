import { execFileSync } from 'node:child_process';

import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import type { Admin } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

// The width at which WCAG 1.4.10 (Reflow) requires content to fit without
// sideways scrolling.
const REFLOW_WIDTH = 320;

// A source-site failure long enough to need more than one line at the
// reflow width.
const LONG_ERROR =
	'Could not reach the source site: cURL error 28: Operation timed out ' +
	'after 10001 milliseconds with 0 bytes received.';

const titles: string[] = [];

/**
 * Runs a WP-CLI command on a wp-env site.
 *
 * @param {string}   site  'cli' for the destination, 'tests-cli' for the
 *                         source.
 * @param {string[]} args  WP-CLI arguments. wp-env drops their quoting, so
 *                         pass values with spaces through input instead.
 * @param {string}   input Standard input, such as post content.
 *
 * @return {string} The command's trimmed output.
 */
function wpCli(
	site: 'cli' | 'tests-cli',
	args: string[],
	input = ''
): string {
	return execFileSync( 'npx', [ 'wp-env', 'run', site, 'wp', ...args ], {
		encoding: 'utf8',
		input,
		stdio: 'pipe',
	} ).trim();
}

/**
 * Wraps HTML in a paragraph block.
 *
 * @param {string} html Paragraph content.
 *
 * @return {string} Block markup.
 */
function paragraph( html: string ): string {
	return `<!-- wp:paragraph -->\n<p>${ html }</p>\n<!-- /wp:paragraph -->`;
}

/**
 * Imports a new source post, changes it on the source, and opens Compare for
 * it on the destination at the given width.
 *
 * @param {Admin}  admin   Admin page utilities.
 * @param {Page}   page    The page.
 * @param {string} content The source post's block markup.
 * @param {number} width   Viewport width for Compare.
 */
async function openCompare(
	admin: Admin,
	page: Page,
	content: string,
	width: number
): Promise< void > {
	const title = `Compare-toolbar-${ Date.now() }`;
	titles.push( title );
	const sourceId = wpCli(
		'tests-cli',
		[
			'post',
			'create',
			'-',
			`--post_title=${ title }`,
			'--post_author=1',
			'--post_status=publish',
			'--porcelain',
		],
		content
	);

	await admin.visitAdminPage( 'admin.php', 'page=safe-publish' );
	const row = page.getByRole( 'row' ).filter( { hasText: title } );
	await row.getByRole( 'button', { name: 'Import' } ).click();
	await expect( page.getByText( /has been imported/ ) ).toBeVisible();

	// Freshness compares whole seconds, so the change must land in a later
	// second than the import to mark the row outdated.
	await page.waitForTimeout( 1000 );
	wpCli(
		'tests-cli',
		[ 'post', 'update', sourceId, '-' ],
		`${ content }\n\n${ paragraph( 'Changed on the source.' ) }`
	);

	// Narrow screens move Compare into the row's Actions menu. Resizing an
	// open modal would unmount it with the inline button.
	await page.setViewportSize( { width, height: 800 } );
	await page.reload();
	await row.getByRole( 'button', { name: 'Actions' } ).click();
	await page.getByRole( 'menuitem', { name: 'Compare' } ).click();
	await expect(
		page.locator( '.safe-publish-compare-modal__section' ).first()
	).toBeVisible();
}

/**
 * Measures how the Compare body and its toolbar controls fit the screen.
 *
 * @param {Page} page The page.
 *
 * @return {Promise<Object>} The body's sideways overflow in pixels, and the
 *                           names of controls spilling past their own box.
 */
function measureLayout(
	page: Page
): Promise< { overflow: number; spilled: string[] } > {
	return page.evaluate( () => {
		const body = document.querySelector( '.components-modal__content' )!;
		const controls = [
			...document.querySelector( '.safe-publish-compare-modal__toggles' )!
				.children,
		];
		const name = ( el: Element ) => ( el.textContent ?? '' ).trim();

		return {
			overflow: body.scrollWidth - body.clientWidth,
			spilled: controls
				.filter( ( el ) => el.scrollWidth > el.clientWidth + 1 )
				.map( name ),
		};
	} );
}

test.describe( 'Compare toolbar', () => {
	test.afterAll( () => {
		for ( const title of titles ) {
			for ( const site of [ 'cli', 'tests-cli' ] as const ) {
				const ids = wpCli( site, [
					'post',
					'list',
					'--post_type=post',
					'--post_status=any',
					`--title=${ title }`,
					'--format=ids',
				] );
				if ( '' !== ids ) {
					wpCli( site, [
						'post',
						'delete',
						...ids.split( ' ' ),
						'--force',
					] );
				}
			}
		}
	} );

	test( 'should fit a narrow screen', async ( { admin, page } ) => {
		// ARRANGE: An outdated import opened in Compare at the reflow width.
		await openCompare(
			admin,
			page,
			paragraph( 'Original paragraph.' ),
			REFLOW_WIDTH
		);
		const toolbar = page.locator( '.safe-publish-compare-modal__toggles' );
		await expect(
			toolbar.getByRole( 'button', { name: 'Update' } )
		).toBeVisible();

		// ACT: Measure the modal body and each toolbar control.
		const layout = await measureLayout( page );

		// ASSERT: The body does not scroll sideways beyond subpixel rounding,
		// and no control's label spills.
		expect( layout.overflow ).toBeLessThanOrEqual( 1 );
		expect( layout.spilled ).toEqual( [] );
	} );

	test( 'should wrap a long update error', async ( {
		admin,
		page,
	} ) => {
		// ARRANGE: Compare at the reflow width, with an update that fails
		// with a long message.
		await openCompare(
			admin,
			page,
			paragraph( 'Original paragraph.' ),
			REFLOW_WIDTH
		);
		await page.route( '**/admin-ajax.php', async ( route ) => {
			const body = route.request().postData() ?? '';
			if ( body.includes( 'safe_publish_create_draft' ) ) {
				await route.fulfill( {
					json: { success: false, data: { message: LONG_ERROR } },
				} );
				return;
			}
			await route.continue();
		} );
		const toolbar = page.locator( '.safe-publish-compare-modal__toggles' );

		// ACT: Submit the update, and measure once the error shows.
		await toolbar.getByRole( 'button', { name: 'Update' } ).click();
		await expect( toolbar.getByRole( 'alert' ) ).toHaveText( LONG_ERROR );
		const layout = await measureLayout( page );

		// ASSERT: The error wraps instead of widening the body.
		expect( layout.overflow ).toBeLessThanOrEqual( 1 );
	} );

	test( 'should keep backward focus out from behind the toolbar', async ( {
		admin,
		page,
	} ) => {
		// ARRANGE: Compare at the reflow width, where the sticky toolbar wraps
		// onto several rows, over a column of links.
		const links = Array.from( { length: 12 }, ( _, i ) =>
			paragraph( `<a href="https://example.com/${ i }">Link ${ i }</a>` )
		).join( '\n\n' );
		await openCompare( admin, page, links, REFLOW_WIDTH );
		await page.getByRole( 'checkbox', { name: 'Show unchanged' } ).check();
		const toolbar = page.locator( '.safe-publish-compare-modal__toggles' );
		const link = ( i: number ) =>
			page.getByRole( 'link', { name: `Link ${ i }`, exact: true } );

		// ACT: Move focus backward from the last link, which scrolls each
		// earlier link in from above, and record the links the toolbar
		// covers completely.
		await link( 11 ).focus();
		const hidden: string[] = [];
		for ( let i = 10; i >= 0; i-- ) {
			await page.keyboard.press( 'Shift+Tab' );
			await expect( link( i ) ).toBeFocused();
			const bar = ( await toolbar.boundingBox() )!;
			const box = ( await link( i ).boundingBox() )!;
			if ( box.y >= bar.y && box.y + box.height <= bar.y + bar.height ) {
				hidden.push( `Link ${ i }` );
			}
		}

		// ASSERT: No focused link sits entirely behind the toolbar (WCAG 2.2
		// SC 2.4.11, Focus Not Obscured).
		expect( hidden ).toEqual( [] );
	} );
} );
