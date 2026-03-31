/**
 * Import Modal component.
 *
 * Shared confirmation modal for both the Import Post and Update Post actions.
 * Detects whether the post is already imported via `is_imported` and adjusts
 * labels and the `force_update` flag accordingly.
 *
 * @file This file defines the ImportModal component.
 */

import { ApiResponse, CreateDraftResponse, Post } from '../types';
import { getErrorMessage } from '../utils';
import {
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Props for the ImportModal component.
 *
 * @property {Post[]}   items        Array containing the single post to import or update.
 * @property {Function} [closeModal] Callback to close the modal.
 * @property {Function} [onRefresh]  Callback to refresh the posts list after a successful operation.
 */
interface ImportModalProps {
	items: Post[];
	closeModal?: () => void;
	onRefresh?: () => void;
}

/**
 * Confirmation modal for importing or updating a single post.
 *
 * @param {ImportModalProps} props Component props.
 */
const ImportModal = ( { items, closeModal, onRefresh }: ImportModalProps ) => {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ editUrl, setEditUrl ] = useState< string | null >( null );

	const post = items[ 0 ];
	const isUpdate = Boolean( post?.is_imported );
	const submitLabel = isUpdate ? __( 'Update', 'safe-publish' ) : __( 'Import', 'safe-publish' );
	const loadingLabel = isUpdate ? __( 'Updating…', 'safe-publish' ) : __( 'Importing…', 'safe-publish' );

	/**
	 * Sends the post to the backend for import or update.
	 *
	 * When updating an already-imported post, `force_update` is set so
	 * the backend skips its own existence-confirmation roundtrip.
	 */
	const handleSubmit = () => {
		setIsLoading( true );
		setError( null );

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_create_draft' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'external_post_id', post.id.toString() );
		formData.append( 'title', post.title );
		formData.append( 'content', post.content || post.excerpt || '' );
		formData.append( 'external_link', post.link );
		formData.append( 'post_type', post.post_type || 'post' );

		if ( isUpdate ) {
			formData.append( 'force_update', 'true' );
		}

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

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
			headers: {
				'Accept': 'application/json; charset=utf-8',
			},
		} )
		.then( response => response.json() as Promise< ApiResponse< CreateDraftResponse > > )
		.then( ( result ) => {
			setIsLoading( false );

			if ( ! result.success ) {
				setError( getErrorMessage( result, __( 'Failed to import', 'safe-publish' ) ) );
				return;
			}

			const data = result.data;

			// Validate edit URL before using it.
			if ( ! data.edit_url || typeof data.edit_url !== 'string' ) {
				setError( __( 'Invalid response: missing edit URL', 'safe-publish' ) );
				return;
			}

			setEditUrl( data.edit_url );
		} )
		.catch( err => {
			setError( err instanceof Error ? err.message : __( 'Unknown error occurred', 'safe-publish' ) );
			setIsLoading( false );
		} );
	};

	// Success state. Show a modal with options to edit the post or close the modal.
	if ( editUrl ) {
		const successMessage = isUpdate
			? sprintf( /* translators: %s is the post title */
				__( '"%s" has been updated.', 'safe-publish' ), post.title
			)
			: sprintf( /* translators: %s is the post title */
				__( '"%s" has been imported as a draft.', 'safe-publish' ), post.title
			);

		return (
			<VStack spacing="5">
				<Text>{ successMessage }</Text>
				<HStack justify="right">
					<Button
						__next40pxDefaultSize
						variant="tertiary"
						onClick={ () => {
							onRefresh?.();
							closeModal?.();
						} }
					>
						{ __( 'Close', 'safe-publish' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ () => {
							window.open( editUrl, '_blank', 'noreferrer' );
							onRefresh?.();
							closeModal?.();
						} }
					>
						{ __( 'Edit Post', 'safe-publish' ) }
					</Button>
				</HStack>
			</VStack>
		);
	}

	return (
		<VStack spacing="5">
			<Text>{ isUpdate
				? sprintf( /* translators: %s is the post title */
					__( 'Update "%s" with the latest content from the external site?', 'safe-publish' ),
					post.title
				)
				: sprintf( /* translators: %s is the post title */
					__( 'Import "%s" as a draft?', 'safe-publish' ), post.title
				)
			}</Text>
			{ ! isUpdate && (
				<Text style={ { fontSize: '0.9em', color: '#666' } }>
					{ __(
						'This will import the post content including images, links, and formatting.',
						'safe-publish'
					) }
				</Text>
			) }
			{ error && <Text role="alert" style={ { color: '#d63638' } }>{ error }</Text> }
			<HStack justify="right">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ closeModal }
					disabled={ isLoading }
				>
					{ __( 'Cancel', 'safe-publish' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ handleSubmit }
					disabled={ isLoading }
				>
					{ isLoading ? (
						<>
							<Spinner />
							{ loadingLabel }
						</>
					) : submitLabel }
				</Button>
			</HStack>
		</VStack>
	);
};

export default ImportModal;
