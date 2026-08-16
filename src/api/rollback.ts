/**
 * Rollback API helpers for the Manage listing.
 *
 * Wraps the `safe_publish_rollback_item` admin-ajax endpoint and provides the
 * shared delete-vs-restore prediction used by the single and bulk rollback
 * modals.
 *
 * @file This file defines the rollback API helpers for the Safe Publish plugin.
 */

import { __ } from '@wordpress/i18n';

import { getErrorMessage } from '../utils';

import type { ApiResponse, UnifiedPostRow } from '../types';

/**
 * Action the server performs when rolling back an item: A newly created post
 * is deleted, an updated post is restored to its previous version.
 */
export type RollbackAction = 'deleted' | 'restored';

/**
 * Outcome of rolling back a single item.
 *
 * @property {boolean}        success   Whether the rollback succeeded.
 * @property {RollbackAction} [action]  Action taken on success.
 * @property {string}         [message] Confirmation message on success.
 * @property {string}         [error]   Human-readable message on failure.
 */
export type RollbackItemOutcome =
	| { success: true; action: RollbackAction; message: string }
	| { success: false; error: string };

/**
 * Pairs a source row with its rollback outcome so callers can report which
 * titles succeeded or failed.
 */
export interface BulkRollbackEntry {
	item: UnifiedPostRow;
	outcome: RollbackItemOutcome;
}

/**
 * Aggregated result of a bulk rollback run.
 *
 * @property {number}              total      Number of items attempted.
 * @property {number}              successful Number rolled back successfully.
 * @property {number}              failed     Number that failed.
 * @property {BulkRollbackEntry[]} entries    Per-item outcomes, in input order.
 */
export interface BulkRollbackResult {
	total: number;
	successful: number;
	failed: number;
	entries: BulkRollbackEntry[];
}

/**
 * Predicts whether rolling back an item restores its previous version (true)
 * or permanently deletes a newly created post (false). Mirrors the server's
 * Session_Rollback_Service: Only fresh creations (no captured previous
 * content) get deleted; every other eligible row restores.
 *
 * @param {UnifiedPostRow} item Unified Posts listing row.
 *
 * @return {boolean} True when the rollback restores a previous version.
 */
export const isRollbackRestore = ( item: UnifiedPostRow ): boolean =>
	item.has_previous_content;

/**
 * Rolls back a single import event via the admin-ajax endpoint.
 *
 * @param {number} itemId  Items-table row ID to roll back.
 * @param {string} ajaxurl WordPress admin-ajax URL.
 * @param {string} nonce   AJAX nonce for the rollback endpoint.
 *
 * @return {Promise<RollbackItemOutcome>} The endpoint outcome.
 */
export const rollbackItem = async (
	itemId: number,
	ajaxurl: string,
	nonce: string
): Promise< RollbackItemOutcome > => {
	const formData = new FormData();
	formData.append( 'action', 'safe_publish_rollback_item' );
	formData.append( 'nonce', nonce );
	formData.append( 'item_id', itemId.toString() );

	try {
		const response = await fetch( ajaxurl, {
			method: 'POST',
			body: formData,
			headers: { Accept: 'application/json; charset=utf-8' },
		} );

		const result = ( await response.json() ) as ApiResponse< {
			action: RollbackAction;
			message: string;
		} >;

		if ( ! result.success ) {
			return {
				success: false,
				error: getErrorMessage(
					result,
					__( 'Failed to roll back the post.', 'safe-publish' )
				),
			};
		}

		return {
			success: true,
			action: result.data.action,
			message: result.data.message,
		};
	} catch ( error ) {
		return {
			success: false,
			error:
				error instanceof Error
					? error.message
					: __( 'Unknown error occurred', 'safe-publish' ),
		};
	}
};

/**
 * Rolls back multiple items sequentially, reporting progress after each.
 *
 * Runs one request per item against the single-item endpoint so each rollback
 * is independently applied and audited; a failure on one item does not abort
 * the rest. Sequential (not parallel) execution keeps server-side post hooks
 * from interleaving and yields deterministic progress.
 *
 * @param {UnifiedPostRow[]} items        Eligible rows to roll back.
 * @param {string}           ajaxurl      WordPress admin-ajax URL.
 * @param {string}           nonce        AJAX nonce for the rollback endpoint.
 * @param {Function}         [onProgress] Called with (completed, total) per item.
 *
 * @return {Promise<BulkRollbackResult>} Aggregated per-item outcomes.
 */
export const rollbackItems = async (
	items: UnifiedPostRow[],
	ajaxurl: string,
	nonce: string,
	onProgress?: ( completed: number, total: number ) => void
): Promise< BulkRollbackResult > => {
	const total = items.length;
	const entries: BulkRollbackEntry[] = [];
	let successful = 0;
	let failed = 0;

	for ( const item of items ) {
		let outcome: RollbackItemOutcome;

		if ( null === item.item_id ) {
			outcome = {
				success: false,
				error: __(
					'This post has no import record to roll back.',
					'safe-publish'
				),
			};
		} else {
			// Sequential by design: Rolling back one item at a time keeps
			// server-side post hooks from interleaving and yields
			// deterministic progress.
			// eslint-disable-next-line no-await-in-loop
			outcome = await rollbackItem( item.item_id, ajaxurl, nonce );
		}

		if ( outcome.success ) {
			++successful;
		} else {
			++failed;
		}

		entries.push( { item, outcome } );
		onProgress?.( entries.length, total );
	}

	return { total, successful, failed, entries };
};
