/**
 * Tests for diff API functions
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import {
	fetchDiffPreview,
	updatePostContent,
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

describe( 'updatePostContent', () => {
	beforeEach( () => {
		global.fetch = vi.fn();
	} );

	afterEach( () => {
		vi.restoreAllMocks();
	} );

	it( 'should update post content successfully', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => ( { success: true } ),
		} );

		const result = await updatePostContent( 123, 'New content', REST_NONCE );
		expect( result.success ).toBe( true );
		expect( global.fetch ).toHaveBeenCalledWith(
			'/wp-json/safe-publish/v1/update-post',
			expect.objectContaining( {
				method: 'POST',
				headers: expect.objectContaining( {
					'Content-Type': 'application/json',
					'X-WP-Nonce': REST_NONCE,
				} ),
			} )
		);
	} );

	it( 'should include all optional parameters in request', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => ( { success: true } ),
		} );

		await updatePostContent(
			123,
			'New content',
			'custom-nonce',
			{ key: 'value' },
			{ category: [ 'cat1' ] },
			'New Title',
			'New excerpt',
			456
		);

		const callArgs = ( global.fetch as any ).mock.calls[ 0 ];
		const body = JSON.parse( callArgs[ 1 ].body );

		expect( body.postId ).toBe( 123 );
		expect( body.content ).toBe( 'New content' );
		expect( body.title ).toBe( 'New Title' );
		expect( body.excerpt ).toBe( 'New excerpt' );
		expect( body.meta ).toEqual( { key: 'value' } );
		expect( body.terms ).toEqual( { category: [ 'cat1' ] } );
		expect( body.featuredMediaId ).toBe( 456 );
	} );

	it( 'should send the supplied nonce as X-WP-Nonce', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => ( { success: true } ),
		} );

		await updatePostContent( 123, 'Content', 'custom-nonce' );

		const callArgs = ( global.fetch as any ).mock.calls[ 0 ];
		expect( callArgs[ 1 ].headers[ 'X-WP-Nonce' ] ).toBe( 'custom-nonce' );
	} );

	it( 'should handle HTTP error response', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: false,
			status: 403,
			text: async () => 'Forbidden',
		} );

		const result = await updatePostContent( 123, 'Content', REST_NONCE );
		if ( result.success ) {
			throw new Error( 'Expected failure result' );
		}
		expect( result.error ).toBe( 'Forbidden' );
	} );

	it( 'should handle error response with status code', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: false,
			status: 500,
			text: async () => {
				throw new Error();
			},
		} );

		const result = await updatePostContent( 123, 'Content', REST_NONCE );
		if ( result.success ) {
			throw new Error( 'Expected failure result' );
		}
		expect( result.error ).toBe( 'HTTP 500' );
	} );

	it( 'should handle invalid JSON response', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => {
				throw new Error( 'Invalid JSON' );
			},
		} );

		const result = await updatePostContent( 123, 'Content', REST_NONCE );
		if ( result.success ) {
			throw new Error( 'Expected failure result' );
		}
		expect( result.error ).toBe( 'Invalid response from server' );
	} );

	it( 'should handle success: false in response', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => ( { success: false, error: 'Custom error' } ),
		} );

		const result = await updatePostContent( 123, 'Content', REST_NONCE );
		if ( result.success ) {
			throw new Error( 'Expected failure result' );
		}
		expect( result.error ).toBe( 'Custom error' );
	} );

	it( 'should not include undefined optional properties in body', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => ( { success: true } ),
		} );

		await updatePostContent( 123, 'Content', REST_NONCE );

		const callArgs = ( global.fetch as any ).mock.calls[ 0 ];
		const body = JSON.parse( callArgs[ 1 ].body );

		expect( body ).toEqual( {
			postId: 123,
			content: 'Content',
		} );
		expect( body.title ).toBeUndefined();
		expect( body.excerpt ).toBeUndefined();
		expect( body.meta ).toBeUndefined();
		expect( body.terms ).toBeUndefined();
		expect( body.featuredMediaId ).toBeUndefined();
	} );
} );
