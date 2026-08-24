/**
 * Tests for the DeleteFailedImportsModal confirmation, which names the
 * selected failure rows on the bulk path.
 */
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import DeleteFailedImportsModal, {
	type DeleteFailedImportsItem,
} from '@/components/DeleteFailedImportsModal';

const AJAX_URL = 'https://example.com/wp-admin/admin-ajax.php';
const NONCE = 'test-nonce';

const ITEMS: DeleteFailedImportsItem[] = [
	{ itemId: 1, sourcePostId: 11, title: 'Failed one' },
	{ itemId: 2, sourcePostId: null, title: 'Orphan failure' },
];

describe( 'DeleteFailedImportsModal confirmation', () => {
	it( 'Verifies that a bulk selection lists every selected title', () => {
		// ARRANGE + ACT: A source-linked failure alongside an orphan.
		render(
			<DeleteFailedImportsModal
				items={ ITEMS }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
			/>
		);

		// ASSERT: The question labels the list of affected titles.
		const list = screen.getByRole( 'list' );
		expect( list ).toHaveAccessibleName( 'Remove 2 failed imports?' );
		expect( list ).toHaveTextContent( 'Failed one' );
		expect( list ).toHaveTextContent( 'Orphan failure' );
	} );

	it( 'Verifies that a single selection names the row in the question', () => {
		// ARRANGE + ACT: One failure row selected.
		render(
			<DeleteFailedImportsModal
				items={ [ ITEMS[ 0 ] ] }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
			/>
		);

		// ASSERT: The title reads inline, with no list to scan.
		expect(
			screen.getByText( 'Remove "Failed one"?' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'list' ) ).not.toBeInTheDocument();
	} );
} );
