/**
 * Tests for the BulkRollbackPostModal confirmation groups, which name the
 * titles the run permanently deletes and the ones it restores.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

import BulkRollbackPostModal from '@/components/BulkRollbackPostModal';
import type { UnifiedPostRow } from '@/types';

const AJAX_URL = 'https://example.com/wp-admin/admin-ajax.php';
const NONCE = 'test-nonce';

/**
 * Builds an eligible imported row; has_previous_content decides whether the
 * rollback restores or deletes.
 *
 * @param {number}  id                 Source post and item id seed.
 * @param {string}  title              Row title.
 * @param {boolean} hasPreviousContent Whether a previous version was captured.
 *
 * @return {UnifiedPostRow} Row fixture.
 */
function buildRow(
	id: number,
	title: string,
	hasPreviousContent: boolean
): UnifiedPostRow {
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
		has_previous_content: hasPreviousContent,
		edit_url: '',
	};
}

const MIXED: UnifiedPostRow[] = [
	buildRow( 1, 'Fresh creation', false ),
	buildRow( 2, 'Second creation', false ),
	buildRow( 3, 'Updated post', true ),
];

describe( 'BulkRollbackPostModal confirmation groups', () => {
	afterEach( () => {
		vi.unstubAllGlobals();
	} );

	it( 'Verifies that a mixed selection names the deletions and the restores', () => {
		// ARRANGE + ACT: Two fresh creations alongside one updated post.
		render(
			<BulkRollbackPostModal
				items={ MIXED }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
			/>
		);

		// ASSERT: Both group headings carry their own count.
		expect(
			screen.getByText( '2 posts will be permanently deleted:' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'1 post will be restored to its previous version:'
			)
		).toBeInTheDocument();

		// ASSERT: Each title sits in the list its own group heading names.
		const deletions = screen.getByRole( 'list', {
			name: '2 posts will be permanently deleted:',
		} );
		const restores = screen.getByRole( 'list', {
			name: '1 post will be restored to its previous version:',
		} );
		expect( deletions ).toHaveTextContent( 'Fresh creation' );
		expect( deletions ).toHaveTextContent( 'Second creation' );
		expect( deletions ).not.toHaveTextContent( 'Updated post' );
		expect( restores ).toHaveTextContent( 'Updated post' );
		expect( restores ).not.toHaveTextContent( 'Fresh creation' );
	} );

	it( 'Verifies that an all-restore selection shows no deletion group', () => {
		// ARRANGE + ACT: Every row captured a previous version.
		render(
			<BulkRollbackPostModal
				items={ [
					buildRow( 1, 'First update', true ),
					buildRow( 2, 'Second update', true ),
				] }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
			/>
		);

		// ASSERT: Only the restore group renders.
		expect(
			screen.getByText(
				'2 posts will be restored to their previous version:'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( /will be permanently deleted/ )
		).not.toBeInTheDocument();
	} );

	it( 'Verifies that a group lists ten titles and counts the remainder', () => {
		// ARRANGE: Thirteen deletions, three past the ten a group lists.
		const overCap = Array.from( { length: 13 }, ( _, index ) =>
			buildRow( index + 1, `Post ${ index + 1 }`, false )
		);

		// ACT: Confirm the over-cap selection.
		render(
			<BulkRollbackPostModal
				items={ overCap }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
			/>
		);

		// ASSERT: The tenth title shows, the eleventh does not, and the three
		// left over are counted rather than dropped.
		expect( screen.getByText( 'Post 10' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Post 11' ) ).not.toBeInTheDocument();
		expect( screen.getByText( '…and 3 more' ) ).toBeInTheDocument();
	} );

	it( 'Verifies that the groups give way to progress once the run starts', () => {
		// ARRANGE: A request held open so the run stays in flight.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockReturnValue( new Promise( () => {} ) )
		);
		render(
			<BulkRollbackPostModal
				items={ MIXED }
				ajaxurl={ AJAX_URL }
				nonce={ NONCE }
			/>
		);

		// ACT: Start the rollback.
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Roll back 3 posts' } )
		);

		// ASSERT: The confirmation copy is replaced by the progress copy.
		expect(
			screen.queryByText( /will be permanently deleted/ )
		).not.toBeInTheDocument();
		expect( screen.getByText( 'Rolled back 0 of 3' ) ).toBeInTheDocument();
	} );
} );
