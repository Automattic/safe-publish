/**
 * Diff API functions for comparing post content.
 *
 * Provides a function to fetch diff previews via the WordPress REST API.
 *
 * @file This file defines the diff API functions for the Safe Publish plugin.
 */

import { __ } from '@wordpress/i18n';

import type { JsonObject } from '../types';

/**
 * Payload for requesting a diff preview.
 *
 * @property {number}  postId     Source post ID to compare.
 * @property {string}  [postType] Post type slug.
 * @property {string}  [mode]     Display mode: 'split' or 'inline'.
 * @property {boolean} [cleanup]  Whether to clean up the diff output.
 */
export interface DiffPreviewPayload {
	postId: number;
	postType?: string;
	mode?: 'split' | 'inline';
	cleanup?: boolean;
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
 * @property {string}      [contentDiffHtml]         Raw content HTML diff.
 * @property {string}      [renderedContentDiffHtml] Rendered content HTML diff.
 * @property {Object}      [nonContentDiffs]         Non-content field diffs.
 * @property {BlockDiff[]} [blockDiffs]              Block-level diffs.
 * @property {string}      [error]                   Error message if failed.
 * @property {Object}      [current]                 Current post data.
 * @property {string}      [html]                    Legacy HTML diff output.
 * @property {string}      [incomingRenderedHtml]    Incoming rendered HTML.
 * @property {string}      [currentRenderedHtml]     Current rendered HTML.
 */
export interface DiffPreviewResult {
	contentDiffHtml?: string;
	renderedContentDiffHtml?: string;
	nonContentDiffs?: {
		title?: string;
		excerpt?: string;
		taxonomies?: string;
		meta?: string;
		featuredMedia?: string;
	};
	blockDiffs?: BlockDiff[];
	error?: string;
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
 * @param {DiffPreviewPayload} payload   Payload containing post ID and content.
 * @param {string}             restNonce REST API nonce for the X-WP-Nonce header.
 *
 * @return {Promise<DiffPreviewResult>} Diff preview result.
 */
export async function fetchDiffPreview(
	payload: DiffPreviewPayload,
	restNonce: string
): Promise< DiffPreviewResult > {
	const headers: Record< string, string > = {
		'Content-Type': 'application/json',
		'X-WP-Nonce': restNonce,
	};

	const res = await fetch( '/wp-json/safe-publish/v1/diff-preview', {
		method: 'POST',
		headers,
		body: JSON.stringify( payload ),
	} );
	if ( ! res.ok ) {
		const text = await res.text().catch( () => __( 'Failed to fetch diff', 'safe-publish' ) );
		return { error: text };
	}

	return res.json() as Promise<DiffPreviewResult>;
}
