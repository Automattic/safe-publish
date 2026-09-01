/**
 * Tests for PostDiffModal refresh and error rendering.
 */
import { afterEach, describe, expect, it, vi, type Mock } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
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

		// ASSERT: Update applies the same bidirectional isolation as Compare,
		// inside the live region that announces the failure.
		expect( isolatedReason.tagName ).toBe( 'BDI' );
		expect( isolatedReason ).toHaveAttribute( 'dir', 'auto' );
		expect( screen.getByRole( 'alert' ) ).toContainElement( isolatedReason );
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
		await waitFor( () => {
			expect(
				getByRole( 'button', { name: 'Update' } )
			).not.toHaveAttribute( 'aria-disabled' );
		} );
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
