/**
 * Tests for the BulkImportFlow confirmation groups, skipped-selection
 * messaging, and the results-summary heading per run outcome.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

import BulkImportFlow, {
	type BulkImportFlowPost,
} from '@/components/BulkImportFlow';
import type { BulkImportResult } from '@/types';

const CONTEXT = {
	ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
	nonce: 'test-nonce',
};

const POSTS: BulkImportFlowPost[] = [
	{ id: 1, post_type: 'post', title: 'A', local_state: 'available' },
	{ id: 2, post_type: 'post', title: 'B', local_state: 'available' },
];

const MIXED: BulkImportFlowPost[] = [
	{ id: 1, post_type: 'post', title: 'Brand new', local_state: 'available' },
	{ id: 2, post_type: 'post', title: 'Stale copy', local_state: 'outdated' },
	{ id: 3, post_type: 'post', title: 'Current copy', local_state: 'up-to-date' },
];

/**
 * Stubs the bulk endpoint with the given outcome counts, then renders the
 * modal and starts the import.
 *
 * @param {number} successful Number of posts that import cleanly.
 * @param {number} failed     Number of posts that error.
 */
function runImport( successful: number, failed: number ): void {
	const results: BulkImportResult[] = [
		...Array.from( { length: successful }, ( _, index ) => ( {
			source_post_id: index + 1,
			title: `Imported ${ index + 1 }`,
			success: true,
		} ) ),
		...Array.from( { length: failed }, ( _, index ) => ( {
			source_post_id: successful + index + 1,
			title: `Broken ${ index + 1 }`,
			success: false,
			error: 'Source fetch failed',
		} ) ),
	];

	vi.stubGlobal(
		'fetch',
		vi.fn().mockResolvedValue( {
			json: async () => ( {
				success: true,
				data: {
					total: results.length,
					successful,
					failed,
					results,
				},
			} ),
		} )
	);

	render(
		<BulkImportFlow posts={ POSTS } context={ CONTEXT } onClose={ () => {} } />
	);
	fireEvent.click( screen.getByRole( 'button', { name: 'Import 2 posts' } ) );
}

describe( 'BulkImportFlow skipped-selection notice', () => {
	it( 'Verifies that a mixed selection shows the N-of-M heading and a plural skipped notice', () => {
		// ARRANGE + ACT: Two importable posts out of five selected.
		render(
			<BulkImportFlow
				posts={ POSTS }
				skippedCount={ 3 }
				context={ CONTEXT }
				onClose={ () => {} }
			/>
		);

		// ASSERT: The heading reconciles both counts and the notice explains
		// the three dropped rows.
		expect(
			screen.getByText( 'Import 2 of 5 selected posts from the source?' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'3 selected posts are already up to date or cannot be imported, so they will be skipped.'
			)
		).toBeInTheDocument();
	} );

	it( 'Verifies that a single skipped row reads in the singular', () => {
		// ARRANGE + ACT: Two importable posts, a single dropped row.
		render(
			<BulkImportFlow
				posts={ POSTS }
				skippedCount={ 1 }
				context={ CONTEXT }
				onClose={ () => {} }
			/>
		);

		// ASSERT: The notice uses the singular form.
		expect(
			screen.getByText(
				'1 selected post is already up to date or cannot be imported, so it will be skipped.'
			)
		).toBeInTheDocument();
	} );

	it( 'Verifies that a fully eligible selection shows the plain heading and no skipped notice', () => {
		// ARRANGE + ACT: Nothing skipped.
		render(
			<BulkImportFlow posts={ POSTS } context={ CONTEXT } onClose={ () => {} } />
		);

		// ASSERT: The plain heading shows and no skipped copy renders.
		expect(
			screen.getByText( 'Import 2 selected posts from the source?' )
		).toBeInTheDocument();
		expect( screen.queryByText( /will be skipped\./ ) ).not.toBeInTheDocument();
	} );
} );

describe( 'BulkImportFlow confirmation groups', () => {
	afterEach( () => {
		vi.unstubAllGlobals();
	} );

	it( 'Verifies that a mixed selection lists each title under its own action', () => {
		// ARRANGE + ACT: One new row alongside an outdated and an up-to-date one.
		render(
			<BulkImportFlow posts={ MIXED } context={ CONTEXT } onClose={ () => {} } />
		);

		// ASSERT: Both group headings render with the right counts.
		expect(
			screen.getByText(
				'2 posts will be updated with the latest source content:'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( '1 post will be imported as a new draft:' )
		).toBeInTheDocument();

		// ASSERT: Each title sits in the list its own group heading names.
		const updates = screen.getByRole( 'list', {
			name: '2 posts will be updated with the latest source content:',
		} );
		const creations = screen.getByRole( 'list', {
			name: '1 post will be imported as a new draft:',
		} );
		expect( updates ).toHaveTextContent( 'Stale copy' );
		expect( updates ).toHaveTextContent( 'Current copy' );
		expect( updates ).not.toHaveTextContent( 'Brand new' );
		expect( creations ).toHaveTextContent( 'Brand new' );
		expect( creations ).not.toHaveTextContent( 'Stale copy' );
	} );

	it( 'Verifies that an all-new selection shows no update group', () => {
		// ARRANGE + ACT: Every selected row is unimported.
		render(
			<BulkImportFlow posts={ POSTS } context={ CONTEXT } onClose={ () => {} } />
		);

		// ASSERT: Only the drafts group renders.
		expect(
			screen.getByText( '2 posts will be imported as new drafts:' )
		).toBeInTheDocument();
		expect(
			screen.queryByText( /will be updated with the latest source content/ )
		).not.toBeInTheDocument();
	} );

	it( 'Verifies that an all-imported selection shows no drafts group', () => {
		// ARRANGE + ACT: Every selected row already exists locally.
		render(
			<BulkImportFlow
				posts={ MIXED.filter( ( post ) => 'available' !== post.local_state ) }
				context={ CONTEXT }
				onClose={ () => {} }
			/>
		);

		// ASSERT: Only the update group renders.
		expect(
			screen.getByText(
				'2 posts will be updated with the latest source content:'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( /will be imported as/ )
		).not.toBeInTheDocument();
	} );

	it( 'Verifies that a group lists ten titles and counts the remainder', () => {
		// ARRANGE: Twelve rows, two past the ten a group lists.
		const overCap: BulkImportFlowPost[] = Array.from(
			{ length: 12 },
			( _, index ) => ( {
				id: index + 1,
				post_type: 'post',
				title: `Post ${ index + 1 }`,
				local_state: 'available' as const,
			} )
		);

		// ACT: Confirm the over-cap selection.
		render(
			<BulkImportFlow
				posts={ overCap }
				context={ CONTEXT }
				onClose={ () => {} }
			/>
		);

		// ASSERT: The tenth title shows, the eleventh does not, and the two
		// left over are counted rather than dropped.
		expect( screen.getByText( 'Post 10' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Post 11' ) ).not.toBeInTheDocument();
		expect( screen.getByText( '…and 2 more' ) ).toBeInTheDocument();
	} );

	it( 'Verifies that the groups give way to progress once the run starts', async () => {
		// ARRANGE: A request held open so the run stays in flight.
		let settle: ( value: unknown ) => void = () => {};
		vi.stubGlobal(
			'fetch',
			vi.fn().mockReturnValue(
				new Promise( ( resolve ) => {
					settle = resolve;
				} )
			)
		);
		render(
			<BulkImportFlow posts={ MIXED } context={ CONTEXT } onClose={ () => {} } />
		);

		// ACT: Start the import.
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Import 3 posts' } )
		);

		// ASSERT: The confirmation copy is replaced by the progress copy.
		expect(
			screen.queryByText( /will be imported as/ )
		).not.toBeInTheDocument();
		expect(
			screen.queryByText( 'Import 3 selected posts from the source?' )
		).not.toBeInTheDocument();
		expect(
			screen.getByText( 'Importing posts as a batch…' )
		).toBeInTheDocument();

		// CLEANUP: Let the run finish so its progress timer clears.
		settle( {
			json: async () => ( {
				success: true,
				data: { total: 3, successful: 3, failed: 0, results: [] },
			} ),
		} );
		expect(
			await screen.findByText( 'Import completed!' )
		).toBeInTheDocument();
	} );
} );

describe( 'BulkImportFlow results summary', () => {
	afterEach( () => {
		vi.unstubAllGlobals();
	} );

	it( 'Verifies that a run where nothing succeeded reads as a failure', async () => {
		// ARRANGE + ACT: Both posts error.
		runImport( 0, 2 );

		// ASSERT: The summary reports a failed run, not a partial one.
		expect( await screen.findByText( 'Import failed' ) ).toBeInTheDocument();
		expect(
			screen.queryByText( 'Import completed with errors' )
		).not.toBeInTheDocument();
		expect( screen.queryByText( 'Import completed!' ) ).not.toBeInTheDocument();
	} );

	it( 'Verifies that a run with both successes and failures reads as partial', async () => {
		// ARRANGE + ACT: One post imports, one errors.
		runImport( 1, 1 );

		// ASSERT: The summary reports a completed run carrying errors.
		expect(
			await screen.findByText( 'Import completed with errors' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'Import failed' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Import completed!' ) ).not.toBeInTheDocument();
	} );

	it( 'Verifies that a run without failures reads as completed', async () => {
		// ARRANGE + ACT: Both posts import.
		runImport( 2, 0 );

		// ASSERT: The summary reports a clean run.
		expect(
			await screen.findByText( 'Import completed!' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'Import failed' ) ).not.toBeInTheDocument();
		expect(
			screen.queryByText( 'Import completed with errors' )
		).not.toBeInTheDocument();
	} );

	it( 'Verifies that a failed item isolates its source reason', async () => {
		// ARRANGE: One item fails with structured source detail.
		const reason = '\u202eSource refused the request.\u202c';
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: true,
					data: {
						total: 1,
						successful: 0,
						failed: 1,
						results: [
							{
								source_post_id: 1,
								title: 'Broken post',
								success: false,
								error: 'Source site returned HTTP error 401.',
								source_error: {
									message: reason,
									template:
										'Source site returned HTTP error 401. <reason />',
								},
							},
						],
					},
				} ),
			} )
		);

		// ACT: Import the single post and find the source-provided reason.
		render(
			<BulkImportFlow
				posts={ [ POSTS[ 0 ] ] }
				context={ CONTEXT }
				onClose={ () => {} }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Import 1 posts' } )
		);
		const isolatedReason = await screen.findByText( reason );

		// ASSERT: The item result prevents its reason from reordering the UI.
		expect( isolatedReason.tagName ).toBe( 'BDI' );
		expect( isolatedReason ).toHaveAttribute( 'dir', 'auto' );
	} );
} );

describe( 'BulkImportFlow outcome announcements', () => {
	afterEach( () => {
		vi.unstubAllGlobals();
	} );

	it( 'Verifies that the outcome heading lands in a live region', async () => {
		// ARRANGE + ACT: A mixed run resolves.
		runImport( 1, 1 );

		// ASSERT: The heading is the modal's status message.
		expect( await screen.findByRole( 'status' ) ).toHaveTextContent(
			'Import completed with errors'
		);
	} );

	it( 'Verifies that a rejected request lands in an alert region', async () => {
		// ARRANGE: The endpoint refuses the whole request.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: false,
					data: { message: 'Source site unreachable' },
				} ),
			} )
		);

		// ACT: Run the import.
		render(
			<BulkImportFlow posts={ POSTS } context={ CONTEXT } onClose={ () => {} } />
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Import 2 posts' } ) );

		// ASSERT: The error is announced assertively.
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Source site unreachable'
		);
	} );
} );

describe( 'BulkImportFlow listing refresh', () => {
	afterEach( () => {
		vi.unstubAllGlobals();
	} );

	it( 'Verifies that a run whose request fails still refreshes the listing', async () => {
		// ARRANGE: The endpoint refuses the whole request, so no results land.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: false,
					data: { message: 'Source site unreachable' },
				} ),
			} )
		);
		const onRefresh = vi.fn();

		// ACT: Run the import and wait for the error.
		const { unmount } = render(
			<BulkImportFlow
				posts={ POSTS }
				context={ CONTEXT }
				onClose={ () => {} }
				onRefresh={ onRefresh }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Import 2 posts' } )
		);
		expect( await screen.findByRole( 'alert' ) ).toBeInTheDocument();

		// ASSERT: The error stays readable, so the refresh is still owed.
		expect( onRefresh ).not.toHaveBeenCalled();

		// ACT: Dismiss the modal.
		unmount();

		// ASSERT: The server may have imported before failing, so the listing
		// refreshes anyway.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that canceling without running an import does not refresh', () => {
		// ARRANGE: A confirmation the operator backs out of.
		const onRefresh = vi.fn();

		// ACT: Render, then dismiss without starting the run.
		const { unmount } = render(
			<BulkImportFlow
				posts={ POSTS }
				context={ CONTEXT }
				onClose={ () => {} }
				onRefresh={ onRefresh }
			/>
		);
		unmount();

		// ASSERT: Nothing reached the server, so the listing is left alone.
		expect( onRefresh ).not.toHaveBeenCalled();
	} );
} );
