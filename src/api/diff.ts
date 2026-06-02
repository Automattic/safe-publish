/**
 * Diff API functions for comparing post content.
 *
 * Provides functions to fetch diff previews and update post content via the
 * WordPress REST API.
 *
 * @file This file defines the diff API functions for the Safe Publish plugin.
 */

import { __ } from '@wordpress/i18n';

import type { JsonObject, JsonValue } from '../types';

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
 * @property {number}      [localPostId]             Local post ID if matched.
 * @property {Object}      [incoming]                Incoming post data.
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
	localPostId?: number;
	incoming?: {
		title?: string;
		content?: string;
		excerpt?: string;
		meta?: JsonObject;
		terms?: Record< string, string[] >;
		featuredMedia?: number;
	};
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

/**
 * Result from updating a post's content.
 *
 * Discriminated union where success=true has no additional data,
 * and success=false may contain error information in either field.
 */
export type UpdatePostResult =
	| { success: true }
	| { success: false; data?: JsonValue; error?: string };

/**
 * Updates a post's content via the REST API.
 *
 * Sends updated content, meta, terms, and other fields to the WordPress REST
 * API endpoint for updating posts.
 *
 * @param {number}                   postId            Post ID to update.
 * @param {string}                   content           New post content.
 * @param {string}                   restNonce         REST API nonce for the X-WP-Nonce header.
 * @param {JsonObject}               [meta]            Meta fields to update.
 * @param {Record<string, string[]>} [terms]           Taxonomy terms to update.
 * @param {string}                   [title]           New post title.
 * @param {string}                   [excerpt]         New post excerpt.
 * @param {number}                   [featuredMediaId] Featured media ID.
 *
 * @return {Promise<UpdatePostResult>} Success or failure result.
 */
export async function updatePostContent(
	postId: number,
	content: string,
	restNonce: string,
	meta?: JsonObject,
	terms?: Record< string, string[] >,
	title?: string,
	excerpt?: string,
	featuredMediaId?: number
): Promise< UpdatePostResult > {
	const headers: Record< string, string > = {
		'Content-Type': 'application/json',
		'X-WP-Nonce': restNonce,
	};

	const body: Record< string, JsonValue > = {
		postId,
		content,
		...( typeof title !== 'undefined' ? { title } : {} ),
		...( typeof excerpt !== 'undefined' ? { excerpt } : {} ),
		...( meta ? { meta } : {} ),
		...( terms ? { terms } : {} ),
		...( typeof featuredMediaId !== 'undefined' ? { featuredMediaId } : {} ),
	};

	const res = await fetch( '/wp-json/safe-publish/v1/update-post', {
		method: 'POST',
		headers,
		body: JSON.stringify( body ),
	} );

	if ( ! res.ok ) {
		const text = await res.text().catch( () => '' );
		return { success: false, error: text || `HTTP ${ res.status }` };
	}

	const data = await res.json().catch( () => null ) as { success?: boolean; error?: string; data?: JsonValue } | null;

	if ( true === data?.success ) {
		return { success: true };
	}

	// Treat the entire response as an error response.
	if ( data && ( data.error || data.data ) ) {
		return {
			success: false,
			error: data.error,
			data: data.data,
		};
	}

	return { success: false, error: __( 'Invalid response from server', 'safe-publish' ) };
}
