/**
 * Action definitions for the DataViews component.
 *
 * Defines the available actions for external posts including creating drafts,
 * bulk importing, updating posts, and viewing post diffs.
 *
 * @file This file defines DataViews actions for the CCP plugin.
 */
import { drafts, update, download } from '@wordpress/icons';

import PostDiffModal from './components/PostDiffModal';
import {
	ApiResponse,
	BulkImportResponse,
	CreateDraftResponse,
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
	formData.append( 'action', 'ccp_bulk_import' );
	formData.append( 'nonce', window.ccpAdminData.nonce );
	formData.append( 'posts_data', JSON.stringify( posts ) );

	// Show initial progress.
	if ( onProgress ) {
		onProgress( 0, posts.length );
	}

	try {
		const response = await fetch( window.ccpAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
			headers: {
				'Accept': 'application/json; charset=utf-8',
			},
		} );

		const result = await response.json() as ApiResponse<BulkImportResponse>;

		if ( ! result.success ) {
			throw new Error( getErrorMessage( result, __( 'Bulk import failed', 'ccp' ) ) );
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
 * DataViews actions for external posts.
 *
 * Defines the available actions that can be performed on posts in the DataViews
 * component, including creating drafts, bulk importing, updating, and viewing
 * diffs.
 */
export const actions: Action< Post >[] = [
	/**
	 * Create Draft action.
	 *
	 * Creates a new draft post from the selected external post, importing all
	 * content including images, links, and formatting.
	 */
	{
		id: 'draft',
		label: __( 'Create Draft', 'ccp' ),
		isPrimary: true,
		icon: drafts,
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		supportsBulk: true,
		RenderModal: ( { items, closeModal } ) => {
			const [ isLoading, setIsLoading ] = useState( false );
			const [ error, setError ] = useState< string | null >( null );
			const [ confirmData, setConfirmData ] = useState< CreateDraftResponse | null >( null );

			// Only process if exactly one item is selected.
			if ( 1 !== items.length ) {
				return (
					<VStack spacing="5">
					<Text>{ __( 'Please select exactly one post to create a draft.', 'ccp' ) }</Text>
						<HStack justify="right">
							<Button
								__next40pxDefaultSize
								variant="primary"
								onClick={ closeModal }
							>
								OK
							</Button>
						</HStack>
					</VStack>
				);
			}

			/**
			 * Handles creating a draft post.
			 *
			 * Sends the post data to WordPress and handles confirmation if the
			 * post already exists.
			 *
			 * @param {boolean} forceUpdate Whether to force update an existing post.
			 */
			const handleCreateDraft = ( forceUpdate = false ) => {
				setIsLoading( true );
				setError( null );
				setConfirmData( null );

				const formData = new FormData();
				formData.append( 'action', 'ccp_create_draft' );
				formData.append( 'nonce', window.ccpAdminData.nonce );
				formData.append( 'external_post_id', items[ 0 ].id.toString() );
				formData.append( 'title', items[ 0 ].title );
				formData.append( 'content', items[ 0 ].content || items[ 0 ].excerpt || '' );
				formData.append( 'external_link', items[ 0 ].link );
				formData.append( 'post_type', items[ 0 ].post_type || 'post' );

				if ( forceUpdate ) {
					formData.append( 'force_update', 'true' );
				}

				if ( items[ 0 ].featured_media ) {
					formData.append( 'featured_media_id', items[ 0 ].featured_media.toString() );
				}

				if ( items[ 0 ].excerpt ) {
					formData.append( 'excerpt', items[ 0 ].excerpt );
				}

				if ( items[ 0 ].meta ) {
					formData.append( 'meta', JSON.stringify( items[ 0 ].meta ) );
				}

				if ( items[ 0 ].terms ) {
					formData.append( 'terms', JSON.stringify( items[ 0 ].terms ) );
				}

				fetch( window.ccpAdminData.ajaxurl, {
					method: 'POST',
					body: formData,
					headers: {
						'Accept': 'application/json; charset=utf-8',
					},
				} )
				.then( response => response.json() as Promise<ApiResponse<CreateDraftResponse>> )
				.then( ( result ) => {
					setIsLoading( false );

					if ( ! result.success ) {
						setError( getErrorMessage( result, __( 'Failed to create draft post', 'ccp' ) ) );

						return;
					}

					const data = result.data;

					// Check if this is a confirmation request.
					if ( data.existing && 'update_existing' === data.confirm_action ) {
						setConfirmData( data );
						return;
					}

					// Validate edit URL before redirecting.
					if ( ! data.edit_url || typeof data.edit_url !== 'string' ) {
						setError( 'Invalid response: missing edit URL' );
						return;
					}

					// Redirect to edit page.
					window.location.href = data.edit_url;
				} )
				.catch( err => {
					setError( err instanceof Error ? err.message : 'Unknown error occurred' );
					setIsLoading( false );
				} );
			};

			/**
			 * Handles confirming an update to an existing post.
			 */
			const handleConfirmUpdate = () => {
				handleCreateDraft( true );
			};

			/**
			 * Handles cancelling the update and redirecting to the existing post.
			 */
			const handleCancelUpdate = () => {
				if ( confirmData?.edit_url && typeof confirmData.edit_url === 'string' ) {
					// User chose not to update, redirect to existing post.
					window.location.href = confirmData.edit_url;
				} else {
					closeModal?.();
				}
			};

			// Show confirmation dialog if post exists.
			if ( confirmData ) {
				return (
					<VStack spacing="5">
						<Text style={ { fontWeight: 'bold' } }>Post Already Exists</Text>
						<Text>{ confirmData.message }</Text>
						<Text style={ { fontSize: '0.9em', color: '#666' } }>
							Updating will fetch the latest content from the external site and replace the current content.
						</Text>
						{ error && <Text style={ { color: '#d63638' } }>{ error }</Text> }
						<HStack justify="right">
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								onClick={ handleCancelUpdate }
								disabled={ isLoading }
							>
								Edit Existing
							</Button>
							<Button
								__next40pxDefaultSize
								variant="primary"
								onClick={ handleConfirmUpdate }
								disabled={ isLoading }
							>
								{ isLoading ? (
									<>
										<Spinner />
										Updating...
									</>
								) : (
									'Update with Latest'
								) }
							</Button>
						</HStack>
					</VStack>
				);
			}

			return (
				<VStack spacing="5">
					<Text>{ /* translators: %s is the post title */
						__( 'Create a draft for "%s"?', 'ccp' ).replace( '%s', items[ 0 ].title ) }
					</Text>
					<Text style={ { fontSize: '0.9em', color: '#666' } }>
						{ __( 'This will import the post content including images, links, and formatting.', 'ccp' ) }
					</Text>
					{ error && <Text style={ { color: '#d63638' } }>{ error }</Text> }
					<HStack justify="right">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ closeModal }
							disabled={ isLoading }
						>
							Cancel
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ () => handleCreateDraft( false ) }
							disabled={ isLoading }
						>
							{ isLoading ? (
								<>
									<Spinner />
									Importing...
								</>
							) : (
								'Create Draft'
							) }
						</Button>
					</HStack>
				</VStack>
			);
		},
	},
	/**
	 * Bulk Import action.
	 *
	 * Imports multiple selected posts as drafts in a single batch operation,
	 * with progress tracking and result summary.
	 */
	{
		id: 'bulk-import',
		label: __( 'Bulk Import', 'ccp' ),
		isPrimary: false,
		icon: download,
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		supportsBulk: true,
		RenderModal: ( { items, closeModal } ) => {
			const [ isLoading, setIsLoading ] = useState( false );
			const [ error, setError ] = useState< string | null >( null );
			const [ progress, setProgress ] = useState( 0 );
			const [ importResults, setImportResults ] = useState< BulkImportResponse | null >( null );

			// Only process if multiple items are selected.
			if ( items.length <= 1 ) {
				return (
					<VStack spacing="5">
						<Text>{ __( 'Please select multiple posts to use bulk import.', 'ccp' ) }</Text>
						<HStack justify="right">
							<Button
								__next40pxDefaultSize
								variant="primary"
								onClick={ closeModal }
							>
								OK
							</Button>
						</HStack>
					</VStack>
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
					setError( err instanceof Error ? err.message : 'Unknown error occurred' );
				} finally {
					setIsLoading( false );
				}
			};

			/**
			 * Handles closing the modal.
			 *
			 * Refreshes the page if imports were successful to show updated
			 * post list.
			 */
			const handleCloseModal = () => {
				if ( importResults && importResults.successful > 0 ) {
					// Refresh the page to show updated post list.
					window.location.reload();
				} else {
					closeModal?.();
				}
			};

			return (
				<VStack spacing="5" style={ { minWidth: '400px' } } className="ccp-bulk-import-modal">
					<Text>
						{ /* translators: %d is the number of posts */
						__( 'Import %d selected posts as drafts?', 'ccp' ).replace( '%d', items.length.toString() ) }
					</Text>

					{ ! importResults && (
						<VStack spacing="2">
							<Text style={ { fontSize: '0.9em', color: '#666' } }>
								{ __( 'This will import all selected posts including their content, images, links, and formatting.', 'ccp' ) }
							</Text>
							<Text style={ { fontSize: '0.8em', color: '#d63638', fontWeight: 'bold' } }>
								{ __( '⚠️ Note: Posts that already exist will be automatically updated with the latest content from the external site.', 'ccp' ) }
							</Text>
						</VStack>
					) }

					{ isLoading && (
						<VStack spacing="3" className="ccp-bulk-import-progress">
							<Text>{ __( 'Importing posts as a batch…', 'ccp' ) }</Text>
							<ProgressBar value={ progress } />
							<Text style={ { fontSize: '0.8em', color: '#666' } }>
								{ progress === 100
									? __( 'Batch import completed!', 'ccp' )
									: /* translators: %d is the percentage complete */
									__( 'Processing bulk import… %d%% complete', 'ccp' )
										.replace( '%d', Math.round(progress).toString() )
								}
							</Text>
							<Text style={ { fontSize: '0.75em', color: '#999' } }>
								{ /* translators: %d is the number of posts */
								__( 'All %d posts will be imported in a single session', 'ccp' )
									.replace( '%d', items.length.toString() ) }
							</Text>
						</VStack>
					) }

					{ importResults && (
						<VStack spacing="3">
							<Text style={ { color: '#008a20', fontWeight: 'bold' } }>
								{ __( 'Import completed!', 'ccp' ) }
							</Text>
							<Text>
								{ /* translators: 1: successful count, 2: total count */
								__( 'Successfully imported: %1$d of %2$d posts', 'ccp' )
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
											parts.push( __( '%d created', 'ccp' ).replace( '%d', created.toString() ) );
										}
										if ( updated > 0 ) {
											/* translators: %d is the number of posts updated */
											parts.push( __( '%d updated with latest content', 'ccp' )
												.replace( '%d', updated.toString() ) );
										}
										return parts.join( ', ' );
									} )() }
								</Text>
							) }
							{ importResults.failed > 0 && (
								<Text style={ { color: '#d63638' } }>
									{ /* translators: %d is the number of failed imports */
									__( 'Failed imports: %d', 'ccp' ).replace( '%d', importResults.failed.toString() ) }
								</Text>
							) }

							{ importResults.results.length > 0 && (
								<div className="ccp-import-results">
								{ importResults.results.map( ( result, index ) => {
									let status;
									if ( ! result.success ) {
										status = 'Failed';
									} else if ( result.existing ) {
										status = 'Updated';
									} else {
										status = 'Created';
									}

									return (
										<div key={ index } className="ccp-import-result-item">
											<span className={ `ccp-result-title ${ result.success ? 'success' : 'error' }` }>
												{ result.title }
											</span>
											<span className="ccp-result-status">
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
							{ importResults ? 'Close' : 'Cancel' }
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
										Importing...
									</>
								) : (
									`Import ${ items.length } Posts`
								) }
							</Button>
						) }
					</HStack>
				</VStack>
			);
		},
	},
	/**
	 * Update Post action.
	 *
	 * Updates an existing local post with the latest content from the external
	 * source.
	 */
	{
		id: 'update',
		label: __( 'Update Post', 'ccp' ),
		icon: update,
		hideModalHeader: false,
		supportsBulk: true,
		RenderModal: ( { items, closeModal } ) => {
			return (
				<VStack spacing="5">
					<Text>{ /* translators: %s is the post title */
						__( 'Are you sure you want to update "%s"?', 'ccp' ).replace( '%s', items[ 0 ].title ) }
					</Text>
					<HStack justify="right">
						<Button __next40pxDefaultSize variant="tertiary" onClick={ closeModal }>
							{ __( 'Cancel', 'ccp' ) }
						</Button>
						<Button __next40pxDefaultSize variant="primary" onClick={ closeModal }>
							{ __( 'Update Post', 'ccp' ) }
						</Button>
					</HStack>
				</VStack>
			);
		},
	},
	/**
	 * Post Diff action.
	 *
	 * Displays a visual comparison between the local post content and the
	 * incoming external content.
	 */
	{
		id: 'post-diff',
		label: __( 'Post Diff', 'ccp' ),
		icon: drafts,
		hideModalHeader: false,
		supportsBulk: false,
		modalSize: 'fill',
		RenderModal: ( { items, closeModal } ) => {
			return <PostDiffModal items={ items } closeModal={ closeModal } />;
		},
	},
];
