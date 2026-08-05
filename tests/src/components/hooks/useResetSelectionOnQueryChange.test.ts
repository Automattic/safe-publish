/**
 * Tests for the useResetSelectionOnQueryChange hook.
 *
 * The hook clears a lifted DataViews selection when the listing query changes,
 * so rows picked under one chip, search, or filter can't stay selected — and
 * bulk-actionable — once a different result set loads. The reset decision is
 * pinned here, independent of the listing that consumes it.
 */
import { describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';

import { useResetSelectionOnQueryChange } from '@/components/hooks/useResetSelectionOnQueryChange';

/**
 * Mounts the hook under an initial query key and returns the reset spy plus a
 * rerender that swaps the key.
 */
function renderReset( initialKey: string ): {
	reset: ReturnType< typeof vi.fn >;
	rerender: ( key: string ) => void;
} {
	const reset = vi.fn();
	const { rerender } = renderHook(
		( { queryKey }: { queryKey: string } ) =>
			useResetSelectionOnQueryChange( queryKey, reset ),
		{ initialProps: { queryKey: initialKey } }
	);
	return { reset, rerender: ( key ) => rerender( { queryKey: key } ) };
}

describe( 'useResetSelectionOnQueryChange', () => {
	it( 'clears the selection when the query key changes', () => {
		// ARRANGE: Mount under one query key.
		const { reset, rerender } = renderReset( 'all|' );

		// ACT: The query changes, e.g. the user switches chip.
		rerender( 'imported|' );

		// ASSERT: The selection is cleared exactly once, on the change.
		expect( reset ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'leaves the selection alone on the initial render', () => {
		// ARRANGE + ACT: First mount, with no prior query to differ from.
		const reset = vi.fn();
		renderHook( () => useResetSelectionOnQueryChange( 'all|', reset ) );

		// ASSERT: Nothing clears before the query actually changes.
		expect( reset ).not.toHaveBeenCalled();
	} );

	it( 'preserves the selection while the query key holds', () => {
		// ARRANGE: Mount under a stable key — the paging- and sort-only case,
		// which never changes the key.
		const { reset, rerender } = renderReset( 'all|' );

		// ACT: Re-render twice without changing the key.
		rerender( 'all|' );
		rerender( 'all|' );

		// ASSERT: An unchanged query never clears the selection.
		expect( reset ).not.toHaveBeenCalled();
	} );

	it( 'ignores an unstable reset identity between real changes', () => {
		// ARRANGE: A fresh reset closure every render, so a key-independent
		// trigger would fire on every re-render.
		const reset = vi.fn();
		const { rerender } = renderHook(
			( { queryKey }: { queryKey: string } ) =>
				useResetSelectionOnQueryChange( queryKey, () => reset() ),
			{ initialProps: { queryKey: 'a' } }
		);

		// ACT: A same-key render, then one genuine change.
		rerender( { queryKey: 'a' } );
		rerender( { queryKey: 'b' } );

		// ASSERT: Only the real transition clears; the changing callback
		// identity leaks no extra clears.
		expect( reset ).toHaveBeenCalledTimes( 1 );
	} );
} );
