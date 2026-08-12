/**
 * Tests for the BulkImportFlow skipped-selection messaging and the
 * results-summary heading per run outcome.
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
	{ id: 1, post_type: 'post', title: 'A' },
	{ id: 2, post_type: 'post', title: 'B' },
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
			screen.getByText( 'Import 2 of 5 selected posts as drafts?' )
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
			screen.getByText( 'Import 2 selected posts as drafts?' )
		).toBeInTheDocument();
		expect( screen.queryByText( /will be skipped\./ ) ).not.toBeInTheDocument();
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
} );
