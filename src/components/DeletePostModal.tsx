/**
 * Delete Post Modal component.
 *
 * Confirmation modal for trashing one or many imported posts. Branches
 * on items.length: the single path hits safe_publish_delete_post; the
 * bulk path hits safe_publish_bulk_delete_posts with the full id list.
 *
 * @file This file defines the DeletePostModal component.
 */

import { ApiResponse, UnifiedPostRow } from '../types';
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
 */
interface DeletePostModalProps {
	items: UnifiedPostRow[];
	ajaxurl: string;
	nonce: string;
	closeModal?: () => void;
	onRefresh?: () => void;
}

/**
 * Response payload from safe_publish_bulk_delete_posts.
 */
interface BulkDeleteResponse {
	deleted: number;
	skipped: number;
}

/**
 * Confirmation modal for moving one or many imported posts to trash.
 *
 * @param {DeletePostModalProps} props Component props.
 */
const DeletePostModal = ( {
	items,
	ajaxurl,
	nonce,
	closeModal,
	onRefresh,
}: DeletePostModalProps ) => {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const isBulk = items.length > 1;

	const handleDelete = (): void => {
		setIsLoading( true );
		setError( null );

		const formData = new FormData();
		formData.append( 'nonce', nonce );

		if ( isBulk ) {
			formData.append( 'action', 'safe_publish_bulk_delete_posts' );
			items.forEach( ( item ) => {
				if ( null !== item.post_id ) {
					formData.append( 'post_ids[]', item.post_id.toString() );
				}
			} );
		} else {
			formData.append( 'action', 'safe_publish_delete_post' );
			formData.append( 'post_id', String( items[ 0 ].post_id ?? 0 ) );
		}

		fetch( ajaxurl, {
			method: 'POST',
			body: formData,
			headers: { Accept: 'application/json; charset=utf-8' },
		} )
			.then(
				( response ) =>
					response.json() as Promise< ApiResponse< BulkDeleteResponse > >
			)
			.then( ( result ) => {
				if ( ! result.success ) {
					setError(
						getErrorMessage(
							result,
							__( 'Failed to delete the post.', 'safe-publish' )
						)
					);
					setIsLoading( false );
					return;
				}

				if ( ! isBulk || result.data.deleted > 0 ) {
					onRefresh?.();
				}
				setIsLoading( false );
				closeModal?.();
			} )
			.catch( ( err ) => {
				setError(
					err instanceof Error
						? err.message
						: __( 'Unknown error occurred', 'safe-publish' )
				);
				setIsLoading( false );
			} );
	};

	const confirmationText = isBulk
		? sprintf(
				/* translators: %d is the number of selected posts */
				__( 'Move %d selected posts to trash?', 'safe-publish' ),
				items.length
		  )
		: sprintf(
				/* translators: %s is the post title */
				__( 'Move "%s" to trash?', 'safe-publish' ),
				items[ 0 ].title
		  );

	return (
		<VStack spacing="5">
			<Text>{ confirmationText }</Text>
			{ error && (
				<Text role="alert" style={ { color: '#d63638' } }>
					{ error }
				</Text>
			) }
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
							{ __( 'Moving to trash…', 'safe-publish' ) }
						</>
					) : (
						__( 'Move to Trash', 'safe-publish' )
					) }
				</Button>
			</HStack>
		</VStack>
	);
};

export default DeletePostModal;
