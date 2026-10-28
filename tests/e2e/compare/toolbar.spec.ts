import { execFile } from 'node:child_process';

import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import type { Admin } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

// The width at which WCAG 1.4.10 (Reflow) requires content to fit without
// sideways scrolling, and the height that goes with it: a 1280×1024 window
// at 400% zoom.
const REFLOW_WIDTH = 320;
const REFLOW_HEIGHT = 256;

// A source-site failure long enough to need more than one line at the
// reflow width.
const LONG_ERROR =
	'Could not reach the source site: cURL error 28: Operation timed out ' +
	'after 10001 milliseconds with 0 bytes received.';

const titles: string[] = [];

/**
 * Runs a WP-CLI command on a wp-env site. It runs asynchronously, so a slow
 * command can't hold a test past its timeout.
 *
 * @param {string}   site  'cli' for the destination, 'tests-cli' for the
 *                         source.
 * @param {string[]} args  WP-CLI arguments. wp-env drops their quoting, so
 *                         pass values with spaces through input instead.
 * @param {string}   input Standard input, such as post content.
 *
 * @return {Promise<string>} The command's trimmed output.
 */
function wpCli(
	site: 'cli' | 'tests-cli',
	args: string[],
	input = ''
): Promise< string > {
	return new Promise( ( resolve, reject ) => {
		const child = execFile(
			'npx',
			[ 'wp-env', 'run', site, 'wp', ...args ],
			( error, stdout ) =>
				error ? reject( error ) : resolve( stdout.trim() )
		);
		child.stdin?.end( input );
	} );
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

// A column of linked paragraphs, tall enough to scroll at the reflow width.
const LINKS = Array.from( { length: 12 }, ( _, i ) =>
	paragraph( `<a href="https://example.com/${ i }">Link ${ i }</a>` )
).join( '\n\n' );

/**
 * Imports a new source post, changes it on the source, and opens Compare for
 * it on the destination at the given viewport size.
 *
 * @param {Admin}  admin   Admin page utilities.
 * @param {Page}   page    The page.
 * @param {string} content The source post's block markup.
 * @param {number} width   Viewport width for Compare.
 * @param {number} height  Viewport height for Compare.
 */
async function openCompare(
	admin: Admin,
	page: Page,
	content: string,
	width: number,
	height = 800
): Promise< void > {
	const title = `Compare-toolbar-${ Date.now() }`;
	titles.push( title );
	const sourceId = await wpCli(
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
	await wpCli(
		'tests-cli',
		[ 'post', 'update', sourceId, '-' ],
		`${ content }\n\n${ paragraph( 'Changed on the source.' ) }`
	);

	// Narrow screens move Compare into the row's Actions menu. Resizing an
	// open modal would unmount it with the inline button.
	await page.setViewportSize( { width, height } );
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
	test.afterAll( async () => {
		for ( const title of titles ) {
			for ( const site of [ 'cli', 'tests-cli' ] as const ) {
				const ids = await wpCli( site, [
					'post',
					'list',
					'--post_type=post',
					'--post_status=any',
					`--title=${ title }`,
					'--format=ids',
				] );
				if ( '' !== ids ) {
					await wpCli( site, [
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
		// ARRANGE: An outdated import opened in Compare at the reflow size.
		await openCompare(
			admin,
			page,
			paragraph( 'Original paragraph.' ),
			REFLOW_WIDTH,
			REFLOW_HEIGHT
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

	// At the reflow size the toolbar scrolls away with the diff. On a taller
	// screen it sticks.
	for ( const height of [ REFLOW_HEIGHT, 800 ] ) {
		test( `should keep backward focus visible ${ height }px tall`, async ( {
			admin,
			page,
		} ) => {
			// ARRANGE: Compare at the reflow width, where the toolbar wraps
			// onto several rows, over a column of links.
			await openCompare( admin, page, LINKS, REFLOW_WIDTH, height );
			await page
				.getByRole( 'checkbox', { name: 'Show unchanged' } )
				.check();
			const scroller = page.locator( '.components-modal__content' );
			const toolbar = page.locator(
				'.safe-publish-compare-modal__toggles'
			);
			const link = ( i: number ) =>
				page.getByRole( 'link', { name: `Link ${ i }`, exact: true } );

			// ACT: Move focus backward from the last link, scrolling to the end
			// of the diff first so that each earlier link scrolls in from
			// above, and record the links that don't show in full between the
			// toolbar and the bottom of the scroll area.
			await link( 11 ).focus();
			const obscured: string[] = [];
			for ( let i = 10; i >= 0; i-- ) {
				await scroller.evaluate( ( el ) =>
					el.scrollTo( 0, el.scrollHeight )
				);
				await page.keyboard.press( 'Shift+Tab' );
				await expect( link( i ) ).toBeFocused();
				const area = ( await scroller.boundingBox() )!;
				const bar = ( await toolbar.boundingBox() )!;
				const box = ( await link( i ).boundingBox() )!;
				const top = Math.max( area.y, bar.y + bar.height );
				// Allow 1px of subpixel rounding at either edge.
				if (
					box.y < top - 1 ||
					box.y + box.height > area.y + area.height + 1
				) {
					obscured.push( `Link ${ i }` );
				}
			}

			// ASSERT: Every focused link shows in full (WCAG 2.2 SC 2.4.12,
			// Focus Not Obscured (Enhanced)).
			expect( obscured ).toEqual( [] );
		} );
	}

	test( 'should keep the diff still on Tab in the toolbar', async ( {
		admin,
		page,
	} ) => {
		// ARRANGE: Compare scrolled down the diff, with focus on the toolbar's
		// first toggle.
		await openCompare( admin, page, LINKS, REFLOW_WIDTH );
		await page.getByRole( 'checkbox', { name: 'Show unchanged' } ).check();
		const scroller = page.locator( '.components-modal__content' );
		const scrollTop = () => scroller.evaluate( ( el ) => el.scrollTop );
		await scroller.evaluate( ( el ) => el.scrollTo( 0, 600 ) );
		await page
			.getByRole( 'checkbox', { name: 'Show labels' } )
			.evaluate( ( el: HTMLElement ) =>
				el.focus( { preventScroll: true } )
			);
		const before = await scrollTop();

		// ACT: Tab through the rest of the toolbar to Update.
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Tab' );
		await expect(
			page.getByRole( 'button', { name: 'Update' } )
		).toBeFocused();

		// ASSERT: The diff was scrolled, and the toolbar's own controls
		// didn't move it.
		expect( before ).toBeGreaterThan( 0 );
		expect( await scrollTop() ).toBe( before );
	} );
} );
