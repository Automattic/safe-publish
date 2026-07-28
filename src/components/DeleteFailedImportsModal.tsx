/**
 * Delete Failed Imports Modal component.
 *
 * Confirms removal of one or more failure rows from the items table. Used by
 * both the orphan-failures drawer and the Failed-chip Dismiss action; no
 * WordPress post is affected by either path.
 *
 * @file This file defines the DeleteFailedImportsModal component.
 */

import { ApiResponse } from '../types';
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
 * Minimal row shape needed by the modal. `id` carries either an items-table
 * row id or a source_post_id depending on the caller's `scope`.
 */
export interface DeleteFailedImportsItem {
	id: number;
	title: string;
}

/**
 * Delete scope. `items` targets specific items-table rows (drawer / orphan
 * failures). `sources` clears every failure attempt for the source_post_id,
 * so the listing's deduped row doesn't re-surface a sibling on refresh.
 */
export type DeleteFailedImportsScope = 'items' | 'sources';

/**
 * Props for the DeleteFailedImportsModal component.
 */
interface DeleteFailedImportsModalProps {
	items: DeleteFailedImportsItem[];
	ajaxurl: string;
	nonce: string;
	scope?: DeleteFailedImportsScope;
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
	scope = 'items',
	closeModal,
	onRefresh,
}: DeleteFailedImportsModalProps ): JSX.Element => {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const isBulk = items.length > 1;
	// Dismiss (Failed chip) and Remove (orphan drawer) share this modal; the
	// verb tracks the scope so each entry reads in its own voice.
	const isDismiss = 'sources' === scope;

	const handleDelete = (): void => {
		setIsLoading( true );
		setError( null );

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_delete_failed_items' );
		formData.append( 'nonce', nonce );
		const idField =
			'sources' === scope ? 'source_post_ids[]' : 'item_ids[]';
		items.forEach( ( item ) =>
			formData.append( idField, String( item.id ) )
		);

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
							isDismiss
								? __( 'Failed to dismiss.', 'safe-publish' )
								: __( 'Failed to remove.', 'safe-publish' )
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

	let confirmationText: string;
	if ( isBulk ) {
		confirmationText = isDismiss
			? sprintf(
					/* translators: %d: number of failed imports selected. */
					__( 'Dismiss %d failed imports?', 'safe-publish' ),
					items.length
			  )
			: sprintf(
					/* translators: %d: number of failed imports selected. */
					__( 'Remove %d failed imports?', 'safe-publish' ),
					items.length
			  );
	} else {
		confirmationText = isDismiss
			? sprintf(
					/* translators: %s: failed-import title. */
					__( 'Dismiss "%s"?', 'safe-publish' ),
					items[ 0 ].title
			  )
			: sprintf(
					/* translators: %s: failed-import title. */
					__( 'Remove "%s"?', 'safe-publish' ),
					items[ 0 ].title
			  );
	}

	const processingLabel = isDismiss
		? __( 'Dismissing…', 'safe-publish' )
		: __( 'Removing…', 'safe-publish' );
	const actionLabel = isDismiss
		? __( 'Dismiss', 'safe-publish' )
		: __( 'Remove', 'safe-publish' );

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
							{ processingLabel }
						</>
					) : (
						actionLabel
					) }
				</Button>
			</HStack>
		</VStack>
	);
};

export default DeleteFailedImportsModal;
