/**
 * Tests for rollback API helpers
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import {
	isRollbackRestore,
	rollbackItem,
	rollbackItems,
} from '@/api/rollback';
import type { UnifiedPostRow } from '@/types';

const AJAX_URL = 'https://example.com/wp-admin/admin-ajax.php';
const NONCE = 'test-nonce';

/**
 * Builds a UnifiedPostRow fixture with rollback-eligible defaults.
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
		error_message: null,
		has_previous_content: false,
		edit_url: '',
		...overrides,
	};
}

describe( 'isRollbackRestore', () => {
	it( 'Verifies that rows without captured prior content delete on rollback', () => {
		// ARRANGE + ACT + ASSERT: a fresh creation has no previous content;
		// rolling it back deletes the local post.
		expect( isRollbackRestore( buildRow() ) ).toBe( false );
	} );

	it( 'Verifies that rows with captured prior content restore on rollback', () => {
		// ARRANGE + ACT + ASSERT: an updated row carries the snapshot, so
		// rolling it back restores the previous content.
		expect(
			isRollbackRestore( buildRow( { has_previous_content: true } ) )
		).toBe( true );
	} );
} );

describe( 'rollbackItem', () => {
	beforeEach( () => {
		global.fetch = vi.fn();
	} );

	afterEach( () => {
		vi.restoreAllMocks();
	} );

	it( 'Verifies that the request mirrors the single-item endpoint contract', async () => {
		// ARRANGE: a successful endpoint response.
		( global.fetch as any ).mockResolvedValue( {
			json: async () => ( { success: true, data: { action: 'deleted' } } ),
		} );

		// ACT: roll back a single item.
		await rollbackItem( 100, AJAX_URL, NONCE );

		// ASSERT: the request carries the action, nonce, and item id the
		// admin-ajax endpoint expects.
		const [ url, options ] = ( global.fetch as any ).mock.calls[ 0 ];
		expect( url ).toBe( AJAX_URL );
		const body = options.body as FormData;
		expect( body.get( 'action' ) ).toBe( 'safe_publish_rollback_item' );
		expect( body.get( 'nonce' ) ).toBe( NONCE );
		expect( body.get( 'item_id' ) ).toBe( '100' );
	} );

	it( 'Verifies that the server-reported action and message are returned on success', async () => {
		// ARRANGE: the endpoint reports a restore with its confirmation message.
		( global.fetch as any ).mockResolvedValue( {
			json: async () => ( {
				success: true,
				data: {
					action: 'restored',
					message: 'Post restored to its previous version.',
				},
			} ),
		} );

		// ACT: roll back the item.
		const outcome = await rollbackItem( 100, AJAX_URL, NONCE );

		// ASSERT: the outcome surfaces the server-reported action and message.
		expect( outcome ).toEqual( {
			success: true,
			action: 'restored',
			message: 'Post restored to its previous version.',
		} );
	} );

	it( 'Verifies that the server error message surfaces on failure', async () => {
		// ARRANGE: the endpoint rejects the request with a message payload.
		( global.fetch as any ).mockResolvedValue( {
			json: async () => ( { success: false, data: 'Invalid item ID' } ),
		} );

		// ACT: attempt the rollback.
		const outcome = await rollbackItem( 0, AJAX_URL, NONCE );

		// ASSERT: the failure outcome carries the server's message.
		expect( outcome ).toEqual( { success: false, error: 'Invalid item ID' } );
	} );

	it( 'Verifies that a network error becomes a failure outcome', async () => {
		// ARRANGE: fetch rejects at the network layer.
		( global.fetch as any ).mockRejectedValue( new Error( 'Network down' ) );

		// ACT: attempt the rollback.
		const outcome = await rollbackItem( 100, AJAX_URL, NONCE );

		// ASSERT: the rejection is reported as a failure outcome, not thrown.
		expect( outcome ).toEqual( { success: false, error: 'Network down' } );
	} );
} );

describe( 'rollbackItems', () => {
	beforeEach( () => {
		global.fetch = vi.fn();
	} );

	afterEach( () => {
		vi.restoreAllMocks();
	} );

	it( 'Verifies that each item is processed and progress advances once per item', async () => {
		// ARRANGE: every endpoint call succeeds; capture progress notifications.
		( global.fetch as any ).mockResolvedValue( {
			json: async () => ( { success: true, data: { action: 'deleted' } } ),
		} );
		const progress: Array< [ number, number ] > = [];

		// ACT: roll back three items.
		const result = await rollbackItems(
			[
				buildRow( { item_id: 1 } ),
				buildRow( { item_id: 2 } ),
				buildRow( { item_id: 3 } ),
			],
			AJAX_URL,
			NONCE,
			( done, total ) => progress.push( [ done, total ] )
		);

		// ASSERT: aggregate counters reflect three successes and progress
		// advanced once per item in order.
		expect( result.total ).toBe( 3 );
		expect( result.successful ).toBe( 3 );
		expect( result.failed ).toBe( 0 );
		expect( result.entries ).toHaveLength( 3 );
		expect( progress ).toEqual( [
			[ 1, 3 ],
			[ 2, 3 ],
			[ 3, 3 ],
		] );
	} );

	it( 'Verifies that a mid-list failure does not abort the remainder', async () => {
		// ARRANGE: the middle call fails; the others succeed.
		( global.fetch as any )
			.mockResolvedValueOnce( {
				json: async () =>
					( { success: true, data: { action: 'deleted' } } ),
			} )
			.mockResolvedValueOnce( {
				json: async () =>
					( {
						success: false,
						data: 'The post no longer exists',
					} ),
			} )
			.mockResolvedValueOnce( {
				json: async () =>
					( { success: true, data: { action: 'restored' } } ),
			} );

		// ACT: roll back three items.
		const result = await rollbackItems(
			[
				buildRow( { item_id: 1, title: 'A' } ),
				buildRow( { item_id: 2, title: 'B' } ),
				buildRow( { item_id: 3, title: 'C' } ),
			],
			AJAX_URL,
			NONCE
		);

		// ASSERT: the loop continues past the failed item and the failed
		// entry surfaces the server's message keyed to the right row.
		expect( result.successful ).toBe( 2 );
		expect( result.failed ).toBe( 1 );
		const failed = result.entries.find( ( entry ) => ! entry.outcome.success );
		expect( failed?.item.title ).toBe( 'B' );
		expect( failed?.outcome ).toEqual( {
			success: false,
			error: 'The post no longer exists',
		} );
	} );

	it( 'Verifies that a null item_id row is failed without hitting the endpoint', async () => {
		// ARRANGE: a row whose active items-table id is null.

		// ACT: attempt to roll the orphan row back.
		const result = await rollbackItems(
			[ buildRow( { item_id: null, title: 'Orphan' } ) ],
			AJAX_URL,
			NONCE
		);

		// ASSERT: no network request runs and the row is reported as failed.
		expect( global.fetch ).not.toHaveBeenCalled();
		expect( result.failed ).toBe( 1 );
		expect( result.entries[ 0 ].outcome.success ).toBe( false );
	} );
} );
