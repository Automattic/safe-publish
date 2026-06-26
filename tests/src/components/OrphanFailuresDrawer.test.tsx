/**
 * Tests for the OrphanFailuresDrawer component.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import OrphanFailuresDrawer from '@/components/OrphanFailuresDrawer';
import type { OrphanFailure } from '@/types';

// DataViews pulls in @wordpress/private-apis, which cannot unlock in the test
// env. Stub it with a minimal renderer that exercises each field's render so
// the row mapping is still asserted, plus a next-page control that drives
// onChangeView for the pagination test.
vi.mock( '@wordpress/dataviews', () => ( {
	DataViews: ( {
		data,
		fields,
		view,
		onChangeView,
	}: {
		data: OrphanFailure[];
		fields: Array< {
			id: string;
			render?: ( arg: { item: OrphanFailure } ) => JSX.Element;
		} >;
		view: { page?: number };
		onChangeView: ( next: { page?: number } ) => void;
	} ): JSX.Element => (
		<div>
			<button
				type="button"
				onClick={ () =>
					onChangeView( { ...view, page: ( view.page ?? 1 ) + 1 } )
				}
			>
				next-page
			</button>
			{ data.map( ( item ) => (
				<div key={ item.id }>
					{ fields.map( ( field ) => (
						<span key={ field.id }>
							{ field.render ? field.render( { item } ) : null }
						</span>
					) ) }
				</div>
			) ) }
		</div>
	),
} ) );

const FAILURE: OrphanFailure = {
	id: 4096,
	session_id: 12,
	title: 'Orphaned draft',
	source_site_url: 'https://source.example.com',
	error_message: 'Source post could not be resolved.',
	import_date_gmt: '2024-03-15 10:30:00',
};

/**
 * Stubs fetch to return the given failures from the list endpoint.
 */
function mockListResponse( items: OrphanFailure[] ): void {
	vi.stubGlobal(
		'fetch',
		vi.fn().mockResolvedValue( {
			json: () =>
				Promise.resolve( {
					success: true,
					data: { items, has_more: false },
				} ),
		} )
	);
}

afterEach( () => {
	vi.unstubAllGlobals();
} );

describe( 'OrphanFailuresDrawer', () => {
	it( 'renders the listed failures', async () => {
		// ARRANGE: the list endpoint returns one failure.
		mockListResponse( [ FAILURE ] );

		// ACT: render the drawer.
		render(
			<OrphanFailuresDrawer
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
				onClose={ () => undefined }
			/>
		);

		// ASSERT: the title and error message appear.
		expect( await screen.findByText( 'Orphaned draft' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Source post could not be resolved.' )
		).toBeInTheDocument();
	} );

	it( 'shows an empty state when there are no failures', async () => {
		// ARRANGE: the endpoint returns no failures.
		mockListResponse( [] );

		// ACT: render the drawer.
		render(
			<OrphanFailuresDrawer
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
				onClose={ () => undefined }
			/>
		);

		// ASSERT: the empty-state copy appears.
		expect(
			await screen.findByText( 'No orphan failures.' )
		).toBeInTheDocument();
	} );

	it( 'steps back to the prior page when a trailing page empties', async () => {
		// ARRANGE: page 1 holds a failure; any later page comes back empty, as
		// it would once the trailing page's failures are removed.
		const fetchMock = vi
			.fn()
			.mockImplementation(
				( _url: string, options: { body: FormData } ) =>
					Promise.resolve( {
						json: () =>
							Promise.resolve( {
								success: true,
								data: {
									items:
										'1' === options.body.get( 'page' )
											? [ FAILURE ]
											: [],
									has_more: false,
								},
							} ),
					} )
			);
		vi.stubGlobal( 'fetch', fetchMock );

		// ACT: land on page 1, then page into the now-empty page 2.
		render(
			<OrphanFailuresDrawer
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
				onClose={ () => undefined }
			/>
		);
		await screen.findByText( 'Orphaned draft' );
		fireEvent.click( screen.getByText( 'next-page' ) );

		// ASSERT: the empty page triggers a step-back that refetches page 1,
		// so the listing recovers instead of falsely reading as clear.
		await waitFor( () => {
			const pages = fetchMock.mock.calls.map( ( call ) =>
				( call[ 1 ] as { body: FormData } ).body.get( 'page' )
			);
			expect( pages ).toContain( '2' );
			expect( pages[ pages.length - 1 ] ).toBe( '1' );
		} );
		expect( await screen.findByText( 'Orphaned draft' ) ).toBeInTheDocument();
	} );
} );
