/**
 * Tests for the DeletePostModal confirmation, which names the selected titles
 * on the bulk path and reads as a single question on the single path.
 */
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

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
