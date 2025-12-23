/**
 * Session Details Modal Component.
 *
 * Displays detailed information about an import session including individual
 * log entries with rollback and diff viewing capabilities.
 *
 * @file This file defines the SessionDetailsModal component.
 */
import {
	Button,
	__experimentalHStack as HStack,
	Spinner,
	__experimentalText as Text,
	__experimentalVStack as VStack
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { ImportLog, ImportSession } from '../types';

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
 * Displays session statistics, individual import logs, and provides actions for
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
	const [ logs, setLogs ] = useState< ImportLog[] >( [] );
	const [ isLoading, setIsLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ isRollingBack, setIsRollingBack ] = useState< boolean >( false );
	const [ rollingBackItemId, setRollingBackItemId ] = useState< number | null >( null );

	// Load session details on component mount.
	useEffect( () => {
		loadSessionDetails();
	}, [ session.id ] );

	/**
	 * Loads session details and logs.
	 *
	 * Fetches the detailed log entries for the current session from the
	 * WordPress AJAX endpoint.
	 *
	 * @return {Promise<void>} Resolves when details are loaded.
	 */
	const loadSessionDetails = async (): Promise< void > => {
		setIsLoading( true );
		setError( null );

		try {
			const formData = new FormData();
			formData.append( 'action', 'ccp_get_session_details' );
			formData.append( 'nonce', window.ccpAdminData.nonce );
			formData.append( 'session_id', session.id.toString() );

			const response = await fetch( window.ccpAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json();

			if ( result.success && result.data ) {
				setLogs( result.data.logs || [] );
			} else {
				setError( result.data || __( 'Failed to load session details.', 'ccp' ) );
			}
		} catch ( err ) {
			setError( __( 'Network error while loading session details.', 'ccp' ) );
		} finally {
			setIsLoading( false );
		}
	};

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
	 * @param {number} logId Log ID of the item to rollback.
	 * @param {string} title Title of the post for the confirmation dialog.
	 * @return {Promise<void>} Resolves when rollback is complete.
	 */
	const handleItemRollback = async ( logId: number, title: string ): Promise< void > => {
		if ( ! window.confirm(
			__( 'Are you sure you want to rollback "%s"? This action cannot be undone.', 'ccp' ).replace( '%s', title )
		) ) {
			return;
		}

		setRollingBackItemId( logId );

		try {
			const formData = new FormData();
			formData.append( 'action', 'ccp_rollback_item' );
			formData.append( 'nonce', window.ccpAdminData.nonce );
			formData.append( 'log_id', logId.toString() );

			const response = await fetch( window.ccpAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json();

			if ( result.success ) {
				// Show success message.
				const actionText = result.data.action === 'restored' ? __( 'restored', 'ccp' ) : __( 'deleted', 'ccp' );
				alert( __( 'Item successfully %s.', 'ccp' ).replace( '%s', actionText ) );

				// Reload session details to update the UI.
				await loadSessionDetails();
			} else {
				alert( __( 'Error: %s', 'ccp' ).replace( '%s', result.data || __( 'Unknown error', 'ccp' ) ) );
			}
		} catch ( err ) {
			alert( __( 'Network error while rolling back item.', 'ccp' ) );
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
			<div className="ccp-stat">
				<Text>{ __( 'Total: %d', 'ccp' ).replace( '%d', session.total_items.toString() ) }</Text>
			</div>
			<div className="ccp-stat">
				<Text>{ __( 'Successful: %d', 'ccp' ).replace( '%d', session.successful.toString() ) }</Text>
			</div>
			<div className="ccp-stat">
				<Text>{ __( 'Failed: %d', 'ccp' ).replace( '%d', session.failed.toString() ) }</Text>
			</div>
			<div className="ccp-stat">
				<Text>{ __( 'Updated: %d', 'ccp' ).replace( '%d', session.updated.toString() ) }</Text>
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
			<Text><strong>{ __( 'Date:', 'ccp' ) }</strong> { session.date }</Text>
			<Text><strong>{ __( 'User:', 'ccp' ) }</strong> { session.user }</Text>
			<Text><strong>{ __( 'Source:', 'ccp' ) }</strong> { session.source_url }</Text>
			<Text>
				<strong>{ __( 'Status:', 'ccp' ) } </strong>
				<span className={ `ccp-status-${ session.status }` }>
					{ session.status_label }
				</span>
			</Text>
		</VStack>
	);

	/**
	 * Renders import logs.
	 *
	 * Displays the list of individual import log entries with actions for
	 * viewing diffs and rolling back items.
	 *
	 * @return {JSX.Element} Rendered import logs list.
	 */
	const renderImportLogs = (): JSX.Element => {
		if ( session.status === 'rolled_back' ) {
			return (
				<div className="ccp-log-item">
					<Text><strong>{ __( 'This session has been rolled back.', 'ccp' ) }</strong></Text>
					<Text>{ __( 'All imported posts from this session have been deleted and are no longer available.', 'ccp' ) }</Text>
				</div>
			);
		}

		if ( logs.length === 0 ) {
			return (
				<Text>{ __( 'No detailed logs available for this session.', 'ccp' ) }</Text>
			);
		}

		return (
			<VStack spacing={ 2 }>
				{ logs.map( ( log ) => (
					<div key={ log.id } className="ccp-log-item" style={ {
						background: '#fff',
						border: '1px solid #ddd',
						borderRadius: '4px',
						padding: '15px',
					} }>
						<VStack spacing={ 2 }>
							<HStack justify="space-between">
								<Text><strong>{ log.title }</strong></Text>
								<HStack spacing={ 2 }>
									{ log.post_id && log.edit_url && ! log.is_rolled_back && (
										<Button
											variant="secondary"
											size="compact"
											href={ log.edit_url }
											target="_blank"
										>
											{ __( 'Edit Post', 'ccp' ) }
										</Button>
									) }
									{ log.has_changes && log.post_id && ! log.is_rolled_back && (
										<Button
											variant="tertiary"
											size="compact"
											onClick={ () => onViewDiff( log.post_id! ) }
										>
											{ __( 'View Changes', 'ccp' ) }
										</Button>
									) }
									{ log.can_rollback && ! log.is_rolled_back && (
										<Button
											variant="primary"
											isDestructive
											size="compact"
											onClick={ () => handleItemRollback( log.id, log.title ) }
											disabled={ rollingBackItemId === log.id }
										>
											{ rollingBackItemId === log.id ? (
												<>
													<Spinner />
													{ __( 'Rolling back…', 'ccp' ) }
												</>
											) : (
												log.rollback_action === 'restore'
													? __( 'Restore', 'ccp' )
													: __( 'Delete', 'ccp' )
											) }
										</Button>
									) }
									{ log.is_rolled_back && (
										<Text style={ { color: '#d63638', fontWeight: 'bold' } }>
											{ __( 'Rolled Back', 'ccp' ) }
										</Text>
									) }
								</HStack>
							</HStack>
							<HStack spacing={ 2 }>
								<span className={ `ccp-status-${ log.status }` }>
									{ log.status_label }
								</span>
								<Text>{ __( 'External ID: %s', 'ccp' ).replace( '%s', log.external_id ) }</Text>
								{ log.error && (
									<>
										<Text>|</Text>
										<Text style={ { color: '#d63638' } }>
											{ __( 'Error: %s', 'ccp' ).replace( '%s', log.error ) }
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

			{ session.can_rollback && (
				<HStack justify="left" style={ { marginBottom: '16px' } }>
					<Button
						variant="primary"
						isDestructive
						onClick={ handleRollback }
						disabled={ isRollingBack }
					>
						{ isRollingBack ? (
							<>
								<Spinner />
								{ __( 'Rolling back…', 'ccp' ) }
							</>
						) : (
							__( 'Rollback This Session', 'ccp' )
						) }
					</Button>
				</HStack>
			) }

			<VStack spacing={ 2 }>
				<Text as="h3">{ __( 'Import Details', 'ccp' ) }</Text>

				{ isLoading ? (
					<HStack>
						<Spinner />
						<Text>{ __( 'Loading session details…', 'ccp' ) }</Text>
					</HStack>
				) : error ? (
					<Text style={ { color: '#d63638' } }>
						{ __( 'Error: %s', 'ccp' ).replace( '%s', error ) }
					</Text>
				) : (
					renderImportLogs()
				) }
			</VStack>

			<HStack justify="right">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Close', 'ccp' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
