/**
 * Pure helpers for rendering and sorting Post columns in DataViews.
 *
 * Centralized here so the dashboard column config (in
 * SourcePostsDataView) can reuse the same logic for `getValue`,
 * `render`, and `sort`, and so the helpers can be unit-tested in
 * isolation from the React tree.
 *
 * @file This file defines Post column helpers for DataViews.
 */

import { PUBLISH_STATUS_LABELS, SYNC_STATUS_LABELS } from '../utils';

import type { Post } from '../types';

/**
 * Returns the display label for the post type column.
 *
 * @param {Post} item Source post.
 *
 * @return {string} Capitalized post type (e.g. "Post", "Page").
 */
export function getPostTypeLabel( item: Post ): string {
	const postType = item.post_type || 'post';
	return postType.charAt( 0 ).toUpperCase() + postType.slice( 1 );
}

/**
 * Returns the display label for the sync status column.
 *
 * @param {Post} item Source post.
 *
 * @return {string} Localized sync status label.
 */
export function getSyncStatusLabel( item: Post ): string {
	if ( item.is_imported && item.has_update ) {
		return SYNC_STATUS_LABELS.outdated;
	}
	if ( item.is_imported ) {
		return SYNC_STATUS_LABELS.upToDate;
	}
	return SYNC_STATUS_LABELS.available;
}

/**
 * Returns the display label for the publish status column.
 *
 * @param {Post} item Source post.
 *
 * @return {string} Localized publish status label, or empty when
 *                  the post has no local counterpart.
 */
export function getPublishStatusLabel( item: Post ): string {
	if ( ! item.is_imported || ! item.local_status ) {
		return '';
	}
	return PUBLISH_STATUS_LABELS[ item.local_status ] ?? item.local_status;
}
