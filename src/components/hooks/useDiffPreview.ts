/**
 * Custom hook for fetching and managing diff preview data.
 *
 * @file This file defines the useDiffPreview hook.
 */

import { fetchDiffPreview } from '../../api/diff';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { BlockDiff, DiffPreviewResult } from '../../api/diff';

/**
 * Parameters for the useDiffPreview hook.
 *
 * @property {number} postId   ID of the post to fetch diff for.
 * @property {string} postType Post type slug.
 * @property {string} content  Post content.
 * @property {string} excerpt  Post excerpt.
 */
interface UseDiffPreviewParams {
	postId: number;
	postType?: string;
	content?: string;
	excerpt?: string;
}

/**
 * Return value from the useDiffPreview hook.
 *
 * @property {string | null}                        diffHtml         Source diff HTML.
 * @property {string | null}                        renderedDiffHtml Rendered diff HTML.
 * @property {BlockDiff[]}                          blockDiffs       Block-level diffs.
 * @property {DiffPreviewResult['nonContentDiffs']} nonContentDiffs  Non-content field diffs.
 * @property {DiffPreviewResult['incoming']}        incoming         Incoming post data.
 * @property {number}                               localPostId      Local post ID.
 * @property {boolean}                              isLoading        Whether diff is loading.
 * @property {string | null}                        error            Error message if fetch failed.
 */
interface UseDiffPreviewResult {
	diffHtml: string | null;
	renderedDiffHtml: string | null;
	blockDiffs: BlockDiff[];
	nonContentDiffs: DiffPreviewResult['nonContentDiffs'];
	incoming: DiffPreviewResult['incoming'];
	localPostId: number;
	isLoading: boolean;
	error: string | null;
}

/**
 * Hook to fetch diff preview for a post.
 *
 * @param {UseDiffPreviewParams} params Post data for fetching diff.
 *
 * @return {UseDiffPreviewResult} Diff preview data and loading state.
 */
export function useDiffPreview( {
	postId,
	postType,
	content,
	excerpt,
}: UseDiffPreviewParams ): UseDiffPreviewResult {
	const [ diffHtml, setDiffHtml ] = useState< string | null >( null );
	const [ renderedDiffHtml, setRenderedDiffHtml ] = useState< string | null >( null );
	const [ blockDiffs, setBlockDiffs ] = useState< BlockDiff[] >( [] );
	const [ nonContentDiffs, setNonContentDiffs ] = useState< DiffPreviewResult['nonContentDiffs'] >( undefined );
	const [ incoming, setIncoming ] = useState< DiffPreviewResult['incoming'] >( undefined );
	const [ localPostId, setLocalPostId ] = useState< number >( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let mounted = true;

		/**
		 * Fetches diff preview data from the API.
		 *
		 * Handles loading states, error conditions, and updates state
		 * with the fetched diff data.
		 *
		 * @return {Promise<void>} Resolves when fetch is complete.
		 */
		const fetchDiff = async (): Promise< void > => {
			setIsLoading( true );
			setError( null );
			const result = await fetchDiffPreview( {
				postId,
				postType,
				content: content || excerpt || '',
				mode: 'split',
				cleanup: true,
			} );

			if ( ! mounted ) {
				return;
			}

			if ( result.error ) {
				setError( result.error );
			} else if ( ( result.contentDiffHtml || result.html ) && result.localPostId ) {
				setDiffHtml( result.contentDiffHtml ?? result.html ?? null );
				setLocalPostId( result.localPostId ?? 0 );
				setIncoming( result.incoming ?? undefined );
				setNonContentDiffs( result.nonContentDiffs ?? undefined );
				setRenderedDiffHtml( result.renderedContentDiffHtml ?? null );
				setBlockDiffs( result.blockDiffs || [] );
			} else {
				setError( __( 'No diff available.', 'safe-publish' ) );
			}

			if ( mounted ) {
				setIsLoading( false );
			}
		};

		void fetchDiff();

		return () => {
			mounted = false;
		};
	}, [ postId, postType, content, excerpt ] );

	return {
		diffHtml,
		renderedDiffHtml,
		blockDiffs,
		nonContentDiffs,
		incoming,
		localPostId,
		isLoading,
		error,
	};
}
