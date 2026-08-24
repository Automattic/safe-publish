/**
 * Delete Failed Imports Modal component.
 *
 * Confirms removal of one or more failure rows from the items table; no
 * WordPress post is affected. Orphan failures are cleared by item id;
 * source-linked failures clear every attempt for the source, so the inbox's
 * deduped row doesn't re-surface a sibling on refresh.
 *
 * @file This file defines the DeleteFailedImportsModal component.
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

import { ApiResponse } from '../types';
import { getErrorMessage } from '../utils';
import ConfirmTitleList from './ConfirmTitleList';

/**
 * A failure row to remove. Orphans carry only an itemId; source-linked
 * failures carry a sourcePostId so every attempt for the source is cleared.
 */
export interface DeleteFailedImportsItem {
	itemId: number;
	sourcePostId: number | null;
	title: string;
}

/**
 * Props for the DeleteFailedImportsModal component.
 */
interface DeleteFailedImportsModalProps {
	items: DeleteFailedImportsItem[];
	ajaxurl: string;
	nonce: string;
	closeModal?: () => void;
	onRefresh?: () => void;
}

/**
 * Confirmation modal for removing failed-import rows.
 *
 * @param {DeleteFailedImportsModalProps} props Component props.
 */
const DeleteFailedImportsModal = ( {
	items,
	ajaxurl,
	nonce,
	closeModal,
	onRefresh,
}: DeleteFailedImportsModalProps ): JSX.Element => {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const isBulk = items.length > 1;

	const handleDelete = (): void => {
		setIsLoading( true );
		setError( null );

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_delete_failed_items' );
		formData.append( 'nonce', nonce );
		items.forEach( ( item ) => {
			if ( null !== item.sourcePostId ) {
				formData.append(
					'source_post_ids[]',
					String( item.sourcePostId )
				);
			} else {
				formData.append( 'item_ids[]', String( item.itemId ) );
			}
		} );

		fetch( ajaxurl, {
			method: 'POST',
			body: formData,
			headers: { Accept: 'application/json; charset=utf-8' },
		} )
			.then( ( response ) => response.json() as Promise< ApiResponse > )
			.then( ( result ) => {
				if ( ! result.success ) {
					setError(
						getErrorMessage(
							result,
							__( 'Failed to remove.', 'safe-publish' )
						)
					);
					setIsLoading( false );
					return;
				}

				onRefresh?.();
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
				/* translators: %d: number of failed imports selected. */
				__( 'Remove %d failed imports?', 'safe-publish' ),
				items.length
		  )
		: sprintf(
				/* translators: %s: failed-import title. */
				__( 'Remove "%s"?', 'safe-publish' ),
				items[ 0 ].title
		  );

	return (
		<VStack spacing="5">
			{ isBulk ? (
				<ConfirmTitleList
					heading={ confirmationText }
					titles={ items.map( ( item ) => item.title ) }
				/>
			) : (
				<Text>{ confirmationText }</Text>
			) }
			{ error && (
				<Text
					role="alert"
					style={ { color: 'var(--safe-publish-status-error)' } }
				>
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
							{ __( 'Removing…', 'safe-publish' ) }
						</>
					) : (
						__( 'Remove', 'safe-publish' )
					) }
				</Button>
			</HStack>
		</VStack>
	);
};

export default DeleteFailedImportsModal;
