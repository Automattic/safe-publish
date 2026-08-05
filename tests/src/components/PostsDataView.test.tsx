/**
 * Tests for PostsDataView search-to-chip routing.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { PostsDataView } from '@/components/PostsDataView';

// DataViews pulls in @wordpress/private-apis, which cannot unlock in the test
// env; it also only renders once rows exist. Stub it so mounting the container
// doesn't fault.
vi.mock( '@wordpress/dataviews', () => ( {
	DataViews: () => null,
	View: {},
} ) );

// The post-type selector fetches on mount and is orthogonal to search routing.
vi.mock( '@/post-type-selector', () => ( {
	PostTypeSelector: () => null,
} ) );

// Pin auth so the notice and downstream gating stay deterministic.
vi.mock( '@/components/hooks/useAuthStatus', () => ( {
	useAuthStatus: () => 'authorized',
} ) );

const SOURCE_URL = 'https://source.example.com';
const DEST_URL = 'https://destination.example.com';

let fetchMock: ReturnType< typeof vi.fn >;

/**
 * Returns the search input by its stable id; the doubled BaseControl/TextControl
 * label makes a role/name query ambiguous.
 */
function searchInput(): HTMLInputElement {
	const input = document.getElementById( 'safe-publish-search-input' );
	if ( ! ( input instanceof HTMLInputElement ) ) {
		throw new Error( 'search input not found' );
	}
	return input;
}

beforeEach( () => {
	// The destination host is the localized home_url(), distinct from both the
	// source host and happy-dom's window.location.origin.
	window.safePublishAdminData.homeUrl = DEST_URL;

	fetchMock = vi.fn().mockResolvedValue( {
		json: () =>
			Promise.resolve( {
				success: true,
				data: { items: [], has_more: false },
			} ),
	} );
	vi.stubGlobal( 'fetch', fetchMock );
} );

afterEach( () => {
	vi.unstubAllGlobals();
	delete window.safePublishAdminData.homeUrl;
} );

describe( 'PostsDataView URL search routing', () => {
	it( 'should hint to switch chips when a destination link is pasted on a catalog-primary chip', async () => {
		// ARRANGE: Mount on the default All chip (catalog-primary).
		render( <PostsDataView sourceSiteUrl={ SOURCE_URL } /> );

		// ACT: Paste a destination-host permalink; the debounce then routes it
		// by matching the home_url() host, not the browser origin.
		fireEvent.change( searchInput(), {
			target: { value: `${ DEST_URL }/2026/03/my-post/` },
		} );

		// ASSERT: The listing hints toward the destination chips rather than
		// running a doomed lookup on the wrong slug column.
		expect(
			await screen.findByText(
				'This looks like a destination link. Switch to Up to date or Outdated to find it.'
			)
		).toBeInTheDocument();
	} );

	it( 'should route a source link to a slug lookup on a catalog-primary chip', async () => {
		// ARRANGE: Mount on the default All chip (catalog-primary).
		render( <PostsDataView sourceSiteUrl={ SOURCE_URL } /> );

		// ACT: Paste a source-host permalink; the debounce then routes it.
		fireEvent.change( searchInput(), {
			target: { value: `${ SOURCE_URL }/2026/03/my-post/` },
		} );

		// ASSERT: The source slug is sent as an exact name= lookup.
		await waitFor( () => {
			const names = fetchMock.mock.calls.map( ( call ) =>
				( call[ 1 ] as { body: FormData } ).body.get( 'name' )
			);
			expect( names ).toContain( 'my-post' );
		} );

		// ASSERT: A matching chip shows no switch hint.
		expect( screen.queryByText( /Switch to/ ) ).not.toBeInTheDocument();
	} );
} );
