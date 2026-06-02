/**
 * Rollback Post Modal component.
 *
 * Confirms rolling back the most recent import event for an Imported Posts
 * row: a created post is permanently deleted, an updated post is restored to
 * its previous version.
 *
 * @file This file defines the RollbackPostModal component.
 */

import { isRollbackRestore, rollbackItem } from '../api/rollback';
import {
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { ImportedPost } from '../types';

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

	const isRestore = isRollbackRestore( post );

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

		void rollbackItem( post.item_id ).then( outcome => {
			if ( ! outcome.success ) {
				setError( outcome.error );
				setIsLoading( false );
				return;
			}

			onRefresh?.();
			setIsLoading( false );
			closeModal?.();
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
