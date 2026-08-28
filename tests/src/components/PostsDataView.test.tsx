/**
 * Tests for PostsDataView search-to-chip routing and source-error notices.
 */
import { useEffect } from '@wordpress/element';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';

import { PostsDataView } from '@/components/PostsDataView';

import type { DataViewsField, UnifiedPostRow } from '@/types';

const dataViews = vi.hoisted( () => ( {
	props: null as {
		data: UnifiedPostRow[];
		fields: DataViewsField< UnifiedPostRow >[];
		config?: { perPageSizes: number[] };
		header?: JSX.Element;
	} | null,
} ) );

// DataViews pulls in @wordpress/private-apis, which cannot unlock in the test
// env; it also only renders once rows exist. Stub it so mounting the container
// doesn't fault, rendering only the header it is handed.
vi.mock( '@wordpress/dataviews', () => ( {
	DataViews: ( props: NonNullable< typeof dataViews.props > ) => {
		dataViews.props = props;
		return props.header ?? null;
	},
	View: {},
} ) );

const selector = vi.hoisted( () => ( {
	error: null as string | null,
} ) );

// Stub the selector and drive postTypeError through onError to exercise the
// notice gating without a network call.
vi.mock( '@/post-type-selector', () => ( {
	PostTypeSelector: ( {
		onError,
	}: {
		onError?: ( error: string | null ) => void;
	} ) => {
		useEffect( () => {
			onError?.( selector.error );
		}, [ onError ] );
		return null;
	},
} ) );

// Pin auth so the notice and downstream gating stay deterministic.
vi.mock( '@/components/hooks/useAuthStatus', () => ( {
	useAuthStatus: () => 'authorized',
} ) );

const SOURCE_URL = 'https://source.example.com';
const DEST_URL = 'https://destination.example.com';

// The 503 string both AJAX paths surface verbatim.
const HTTP_503 = 'Source site returned HTTP error 503.';

let fetchMock: ReturnType< typeof vi.fn >;

/**
 * Returns the source-error notices; both share the layout class.
 */
function errorNotices(): NodeListOf< Element > {
	return document.querySelectorAll( '.safe-publish-source-error' );
}

/**
 * Defers the list fetch so a test can settle its error after the post-type
 * error is already shown.
 */
function deferListFetch(): ( data: unknown ) => Promise< void > {
	let resolve: ( value: { json: () => Promise< unknown > } ) => void;
	fetchMock.mockReturnValue(
		new Promise( ( r ) => {
			resolve = r;
		} )
	);
	return async ( data ) => {
		await act( async () => {
			resolve( { json: () => Promise.resolve( data ) } );
			// Drain the response.json() → setState microtask chain.
			await new Promise( ( r ) => setTimeout( r, 0 ) );
		} );
	};
}

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
	selector.error = null;
	dataViews.props = null;

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

describe( 'PostsDataView fields', () => {
	beforeEach( () => {
		fetchMock.mockResolvedValue( {
			json: () =>
				Promise.resolve( {
					success: true,
					data: {
						items: [
							{
								id: 10,
								source_post_id: 10,
								title: 'Source post',
								link: `${ SOURCE_URL }/source-post/`,
								date_gmt: '2026-08-20T00:00:00Z',
								modified_gmt: '2026-08-20T00:00:00Z',
								post_type: 'post',
								status: 'publish',
								local_state: 'available',
								is_imported: false,
								wp_post_status: null,
								item_id: null,
								post_id: null,
								import_date_gmt: null,
								has_previous_content: false,
								edit_url: '',
							},
						],
						has_more: false,
					},
				} ),
		} );
	} );

	it( 'Verifies that source post titles render without links', async () => {
		// ARRANGE: Mount the listing and capture the title field DataViews gets.
		render( <PostsDataView sourceSiteUrl={ SOURCE_URL } /> );
		await waitFor( () => expect( dataViews.props?.data ).toHaveLength( 1 ) );
		const titleField = dataViews.props?.fields.find(
			( field ) => 'title' === field.id
		);
		expect( titleField?.render ).toBeDefined();

		// ACT: Render the title cell for the source post the catalog returned.
		const { container } = render(
			titleField?.render?.( {
				item: dataViews.props?.data[ 0 ] as UnifiedPostRow,
			} )
		);

		// ASSERT: The title remains visible without linking to the source site.
		expect( container ).toHaveTextContent( 'Source post' );
		expect( container.querySelector( 'a' ) ).toBeNull();
	} );

	it( 'Verifies that available source posts are labeled Not imported', async () => {
		// ARRANGE: Mount the listing and capture the local-state field.
		render( <PostsDataView sourceSiteUrl={ SOURCE_URL } /> );
		await waitFor( () => expect( dataViews.props?.data ).toHaveLength( 1 ) );
		const localStateField = dataViews.props?.fields.find(
			( field ) => 'local_state' === field.id
		);
		expect( localStateField?.render ).toBeDefined();

		// ACT: Render the local-state cell and open the search help popover.
		const { container } = render(
			localStateField?.render?.( {
				item: dataViews.props?.data[ 0 ] as UnifiedPostRow,
			} )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Search help' } ) );

		// ASSERT: Filter option, row cell, and search help share the wording.
		expect(
			screen.getByRole( 'option', { name: 'Not imported' } )
		).toBeInTheDocument();
		expect( container ).toHaveTextContent( 'Not imported' );
		expect(
			screen.getByText( /Source links match on All and Not imported/ )
		).toBeInTheDocument();
	} );

	it( 'Verifies that a loading refresh uses accessible disabled semantics', async () => {
		// ARRANGE: A loaded listing whose next request stays in flight.
		render( <PostsDataView sourceSiteUrl={ SOURCE_URL } /> );
		const refresh = await screen.findByRole( 'button', {
			name: 'Refresh',
		} );
		fetchMock.mockReturnValue( new Promise( () => {} ) );

		// ACT: Start a refresh.
		fireEvent.click( refresh );

		// ASSERT: The action uses aria-disabled instead of native disabled.
		await waitFor( () =>
			expect( refresh ).toHaveAttribute( 'aria-disabled', 'true' )
		);
		expect( refresh ).not.toHaveAttribute( 'disabled' );
	} );

	it( 'Verifies that the largest page size matches the bulk request cap', async () => {
		// ARRANGE: Mount a listing with the DataViews view controls.
		render( <PostsDataView sourceSiteUrl={ SOURCE_URL } /> );

		// ACT: Wait for DataViews to receive the listing configuration.
		await waitFor( () => expect( dataViews.props ).not.toBeNull() );

		// ASSERT: A user cannot show and select more than 50 rows at once.
		expect( dataViews.props?.config?.perPageSizes ).toEqual( [ 10, 20, 50 ] );
	} );
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

describe( 'PostsDataView source-error notices', () => {
	it( 'should collapse to one notice when both paths surface the identical error', async () => {
		// ARRANGE: The post-type call reports a 503; the list call is deferred
		// so its identical error settles after the post-type error is shown.
		selector.error = HTTP_503;
		const resolveList = deferListFetch();

		// ACT: Mount on the default catalog chip, then settle the list 503.
		render( <PostsDataView sourceSiteUrl={ SOURCE_URL } /> );
		await waitFor( () => expect( errorNotices() ).toHaveLength( 1 ) );
		await resolveList( { success: false, data: HTTP_503 } );

		// ASSERT: The duplicate is gated out, leaving a single banner.
		expect( errorNotices() ).toHaveLength( 1 );
	} );

	it( 'should keep both notices when the two errors genuinely differ', async () => {
		// ARRANGE: Post-type call 503s; the list call fails with a distinct
		// message.
		selector.error = HTTP_503;
		fetchMock.mockResolvedValue( {
			json: () =>
				Promise.resolve( {
					success: false,
					data: 'Source response was too large.',
				} ),
		} );

		// ACT: Mount on the default catalog chip; both calls error differently.
		render( <PostsDataView sourceSiteUrl={ SOURCE_URL } /> );

		// ASSERT: Distinct errors stack as two banners. Scope the text check to
		// the notices; Notice also mirrors its message into an a11y live region.
		await waitFor( () => expect( errorNotices() ).toHaveLength( 2 ) );
		const texts = Array.from( errorNotices() ).map(
			( notice ) => notice.textContent ?? ''
		);
		expect( texts.some( ( text ) => text.includes( HTTP_503 ) ) ).toBe(
			true
		);
		expect(
			texts.some( ( text ) =>
				text.includes( 'Source response was too large.' )
			)
		).toBe( true );
	} );

	it( 'should clear both states on dismiss so the duplicate cannot reappear', async () => {
		// ARRANGE: The identical-error case, with the surviving notice being
		// the fetch-error one that carries the coupling.
		selector.error = HTTP_503;
		const resolveList = deferListFetch();
		render( <PostsDataView sourceSiteUrl={ SOURCE_URL } /> );
		await waitFor( () => expect( errorNotices() ).toHaveLength( 1 ) );
		await resolveList( { success: false, data: HTTP_503 } );
		expect( errorNotices() ).toHaveLength( 1 );

		// ACT: Dismiss the visible banner.
		const dismiss = errorNotices()[ 0 ].querySelector( 'button' );
		expect( dismiss ).not.toBeNull();
		await act( async () => {
			fireEvent.click( dismiss as HTMLButtonElement );
		} );

		// ASSERT: No banner remains; the suppressed twin does not resurface.
		expect( errorNotices() ).toHaveLength( 0 );
	} );
} );
