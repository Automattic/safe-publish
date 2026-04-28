/**
 * Tests for action modal components and bulk operations
 */
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { createActions } from '@/actions';
import type { Post } from '@/types';

const actions = createActions();

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
			? bulkAction.label( [ { id: 1, link: '', title: 'Test', modified: '', is_imported: false } ] )
			: bulkAction?.label;
		expect( label ).toBe( 'Import' );
	} );

	it( 'bulk-import label returns "Update" for a single imported item with an update', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		const label = typeof bulkAction?.label === 'function'
			? bulkAction.label( [ { id: 1, link: '', title: 'Test', modified: '', is_imported: true, has_update: true } ] )
			: bulkAction?.label;
		expect( label ).toBe( 'Update' );
	} );

	it( 'bulk-import label returns "Import / Update" for multiple items', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		const label = typeof bulkAction?.label === 'function'
			? bulkAction.label( [
				{ id: 1, link: '', title: 'A', modified: '', is_imported: false },
				{ id: 2, link: '', title: 'B', modified: '', is_imported: true, has_update: true },
			] )
			: bulkAction?.label;
		expect( label ).toBe( 'Import / Update' );
	} );

	it( 'bulk-import action isEligible covers posts that can be imported or updated', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		expect( bulkAction?.isEligible?.( { id: 1, link: '', title: 'Test', modified: '', is_imported: false } ) ).toBe( true );
		expect( bulkAction?.isEligible?.( { id: 1, link: '', title: 'Test', modified: '', is_imported: true, has_update: true } ) ).toBe( true );
		expect( bulkAction?.isEligible?.( { id: 1, link: '', title: 'Test', modified: '', is_imported: true, has_update: false } ) ).toBe( false );
	} );

	it( 'bulk-import action isEligible returns false when isAuthorized is false', () => {
		const unauthorizedActions = createActions( undefined, false );
		const bulkAction = unauthorizedActions.find( ( a ) => a.id === 'bulk-import' );
		expect( bulkAction?.isEligible?.( { id: 1, link: '', title: 'Test', modified: '', is_imported: false } ) ).toBe( false );
		expect( bulkAction?.isEligible?.( { id: 1, link: '', title: 'Test', modified: '', is_imported: true, has_update: true } ) ).toBe( false );
	} );



	it( 'should have post diff action', () => {
		const diffAction = actions.find( ( a ) => a.id === 'post-diff' );
		expect( diffAction ).toBeDefined();
		expect( diffAction?.label ).toBe( 'Post Diff' );
		expect( diffAction?.supportsBulk ).toBe( false );
		expect( diffAction?.modalSize ).toBe( 'fill' );
	} );
} );

describe( 'Bulk import action', () => {
	it( 'should have RenderModal component', () => {
		const bulkAction = actions.find( ( a: any ) => a.id === 'bulk-import' );
		expect( bulkAction?.RenderModal ).toBeDefined();
		expect( typeof bulkAction?.RenderModal ).toBe( 'function' );
	} );

	it( 'should hide modal header', () => {
		const bulkAction = actions.find( ( a: any ) => a.id === 'bulk-import' );
		expect( bulkAction?.hideModalHeader ).toBe( true );
	} );

	it( 'should focus on first content element', () => {
		const bulkAction = actions.find( ( a: any ) => a.id === 'bulk-import' );
		expect( bulkAction?.modalFocusOnMount ).toBe( 'firstContentElement' );
	} );
} );

describe( 'Post diff action', () => {
	it( 'should have RenderModal component', () => {
		const diffAction = actions.find( ( a: any ) => a.id === 'post-diff' );
		expect( diffAction?.RenderModal ).toBeDefined();
		expect( typeof diffAction?.RenderModal ).toBe( 'function' );
	} );

	it( 'should have fill modal size', () => {
		const diffAction = actions.find( ( a: any ) => a.id === 'post-diff' );
		expect( diffAction?.modalSize ).toBe( 'fill' );
	} );
} );
