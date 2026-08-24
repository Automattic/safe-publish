/**
 * Tests for the useRefreshOnUnmount hook, which every confirmation modal
 * relies on to refresh its listing exactly once, on dismissal.
 */
import { describe, expect, it, vi } from 'vitest';
import { renderHook } from '@testing-library/react';

import { useRefreshOnUnmount } from '@/components/hooks/useRefreshOnUnmount';

describe( 'useRefreshOnUnmount', () => {
	it( 'Verifies that an ungated hook never refreshes', () => {
		// ARRANGE + ACT: Mount and unmount with the gate closed throughout.
		const onRefresh = vi.fn();
		const { unmount } = renderHook( () =>
			useRefreshOnUnmount( false, onRefresh )
		);
		unmount();

		// ASSERT: Nothing fires.
		expect( onRefresh ).not.toHaveBeenCalled();
	} );

	it( 'Verifies that a gated hook refreshes on unmount rather than on the flip', () => {
		// ARRANGE: The gate starts closed.
		const onRefresh = vi.fn();
		const { rerender, unmount } = renderHook(
			( { gate }: { gate: boolean } ) =>
				useRefreshOnUnmount( gate, onRefresh ),
			{ initialProps: { gate: false } }
		);

		// ACT: Open the gate.
		rerender( { gate: true } );

		// ASSERT: Flipping the flag alone does not refresh.
		expect( onRefresh ).not.toHaveBeenCalled();

		// ACT: Unmount.
		unmount();

		// ASSERT: The refresh lands exactly once.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that re-renders after the flip do not refresh repeatedly', () => {
		// ARRANGE: A hook already past its gate.
		const onRefresh = vi.fn();
		const { rerender, unmount } = renderHook(
			( { gate }: { gate: boolean } ) =>
				useRefreshOnUnmount( gate, onRefresh ),
			{ initialProps: { gate: true } }
		);

		// ACT: Re-render several times with the gate held open.
		rerender( { gate: true } );
		rerender( { gate: true } );

		// ASSERT: A stable flag and callback keep the cleanup from firing.
		expect( onRefresh ).not.toHaveBeenCalled();

		// ACT: Unmount.
		unmount();

		// ASSERT: Still only one refresh.
		expect( onRefresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'Verifies that an undefined callback unmounts without throwing', () => {
		// ARRANGE + ACT: Gate open, no callback supplied.
		const { unmount } = renderHook( () =>
			useRefreshOnUnmount( true, undefined )
		);

		// ASSERT: Dismissal is a no-op instead of a crash.
		expect( () => unmount() ).not.toThrow();
	} );
} );
