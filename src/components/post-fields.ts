/**
 * Pure helpers for sync-status column labels in DataViews.
 *
 * Centralized here so SourcePostsDataView and ImportedPostsDataView
 * share the same verdict-to-label mapping, and so the helpers can be
 * unit-tested in isolation from the React tree.
 *
 * @file This file defines sync-status label helpers for DataViews.
 */

import { SYNC_STATUS_LABELS } from '../utils';

import type { ImportSyncStatus, Post } from '../types';

/**
 * Returns the display label for the sync status column.
 *
 * @param {Post} item Source post.
 *
 * @return {string} Localized sync status label.
 */
export function getSyncStatusLabel( item: Post ): string {
	switch ( item.sync_status ) {
		case 'outdated':
			return SYNC_STATUS_LABELS.outdated;
		case 'up-to-date':
			return SYNC_STATUS_LABELS.upToDate;
		case 'unknown':
			return SYNC_STATUS_LABELS.unknown;
		default:
			return SYNC_STATUS_LABELS.available;
	}
}

/**
 * Returns the display label for an imported post's sync status verdict.
 *
 * Companion to getSyncStatusLabel: that helper derives a verdict from
 * the backend's annotated `sync_status`, this one takes a verdict
 * already returned by the sync-status batch endpoint.
 *
 * @param {ImportSyncStatus|null} status Verdict, or null while loading.
 *
 * @return {string} Localized sync status label.
 */
export function getImportedSyncStatusLabel(
	status: ImportSyncStatus | null
): string {
	switch ( status ) {
		case 'up-to-date':
			return SYNC_STATUS_LABELS.upToDate;
		case 'outdated':
			return SYNC_STATUS_LABELS.outdated;
		case 'missing':
			return SYNC_STATUS_LABELS.missing;
		case 'unreachable':
			return SYNC_STATUS_LABELS.unreachable;
		case 'invalid':
			return SYNC_STATUS_LABELS.invalid;
		default:
			return SYNC_STATUS_LABELS.loading;
	}
}

