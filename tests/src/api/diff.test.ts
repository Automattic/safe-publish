/**
 * Tests for diff API functions
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import {
	fetchDiffPreview,
	updatePostContent,
	type DiffPreviewPayload,
	type DiffPreviewResult,
	type UpdatePostResult,
} from '@/api/diff';

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
			content: 'New content',
		};

		const result = await fetchDiffPreview( payload );
		expect( result ).toEqual( mockResponse );
		expect( global.fetch ).toHaveBeenCalledWith(
			'/wp-json/ccp/v1/diff-preview',
			expect.objectContaining( {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
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

		const result = await fetchDiffPreview( payload );
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

		const result = await fetchDiffPreview( payload );
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
			content: 'New content',
			mode: 'split',
			cleanup: true,
		};

		await fetchDiffPreview( payload );
		expect( global.fetch ).toHaveBeenCalledWith(
			'/wp-json/ccp/v1/diff-preview',
			expect.objectContaining( {
				body: JSON.stringify( payload ),
			} )
		);
	} );
} );

describe( 'updatePostContent', () => {
	beforeEach( () => {
		global.fetch = vi.fn();
		( global as any ).window = {
			ccpAdminData: {
				restNonce: 'test-nonce',
			},
		};
	} );

	afterEach( () => {
		vi.restoreAllMocks();
		delete ( global as any ).window;
	} );

	it( 'should update post content successfully', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => ( { success: true } ),
		} );

		const result = await updatePostContent( 123, 'New content' );
		expect( result.success ).toBe( true );
		expect( global.fetch ).toHaveBeenCalledWith(
			'/wp-json/ccp/v1/update-post',
			expect.objectContaining( {
				method: 'POST',
				headers: expect.objectContaining( {
					'Content-Type': 'application/json',
					'X-WP-Nonce': 'test-nonce',
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

	it( 'should use custom nonce when provided', async () => {
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

		const result = await updatePostContent( 123, 'Content' );
		expect( result.success ).toBe( false );
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

		const result = await updatePostContent( 123, 'Content' );
		expect( result.success ).toBe( false );
		expect( result.error ).toBe( 'HTTP 500' );
	} );

	it( 'should handle invalid JSON response', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => {
				throw new Error( 'Invalid JSON' );
			},
		} );

		const result = await updatePostContent( 123, 'Content' );
		expect( result.success ).toBe( false );
		expect( result.error ).toBe( 'Invalid response' );
	} );

	it( 'should handle success: false in response', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => ( { success: false, error: 'Custom error' } ),
		} );

		const result = await updatePostContent( 123, 'Content' );
		expect( result.success ).toBe( false );
		expect( result.error ).toBe( 'Custom error' );
	} );

	it( 'should work without window.ccpAdminData', async () => {
		// Save original window.
		const originalWindow = ( global as any ).window;

		// Set window to undefined.
		( global as any ).window = undefined;

		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => ( { success: true } ),
		} );

		const result = await updatePostContent( 123, 'Content', 'explicit-nonce' );
		expect( result.success ).toBe( true );

		// Restore original window.
		( global as any ).window = originalWindow;
	} );

	it( 'should not include undefined optional properties in body', async () => {
		( global.fetch as any ).mockResolvedValue( {
			ok: true,
			json: async () => ( { success: true } ),
		} );

		await updatePostContent( 123, 'Content' );

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
