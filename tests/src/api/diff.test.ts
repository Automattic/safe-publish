/**
 * Tests for diff API functions
 */
import { describe, expect, it, vi, beforeEach, type Mock } from 'vitest';

import {
	fetchDiffPreview,
	type DiffPreviewPayload,
	type DiffPreviewResult,
} from '@/api/diff';
import apiFetch from '@wordpress/api-fetch';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const mockApiFetch = apiFetch as unknown as Mock;

describe( 'fetchDiffPreview', () => {
	beforeEach( () => {
		mockApiFetch.mockReset();
	} );

	it( 'requests the REST route via apiFetch and returns the result', async () => {
		// ARRANGE: apiFetch resolves with a diff result.
		const mockResponse: DiffPreviewResult = {
			contentDiffHtml: '<div>Diff content</div>',
			blockDiffs: [],
		};
		mockApiFetch.mockResolvedValue( mockResponse );

		const payload: DiffPreviewPayload = { postId: 123 };

		// ACT: Fetch the diff preview.
		const result = await fetchDiffPreview( payload );

		// ASSERT: Result is returned and the request resolves against the site
		// REST root via a relative path (apiFetch), not a hardcoded /wp-json/.
		expect( result ).toEqual( mockResponse );
		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/safe-publish/v1/diff-preview',
			method: 'POST',
			data: payload,
		} );
	} );

	it( 'sends all payload properties as the request data', async () => {
		// ARRANGE: apiFetch resolves; the full payload is the request data.
		mockApiFetch.mockResolvedValue( {} );

		const payload: DiffPreviewPayload = {
			postId: 123,
			postType: 'page',
		};

		// ACT: Fetch the diff preview.
		await fetchDiffPreview( payload );

		// ASSERT: The whole payload is forwarded as data.
		expect( mockApiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( { data: payload } )
		);
	} );

	it( 'surfaces the error message when apiFetch rejects', async () => {
		// ARRANGE: apiFetch rejects with a message-bearing error.
		mockApiFetch.mockRejectedValue( { message: 'Server says no' } );

		// ACT: Fetch the diff preview.
		const result = await fetchDiffPreview( { postId: 123 } );

		// ASSERT: The error message is surfaced to the caller.
		expect( result.error ).toBe( 'Server says no' );
	} );

	it( 'preserves valid structured source error data', async () => {
		// ARRANGE: WordPress rejects with a display message and source detail.
		mockApiFetch.mockRejectedValue( {
			message: 'Source site returned HTTP error 401. Refused.',
			data: {
				source_error: {
					message: 'Refused.',
					template:
						'Source site returned HTTP error 401. <reason />',
				},
			},
		} );

		// ACT: Fetch the diff preview.
		const result = await fetchDiffPreview( { postId: 123 } );

		// ASSERT: The source detail remains separate from the fallback message.
		expect( result ).toEqual( {
			error: 'Source site returned HTTP error 401. Refused.',
			sourceError: {
				message: 'Refused.',
				template:
					'Source site returned HTTP error 401. <reason />',
			},
		} );
	} );

	it( 'ignores malformed structured source error data', async () => {
		// ARRANGE: The optional source template lacks the reason marker.
		mockApiFetch.mockRejectedValue( {
			message: 'Server says no',
			data: {
				source_error: {
					message: 'Refused.',
					template: 'Source site returned HTTP error 401.',
				},
			},
		} );

		// ACT: Fetch the diff preview.
		const result = await fetchDiffPreview( { postId: 123 } );

		// ASSERT: The compatible fallback message still surfaces alone.
		expect( result ).toEqual( { error: 'Server says no' } );
	} );

	it( 'falls back to a generic message when the rejection has no message', async () => {
		// ARRANGE: apiFetch rejects with no usable message.
		mockApiFetch.mockRejectedValue( {} );

		// ACT: Fetch the diff preview.
		const result = await fetchDiffPreview( { postId: 123 } );

		// ASSERT: A generic fallback message is returned.
		expect( result.error ).toBe( 'Failed to fetch diff' );
	} );
} );
