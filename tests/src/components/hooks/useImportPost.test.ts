/**
 * Tests for the useImportPost hook — the canonical client for
 * safe_publish_create_draft, shared by ImportModal and PostDiffModal. Drift
 * in the request shape silently breaks both modals, so the contract is
 * pinned here: payload keys, isUpdate toggle, postType fallback, success
 * parsing, and each distinct error path.
 */
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';

import { useImportPost } from '@/components/hooks/useImportPost';

const PARAMS = {
	sourcePostId: 123,
	title: 'Hello world',
	sourceLink: 'https://src.example.com/?p=123',
	postType: 'post',
	isUpdate: false,
	ajaxurl: 'https://example.com/wp-admin/admin-ajax.php',
	nonce: 'test-nonce',
};

/**
 * Reads the FormData body sent on the last fetch call into a plain object
 * keyed by field name. Lets tests assert against the request payload.
 *
 * @return {Record<string, string>} Submitted form fields.
 */
function lastSubmittedFormFields(): Record< string, string > {
	const calls = vi.mocked( fetch ).mock.calls;
	const lastCall = calls[ calls.length - 1 ];
	const body = lastCall?.[ 1 ]?.body;
	if ( ! ( body instanceof FormData ) ) {
		throw new Error( 'Expected FormData body on the last fetch call.' );
	}
	const fields: Record< string, string > = {};
	body.forEach( ( value, key ) => {
		fields[ key ] = String( value );
	} );
	return fields;
}

describe( 'useImportPost', () => {
	beforeEach( () => {
		// Fresh fetch mock per case keeps state from leaking between tests.
		vi.stubGlobal( 'fetch', vi.fn() );
	} );

	afterEach( () => {
		vi.unstubAllGlobals();
		vi.restoreAllMocks();
	} );

	it( 'submits the create-draft action with the documented field names', async () => {
		// ARRANGE: A bare successful response keeps the focus on the request.
		vi.mocked( fetch ).mockResolvedValue(
			new Response(
				JSON.stringify( {
					success: true,
					data: { edit_url: 'https://example.com/wp-admin/post.php?post=42&action=edit' },
				} ),
				{ status: 200 }
			)
		);

		// ACT: Mount the hook and trigger a submit.
		const { result } = renderHook( () => useImportPost( PARAMS ) );
		act( () => {
			result.current.submit();
		} );

		// ASSERT: The request hit admin-ajax with the exact field names the
		// backend handler reads.
		await waitFor( () => expect( fetch ).toHaveBeenCalledTimes( 1 ) );
		expect( vi.mocked( fetch ).mock.calls[ 0 ][ 0 ] ).toBe( PARAMS.ajaxurl );
		const fields = lastSubmittedFormFields();
		expect( fields.action ).toBe( 'safe_publish_create_draft' );
		expect( fields.nonce ).toBe( PARAMS.nonce );
		expect( fields.source_post_id ).toBe( String( PARAMS.sourcePostId ) );
		expect( fields.title ).toBe( PARAMS.title );
		expect( fields.source_link ).toBe( PARAMS.sourceLink );
		expect( fields.post_type ).toBe( PARAMS.postType );
	} );

	it( 'sets force_update only when isUpdate is true', async () => {
		// ARRANGE: A bare successful response so we can inspect the request.
		vi.mocked( fetch ).mockResolvedValue(
			new Response(
				JSON.stringify( {
					success: true,
					data: { edit_url: 'https://example.com/edit' },
				} ),
				{ status: 200 }
			)
		);

		// ACT: Mount with isUpdate=true and submit.
		const { result } = renderHook( () =>
			useImportPost( { ...PARAMS, isUpdate: true } )
		);
		act( () => {
			result.current.submit();
		} );

		// ASSERT: force_update is on the payload — the backend uses this to
		// skip its existence-confirmation roundtrip.
		await waitFor( () => expect( fetch ).toHaveBeenCalledTimes( 1 ) );
		expect( lastSubmittedFormFields().force_update ).toBe( 'true' );
	} );

	it( 'omits force_update when isUpdate is false', async () => {
		// ARRANGE: Successful response, focus on the request shape.
		vi.mocked( fetch ).mockResolvedValue(
			new Response(
				JSON.stringify( {
					success: true,
					data: { edit_url: 'https://example.com/edit' },
				} ),
				{ status: 200 }
			)
		);

		// ACT: Default PARAMS has isUpdate=false.
		const { result } = renderHook( () => useImportPost( PARAMS ) );
		act( () => {
			result.current.submit();
		} );

		// ASSERT: No force_update field on a plain Import submit.
		await waitFor( () => expect( fetch ).toHaveBeenCalledTimes( 1 ) );
		expect( lastSubmittedFormFields().force_update ).toBeUndefined();
	} );

	it( 'falls back to "post" when postType is an empty string', async () => {
		// ARRANGE: Successful response.
		vi.mocked( fetch ).mockResolvedValue(
			new Response(
				JSON.stringify( {
					success: true,
					data: { edit_url: 'https://example.com/edit' },
				} ),
				{ status: 200 }
			)
		);

		// ACT: Submit with a blank postType, simulating a row without a type.
		const { result } = renderHook( () =>
			useImportPost( { ...PARAMS, postType: '' } )
		);
		act( () => {
			result.current.submit();
		} );

		// ASSERT: Backend still receives a valid post_type, never empty.
		await waitFor( () => expect( fetch ).toHaveBeenCalledTimes( 1 ) );
		expect( lastSubmittedFormFields().post_type ).toBe( 'post' );
	} );

	it( 'captures editUrl and warnings on success', async () => {
		// ARRANGE: Successful response with a warning attached.
		const warning = {
			type: 'author_fallback_applied' as const,
			source: { email: 'a@b.test', login: 'a', display_name: 'A' },
			fallback_user_id: 7,
		};
		vi.mocked( fetch ).mockResolvedValue(
			new Response(
				JSON.stringify( {
					success: true,
					data: {
						edit_url: 'https://example.com/edit',
						warnings: [ warning ],
					},
				} ),
				{ status: 200 }
			)
		);

		// ACT: Mount and submit.
		const { result } = renderHook( () => useImportPost( PARAMS ) );
		act( () => {
			result.current.submit();
		} );

		// ASSERT: Both pieces of post-submit state surface to the caller.
		await waitFor( () =>
			expect( result.current.editUrl ).toBe( 'https://example.com/edit' )
		);
		expect( result.current.warnings ).toEqual( [ warning ] );
		expect( result.current.error ).toBeNull();
		expect( result.current.isLoading ).toBe( false );
	} );

	it( 'surfaces the backend message when result.success is false', async () => {
		// ARRANGE: Server reports a structured failure.
		vi.mocked( fetch ).mockResolvedValue(
			new Response(
				JSON.stringify( { success: false, data: 'Source post is missing' } ),
				{ status: 200 }
			)
		);

		// ACT: Mount and submit.
		const { result } = renderHook( () => useImportPost( PARAMS ) );
		act( () => {
			result.current.submit();
		} );

		// ASSERT: The caller can show the backend's reason instead of a generic
		// fallback. editUrl stays null so success-only UI doesn't render.
		await waitFor( () =>
			expect( result.current.error ).toBe( 'Source post is missing' )
		);
		expect( result.current.editUrl ).toBeNull();
	} );

	it( 'reports a missing-edit-url error when the success payload lacks it', async () => {
		// ARRANGE: Successful envelope but no edit_url — a backend regression.
		vi.mocked( fetch ).mockResolvedValue(
			new Response(
				JSON.stringify( { success: true, data: {} } ),
				{ status: 200 }
			)
		);

		// ACT: Mount and submit.
		const { result } = renderHook( () => useImportPost( PARAMS ) );
		act( () => {
			result.current.submit();
		} );

		// ASSERT: Hook refuses to treat a malformed success as a success.
		await waitFor( () =>
			expect( result.current.error ).toBe(
				'Invalid response: missing edit URL'
			)
		);
		expect( result.current.editUrl ).toBeNull();
	} );

	it( 'falls back to the error message when fetch rejects', async () => {
		// ARRANGE: Simulate a network failure.
		vi.mocked( fetch ).mockRejectedValue( new Error( 'network down' ) );

		// ACT: Mount and submit.
		const { result } = renderHook( () => useImportPost( PARAMS ) );
		act( () => {
			result.current.submit();
		} );

		// ASSERT: The thrown error's message reaches the caller verbatim and
		// loading clears so the UI isn't stuck spinning.
		await waitFor( () =>
			expect( result.current.error ).toBe( 'network down' )
		);
		expect( result.current.isLoading ).toBe( false );
	} );

	it( 'treats a non-array warnings field as empty', async () => {
		// ARRANGE: Successful response with a non-array warnings field,
		// exercising the hook's defensive Array.isArray guard.
		vi.mocked( fetch ).mockResolvedValue(
			new Response(
				JSON.stringify( {
					success: true,
					data: {
						edit_url: 'https://example.com/edit',
						warnings: 'not an array',
					},
				} ),
				{ status: 200 }
			)
		);

		// ACT: Mount and submit.
		const { result } = renderHook( () => useImportPost( PARAMS ) );
		act( () => {
			result.current.submit();
		} );

		// ASSERT: warnings stays an empty array; callers can map() without
		// guarding for a string.
		await waitFor( () =>
			expect( result.current.editUrl ).toBe( 'https://example.com/edit' )
		);
		expect( result.current.warnings ).toEqual( [] );
	} );
} );
