/**
 * Tests for the useAuthStatus hook.
 *
 * The hook funnels the source-site auth probe used by both DataView pages, so
 * each terminal state (authorized, unauthorized, network error) earns
 * dedicated coverage to guard against silent regressions in the banner the
 * admin sees before any user action.
 */
import {
	describe,
	expect,
	it,
	vi,
	beforeEach,
	afterEach,
} from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';

import { useAuthStatus } from '@/components/hooks/useAuthStatus';

describe( 'useAuthStatus', () => {
	beforeEach( () => {
		// Restore a fresh fetch mock per case so leaked vi.spyOn doesn't carry
		// state between tests.
		vi.stubGlobal( 'fetch', vi.fn() );
	} );

	afterEach( () => {
		vi.unstubAllGlobals();
		vi.restoreAllMocks();
	} );

	it( 'returns the probed status once the request resolves', async () => {
		// ARRANGE: A successful probe returning the authorized verdict.
		vi.mocked( fetch ).mockResolvedValue(
			new Response(
				JSON.stringify( { success: true, data: { status: 'authorized' } } ),
				{ status: 200 }
			)
		);

		// ACT: Mount the hook.
		const { result } = renderHook( () => useAuthStatus() );

		// ASSERT: Status starts as null (in flight), then settles to the
		// server's verdict.
		expect( result.current ).toBeNull();
		await waitFor( () => expect( result.current ).toBe( 'authorized' ) );
	} );

	it( 'surfaces the unauthorized verdict so the caller can lock destructive UI', async () => {
		// ARRANGE: Server reports a rejected shared secret.
		vi.mocked( fetch ).mockResolvedValue(
			new Response(
				JSON.stringify( { success: true, data: { status: 'unauthorized' } } ),
				{ status: 200 }
			)
		);

		// ACT: Mount the hook.
		const { result } = renderHook( () => useAuthStatus() );

		// ASSERT: Status reaches 'unauthorized' so the UI banner can render.
		await waitFor( () => expect( result.current ).toBe( 'unauthorized' ) );
	} );

	it( 'falls back to "unreachable" when the network request rejects', async () => {
		// ARRANGE: Simulate a transient network failure.
		vi.mocked( fetch ).mockRejectedValue( new Error( 'network' ) );

		// ACT: Mount the hook.
		const { result } = renderHook( () => useAuthStatus() );

		// ASSERT: The hook reports unreachable so callers don't lock the page
		// over a blip.
		await waitFor( () => expect( result.current ).toBe( 'unreachable' ) );
	} );

	it( 'treats success:false as unreachable rather than authorized', async () => {
		// ARRANGE: Server returns a structured failure envelope.
		vi.mocked( fetch ).mockResolvedValue(
			new Response(
				JSON.stringify( { success: false, error: 'boom' } ),
				{ status: 200 }
			)
		);

		// ACT: Mount the hook.
		const { result } = renderHook( () => useAuthStatus() );

		// ASSERT: Coerces to 'unreachable'; we never silently report authorized.
		await waitFor( () => expect( result.current ).toBe( 'unreachable' ) );
	} );
} );
