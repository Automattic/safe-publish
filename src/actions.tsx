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
	trash,
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
	ChipState,
	ImportSyncStatus,
	OrphanFailure,
	RetryAttentionIssueResponse,
	UnifiedPostRow,
} from './types';
import {
	attentionIssueId,
	attentionIssueLabel,
	getErrorMessage,
	renderIssueMessage,
} from './utils';
import { Action } from '@wordpress/dataviews/build-types';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Auth context for the unified Posts action set.
 */
export interface PostsActionsContext {
	ajaxurl: string;
	nonce: string;
	onNotice?: ( notice: ActionNotice | null ) => void;
}

/**
 * Auth context for the orphan-failures drawer's Remove action.
 */
export interface OrphanFailuresActionsContext {
	ajaxurl: string;
	nonce: string;
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
 * endpoint; per-state eligibility: 'available'/'failed' → Import;
 * 'up-to-date'/'outdated' → Compare, Import, Edit, Trash, Rollback
 * (Compare/Import hide on up-to-date); 'failed' on the Failed chip also
 * exposes Dismiss. Mixed bulk selections rely on per-item isEligible.
 *
 * @param {Function}            onRefresh       Listing refresh callback.
 * @param {boolean}             isAuthorized    Whether the source authorizes imports.
 * @param {PostsActionsContext} context         Admin-ajax URL, nonce, notice sink.
 * @param {Object}              syncStatuses    Per-row sync entries keyed by source post id.
 * @param {ChipState}           chipState       Current chip; gates Failed-only actions.
 * @param {number}              [selectedCount] Selected rows; sizes the bulk-import skipped count.
 *
 * @return {Action<UnifiedPostRow>[]} Array of DataViews actions.
 */
export const createPostsActions = (
	onRefresh: ( () => void ) | undefined,
	isAuthorized: boolean,
	context: PostsActionsContext,
	syncStatuses: Record< number, { status: ImportSyncStatus } >,
	chipState: ChipState,
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
					|| 'failed' === item.local_state
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

	// Failed-chip only so Dismiss reads as failure cleanup, not deletion.
	if ( 'failed' === chipState ) {
		actions.push( {
			id: 'dismiss-failure',
			label: __( 'Dismiss', 'safe-publish' ),
			icon: trash,
			isDestructive: true,
			isPrimary: true,
			// The listing dedupes failures by source_post_id, so dismiss
			// has to clear by source — otherwise older failure siblings
			// re-surface on refresh.
			isEligible: ( item ) =>
				'failed' === item.local_state && null !== item.source_post_id,
			hideModalHeader: true,
			modalFocusOnMount: 'firstContentElement',
			supportsBulk: true,
			RenderModal: ( { items, closeModal } ) => (
				<DeleteFailedImportsModal
					items={ items.map( ( item ) => ( {
						id: item.source_post_id ?? 0,
						title: item.title,
					} ) ) }
					ajaxurl={ context.ajaxurl }
					nonce={ context.nonce }
					scope="sources"
					closeModal={ closeModal }
					onRefresh={ onRefresh }
				/>
			),
		} );
	}

	return actions;
};

/**
 * Creates the drawer's Remove action for orphan failures.
 *
 * @param {Function}                     onRefresh Callback to refresh the drawer.
 * @param {OrphanFailuresActionsContext} context   Admin-ajax URL + nonce.
 *
 * @return {Action<OrphanFailure>[]} Array of DataViews actions.
 */
export const createOrphanFailuresActions = (
	onRefresh: ( () => void ) | undefined,
	context: OrphanFailuresActionsContext
): Action< OrphanFailure >[] => [
	{
		id: 'remove-orphan-failure',
		label: __( 'Remove', 'safe-publish' ),
		icon: trash,
		isDestructive: true,
		isPrimary: true,
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		supportsBulk: true,
		RenderModal: ( { items, closeModal } ) => (
			<DeleteFailedImportsModal
				items={ items }
				ajaxurl={ context.ajaxurl }
				nonce={ context.nonce }
				closeModal={ closeModal }
				onRefresh={ onRefresh }
			/>
		),
	},
];

/**
 * A banner shown for an action outcome: in-flight (info), succeeded (success),
 * failed (error), or completed without resolving the issue (warning).
 */
export interface ActionNotice {
	status: 'error' | 'warning' | 'info' | 'success';
	message: string;
}

/**
 * Auth + notice context for the Needs attention drawer's Retry action.
 */
export interface AttentionIssueActionsContext {
	ajaxurl: string;
	nonce: string;
	onNotice?: ( notice: ActionNotice | null ) => void;
	// Keys of issues with a retry in flight, to drop concurrent submits.
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
 * Creates the drawer's Retry action for attention issues.
 *
 * Eligible only for issues whose reconciliation is callable today; it re-runs
 * the real reconciliation, which is self-verifying, then refreshes the listing
 * so the row clears or stays based on the actual result. Every outcome —
 * resolved, still open, or failed — surfaces a notice, so a run never reads as
 * a silent success. Concurrent submits for the same issue are ignored while one
 * is in flight.
 *
 * @param {Function}                     onRefresh Callback to refresh the drawer.
 * @param {AttentionIssueActionsContext} context   Admin-ajax URL, nonce, notice sink.
 *
 * @return {Action<AttentionIssue>[]} Array of DataViews actions.
 */
export const createAttentionIssueActions = (
	onRefresh: ( () => void ) | undefined,
	context: AttentionIssueActionsContext
): Action< AttentionIssue >[] => [
	{
		id: 'retry-attention-issue',
		label: __( 'Retry', 'safe-publish' ),
		icon: update,
		isPrimary: true,
		isEligible: ( item ) => item.retryable,
		callback: ( items ) => {
			const issue = items[ 0 ];
			if ( ! issue ) {
				return;
			}

			// The button stays clickable, so guard against concurrent submits
			// for the same issue.
			const key = attentionIssueId( issue );
			if ( context.inFlight?.has( key ) ) {
				return;
			}
			context.inFlight?.add( key );

			// Clear any prior outcome, then show "Retrying…" only if the
			// request outlasts the delay, so fast retries skip the flash.
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
			formData.append(
				'affected_post_id',
				String( issue.affected_post_id )
			);
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

					// Reconciliation ran but the issue persists; map the outcome
					// to a notice so the refetch doesn't read as a silent success.
					if ( ! result.data.resolved ) {
						context.onNotice?.(
							retryOutcomeNotice( result.data.outcome, issue )
						);
						return;
					}

					// Resolved: confirm it, since the row drops from the
					// refetched list and would otherwise clear silently.
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
						message: __(
							'Network error while retrying.',
							'safe-publish'
						),
					} );
				} )
				.finally( () => {
					context.inFlight?.delete( key );
					onRefresh?.();
				} );
		},
	},
];
