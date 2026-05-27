/**
 * Tests for the usePostUpdate hook's safety guard.
 *
 * The hook posts to /wp-json/safe-publish/v1/update-post, which writes
 * post_content directly. The guard against missing/empty incoming content
 * is the only thing standing between a broken diff response and silent
 * data loss in the destination DB, so it earns dedicated coverage.
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { act, renderHook } from '@testing-library/react';

import { usePostUpdate } from '@/components/hooks/usePostUpdate';

vi.mock( '@/api/diff', () => ( {
	updatePostContent: vi.fn(),
} ) );

import { updatePostContent } from '@/api/diff';

describe( 'usePostUpdate', () => {
	beforeEach( () => {
		vi.mocked( updatePostContent ).mockReset();
	} );

	afterEach( () => {
		vi.restoreAllMocks();
	} );

	it( 'refuses to submit and surfaces an error when incoming is undefined', async () => {
		// ARRANGE: No diff payload at all.
		const { result } = renderHook( () =>
			usePostUpdate( { localPostId: 99, incoming: undefined } )
		);

		// ACT: Attempt the update.
		await act( async () => {
			await result.current.handleUpdatePost();
		} );

		// ASSERT: updatePostContent never fires; the user sees an error.
		expect( updatePostContent ).not.toHaveBeenCalled();
		expect( result.current.updateError ).not.toBeNull();
		expect( result.current.isUpdating ).toBe( false );
	} );

	it( 'refuses to submit when incoming.content is an empty string', async () => {
		// ARRANGE: Diff returned everything except content.
		const { result } = renderHook( () =>
			usePostUpdate( {
				localPostId: 99,
				incoming: { title: 'T', content: '', excerpt: '' },
			} )
		);

		// ACT.
		await act( async () => {
			await result.current.handleUpdatePost();
		} );

		// ASSERT: Same guard fires — no network call, error surfaced.
		expect( updatePostContent ).not.toHaveBeenCalled();
		expect( result.current.updateError ).not.toBeNull();
	} );

	it( 'submits incoming.content and featuredMedia when both are present', async () => {
		// ARRANGE: A successful diff response.
		vi.mocked( updatePostContent ).mockResolvedValue( { success: true } );

		const { result } = renderHook( () =>
			usePostUpdate( {
				localPostId: 42,
				incoming: {
					title: 'Hello',
					content: '<p>Body</p>',
					excerpt: 'Summary',
					featuredMedia: 7,
				},
			} )
		);

		// ACT.
		await act( async () => {
			await result.current.handleUpdatePost();
		} );

		// ASSERT: The mock was invoked with the incoming content and the
		// featuredMedia id forwarded through.
		expect( updatePostContent ).toHaveBeenCalledTimes( 1 );
		const args = vi.mocked( updatePostContent ).mock.calls[ 0 ];
		expect( args[ 0 ] ).toBe( 42 );
		expect( args[ 1 ] ).toBe( '<p>Body</p>' );
		expect( args[ 5 ] ).toBe( 'Hello' );
		expect( args[ 6 ] ).toBe( 'Summary' );
		expect( args[ 7 ] ).toBe( 7 );
		expect( result.current.updateError ).toBeNull();
		expect( result.current.updateSuccess ).not.toBeNull();
	} );
} );
