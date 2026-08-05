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
		render(
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

		// ASSERT: The server message surfaces as a success notice, then the
		// listing refreshes and the modal closes.
		await waitFor( () =>
			expect( onNotice ).toHaveBeenCalledWith( {
				status: 'success',
				message: 'Post restored to its previous version.',
			} )
		);
		expect( onRefresh ).toHaveBeenCalled();
		expect( closeModal ).toHaveBeenCalled();
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
		render(
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

		// ASSERT: The failure stays in the modal — no notice, no refresh, no
		// close, so the operator can read the error and retry or cancel.
		expect(
			await screen.findByText( 'The post no longer exists' )
		).toBeInTheDocument();
		expect( onNotice ).not.toHaveBeenCalled();
		expect( onRefresh ).not.toHaveBeenCalled();
		expect( closeModal ).not.toHaveBeenCalled();
	} );
} );
