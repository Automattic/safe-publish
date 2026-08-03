/**
 * DataViews actions for the unified Posts listing. Each action's isEligible
 * inspects `local_state` so row menus adapt without caller pre-filtering.
 *
 * @file This file defines DataViews actions for the Safe Publish plugin.
 */
import {
	download,
	pages,
	pencil,
	rotateLeft,
	seen,
	trash,
	unseen,
	update,
} from '@wordpress/icons';

import BulkImportFlow from './components/BulkImportFlow';
import BulkRollbackPostModal from './components/BulkRollbackPostModal';
import DeleteFailedImportsModal from './components/DeleteFailedImportsModal';
import DeletePostModal from './components/DeletePostModal';
import ImportModal from './components/ImportModal';
import PostDiffModal from './components/PostDiffModal';
import RollbackPostModal from './components/RollbackPostModal';
import { RETRY_PENDING_DELAY_MS } from './constants';
import {
	ApiResponse,
	AttentionIssue,
	BulkRetryAttentionResponse,
	ImportSyncStatus,
	InboxDegradation,
	InboxFailure,
	NeedsAttentionRow,
	NeedsAttentionView,
	RetryAttentionIssueResponse,
	SetIgnoredResponse,
	UnifiedPostRow,
} from './types';
import {
	attentionIssueId,
	attentionIssueLabel,
	getErrorMessage,
	renderIssueMessage,
} from './utils';
import { Action } from '@wordpress/dataviews/build-types';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Auth context for the unified Posts action set.
 */
export interface PostsActionsContext {
	ajaxurl: string;
	nonce: string;
	onNotice?: ( notice: ActionNotice | null ) => void;
}

/**
 * Returns true when the row supports rollback (had an import event whose
 * active item is success/updated and the local post still resolves).
 *
 * @param {UnifiedPostRow} item Row to test.
 * @return {boolean} True when Rollback should appear.
 */
const isRollbackEligible = ( item: UnifiedPostRow ): boolean =>
	( 'up-to-date' === item.local_state || 'outdated' === item.local_state )
		&& null !== item.item_id;

/**
 * Creates DataViews actions for the unified Manage listing. Import covers
 * first-time create, re-import (update), and retry against a single source
 * endpoint; per-state eligibility: 'available' → Import; 'up-to-date'/'outdated'
 * → Compare, Import, Edit, Trash, Rollback (Compare/Import hide on up-to-date).
 * Mixed bulk selections rely on per-item isEligible.
 *
 * @param {Function}            onRefresh       Listing refresh callback.
 * @param {boolean}             isAuthorized    Whether the source authorizes imports.
 * @param {PostsActionsContext} context         Admin-ajax URL, nonce, notice sink.
 * @param {Object}              syncStatuses    Per-row sync entries keyed by source post id.
 * @param {number}              [selectedCount] Selected rows; sizes the bulk-import skipped count.
 *
 * @return {Action<UnifiedPostRow>[]} Array of DataViews actions.
 */
export const createPostsActions = (
	onRefresh: ( () => void ) | undefined,
	isAuthorized: boolean,
	context: PostsActionsContext,
	syncStatuses: Record< number, { status: ImportSyncStatus } >,
	selectedCount = 0
): Action< UnifiedPostRow >[] => {
	const actions: Action< UnifiedPostRow >[] = [
	{
		id: 'compare-post',
		label: __( 'Compare', 'safe-publish' ),
		icon: pages,
		isPrimary: true,
		hideModalHeader: false,
		supportsBulk: false,
		modalSize: 'fill',
		isEligible: ( item ) =>
			null !== item.source_post_id
				&& (
					'outdated' === item.local_state
					|| (
						'up-to-date' === item.local_state
							&& 'outdated' ===
								syncStatuses[ item.source_post_id ]?.status
					)
				),
		RenderModal: ( { items } ) => (
			<PostDiffModal
				items={ items }
				ajaxurl={ context.ajaxurl }
				nonce={ context.nonce }
				syncStatus={
					null !== items[ 0 ].source_post_id
						? syncStatuses[ items[ 0 ].source_post_id ]?.status
						: undefined
				}
				onRefresh={ onRefresh }
			/>
		),
	},
	{
		id: 'import',
		label: __( 'Import', 'safe-publish' ),
		icon: download,
		isPrimary: true,
		isEligible: ( item ) =>
			isAuthorized
				&& null !== item.source_post_id
				&& (
					'available' === item.local_state
					|| 'outdated' === item.local_state
					|| (
						'up-to-date' === item.local_state
							&& 'outdated' ===
								syncStatuses[ item.source_post_id ]?.status
					)
				),
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		supportsBulk: true,
		RenderModal: ( { items, closeModal } ) => {
			if ( 1 === items.length ) {
				const item = items[ 0 ];
				const isUpdate =
					'up-to-date' === item.local_state
						|| 'outdated' === item.local_state;
				return (
					<ImportModal
						sourcePostId={ item.source_post_id ?? item.id }
						title={ item.title }
						sourceLink={ item.link }
						postType={ item.post_type }
						isUpdate={ isUpdate }
						skippedCount={ Math.max( 0, selectedCount - items.length ) }
						ajaxurl={ context.ajaxurl }
						nonce={ context.nonce }
						closeModal={ closeModal }
						onRefresh={ onRefresh }
					/>
				);
			}

			return (
				<BulkImportFlow
					posts={ items.map( ( item ) => ( {
						id: item.source_post_id ?? item.id,
						post_type: item.post_type,
						title: item.title,
					} ) ) }
					skippedCount={ Math.max( 0, selectedCount - items.length ) }
					context={ { ajaxurl: context.ajaxurl, nonce: context.nonce } }
					onClose={ () => closeModal?.() }
					onRefresh={ onRefresh }
					labels={ {
						/* translators: %d is the number of selected posts */
						confirmQuestion: __(
							'Import %d selected posts from the source?',
							'safe-publish'
						),
						/* translators: 1: importable count, 2: total selected count */
						confirmQuestionPartial: __(
							'Import %1$d of %2$d selected posts from the source?',
							'safe-publish'
						),
						confirmDescription: __(
							'This pulls each post from the source — content, images, links, and formatting.',
							'safe-publish'
						),
						processingHeading: __(
							'Importing posts as a batch…',
							'safe-publish'
						),
						/* translators: %d is the number of selected posts */
						processingFootnote: __(
							'All %d posts will be imported in a single session',
							'safe-publish'
						),
						completedHeading: __( 'Import completed!', 'safe-publish' ),
						failedHeading: __( 'Import failed', 'safe-publish' ),
						partialHeading: __(
							'Import completed with errors',
							'safe-publish'
						),
						/* translators: 1: successful count, 2: total count */
						totalSummary: __(
							'Imported: %1$d of %2$d posts',
							'safe-publish'
						),
						/* translators: %d is the number of selected posts */
						primaryButton: __( 'Import %d posts', 'safe-publish' ),
						loadingButton: __( 'Importing…', 'safe-publish' ),
						primaryActionId: 'import',
					} }
				/>
			);
		},
	},
	{
		id: 'edit-post',
		label: __( 'Edit', 'safe-publish' ),
		icon: pencil,
		isPrimary: true,
		isEligible: ( item ) =>
			( 'up-to-date' === item.local_state || 'outdated' === item.local_state )
				&& '' !== item.edit_url,
		callback: ( items ) => {
			const url = items[ 0 ]?.edit_url;
			if ( url ) {
				window.open( url, '_blank', 'noreferrer' );
			}
		},
	},
	{
		id: 'delete-post',
		label: __( 'Trash', 'safe-publish' ),
		icon: trash,
		isDestructive: true,
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		supportsBulk: true,
		isEligible: ( item ) =>
			( 'up-to-date' === item.local_state || 'outdated' === item.local_state )
				&& null !== item.post_id,
		RenderModal: ( { items, closeModal } ) => (
			<DeletePostModal
				items={ items }
				ajaxurl={ context.ajaxurl }
				nonce={ context.nonce }
				closeModal={ closeModal }
				onRefresh={ onRefresh }
			/>
		),
	},
	{
		id: 'rollback',
		label: __( 'Roll back', 'safe-publish' ),
		icon: rotateLeft,
		isDestructive: true,
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		supportsBulk: true,
		isEligible: isRollbackEligible,
		RenderModal: ( { items, closeModal } ) =>
			1 === items.length ? (
				<RollbackPostModal
					items={ items }
					ajaxurl={ context.ajaxurl }
					nonce={ context.nonce }
					closeModal={ closeModal }
					onNotice={ context.onNotice }
					onRefresh={ onRefresh }
				/>
			) : (
				<BulkRollbackPostModal
					items={ items }
					ajaxurl={ context.ajaxurl }
					nonce={ context.nonce }
					closeModal={ closeModal }
					onRefresh={ onRefresh }
				/>
			),
	},
	];

	return actions;
};

/**
 * A banner shown for an action outcome: in-flight (info), succeeded (success),
 * failed (error), or completed without resolving the issue (warning).
 */
export interface ActionNotice {
	status: 'error' | 'warning' | 'info' | 'success';
	message: string;
}

/**
 * Auth + notice context for the Needs attention inbox actions.
 */
export interface NeedsAttentionActionsContext {
	ajaxurl: string;
	nonce: string;
	onNotice?: ( notice: ActionNotice | null ) => void;
	// Keys of degradations with a retry in flight, to drop concurrent submits.
	inFlight?: Set< string >;
}

/**
 * Maps a not-resolved retry outcome to its notice; a write failure surfaces as
 * an error, not a soft warning.
 *
 * @param {RetryAttentionIssueResponse['outcome']} outcome Reconciliation outcome.
 * @param {AttentionIssue}                         issue   Retried issue.
 *
 * @return {ActionNotice} Notice to surface.
 */
const retryOutcomeNotice = (
	outcome: RetryAttentionIssueResponse[ 'outcome' ],
	issue: AttentionIssue
): ActionNotice => {
	switch ( outcome ) {
		case 'write_failed':
			return {
				status: 'error',
				message: sprintf(
					/* translators: %s: affected content title */
					__( 'Failed to retry %s.', 'safe-publish' ),
					attentionIssueLabel( issue )
				),
			};
		case 'target_absent':
			return {
				status: 'warning',
				message: renderIssueMessage( issue ),
			};
		default:
			return {
				status: 'warning',
				message: sprintf(
					/* translators: %s: issue guidance sentence */
					__( 'Still needs attention. %s', 'safe-publish' ),
					renderIssueMessage( issue )
				),
			};
	}
};

/**
 * Inbox row descriptor sent to the ignore/restore endpoint; each carries its
 * kind so the server routes failures by scope and degradations by identity.
 */
type IgnoreDescriptor =
	| { kind: 'failure'; item_id: number; source_post_id: number | null }
	| {
			kind: 'degradation';
			affected_post_id: number;
			issue_type: string;
			target_ref: number;
			target_kind: string;
	  };

/**
 * Maps inbox rows to ignore/restore descriptors.
 *
 * @param {NeedsAttentionRow[]} items Selected inbox rows.
 * @return {IgnoreDescriptor[]} Descriptors for the endpoint.
 */
const buildIgnoreDescriptors = (
	items: NeedsAttentionRow[]
): IgnoreDescriptor[] =>
	items.map( ( item ) =>
		'failure' === item.kind
			? {
					kind: 'failure',
					item_id: item.item_id,
					source_post_id: item.source_post_id,
			  }
			: {
					kind: 'degradation',
					affected_post_id: item.affected_post_id,
					issue_type: item.issue_type,
					target_ref: item.target_ref,
					target_kind: item.target_kind,
			  }
	);

/**
 * Ignores or restores the selected rows, then refreshes. A soft, reversible
 * acknowledge — no confirmation modal.
 *
 * @param {NeedsAttentionRow[]}          items     Selected inbox rows.
 * @param {boolean}                      ignored   True to ignore, false to restore.
 * @param {NeedsAttentionActionsContext} context   Admin-ajax URL, nonce, notice sink.
 * @param {Function}                     onRefresh Callback to refresh the inbox.
 */
const dispatchSetIgnored = (
	items: NeedsAttentionRow[],
	ignored: boolean,
	context: NeedsAttentionActionsContext,
	onRefresh: ( () => void ) | undefined
): void => {
	if ( 0 === items.length ) {
		return;
	}

	const formData = new FormData();
	formData.append( 'action', 'safe_publish_set_needs_attention_ignored' );
	formData.append( 'nonce', context.nonce );
	formData.append( 'ignored', ignored ? '1' : '0' );
	formData.append(
		'items',
		JSON.stringify( buildIgnoreDescriptors( items ) )
	);

	void fetch( context.ajaxurl, { method: 'POST', body: formData } )
		.then(
			( response ) =>
				response.json() as Promise< ApiResponse< SetIgnoredResponse > >
		)
		.then( ( result ) => {
			if ( ! result.success ) {
				context.onNotice?.( {
					status: 'error',
					message: getErrorMessage(
						result,
						ignored
							? __( 'Failed to ignore.', 'safe-publish' )
							: __( 'Failed to restore.', 'safe-publish' )
					),
				} );
				return;
			}

			context.onNotice?.( {
				status: 'success',
				message: ignored
					? sprintf(
							/* translators: %d: number of items ignored */
							_n(
								'Ignored %d item.',
								'Ignored %d items.',
								items.length,
								'safe-publish'
							),
							items.length
					  )
					: sprintf(
							/* translators: %d: number of items restored */
							_n(
								'Restored %d item.',
								'Restored %d items.',
								items.length,
								'safe-publish'
							),
							items.length
					  ),
			} );
		} )
		.catch( () => {
			context.onNotice?.( {
				status: 'error',
				message: ignored
					? __( 'Network error while ignoring.', 'safe-publish' )
					: __( 'Network error while restoring.', 'safe-publish' ),
			} );
		} )
		.finally( () => {
			onRefresh?.();
		} );
};

/**
 * Runs the self-verifying single-issue retry, then refreshes. Surfaces every
 * outcome and drops a concurrent submit for the same issue.
 *
 * @param {InboxDegradation}             issue     Degradation to retry.
 * @param {NeedsAttentionActionsContext} context   Admin-ajax URL, nonce, notice sink.
 * @param {Function}                     onRefresh Callback to refresh the inbox.
 */
const dispatchSingleRetry = (
	issue: InboxDegradation,
	context: NeedsAttentionActionsContext,
	onRefresh: ( () => void ) | undefined
): void => {
	// The button stays clickable, so guard against concurrent submits for the
	// same issue.
	const key = attentionIssueId( issue );
	if ( context.inFlight?.has( key ) ) {
		return;
	}
	context.inFlight?.add( key );

	// Clear any prior outcome, then show "Retrying…" only if the request
	// outlasts the delay, so fast retries skip the flash.
	context.onNotice?.( null );
	const pendingTimer = setTimeout( () => {
		context.onNotice?.( {
			status: 'info',
			message: __( 'Retrying…', 'safe-publish' ),
		} );
	}, RETRY_PENDING_DELAY_MS );

	const formData = new FormData();
	formData.append( 'action', 'safe_publish_retry_attention_issue' );
	formData.append( 'nonce', context.nonce );
	formData.append( 'affected_post_id', String( issue.affected_post_id ) );
	formData.append( 'issue_type', issue.issue_type );
	formData.append( 'target_ref', String( issue.target_ref ) );
	formData.append( 'target_kind', issue.target_kind );

	void fetch( context.ajaxurl, { method: 'POST', body: formData } )
		.then(
			( response ) =>
				response.json() as Promise<
					ApiResponse< RetryAttentionIssueResponse >
				>
		)
		.then( ( result ) => {
			clearTimeout( pendingTimer );

			if ( ! result.success ) {
				context.onNotice?.( {
					status: 'error',
					message: getErrorMessage(
						result,
						__( 'Failed to retry.', 'safe-publish' )
					),
				} );
				return;
			}

			// Reconciliation ran but the issue persists; map the outcome to a
			// notice so the refetch doesn't read as a silent success.
			if ( ! result.data.resolved ) {
				context.onNotice?.(
					retryOutcomeNotice( result.data.outcome, issue )
				);
				return;
			}

			// Resolved: Confirm it, since the row drops from the refetched list
			// and would otherwise clear silently.
			context.onNotice?.( {
				status: 'success',
				message: sprintf(
					/* translators: %s: affected content title */
					__( 'Resolved: %s', 'safe-publish' ),
					attentionIssueLabel( issue )
				),
			} );
		} )
		.catch( () => {
			clearTimeout( pendingTimer );
			context.onNotice?.( {
				status: 'error',
				message: __( 'Network error while retrying.', 'safe-publish' ),
			} );
		} )
		.finally( () => {
			context.inFlight?.delete( key );
			onRefresh?.();
		} );
};

/**
 * Sums a bulk retry's per-outcome counts into one notice: An error if any write
 * failed, a warning if any target is still absent or unresolved, else success.
 *
 * @param {BulkRetryAttentionResponse} counts Per-outcome counts.
 * @return {ActionNotice} Aggregate notice.
 */
const bulkRetryNotice = (
	counts: BulkRetryAttentionResponse
): ActionNotice => {
	const message = sprintf(
		/* translators: 1: resolved count, 2: waiting-on-import count, 3: failed count */
		__(
			'%1$d resolved, %2$d waiting on import, %3$d failed.',
			'safe-publish'
		),
		counts.resolved,
		counts.target_absent,
		counts.write_failed + counts.unresolved
	);

	if ( counts.write_failed > 0 ) {
		return { status: 'error', message };
	}
	if ( counts.target_absent + counts.unresolved > 0 ) {
		return { status: 'warning', message };
	}
	return { status: 'success', message };
};

/**
 * Retries the selected degradations in one batched request, then refreshes and
 * surfaces the aggregate outcome.
 *
 * @param {InboxDegradation[]}           issues    Degradations to retry.
 * @param {NeedsAttentionActionsContext} context   Admin-ajax URL, nonce, notice sink.
 * @param {Function}                     onRefresh Callback to refresh the inbox.
 */
const dispatchBulkRetry = (
	issues: InboxDegradation[],
	context: NeedsAttentionActionsContext,
	onRefresh: ( () => void ) | undefined
): void => {
	context.onNotice?.( null );

	const formData = new FormData();
	formData.append( 'action', 'safe_publish_bulk_retry_attention_issues' );
	formData.append( 'nonce', context.nonce );
	formData.append(
		'items',
		JSON.stringify(
			issues.map( ( issue ) => ( {
				affected_post_id: issue.affected_post_id,
				issue_type: issue.issue_type,
				target_ref: issue.target_ref,
				target_kind: issue.target_kind,
			} ) )
		)
	);

	void fetch( context.ajaxurl, { method: 'POST', body: formData } )
		.then(
			( response ) =>
				response.json() as Promise<
					ApiResponse< BulkRetryAttentionResponse >
				>
		)
		.then( ( result ) => {
			context.onNotice?.(
				result.success
					? bulkRetryNotice( result.data )
					: {
							status: 'error',
							message: getErrorMessage(
								result,
								__( 'Failed to retry.', 'safe-publish' )
							),
					  }
			);
		} )
		.catch( () => {
			context.onNotice?.( {
				status: 'error',
				message: __( 'Network error while retrying.', 'safe-publish' ),
			} );
		} )
		.finally( () => {
			onRefresh?.();
		} );
};

/**
 * Creates the Needs attention inbox actions for the active view. Open: Remove
 * (failures), self-verifying Retry (retryable degradations), and Ignore (a
 * reversible acknowledge, both kinds). Ignored: Un-ignore (both kinds) plus
 * Remove for failures. Retry re-runs the real reconciliation then refreshes,
 * surfacing every outcome (an aggregate notice for a bulk run); concurrent
 * submits for one issue are dropped.
 *
 * @param {Function}                     onRefresh Callback to refresh the inbox.
 * @param {NeedsAttentionActionsContext} context   Admin-ajax URL, nonce, notice sink.
 * @param {NeedsAttentionView}           view      Active view: open or ignored.
 *
 * @return {Action<NeedsAttentionRow>[]} Array of DataViews actions.
 */
export const createNeedsAttentionActions = (
	onRefresh: ( () => void ) | undefined,
	context: NeedsAttentionActionsContext,
	view: NeedsAttentionView
): Action< NeedsAttentionRow >[] => {
	const removeFailure: Action< NeedsAttentionRow > = {
		id: 'remove-failure',
		label: __( 'Remove', 'safe-publish' ),
		icon: trash,
		isDestructive: true,
		isPrimary: true,
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		supportsBulk: true,
		isEligible: ( item ) => 'failure' === item.kind,
		RenderModal: ( { items, closeModal } ) => (
			<DeleteFailedImportsModal
				items={ items
					.filter(
						( item ): item is InboxFailure =>
							'failure' === item.kind
					)
					.map( ( item ) => ( {
						itemId: item.item_id,
						sourcePostId: item.source_post_id,
						title: item.title,
					} ) ) }
				ajaxurl={ context.ajaxurl }
				nonce={ context.nonce }
				closeModal={ closeModal }
				onRefresh={ onRefresh }
			/>
		),
	};

	const retryDegradation: Action< NeedsAttentionRow > = {
		id: 'retry-degradation',
		label: __( 'Retry', 'safe-publish' ),
		icon: update,
		isPrimary: true,
		supportsBulk: true,
		isEligible: ( item ) => 'degradation' === item.kind && item.retryable,
		callback: ( items ) => {
			const degradations = items.filter(
				( item ): item is InboxDegradation =>
					'degradation' === item.kind && item.retryable
			);
			if ( 0 === degradations.length ) {
				return;
			}
			if ( 1 === degradations.length ) {
				dispatchSingleRetry( degradations[ 0 ], context, onRefresh );
				return;
			}
			dispatchBulkRetry( degradations, context, onRefresh );
		},
	};

	const ignore: Action< NeedsAttentionRow > = {
		id: 'ignore-needs-attention',
		label: __( 'Ignore', 'safe-publish' ),
		icon: unseen,
		isPrimary: true,
		supportsBulk: true,
		isEligible: () => true,
		callback: ( items ) =>
			dispatchSetIgnored( items, true, context, onRefresh ),
	};

	const unignore: Action< NeedsAttentionRow > = {
		id: 'unignore-needs-attention',
		label: __( 'Un-ignore', 'safe-publish' ),
		icon: seen,
		isPrimary: true,
		supportsBulk: true,
		isEligible: () => true,
		callback: ( items ) =>
			dispatchSetIgnored( items, false, context, onRefresh ),
	};

	if ( 'ignored' === view ) {
		return [ unignore, removeFailure ];
	}

	return [ removeFailure, retryDegradation, ignore ];
};
