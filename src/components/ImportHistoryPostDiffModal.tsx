/**
 * Post Diff Modal Component for Import History.
 *
 * Displays a modal showing the content changes for a post that was updated
 * during an import session.
 *
 * @file This file defines the PostDiffModal component for import history.
 */
import { getErrorMessage } from '../utils';
import {
	Button,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	Spinner
} from '@wordpress/components';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { ApiResponse, DiffHtmlData } from '../types';

/**
 * Props for the PostDiffModal component.
 *
 * @property {number}   postId  ID of the post to show diff for.
 * @property {Function} onClose Callback to close the modal.
 */
interface PostDiffModalProps {
	postId: number;
	onClose: () => void;
}

/**
 * Post Diff Modal component for import history.
 *
 * Fetches and displays the content diff for a post that was updated during an
 * import session.
 *
 * @param {Object}   props         Component props.
 * @param {number}   props.postId  ID of the post to show diff for.
 * @param {Function} props.onClose Callback to close the modal.
 *
 * @return {JSX.Element} Rendered modal content.
 */
export function PostDiffModal( { postId, onClose }: PostDiffModalProps ): JSX.Element {
	const [ diffHtml, setDiffHtml ] = useState< string | null >( null );
	const [ isLoading, setIsLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );

	/**
	 * Loads post diff from the backend.
	 *
	 * Fetches the diff HTML for the specified post from the WordPress AJAX
	 * endpoint.
	 *
	 * @return {Promise<void>} Resolves when diff is loaded.
	 */
	const loadPostDiff = useCallback( async (): Promise< void > => {
		setIsLoading( true );
		setError( null );

		try {
			const formData = new FormData();
			formData.append( 'action', 'safe_publish_get_post_diff' );
			formData.append( 'nonce', window.safePublishAdminData.nonce );
			formData.append( 'post_id', postId.toString() );

			const response = await fetch( window.safePublishAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json() as ApiResponse< DiffHtmlData >;

			if ( result.success ) {
				setDiffHtml( result.data.diff_html );
			} else {
				setError( getErrorMessage( result, __( 'Failed to load content changes.', 'safe-publish' ) ) );
			}
		} catch ( err ) {
			setError( __( 'Network error while loading content changes.', 'safe-publish' ) );
		} finally {
			setIsLoading( false );
		}
	}, [ postId ] );

	// Load post diff on component mount.
	useEffect( () => {
		void loadPostDiff();
	}, [ loadPostDiff ] );

	/**
	 * Renders the appropriate content based on loading/error/data state.
	 *
	 * @return {JSX.Element} Loading spinner, error message, diff HTML, or no changes message.
	 */
	const renderContent = () => {
		if ( isLoading ) {
			return (
				<HStack>
					<Spinner />
					<Text>{ __( 'Loading changes…', 'safe-publish' ) }</Text>
				</HStack>
			);
		}

		if ( error ) {
			return (
				<Text className="safe-publish-history-diff-error">
					{ /* translators: %s is the error message */
					__( 'Error: %s', 'safe-publish' ).replace( '%s', error ) }
				</Text>
			);
		}

		if ( diffHtml ) {
			return (
				<div
					className="safe-publish-history-diff-viewer"
					dangerouslySetInnerHTML={ { __html: diffHtml } }
				/>
			);
		}

		return <Text>{ __( 'No changes available.', 'safe-publish' ) }</Text>;
	};

	return (
		<VStack spacing={ 4 }>
			{ renderContent() }

			<HStack justify="right">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Close', 'safe-publish' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
