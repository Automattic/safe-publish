/**
 * Post Diff Modal Component for Import History.
 *
 * Displays a modal showing the content changes for a post that was updated
 * during an import session.
 *
 * @file This file defines the PostDiffModal component for import history.
 */
import {
	Button,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	Spinner
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

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

	// Load post diff on component mount.
	useEffect( () => {
		loadPostDiff();
	}, [ postId ] );

	/**
	 * Loads post diff from the backend.
	 *
	 * Fetches the diff HTML for the specified post from the WordPress AJAX
	 * endpoint.
	 *
	 * @return {Promise<void>} Resolves when diff is loaded.
	 */
	const loadPostDiff = async (): Promise< void > => {
		setIsLoading( true );
		setError( null );

		try {
			const formData = new FormData();
			formData.append( 'action', 'ccp_get_post_diff' );
			formData.append( 'nonce', window.ccpAdminData.nonce );
			formData.append( 'post_id', postId.toString() );

			const response = await fetch( window.ccpAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json();

			if ( result.success && result.data ) {
				setDiffHtml( result.data.diff_html );
			} else {
				setError( result.data || __( 'Failed to load content changes.', 'ccp' ) );
			}
		} catch ( err ) {
			setError( __( 'Network error while loading content changes.', 'ccp' ) );
		} finally {
			setIsLoading( false );
		}
	};

	return (
		<VStack spacing={ 4 }>
			{ isLoading ? (
				<HStack>
					<Spinner />
					<Text>{ __( 'Loading changes…', 'ccp' ) }</Text>
				</HStack>
			) : error ? (
				<Text style={ { color: '#d63638' } }>
					{ __( 'Error: %s', 'ccp' ).replace( '%s', error ) }
				</Text>
			) : diffHtml ? (
				<div
					style={ {
						maxHeight: '60vh',
						overflowY: 'auto',
						background: '#f9f9f9',
						border: '1px solid #ddd',
						borderRadius: '4px',
						padding: '16px',
					} }
					dangerouslySetInnerHTML={ { __html: diffHtml } }
				/>
			) : (
				<Text>{ __( 'No changes available.', 'ccp' ) }</Text>
			) }

			<HStack justify="right">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Close', 'ccp' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
