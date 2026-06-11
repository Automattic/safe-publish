/**
 * Tests for rollback API helpers
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import {
	isRollbackRestore,
	rollbackItem,
	rollbackItems,
} from '@/api/rollback';
import type { ImportedPost } from '@/types';

const AJAX_URL = 'https://example.com/wp-admin/admin-ajax.php';
const NONCE = 'test-nonce';

/**
 * Builds an ImportedPost fixture with eligible-rollback defaults. Tests
 * override the fields that matter for the case at hand.
 */
function buildImportedPost(
	overrides: Partial< ImportedPost > = {}
): ImportedPost {
	return {
		id: 1,
		source_post_id: 10,
		title: 'Test',
		post_type: 'post',
		local_status: 'publish',
		edit_url: '',
		local_link: '',
		source_link: '',
		item_id: 100,
		session_id: 5,
		rollback_status: 'success',
		has_previous_content: false,
		import_date_gmt: null,
		...overrides,
	};
}

describe( 'isRollbackRestore', () => {
	it( 'returns false for newly created (success) rows that get deleted', () => {
		// ARRANGE + ACT + ASSERT: a `success` row is a fresh creation.
		expect(
			isRollbackRestore( buildImportedPost( { rollback_status: 'success' } ) )
		).toBe( false );
	} );

	it( 'returns true for updated rows that restore a previous version', () => {
		// ARRANGE + ACT + ASSERT: an `updated` row restores its snapshot.
		expect(
			isRollbackRestore( buildImportedPost( { rollback_status: 'updated' } ) )
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

	it( 'posts the action, nonce, and item_id to the ajax endpoint', async () => {
		// ARRANGE: a successful endpoint response.
		( global.fetch as any ).mockResolvedValue( {
			json: async () => ( { success: true, data: { action: 'deleted' } } ),
		} );

		// ACT: roll back a single item.
		await rollbackItem( 100, AJAX_URL, NONCE );

		// ASSERT: the request mirrors the single-item endpoint contract.
		const [ url, options ] = ( global.fetch as any ).mock.calls[ 0 ];
		expect( url ).toBe( AJAX_URL );
		const body = options.body as FormData;
		expect( body.get( 'action' ) ).toBe( 'safe_publish_rollback_item' );
		expect( body.get( 'nonce' ) ).toBe( NONCE );
		expect( body.get( 'item_id' ) ).toBe( '100' );
	} );

	it( 'returns the server action on success', async () => {
		// ARRANGE: the endpoint reports a restore.
		( global.fetch as any ).mockResolvedValue( {
			json: async () => ( { success: true, data: { action: 'restored' } } ),
		} );

		// ACT: roll back the item.
		const outcome = await rollbackItem( 100, AJAX_URL, NONCE );

		// ASSERT: the outcome carries the server-reported action.
		expect( outcome ).toEqual( { success: true, action: 'restored' } );
	} );

	it( 'surfaces the server error message on failure', async () => {
		// ARRANGE: the endpoint rejects the request.
		( global.fetch as any ).mockResolvedValue( {
			json: async () => ( { success: false, data: 'Invalid item ID' } ),
		} );

		// ACT: attempt the rollback.
		const outcome = await rollbackItem( 0, AJAX_URL, NONCE );

		// ASSERT: the failure carries the server message.
		expect( outcome ).toEqual( { success: false, error: 'Invalid item ID' } );
	} );

	it( 'treats a network error as a failure outcome', async () => {
		// ARRANGE: fetch rejects at the network layer.
		( global.fetch as any ).mockRejectedValue( new Error( 'Network down' ) );

		// ACT: attempt the rollback.
		const outcome = await rollbackItem( 100, AJAX_URL, NONCE );

		// ASSERT: the rejection becomes a failure outcome, not a throw.
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

	it( 'rolls back every item and reports progress per item', async () => {
		// ARRANGE: all requests succeed; capture progress callbacks.
		( global.fetch as any ).mockResolvedValue( {
			json: async () => ( { success: true, data: { action: 'deleted' } } ),
		} );
		const progress: Array< [ number, number ] > = [];

		// ACT: roll back three items.
		const result = await rollbackItems(
			[
				buildImportedPost( { item_id: 1 } ),
				buildImportedPost( { item_id: 2 } ),
				buildImportedPost( { item_id: 3 } ),
			],
			AJAX_URL,
			NONCE,
			( done, total ) => progress.push( [ done, total ] )
		);

		// ASSERT: all succeeded and progress advanced once per item.
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

	it( 'continues past a failed item and tracks the partial failure', async () => {
		// ARRANGE: the middle request fails, the others succeed.
		( global.fetch as any )
			.mockResolvedValueOnce( {
				json: async () => ( { success: true, data: { action: 'deleted' } } ),
			} )
			.mockResolvedValueOnce( {
				json: async () => ( {
					success: false,
					data: 'The post no longer exists',
				} ),
			} )
			.mockResolvedValueOnce( {
				json: async () => ( { success: true, data: { action: 'restored' } } ),
			} );

		// ACT: roll back three items.
		const result = await rollbackItems(
			[
				buildImportedPost( { item_id: 1, title: 'A' } ),
				buildImportedPost( { item_id: 2, title: 'B' } ),
				buildImportedPost( { item_id: 3, title: 'C' } ),
			],
			AJAX_URL,
			NONCE
		);

		// ASSERT: two succeeded, one failed, and the failure names its row.
		expect( result.successful ).toBe( 2 );
		expect( result.failed ).toBe( 1 );
		const failed = result.entries.find( ( entry ) => ! entry.outcome.success );
		expect( failed?.item.title ).toBe( 'B' );
		expect( failed?.outcome ).toEqual( {
			success: false,
			error: 'The post no longer exists',
		} );
	} );

	it( 'fails a null item_id row without calling the endpoint', async () => {
		// ARRANGE: a row whose import record is gone.

		// ACT: attempt to roll it back.
		const result = await rollbackItems(
			[ buildImportedPost( { item_id: null, title: 'Orphan' } ) ],
			AJAX_URL,
			NONCE
		);

		// ASSERT: no request was made and the row is reported as failed.
		expect( global.fetch ).not.toHaveBeenCalled();
		expect( result.failed ).toBe( 1 );
		expect( result.entries[ 0 ].outcome.success ).toBe( false );
	} );
} );
