/**
 * Diff API functions for comparing post content.
 *
 * Provides a function to fetch diff previews via the WordPress REST API.
 *
 * @file This file defines the diff API functions for the Safe Publish plugin.
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import type { JsonObject } from '../types';

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
 * Structured source-site detail attached to a failed diff request.
 */
export interface DiffPreviewSourceError {
	message: string;
	template: string;
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
 * @property {Object}      [sourceError]          Isolated source failure detail.
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
	sourceError?: DiffPreviewSourceError;
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
		const error = isRecord( err ) ? err : undefined;
		const message =
			typeof error?.message === 'string'
				? error.message
				: __( 'Failed to fetch diff', 'safe-publish' );
		const sourceError = getSourceError( error?.data );

		return sourceError
			? { error: message, sourceError }
			: { error: message };
	}
}

/**
 * Checks whether a value can be safely inspected by property name.
 *
 * @param {unknown} value Value to inspect.
 *
 * @return {boolean} Whether the value is a record.
 */
function isRecord( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null;
}

/**
 * Extracts structured source detail from WordPress' REST error data.
 *
 * @param {unknown} data WordPress REST error data.
 *
 * @return {DiffPreviewSourceError | undefined} Valid source detail.
 */
function getSourceError( data: unknown ): DiffPreviewSourceError | undefined {
	if ( ! isRecord( data ) || ! isRecord( data.source_error ) ) {
		return undefined;
	}

	const { message, template } = data.source_error;
	if (
		typeof message !== 'string' ||
		typeof template !== 'string' ||
		! template.includes( '<reason />' )
	) {
		return undefined;
	}

	return { message, template };
}
