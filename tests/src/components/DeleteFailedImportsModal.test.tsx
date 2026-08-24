/**
 * Tests for the DeleteFailedImportsModal confirmation, which names the
 * selected failure rows on the bulk path.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

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

describe( 'DeleteFailedImportsModal inbox refresh', () => {
	afterEach( () => {
		vi.unstubAllGlobals();
	} );

	it( 'Verifies that a failed removal refreshes once the error has been read', async () => {
		// ARRANGE: The endpoint refuses the removal.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: false,
					data: 'That failure row is already gone',
				} ),
			} )
		);
		const onRefresh = vi.fn();

		// ACT: Attempt the removal and wait for the error.
		const { unmount } = render(
			<DeleteFailedImportsModal
				items={ ITEMS }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
				onRefresh={ onRefresh }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Remove' } ) );
		expect(
			await screen.findByText( 'That failure row is already gone' )
		).toBeInTheDocument();

		// ASSERT: The error stays readable, so the refresh is still owed.
		expect( onRefresh ).not.toHaveBeenCalled();

		// ACT: Dismiss the modal.
		unmount();

		// ASSERT: A refused removal can mean the inbox was stale, so it
		// refreshes anyway.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that canceling without attempting a removal does not refresh', () => {
		// ARRANGE: A confirmation the operator backs out of.
		const closeModal = vi.fn();
		const onRefresh = vi.fn();

		// ACT: Cancel, then dismiss the modal.
		const { unmount } = render(
			<DeleteFailedImportsModal
				items={ ITEMS }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
				closeModal={ closeModal }
				onRefresh={ onRefresh }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		unmount();

		// ASSERT: Nothing reached the server, so the inbox is left alone.
		expect( closeModal ).toHaveBeenCalled();
		expect( onRefresh ).not.toHaveBeenCalled();
	} );
} );
