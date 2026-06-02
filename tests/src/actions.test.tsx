/**
 * Tests for action modal components and bulk operations
 */
import { describe, expect, it } from 'vitest';
import { createActions, createImportedActions } from '@/actions';
import BulkRollbackPostModal from '@/components/BulkRollbackPostModal';
import RollbackPostModal from '@/components/RollbackPostModal';
import type { ImportedPost, Post } from '@/types';
import type { Action, ActionModal } from '@wordpress/dataviews/build-types';

const actions = createActions();

/**
 * Builds a Post fixture with sensible listing-shape defaults. Tests can
 * override any field that matters for the case at hand.
 */
function buildPost( overrides: Partial< Post > = {} ): Post {
	return {
		id: 1,
		link: '',
		title: 'Test',
		date_gmt: '',
		modified_gmt: '',
		post_type: 'post',
		status: 'publish',
		...overrides,
	};
}

/**
 * Builds an ImportedPost fixture with eligible-rollback defaults. Tests
 * override any field that matters for the case at hand.
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
		modified_gmt: '',
		edit_url: '',
		source_link: '',
		item_id: 100,
		session_id: 5,
		rollback_status: 'success',
		has_previous_content: false,
		rolled_back: false,
		import_date_gmt: null,
		...overrides,
	};
}

/**
 * Returns the modal action with the given id, throwing if absent or not modal.
 */
function getModalAction( id: string ): ActionModal< Post > {
	const action = actions.find( ( a: Action< Post > ) => a.id === id );
	if ( ! action || ! ( 'RenderModal' in action ) ) {
		throw new Error( `Expected modal action with id "${ id }"` );
	}
	return action;
}

describe( 'Actions configuration', () => {
	it( 'should export actions array', () => {
		expect( actions ).toBeDefined();
		expect( Array.isArray( actions ) ).toBe( true );
	} );

	it( 'should have bulk-import action', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		expect( bulkAction ).toBeDefined();
		expect( typeof bulkAction?.label ).toBe( 'function' );
		expect( bulkAction?.isPrimary ).toBe( true );
		expect( bulkAction?.supportsBulk ).toBe( true );
	} );

	it( 'bulk-import label returns "Import" for a single non-imported item', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		const label = typeof bulkAction?.label === 'function'
			? bulkAction.label( [ buildPost( { is_imported: false } ) ] )
			: bulkAction?.label;
		expect( label ).toBe( 'Import' );
	} );

	it( 'bulk-import label returns "Update" for a single imported item with an update', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		const label = typeof bulkAction?.label === 'function'
			? bulkAction.label( [ buildPost( { is_imported: true, has_update: true } ) ] )
			: bulkAction?.label;
		expect( label ).toBe( 'Update' );
	} );

	it( 'bulk-import label returns "Import / Update" for multiple items', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		const label = typeof bulkAction?.label === 'function'
			? bulkAction.label( [
				buildPost( { id: 1, title: 'A', is_imported: false } ),
				buildPost( { id: 2, title: 'B', is_imported: true, has_update: true } ),
			] )
			: bulkAction?.label;
		expect( label ).toBe( 'Import / Update' );
	} );

	it( 'bulk-import action isEligible covers posts that can be imported or updated', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		expect( bulkAction?.isEligible?.( buildPost( { is_imported: false } ) ) ).toBe( true );
		expect( bulkAction?.isEligible?.( buildPost( { is_imported: true, has_update: true } ) ) ).toBe( true );
		expect( bulkAction?.isEligible?.( buildPost( { is_imported: true, has_update: false } ) ) ).toBe( false );
	} );

	it( 'bulk-import isEligible returns false when not authorized', () => {
		const unauthorized = createActions( undefined, false );
		const bulkAction = unauthorized.find( ( a ) => a.id === 'bulk-import' );
		const importable = buildPost( { is_imported: false } );
		const updatable = buildPost( { is_imported: true, has_update: true } );
		expect( bulkAction?.isEligible?.( importable ) ).toBe( false );
		expect( bulkAction?.isEligible?.( updatable ) ).toBe( false );
	} );



	it( 'should have post diff action', () => {
		const diffAction = getModalAction( 'post-diff' );
		expect( diffAction.label ).toBe( 'Post Diff' );
		expect( diffAction.supportsBulk ).toBe( false );
		expect( diffAction.modalSize ).toBe( 'fill' );
	} );
} );

describe( 'Bulk import action', () => {
	it( 'should have RenderModal component', () => {
		const bulkAction = getModalAction( 'bulk-import' );
		expect( typeof bulkAction.RenderModal ).toBe( 'function' );
	} );

	it( 'should hide modal header', () => {
		const bulkAction = getModalAction( 'bulk-import' );
		expect( bulkAction.hideModalHeader ).toBe( true );
	} );

	it( 'should focus on first content element', () => {
		const bulkAction = getModalAction( 'bulk-import' );
		expect( bulkAction.modalFocusOnMount ).toBe( 'firstContentElement' );
	} );
} );

describe( 'Post diff action', () => {
	it( 'should have RenderModal component', () => {
		const diffAction = getModalAction( 'post-diff' );
		expect( typeof diffAction.RenderModal ).toBe( 'function' );
	} );

	it( 'should have fill modal size', () => {
		const diffAction = getModalAction( 'post-diff' );
		expect( diffAction.modalSize ).toBe( 'fill' );
	} );

	it( 'isEligible only allows imported posts (avoids 404 from non-mapped source posts)', () => {
		// ARRANGE: get the action under test.
		const diffAction = getModalAction( 'post-diff' );

		// ACT + ASSERT: imported posts have a local mapping to diff against.
		expect(
			diffAction?.isEligible?.( {
				id: 1, link: '', title: 'Test', modified_gmt: '',
				date_gmt: '', post_type: 'post', status: 'publish',
				is_imported: true,
			} )
		).toBe( true );

		// ACT + ASSERT: source-only posts cannot be diffed — no local copy exists.
		expect(
			diffAction?.isEligible?.( {
				id: 1, link: '', title: 'Test', modified_gmt: '',
				date_gmt: '', post_type: 'post', status: 'publish',
				is_imported: false,
			} )
		).toBe( false );
	} );
} );

describe( 'createImportedActions rollback action', () => {
	const importedActions = createImportedActions();

	/**
	 * Returns the rollback modal action, throwing if absent or not modal.
	 */
	function getRollbackAction(): ActionModal< ImportedPost > {
		const action = importedActions.find(
			( a: Action< ImportedPost > ) => a.id === 'rollback'
		);
		if ( ! action || ! ( 'RenderModal' in action ) ) {
			throw new Error( 'Expected modal action with id "rollback"' );
		}
		return action;
	}

	it( 'supports bulk selection and stays destructive', () => {
		// ARRANGE + ACT + ASSERT: rollback opts into the bulk toolbar.
		const rollback = getRollbackAction();
		expect( rollback.supportsBulk ).toBe( true );
		expect( rollback.isDestructive ).toBe( true );
	} );

	it( 'is eligible only for non-rolled-back success/updated rows with an item_id', () => {
		// ARRANGE: the rollback action under test.
		const rollback = getRollbackAction();

		// ACT + ASSERT: created and updated rows can be rolled back.
		expect(
			rollback.isEligible?.( buildImportedPost( { rollback_status: 'success' } ) )
		).toBe( true );
		expect(
			rollback.isEligible?.( buildImportedPost( { rollback_status: 'updated' } ) )
		).toBe( true );

		// ACT + ASSERT: already-rolled-back, record-less, and error rows cannot.
		expect(
			rollback.isEligible?.( buildImportedPost( { rolled_back: true } ) )
		).toBe( false );
		expect(
			rollback.isEligible?.( buildImportedPost( { item_id: null } ) )
		).toBe( false );
		expect(
			rollback.isEligible?.( buildImportedPost( { rollback_status: 'error' } ) )
		).toBe( false );
	} );

	it( 'delegates to the single modal for one item and the bulk modal for many', () => {
		// ARRANGE: the rollback action under test.
		const rollback = getRollbackAction();

		// ACT + ASSERT: a single selection keeps the original single-row modal.
		const single = rollback.RenderModal( {
			items: [ buildImportedPost() ],
		} );
		expect( single.type ).toBe( RollbackPostModal );

		// ACT + ASSERT: a multi selection routes to the bulk modal.
		const bulk = rollback.RenderModal( {
			items: [ buildImportedPost( { id: 1 } ), buildImportedPost( { id: 2 } ) ],
		} );
		expect( bulk.type ).toBe( BulkRollbackPostModal );
	} );
} );
