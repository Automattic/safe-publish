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
 * Options for which post fields to update.
 *
 * @property {boolean} title         Update post title.
 * @property {boolean} excerpt       Update post excerpt.
 * @property {boolean} meta          Update post meta fields.
 * @property {boolean} terms         Update post taxonomies/terms.
 * @property {boolean} featuredMedia Update featured image.
 */
interface UpdateOptions {
	title: boolean;
	excerpt: boolean;
	meta: boolean;
	terms: boolean;
	featuredMedia: boolean;
}

/**
 * Parameters for the usePostUpdate hook.
 *
 * @property {number}                        localPostId     Local post ID to update.
 * @property {string}                        content         Post content.
 * @property {number}                        featuredMediaId Featured media attachment ID.
 * @property {DiffPreviewResult['incoming']} incoming        Incoming post data from external source.
 */
interface UsePostUpdateParams {
	localPostId: number;
	content: string;
	featuredMediaId?: number;
	incoming: DiffPreviewResult['incoming'];
}

/**
 * Return value from the usePostUpdate hook.
 *
 * @property {UpdateOptions}       updateOpts       Current update options.
 * @property {Function}            setUpdateOpts    Setter for update options.
 * @property {boolean}             isUpdating       Whether update is in progress.
 * @property {string | null}       updateError      Error message if update failed.
 * @property {string | null}       updateSuccess    Success message if update succeeded.
 * @property {() => Promise<void>} handleUpdatePost Function to trigger post update.
 */
interface UsePostUpdateResult {
	updateOpts: UpdateOptions;
	setUpdateOpts: React.Dispatch< React.SetStateAction< UpdateOptions > >;
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
	content,
	featuredMediaId,
	incoming,
}: UsePostUpdateParams ): UsePostUpdateResult {
	const [ updateOpts, setUpdateOpts ] = useState< UpdateOptions >( {
		title: true,
		excerpt: true,
		meta: true,
		terms: true,
		featuredMedia: true,
	} );

	const [ isUpdating, setIsUpdating ] = useState( false );
	const [ updateError, setUpdateError ] = useState< string | null >( null );
	const [ updateSuccess, setUpdateSuccess ] = useState< string | null >( null );

	/**
	 * Handles the post update operation.
	 *
	 * Conditionally builds the update payload based on user selections,
	 * filters internal meta keys, and sends the update request.
	 *
	 * @return {Promise<void>} Resolves when update is complete.
	 */
	const handleUpdatePost = async (): Promise< void > => {
		setIsUpdating( true );
		setUpdateError( null );
		setUpdateSuccess( null );

		const maybeMeta = updateOpts.meta ? ( incoming?.meta ?? undefined ) : undefined;
		const maybeTerms = updateOpts.terms ? ( incoming?.terms ?? undefined ) : undefined;
		const maybeTitle = updateOpts.title ? ( incoming?.title ?? undefined ) : undefined;
		const maybeExcerpt = updateOpts.excerpt ? ( incoming?.excerpt ?? undefined ) : undefined;
		const maybeFeaturedId =
			updateOpts.featuredMedia && typeof featuredMediaId === 'number'
				? featuredMediaId
				: undefined;

		const metaToSend =
			maybeMeta && typeof maybeMeta === 'object'
				? Object.fromEntries(
						Object.entries( maybeMeta ).filter(
							( [ key ] ) => ! key.startsWith( 'safe_publish_' ) && ! key.startsWith( '_' )
						)
				  )
				: undefined;

		const result = await updatePostContent(
			localPostId,
			content,
			window?.safePublishAdminData?.restNonce,
			metaToSend,
			maybeTerms,
			maybeTitle,
			maybeExcerpt,
			maybeFeaturedId
		);

		if ( result.success ) {
			setUpdateSuccess( __( 'Post updated successfully.', 'safe-publish' ) );
		} else {
			setUpdateError( getErrorMessage( result, __( 'Failed to update post.', 'safe-publish' ) ) );
		}

		setIsUpdating( false );
	};

	return {
		updateOpts,
		setUpdateOpts,
		isUpdating,
		updateError,
		updateSuccess,
		handleUpdatePost,
	};
}
