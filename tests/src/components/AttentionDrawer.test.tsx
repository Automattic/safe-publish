/**
 * Tests for the AttentionDrawer component.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import AttentionDrawer from '@/components/AttentionDrawer';
import type { AttentionIssue } from '@/types';

// DataViews pulls in @wordpress/private-apis, which cannot unlock in the test
// env. Stub it with a minimal renderer that exercises each field's render so
// the row mapping (title link, reused issue copy) is still asserted, plus a
// next-page control that drives onChangeView for the pagination test.
vi.mock( '@wordpress/dataviews', () => ( {
	DataViews: ( {
		data,
		fields,
		view,
		onChangeView,
		actions = [],
	}: {
		data: AttentionIssue[];
		fields: Array< {
			id: string;
			render?: ( arg: { item: AttentionIssue } ) => JSX.Element;
		} >;
		view: { page?: number };
		onChangeView: ( next: { page?: number } ) => void;
		actions?: Array< {
			id: string;
			label: string;
			isEligible?: ( item: AttentionIssue ) => boolean;
			callback?: ( items: AttentionIssue[] ) => void;
		} >;
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
				<div key={ item.affected_post_id }>
					{ fields.map( ( field ) => (
						<span key={ field.id }>
							{ field.render ? field.render( { item } ) : null }
						</span>
					) ) }
					{ actions
						.filter(
							( action ) =>
								! action.isEligible || action.isEligible( item )
						)
						.map( ( action ) => (
							<button
								key={ action.id }
								onClick={ () => action.callback?.( [ item ] ) }
							>
								{ action.label }
							</button>
						) ) }
				</div>
			) ) }
		</div>
	),
} ) );

const ISSUE: AttentionIssue = {
	affected_post_id: 1024,
	issue_type: 'nav_ref_rewrite_failed',
	target_ref: 8300,
	target_kind: 'post',
	target_is_reusable_block: false,
	severity: 'error',
	source_site_url: 'https://source.example.com',
	first_detected_gmt: '2024-03-15 10:30:00',
	last_seen_gmt: '2024-03-15 10:30:00',
	affected_title: 'Primary Menu',
	affected_edit_url: 'https://destination.example/wp-admin/post.php?post=1024',
	retryable: true,
};

/**
 * Stubs fetch to return the given issues from the list endpoint.
 */
function mockListResponse( items: AttentionIssue[] ): void {
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

describe( 'AttentionDrawer', () => {
	it( 'renders open issues with the reused issue copy', async () => {
		// ARRANGE: the list endpoint returns one error-level issue.
		mockListResponse( [ ISSUE ] );

		// ACT: render the drawer.
		render(
			<AttentionDrawer
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
				onClose={ () => undefined }
			/>
		);

		// ASSERT: the affected title and the per-issue message appear.
		expect( await screen.findByText( 'Primary Menu' ) ).toBeInTheDocument();
		expect( screen.getByText( /menu 8300/ ) ).toBeInTheDocument();

		// ASSERT: the content link opens in a new tab so following it doesn't
		// tear down the drawer.
		const link = screen.getByRole( 'link', { name: 'Primary Menu' } );
		expect( link ).toHaveAttribute( 'target', '_blank' );
		expect( link ).toHaveAttribute( 'rel', 'noreferrer' );
	} );

	it( 'offers Retry with Patterns-oriented copy for a reusable-block issue', async () => {
		// ARRANGE: a retryable reusable-block reference issue.
		mockListResponse( [
			{
				...ISSUE,
				issue_type: 'unmapped_block_reference',
				target_kind: 'post',
				target_ref: 555,
				target_is_reusable_block: true,
				severity: 'warning',
				retryable: true,
			},
		] );

		// ACT: render the drawer.
		render(
			<AttentionDrawer
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
				onClose={ () => undefined }
			/>
		);

		// ASSERT: the Patterns-oriented copy shows and Retry is offered, since
		// importing the block now resolves the reference.
		expect(
			await screen.findByText( /Reusable block 555.*Patterns/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Retry' } )
		).toBeInTheDocument();
	} );

	it( 'shows an empty state when nothing needs attention', async () => {
		// ARRANGE: the endpoint returns no issues.
		mockListResponse( [] );

		// ACT: render the drawer.
		render(
			<AttentionDrawer
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
				onClose={ () => undefined }
			/>
		);

		// ASSERT: the empty-state copy appears.
		expect(
			await screen.findByText( 'Nothing needs attention.' )
		).toBeInTheDocument();
	} );

	it( 'surfaces a warning banner when a retry leaves the issue open', async () => {
		// ARRANGE: a retryable unmapped-reference issue whose retry runs but
		// doesn't resolve. The stub branches by the posted action.
		const issue: AttentionIssue = {
			...ISSUE,
			issue_type: 'unmapped_block_reference',
			target_kind: 'post',
			target_ref: 5,
		};
		vi.stubGlobal(
			'fetch',
			vi.fn(
				(
					_url: string,
					options: RequestInit
				): Promise< { json: () => Promise< unknown > } > => {
					const isRetry =
						( options.body as FormData ).get( 'action' ) ===
						'safe_publish_retry_attention_issue';
					return Promise.resolve( {
						json: () =>
							Promise.resolve(
								isRetry
									? { success: true, data: { resolved: false } }
									: {
											success: true,
											data: { items: [ issue ], has_more: false },
									  }
							),
					} );
				}
			)
		);

		// ACT: render, then retry the row once it appears.
		render(
			<AttentionDrawer
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
				onClose={ () => undefined }
			/>
		);
		fireEvent.click( await screen.findByRole( 'button', { name: 'Retry' } ) );

		// ASSERT: the unresolved retry surfaces its guidance as a banner.
		expect(
			( await screen.findAllByText( /Still needs attention/ ) ).length
		).toBeGreaterThan( 0 );
	} );

	it( 'confirms a resolved retry with a success banner', async () => {
		// ARRANGE: a retryable issue whose retry resolves. The stub branches by
		// the posted action: the retry clears, the list keeps returning it.
		vi.stubGlobal(
			'fetch',
			vi.fn(
				(
					_url: string,
					options: RequestInit
				): Promise< { json: () => Promise< unknown > } > => {
					const isRetry =
						( options.body as FormData ).get( 'action' ) ===
						'safe_publish_retry_attention_issue';
					return Promise.resolve( {
						json: () =>
							Promise.resolve(
								isRetry
									? { success: true, data: { resolved: true } }
									: {
											success: true,
											data: { items: [ ISSUE ], has_more: false },
									  }
							),
					} );
				}
			)
		);

		// ACT: render, then retry the row once it appears.
		render(
			<AttentionDrawer
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
				onClose={ () => undefined }
			/>
		);
		fireEvent.click( await screen.findByRole( 'button', { name: 'Retry' } ) );

		// ASSERT: a success banner names the resolved content.
		expect(
			( await screen.findAllByText( /Resolved: Primary Menu/ ) ).length
		).toBeGreaterThan( 0 );
	} );

	it( 'steps back to the prior page when a trailing page empties', async () => {
		// ARRANGE: page 1 holds an issue; any later page comes back empty, as
		// it would once the trailing page's issues are resolved.
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
											? [ ISSUE ]
											: [],
									has_more: false,
								},
							} ),
					} )
			);
		vi.stubGlobal( 'fetch', fetchMock );

		// ACT: land on page 1, then page into the now-empty page 2.
		render(
			<AttentionDrawer
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
				onClose={ () => undefined }
			/>
		);
		await screen.findByText( 'Primary Menu' );
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
		expect( await screen.findByText( 'Primary Menu' ) ).toBeInTheDocument();
	} );
} );
