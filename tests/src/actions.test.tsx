/**
 * Tests for the unified Posts action factory.
 */
import { describe, expect, it } from 'vitest';
import {
	createPostsActions,
	type PostsActionsContext,
} from '@/actions';
import BulkRollbackPostModal from '@/components/BulkRollbackPostModal';
import RollbackPostModal from '@/components/RollbackPostModal';
import type {
	ChipState,
	ImportSyncStatus,
	LocalState,
	UnifiedPostRow,
} from '@/types';
import type { Action, ActionModal } from '@wordpress/dataviews/build-types';

const CONTEXT: PostsActionsContext = {
	ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
	nonce: 'test-nonce',
	restNonce: 'test-rest-nonce',
};

/**
 * Builds a UnifiedPostRow fixture; tests override fields to match the case.
 */
function buildRow( overrides: Partial< UnifiedPostRow > = {} ): UnifiedPostRow {
	return {
		id: 10,
		source_post_id: 10,
		title: 'Test',
		link: 'https://source.example/test',
		date_gmt: '2024-03-15T10:30:00Z',
		modified_gmt: '2024-03-15T10:30:00Z',
		post_type: 'post',
		status: 'publish',
		local_state: 'available',
		is_imported: false,
		wp_post_status: null,
		item_id: null,
		post_id: null,
		import_date_gmt: null,
		error_message: null,
		has_previous_content: false,
		edit_url: '',
		...overrides,
	};
}

/**
 * Builds an imported row with eligible defaults.
 */
function buildImportedRow(
	overrides: Partial< UnifiedPostRow > = {}
): UnifiedPostRow {
	return buildRow( {
		local_state: 'up-to-date' as LocalState,
		is_imported: true,
		wp_post_status: null,
		item_id: 100,
		post_id: 1024,
		import_date_gmt: '2024-03-15 10:30:00',
		edit_url: 'https://destination.example/wp-admin/post.php?post=1024',
		...overrides,
	} );
}

/**
 * Returns a modal action by id, throwing if absent or non-modal.
 */
function getModalAction(
	actions: Action< UnifiedPostRow >[],
	id: string
): ActionModal< UnifiedPostRow > {
	const action = actions.find( ( a ) => a.id === id );
	if ( ! action || ! ( 'RenderModal' in action ) ) {
		throw new Error( `Expected modal action with id "${ id }"` );
	}
	return action;
}

describe( 'createPostsActions per-state eligibility', () => {
	const ALL_CHIP: ChipState = 'all';
	const actions = createPostsActions( undefined, true, CONTEXT, {}, ALL_CHIP );

	it( 'Verifies that Import covers available, failed-with-source, and outdated rows', () => {
		// ARRANGE: the merged Import action and rows in each eligible state.
		const importAction = actions.find( ( a ) => a.id === 'import' );

		// ACT + ASSERT: available, failed-with-source, and outdated all show
		// Sync. Outdated needs a non-up-to-date verdict to stay eligible;
		// the default action set is built with an empty sync-statuses map.
		expect( importAction?.isEligible?.( buildRow() ) ).toBe( true );
		expect(
			importAction?.isEligible?.(
				buildRow( {
					local_state: 'failed',
					is_imported: false,
					wp_post_status: null,
					item_id: 50,
				} )
			)
		).toBe( true );
		expect(
			importAction?.isEligible?.( buildImportedRow( { local_state: 'outdated' } ) )
		).toBe( true );
	} );

	it( 'Verifies that Import hides on up-to-date imported and orphan-failure rows', () => {
		// ARRANGE: a sync-statuses map that reports up-to-date.
		const statuses: Record< number, { status: ImportSyncStatus } > = {
			10: { status: 'up-to-date' },
		};
		const importAction = createPostsActions(
			undefined,
			true,
			CONTEXT,
			statuses,
			ALL_CHIP
		).find( ( a ) => a.id === 'import' );

		// ACT + ASSERT: up-to-date imported hides Sync; failed without a
		// source_post_id (orphan) also hides — Sync needs a source endpoint.
		expect( importAction?.isEligible?.( buildImportedRow() ) ).toBe( false );
		expect(
			importAction?.isEligible?.(
				buildRow( {
					local_state: 'failed',
					source_post_id: null,
					item_id: 50,
				} )
			)
		).toBe( false );
	} );

	it( 'Verifies that Import is gated by authorization', () => {
		// ARRANGE: an unauthorized action set.
		const unauthorized = createPostsActions(
			undefined,
			false,
			CONTEXT,
			{},
			ALL_CHIP
		);
		const importAction = unauthorized.find( ( a ) => a.id === 'import' );

		// ACT + ASSERT: even an Available row hides Import when unauthorized.
		expect( importAction?.isEligible?.( buildRow() ) ).toBe( false );
	} );

	it( 'Verifies that Edit appears only on imported/outdated rows with an edit_url', () => {
		// ARRANGE: the Edit action under test.
		const editAction = actions.find( ( a ) => a.id === 'edit-post' );

		// ACT + ASSERT: imported and outdated rows expose Edit; missing
		// edit_url or other states hide it.
		expect( editAction?.isEligible?.( buildImportedRow() ) ).toBe( true );
		expect(
			editAction?.isEligible?.(
				buildImportedRow( { local_state: 'outdated' } )
			)
		).toBe( true );
		expect(
			editAction?.isEligible?.( buildImportedRow( { edit_url: '' } ) )
		).toBe( false );
		expect( editAction?.isEligible?.( buildRow() ) ).toBe( false );
	} );

	it( 'Verifies that Compare on Imported only shows on a confirmed outdated verdict', () => {
		// ARRANGE: imported rows shouldn't surface Compare until the async
		// sync check confirms a divergence — otherwise the action flashes
		// then vanishes once the up-to-date verdict lands.
		const compareLoading = actions.find( ( a ) => a.id === 'compare-post' );
		const compareOutdatedVerdict = createPostsActions(
			undefined,
			true,
			CONTEXT,
			{ 10: { status: 'outdated' } },
			ALL_CHIP
		).find( ( a ) => a.id === 'compare-post' );
		const compareUpToDate = createPostsActions(
			undefined,
			true,
			CONTEXT,
			{ 10: { status: 'up-to-date' } },
			ALL_CHIP
		).find( ( a ) => a.id === 'compare-post' );

		// ACT + ASSERT: imported + (no status / up-to-date) hides; imported +
		// outdated verdict shows.
		expect( compareLoading?.isEligible?.( buildImportedRow() ) ).toBe( false );
		expect(
			compareUpToDate?.isEligible?.( buildImportedRow() )
		).toBe( false );
		expect(
			compareOutdatedVerdict?.isEligible?.( buildImportedRow() )
		).toBe( true );
	} );

	it( 'Verifies that Compare always shows on server-confirmed outdated rows', () => {
		// ARRANGE: a local_state='outdated' row needs no client verdict —
		// the server already flagged the divergence.
		const compare = actions.find( ( a ) => a.id === 'compare-post' );

		// ACT + ASSERT: outdated shows regardless of empty syncStatuses.
		expect(
			compare?.isEligible?.(
				buildImportedRow( { local_state: 'outdated' } )
			)
		).toBe( true );
	} );

	it( 'Verifies that Dismiss is only present on the Failed chip', () => {
		// ARRANGE: action sets for the All chip and the Failed chip.
		const failedActions = createPostsActions(
			undefined,
			true,
			CONTEXT,
			{},
			'failed'
		);

		// ACT + ASSERT: Dismiss is absent off-chip and present on-chip.
		expect(
			actions.find( ( a ) => a.id === 'dismiss-failure' )
		).toBeUndefined();
		expect(
			failedActions.find( ( a ) => a.id === 'dismiss-failure' )
		).toBeDefined();
	} );

	it( 'Verifies that Dismiss is eligible only on failed rows with a source_post_id', () => {
		// ARRANGE: the Dismiss action from the Failed-chip action set.
		// Dismiss clears by source_post_id (not item_id) so older failure
		// siblings don't re-surface on refresh.
		const dismiss = createPostsActions(
			undefined,
			true,
			CONTEXT,
			{},
			'failed'
		).find( ( a ) => a.id === 'dismiss-failure' );

		// ACT + ASSERT: failed-with-source exposes Dismiss; orphan failures
		// or non-failed rows hide it.
		expect(
			dismiss?.isEligible?.(
				buildRow( { local_state: 'failed', source_post_id: 10 } )
			)
		).toBe( true );
		expect(
			dismiss?.isEligible?.(
				buildRow( { local_state: 'failed', source_post_id: null } )
			)
		).toBe( false );
		expect( dismiss?.isEligible?.( buildImportedRow() ) ).toBe( false );
		expect( dismiss?.isEligible?.( buildRow() ) ).toBe( false );
	} );

	it( 'Verifies that Rollback requires imported/outdated state and an item_id', () => {
		// ARRANGE: the Rollback action.
		const rollback = actions.find( ( a ) => a.id === 'rollback' );

		// ACT + ASSERT: imported with an item_id shows; missing item_id
		// or wrong state hides.
		expect( rollback?.isEligible?.( buildImportedRow() ) ).toBe( true );
		expect(
			rollback?.isEligible?.( buildImportedRow( { item_id: null } ) )
		).toBe( false );
		expect( rollback?.isEligible?.( buildRow() ) ).toBe( false );
	} );

	it( 'Verifies that single vs bulk rollback selects the matching modal', () => {
		// ARRANGE: the Rollback action.
		const rollback = getModalAction( actions, 'rollback' );

		// ACT + ASSERT: a single selection routes to RollbackPostModal.
		const single = rollback.RenderModal( {
			items: [ buildImportedRow() ],
		} );
		expect( single.type ).toBe( RollbackPostModal );

		// ACT + ASSERT: a multi selection routes to BulkRollbackPostModal.
		const bulk = rollback.RenderModal( {
			items: [
				buildImportedRow( { id: 1, source_post_id: 1 } ),
				buildImportedRow( { id: 2, source_post_id: 2 } ),
			],
		} );
		expect( bulk.type ).toBe( BulkRollbackPostModal );
	} );
} );
