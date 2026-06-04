/**
 * Action definitions for the DataViews component.
 *
 * Defines actions for Source Posts (Import single + bulk, View in Imports for
 * already-imported items) and for the Imports → Posts tab (Edit, Update,
 * Compare, Delete, Rollback).
 *
 * @file This file defines DataViews actions for the Safe Publish plugin.
 */
import { drafts, download, pencil, rotateLeft, seen, trash } from '@wordpress/icons';

import BulkImportFlow from './components/BulkImportFlow';
import BulkRollbackPostModal from './components/BulkRollbackPostModal';
import DeleteFailedImportsModal from './components/DeleteFailedImportsModal';
import DeletePostModal from './components/DeletePostModal';
import ImportModal from './components/ImportModal';
import PostDiffModal from './components/PostDiffModal';
import RollbackPostModal from './components/RollbackPostModal';
import {
	FailedImport,
	ImportedPost,
	ImportSyncStatus,
	Post,
} from './types';
import { Action } from '@wordpress/dataviews/build-types';
import { __ } from '@wordpress/i18n';

/**
 * Auth context shared by the Source Posts action set. Threaded as a prop so
 * the modals and bulk-import helper don't reach into the admin-data global.
 */
export interface SourceActionsContext {
	ajaxurl: string;
	nonce: string;
}

/**
 * Auth context shared by the Imports → Posts tab action set. Adds restNonce
 * for the diff-preview REST endpoint used by PostDiffModal.
 */
export interface ImportedActionsContext {
	ajaxurl: string;
	nonce: string;
	restNonce: string;
}

/**
 * Auth context for the Imports → Failures tab action set.
 */
export interface FailedImportsActionsContext {
	ajaxurl: string;
	nonce: string;
}

/**
 * Creates DataViews actions for source posts.
 *
 * Returns the Import action (single + bulk) for non-imported items and the
 * View in Imports action for already-imported items, which deep-links to the
 * Imports → Posts tab narrowed to the matching row via focus_source.
 *
 * @param {Function}             onRefresh    Callback to refresh the posts list.
 * @param {boolean}              isAuthorized Whether the source site authorizes imports.
 * @param {SourceActionsContext} context      Admin-ajax URL + nonce.
 *
 * @return {Action<Post>[]} Array of DataViews actions.
 */
export const createActions = (
	onRefresh: ( () => void ) | undefined,
	isAuthorized: boolean,
	context: SourceActionsContext
): Action< Post >[] => [
	/**
	 * Import action.
	 *
	 * Single item: confirmation modal. Multiple items: batch import with
	 * progress tracking. Excludes already-imported posts — Update/Diff/Delete
	 * live on the Imports → Posts tab.
	 */
	{
		id: 'import',
		label: __( 'Import', 'safe-publish' ),
		icon: download,
		isPrimary: true,
		isEligible: ( item: Post ) => isAuthorized && ! item.is_imported,
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		supportsBulk: true,
		RenderModal: ( { items, closeModal } ) => {
			// Single item: simpler confirmation flow.
			if ( 1 === items.length ) {
				const item = items[ 0 ];
				return (
					<ImportModal
						sourcePostId={ item.id }
						title={ item.title }
						sourceLink={ item.link }
						postType={ item.post_type }
						isUpdate={ false }
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
						id: item.id,
						post_type: item.post_type,
						title: item.title,
					} ) ) }
					context={ context }
					onClose={ () => closeModal?.() }
					onRefresh={ onRefresh }
				/>
			);
		},
	},
	/**
	 * View in Imports action.
	 *
	 * Deep-links an already-imported source row to the Imports → Posts tab
	 * narrowed to the matching imported post via
	 * `?focus_source=<source_post_id>`, so Update / Diff / Delete / Rollback
	 * are one click away.
	 */
	{
		id: 'view-in-imports',
		label: __( 'View in Imports', 'safe-publish' ),
		icon: seen,
		isPrimary: true,
		isEligible: ( item: Post ) => Boolean( item.is_imported ),
		callback: ( items: Post[] ) => {
			const baseUrl = window.safePublishAdminData?.importsUrl;
			const item = items[ 0 ];

			if ( ! baseUrl || ! item ) {
				return;
			}

			const url = new URL( baseUrl );
			url.searchParams.set( 'focus_source', String( item.id ) );
			window.location.href = url.toString();
		},
	},
];

/**
 * Creates DataViews actions for the Imports → Posts tab.
 *
 * Edit opens the local editor. Update / Delete / Rollback all support
 * single and bulk paths; Compare is single-only because a diff is
 * intrinsically per-row. Each modal-backed action takes the row's
 * explicit identity (source_post_id for Update/Compare, local id for
 * Delete, item_id for Rollback) plus the admin-ajax/REST auth tokens
 * from `context`.
 *
 * @param {Function}               onRefresh    Callback to refresh the listing after a change.
 * @param {ImportedActionsContext} context      Admin-ajax URL + nonce + REST nonce.
 * @param {Object}                 syncStatuses Per-row sync entries keyed by source post id.
 *
 * @return {Action<ImportedPost>[]} Array of DataViews actions.
 */
export const createImportedActions = (
	onRefresh: ( () => void ) | undefined,
	context: ImportedActionsContext,
	syncStatuses: Record< number, { status: ImportSyncStatus } >
): Action< ImportedPost >[] => [
	{
		id: 'edit-post',
		label: __( 'Edit', 'safe-publish' ),
		icon: pencil,
		isPrimary: true,
		isEligible: ( item: ImportedPost ) => '' !== item.edit_url,
		callback: ( items: ImportedPost[] ) => {
			const url = items[ 0 ]?.edit_url;
			if ( url ) {
				window.open( url, '_blank', 'noreferrer' );
			}
		},
	},
	{
		id: 'update-post',
		label: __( 'Update', 'safe-publish' ),
		icon: download,
		isPrimary: true,
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		supportsBulk: true,
		// Only hide Update when we know the row is up-to-date; loading and
		// unreachable states still show it so the user can act on partial info.
		isEligible: ( item: ImportedPost ) =>
			'up-to-date' !== syncStatuses[ item.source_post_id ]?.status,
		RenderModal: ( { items, closeModal } ) => {
			if ( 1 === items.length ) {
				const item = items[ 0 ];
				return (
					<ImportModal
						sourcePostId={ item.source_post_id }
						title={ item.title }
						sourceLink={ item.source_link }
						postType={ item.post_type }
						isUpdate={ true }
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
						id: item.source_post_id,
						post_type: item.post_type,
						title: item.title,
					} ) ) }
					context={ { ajaxurl: context.ajaxurl, nonce: context.nonce } }
					onClose={ () => closeModal?.() }
					onRefresh={ onRefresh }
					labels={ {
						/* translators: %d is the number of selected posts */
						confirmQuestion: __(
							'Update %d selected posts with the latest content?',
							'safe-publish'
						),
						confirmDescription: __(
							'This re-imports each post from the source — content, images, links, and formatting.',
							'safe-publish'
						),
						processingHeading: __(
							'Updating posts as a batch…',
							'safe-publish'
						),
						/* translators: %d is the number of selected posts */
						processingFootnote: __(
							'All %d posts will be updated in a single session',
							'safe-publish'
						),
						completedHeading: __( 'Update completed!', 'safe-publish' ),
						failedHeading: __( 'Update failed', 'safe-publish' ),
						partialHeading: __(
							'Update completed with errors',
							'safe-publish'
						),
						/* translators: 1: successful count, 2: total count */
						totalSummary: __( 'Updated: %1$d of %2$d posts', 'safe-publish' ),
						/* translators: %d is the number of selected posts */
						primaryButton: __( 'Update %d Posts', 'safe-publish' ),
						loadingButton: __( 'Updating…', 'safe-publish' ),
						primaryActionId: 'update',
					} }
				/>
			);
		},
	},
	{
		id: 'post-diff',
		label: __( 'Compare', 'safe-publish' ),
		icon: drafts,
		hideModalHeader: false,
		supportsBulk: false,
		modalSize: 'fill',
		RenderModal: ( { items, closeModal } ) => (
			<PostDiffModal
				items={ items }
				restNonce={ context.restNonce }
				ajaxurl={ context.ajaxurl }
				nonce={ context.nonce }
				closeModal={ closeModal }
				onRefresh={ onRefresh }
			/>
		),
	},
	{
		id: 'delete-post',
		label: __( 'Delete', 'safe-publish' ),
		icon: trash,
		isDestructive: true,
		isPrimary: true,
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		supportsBulk: true,
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
		isEligible: ( item: ImportedPost ) =>
			null !== item.item_id &&
			! item.rolled_back &&
			( 'success' === item.rollback_status ||
				'updated' === item.rollback_status ),
		RenderModal: ( { items, closeModal } ) =>
			1 === items.length ? (
				<RollbackPostModal
					items={ items }
					ajaxurl={ context.ajaxurl }
					nonce={ context.nonce }
					closeModal={ closeModal }
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

/**
 * Creates DataViews actions for the Imports → Failures tab.
 *
 * Remove is the only action — a confirmation modal that hard-deletes the
 * selected failed-import rows from the items table. Supports single and bulk
 * via the same modal.
 *
 * @param {Function}                    onRefresh Callback to refresh the listing after a change.
 * @param {FailedImportsActionsContext} context   Admin-ajax URL + nonce.
 *
 * @return {Action<FailedImport>[]} Array of DataViews actions.
 */
export const createFailedImportsActions = (
	onRefresh: ( () => void ) | undefined,
	context: FailedImportsActionsContext
): Action< FailedImport >[] => [
	{
		id: 'remove-failed-import',
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
