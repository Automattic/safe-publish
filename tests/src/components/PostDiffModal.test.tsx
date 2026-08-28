/**
 * Tests for the PostDiffModal listing refresh, which is owed once an update
 * has been submitted from the compare view.
 */
import { afterEach, describe, expect, it, vi, type Mock } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import PostDiffModal from '@/components/PostDiffModal';
import type { UnifiedPostRow } from '@/types';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const mockApiFetch = apiFetch as unknown as Mock;

const AJAX_URL = 'https://example.com/wp-admin/admin-ajax.php';
const NONCE = 'test-nonce';

const ROW: UnifiedPostRow = {
	id: 7,
	source_post_id: 7,
	title: 'Outdated copy',
	link: 'https://source.example/7',
	date_gmt: '2026-03-15T10:30:00Z',
	modified_gmt: '2026-03-15T10:30:00Z',
	post_type: 'post',
	status: 'publish',
	local_state: 'outdated',
	is_imported: true,
	wp_post_status: 'publish',
	item_id: 107,
	post_id: 1007,
	import_date_gmt: '2026-03-15 10:30:00',
	has_previous_content: true,
	edit_url: '',
};

/**
 * Renders the compare modal over a diff that reports content changes, so the
 * Update button is offered.
 *
 * @param {Function} onRefresh Listing refresh callback.
 *
 * @return {Object} The render result.
 */
function renderWithDiff( onRefresh: () => void ) {
	mockApiFetch.mockResolvedValue( {
		contentDiffHtml: '<ins>Incoming paragraph</ins>',
		blockDiffs: [],
	} );

	return render(
		<PostDiffModal
			items={ [ ROW ] }
			ajaxurl={ AJAX_URL }
			nonce={ NONCE }
			syncStatus="outdated"
			onRefresh={ onRefresh }
		/>
	);
}

afterEach( () => {
	mockApiFetch.mockReset();
	vi.unstubAllGlobals();
} );

describe( 'PostDiffModal listing refresh', () => {
	/**
	 * Stubs the update endpoint so a submit resolves successfully.
	 */
	function stubSuccessfulUpdate(): void {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: true,
					data: { edit_url: 'https://example.com/edit' },
				} ),
			} )
		);
	}

	it( 'Verifies that a loading update uses accessible disabled semantics', async () => {
		// ARRANGE: Hold the update request open at the in-flight stage.
		vi.stubGlobal( 'fetch', vi.fn().mockReturnValue( new Promise( () => {} ) ) );
		renderWithDiff( vi.fn() );
		const update = await screen.findByRole( 'button', { name: 'Update' } );

		// ACT: Start the update.
		fireEvent.click( update );

		// ASSERT: The action uses aria-disabled instead of native disabled.
		expect( update ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( update ).not.toHaveAttribute( 'disabled' );
	} );

	it( 'Verifies that a completed update refreshes the listing once its result has been read', async () => {
		// ARRANGE: The update endpoint accepts the submit.
		stubSuccessfulUpdate();
		const onRefresh = vi.fn();

		// ACT: Submit the update and wait for its confirmation.
		const { unmount } = renderWithDiff( onRefresh );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Update' } )
		);
		expect( await screen.findByText( /Update applied/ ) ).toBeInTheDocument();

		// ASSERT: The confirmation stays readable, so the refresh is owed.
		expect( onRefresh ).not.toHaveBeenCalled();

		// ACT: Dismiss the modal.
		unmount();

		// ASSERT: The listing refreshes once.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that a dismissal beating the response still refreshes the listing', async () => {
		// ARRANGE: The update endpoint accepts the submit.
		stubSuccessfulUpdate();
		const onRefresh = vi.fn();

		// ACT: Submit the update and dismiss before the response lands, as a
		// timed-out request would.
		const { unmount } = renderWithDiff( onRefresh );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Update' } )
		);
		unmount();

		// ASSERT: The server may have applied the update, so the listing
		// refreshes despite the unread result.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that a refused update still refreshes the listing', async () => {
		// ARRANGE: The update endpoint refuses the submit.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: false,
					data: 'The source post is no longer available',
				} ),
			} )
		);
		const onRefresh = vi.fn();

		// ACT: Submit the update, then dismiss once it has failed.
		const { unmount } = renderWithDiff( onRefresh );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Update' } )
		);
		unmount();

		// ASSERT: The server may have applied the update despite the response,
		// so the listing refreshes anyway.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that reading a diff without updating does not refresh', async () => {
		// ARRANGE: A compare view the operator only reads.
		const onRefresh = vi.fn();

		// ACT: Wait for the diff, then dismiss without submitting.
		const { unmount } = renderWithDiff( onRefresh );
		await screen.findByRole( 'button', { name: 'Update' } );
		unmount();

		// ASSERT: Nothing was submitted, so the listing is left alone.
		expect( onRefresh ).not.toHaveBeenCalled();
	} );
} );

describe( 'PostDiffModal keyboard focus', () => {
	it( 'Verifies that a completed update keeps its action focused after the diff clears', async () => {
		// ARRANGE: The first preview reports changes and the post-update
		// refetch reports none, which is what retires the Update button.
		mockApiFetch
			.mockResolvedValueOnce( {
				contentDiffHtml: '<ins>Incoming paragraph</ins>',
				blockDiffs: [],
			} )
			.mockResolvedValue( { contentDiffHtml: '', blockDiffs: [] } );
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: true,
					data: { edit_url: 'https://example.com/edit' },
				} ),
			} )
		);
		render(
			<PostDiffModal
				items={ [ ROW ] }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
				syncStatus="outdated"
				onRefresh={ vi.fn() }
			/>
		);
		const update = await screen.findByRole( 'button', { name: 'Update' } );
		update.focus();

		// ACT: Submit from the focused action, then wait out the refetch that
		// empties the diff.
		fireEvent.click( update );
		expect( await screen.findByText( /Update applied/ ) ).toBeInTheDocument();
		expect(
			await screen.findByText( 'No differences detected.' )
		).toBeInTheDocument();

		// ASSERT: The action outlives the cleared diff, holds focus so Escape
		// still reaches the dialog, and refuses a second submit.
		expect( update ).toBeInTheDocument();
		expect( update ).toHaveFocus();
		expect( update ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( update ).not.toHaveAttribute( 'disabled' );
	} );

	it( 'Verifies that an update leaving differences behind stays submittable', async () => {
		// ARRANGE: Every preview reports differences, so the refetch that
		// follows the update still has something to show.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: true,
					data: { edit_url: 'https://example.com/edit' },
				} ),
			} )
		);
		const { getByRole } = renderWithDiff( vi.fn() );

		// ACT: Complete an update that cannot clear the whole diff.
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Update' } )
		);
		expect(
			await screen.findByText( /Some differences remain/ )
		).toBeInTheDocument();

		// ASSERT: The action stays live, so the operator can resubmit.
		expect( getByRole( 'button', { name: 'Update' } ) ).not.toHaveAttribute(
			'aria-disabled'
		);
	} );
} );

describe( 'PostDiffModal outcome announcements', () => {
	it( 'Verifies that a completed update lands in a live region', async () => {
		// ARRANGE: The update endpoint accepts the submit.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: true,
					data: { edit_url: 'https://example.com/edit' },
				} ),
			} )
		);
		renderWithDiff( vi.fn() );

		// ACT: Submit the update.
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Update' } )
		);

		// ASSERT: Focus stays on the action, so the outcome only reaches a
		// screen reader as a status message.
		expect( await screen.findByRole( 'status' ) ).toHaveTextContent(
			'Update applied'
		);
	} );

	it( 'Verifies that a refused update lands in a live region', async () => {
		// ARRANGE: The update endpoint refuses the submit.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: false,
					data: 'The source post is no longer available',
				} ),
			} )
		);
		renderWithDiff( vi.fn() );

		// ACT: Submit the update.
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Update' } )
		);

		// ASSERT: The reason is announced rather than left for the operator
		// to discover.
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'The source post is no longer available'
		);
	} );
} );
