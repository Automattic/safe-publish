/**
 * Tests for the NeedsAttentionInbox component.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

import NeedsAttentionInbox from '@/components/NeedsAttentionInbox';
import type { NeedsAttentionRow } from '@/types';

const dv = vi.hoisted( () => ( {
	view: undefined as unknown,
	onChangeView: undefined as ( ( next: unknown ) => void ) | undefined,
} ) );

// DataViews pulls in @wordpress/private-apis, which cannot unlock in the test
// env. Stub it with a minimal renderer that exercises each field's render and
// each row's eligible actions, and captures view/onChangeView so a test can
// drive a view change.
vi.mock( '@wordpress/dataviews', () => ( {
	DataViews: ( {
		data,
		fields,
		actions = [],
		view,
		onChangeView,
	}: {
		data: NeedsAttentionRow[];
		fields: Array< {
			id: string;
			render?: ( arg: { item: NeedsAttentionRow } ) => JSX.Element;
		} >;
		actions?: Array< {
			id: string;
			label: string;
			isEligible?: ( item: NeedsAttentionRow ) => boolean;
		} >;
		view: unknown;
		onChangeView: ( next: unknown ) => void;
	} ): JSX.Element => {
		dv.view = view;
		dv.onChangeView = onChangeView;
		return (
			<div>
				{ data.map( ( item ) => (
					<div key={ item.row_id }>
						{ fields.map( ( field ) => (
							<span key={ field.id }>
								{ field.render ? field.render( { item } ) : null }
							</span>
						) ) }
						{ actions
							.filter(
								( action ) =>
									! action.isEligible ||
									action.isEligible( item )
							)
							.map( ( action ) => (
								<button key={ action.id } type="button">
									{ action.label }
								</button>
							) ) }
					</div>
				) ) }
			</div>
		);
	},
} ) );

const FAILURE: NeedsAttentionRow = {
	kind: 'failure',
	row_id: 'failure:42',
	item_id: 42,
	source_post_id: null,
	title: 'Broken import',
	error_message: 'Timed out',
	import_date_gmt: '2024-03-15 10:30:00',
	source_site_url: 'https://source.example.com',
	edit_url: '',
};

const DEGRADATION: NeedsAttentionRow = {
	kind: 'degradation',
	row_id: 'degradation:1024:nav_ref_rewrite_failed:8300:post:',
	affected_post_id: 1024,
	issue_type: 'nav_ref_rewrite_failed',
	target_ref: 8300,
	target_kind: 'post',
	target_slug: '',
	target_is_reusable_block: false,
	target_terms: [],
	target_reason: '',
	severity: 'error',
	source_site_url: 'https://source.example.com',
	first_detected_gmt: '2024-03-15 10:30:00',
	last_seen_gmt: '2024-03-15 10:30:00',
	affected_title: 'Primary Menu',
	affected_edit_url: 'https://destination.example/wp-admin/post.php?post=1024',
	retryable: true,
	resolvable: false,
};

/**
 * Stubs fetch to return the given rows and count from the inbox endpoint.
 */
function mockListResponse(
	items: NeedsAttentionRow[],
	count = items.length
): void {
	vi.stubGlobal(
		'fetch',
		vi.fn().mockResolvedValue( {
			json: () =>
				Promise.resolve( {
					success: true,
					data: {
						items,
						has_more: false,
						needs_attention_count: count,
					},
				} ),
		} )
	);
}

afterEach( () => {
	vi.unstubAllGlobals();
} );

describe( 'NeedsAttentionInbox', () => {
	it( 'Verifies that failures and degradations render with per-kind actions', async () => {
		// ARRANGE: The endpoint returns one failure and one degradation.
		mockListResponse( [ FAILURE, DEGRADATION ] );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);

		// ASSERT: Both rows appear, each under its own type label.
		expect( await screen.findByText( 'Broken import' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Primary Menu' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Failed' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Degraded' ) ).toBeInTheDocument();

		// ASSERT: The failure exposes Remove; the retryable degradation Retry.
		expect( screen.getByText( 'Remove' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Retry' ) ).toBeInTheDocument();
	} );

	it( 'Verifies that a live destination post links the Content title', async () => {
		// ARRANGE: A degradation whose affected post has an edit URL.
		mockListResponse( [ DEGRADATION ] );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);

		// ASSERT: The title links out to the edit screen in a new tab.
		const link = await screen.findByRole( 'link', { name: 'Primary Menu' } );
		expect( link ).toHaveAttribute( 'target', '_blank' );
		expect( link ).toHaveAttribute(
			'href',
			'https://destination.example/wp-admin/post.php?post=1024'
		);
	} );

	it( 'Verifies that the fetched count is reported to the tab', async () => {
		// ARRANGE: The endpoint reports a count of 5 across two visible rows.
		mockListResponse( [ FAILURE, DEGRADATION ], 5 );
		const onCountChange = vi.fn();

		// ACT: Render the inbox with a count sink.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
				onCountChange={ onCountChange }
			/>
		);

		// ASSERT: The reported count drives the tab label, not the page size.
		await waitFor( () =>
			expect( onCountChange ).toHaveBeenCalledWith( 5 )
		);
	} );

	it( 'Verifies that a resolvable degradation shows the resolvable-now badge', async () => {
		// ARRANGE: A retryable degradation whose target is imported.
		mockListResponse( [ { ...DEGRADATION, resolvable: true } ] );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);

		// ASSERT: The resolvable-now badge appears.
		expect(
			await screen.findByText( 'Resolvable now' )
		).toBeInTheDocument();
	} );

	it( 'Verifies that a degradation awaiting its target shows the waiting badge', async () => {
		// ARRANGE: A retryable degradation whose target is not imported.
		mockListResponse( [ { ...DEGRADATION, resolvable: false } ] );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);

		// ASSERT: The waiting-on-import badge appears.
		expect(
			await screen.findByText( 'Waiting on import' )
		).toBeInTheDocument();
	} );

	it( 'Verifies that the resolvable badge is gated to the Open view', async () => {
		// ARRANGE: A resolvable degradation, returned in either view.
		mockListResponse( [ { ...DEGRADATION, resolvable: true } ] );

		// ACT: Render (Open by default), then switch to the Ignored view.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);
		expect(
			await screen.findByText( 'Resolvable now' )
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'radio', { name: 'Ignored' } ) );

		// ASSERT: The Ignored view drops the badge — no Retry lives there.
		await waitFor( () =>
			expect(
				screen.queryByText( 'Resolvable now' )
			).not.toBeInTheDocument()
		);
	} );

	it( 'Verifies that an empty inbox shows the reassuring empty state', async () => {
		// ARRANGE: The endpoint returns no rows.
		mockListResponse( [], 0 );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);

		// ASSERT: The empty-state copy appears.
		expect(
			await screen.findByText( 'Nothing needs attention.' )
		).toBeInTheDocument();
	} );

	it( 'Verifies that toggling views clears the old rows before the refetch', async () => {
		// ARRANGE: The open view loads a degradation; the ignored refetch hangs.
		const fetchMock = vi
			.fn()
			.mockResolvedValueOnce( {
				json: () =>
					Promise.resolve( {
						success: true,
						data: {
							items: [ DEGRADATION ],
							has_more: false,
							needs_attention_count: 1,
						},
					} ),
				} )
			.mockReturnValue( new Promise( () => {} ) );
		vi.stubGlobal( 'fetch', fetchMock );

		// ACT: Render (open), then switch to Ignored — whose fetch never settles.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);
		expect( await screen.findByText( 'Primary Menu' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'radio', { name: 'Ignored' } ) );

		// ASSERT: The old row is dropped at once, not left actionable mid-fetch.
		await waitFor( () =>
			expect( screen.queryByText( 'Primary Menu' ) ).not.toBeInTheDocument()
		);
	} );

	it( 'Verifies that raising the page size refetches from page 1', async () => {
		// ARRANGE: Every fetch returns a row so pages never empty.
		const fetchMock = vi.fn().mockResolvedValue( {
			json: () =>
				Promise.resolve( {
					success: true,
					data: {
						items: [ DEGRADATION ],
						has_more: true,
						needs_attention_count: 50,
					},
				} ),
		} );
		vi.stubGlobal( 'fetch', fetchMock );
		const fetchedPage = (): string =>
			( fetchMock.mock.calls.at( -1 )![ 1 ].body as FormData ).get(
				'page'
			) as string;

		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);
		await screen.findByText( 'Primary Menu' );

		// ACT: Page forward, then raise the page size.
		act( () => dv.onChangeView?.( { ...( dv.view as object ), page: 2 } ) );
		await waitFor( () => expect( fetchedPage() ).toBe( '2' ) );
		act( () =>
			dv.onChangeView?.( { ...( dv.view as object ), perPage: 50 } )
		);

		// ASSERT: The per-page change resets the offset to page 1.
		await waitFor( () => expect( fetchedPage() ).toBe( '1' ) );
	} );
} );
