/**
 * Tests for the RollbackPostModal component.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import RollbackPostModal from '@/components/RollbackPostModal';
import type { UnifiedPostRow } from '@/types';

const AJAX_URL = 'https://example.com/wp-admin/admin-ajax.php';
const NONCE = 'test-nonce';

/**
 * Builds a rollback-eligible UnifiedPostRow fixture that restores on rollback.
 */
function buildRow( overrides: Partial< UnifiedPostRow > = {} ): UnifiedPostRow {
	return {
		id: 10,
		source_post_id: 10,
		title: 'Test',
		link: '',
		date_gmt: '',
		modified_gmt: '',
		post_type: 'post',
		status: 'publish',
		local_state: 'up-to-date',
		is_imported: true,
		wp_post_status: null,
		item_id: 100,
		post_id: 1024,
		import_date_gmt: '2024-03-15 10:30:00',
		has_previous_content: true,
		edit_url: '',
		...overrides,
	};
}

afterEach( () => {
	vi.unstubAllGlobals();
} );

describe( 'RollbackPostModal', () => {
	it( 'Verifies that loading actions use accessible disabled semantics', async () => {
		// ARRANGE: Hold the rollback request open at the in-flight stage.
		vi.stubGlobal( 'fetch', vi.fn().mockReturnValue( new Promise( () => {} ) ) );
		render(
			<RollbackPostModal
				items={ [ buildRow() ] }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
			/>
		);

		// ACT: Start the rollback.
		fireEvent.click( screen.getByRole( 'button', { name: 'Roll back' } ) );
		const rollingBack = await screen.findByRole( 'button', {
			name: 'Rolling back…',
		} );

		// ASSERT: Loading actions use aria-disabled instead of native disabled.
		expect( rollingBack ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( rollingBack ).not.toHaveAttribute( 'disabled' );
		const cancel = screen.getByRole( 'button', { name: 'Cancel' } );
		expect( cancel ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( cancel ).not.toHaveAttribute( 'disabled' );
	} );

	it( 'Verifies that a successful rollback surfaces the server message as a success notice', async () => {
		// ARRANGE: The endpoint restores the post and returns its confirmation.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: true,
					data: {
						action: 'restored',
						message: 'Post restored to its previous version.',
					},
				} ),
			} )
		);
		const onNotice = vi.fn();
		const onRefresh = vi.fn();
		const closeModal = vi.fn();

		// ACT: Render the modal and confirm the rollback.
		const { unmount } = render(
			<RollbackPostModal
				items={ [ buildRow() ] }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
				closeModal={ closeModal }
				onNotice={ onNotice }
				onRefresh={ onRefresh }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Roll back' } ) );

		// ASSERT: The server message surfaces as a success notice and the modal
		// closes, with the refresh still owed.
		await waitFor( () =>
			expect( onNotice ).toHaveBeenCalledWith( {
				status: 'success',
				message: 'Post restored to its previous version.',
			} )
		);
		expect( closeModal ).toHaveBeenCalled();
		expect( onRefresh ).not.toHaveBeenCalled();

		// ACT: Dismiss the modal.
		unmount();

		// ASSERT: The listing refreshes once.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that a failed rollback shows the error in-modal without a notice', async () => {
		// ARRANGE: The endpoint rejects the rollback with a message.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: false,
					data: 'The post no longer exists',
				} ),
			} )
		);
		const onNotice = vi.fn();
		const onRefresh = vi.fn();
		const closeModal = vi.fn();

		// ACT: Render the modal and attempt the rollback.
		const { unmount } = render(
			<RollbackPostModal
				items={ [ buildRow() ] }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
				closeModal={ closeModal }
				onNotice={ onNotice }
				onRefresh={ onRefresh }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Roll back' } ) );

		// ASSERT: The failure stays in the modal — no notice, no close, and no
		// refresh yet, so the operator can read the error and retry or cancel.
		expect(
			await screen.findByText( 'The post no longer exists' )
		).toBeInTheDocument();
		expect( onNotice ).not.toHaveBeenCalled();
		expect( onRefresh ).not.toHaveBeenCalled();
		expect( closeModal ).not.toHaveBeenCalled();

		// ACT: Dismiss the modal after reading the error.
		unmount();

		// ASSERT: A refused rollback can mean the listing was already stale, so
		// it refreshes anyway.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that canceling without attempting a rollback does not refresh', () => {
		// ARRANGE: A modal the operator opens and backs out of.
		const onRefresh = vi.fn();
		const closeModal = vi.fn();

		// ACT: Render, cancel, then dismiss the modal.
		const { unmount } = render(
			<RollbackPostModal
				items={ [ buildRow() ] }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
				closeModal={ closeModal }
				onRefresh={ onRefresh }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		unmount();

		// ASSERT: Nothing reached the server, so the listing is left alone.
		expect( closeModal ).toHaveBeenCalled();
		expect( onRefresh ).not.toHaveBeenCalled();
	} );
} );
