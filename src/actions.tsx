/**
 * Action definitions for the DataViews component.
 *
 * Defines actions for Source Posts (Import single + bulk, View in Imports for
 * already-imported items) and for the Imports → Posts tab (Edit, Update, Diff,
 * Delete, Rollback).
 *
 * @file This file defines DataViews actions for the Safe Publish plugin.
 */
import { drafts, download, pencil, rotateLeft, seen, trash } from '@wordpress/icons';

import BulkRollbackPostModal from './components/BulkRollbackPostModal';
import DeleteFailedImportsModal from './components/DeleteFailedImportsModal';
import DeletePostModal from './components/DeletePostModal';
import ImportModal from './components/ImportModal';
import PostDiffModal from './components/PostDiffModal';
import RollbackPostModal from './components/RollbackPostModal';
import {
	ApiResponse,
	BulkImportResponse,
	BulkImportResult,
	FailedImport,
	ImportedPost,
	ImportSyncStatus,
	Post,
} from './types';
import { getErrorMessage, renderWarningShortLabel } from './utils';
import {
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
	ProgressBar,
} from '@wordpress/components';
import { Action } from '@wordpress/dataviews/build-types';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Maximum number of warning entries shown in the bulk results titles list
 * before collapsing the remainder into a "…and N more" line.
 */
const MAX_VISIBLE_WARNING_TITLES = 10;

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
 * for the diff/update REST endpoints used by PostDiffModal.
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
 * Returns true when the result is a successful import that carries warnings.
 *
 * @param {BulkImportResult} result Per-post result entry.
 *
 * @return {boolean} True when warnings were attached to a successful import.
 */
const hasWarnings = ( result: BulkImportResult ): boolean =>
	Boolean( result.success && result.warnings && result.warnings.length > 0 );

/**
 * Imports multiple posts in bulk with progress tracking.
 *
 * Sends all selected posts to the bulk import endpoint and tracks the progress
 * of the import operation.
 *
 * @param {Post[]}               posts        Posts to import.
 * @param {SourceActionsContext} context      Admin-ajax URL + nonce.
 * @param {Function}             [onProgress] Progress callback (current, total) => void.
 *
 * @return {Promise<BulkImportResponse>} Bulk import results.
 */
const bulkImportPosts = async (
	posts: Post[],
	context: SourceActionsContext,
	onProgress?: ( current: number, total: number ) => void
): Promise< BulkImportResponse > => {
	// Use the proper bulk import endpoint instead of individual calls.
	const formData = new FormData();
	formData.append( 'action', 'safe_publish_bulk_import' );
	formData.append( 'nonce', context.nonce );
	formData.append( 'posts_data', JSON.stringify( posts ) );

	// Show initial progress.
	if ( onProgress ) {
		onProgress( 0, posts.length );
	}

	try {
		const response = await fetch( context.ajaxurl, {
			method: 'POST',
			body: formData,
			headers: {
				'Accept': 'application/json; charset=utf-8',
			},
		} );

		const result = await response.json() as ApiResponse<BulkImportResponse>;

		if ( ! result.success ) {
			throw new Error( getErrorMessage( result, __( 'Bulk import failed', 'safe-publish' ) ) );
		}

		const bulkResult = result.data;

		// Show completion progress.
		if ( onProgress ) {
			onProgress( posts.length, posts.length );
		}

		// Transform the backend response to match our expected format.
		return {
			total: bulkResult.total || posts.length,
			successful: bulkResult.successful || 0,
			failed: bulkResult.failed || 0,
			results: bulkResult.results || [],
		};

	} catch ( error ) {
		// Show completion even on error.
		if ( onProgress ) {
			onProgress( posts.length, posts.length );
		}
		throw error;
	}
};

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
			const [ isLoading, setIsLoading ] = useState( false );
			const [ error, setError ] = useState< string | null >( null );
			const [ progress, setProgress ] = useState( 0 );
			const [ importResults, setImportResults ] = useState< BulkImportResponse | null >( null );

			// For a single item, delegate to the simpler confirmation modal.
			if ( items.length === 1 ) {
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

			/**
			 * Handles the bulk import operation.
			 *
			 * Sends all selected posts to the bulk import endpoint with
			 * progress simulation for better UX.
			 */
			const handleBulkImport = async () => {
				setIsLoading( true );
				setError( null );
				setProgress( 0 );
				setImportResults( null );

				try {
					// Simulate progress for better UX since bulk import happens in one request.
					const progressSteps = 10;
					const progressInterval = setInterval(() => {
						setProgress( prevProgress => {
							if ( prevProgress >= 90 ) {
								clearInterval( progressInterval );
								return 90; // Stop at 90% until we get real results.
							}
							return prevProgress + ( 90 / progressSteps );
						});
					}, 200 );

					const result = await bulkImportPosts(
						items,
						context,
						( current, total ) => {
							// This won't be called much since it's a single request,
							// but we'll use it for final completion.
							if ( current === total ) {
								clearInterval( progressInterval );
								setProgress( 100 );
							}
						}
					);

					clearInterval( progressInterval );
					setImportResults( result );
					setProgress( 100 );
				} catch ( err ) {
					setError( err instanceof Error ? err.message : __( 'Unknown error occurred', 'safe-publish' ) );
				} finally {
					setIsLoading( false );
				}
			};

			/**
			 * Handles closing the modal.
			 *
			 * Refreshes the post list if imports were successful.
			 */
			const handleCloseModal = () => {
				if ( importResults && importResults.successful > 0 ) {
					onRefresh?.();
				}
				closeModal?.();
			};

			const allFailed =
				importResults !== null &&
				importResults.successful === 0 &&
				importResults.failed > 0;
			const partialFailure =
				importResults !== null &&
				importResults.successful > 0 &&
				importResults.failed > 0;

			let summaryHeading = __( 'Import completed!', 'safe-publish' );
			let summaryColor = '#008a20';
			if ( allFailed ) {
				summaryHeading = __( 'Import failed', 'safe-publish' );
				summaryColor = '#d63638';
			} else if ( partialFailure ) {
				summaryHeading = __( 'Import completed with errors', 'safe-publish' );
				summaryColor = '#996800';
			}

			return (
				<VStack spacing="5" style={ { minWidth: '400px' } } className="safe-publish-bulk-import-modal">
					{ ! importResults && (
						<>
							<Text>
								{ /* translators: %d is the number of posts */
								__( 'Import %d selected posts as drafts?', 'safe-publish' ).replace( '%d', items.length.toString() ) }
							</Text>
							<Text style={ { fontSize: '0.9em', color: '#666' } }>
								{ __( 'This will import all selected posts including their content, images, links, and formatting.', 'safe-publish' ) }
							</Text>
						</>
					) }

					{ isLoading && (
						<VStack spacing="3" className="safe-publish-bulk-import-progress">
							<Text>{ __( 'Importing posts as a batch…', 'safe-publish' ) }</Text>
							<ProgressBar value={ progress } />
							<Text style={ { fontSize: '0.8em', color: '#666' } }>
								{ progress === 100
									? __( 'Batch import completed!', 'safe-publish' )
									: /* translators: %d is the percentage complete */
									__( 'Processing bulk import… %d%% complete', 'safe-publish' )
										.replace( '%d', Math.round(progress).toString() )
								}
							</Text>
							<Text style={ { fontSize: '0.75em', color: '#999' } }>
								{ /* translators: %d is the number of posts */
								__( 'All %d posts will be imported in a single session', 'safe-publish' )
									.replace( '%d', items.length.toString() ) }
							</Text>
						</VStack>
					) }

					{ importResults && (() => {
						const withWarnings = importResults.results.filter( hasWarnings );
						const visibleWarnings = withWarnings.slice( 0, MAX_VISIBLE_WARNING_TITLES );
						const hiddenWarnings = withWarnings.length - visibleWarnings.length;

						return (
							<VStack spacing="3">
								<Text style={ { color: summaryColor, fontWeight: 'bold' } }>
									{ summaryHeading }
								</Text>
								<Text>
									{ /* translators: 1: successful count, 2: total count */
									__( 'Imported: %1$d of %2$d posts', 'safe-publish' )
										.replace( '%1$d', importResults.successful.toString() )
										.replace( '%2$d', importResults.total.toString() ) }
								</Text>
								{ importResults.successful > 0 && (
									<Text style={ { fontSize: '0.9em', color: '#666' } }>
										{ (() => {
											const created = importResults.results.filter( result => result.success && !result.existing ).length;
											const updated = importResults.results.filter( result => result.success && result.existing ).length;
											const parts = [];
											if ( created > 0 ) {
												/* translators: %d is the number of posts created */
												parts.push( __( '%d created', 'safe-publish' ).replace( '%d', created.toString() ) );
											}
											if ( updated > 0 ) {
												/* translators: %d is the number of posts updated */
												parts.push( __( '%d updated with latest content', 'safe-publish' )
													.replace( '%d', updated.toString() ) );
											}
											return parts.join( ', ' );
										} )() }
									</Text>
								) }
								{ withWarnings.length > 0 && (
									<Text className="safe-publish-warning-count">
										{ sprintf(
											/* translators: %d is the number of imports with warnings */
											__( 'Imported with warnings: %d', 'safe-publish' ),
											withWarnings.length
										) }
									</Text>
								) }
								{ withWarnings.length > 0 && (
									<div className="safe-publish-import-warnings-list">
										<Text className="safe-publish-warning-list-heading">
											{ sprintf(
												/* translators: %d is the number of imports with warnings */
												__( 'Imported with warnings (%d):', 'safe-publish' ),
												withWarnings.length
											) }
										</Text>
										<ul>
											{ visibleWarnings.map( ( result, index ) => {
												const reasons = ( result.warnings ?? [] )
													.map( renderWarningShortLabel )
													.join( ', ' );

												return (
													<li key={ index }>
														{ result.edit_url ? (
															<a
																href={ result.edit_url }
																target="_blank"
																rel="noreferrer"
															>
																{ result.title }
															</a>
														) : (
															<span>{ result.title }</span>
														) }
														{ ' — ' }
														{ reasons }
													</li>
												);
											} ) }
											{ hiddenWarnings > 0 && (
												<li>
													{ sprintf(
														/* translators: %d is the number of additional posts with warnings */
														__( '…and %d more', 'safe-publish' ),
														hiddenWarnings
													) }
												</li>
											) }
										</ul>
									</div>
								) }
								{ importResults.failed > 0 && (
									<Text style={ { color: '#d63638' } }>
										{ /* translators: %d is the number of failed imports */
										__( 'Failed imports: %d', 'safe-publish' ).replace( '%d', importResults.failed.toString() ) }
									</Text>
								) }

								{ importResults.results.length > 0 && (
									<div className="safe-publish-import-results">
									{ importResults.results.map( ( result, index ) => {
										const warned = hasWarnings( result );
										let titleClass: 'success' | 'warning' | 'error';
										if ( ! result.success ) {
											titleClass = 'error';
										} else if ( warned ) {
											titleClass = 'warning';
										} else {
											titleClass = 'success';
										}

										let status;
										if ( ! result.success ) {
											status = __( 'Failed', 'safe-publish' );
										} else if ( result.existing ) {
											status = warned
												? __( 'Updated (warning)', 'safe-publish' )
												: __( 'Updated', 'safe-publish' );
										} else {
											status = warned
												? __( 'Created (warning)', 'safe-publish' )
												: __( 'Created', 'safe-publish' );
										}

										return (
											<div key={ index } className="safe-publish-import-result-item">
												<div className="safe-publish-result-text">
													<span className={ `safe-publish-result-title ${ titleClass }` }>
														{ result.title }
													</span>
													{ ! result.success && result.error && (
														<span className="safe-publish-result-error">
															{ result.error }
														</span>
													) }
												</div>
												<span className="safe-publish-result-status">
													{ status }
												</span>
											</div>
										);
									} ) }
									</div>
								) }
							</VStack>
						);
					} )() }

					{ error && (
						<Text style={ { color: '#d63638' } }>{ error }</Text>
					) }

					<HStack justify="right">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ handleCloseModal }
							disabled={ isLoading }
						>
							{ importResults ? __( 'Close', 'safe-publish' ) : __( 'Cancel', 'safe-publish' ) }
						</Button>
						{ ! importResults && (
							<Button
								__next40pxDefaultSize
								variant="primary"
								onClick={ () => void handleBulkImport() }
								disabled={ isLoading }
								data-action-id="import"
							>
								{ isLoading ? (
									<>
										<Spinner />
										{ __( 'Importing…', 'safe-publish' ) }
									</>
								) : (
									/* translators: %d is the number of posts */
									__( 'Import %d Posts', 'safe-publish' ).replace( '%d', items.length.toString() )
								) }
							</Button>
						) }
					</HStack>
				</VStack>
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
 * Edit opens the local editor. Rollback supports single and bulk paths;
 * Update, Diff, and Delete are single-only modals. Each modal-backed
 * action takes the row's explicit identity (source_post_id for
 * Update/Diff, local id for Delete, item_id for Rollback) plus the
 * admin-ajax/REST auth tokens from `context`.
 *
 * @param {Function}                        onRefresh    Callback to refresh the listing after a change.
 * @param {ImportedActionsContext}          context      Admin-ajax URL + nonce + REST nonce.
 * @param {Record<number,ImportSyncStatus>} syncStatuses Per-row sync verdict keyed by source post id.
 *
 * @return {Action<ImportedPost>[]} Array of DataViews actions.
 */
export const createImportedActions = (
	onRefresh: ( () => void ) | undefined,
	context: ImportedActionsContext,
	syncStatuses: Record< number, ImportSyncStatus >
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
		// Only hide Update when we know the row is up-to-date; loading and
		// unreachable states still show it so the user can act on partial info.
		isEligible: ( item: ImportedPost ) =>
			'up-to-date' !== syncStatuses[ item.source_post_id ],
		RenderModal: ( { items, closeModal } ) => {
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
		},
	},
	{
		id: 'post-diff',
		label: __( 'Post Diff', 'safe-publish' ),
		icon: drafts,
		hideModalHeader: false,
		supportsBulk: false,
		modalSize: 'fill',
		RenderModal: ( { items, closeModal } ) => (
			<PostDiffModal
				items={ items }
				restNonce={ context.restNonce }
				closeModal={ closeModal }
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
