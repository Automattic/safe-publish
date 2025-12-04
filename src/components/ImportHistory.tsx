/**
 * Import History React component using DataViews.
 *
 * Displays a table of import sessions with details about each import operation,
 * including success rates and rollback capabilities.
 *
 * @file This file defines the ImportHistory component.
 */
import {
	Button,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	Spinner,
	Icon
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { close } from '@wordpress/icons';
import React from 'react';

import { SessionDetailsModal } from './SessionDetailsModal';
import { PostDiffModal } from './ImportHistoryPostDiffModal';
import type { ImportSession, ImportLog, DataViewsField } from '../types';

/**
 * Props for the ImportHistory component.
 *
 * Currently empty but reserved for future props passed from PHP.
 */
interface ImportHistoryProps {
	// Props can be passed from PHP if needed
}

/**
 * Import History component.
 *
 * Renders a DataViews table of import sessions with filtering, sorting, and the
 * ability to view session details or rollback imports.
 *
 * @param {Object} props Component props (currently unused).
 *
 * @return {JSX.Element} Rendered import history table.
 */
export function ImportHistory( {}: ImportHistoryProps ): JSX.Element {
	const [ sessions, setSessions ] = useState< ImportSession[] >( [] );
	const [ isLoading, setIsLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ selectedSession, setSelectedSession ] = useState< ImportSession | null >( null );
	const [ isSessionModalOpen, setIsSessionModalOpen ] = useState< boolean >( false );
	const [ isDiffModalOpen, setIsDiffModalOpen ] = useState< boolean >( false );
	const [ diffPostId, setDiffPostId ] = useState< number | null >( null );

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

	// Pagination info for DataViews
	const [ paginationInfo, setPaginationInfo ] = useState( {
		totalItems: 0,
		totalPages: 1,
	} );

	// Load import sessions on component mount
	useEffect( () => {
		loadImportSessions();
	}, [] );

	// Update pagination info when sessions change
	useEffect( () => {
		setPaginationInfo( {
			totalItems: sessions.length,
			totalPages: Math.ceil( sessions.length / ( view.perPage || 20 ) ),
		} );
	}, [ sessions, view.perPage ] );

	// Handle ESC key to close modals
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
			formData.append( 'action', 'ccp_get_import_sessions' );
			formData.append( 'nonce', window.ccpAdminData.nonce );

			const response = await fetch( window.ccpAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json();

			if ( result.success && result.data ) {
				setSessions( result.data );
			} else {
				setError( result.data || __( 'Failed to load import sessions.', 'ccp' ) );
			}
		} catch ( err ) {
			setError( __( 'Network error while loading import sessions.', 'ccp' ) );
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
		if ( ! confirm( __( 'Are you sure you want to rollback this import session? This will delete all newly created posts and restore updated posts to their previous version. This action cannot be undone.', 'ccp' ) ) ) {
			return;
		}

		try {
			const formData = new FormData();
			formData.append( 'action', 'ccp_rollback_session' );
			formData.append( 'nonce', window.ccpAdminData.nonce );
			formData.append( 'session_id', sessionId.toString() );

			const response = await fetch( window.ccpAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json();

			if ( result.success ) {
				const message = __( 'Session rolled back successfully. %1$d posts were deleted and %2$d posts were restored to their previous version.', 'ccp' )
					.replace( '%1$d', result.data.deleted_count.toString() )
					.replace( '%2$d', result.data.restored_count.toString() );
				alert( message );
				// Reload sessions and close modal
				await loadImportSessions();
				setIsSessionModalOpen( false );
				setSelectedSession( null );
			} else {
				alert( __( 'Error rolling back session: %s', 'ccp' ).replace( '%s', result.data ) );
			}
		} catch ( err ) {
			alert( __( 'Error rolling back session.', 'ccp' ) );
		}
	};

	/**
	 * Handle delete session
	 */
	const handleDeleteSession = async ( sessionId: number ): Promise< void > => {
		if ( ! confirm( __( 'Are you sure you want to delete this import session? This will permanently remove the session and all its associated log entries. This action cannot be undone.', 'ccp' ) ) ) {
			return;
		}

		try {
			const formData = new FormData();
			formData.append( 'action', 'ccp_delete_session' );
			formData.append( 'nonce', window.ccpAdminData.nonce );
			formData.append( 'session_id', sessionId.toString() );

			const response = await fetch( window.ccpAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json();

			if ( result.success ) {
				alert( __( 'Session deleted successfully. %s', 'ccp' ).replace( '%s', result.data.message ) );
				// Reload sessions and close modal if it was open
				await loadImportSessions();
				if ( isSessionModalOpen ) {
					setIsSessionModalOpen( false );
					setSelectedSession( null );
				}
			} else {
				alert( __( 'Error deleting session: %s', 'ccp' ).replace( '%s', result.data ) );
			}
		} catch ( err ) {
			alert( __( 'Error deleting session.', 'ccp' ) );
		}
	};

	/**
	 * Open session details modal
	 */
	const openSessionDetails = ( session: ImportSession ): void => {
		setSelectedSession( session );
		setIsSessionModalOpen( true );
	};

	/**
	 * Open diff modal for a specific post
	 */
	const openDiffModal = ( postId: number ): void => {
		setDiffPostId( postId );
		setIsDiffModalOpen( true );
	};

	// DataViews fields configuration
	const fields: DataViewsField[] = [
		{
			id: 'date',
			label: __( 'Date', 'ccp' ),
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
			label: __( 'User', 'ccp' ),
			enableSorting: true,
			render: ( { item }: { item: ImportSession } ): JSX.Element => (
				<span>{ item.user }</span>
			),
		},
		{
			id: 'items',
			label: __( 'Items', 'ccp' ),
			enableSorting: false,
			render: ( { item }: { item: ImportSession } ): JSX.Element => (
				<span>
					{ item.total_items } total
					({ item.successful } successful, { item.failed } failed
					{ item.updated > 0 && `, ${ item.updated } updated` })
				</span>
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'ccp' ),
			enableSorting: true,
			render: ( { item }: { item: ImportSession } ): JSX.Element => (
				<span className={ `ccp-status-${ item.status }` }>
					{ item.status_label }
				</span>
			),
		},
		{
			id: 'source',
			label: __( 'Source', 'ccp' ),
			enableSorting: false,
			render: ( { item }: { item: ImportSession } ): JSX.Element => (
				<span>{ item.source_url }</span>
			),
		},
	];

	// DataViews actions
	const actions = [
		{
			id: 'view-details',
			label: __( 'View Details', 'ccp' ),
			isPrimary: true,
			supportsBulk: false,
			callback: ( items: ImportSession[] ): void => {
				if ( items.length === 1 ) {
					openSessionDetails( items[0] );
				}
			},
		},
		{
			id: 'rollback',
			label: __( 'Rollback', 'ccp' ),
			isDestructive: true,
			supportsBulk: false,
			isEligible: ( item: ImportSession ): boolean => {
				return item.status === 'completed' && item.successful > 0;
			},
			callback: ( items: ImportSession[] ): void => {
				if ( items.length === 1 ) {
					handleRollbackSession( items[0].id );
				}
			},
		},
		{
			id: 'delete',
			label: __( 'Delete', 'ccp' ),
			isDestructive: true,
			supportsBulk: false,
			callback: ( items: ImportSession[] ): void => {
				if ( items.length === 1 ) {
					handleDeleteSession( items[0].id );
				}
			},
		},
	];

	if ( isLoading ) {
		return (
			<VStack>
				<HStack>
					<Spinner />
					<Text>{ __( 'Loading import sessions...', 'ccp' ) }</Text>
				</HStack>
			</VStack>
		);
	}

	if ( error ) {
		return (
			<VStack>
				<Text style={ { color: '#d63638' } }>
					{ __( 'Error: %s', 'ccp' ).replace( '%s', error ) }
				</Text>
				<Button variant="secondary" onClick={ loadImportSessions }>
					{ __( 'Retry', 'ccp' ) }
				</Button>
			</VStack>
		);
	}

	return (
		<div className="ccp-import-history">
			<VStack spacing={ 4 }>
				<Text as="h2">
					{ __( 'Import History', 'ccp' ) }
				</Text>
				<Text>
					{ __( 'Track and manage your content import sessions with detailed change logs and rollback capabilities.', 'ccp' ) }
				</Text>

				{ sessions.length === 0 ? (
					<Text>{ __( 'No import sessions found.', 'ccp' ) }</Text>
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
					className="ccp-modal-backdrop"
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
					onClick={ () => {
						setIsSessionModalOpen( false );
						setSelectedSession( null );
					} }
				>
					<div
						className="ccp-modal-content components-modal__frame"
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
						onClick={ (e) => e.stopPropagation() }
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
								{ __( 'Session Details', 'ccp' ) }
							</h1>
							<Button
								variant="tertiary"
								onClick={ () => {
									setIsSessionModalOpen( false );
									setSelectedSession( null );
								} }
								aria-label={ __( 'Close dialog', 'ccp' ) }
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
					className="ccp-modal-backdrop"
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
					onClick={ () => {
						setIsDiffModalOpen( false );
						setDiffPostId( null );
					} }
				>
					<div
						className="ccp-modal-content components-modal__frame"
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
						onClick={ (e) => e.stopPropagation() }
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
								{ __( 'Content Changes', 'ccp' ) }
							</h1>
							<Button
								variant="tertiary"
								onClick={ () => {
									setIsDiffModalOpen( false );
									setDiffPostId( null );
								} }
								aria-label={ __( 'Close dialog', 'ccp' ) }
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
