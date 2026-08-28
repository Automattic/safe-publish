/**
 * Custom hook for fetching and managing diff preview data.
 *
 * @file This file defines the useDiffPreview hook.
 */

import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { fetchDiffPreview } from '../../api/diff';

import type { BlockDiff, DiffPreviewResult } from '../../api/diff';
import type { DisplayError } from '../../types';

/**
 * Parameters for the useDiffPreview hook.
 *
 * The diff renderer re-fetches source content server-side, so the
 * frontend only needs to identify the post to diff.
 *
 * @property {number} postId   ID of the post to fetch diff for.
 * @property {string} postType Post type slug.
 */
interface UseDiffPreviewParams {
	postId: number;
	postType?: string;
}

/**
 * Return value from the useDiffPreview hook.
 *
 * @property {string | null}                        diffHtml        Source diff HTML.
 * @property {BlockDiff[]}                          blockDiffs      Block-level diffs.
 * @property {DiffPreviewResult['nonContentDiffs']} nonContentDiffs Non-content field diffs.
 * @property {boolean}                              isLoading       Whether diff is loading.
 * @property {?DisplayError}                        error           Error.
 * @property {() => void}                           refetch         Re-runs the diff fetch.
 */
interface UseDiffPreviewResult {
	diffHtml: string | null;
	blockDiffs: BlockDiff[];
	nonContentDiffs: DiffPreviewResult['nonContentDiffs'];
	isLoading: boolean;
	error: DisplayError | null;
	refetch: () => void;
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
}: UseDiffPreviewParams ): UseDiffPreviewResult {
	const [ diffHtml, setDiffHtml ] = useState< string | null >( null );
	const [ blockDiffs, setBlockDiffs ] = useState< BlockDiff[] >( [] );
	const [ nonContentDiffs, setNonContentDiffs ] = useState< DiffPreviewResult['nonContentDiffs'] >( undefined );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState< DisplayError | null >( null );
	const [ refetchCount, setRefetchCount ] = useState( 0 );

	useEffect( () => {
		let active = true;

		void ( async () => {
			setIsLoading( true );
			setError( null );

			const result = await fetchDiffPreview( {
				postId,
				postType,
			} );

			if ( ! active ) {
				return;
			}

			if ( result.error ) {
				setError( result.sourceError ?? result.error );
			} else if (
				result.contentDiffHtml !== undefined ||
				result.html !== undefined ||
				result.blockDiffs !== undefined ||
				result.nonContentDiffs !== undefined
			) {
				setDiffHtml( result.contentDiffHtml ?? result.html ?? null );
				setNonContentDiffs( result.nonContentDiffs ?? undefined );
				setBlockDiffs( result.blockDiffs ?? [] );
			} else {
				setError( __( 'No diff available.', 'safe-publish' ) );
			}

			setIsLoading( false );
		} )();

		return () => {
			active = false;
		};
	}, [ postId, postType, refetchCount ] );

	const refetch = useCallback( () => {
		setRefetchCount( ( count ) => count + 1 );
	}, [] );

	return {
		diffHtml,
		blockDiffs,
		nonContentDiffs,
		isLoading,
		error,
		refetch,
	};
}
