/**
 * Custom hook that drives the safe_publish_create_draft AJAX flow used by
 * the Import action on the Manage listing (first-time and re-import paths).
 *
 * @file This file defines the useImportPost hook.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ApiResponse, CreateDraftResponse, Warning } from '../../types';
import { getErrorMessage } from '../../utils';

/**
 * Parameters for the useImportPost hook.
 *
 * @property {number}     sourcePostId Source post ID to import or update.
 * @property {string}     title        Post title (used for the AJAX payload).
 * @property {string}     sourceLink   Source post permalink.
 * @property {string}     postType     Source post type slug.
 * @property {boolean}    isUpdate     True to set force_update so the backend
 *                                     skips its existence-confirmation roundtrip.
 * @property {string}     ajaxurl      WordPress admin-ajax URL.
 * @property {string}     nonce        AJAX nonce for the create-draft endpoint.
 * @property {() => void} [onSuccess]  Fires after every successful submit
 *                                     (not just the first).
 */
interface UseImportPostParams {
	sourcePostId: number;
	title: string;
	sourceLink: string;
	postType: string;
	isUpdate: boolean;
	ajaxurl: string;
	nonce: string;
	onSuccess?: () => void;
}

/**
 * Return value from the useImportPost hook.
 *
 * @property {boolean}       isLoading       Whether a submit is in progress.
 * @property {string | null} error           Error message if the submit failed.
 * @property {string | null} editUrl         Edit URL returned on success, or null.
 * @property {Warning[]}     warnings        Non-fatal warnings raised by the backend.
 * @property {boolean}       alreadyImported Whether the endpoint returned its
 *                                           update prompt instead of importing.
 * @property {() => void}    submit          Triggers the AJAX request.
 */
interface UseImportPostResult {
	isLoading: boolean;
	error: string | null;
	editUrl: string | null;
	warnings: Warning[];
	alreadyImported: boolean;
	submit: () => void;
}

/**
 * Hook that POSTs the create-draft action and tracks its lifecycle.
 *
 * @param {UseImportPostParams} params Import or update parameters.
 *
 * @return {UseImportPostResult} Request state and a submit trigger.
 */
export function useImportPost( {
	sourcePostId,
	title,
	sourceLink,
	postType,
	isUpdate,
	ajaxurl,
	nonce,
	onSuccess,
}: UseImportPostParams ): UseImportPostResult {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ editUrl, setEditUrl ] = useState< string | null >( null );
	const [ warnings, setWarnings ] = useState< Warning[] >( [] );
	const [ alreadyImported, setAlreadyImported ] = useState( false );

	const submit = (): void => {
		setIsLoading( true );
		setError( null );

		// ajax_create_draft re-fetches via fetch_fresh_post; only ID/title needed.
		const formData = new FormData();
		formData.append( 'action', 'safe_publish_create_draft' );
		formData.append( 'nonce', nonce );
		formData.append( 'source_post_id', sourcePostId.toString() );
		formData.append( 'title', title );
		formData.append( 'source_link', sourceLink );
		formData.append( 'post_type', postType || 'post' );

		if ( isUpdate ) {
			formData.append( 'force_update', 'true' );
		}

		fetch( ajaxurl, {
			method: 'POST',
			body: formData,
			headers: {
				Accept: 'application/json; charset=utf-8',
			},
		} )
			.then(
				( response ) =>
					response.json() as Promise<
						ApiResponse< CreateDraftResponse >
					>
			)
			.then( ( result ) => {
				setIsLoading( false );

				if ( ! result.success ) {
					setError(
						getErrorMessage(
							result,
							__( 'Failed to import the post.', 'safe-publish' )
						)
					);
					return;
				}

				const data = result.data;

				// An unforced submit for an already-imported post gets the
				// endpoint's confirmation prompt, whose edit_url would
				// otherwise read as a completed import.
				if ( 'update_existing' === data.confirm_action ) {
					setAlreadyImported( true );
					return;
				}

				// Validate edit URL before using it.
				if ( ! data.edit_url || typeof data.edit_url !== 'string' ) {
					setError(
						__(
							'Invalid response: missing edit URL',
							'safe-publish'
						)
					);
					return;
				}

				setWarnings(
					Array.isArray( data.warnings ) ? data.warnings : []
				);
				setEditUrl( data.edit_url );
				onSuccess?.();
			} )
			.catch( ( err ) => {
				setError(
					err instanceof Error
						? err.message
						: __( 'Unknown error occurred', 'safe-publish' )
				);
				setIsLoading( false );
			} );
	};

	return {
		isLoading,
		error,
		editUrl,
		warnings,
		alreadyImported,
		submit,
	};
}
