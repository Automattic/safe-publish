/**
 * Side drawer that lists orphan failures (errors with no source_post_id)
 * since they can't fold under a unified Posts row.
 *
 * @file This file defines the OrphanFailuresDrawer component.
 */
import { update } from '@wordpress/icons';

import { useStepBackWhenPageEmpties } from './hooks/useStepBackWhenPageEmpties';
import { createOrphanFailuresActions } from '../actions';
import { DEFAULT_ITEMS_PER_PAGE, LAYOUT_TABLE } from '../constants';
import { formatDateTime, getErrorMessage } from '../utils';
import {
	Button,
	Modal,
	Notice,
	Spinner,
} from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	DataViewsField,
	OrphanFailure,
	OrphanFailuresResponse,
} from '../types';

/**
 * Props for the OrphanFailuresDrawer component.
 */
interface OrphanFailuresDrawerProps {
	ajaxurl: string;
	nonce: string;
	onClose: () => void;
	onRemoved?: () => void;
}

/**
 * Side drawer that lists orphan failures and supports Remove.
 *
 * @param {OrphanFailuresDrawerProps} props Component props.
 */
const OrphanFailuresDrawer = ( {
	ajaxurl,
	nonce,
	onClose,
	onRemoved,
}: OrphanFailuresDrawerProps ): JSX.Element => {
	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: DEFAULT_ITEMS_PER_PAGE,
		page: 1,
		fields: [ 'import_date_gmt', 'error_message' ],
		titleField: 'title',
		layout: { density: 'compact' },
	} );

	const [ items, setItems ] = useState< OrphanFailure[] >( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ hasFetchedOnce, setHasFetchedOnce ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ refreshNonce, setRefreshNonce ] = useState( 0 );

	const refresh = useCallback( () => {
		setRefreshNonce( ( previous ) => previous + 1 );
		onRemoved?.();
	}, [ onRemoved ] );

	useEffect( () => {
		const controller = new AbortController();

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_list_orphan_failures' );
		formData.append( 'nonce', nonce );
		formData.append( 'page', String( view.page ?? 1 ) );
		formData.append( 'per_page', String( view.perPage ?? DEFAULT_ITEMS_PER_PAGE ) );

		setIsLoading( true );
		setError( null );

		fetch( ajaxurl, {
			method: 'POST',
			body: formData,
			signal: controller.signal,
		} )
			.then(
				( response ) =>
					response.json() as Promise< ApiResponse< OrphanFailuresResponse > >
			)
			.then( ( result ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( result.success ) {
					setItems( result.data.items );
					setHasMore( Boolean( result.data.has_more ) );
				} else {
					setError(
						getErrorMessage(
							result,
							__( 'Failed to load orphan failures.', 'safe-publish' )
						)
					);
					setItems( [] );
					setHasMore( false );
				}
			} )
			.catch( ( err: unknown ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( err instanceof DOMException && 'AbortError' === err.name ) {
					return;
				}
				setError(
					__( 'Network error while loading orphan failures.', 'safe-publish' )
				);
				setItems( [] );
				setHasMore( false );
			} )
			.finally( () => {
				if ( controller.signal.aborted ) {
					return;
				}
				setIsLoading( false );
				setHasFetchedOnce( true );
			} );

		return () => controller.abort();
	}, [ ajaxurl, nonce, view.page, view.perPage, refreshNonce ] );

	const setPage = useCallback(
		( next: number ): void =>
			setView( ( current ) => ( { ...current, page: next } ) ),
		[]
	);

	useStepBackWhenPageEmpties( {
		hasFetchedOnce,
		isLoading,
		fetchError: error,
		isEmpty: 0 === items.length,
		page: view.page,
		setPage,
	} );

	const fields: DataViewsField< OrphanFailure >[] = [
		{
			id: 'title',
			label: __( 'Title', 'safe-publish' ),
			enableSorting: false,
			render: ( { item } ) => <span>{ item.title }</span>,
		},
		{
			id: 'error_message',
			label: __( 'Error', 'safe-publish' ),
			enableSorting: false,
			render: ( { item } ) => (
				<span title={ item.error_message }>{ item.error_message }</span>
			),
		},
		{
			id: 'import_date_gmt',
			label: __( 'Attempted', 'safe-publish' ),
			enableSorting: false,
			getValue: ( { item } ) => item.import_date_gmt,
			render: ( { item } ) => (
				<span>{ formatDateTime( item.import_date_gmt ) }</span>
			),
		},
	];

	const currentPage = view.page ?? 1;
	const currentPerPage = view.perPage ?? DEFAULT_ITEMS_PER_PAGE;
	const paginationInfo = ! hasMore
		? {
				totalItems: ( currentPage - 1 ) * currentPerPage + items.length,
				totalPages: currentPage,
		  }
		: {
				totalItems: currentPage * currentPerPage + 1,
				totalPages: currentPage + 1,
		  };

	const pageStatusText = sprintf(
		/* translators: %d: current page number */
		__( 'Page %d', 'safe-publish' ),
		currentPage
	);

	const actions = createOrphanFailuresActions( refresh, { ajaxurl, nonce } );

	return (
		<Modal
			title={ __( 'Orphan failures', 'safe-publish' ) }
			onRequestClose={ onClose }
			className="safe-publish-orphan-failures-drawer"
			size="fill"
			__experimentalHideHeader={ false }
		>
			<div
				className="safe-publish-dataviews-wrapper safe-publish-dataviews-wrapper--approx-pagination"
				style={
					{
						'--safe-publish-page-text': `"${ pageStatusText }"`,
					} as React.CSSProperties
				}
			>
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
				{ isLoading && ! hasFetchedOnce && (
					<div className="safe-publish-loading" role="status" aria-live="polite">
						<Spinner />
						<p>{ __( 'Loading…', 'safe-publish' ) }</p>
					</div>
				) }
				{ hasFetchedOnce && ! error && 0 === items.length && ! isLoading && (
					<div className="safe-publish-no-data" role="status">
						<p>{ __( 'No orphan failures.', 'safe-publish' ) }</p>
					</div>
				) }
				{ hasFetchedOnce && items.length > 0 && (
					<DataViews
						getItemId={ ( item: OrphanFailure ) => String( item.id ) }
						data={ items }
						fields={ fields }
						view={ view }
						onChangeView={ ( next: View ) => setView( next ) }
						paginationInfo={ paginationInfo }
						defaultLayouts={ { [ LAYOUT_TABLE ]: {} } }
						actions={ actions }
						header={
							<Button
								className="safe-publish-refresh-button"
								icon={ update }
								aria-busy={ isLoading }
								disabled={ isLoading }
								label={ __( 'Refresh', 'safe-publish' ) }
								onClick={ refresh }
							/>
						}
					/>
				) }
			</div>
		</Modal>
	);
};

export default OrphanFailuresDrawer;
