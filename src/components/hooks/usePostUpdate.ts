/**
 * Custom hook for handling post updates.
 *
 * @file This file defines the usePostUpdate hook.
 */

import { updatePostContent } from '../../api/diff';
import { getErrorMessage } from '../../utils';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { DiffPreviewResult } from '../../api/diff';

/**
 * Parameters for the usePostUpdate hook.
 *
 * @property {number}                        localPostId Local post ID.
 * @property {DiffPreviewResult['incoming']} incoming    Source-side payload
 *                                                       captured by the diff
 *                                                       request; carries
 *                                                       fresh content,
 *                                                       title, excerpt,
 *                                                       meta, terms, and
 *                                                       featuredMedia.
 */
interface UsePostUpdateParams {
	localPostId: number;
	incoming: DiffPreviewResult['incoming'];
}

/**
 * Return value from the usePostUpdate hook.
 *
 * @property {boolean}             isUpdating       Whether update is in progress.
 * @property {string | null}       updateError      Error message if update failed.
 * @property {string | null}       updateSuccess    Success message if update succeeded.
 * @property {() => Promise<void>} handleUpdatePost Function to trigger post update.
 */
interface UsePostUpdateResult {
	isUpdating: boolean;
	updateError: string | null;
	updateSuccess: string | null;
	handleUpdatePost: () => Promise< void >;
}

/**
 * Hook to handle post update logic.
 *
 * @param {UsePostUpdateParams} params Post update parameters.
 *
 * @return {UsePostUpdateResult} Update state and handlers.
 */
export function usePostUpdate( {
	localPostId,
	incoming,
}: UsePostUpdateParams ): UsePostUpdateResult {
	const [ isUpdating, setIsUpdating ] = useState( false );
	const [ updateError, setUpdateError ] = useState< string | null >( null );
	const [ updateSuccess, setUpdateSuccess ] = useState< string | null >( null );

	/**
	 * Handles the post update operation.
	 *
	 * Builds the update payload from all incoming fields, filters
	 * internal meta keys, and sends the update request.
	 *
	 * @return {Promise<void>} Resolves when update is complete.
	 */
	const handleUpdatePost = async (): Promise< void > => {
		setIsUpdating( true );
		setUpdateError( null );
		setUpdateSuccess( null );

		// Refuse to submit when the diff renderer didn't return content —
		// posting an empty string would silently wipe the destination post.
		if ( typeof incoming?.content !== 'string' || '' === incoming.content ) {
			setUpdateError(
				__(
					'Source content is unavailable. Close this diff and reopen it to retry.',
					'safe-publish'
				)
			);
			setIsUpdating( false );
			return;
		}

		const meta = incoming?.meta;
		const metaToSend =
			meta && typeof meta === 'object'
				? Object.fromEntries(
						Object.entries( meta ).filter(
							( [ key ] ) =>
								! key.startsWith( 'safe_publish_' ) &&
								! key.startsWith( '_' )
						)
				  )
				: undefined;

		const result = await updatePostContent(
			localPostId,
			incoming.content,
			window?.safePublishAdminData?.restNonce,
			metaToSend,
			incoming?.terms,
			incoming?.title,
			incoming?.excerpt,
			typeof incoming?.featuredMedia === 'number'
				? incoming.featuredMedia
				: undefined
		);

		if ( result.success ) {
			setUpdateSuccess(
				__( 'Post updated successfully.', 'safe-publish' )
			);
		} else {
			setUpdateError(
				getErrorMessage(
					result,
					__( 'Failed to update post.', 'safe-publish' )
				)
			);
		}

		setIsUpdating( false );
	};

	return {
		isUpdating,
		updateError,
		updateSuccess,
		handleUpdatePost,
	};
}
