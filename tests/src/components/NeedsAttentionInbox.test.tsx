/**
 * Tests for the NeedsAttentionInbox component.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';

import NeedsAttentionInbox from '@/components/NeedsAttentionInbox';
import type { NeedsAttentionRow } from '@/types';

// DataViews pulls in @wordpress/private-apis, which cannot unlock in the test
// env. Stub it with a minimal renderer that exercises each field's render and
// each row's eligible actions.
vi.mock( '@wordpress/dataviews', () => ( {
	DataViews: ( {
		data,
		fields,
		actions = [],
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
	} ): JSX.Element => (
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
								! action.isEligible || action.isEligible( item )
						)
						.map( ( action ) => (
							<button key={ action.id } type="button">
								{ action.label }
							</button>
						) ) }
				</div>
			) ) }
		</div>
	),
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
	row_id: 'degradation:1024:nav_ref_rewrite_failed:8300:post',
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
} );
