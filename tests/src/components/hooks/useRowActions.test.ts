/**
 * Tests for the useRowActions hook, which keeps row actions reachable on the
 * narrow viewports where DataViews renders no inline primary actions.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';

import {
	createNeedsAttentionActions,
	type NeedsAttentionActionsContext,
} from '@/actions';
import { useRowActions } from '@/components/hooks/useRowActions';

import type { Action } from '@wordpress/dataviews/build-types';
import type { InboxFailure, NeedsAttentionRow } from '@/types';

const useViewportMatch = vi.hoisted( () => vi.fn() );

vi.mock( '@wordpress/compose', async ( importOriginal ) => ( {
	...( await importOriginal< typeof import('@wordpress/compose') >() ),
	useViewportMatch,
} ) );

const CONTEXT: NeedsAttentionActionsContext = {
	ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
	nonce: 'test-nonce',
};

/**
 * Builds a minimal inbox failure row.
 *
 * @return {Object} An inbox failure row.
 */
function buildFailure(): InboxFailure {
	return {
		kind: 'failure',
		row_id: 'failure:42',
		item_id: 42,
		source_post_id: 10,
		title: 'Broken import',
		error_message: 'Boom',
		import_date_gmt: '2024-03-15 10:30:00',
		source_site_url: 'https://source.example.com',
		edit_url: '',
	};
}

/**
 * Mirrors the condition DataViews uses to decide whether a row gets an
 * overflow menu: at least one eligible action must not be primary.
 *
 * @param {Array}  actions Row actions.
 * @param {Object} item    Row the actions render against.
 * @return {boolean} True when the row renders an overflow menu.
 */
function hasOverflowMenu< Item >(
	actions: Action< Item >[],
	item: Item
): boolean {
	const eligible = actions.filter(
		( action ) => ! action.isEligible || action.isEligible( item )
	);

	return (
		eligible.filter( ( action ) => action.isPrimary ).length <
		eligible.length
	);
}

describe( 'useRowActions', () => {
	beforeEach( () => {
		useViewportMatch.mockReset();
	} );

	it( 'Verifies that wide viewports hand back the very same actions', () => {
		// ARRANGE: A mixed action set, above the breakpoint.
		useViewportMatch.mockReturnValue( false );
		const actions: Action< { id: number } >[] = [
			{ id: 'a', label: 'A', isPrimary: true, callback: () => {} },
			{ id: 'b', label: 'B', callback: () => {} },
		];

		// ACT: Adapt them.
		const { result } = renderHook( () => useRowActions( actions ) );

		// ASSERT: The array passes through by reference, so nothing above the
		// breakpoint can change.
		expect( result.current ).toBe( actions );
	} );

	it( 'Verifies that narrow viewports clear isPrimary on every action', () => {
		// ARRANGE: A mixed action set, below the breakpoint.
		useViewportMatch.mockReturnValue( true );
		const actions: Action< { id: number } >[] = [
			{ id: 'a', label: 'A', isPrimary: true, callback: () => {} },
			{ id: 'b', label: 'B', callback: () => {} },
		];

		// ACT: Adapt them.
		const { result } = renderHook( () => useRowActions( actions ) );

		// ASSERT: None stays primary, and the originals are left alone.
		expect(
			result.current.map( ( action ) => action.isPrimary )
		).toStrictEqual( [ false, false ] );
		expect( actions[ 0 ].isPrimary ).toBe( true );
	} );

	it( 'Verifies that narrow viewports keep every other action property', () => {
		// ARRANGE: An action carrying an id, label, eligibility and callback.
		useViewportMatch.mockReturnValue( true );
		const callback = vi.fn();
		const isEligible = ( item: { id: number } ): boolean => 1 === item.id;
		const actions: Action< { id: number } >[] = [
			{
				id: 'a',
				label: 'A',
				isPrimary: true,
				supportsBulk: true,
				isEligible,
				callback,
			},
		];

		// ACT: Adapt them.
		const { result } = renderHook( () => useRowActions( actions ) );

		// ASSERT: Only isPrimary differs.
		expect( result.current[ 0 ] ).toStrictEqual( {
			...actions[ 0 ],
			isPrimary: false,
		} );
	} );

	it( 'Verifies that narrow viewports give inbox rows an overflow menu', () => {
		// ARRANGE: The real inbox action set, whose every action is primary,
		// and a failure row.
		const actions = createNeedsAttentionActions(
			undefined,
			CONTEXT,
			'open'
		);
		const row: NeedsAttentionRow = buildFailure();

		// ACT: Adapt them for each side of the breakpoint.
		useViewportMatch.mockReturnValue( false );
		const wide = renderHook( () => useRowActions( actions ) ).result
			.current;
		useViewportMatch.mockReturnValue( true );
		const narrow = renderHook( () => useRowActions( actions ) ).result
			.current;

		// ASSERT: The row gains the menu it never had, with the full action
		// set intact.
		expect( hasOverflowMenu( wide, row ) ).toBe( false );
		expect( hasOverflowMenu( narrow, row ) ).toBe( true );
		expect( narrow.map( ( action ) => action.id ) ).toStrictEqual(
			actions.map( ( action ) => action.id )
		);
	} );
} );
