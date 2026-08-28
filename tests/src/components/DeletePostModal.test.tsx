/**
 * Tests for the DeletePostModal confirmation, which names the selected titles
 * on the bulk path and reads as a single question on the single path.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import DeletePostModal from '@/components/DeletePostModal';
import type { UnifiedPostRow } from '@/types';

const AJAX_URL = 'https://example.com/wp-admin/admin-ajax.php';
const NONCE = 'test-nonce';

/**
 * Builds an imported row eligible for trashing.
 *
 * @param {number} id    Source post id seed.
 * @param {string} title Row title.
 *
 * @return {UnifiedPostRow} Row fixture.
 */
function buildRow( id: number, title: string ): UnifiedPostRow {
	return {
		id,
		source_post_id: id,
		title,
		link: `https://source.example/${ id }`,
		date_gmt: '2026-03-15T10:30:00Z',
		modified_gmt: '2026-03-15T10:30:00Z',
		post_type: 'post',
		status: 'publish',
		local_state: 'up-to-date',
		is_imported: true,
		wp_post_status: 'draft',
		item_id: id + 100,
		post_id: id + 1000,
		import_date_gmt: '2026-03-15 10:30:00',
		has_previous_content: false,
		edit_url: '',
	};
}

describe( 'DeletePostModal confirmation', () => {
	it( 'Verifies that a bulk selection lists every selected title', () => {
		// ARRANGE + ACT: Three imported rows selected.
		render(
			<DeletePostModal
				items={ [
					buildRow( 1, 'First post' ),
					buildRow( 2, 'Second post' ),
					buildRow( 3, 'Third post' ),
				] }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
			/>
		);

		// ASSERT: The question labels the list of affected titles.
		const list = screen.getByRole( 'list' );
		expect( list ).toHaveAccessibleName(
			'Move 3 selected posts to trash?'
		);
		expect( list ).toHaveTextContent( 'First post' );
		expect( list ).toHaveTextContent( 'Second post' );
		expect( list ).toHaveTextContent( 'Third post' );
	} );

	it( 'Verifies that a single selection names the post in the question', () => {
		// ARRANGE + ACT: One imported row selected.
		render(
			<DeletePostModal
				items={ [ buildRow( 1, 'Lone post' ) ] }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
			/>
		);

		// ASSERT: The title reads inline, with no list to scan.
		expect(
			screen.getByText( 'Move "Lone post" to trash?' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'list' ) ).not.toBeInTheDocument();
	} );

	it( 'Verifies that the list shows ten titles and counts the remainder', () => {
		// ARRANGE: Eleven rows, one past the ten the list shows.
		const overCap = Array.from( { length: 11 }, ( _, index ) =>
			buildRow( index + 1, `Post ${ index + 1 }` )
		);

		// ACT: Confirm the over-cap selection.
		render(
			<DeletePostModal
				items={ overCap }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
			/>
		);

		// ASSERT: The tenth title shows, the eleventh is counted instead.
		expect( screen.getByText( 'Post 10' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Post 11' ) ).not.toBeInTheDocument();
		expect( screen.getByText( '…and 1 more' ) ).toBeInTheDocument();
	} );
} );

describe( 'DeletePostModal loading actions', () => {
	afterEach( () => {
		vi.unstubAllGlobals();
	} );

	it( 'Verifies that loading actions use accessible disabled semantics', async () => {
		// ARRANGE: Hold the trash request open at the in-flight stage.
		vi.stubGlobal( 'fetch', vi.fn().mockReturnValue( new Promise( () => {} ) ) );
		render(
			<DeletePostModal
				items={ [ buildRow( 1, 'Lone post' ) ] }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
			/>
		);

		// ACT: Start moving the post to trash.
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Move to Trash' } )
		);
		const deleting = await screen.findByRole( 'button', {
			name: 'Moving to trash…',
		} );

		// ASSERT: Loading actions use aria-disabled instead of native disabled.
		expect( deleting ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( deleting ).not.toHaveAttribute( 'disabled' );
		const cancel = screen.getByRole( 'button', { name: 'Cancel' } );
		expect( cancel ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( cancel ).not.toHaveAttribute( 'disabled' );
	} );
} );

describe( 'DeletePostModal listing refresh', () => {
	const BULK = [ buildRow( 1, 'First post' ), buildRow( 2, 'Second post' ) ];

	afterEach( () => {
		vi.unstubAllGlobals();
	} );

	/**
	 * Stubs the trash endpoint with a canned response.
	 *
	 * @param {*} payload Response body the endpoint returns.
	 */
	function stubTrash( payload: unknown ): void {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( { json: async () => payload } )
		);
	}

	it( 'Verifies that a bulk trash which skipped every post still refreshes', async () => {
		// ARRANGE: Every selected post was already gone, so none was trashed.
		stubTrash( { success: true, data: { deleted: 0, skipped: 2 } } );
		const closeModal = vi.fn();
		const onRefresh = vi.fn();

		// ACT: Run the trash and wait for it to settle.
		const { unmount } = render(
			<DeletePostModal
				items={ BULK }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
				closeModal={ closeModal }
				onRefresh={ onRefresh }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Move to Trash' } )
		);
		await waitFor( () => expect( closeModal ).toHaveBeenCalled() );

		// ACT: Dismiss the modal.
		unmount();

		// ASSERT: A zero count means the listing was stale, so it refreshes.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that a failed trash refreshes once the error has been read', async () => {
		// ARRANGE: The endpoint refuses the request.
		stubTrash( { success: false, data: 'The post no longer exists' } );
		const onRefresh = vi.fn();

		// ACT: Attempt the trash and wait for the error.
		const { unmount } = render(
			<DeletePostModal
				items={ BULK }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
				onRefresh={ onRefresh }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Move to Trash' } )
		);
		expect(
			await screen.findByText( 'The post no longer exists' )
		).toBeInTheDocument();

		// ASSERT: The error stays readable, so the refresh is still owed.
		expect( onRefresh ).not.toHaveBeenCalled();

		// ACT: Dismiss the modal.
		unmount();

		// ASSERT: The listing refreshes anyway.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that canceling without attempting a trash does not refresh', () => {
		// ARRANGE: A confirmation the operator backs out of.
		const closeModal = vi.fn();
		const onRefresh = vi.fn();

		// ACT: Cancel, then dismiss the modal.
		const { unmount } = render(
			<DeletePostModal
				items={ BULK }
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
