/**
 * Import Modal component.
 *
 * Shared confirmation modal for both the Import Post action (Source Posts) and
 * the Update Post action (Imports → Posts tab). The `isUpdate` flag adjusts
 * labels and toggles the `force_update` flag on the create-draft request.
 *
 * @file This file defines the ImportModal component.
 */

import { ApiResponse, CreateDraftResponse, Warning } from '../types';
import { getErrorMessage, renderWarningMessage } from '../utils';
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
 * @property {number}   sourcePostId Source post ID to import or update.
 * @property {string}   title        Post title.
 * @property {string}   sourceLink   Source post permalink.
 * @property {string}   postType     Source post type slug.
 * @property {boolean}  isUpdate     True for the "Update" flow, false for "Import"; controls force_update + labels.
 * @property {string}   ajaxurl      WordPress admin-ajax URL.
 * @property {string}   nonce        AJAX nonce for the create-draft endpoint.
 * @property {Function} closeModal   Callback to close the modal.
 * @property {Function} onRefresh    Callback to refresh the posts list.
 */
interface ImportModalProps {
	sourcePostId: number;
	title: string;
	sourceLink: string;
	postType: string;
	isUpdate: boolean;
	ajaxurl: string;
	nonce: string;
	closeModal?: () => void;
	onRefresh?: () => void;
}

/**
 * Confirmation modal for importing or updating a single post.
 *
 * @param {ImportModalProps} props Component props.
 */
const ImportModal = ( {
	sourcePostId,
	title,
	sourceLink,
	postType,
	isUpdate,
	ajaxurl,
	nonce,
	closeModal,
	onRefresh,
}: ImportModalProps ) => {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ editUrl, setEditUrl ] = useState< string | null >( null );
	const [ warnings, setWarnings ] = useState< Warning[] >( [] );

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

		// ajax_create_draft re-fetches via fetch_fresh_post; only ID/title needed.
		const formData = new FormData();
		formData.append( 'action', 'safe_publish_create_draft' );
		formData.append( 'nonce', nonce );
		formData.append( 'source_post_id', sourcePostId.toString() );
		formData.append( 'title', title );
		formData.append( 'source_link', sourceLink );
		formData.append( 'post_type', postType || 'post' );

		if ( isUpdate ) {
			formData.append( 'force_update', 'true' );
		}

		fetch( ajaxurl, {
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

			setWarnings( Array.isArray( data.warnings ) ? data.warnings : [] );
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
				__( '"%s" has been updated.', 'safe-publish' ), title
			)
			: sprintf( /* translators: %s is the post title */
				__( '"%s" has been imported as a draft.', 'safe-publish' ), title
			);

		return (
			<VStack spacing="5">
				<Text>{ successMessage }</Text>
				{ warnings.length > 0 && (
					<VStack spacing="2" className="safe-publish-import-warnings" role="status">
						{ warnings.map( ( warning, index ) => (
							<Text key={ index } className="safe-publish-import-warning">
								{ renderWarningMessage( warning ) }
							</Text>
						) ) }
					</VStack>
				) }
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
					__( 'Update "%s" with the latest content from the source site?', 'safe-publish' ),
					title
				)
				: sprintf( /* translators: %s is the post title */
					__( 'Import "%s" as a draft?', 'safe-publish' ), title
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
