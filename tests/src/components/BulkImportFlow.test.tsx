/**
 * Tests for the BulkImportFlow skipped-selection messaging.
 */
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import BulkImportFlow, {
	type BulkImportFlowPost,
} from '@/components/BulkImportFlow';

const CONTEXT = {
	ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
	nonce: 'test-nonce',
};

const POSTS: BulkImportFlowPost[] = [
	{ id: 1, post_type: 'post', title: 'A' },
	{ id: 2, post_type: 'post', title: 'B' },
];

describe( 'BulkImportFlow skipped-selection notice', () => {
	it( 'Verifies that a mixed selection shows the N-of-M heading and a plural skipped notice', () => {
		// ARRANGE + ACT: two importable posts out of five selected.
		render(
			<BulkImportFlow
				posts={ POSTS }
				skippedCount={ 3 }
				context={ CONTEXT }
				onClose={ () => {} }
			/>
		);

		// ASSERT: the heading reconciles both counts and the notice explains
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
		// ARRANGE + ACT: two importable posts, a single dropped row.
		render(
			<BulkImportFlow
				posts={ POSTS }
				skippedCount={ 1 }
				context={ CONTEXT }
				onClose={ () => {} }
			/>
		);

		// ASSERT: the notice uses the singular form.
		expect(
			screen.getByText(
				'1 selected post is already up to date or cannot be imported, so it will be skipped.'
			)
		).toBeInTheDocument();
	} );

	it( 'Verifies that a fully eligible selection shows the plain heading and no skipped notice', () => {
		// ARRANGE + ACT: nothing skipped.
		render(
			<BulkImportFlow posts={ POSTS } context={ CONTEXT } onClose={ () => {} } />
		);

		// ASSERT: the plain heading shows and no skipped copy renders.
		expect(
			screen.getByText( 'Import 2 selected posts as drafts?' )
		).toBeInTheDocument();
		expect( screen.queryByText( /will be skipped\./ ) ).not.toBeInTheDocument();
	} );
} );
