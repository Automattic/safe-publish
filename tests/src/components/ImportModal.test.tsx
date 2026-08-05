/**
 * Tests for the ImportModal skipped-selection messaging.
 */
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import ImportModal from '@/components/ImportModal';

const BASE_PROPS = {
	sourcePostId: 1,
	title: 'A Post',
	sourceLink: 'https://example.com/a-post',
	postType: 'post',
	isUpdate: false,
	ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
	nonce: 'test-nonce',
};

describe( 'ImportModal skipped-selection notice', () => {
	it( 'Verifies that a mixed selection shows a plural skipped notice beside the single-post heading', () => {
		// ARRANGE + ACT: Import one post while three selected rows are dropped.
		render( <ImportModal { ...BASE_PROPS } skippedCount={ 3 } /> );

		// ASSERT: The single-post heading and the plural skipped note both show.
		expect(
			screen.getByText( 'Import "A Post" as a draft?' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'3 selected posts are already up to date or cannot be imported, so they will be skipped.'
			)
		).toBeInTheDocument();
	} );

	it( 'Verifies that a single skipped row reads in the singular', () => {
		// ARRANGE + ACT: Import one post while a single selected row is dropped.
		render( <ImportModal { ...BASE_PROPS } skippedCount={ 1 } /> );

		// ASSERT: The note uses the singular form.
		expect(
			screen.getByText(
				'1 selected post is already up to date or cannot be imported, so it will be skipped.'
			)
		).toBeInTheDocument();
	} );

	it( 'Verifies that no skipped notice shows when nothing was dropped', () => {
		// ARRANGE + ACT: A lone eligible row with no dropped selections.
		render( <ImportModal { ...BASE_PROPS } /> );

		// ASSERT: The heading shows and no skipped copy renders.
		expect(
			screen.getByText( 'Import "A Post" as a draft?' )
		).toBeInTheDocument();
		expect(
			screen.queryByText( /will be skipped\./ )
		).not.toBeInTheDocument();
	} );
} );
