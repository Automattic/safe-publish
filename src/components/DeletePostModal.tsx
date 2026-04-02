/**
 * Delete Post Modal component.
 *
 * Displays a confirmation modal before moving an imported post to trash.
 *
 * @file This file defines the DeletePostModal component.
 */

import { ApiResponse, Post } from '../types';
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
 * Props for the DeletePostModal component.
 *
 * @property {Post[]}   items        Array containing the single post to delete.
 * @property {Function} [closeModal] Callback to close the modal.
 * @property {Function} [onRefresh]  Callback to refresh the posts list after a successful deletion.
 */
interface DeletePostModalProps {
	items: Post[];
	closeModal?: () => void;
	onRefresh?: () => void;
}

/**
 * Confirmation modal for moving an imported post to trash.
 *
 * @param {DeletePostModalProps} props Component props.
 */
const DeletePostModal = ( { items, closeModal, onRefresh }: DeletePostModalProps ) => {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const post = items[ 0 ];

	const handleDelete = () => {
		setIsLoading( true );
		setError( null );

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_delete_post' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'external_post_id', post.id.toString() );

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
			headers: { Accept: 'application/json; charset=utf-8' },
		} )
			.then( response => response.json() as Promise< ApiResponse > )
			.then( result => {
				if ( ! result.success ) {
					setError( getErrorMessage( result, __( 'Failed to delete', 'safe-publish' ) ) );
					setIsLoading( false );
					return;
				}

				onRefresh?.();
				setIsLoading( false );
				closeModal?.();
			} )
			.catch( err => {
				setError( err instanceof Error ? err.message : __( 'Unknown error occurred', 'safe-publish' ) );
				setIsLoading( false );
			} );
	};

	return (
		<VStack spacing="5">
			<Text>
				{ sprintf( /* translators: %s is the post title */
					__( 'Move "%s" to trash?', 'safe-publish' ), post.title
				) }
			</Text>
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
					isDestructive
					onClick={ handleDelete }
					disabled={ isLoading }
				>
					{ isLoading ? (
						<>
							<Spinner />
							{ __( 'Deleting…', 'safe-publish' ) }
						</>
					) : __( 'Move to Trash', 'safe-publish' ) }
				</Button>
			</HStack>
		</VStack>
	);
};

export default DeletePostModal;
