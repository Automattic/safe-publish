/**
 * Diff API functions for comparing post content.
 *
 * Provides a function to fetch diff previews via the WordPress REST API.
 *
 * @file This file defines the diff API functions for the Safe Publish plugin.
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { getSourceError, isRecord } from '../utils';

import type { JsonObject, SourceError } from '../types';

/**
 * Payload for requesting a diff preview.
 *
 * @property {number} postId     Source post ID to compare.
 * @property {string} [postType] Post type slug.
 */
export interface DiffPreviewPayload {
	postId: number;
	postType?: string;
}

/**
 * Represents a single block's diff information.
 *
 * @property {number} index      Block's position index.
 * @property {string} status     Diff status of the block.
 * @property {Object} [current]  Current block data.
 * @property {Object} [incoming] Incoming block data.
 */
export interface BlockDiff {
	index: number;
	status: 'unchanged' | 'added' | 'removed' | 'modified';
	current?: {
		name?: string;
		attrs?: JsonObject;
		rendered: string;
	} | null;
	incoming?: {
		name?: string;
		attrs?: JsonObject;
		rendered: string;
	} | null;
}

/**
 * Result from a diff preview request.
 *
 * @property {string}      [contentDiffHtml]      Raw content HTML diff.
 * @property {Object}      [nonContentDiffs]      Non-content field diffs.
 * @property {BlockDiff[]} [blockDiffs]           Block-level diffs.
 * @property {string}      [error]                Error message if failed.
 * @property {Object}      [sourceError]          Source failure detail.
 * @property {Object}      [current]              Current post data.
 * @property {string}      [html]                 Legacy HTML diff output.
 * @property {string}      [incomingRenderedHtml] Incoming rendered HTML.
 * @property {string}      [currentRenderedHtml]  Current rendered HTML.
 */
export interface DiffPreviewResult {
	contentDiffHtml?: string;
	nonContentDiffs?: {
		title?: string;
		excerpt?: string;
		taxonomies?: string;
		meta?: string;
		featuredMedia?: string;
	};
	blockDiffs?: BlockDiff[];
	error?: string;
	sourceError?: SourceError;
	current?: {
		title?: string;
		excerpt?: string;
		meta?: JsonObject;
		terms?: Record< string, string[] >;
	};
	html?: string;
	incomingRenderedHtml?: string;
	currentRenderedHtml?: string;
}

/**
 * Fetches a diff preview for a post.
 *
 * Compares the current post content with incoming content and returns the
 * differences in various formats.
 *
 * @param {DiffPreviewPayload} payload Payload containing post ID and content.
 *
 * @return {Promise<DiffPreviewResult>} Diff preview result.
 */
export async function fetchDiffPreview(
	payload: DiffPreviewPayload
): Promise< DiffPreviewResult > {
	try {
		return await apiFetch< DiffPreviewResult >( {
			path: '/safe-publish/v1/diff-preview',
			method: 'POST',
			data: payload,
		} );
	} catch ( err ) {
		const error = isRecord( err ) ? err : {};
		const message =
			typeof error.message === 'string'
				? error.message
				: __( 'Failed to fetch diff', 'safe-publish' );
		const sourceError = getSourceError( error.data );

		return sourceError
			? { error: message, sourceError }
			: { error: message };
	}
}
