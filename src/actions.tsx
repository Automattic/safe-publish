/**
 * Action definitions for the DataViews component.
 *
 * Defines the available actions for external posts including creating drafts,
 * bulk importing, updating posts, and viewing post diffs.
 *
 * @file This file defines DataViews actions for the Safe Publish plugin.
 */
import { drafts, download, edit, trash } from '@wordpress/icons';

import DeletePostModal from './components/DeletePostModal';
import ImportModal from './components/ImportModal';
import PostDiffModal from './components/PostDiffModal';
import {
	ApiResponse,
	BulkImportResponse,
	Post,
} from './types';
import { getErrorMessage } from './utils';
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
import { __ } from '@wordpress/i18n';

/**
 * Imports multiple posts in bulk with progress tracking.
 *
 * Sends all selected posts to the bulk import endpoint and tracks the progress
 * of the import operation.
 *
 * @param {Post[]}   posts        Posts to import.
 * @param {Function} [onProgress] Progress callback (current, total) => void.
 *
 * @return {Promise<BulkImportResponse>} Bulk import results.
 */
const bulkImportPosts = async (
	posts: Post[],
	onProgress?: ( current: number, total: number ) => void
): Promise< BulkImportResponse > => {
	// Use the proper bulk import endpoint instead of individual calls.
	const formData = new FormData();
	formData.append( 'action', 'safe_publish_bulk_import' );
	formData.append( 'nonce', window.safePublishAdminData.nonce );
	formData.append( 'posts_data', JSON.stringify( posts ) );

	// Show initial progress.
	if ( onProgress ) {
		onProgress( 0, posts.length );
	}

	try {
		const response = await fetch( window.safePublishAdminData.ajaxurl, {
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
 * Creates DataViews actions for external posts.
 *
 * Defines the available actions that can be performed on posts in the DataViews
 * component, including creating drafts, bulk importing, updating, and viewing
 * diffs.
 *
 * @param {Function} [onRefresh]    Callback to refresh the posts list.
 * @param {boolean}  [isAuthorized] Whether the source site authorizes imports.
 *
 * @return {Action<Post>[]} Array of DataViews actions.
 */
export const createActions = (
	onRefresh?: () => void,
	isAuthorized: boolean = true
): Action< Post >[] => [
	/**
	 * Combined import and update action.
	 *
	 * For a single item: shows a simple confirmation modal that imports or
	 * updates the post depending on its current state. For multiple selected
	 * items: runs a batch operation with progress tracking.
	 */
	{
		id: 'bulk-import',
		label: ( items: Post[] ) => {
			if ( items.length === 1 ) {
				return items[ 0 ].is_imported
					? __( 'Update', 'safe-publish' )
					: __( 'Import', 'safe-publish' );
			}
			return __( 'Import / Update', 'safe-publish' );
		},
		isEligible: ( item: Post ) =>
			isAuthorized && ( ! item.is_imported || Boolean( item.has_update ) ),
		isPrimary: true,
		icon: download,
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
				return <ImportModal items={ items } closeModal={ closeModal } onRefresh={ onRefresh } />;
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

			return (
				<VStack spacing="5" style={ { minWidth: '400px' } } className="safe-publish-bulk-import-modal">
					<Text>
						{ /* translators: %d is the number of posts */
						__( 'Import %d selected posts as drafts?', 'safe-publish' ).replace( '%d', items.length.toString() ) }
					</Text>

					{ ! importResults && (
						<VStack spacing="2">
							<Text style={ { fontSize: '0.9em', color: '#666' } }>
								{ __( 'This will import all selected posts including their content, images, links, and formatting.', 'safe-publish' ) }
							</Text>
							<Text style={ { fontSize: '0.8em', color: '#d63638', fontWeight: 'bold' } }>
								{ __( '⚠️ Note: Posts that already exist will be automatically updated with the latest content from the external site.', 'safe-publish' ) }
							</Text>
						</VStack>
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

					{ importResults && (
						<VStack spacing="3">
							<Text style={ { color: '#008a20', fontWeight: 'bold' } }>
								{ __( 'Import completed!', 'safe-publish' ) }
							</Text>
							<Text>
								{ /* translators: 1: successful count, 2: total count */
								__( 'Successfully imported: %1$d of %2$d posts', 'safe-publish' )
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
							{ importResults.failed > 0 && (
								<Text style={ { color: '#d63638' } }>
									{ /* translators: %d is the number of failed imports */
									__( 'Failed imports: %d', 'safe-publish' ).replace( '%d', importResults.failed.toString() ) }
								</Text>
							) }

							{ importResults.results.length > 0 && (
								<div className="safe-publish-import-results">
								{ importResults.results.map( ( result, index ) => {
									let status;
									if ( ! result.success ) {
										status = __( 'Failed', 'safe-publish' );
									} else if ( result.existing ) {
										status = __( 'Updated', 'safe-publish' );
									} else {
										status = __( 'Created', 'safe-publish' );
									}

									return (
										<div key={ index } className="safe-publish-import-result-item">
											<span className={ `safe-publish-result-title ${ result.success ? 'success' : 'error' }` }>
												{ result.title }
											</span>
											<span className="safe-publish-result-status">
												{ status }
											</span>
										</div>
									);
								} ) }
								</div>
							) }
						</VStack>
					) }

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
								data-action-id="bulk-import"
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
	 * Edit Post action.
	 *
	 * Opens the local WordPress post editor in a new tab. Only available for
	 * posts that have already been imported.
	 */
	{
		id: 'edit-post',
		label: __( 'Edit', 'safe-publish' ),
		icon: edit,
		isPrimary: true,
		isEligible: ( item: Post ) => Boolean( item.is_imported && item.local_edit_url ),
		callback: ( items: Post[] ) => {
			const url = items[ 0 ]?.local_edit_url;

			if ( url ) {
				window.open( url, '_blank', 'noreferrer' );
			}
		},
	},
	/**
	 * Delete Post action.
	 *
	 * Moves the locally imported post to trash after confirmation. Only
	 * available for posts that have already been imported.
	 */
	{
		id: 'delete-post',
		label: __( 'Delete', 'safe-publish' ),
		icon: trash,
		isDestructive: true,
		isPrimary: true,
		isEligible: ( item: Post ) => Boolean( item.is_imported ),
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		RenderModal: ( { items, closeModal } ) => (
			<DeletePostModal items={ items } closeModal={ closeModal } onRefresh={ onRefresh } />
		),
	},
	/**
	 * Post Diff action.
	 *
	 * Displays a visual comparison between the local post content and the
	 * incoming external content.
	 */
	{
		id: 'post-diff',
		label: __( 'Post Diff', 'safe-publish' ),
		icon: drafts,
		hideModalHeader: false,
		supportsBulk: false,
		modalSize: 'fill',
		RenderModal: ( { items, closeModal } ) => {
			return <PostDiffModal items={ items } closeModal={ closeModal } />;
		},
	},
];
