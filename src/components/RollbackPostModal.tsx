/**
 * Rollback Post Modal component.
 *
 * Confirms rolling back the most recent import event for an Imported Posts
 * row: a created post is permanently deleted, an updated post is restored to
 * its previous version.
 *
 * @file This file defines the RollbackPostModal component.
 */

import { ApiResponse, ImportedPost } from '../types';
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
 * Props for the RollbackPostModal component.
 *
 * @property {ImportedPost[]} items        Array containing the single row to roll back.
 * @property {Function}       [closeModal] Callback to close the modal.
 * @property {Function}       [onRefresh]  Callback to refresh the listing after a rollback.
 */
interface RollbackPostModalProps {
	items: ImportedPost[];
	closeModal?: () => void;
	onRefresh?: () => void;
}

/**
 * Confirmation modal for rolling back a single import event.
 *
 * @param {RollbackPostModalProps} props Component props.
 */
const RollbackPostModal = ( { items, closeModal, onRefresh }: RollbackPostModalProps ) => {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const post = items[ 0 ];

	// `success` rows are newly created posts (deleted on rollback); every other
	// eligible status restores the pre-update snapshot. Mirrors the server's
	// Session_Formatter::determine_rollback_action().
	const isRestore = 'success' !== post.rollback_status;

	const actionLabel = isRestore
		? __( 'Restore', 'safe-publish' )
		: __( 'Delete permanently', 'safe-publish' );

	const description = isRestore
		? __( 'This restores the previous version.', 'safe-publish' )
		: __( 'This permanently deletes the imported post.', 'safe-publish' );

	const handleRollback = () => {
		if ( null === post.item_id ) {
			setError( __( 'This post has no import record to roll back.', 'safe-publish' ) );
			return;
		}

		setIsLoading( true );
		setError( null );

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_rollback_item' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'item_id', post.item_id.toString() );

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
			headers: { Accept: 'application/json; charset=utf-8' },
		} )
			.then( response => response.json() as Promise< ApiResponse > )
			.then( result => {
				if ( ! result.success ) {
					setError( getErrorMessage( result, __( 'Failed to roll back', 'safe-publish' ) ) );
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
					__( 'Roll back "%s"?', 'safe-publish' ), post.title ) }
			</Text>
			<Text>{ description }</Text>
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
					isDestructive={ ! isRestore }
					onClick={ handleRollback }
					disabled={ isLoading }
				>
					{ isLoading ? (
						<>
							<Spinner />
							{ __( 'Rolling back…', 'safe-publish' ) }
						</>
					) : actionLabel }
				</Button>
			</HStack>
		</VStack>
	);
};

export default RollbackPostModal;
