/**
 * Tests for the shape PostTypeSelector reports failures in.
 *
 * The listing renders whatever the selector emits, so structured source detail
 * has to reach the parent unflattened. PostsDataView's own tests mock the
 * selector away, leaving this contract uncovered there.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, waitFor } from '@testing-library/react';

import { PostTypeSelector } from '@/post-type-selector';

const SOURCE_URL = 'https://source.example.com';
const TEMPLATE_401 = 'Source site returned HTTP error 401. <reason />';

let fetchMock: ReturnType< typeof vi.fn >;

/**
 * Stubs the post-types request with a single JSON body.
 *
 * @param {unknown} body Response the endpoint answers with.
 */
function stubFetch( body: unknown ): void {
	fetchMock.mockResolvedValue( { json: () => Promise.resolve( body ) } );
}

beforeEach( () => {
	fetchMock = vi.fn();
	vi.stubGlobal( 'fetch', fetchMock );
	// The component logs failures; keep the run output readable.
	vi.spyOn( console, 'error' ).mockImplementation( () => {} );
} );

afterEach( () => {
	vi.unstubAllGlobals();
	vi.restoreAllMocks();
} );

describe( 'PostTypeSelector error reporting', () => {
	it( 'should emit the structured pair when the endpoint sends one', async () => {
		// ARRANGE: The endpoint answers with both halves of the sentence.
		stubFetch( {
			success: false,
			data: {
				message: 'Source site returned HTTP error 401. Refused.',
				source_error: {
					message: 'Refused.',
					template: TEMPLATE_401,
				},
			},
		} );
		const onError = vi.fn();

		// ACT: Mount the selector and let the request settle.
		render(
			<PostTypeSelector sourceSiteUrl={ SOURCE_URL } onError={ onError } />
		);

		// ASSERT: The parent receives the pair, not the flat sentence.
		await waitFor( () =>
			expect( onError ).toHaveBeenCalledWith( {
				message: 'Refused.',
				template: TEMPLATE_401,
			} )
		);
	} );

	it( 'should emit a plain message when no source detail is sent', async () => {
		// ARRANGE: The endpoint answers with a bare string, as it still does
		// for failures that never reached the source.
		stubFetch( { success: false, data: 'Invalid URL provided.' } );
		const onError = vi.fn();

		// ACT: Mount the selector and let the request settle.
		render(
			<PostTypeSelector sourceSiteUrl={ SOURCE_URL } onError={ onError } />
		);

		// ASSERT: The flat message passes through unchanged.
		await waitFor( () =>
			expect( onError ).toHaveBeenCalledWith( 'Invalid URL provided.' )
		);
	} );

	it( 'should emit a plain message when the request never completes', async () => {
		// ARRANGE: The transport itself fails, so there is no response body.
		fetchMock.mockRejectedValue( new Error( 'offline' ) );
		const onError = vi.fn();

		// ACT: Mount the selector and let the request settle.
		render(
			<PostTypeSelector sourceSiteUrl={ SOURCE_URL } onError={ onError } />
		);

		// ASSERT: The local fallback copy reaches the parent.
		await waitFor( () =>
			expect( onError ).toHaveBeenCalledWith(
				'Network error while loading post types.'
			)
		);
	} );
} );
