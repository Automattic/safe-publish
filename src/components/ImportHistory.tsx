/**
 * Import History React component using DataViews.
 *
 * Displays a table of import sessions with details about each import operation,
 * including success rates and rollback capabilities.
 *
 * @file This file defines the ImportHistory component.
 */
import { close } from '@wordpress/icons';

import { PostDiffModal } from './ImportHistoryPostDiffModal';
import { SessionDetailsModal } from './SessionDetailsModal';
import { getErrorMessage } from '../utils';
import {
	Button,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	Spinner,
	Icon,
	Notice
} from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	DataViewsField,
	DeleteSessionData,
	ImportSession,
	RollbackSessionData,
} from '../types';

/**
 * Import History component.
 *
 * Renders a DataViews table of import sessions with filtering, sorting, and the
 * ability to view session details or rollback imports.
 *
 * @return {JSX.Element} Rendered import history table.
 */
export function ImportHistory(): JSX.Element {
	const [ sessions, setSessions ] = useState< ImportSession[] >( [] );
	const [ isLoading, setIsLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ selectedSession, setSelectedSession ] = useState< ImportSession | null >( null );
	const [ isSessionModalOpen, setIsSessionModalOpen ] = useState< boolean >( false );
	const [ isDiffModalOpen, setIsDiffModalOpen ] = useState< boolean >( false );
	const [ diffPostId, setDiffPostId ] = useState< number | null >( null );
	const [ noticeMessage, setNoticeMessage ] = useState< { type: 'success' | 'error'; message: string } | null >( null );

	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: 20,
		page: 1,
		sort: {
			field: 'date',
			direction: 'desc',
		},
		search: '',
		filters: [],
		fields: [ 'date', 'user', 'items', 'status', 'source' ],
	} );

	// Pagination info for DataViews.
	const [ paginationInfo, setPaginationInfo ] = useState( {
		totalItems: 0,
		totalPages: 1,
	} );

	// Load import sessions on component mount.
	useEffect( () => {
		void loadImportSessions();
	}, [] );

	// Update pagination info when sessions change.
	useEffect( () => {
		setPaginationInfo( {
			totalItems: sessions.length,
			totalPages: Math.ceil( sessions.length / ( view.perPage || 20 ) ),
		} );
	}, [ sessions, view.perPage ] );

	// Handle ESC key to close modals.
	useEffect( () => {
		const handleEscKey = ( event: KeyboardEvent ) => {
			if ( event.key === 'Escape' ) {
				if ( isSessionModalOpen ) {
					setIsSessionModalOpen( false );
					setSelectedSession( null );
				} else if ( isDiffModalOpen ) {
					setIsDiffModalOpen( false );
					setDiffPostId( null );
				}
			}
		};

		if ( isSessionModalOpen || isDiffModalOpen ) {
			document.addEventListener( 'keydown', handleEscKey );
			return () => document.removeEventListener( 'keydown', handleEscKey );
		}
	}, [ isSessionModalOpen, isDiffModalOpen ] );

	/**
	 * Loads import sessions from the backend.
	 *
	 * Fetches the list of all import sessions from the WordPress AJAX endpoint
	 * and updates the component state.
	 *
	 * @return {Promise<void>} Resolves when sessions are loaded.
	 */
	const loadImportSessions = async (): Promise< void > => {
		setIsLoading( true );
		setError( null );

		try {
			const formData = new FormData();
			formData.append( 'action', 'safe_publish_get_import_sessions' );
			formData.append( 'nonce', window.safePublishAdminData.nonce );

			const response = await fetch( window.safePublishAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json() as ApiResponse< ImportSession[] >;

			if ( result.success ) {
				setSessions( result.data );
			} else {
				setError( getErrorMessage( result, __( 'Failed to load import sessions.', 'safe-publish' ) ) );
			}
		} catch ( err ) {
			setError( __( 'Network error while loading import sessions.', 'safe-publish' ) );
		} finally {
			setIsLoading( false );
		}
	};

	/**
	 * Handles rollback of an import session.
	 *
	 * Prompts for confirmation then sends a request to rollback all posts from
	 * the specified import session.
	 *
	 * @param {number} sessionId ID of the session to rollback.
	 * @return {Promise<void>} Resolves when rollback is complete.
	 */
	const handleRollbackSession = async ( sessionId: number ): Promise< void > => {
		// eslint-disable-next-line no-alert
		if ( ! confirm( __( 'Are you sure you want to rollback this import session? This will delete all newly created posts and restore updated posts to their previous version. This action cannot be undone.', 'safe-publish' ) ) ) {
			return;
		}

		try {
			const formData = new FormData();
			formData.append( 'action', 'safe_publish_rollback_session' );
			formData.append( 'nonce', window.safePublishAdminData.nonce );
			formData.append( 'session_id', sessionId.toString() );

			const response = await fetch( window.safePublishAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json() as ApiResponse< RollbackSessionData >;

			if ( result.success ) {
				const {
					deleted_count: deletedCount,
					restored_count: restoredCount,
					failed_count: failedCount,
				} = result.data;

				if ( typeof deletedCount !== 'number' || typeof restoredCount !== 'number' ) {
					setNoticeMessage( { type: 'error', message: __( 'Invalid rollback response from server.', 'safe-publish' ) } );
					return;
				}

				let message: string;
				let noticeType: 'success' | 'error';

				if ( failedCount > 0 ) {
					message = sprintf(
						/* translators: %1$d: deleted count, %2$d: restored count, %3$d: failed count */
						__( 'Partial rollback: %1$d posts deleted, %2$d restored. %3$d items failed to roll back.', 'safe-publish' ),
						deletedCount, restoredCount, failedCount
					);
					noticeType = 'error';
				} else {
					message = sprintf(
						/* translators: %1$d is the number of deleted posts, %2$d is the number of restored posts */
						__( 'Session rolled back successfully. %1$d posts were deleted and %2$d posts were restored to their previous version.', 'safe-publish' ),
						deletedCount, restoredCount
					);
					noticeType = 'success';
				}

				setNoticeMessage( { type: noticeType, message } );
				// Reload sessions and close modal.
				await loadImportSessions();
				setIsSessionModalOpen( false );
				setSelectedSession( null );
			} else {
				/* translators: %s is the error message */
				setNoticeMessage( { type: 'error', message: __( 'Error rolling back session: %s', 'safe-publish' )
					.replace( '%s', getErrorMessage( result, '' ) ) } );
			}
		} catch ( err ) {
			setNoticeMessage( { type: 'error', message: __( 'Error rolling back session.', 'safe-publish' ) } );
		}
	};

	/**
	 * Handles deleting an import session.
	 *
	 * Prompts for confirmation, then permanently removes the session and all
	 * associated log entries.
	 *
	 * @param {number} sessionId ID of the session to delete.
	 *
	 * @return {Promise<void>} Resolves when deletion completes.
	 */
	const handleDeleteSession = async ( sessionId: number ): Promise< void > => {
		// eslint-disable-next-line no-alert
		if ( ! confirm( __( 'Are you sure you want to delete this import session? This will permanently remove the session and all its associated log entries. This action cannot be undone.', 'safe-publish' ) ) ) {
			return;
		}

		try {
			const formData = new FormData();
			formData.append( 'action', 'safe_publish_delete_session' );
			formData.append( 'nonce', window.safePublishAdminData.nonce );
			formData.append( 'session_id', sessionId.toString() );

			const response = await fetch( window.safePublishAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json() as ApiResponse< DeleteSessionData >;

			if ( result.success ) {
				const message = result.data.message || '';

				/* translators: %s is the success message from the server */
				setNoticeMessage( { type: 'success', message: __( 'Session deleted successfully. %s', 'safe-publish' )
					.replace( '%s', message ) } );
				// Reload sessions and close modal if it was open.
				await loadImportSessions();
				if ( isSessionModalOpen ) {
					setIsSessionModalOpen( false );
					setSelectedSession( null );
				}
			} else {
				/* translators: %s is the error message */
				setNoticeMessage( { type: 'error', message: __( 'Error deleting session: %s', 'safe-publish' )
					.replace( '%s', getErrorMessage( result, '' ) ) } );
			}
		} catch ( err ) {
			setNoticeMessage( { type: 'error', message: __( 'Error deleting session.', 'safe-publish' ) } );
		}
	};

	/**
	 * Opens the session details modal.
	 *
	 * @param {ImportSession} session Session to display details for.
	 */
	const openSessionDetails = ( session: ImportSession ): void => {
		setSelectedSession( session );
		setIsSessionModalOpen( true );
	};

	/**
	 * Opens the diff modal for a specific post.
	 *
	 * @param {number} postId ID of the post to show diff for.
	 */
	const openDiffModal = ( postId: number ): void => {
		setDiffPostId( postId );
		setIsDiffModalOpen( true );
	};

	// DataViews fields configuration.
	const fields: DataViewsField<ImportSession>[] = [
		{
			id: 'date',
			label: __( 'Date', 'safe-publish' ),
			enableSorting: true,
			render: ( { item }: { item: ImportSession } ): JSX.Element => (
				<Button
					variant="link"
					onClick={ () => openSessionDetails( item ) }
					style={ { padding: 0, height: 'auto', textDecoration: 'underline' } }
				>
					{ item.date }
				</Button>
			),
		},
		{
			id: 'user',
			label: __( 'User', 'safe-publish' ),
			enableSorting: true,
			render: ( { item }: { item: ImportSession } ): JSX.Element => (
				<span>{ item.user }</span>
			),
		},
		{
			id: 'items',
			label: __( 'Items', 'safe-publish' ),
			enableSorting: false,
			render: ( { item }: { item: ImportSession } ): JSX.Element => (
				<span>
					{ /* translators: %d is the total number of items */
					__( '%d total', 'safe-publish' ).replace( '%d', item.total_items.toString() ) }
					({ /* translators: 1: number of successful items, 2: number of failed items */
					sprintf( __( '%1$d successful, %2$d failed', 'safe-publish' ), item.successful, item.failed ) }
					{ item.updated > 0 && (
						/* translators: %d is the number of updated items */
						sprintf( __( ', %d updated', 'safe-publish' ), item.updated )
					) })
				</span>
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'safe-publish' ),
			enableSorting: true,
			render: ( { item }: { item: ImportSession } ): JSX.Element => (
				<span className={ `safe-publish-status-${ item.status }` }>
					{ item.status_label }
				</span>
			),
		},
		{
			id: 'source',
			label: __( 'Source', 'safe-publish' ),
			enableSorting: false,
			render: ( { item }: { item: ImportSession } ): JSX.Element => (
				<span>{ item.source_url }</span>
			),
		},
	];

	// DataViews actions.
	const actions = [
		{
			id: 'view-details',
			label: __( 'View Details', 'safe-publish' ),
			isPrimary: true,
			supportsBulk: false,
			callback: ( items: ImportSession[] ): void => {
				if ( 1 === items.length ) {
					openSessionDetails( items[0] );
				}
			},
		},
		{
			id: 'rollback',
			label: __( 'Rollback', 'safe-publish' ),
			isDestructive: true,
			supportsBulk: false,
			isEligible: ( item: ImportSession ): boolean => {
				return 'completed' === item.status && item.successful > 0;
			},
			callback: ( items: ImportSession[] ): void => {
				if ( 1 === items.length ) {
					void handleRollbackSession( items[0].id );
				}
			},
		},
		{
			id: 'delete',
			label: __( 'Delete', 'safe-publish' ),
			isDestructive: true,
			supportsBulk: false,
			callback: ( items: ImportSession[] ): void => {
				if ( 1 === items.length ) {
					void handleDeleteSession( items[0].id );
				}
			},
		},
	];

	if ( isLoading ) {
		return (
			<VStack>
				<HStack>
					<Spinner />
					<Text>{ __( 'Loading import sessions…', 'safe-publish' ) }</Text>
				</HStack>
			</VStack>
		);
	}

	if ( error ) {
		return (
			<VStack>
				<Text style={ { color: '#d63638' } }>
					{ /* translators: %s is the error message */
					__( 'Error: %s', 'safe-publish' ).replace( '%s', error ) }
				</Text>
				<Button variant="secondary" onClick={ () => void loadImportSessions() }>
					{ __( 'Retry', 'safe-publish' ) }
				</Button>
			</VStack>
		);
	}

	return (
		<div className="safe-publish-history">
			<VStack spacing={ 4 }>
				<Text as="h2">
					{ __( 'Import History', 'safe-publish' ) }
				</Text>
				<Text>
					{ __( 'Track and manage your content import sessions with detailed change logs and rollback capabilities.', 'safe-publish' ) }
				</Text>

				{ noticeMessage && (
					<Notice
						status={ noticeMessage.type }
						onRemove={ () => setNoticeMessage( null ) }
					>
						{ noticeMessage.message }
					</Notice>
				) }

				{ sessions.length === 0 ? (
					<Text>{ __( 'No import sessions found.', 'safe-publish' ) }</Text>
				) : (
					<DataViews
						data={ sessions }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						actions={ actions }
						getItemId={ ( item: ImportSession ) => item.id.toString() }
						paginationInfo={ paginationInfo }
						defaultLayouts={ {
							table: {},
							list: {},
						} }
					/>
				) }
			</VStack>

			{/* Session Details Modal */}
			{ isSessionModalOpen && selectedSession && (
				<div
					className="safe-publish-modal-backdrop"
					style={ {
						position: 'fixed',
						top: 0,
						left: 0,
						right: 0,
						bottom: 0,
						backgroundColor: 'rgba(0,0,0,0.5)',
						zIndex: 100001,
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center'
					} }
					role="button"
					tabIndex={ 0 }
					onClick={ () => {
						setIsSessionModalOpen( false );
						setSelectedSession( null );
					} }
					onKeyDown={ (event) => {
						if ( event.key === 'Escape' || event.key === 'Enter' || event.key === ' ' ) {
							event.preventDefault();
							setIsSessionModalOpen( false );
							setSelectedSession( null );
						}
					} }
					aria-label={ __( 'Close modal', 'safe-publish' ) }
				>
					<div
						className="safe-publish-modal-content components-modal__frame"
						style={ {
							backgroundColor: 'white',
							borderRadius: '2px',
							maxWidth: '800px',
							maxHeight: '80vh',
							overflow: 'hidden',
							width: '90%',
							boxShadow: '0 3px 30px rgba(25,30,35,.1)',
							display: 'flex',
							flexDirection: 'column'
						} }
						role="dialog"
						aria-modal="true"
						aria-labelledby="session-details-title"
					>
						{/* Modal Header */}
						<div
							className="components-modal__header"
							style={ {
								display: 'flex',
								justifyContent: 'space-between',
								alignItems: 'center',
								padding: '16px 24px',
								borderBottom: '1px solid #e0e0e0',
								minHeight: '56px'
							} }
						>
							<h1 className="components-modal__header-heading">
								{ __( 'Session Details', 'safe-publish' ) }
							</h1>
							<Button
								variant="tertiary"
								onClick={ () => {
									setIsSessionModalOpen( false );
									setSelectedSession( null );
								} }
								aria-label={ __( 'Close dialog', 'safe-publish' ) }
								style={ { minWidth: '36px', width: '36px', height: '36px' } }
							>
								<Icon icon={ close } size={ 18 } />
							</Button>
						</div>

						{/* Modal Content */}
						<div
							className="components-modal__content"
							style={ {
								flex: 1,
								overflow: 'auto',
								padding: '24px'
							} }
						>
							<SessionDetailsModal
								session={ selectedSession }
								onRollback={ handleRollbackSession }
								onViewDiff={ openDiffModal }
								onClose={ () => {
									setIsSessionModalOpen( false );
									setSelectedSession( null );
								} }
							/>
						</div>
					</div>
				</div>
			) }

			{/* Post Diff Modal */}
			{ isDiffModalOpen && diffPostId && (
				<div
					className="safe-publish-modal-backdrop"
					style={ {
						position: 'fixed',
						top: 0,
						left: 0,
						right: 0,
						bottom: 0,
						backgroundColor: 'rgba(0,0,0,0.5)',
						zIndex: 100001,
						display: 'flex',
						alignItems: 'center',
						justifyContent: 'center'
					} }
					role="button"
					tabIndex={ 0 }
					onClick={ () => {
						setIsDiffModalOpen( false );
						setDiffPostId( null );
					} }
					onKeyDown={ (event) => {
						if ( event.key === 'Escape' || event.key === 'Enter' || event.key === ' ' ) {
							event.preventDefault();
							setIsDiffModalOpen( false );
							setDiffPostId( null );
						}
					} }
					aria-label={ __( 'Close modal', 'safe-publish' ) }
				>
					<div
						className="safe-publish-modal-content components-modal__frame"
						style={ {
							backgroundColor: 'white',
							borderRadius: '2px',
							maxWidth: '900px',
							maxHeight: '80vh',
							overflow: 'hidden',
							width: '90%',
							boxShadow: '0 3px 30px rgba(25,30,35,.1)',
							display: 'flex',
							flexDirection: 'column'
						} }
						role="dialog"
						aria-modal="true"
						aria-labelledby="post-diff-title"

					>
						{/* Modal Header */}
						<div
							className="components-modal__header"
							style={ {
								display: 'flex',
								justifyContent: 'space-between',
								alignItems: 'center',
								padding: '16px 24px',
								borderBottom: '1px solid #e0e0e0',
								minHeight: '56px'
							} }
						>
							<h1 className="components-modal__header-heading">
								{ __( 'Content Changes', 'safe-publish' ) }
							</h1>
							<Button
								variant="tertiary"
								onClick={ () => {
									setIsDiffModalOpen( false );
									setDiffPostId( null );
								} }
								aria-label={ __( 'Close dialog', 'safe-publish' ) }
								style={ { minWidth: '36px', width: '36px', height: '36px' } }
							>
								<Icon icon={ close } size={ 18 } />
							</Button>
						</div>

						{/* Modal Content */}
						<div
							className="components-modal__content"
							style={ {
								flex: 1,
								overflow: 'auto',
								padding: '24px'
							} }
						>
							<PostDiffModal
								postId={ diffPostId }
								onClose={ () => {
									setIsDiffModalOpen( false );
									setDiffPostId( null );
								} }
							/>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}
