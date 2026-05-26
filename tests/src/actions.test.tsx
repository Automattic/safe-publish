/**
 * Tests for action modal components and bulk operations
 */
import { describe, expect, it } from 'vitest';
import { createActions } from '@/actions';
import type { Post } from '@/types';
import type { Action, ActionModal } from '@wordpress/dataviews/build-types';

const actions = createActions();

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
			? bulkAction.label( [ { id: 1, link: '', title: 'Test', modified_gmt: '', is_imported: false } ] )
			: bulkAction?.label;
		expect( label ).toBe( 'Import' );
	} );

	it( 'bulk-import label returns "Update" for a single imported item with an update', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		const label = typeof bulkAction?.label === 'function'
			? bulkAction.label( [ { id: 1, link: '', title: 'Test', modified_gmt: '', is_imported: true, has_update: true } ] )
			: bulkAction?.label;
		expect( label ).toBe( 'Update' );
	} );

	it( 'bulk-import label returns "Import / Update" for multiple items', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		const label = typeof bulkAction?.label === 'function'
			? bulkAction.label( [
				{ id: 1, link: '', title: 'A', modified_gmt: '', is_imported: false },
				{ id: 2, link: '', title: 'B', modified_gmt: '', is_imported: true, has_update: true },
			] )
			: bulkAction?.label;
		expect( label ).toBe( 'Import / Update' );
	} );

	it( 'bulk-import action isEligible covers posts that can be imported or updated', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		expect( bulkAction?.isEligible?.( { id: 1, link: '', title: 'Test', modified_gmt: '', is_imported: false } ) ).toBe( true );
		expect( bulkAction?.isEligible?.( { id: 1, link: '', title: 'Test', modified_gmt: '', is_imported: true, has_update: true } ) ).toBe( true );
		expect( bulkAction?.isEligible?.( { id: 1, link: '', title: 'Test', modified_gmt: '', is_imported: true, has_update: false } ) ).toBe( false );
	} );

	it( 'bulk-import isEligible returns false when not authorized', () => {
		const unauthorized = createActions( undefined, false );
		const bulkAction = unauthorized.find( ( a ) => a.id === 'bulk-import' );
		const importable = {
			id: 1, link: '', title: 'Test', modified_gmt: '', is_imported: false,
		};
		const updatable = {
			id: 1, link: '', title: 'Test', modified_gmt: '',
			is_imported: true, has_update: true,
		};
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
				id: 1, link: '', title: 'Test', modified_gmt: '', is_imported: true,
			} )
		).toBe( true );

		// ACT + ASSERT: source-only posts cannot be diffed — no local copy exists.
		expect(
			diffAction?.isEligible?.( {
				id: 1, link: '', title: 'Test', modified_gmt: '', is_imported: false,
			} )
		).toBe( false );
	} );
} );
