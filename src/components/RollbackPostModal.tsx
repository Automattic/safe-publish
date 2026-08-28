/**
 * Rollback Post Modal component.
 *
 * Confirms rolling back the most recent import event for a Manage listing
 * row: A created post is permanently deleted, an updated post is restored to
 * its previous version.
 *
 * @file This file defines the RollbackPostModal component.
 */

import {
	Button,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	Spinner,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { isRollbackRestore, rollbackItem } from '../api/rollback';
import { useRefreshOnUnmount } from './hooks/useRefreshOnUnmount';

import type { ActionNotice } from '../actions';
import type { UnifiedPostRow } from '../types';

/**
 * Props for the RollbackPostModal component.
 */
interface RollbackPostModalProps {
	items: UnifiedPostRow[];
	ajaxurl: string;
	nonce: string;
	closeModal?: () => void;
	onNotice?: ( notice: ActionNotice | null ) => void;
	onRefresh?: () => void;
}

/**
 * Confirmation modal for rolling back a single import event.
 *
 * @param {RollbackPostModalProps} props Component props.
 */
const RollbackPostModal = ( {
	items,
	ajaxurl,
	nonce,
	closeModal,
	onNotice,
	onRefresh,
}: RollbackPostModalProps ) => {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ attempted, setAttempted ] = useState( false );

	useRefreshOnUnmount( attempted, onRefresh );

	const post = items[ 0 ];

	const isRestore = isRollbackRestore( post );

	const description = isRestore
		? __( 'This restores the previous version.', 'safe-publish' )
		: __( 'This permanently deletes the imported post.', 'safe-publish' );

	const handleRollback = () => {
		if ( null === post.item_id ) {
			setError( __( 'This post has no import record to roll back.', 'safe-publish' ) );
			return;
		}

		setAttempted( true );
		setIsLoading( true );
		setError( null );

		void rollbackItem( post.item_id, ajaxurl, nonce ).then( outcome => {
			if ( ! outcome.success ) {
				setError( outcome.error );
				setIsLoading( false );
				return;
			}

			onNotice?.( { status: 'success', message: outcome.message } );
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
			{ error && <Text role="alert" style={ { color: 'var(--safe-publish-status-error)' } }>{ error }</Text> }
			<HStack justify="right">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ closeModal }
					disabled={ isLoading }
					accessibleWhenDisabled
				>
					{ __( 'Cancel', 'safe-publish' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive={ ! isRestore }
					onClick={ handleRollback }
					disabled={ isLoading }
					accessibleWhenDisabled
				>
					{ isLoading ? (
						<>
							<Spinner />
							{ __( 'Rolling back…', 'safe-publish' ) }
						</>
					) : __( 'Roll back', 'safe-publish' ) }
				</Button>
			</HStack>
		</VStack>
	);
};

export default RollbackPostModal;
