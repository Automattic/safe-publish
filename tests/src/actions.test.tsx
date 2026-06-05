/**
 * Tests for action modal components and bulk operations
 */
import { describe, expect, it, vi } from 'vitest';
import {
	createActions,
	createImportedActions,
	type ImportedActionsContext,
	type SourceActionsContext,
} from '@/actions';
import BulkRollbackPostModal from '@/components/BulkRollbackPostModal';
import RollbackPostModal from '@/components/RollbackPostModal';
import type { ImportedPost, Post } from '@/types';
import type { Action, ActionModal } from '@wordpress/dataviews/build-types';

const SOURCE_CONTEXT: SourceActionsContext = {
	ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
	nonce: 'test-nonce',
};

const IMPORTED_CONTEXT: ImportedActionsContext = {
	...SOURCE_CONTEXT,
	restNonce: 'test-rest-nonce',
};

const actions = createActions( undefined, true, SOURCE_CONTEXT );

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
		edit_url: '',
		source_link: '',
		item_id: 100,
		session_id: 5,
		rollback_status: 'success',
		has_previous_content: false,
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

	it( 'should have import action', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'import' );
		expect( bulkAction ).toBeDefined();
		expect( bulkAction?.label ).toBe( 'Import' );
		expect( bulkAction?.isPrimary ).toBe( true );
		expect( bulkAction?.supportsBulk ).toBe( true );
	} );

	it( 'import isEligible only allows non-imported items (Update lives on the Imports tab)', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'import' );
		expect( bulkAction?.isEligible?.( buildPost( { is_imported: false } ) ) ).toBe( true );
		expect( bulkAction?.isEligible?.( buildPost( { is_imported: true, sync_status: 'outdated' } ) ) ).toBe( false );
		expect( bulkAction?.isEligible?.( buildPost( { is_imported: true, sync_status: 'up-to-date' } ) ) ).toBe( false );
		expect( bulkAction?.isEligible?.( buildPost( { is_imported: true, sync_status: 'unknown' } ) ) ).toBe( false );
	} );

	it( 'import isEligible returns false when not authorized', () => {
		const unauthorized = createActions( undefined, false, SOURCE_CONTEXT );
		const bulkAction = unauthorized.find( ( a ) => a.id === 'import' );
		expect( bulkAction?.isEligible?.( buildPost( { is_imported: false } ) ) ).toBe( false );
	} );
} );

describe( 'Import action', () => {
	it( 'should have RenderModal component', () => {
		const bulkAction = getModalAction( 'import' );
		expect( typeof bulkAction.RenderModal ).toBe( 'function' );
	} );

	it( 'should hide modal header', () => {
		const bulkAction = getModalAction( 'import' );
		expect( bulkAction.hideModalHeader ).toBe( true );
	} );

	it( 'should focus on first content element', () => {
		const bulkAction = getModalAction( 'import' );
		expect( bulkAction.modalFocusOnMount ).toBe( 'firstContentElement' );
	} );
} );

describe( 'View in Imports action', () => {
	const viewAction = actions.find( ( a ) => a.id === 'view-in-imports' );

	it( 'is hidden for non-imported rows', () => {
		// ARRANGE + ACT + ASSERT: non-imported rows have no Imports counterpart to focus.
		expect( viewAction?.isEligible?.( buildPost( { is_imported: false } ) ) ).toBe( false );
	} );

	it( 'is eligible for imported rows', () => {
		// ARRANGE + ACT + ASSERT: imported rows surface the deep link.
		expect( viewAction?.isEligible?.( buildPost( { is_imported: true } ) ) ).toBe( true );
	} );

	it( 'is a primary action so it sits in the row toolbar, not the kebab menu', () => {
		// ARRANGE + ACT + ASSERT: primary so the imported-row toolbar shows it inline.
		expect( viewAction?.isPrimary ).toBe( true );
	} );

	it( 'is not exposed on the Imports → Posts tab itself', () => {
		// ARRANGE: the Imports → Posts action set.
		const importedActions = createImportedActions( undefined, IMPORTED_CONTEXT, {} );
		// ACT + ASSERT: View in Imports doesn't loop the user back to the same tab.
		expect( importedActions.find( ( a ) => a.id === 'view-in-imports' ) ).toBeUndefined();
	} );

	it( 'navigates to the Imports page with focus_source set to the row id', () => {
		// ARRANGE: stub window.location + the admin-data global the callback reads.
		const originalLocation = window.location;
		const originalData = window.safePublishAdminData;
		const hrefSpy = vi.fn();
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: {
				get href() { return ''; },
				set href( value: string ) { hrefSpy( value ); },
			},
		} );
		window.safePublishAdminData = {
			ajaxurl: 'http://test.local/wp-admin/admin-ajax.php',
			nonce: 'n',
			restNonce: 'rn',
			settingsUrl: 'http://test.local/wp-admin/admin.php?page=safe-publish-settings',
			importsUrl: 'http://test.local/wp-admin/admin.php?page=safe-publish-imports',
			containerId: 'test-container',
		};

		try {
			// ACT: callback runs for an imported row whose source id is 42.
			if ( ! viewAction || ! ( 'callback' in viewAction ) ) {
				throw new Error( 'Expected view-in-imports to be a button action with a callback' );
			}
			viewAction.callback(
				[ buildPost( { id: 42, is_imported: true } ) ],
				{} as never
			);

			// ASSERT: navigation hit importsUrl with focus_source=42 appended.
			expect( hrefSpy ).toHaveBeenCalledTimes( 1 );
			const navigatedTo = hrefSpy.mock.calls[ 0 ][ 0 ] as string;
			expect( navigatedTo ).toContain( 'page=safe-publish-imports' );
			expect( navigatedTo ).toContain( 'focus_source=42' );
		} finally {
			Object.defineProperty( window, 'location', {
				configurable: true,
				value: originalLocation,
			} );
			window.safePublishAdminData = originalData;
		}
	} );
} );

describe( 'createImportedActions primary-row icons', () => {
	const importedActions = createImportedActions( undefined, IMPORTED_CONTEXT, {} );

	it( 'puts Edit, Compare, and Update inline; Delete and Roll back in the kebab', () => {
		// ARRANGE: the action set under test.
		const byId = ( id: string ) =>
			importedActions.find( ( a ) => a.id === id );

		// ACT + ASSERT: inline trio for the primary row toolbar.
		expect( byId( 'edit-post' )?.isPrimary ).toBe( true );
		expect( byId( 'compare-post' )?.isPrimary ).toBe( true );
		expect( byId( 'update-post' )?.isPrimary ).toBe( true );

		// ACT + ASSERT: destructive / less-frequent actions live in the kebab.
		expect( byId( 'delete-post' )?.isPrimary ).toBeFalsy();
		expect( byId( 'rollback' )?.isPrimary ).toBeFalsy();
	} );

	it( 'orders Edit · Compare · Update so review precedes the action', () => {
		// ARRANGE + ACT: positions of the inline actions.
		const ids = importedActions.map( ( a ) => a.id );

		// ASSERT: Compare sits between Edit and Update.
		expect( ids.indexOf( 'edit-post' ) ).toBeLessThan(
			ids.indexOf( 'compare-post' )
		);
		expect( ids.indexOf( 'compare-post' ) ).toBeLessThan(
			ids.indexOf( 'update-post' )
		);
	} );
} );

describe( 'createImportedActions Compare action', () => {
	/**
	 * Returns the Compare modal action from an action set built with the given
	 * sync statuses, throwing if it's absent.
	 */
	function getCompareAction(
		syncStatuses: Parameters< typeof createImportedActions >[ 2 ]
	): ActionModal< ImportedPost > {
		const action = createImportedActions(
			undefined,
			IMPORTED_CONTEXT,
			syncStatuses
		).find( ( a: Action< ImportedPost > ) => a.id === 'compare-post' );
		if ( ! action || ! ( 'RenderModal' in action ) ) {
			throw new Error( 'Expected modal action with id "compare-post"' );
		}
		return action;
	}

	it( 'is hidden for up-to-date rows and visible otherwise', () => {
		// ARRANGE: the row identifies the source via source_post_id 10.
		const item = buildImportedPost();

		// ACT + ASSERT: explicit up-to-date verdict hides Compare.
		expect(
			getCompareAction( { 10: { status: 'up-to-date' } } ).isEligible?.(
				item
			)
		).toBe( false );

		// ACT + ASSERT: ambiguous verdicts still surface Compare.
		expect(
			getCompareAction( { 10: { status: 'outdated' } } ).isEligible?.(
				item
			)
		).toBe( true );
		expect(
			getCompareAction( { 10: { status: 'loading' } } ).isEligible?.(
				item
			)
		).toBe( true );
		expect(
			getCompareAction( {} ).isEligible?.( item )
		).toBe( true );
	} );
} );

describe( 'createImportedActions rollback action', () => {
	const importedActions = createImportedActions( undefined, IMPORTED_CONTEXT, {} );

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

	it( 'is eligible only for rows with an active item_id', () => {
		// ARRANGE: the rollback action under test.
		const rollback = getRollbackAction();

		// ACT + ASSERT: rows with an active items-table row can be rolled back.
		expect(
			rollback.isEligible?.( buildImportedPost() )
		).toBe( true );

		// ACT + ASSERT: rows without an active items-table row cannot.
		expect(
			rollback.isEligible?.( buildImportedPost( { item_id: null } ) )
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
