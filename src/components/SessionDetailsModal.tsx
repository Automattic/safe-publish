/**
 * Session Details Modal Component.
 *
 * Displays detailed information about an import session including individual
 * items with rollback and diff viewing capabilities.
 *
 * @file This file defines the SessionDetailsModal component.
 */
import { getErrorMessage } from '../utils';
import {
	Button,
	__experimentalHStack as HStack,
	Spinner,
	__experimentalText as Text,
	__experimentalVStack as VStack,
	Notice
} from '@wordpress/components';
import { useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type {
	ApiResponse,
	ImportItem,
	ImportSession,
	RollbackItemData,
	SessionDetailsData,
} from '../types';

/**
 * Props for the SessionDetailsModal component.
 *
 * @property {ImportSession} session    Import session to display.
 * @property {Function}      onRollback Callback to rollback the session.
 * @property {Function}      onViewDiff Callback to view a post diff.
 * @property {Function}      onClose    Callback to close the modal.
 */
interface SessionDetailsModalProps {
	session: ImportSession;
	onRollback: ( sessionId: number ) => Promise< void >;
	onViewDiff: ( postId: number ) => void;
	onClose: () => void;
}

/**
 * Session Details Modal component.
 *
 * Displays session statistics, individual import items, and provides actions for
 * rolling back individual items or viewing content diffs.
 *
 * @param {Object}        props            Component props.
 * @param {ImportSession} props.session    Import session to display.
 * @param {Function}      props.onRollback Callback to rollback the session.
 * @param {Function}      props.onViewDiff Callback to view a post diff.
 * @param {Function}      props.onClose    Callback to close the modal.
 *
 * @return {JSX.Element} Rendered modal content.
 */
export function SessionDetailsModal( {
	session,
	onRollback,
	onViewDiff,
	onClose
}: SessionDetailsModalProps ): JSX.Element {
	const [ items, setItems ] = useState< ImportItem[] >( [] );
	const [ isLoading, setIsLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ isRollingBack, setIsRollingBack ] = useState< boolean >( false );
	const [ rollingBackItemId, setRollingBackItemId ] = useState< number | null >( null );
	const [ noticeMessage, setNoticeMessage ] = useState< { type: 'success' | 'error'; message: string } | null >( null );

	/**
	 * Loads session details and items.
	 *
	 * Fetches the detailed items for the current session from the
	 * WordPress AJAX endpoint.
	 *
	 * @return {Promise<void>} Resolves when details are loaded.
	 */
	const loadSessionDetails = useCallback( async (): Promise< void > => {
		setIsLoading( true );
		setError( null );

		try {
			const formData = new FormData();
			formData.append( 'action', 'safe_publish_get_session_details' );
			formData.append( 'nonce', window.safePublishAdminData.nonce );
			formData.append( 'session_id', session.id.toString() );

			const response = await fetch( window.safePublishAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json() as ApiResponse< SessionDetailsData >;

			if ( result.success ) {
				setItems( result.data.items || [] );
			} else {
				setError( getErrorMessage( result, __( 'Failed to load session details.', 'safe-publish' ) ) );
			}
		} catch ( err ) {
			setError( __( 'Network error while loading session details.', 'safe-publish' ) );
		} finally {
			setIsLoading( false );
		}
	}, [ session.id ] );

	// Load session details on component mount.
	useEffect( () => {
		void loadSessionDetails();
	}, [ loadSessionDetails ] );

	/**
	 * Handles the rollback action.
	 *
	 * Calls the parent rollback handler and manages the loading state.
	 *
	 * @return {Promise<void>} Resolves when rollback is complete.
	 */
	const handleRollback = async (): Promise< void > => {
		setIsRollingBack( true );
		try {
			await onRollback( session.id );
		} finally {
			setIsRollingBack( false );
		}
	};

	/**
	 * Handles individual item rollback.
	 *
	 * Prompts for confirmation then sends a request to rollback a single
	 * imported post.
	 *
	 * @param {number} itemId Item ID to rollback.
	 * @param {string} title  Title of the post for the confirmation dialog.
	 * @return {Promise<void>} Resolves when rollback is complete.
	 */
	const handleItemRollback = async ( itemId: number, title: string ): Promise< void > => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm(
			/* translators: %s is the post title */
			__( 'Are you sure you want to rollback "%s"? This action cannot be undone.', 'safe-publish' ).replace( '%s', title )
		) ) {
			return;
		}

		setRollingBackItemId( itemId );

		try {
			const formData = new FormData();
			formData.append( 'action', 'safe_publish_rollback_item' );
			formData.append( 'nonce', window.safePublishAdminData.nonce );
			formData.append( 'item_id', itemId.toString() );

			const response = await fetch( window.safePublishAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json() as ApiResponse< RollbackItemData >;

			if ( result.success ) {
				// Show success message.
				const actionText = 'restored' === result.data.action ? __( 'restored', 'safe-publish' ) : __( 'deleted', 'safe-publish' );
				/* translators: %s is the action performed (restored or deleted) */
				setNoticeMessage( { type: 'success', message: __( 'Item successfully %s.', 'safe-publish' )
					.replace( '%s', actionText ) } );

				// Reload session details to update the UI.
				await loadSessionDetails();
			} else {
				/* translators: %s is the error message */
				setNoticeMessage( { type: 'error', message: __( 'Error: %s', 'safe-publish' )
					.replace( '%s', getErrorMessage( result, __( 'Unknown error', 'safe-publish' ) ) ) } );
			}
		} catch ( err ) {
			setNoticeMessage( { type: 'error', message: __( 'Network error while rolling back item.', 'safe-publish' ) } );
		} finally {
			setRollingBackItemId( null );
		}
	};

	/**
	 * Renders session statistics.
	 *
	 * Displays a summary of total, successful, failed, and updated items.
	 *
	 * @return {JSX.Element} Rendered statistics display.
	 */
	const renderSessionStats = (): JSX.Element => (
		<HStack spacing={ 4 } style={ { marginBottom: '16px' } }>
			<div className="safe-publish-stat">
				<Text>{ /* translators: %d is the total number of items */
				__( 'Total: %d', 'safe-publish' ).replace( '%d', session.total_items.toString() ) }</Text>
			</div>
			<div className="safe-publish-stat">
				<Text>{ /* translators: %d is the number of successful items */
				__( 'Successful: %d', 'safe-publish' ).replace( '%d', session.successful.toString() ) }</Text>
			</div>
			<div className="safe-publish-stat">
				<Text>{ /* translators: %d is the number of failed items */
				__( 'Failed: %d', 'safe-publish' ).replace( '%d', session.failed.toString() ) }</Text>
			</div>
			<div className="safe-publish-stat">
				<Text>{ /* translators: %d is the number of updated items */
				__( 'Updated: %d', 'safe-publish' ).replace( '%d', session.updated.toString() ) }</Text>
			</div>
		</HStack>
	);

	/**
	 * Renders session information.
	 *
	 * Displays the session date, user, source URL, and status.
	 *
	 * @return {JSX.Element} Rendered session info display.
	 */
	const renderSessionInfo = (): JSX.Element => (
		<VStack spacing={ 2 } style={ { marginBottom: '24px' } }>
			<Text><strong>{ __( 'Date:', 'safe-publish' ) }</strong> { session.date }</Text>
			<Text><strong>{ __( 'User:', 'safe-publish' ) }</strong> { session.user }</Text>
			<Text><strong>{ __( 'Source:', 'safe-publish' ) }</strong> { session.source_url }</Text>
			<Text>
				<strong>{ __( 'Status:', 'safe-publish' ) } </strong>
				<span className={ `safe-publish-status-${ session.status }` }>
					{ session.status_label }
				</span>
			</Text>
		</VStack>
	);

	/**
	 * Renders import items.
	 *
	 * Displays the list of individual import items with actions for
	 * viewing diffs and rolling back items.
	 *
	 * @return {JSX.Element} Rendered import items list.
	 */
	const renderImportItems = (): JSX.Element => {
		if ( 'rolled_back' === session.status ) {
			return (
				<div className="safe-publish-item-row">
					<Text><strong>{ __( 'This session has been rolled back.', 'safe-publish' ) }</strong></Text>
					<Text>{ __( 'All imported posts from this session have been deleted and are no longer available.', 'safe-publish' ) }</Text>
				</div>
			);
		}

		if ( 0 === items.length ) {
			return (
				<Text>{ __( 'No detailed items available for this session.', 'safe-publish' ) }</Text>
			);
		}

		return (
			<VStack spacing={ 2 }>
				{ items.map( ( item ) => (
					<div key={ item.id } className="safe-publish-item-row" style={ {
						background: '#fff',
						border: '1px solid #ddd',
						borderRadius: '4px',
						padding: '15px',
					} }>
						<VStack spacing={ 2 }>
							<HStack justify="space-between">
								<Text><strong>{ item.title }</strong></Text>
								<HStack spacing={ 2 }>
									{ item.post_id && item.edit_url && ! item.is_rolled_back && (
										<Button
											variant="secondary"
											size="compact"
											href={ item.edit_url }
											target="_blank"
										>
											{ __( 'Edit Post', 'safe-publish' ) }
										</Button>
									) }
									{ item.has_changes && item.post_id && ! item.is_rolled_back && (
										<Button
											variant="tertiary"
											size="compact"
											onClick={ () => item.post_id && onViewDiff( item.post_id ) }
										>
											{ __( 'View Changes', 'safe-publish' ) }
										</Button>
									) }
									{ item.can_rollback && ! item.is_rolled_back && (
										<Button
											variant="primary"
											isDestructive
											size="compact"
											onClick={ () => void handleItemRollback( item.id, item.title ) }
											disabled={ rollingBackItemId === item.id }
										>
										{ ( () => {
											if ( rollingBackItemId === item.id ) {
												return (
													<>
														<Spinner />
														{ __( 'Rolling back…', 'safe-publish' ) }
													</>
												);
											}

											if ( item.rollback_action === 'restore' ) {
												return __( 'Restore', 'safe-publish' );
											}

											return __( 'Delete', 'safe-publish' );
										} )() }
										</Button>
									) }
									{ item.is_rolled_back && (
										<Text style={ { color: '#d63638', fontWeight: 'bold' } }>
											{ __( 'Rolled Back', 'safe-publish' ) }
										</Text>
									) }
								</HStack>
							</HStack>
							<HStack spacing={ 2 }>
								<span className={ `safe-publish-status-${ item.status }` }>
									{ item.status_label }
								</span>
								<Text>{ /* translators: %s is the external ID of the imported item */
								__( 'External ID: %s', 'safe-publish' )
									.replace( '%s', String( item.external_id ) ) }</Text>
								{ item.error && (
									<>
										<Text>|</Text>
										<Text style={ { color: '#d63638' } }>
											{ /* translators: %s is the error message */
											__( 'Error: %s', 'safe-publish' ).replace( '%s', item.error ) }
										</Text>
									</>
								) }
							</HStack>
						</VStack>
					</div>
				) ) }
			</VStack>
		);
	};

	return (
		<VStack spacing={ 4 }>
			{ renderSessionStats() }
			{ renderSessionInfo() }

			{ noticeMessage && (
				<Notice
					status={ noticeMessage.type }
					onRemove={ () => setNoticeMessage( null ) }
				>
					{ noticeMessage.message }
				</Notice>
			) }

			{ session.can_rollback && (
				<HStack justify="left" style={ { marginBottom: '16px' } }>
					<Button
						variant="primary"
						isDestructive
						onClick={ () => void handleRollback() }
						disabled={ isRollingBack }
					>
						{ isRollingBack ? (
							<>
								<Spinner />
								{ __( 'Rolling back…', 'safe-publish' ) }
							</>
						) : (
							__( 'Rollback This Session', 'safe-publish' )
						) }
					</Button>
				</HStack>
			) }

			<VStack spacing={ 2 }>
				<Text as="h3">{ __( 'Import Details', 'safe-publish' ) }</Text>

				{ ( () => {
					if ( isLoading ) {
						return (
							<HStack>
								<Spinner />
								<Text>{ __( 'Loading session details…', 'safe-publish' ) }</Text>
							</HStack>
						);
					}

					if ( error ) {
						return (
							<Text style={ { color: '#d63638' } }>
								{ /* translators: %s is the error message */
								__( 'Error: %s', 'safe-publish' ).replace( '%s', error ) }
							</Text>
						);
					}

					return renderImportItems();
				} )() }
			</VStack>

			<HStack justify="right">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Close', 'safe-publish' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
