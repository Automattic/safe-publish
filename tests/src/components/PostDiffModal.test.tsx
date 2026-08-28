/**
 * Tests for PostDiffModal refresh and error rendering.
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

describe( 'PostDiffModal', () => {
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

	it( 'Verifies that an HTTP failure isolates the source-provided reason', async () => {
		// ARRANGE: The source reason contains its own directional controls.
		const reason = '\u202eSource refused the request.\u202c';
		mockApiFetch.mockRejectedValue( {
			message: `Source site returned HTTP error 401. ${ reason }`,
			data: {
				source_error: {
					message: reason,
					template:
						'<reason /> Source site returned HTTP error 401.',
				},
			},
		} );

		// ACT: Render Compare's failed request.
		render(
			<PostDiffModal
				items={ [ ROW ] }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
				syncStatus="outdated"
			/>
		);
		const isolatedReason = await screen.findByText( reason );

		// ASSERT: The untrusted run has a markup isolation boundary, while the
		// translated sentence remains outside it.
		expect( isolatedReason.tagName ).toBe( 'BDI' );
		expect( isolatedReason ).toHaveAttribute( 'dir', 'auto' );
		expect( isolatedReason ).toHaveTextContent( reason );
		expect( isolatedReason.parentElement?.firstChild ).toBe(
			isolatedReason
		);
		expect( isolatedReason.parentElement ).toHaveTextContent(
			'Source site returned HTTP error 401.'
		);
	} );

	it( 'Verifies that an Update failure isolates the source reason', async () => {
		// ARRANGE: Compare succeeds, then Update receives an HTTP failure.
		const reason = '\u202eSource refused the request.\u202c';
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( {
				json: async () => ( {
					success: false,
					data: {
						message: `Source site returned HTTP error 401. ${ reason }`,
						source_error: {
							message: reason,
							template:
								'Source site returned HTTP error 401. <reason />',
						},
					},
				} ),
			} )
		);

		// ACT: Submit Update and find its source-provided reason.
		renderWithDiff( vi.fn() );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Update' } )
		);
		const isolatedReason = await screen.findByText( reason );

		// ASSERT: Update applies the same bidirectional isolation as Compare.
		expect( isolatedReason.tagName ).toBe( 'BDI' );
		expect( isolatedReason ).toHaveAttribute( 'dir', 'auto' );
	} );
} );
