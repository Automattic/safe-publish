/**
 * Delete Failed Imports Modal component.
 *
 * Confirms removal of one or more failed import rows from the Failures tab.
 * The rows are deleted from the items table; no WordPress post is affected
 * (failed imports never produced one).
 *
 * @file This file defines the DeleteFailedImportsModal component.
 */

import { ApiResponse, FailedImport } from '../types';
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
 * Props for the DeleteFailedImportsModal component.
 *
 * @property {FailedImport[]} items      Rows to remove (one or more).
 * @property {string}         ajaxurl    WordPress admin-ajax URL.
 * @property {string}         nonce      AJAX nonce for the delete endpoint.
 * @property {Function}       closeModal Callback to close the modal.
 * @property {Function}       onRefresh  Callback to refresh the listing.
 */
interface DeleteFailedImportsModalProps {
	items: FailedImport[];
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
		formData.append( 'action', 'safe_publish_delete_failed_imports' );
		formData.append( 'nonce', nonce );
		items.forEach( ( item ) =>
			formData.append( 'item_ids[]', String( item.id ) )
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

	return (
		<VStack spacing="5">
			<Text>
				{ isBulk
					? sprintf(
							/* translators: %d: number of failed imports selected. */
							__( 'Remove %d failed imports?', 'safe-publish' ),
							items.length
					  )
					: sprintf(
							/* translators: %s: failed-import title. */
							__( 'Remove "%s"?', 'safe-publish' ),
							items[ 0 ].title
					  ) }
			</Text>
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
