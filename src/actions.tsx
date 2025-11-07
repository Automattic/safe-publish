import {
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
	ProgressBar,
} from '@wordpress/components';
import { Action } from '@wordpress/dataviews/build-types';
import { drafts, update, download } from '@wordpress/icons';
import React, { useState } from 'react';

import PostDiffModal from './components/PostDiffModal';
import { Post } from './types';

// Response type for createDraftPost
interface CreateDraftResponse {
	post_id: number;
	edit_url: string;
	message: string;
	existing?: boolean;
	post_title?: string;
	confirm_action?: string;
}

// Response type for bulk import
interface BulkImportResponse {
	total: number;
	successful: number;
	failed: number;
	results: Array<{
		external_id: number;
		title: string;
		success: boolean;
		post_id?: number;
		edit_url?: string;
		error?: string;
		existing?: boolean;
	}>;
}

// API response wrapper
interface ApiResponse {
	success: boolean;
	data: CreateDraftResponse | BulkImportResponse | string;
}

/**
 * Create a draft post from external post data
 */
const createDraftPost = async ( post: Post ): Promise< CreateDraftResponse > => {
	const formData = new FormData();
	formData.append( 'action', 'ccp_create_draft' );
	formData.append( 'nonce', window.ccpAdminData.nonce );
	formData.append( 'external_post_id', post.id.toString() );
	formData.append( 'title', post.title );
	formData.append( 'content', post.content || post.excerpt || '' );
	formData.append( 'external_link', post.link );
	formData.append( 'post_type', post.post_type || 'post' ); // Send the post type

	if ( post.featured_media ) {
		formData.append( 'featured_media_id', post.featured_media.toString() );
	}

	if ( post.excerpt ) {
		formData.append( 'excerpt', post.excerpt );
	}

	if ( post.meta ) {
		formData.append( 'meta', JSON.stringify( post.meta ) );
	}

	if ( post.terms ) {
		formData.append( 'terms', JSON.stringify( post.terms ) );
	}

	console.log(formData);
	return false;

	const response = await fetch( window.ccpAdminData.ajaxurl, {
		method: 'POST',
		body: formData,
		headers: {
			'Accept': 'application/json; charset=utf-8',
		},
	} );

	const result = ( await response.json() ) as ApiResponse;

	if ( ! result.success ) {
		const errorMessage =
			typeof result.data === 'string' ? result.data : 'Failed to create draft post';
		throw new Error( errorMessage );
	}

	return result.data as CreateDraftResponse;
};

/**
 * Import multiple posts in bulk with progress tracking
 */
const bulkImportPosts = async (
	posts: Post[],
	onProgress?: ( current: number, total: number ) => void
): Promise< BulkImportResponse > => {
	// Use the proper bulk import endpoint instead of individual calls
	const formData = new FormData();
	formData.append( 'action', 'ccp_bulk_import' );
	formData.append( 'nonce', window.ccpAdminData.nonce );
	formData.append( 'posts_data', JSON.stringify( posts ) );

	// Show initial progress
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

		const result = await response.json();

		if ( ! result.success ) {
			throw new Error( typeof result.data === 'string' ? result.data : 'Bulk import failed' );
		}

		const bulkResult = result.data;

		// Show completion progress
		if ( onProgress ) {
			onProgress( posts.length, posts.length );
		}

		// Transform the backend response to match our expected format
		return {
			total: bulkResult.total || posts.length,
			successful: bulkResult.successful || 0,
			failed: bulkResult.failed || 0,
			results: bulkResult.results || [],
		};

	} catch ( error ) {
		// Show completion even on error
		if ( onProgress ) {
			onProgress( posts.length, posts.length );
		}
		throw error;
	}
};

export const actions: Action< Post >[] = [
	{
		id: 'draft',
		label: 'Create Draft',
		isPrimary: true,
		icon: drafts,
		hideModalHeader: true,
		modalFocusOnMount: 'firstContentElement',
		supportsBulk: true,
		RenderModal: ( { items, closeModal } ) => {
			const [ isLoading, setIsLoading ] = useState( false );
			const [ error, setError ] = useState< string | null >( null );
			const [ confirmData, setConfirmData ] = useState< CreateDraftResponse | null >( null );

			// Only process if exactly one item is selected
			if ( items.length !== 1 ) {
				return (
					<VStack spacing="5">
						<Text>Please select exactly one post to create a draft.</Text>
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
				.then( response => response.json() )
				.then( result => {
					setIsLoading( false );

					if ( ! result.success ) {
						const errorMessage =
							typeof result.data === 'string' ? result.data : 'Failed to create draft post';
						setError( errorMessage );
						return;
					}

					const data = result.data as CreateDraftResponse;

					// Check if this is a confirmation request
					if ( data.existing && data.confirm_action === 'update_existing' ) {
						setConfirmData( data );
						return;
					}

					// Redirect to edit page
					window.location.href = data.edit_url;
				} )
				.catch( err => {
					setError( err instanceof Error ? err.message : 'Unknown error occurred' );
					setIsLoading( false );
				} );
			};

			const handleConfirmUpdate = () => {
				handleCreateDraft( true );
			};

			const handleCancelUpdate = () => {
				if ( confirmData?.edit_url ) {
					// User chose not to update, redirect to existing post
					window.location.href = confirmData.edit_url;
				} else {
					closeModal();
				}
			};

			// Show confirmation dialog if post exists
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
					<Text>{ `Create a draft for "${ items[ 0 ].title }"?` }</Text>
					<Text style={ { fontSize: '0.9em', color: '#666' } }>
						This will import the post content including images, links, and formatting.
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
	{
		id: 'bulk-import',
		label: 'Bulk Import',
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

			// Only process if multiple items are selected
			if ( items.length <= 1 ) {
				return (
					<VStack spacing="5">
						<Text>Please select multiple posts to use bulk import.</Text>
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

			const handleBulkImport = async () => {
				setIsLoading( true );
				setError( null );
				setProgress( 0 );
				setImportResults( null );

				try {
					// Simulate progress for better UX since bulk import happens in one request
					const progressSteps = 10;
					const progressInterval = setInterval(() => {
						setProgress( prevProgress => {
							if ( prevProgress >= 90 ) {
								clearInterval( progressInterval );
								return 90; // Stop at 90% until we get real results
							}
							return prevProgress + ( 90 / progressSteps );
						});
					}, 200 );

					const result = await bulkImportPosts(
						items,
						( current, total ) => {
							// This won't be called much since it's a single request,
							// but we'll use it for final completion
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

			const handleCloseModal = () => {
				if ( importResults && importResults.successful > 0 ) {
					// Refresh the page to show updated post list
					window.location.reload();
				} else {
					closeModal();
				}
			};

			return (
				<VStack spacing="5" style={ { minWidth: '400px' } } className="ccp-bulk-import-modal">
					<Text>
						{ `Import ${ items.length } selected posts as drafts?` }
					</Text>

					{ ! importResults && (
						<VStack spacing="2">
							<Text style={ { fontSize: '0.9em', color: '#666' } }>
								This will import all selected posts including their content, images, links, and formatting.
							</Text>
							<Text style={ { fontSize: '0.8em', color: '#d63638', fontWeight: 'bold' } }>
								⚠️ Note: Posts that already exist will be automatically updated with the latest content from the external site.
							</Text>
						</VStack>
					) }

					{ isLoading && (
						<VStack spacing="3" className="ccp-bulk-import-progress">
							<Text>Importing posts as a batch...</Text>
							<ProgressBar value={ progress } />
							<Text style={ { fontSize: '0.8em', color: '#666' } }>
								{ progress === 100
									? 'Batch import completed!'
									: `Processing bulk import... ${ Math.round(progress) }% complete`
								}
							</Text>
							<Text style={ { fontSize: '0.75em', color: '#999' } }>
								All { items.length } posts will be imported in a single session
							</Text>
						</VStack>
					) }

					{ importResults && (
						<VStack spacing="3">
							<Text style={ { color: '#008a20', fontWeight: 'bold' } }>
								Import completed!
							</Text>
							<Text>
								Successfully imported: { importResults.successful } of { importResults.total } posts
							</Text>
							{ importResults.successful > 0 && (
								<Text style={ { fontSize: '0.9em', color: '#666' } }>
									{ (() => {
										const created = importResults.results.filter( r => r.success && !r.existing ).length;
										const updated = importResults.results.filter( r => r.success && r.existing ).length;
										const parts = [];
										if ( created > 0 ) parts.push( `${ created } created` );
										if ( updated > 0 ) parts.push( `${ updated } updated with latest content` );
										return parts.join( ', ' );
									} )() }
								</Text>
							) }
							{ importResults.failed > 0 && (
								<Text style={ { color: '#d63638' } }>
									Failed imports: { importResults.failed }
								</Text>
							) }

							{ importResults.results.length > 0 && (
								<div className="ccp-import-results">
									{ importResults.results.map( ( result, index ) => (
										<div key={ index } className="ccp-import-result-item">
											<span className={ `ccp-result-title ${ result.success ? 'success' : 'error' }` }>
												{ result.title }
											</span>
											<span className="ccp-result-status">
												{ result.success
													? ( result.existing ? 'Updated' : 'Created' )
													: 'Failed'
												}
											</span>
										</div>
									) ) }
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
								onClick={ handleBulkImport }
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
	{
		id: 'update',
		label: 'Update Post',
		icon: update,
		hideModalHeader: false,
		supportsBulk: true,
		RenderModal: ( { items, closeModal } ) => {
			return (
				<VStack spacing="5">
					<Text>{ `Are you sure you want to update "${ items[ 0 ].title }"?` }</Text>
					<HStack justify="right">
						<Button __next40pxDefaultSize variant="tertiary" onClick={ closeModal }>
							Cancel
						</Button>
						<Button __next40pxDefaultSize variant="primary" onClick={ closeModal }>
							Update Post
						</Button>
					</HStack>
				</VStack>
			);
		},
	},
	{
		id: 'tertiary',
		label: 'Post Diff',
		icon: drafts,
		hideModalHeader: false,
		supportsBulk: false,
		modalSize: 'fill',
		RenderModal: ( { items, closeModal } ) => {
			return <PostDiffModal items={ items } closeModal={ closeModal } />;
		},
	},
];
