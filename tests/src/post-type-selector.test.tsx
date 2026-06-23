/**
 * Tests for the PostTypeSelector dropdown options.
 *
 * The selector lists whatever the source catalog returns, minus the types we
 * gate out client-side. wp_navigation import has known gaps, so this guards
 * that it stays out of the dropdown until they are resolved.
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { render, waitFor } from '@testing-library/react';

import { PostTypeSelector } from '@/post-type-selector';

/**
 * Stubs fetch with a successful fetch_post_types envelope.
 */
function mockPostTypesResponse(
	postTypes: Array< Record< string, string > >
): void {
	vi.mocked( fetch ).mockResolvedValue(
		new Response( JSON.stringify( { success: true, data: postTypes } ), {
			status: 200,
		} )
	);
}

/**
 * Collects the rendered <option> labels from a selector container.
 */
function optionLabels( container: HTMLElement ): Array< string | null > {
	return Array.from( container.querySelectorAll( 'option' ) ).map(
		option => option.textContent
	);
}

describe( 'PostTypeSelector options', () => {
	beforeEach( () => {
		vi.stubGlobal( 'fetch', vi.fn() );
	} );

	afterEach( () => {
		vi.unstubAllGlobals();
		vi.restoreAllMocks();
	} );

	it( 'Verifies that hidden post types are filtered out of the options', async () => {
		// ARRANGE: the source returns post, page, and the gated wp_navigation.
		mockPostTypesResponse( [
			{ slug: 'post', name: 'Posts', label: 'Posts', rest_base: 'posts' },
			{ slug: 'page', name: 'Pages', label: 'Pages', rest_base: 'pages' },
			{
				slug: 'wp_navigation',
				name: 'Navigation Menus',
				label: 'Navigation Menus',
				rest_base: 'navigation',
			},
		] );

		// ACT: render and wait for the catalog fetch to populate the options.
		const { container } = render(
			<PostTypeSelector sourceSiteUrl="https://example.com" />
		);
		await waitFor( () =>
			expect( optionLabels( container ) ).toContain( 'Posts' )
		);

		// ASSERT: non-hidden types remain; wp_navigation is gone.
		const labels = optionLabels( container );
		expect( labels ).toContain( 'Pages' );
		expect( labels ).not.toContain( 'Navigation Menus' );
	} );
} );
