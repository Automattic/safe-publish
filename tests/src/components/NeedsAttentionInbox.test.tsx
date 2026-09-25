/**
 * Tests for the NeedsAttentionInbox component.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

import NeedsAttentionInbox from '@/components/NeedsAttentionInbox';
import { renderIssueMessage } from '@/utils';

import type { InboxDegradation, NeedsAttentionRow } from '@/types';

const dv = vi.hoisted( () => ( {
	view: undefined as unknown,
	onChangeView: undefined as ( ( next: unknown ) => void ) | undefined,
	actions: [] as Array< { id: string; isPrimary?: boolean } >,
} ) );

const useViewportMatch = vi.hoisted( () => vi.fn( () => false ) );

// useRowActions reads this to decide whether to demote primary actions.
vi.mock( '@wordpress/compose', async ( importOriginal ) => ( {
	...( await importOriginal< typeof import('@wordpress/compose') >() ),
	useViewportMatch,
} ) );

// DataViews pulls in @wordpress/private-apis, which cannot unlock in the test
// env. Stub it with a minimal renderer that exercises each field's render and
// each row's eligible actions and the header, and captures view/onChangeView
// so a test can drive a view change.
vi.mock( '@wordpress/dataviews', () => ( {
	DataViews: ( {
		data,
		fields,
		actions = [],
		view,
		onChangeView,
		header,
	}: {
		data: NeedsAttentionRow[];
		fields: Array< {
			id: string;
			render?: ( arg: { item: NeedsAttentionRow } ) => JSX.Element;
		} >;
		actions?: Array< {
			id: string;
			label: string;
			isPrimary?: boolean;
			isEligible?: ( item: NeedsAttentionRow ) => boolean;
		} >;
		view: unknown;
		onChangeView: ( next: unknown ) => void;
		header?: JSX.Element;
	} ): JSX.Element => {
		dv.view = view;
		dv.onChangeView = onChangeView;
		dv.actions = actions;
		return (
			<div>
				{ header }
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

const DEGRADATION: InboxDegradation = {
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

beforeEach( () => {
	dv.actions = [];
	useViewportMatch.mockReturnValue( false );
} );

afterEach( () => {
	vi.unstubAllGlobals();
} );

describe( 'NeedsAttentionInbox', () => {
	it( 'Verifies that wide viewports keep the inbox actions inline', async () => {
		// ARRANGE: A wide viewport and one failure row.
		useViewportMatch.mockReturnValue( false );
		mockListResponse( [ FAILURE ] );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);
		expect( await screen.findByText( 'Broken import' ) ).toBeInTheDocument();

		// ASSERT: The actions reach DataViews as their factory built them.
		expect( dv.actions.length ).toBeGreaterThan( 0 );
		expect( dv.actions.some( ( action ) => action.isPrimary ) ).toBe( true );
	} );

	it( 'Verifies that narrow viewports demote every inbox action', async () => {
		// ARRANGE: A viewport below the breakpoint and one failure row.
		useViewportMatch.mockReturnValue( true );
		mockListResponse( [ FAILURE ] );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);
		expect( await screen.findByText( 'Broken import' ) ).toBeInTheDocument();

		// ASSERT: None is primary, so the row gets an overflow menu.
		expect( dv.actions.length ).toBeGreaterThan( 0 );
		expect( dv.actions.some( ( action ) => action.isPrimary ) ).toBe( false );
	} );

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

	// The detail cell truncates in CSS, so the full text has to stay reachable.
	it( "Verifies that a degradation's detail carries its full text as a title", async () => {
		// ARRANGE: A degradation, and the message the inbox renders for it.
		mockListResponse( [ DEGRADATION ] );
		const message = renderIssueMessage( DEGRADATION );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);

		// ASSERT: The detail span titles itself with the message it renders.
		const detail = await screen.findByTitle( message );
		expect( detail ).toHaveClass( 'safe-publish-inbox-detail' );
		expect( detail.textContent ).toBe( message );
	} );

	it( "Verifies that a failure's detail carries its full text as a title", async () => {
		// ARRANGE: A failure row carrying an error message.
		mockListResponse( [ FAILURE ] );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);

		// ASSERT: The detail span titles itself with the error it renders.
		const detail = await screen.findByTitle( 'Timed out' );
		expect( detail ).toHaveClass( 'safe-publish-inbox-detail' );
		expect( detail.textContent ).toBe( 'Timed out' );
	} );

	it( 'Verifies that a linked content title carries its full text as a title', async () => {
		// ARRANGE: A degradation, which always has a destination post to link.
		mockListResponse( [ DEGRADATION ] );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);

		// ASSERT: The title link titles itself with its own text.
		const title = await screen.findByTitle( 'Primary Menu' );
		expect( title.tagName ).toBe( 'A' );
		expect( title.textContent ).toBe( 'Primary Menu' );
	} );

	it( 'Verifies that an unlinked content title carries its full text as a title', async () => {
		// ARRANGE: A first-import failure, which has no destination post to
		// link to, so the Content cell falls back to plain text.
		mockListResponse( [ FAILURE ] );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);

		// ASSERT: The unlinked title span titles itself with its own text.
		const title = await screen.findByTitle( 'Broken import' );
		expect( title.tagName ).toBe( 'SPAN' );
		expect( title.textContent ).toBe( 'Broken import' );
	} );

	it( 'Verifies that the resolvable badge sits outside the truncated detail', async () => {
		// ARRANGE: A resolvable degradation, so both elements render.
		const resolvable = { ...DEGRADATION, resolvable: true };
		mockListResponse( [ resolvable ] );

		// ACT: Render the inbox.
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);
		const badge = await screen.findByText( 'Resolvable now' );
		const detail = await screen.findByTitle(
			renderIssueMessage( resolvable )
		);

		// ASSERT: The badge is the detail's sibling, so truncating the detail
		// cannot clip it.
		expect( detail.contains( badge ) ).toBe( false );
		expect( badge.parentElement ).toBe( detail.parentElement );
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

	it( 'Verifies that a loading refresh uses accessible disabled semantics', async () => {
		// ARRANGE: A loaded inbox whose next request stays in flight.
		mockListResponse( [ FAILURE ] );
		render(
			<NeedsAttentionInbox
				ajaxurl="https://example.com/wp-admin/admin-ajax.php"
				nonce="test-nonce"
			/>
		);
		const refresh = await screen.findByRole( 'button', {
			name: 'Refresh',
		} );
		vi.stubGlobal( 'fetch', vi.fn().mockReturnValue( new Promise( () => {} ) ) );

		// ACT: Start a refresh.
		fireEvent.click( refresh );

		// ASSERT: The action uses aria-disabled instead of native disabled.
		await waitFor( () =>
			expect( refresh ).toHaveAttribute( 'aria-disabled', 'true' )
		);
		expect( refresh ).not.toHaveAttribute( 'disabled' );
	} );
} );
