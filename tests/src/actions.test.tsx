/**
 * Tests for action modal components and bulk operations
 */
import React from 'react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { actions } from '@/actions';
import type { Post } from '@/types';

describe( 'Actions configuration', () => {
	it( 'should export actions array', () => {
		expect( actions ).toBeDefined();
		expect( Array.isArray( actions ) ).toBe( true );
	} );

	it( 'should have draft action', () => {
		const draftAction = actions.find( ( a ) => a.id === 'draft' );
		expect( draftAction ).toBeDefined();
		expect( draftAction?.label ).toBe( 'Create Draft' );
		expect( draftAction?.isPrimary ).toBe( true );
		expect( draftAction?.supportsBulk ).toBe( true );
	} );

	it( 'should have bulk-import action', () => {
		const bulkAction = actions.find( ( a ) => a.id === 'bulk-import' );
		expect( bulkAction ).toBeDefined();
		expect( bulkAction?.label ).toBe( 'Bulk Import' );
		expect( bulkAction?.supportsBulk ).toBe( true );
	} );

	it( 'should have update action', () => {
		const updateAction = actions.find( ( a ) => a.id === 'update' );
		expect( updateAction ).toBeDefined();
		expect( updateAction?.label ).toBe( 'Update Post' );
		expect( updateAction?.supportsBulk ).toBe( true );
	} );

	it( 'should have post diff action', () => {
		const diffAction = actions.find( ( a ) => a.id === 'tertiary' );
		expect( diffAction ).toBeDefined();
		expect( diffAction?.label ).toBe( 'Post Diff' );
		expect( diffAction?.supportsBulk ).toBe( false );
		expect( diffAction?.modalSize ).toBe( 'fill' );
	} );
} );

describe( 'Draft action', () => {
	it( 'should have RenderModal component', () => {
		const draftAction = actions.find( ( a: any ) => a.id === 'draft' );
		expect( draftAction?.RenderModal ).toBeDefined();
		expect( typeof draftAction?.RenderModal ).toBe( 'function' );
	} );

	it( 'should hide modal header', () => {
		const draftAction = actions.find( ( a: any ) => a.id === 'draft' );
		expect( draftAction?.hideModalHeader ).toBe( true );
	} );

	it( 'should focus on first content element', () => {
		const draftAction = actions.find( ( a: any ) => a.id === 'draft' );
		expect( draftAction?.modalFocusOnMount ).toBe( 'firstContentElement' );
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

describe( 'Update action', () => {
	it( 'should have RenderModal component', () => {
		const updateAction = actions.find( ( a: any ) => a.id === 'update' );
		expect( updateAction?.RenderModal ).toBeDefined();
		expect( typeof updateAction?.RenderModal ).toBe( 'function' );
	} );

	it( 'should not hide modal header', () => {
		const updateAction = actions.find( ( a: any ) => a.id === 'update' );
		expect( updateAction?.hideModalHeader ).toBe( false );
	} );
} );

describe( 'Post diff action', () => {
	it( 'should have RenderModal component', () => {
		const diffAction = actions.find( ( a: any ) => a.id === 'tertiary' );
		expect( diffAction?.RenderModal ).toBeDefined();
		expect( typeof diffAction?.RenderModal ).toBe( 'function' );
	} );

	it( 'should have fill modal size', () => {
		const diffAction = actions.find( ( a: any ) => a.id === 'tertiary' );
		expect( diffAction?.modalSize ).toBe( 'fill' );
	} );
} );
