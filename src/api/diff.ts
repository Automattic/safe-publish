/**
 * Diff API functions for comparing post content.
 *
 * Provides functions to fetch diff previews and update post content via the
 * WordPress REST API.
 *
 * @file This file defines the diff API functions for the CCP plugin.
 */

/**
 * Payload for requesting a diff preview.
 *
 * @property {number}  postId     External post ID to compare.
 * @property {string}  [postType] Post type slug.
 * @property {string}  [content]  Incoming content to compare.
 * @property {string}  [mode]     Display mode: 'split' or 'inline'.
 * @property {boolean} [cleanup]  Whether to clean up the diff output.
 */
export interface DiffPreviewPayload {
	postId: number;
	postType?: string;
	content?: string;
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
		attrs?: Record< string, any >;
		rendered: string;
	} | null;
	incoming?: {
		name?: string;
		attrs?: Record< string, any >;
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
		excerpt?: string;
		meta?: Record< string, any >;
		terms?: Record< string, string[] >;
	};
	current?: {
		title?: string;
		excerpt?: string;
		meta?: Record< string, any >;
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
	const res = await fetch( '/wp-json/ccp/v1/diff-preview', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( payload ),
	} );
	if ( ! res.ok ) {
		const text = await res.text().catch( () => 'Failed to fetch diff' );
		return { error: text };
	}
	return res.json();
}

export interface UpdatePostResult {
	success: boolean;
	error?: string;
}

/**
 * Updates a post's content via the REST API.
 *
 * Sends updated content, meta, terms, and other fields to the WordPress REST
 * API endpoint for updating posts.
 *
 * @param {number}                   postId            Post ID to update.
 * @param {string}                   content           New post content.
 * @param {string}                   [nonce]           REST API nonce.
 * @param {Record<string, any>}      [meta]            Meta fields to update.
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
	nonce?: string,
	meta?: Record< string, any >,
	terms?: Record< string, string[] >,
	title?: string,
	excerpt?: string,
	featuredMediaId?: number
): Promise< UpdatePostResult > {
	const headers: Record< string, string > = { 'Content-Type': 'application/json' };
	const wpNonce = nonce || ( window as any )?.ccpAdminData?.restNonce;
	if ( wpNonce ) {
		headers[ 'X-WP-Nonce' ] = wpNonce;
	}

	const body: Record< string, any > = {
		postId,
		content,
		...( typeof title !== 'undefined' ? { title } : {} ),
		...( typeof excerpt !== 'undefined' ? { excerpt } : {} ),
		...( meta ? { meta } : {} ),
		...( terms ? { terms } : {} ),
		...( typeof featuredMediaId !== 'undefined' ? { featuredMediaId } : {} ),
	};

	const res = await fetch( '/wp-json/ccp/v1/update-post', {
		method: 'POST',
		headers,
		body: JSON.stringify( body ),
	} );

	if ( ! res.ok ) {
		const text = await res.text().catch( () => '' );
		return { success: false, error: text || `HTTP ${ res.status }` };
	}

	const data = await res.json().catch( () => null );

	if ( data && data.success === true ) {
		return { success: true };
	}

	return { success: false, error: data && data.error ? data.error : 'Invalid response' };
}
