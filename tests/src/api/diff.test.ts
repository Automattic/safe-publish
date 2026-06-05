/**
 * Tests for diff API functions
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import {
	fetchDiffPreview,
	type DiffPreviewPayload,
	type DiffPreviewResult,
} from '@/api/diff';

const REST_NONCE = 'test-rest-nonce';

describe( 'fetchDiffPreview', () => {
	beforeEach( () => {
		global.fetch = vi.fn();
	} );

	afterEach( () => {
		vi.restoreAllMocks();
	} );

	it( 'should fetch diff preview successfully', async () => {
		const mockResponse: DiffPreviewResult = {
			contentDiffHtml: '<div>Diff content</div>',
			blockDiffs: [],
		};

		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => mockResponse,
		} );

		const payload: DiffPreviewPayload = {
			postId: 123,
		};

		const result = await fetchDiffPreview( payload, REST_NONCE );
		expect( result ).toEqual( mockResponse );
		expect( global.fetch ).toHaveBeenCalledWith(
			'/wp-json/safe-publish/v1/diff-preview',
			expect.objectContaining( {
				method: 'POST',
				headers: expect.objectContaining( {
					'Content-Type': 'application/json',
				} ),
				body: JSON.stringify( payload ),
			} )
		);
	} );

	it( 'should handle fetch error', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: false,
			text: async () => 'Error message',
		} );

		const payload: DiffPreviewPayload = {
			postId: 123,
		};

		const result = await fetchDiffPreview( payload, REST_NONCE );
		expect( result.error ).toBe( 'Error message' );
	} );

	it( 'should handle network error', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: false,
			text: async () => {
				throw new Error( 'Network error' );
			},
		} );

		const payload: DiffPreviewPayload = {
			postId: 123,
		};

		const result = await fetchDiffPreview( payload, REST_NONCE );
		expect( result.error ).toBe( 'Failed to fetch diff' );
	} );

	it( 'should send all payload properties', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => ( {} ),
		} );

		const payload: DiffPreviewPayload = {
			postId: 123,
			postType: 'page',
			mode: 'split',
			cleanup: true,
		};

		await fetchDiffPreview( payload, REST_NONCE );
		expect( global.fetch ).toHaveBeenCalledWith(
			'/wp-json/safe-publish/v1/diff-preview',
			expect.objectContaining( {
				body: JSON.stringify( payload ),
			} )
		);
	} );

	it( 'should include X-WP-Nonce header so WP can authenticate the request', async () => {
		// ARRANGE: the caller passes a nonce in. Pins the contract — nonce
		// must reach the wire as X-WP-Nonce so current_user_can() works.
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => ( {} ),
		} );

		// ACT: trigger a diff preview request.
		await fetchDiffPreview( { postId: 123 }, REST_NONCE );

		// ASSERT: the request carried the nonce.
		expect( global.fetch ).toHaveBeenCalledWith(
			'/wp-json/safe-publish/v1/diff-preview',
			expect.objectContaining( {
				headers: expect.objectContaining( {
					'Content-Type': 'application/json',
					'X-WP-Nonce': REST_NONCE,
				} ),
			} )
		);
	} );
} );
