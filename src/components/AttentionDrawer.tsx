/**
 * Side drawer that lists open degradation issues for the connected source and
 * offers a self-verifying Retry where the fixup is callable.
 *
 * @file This file defines the AttentionDrawer component.
 */
import { createAttentionIssueActions, type RetryNotice } from '../actions';
import { DEFAULT_ITEMS_PER_PAGE, LAYOUT_TABLE } from '../constants';
import {
	attentionIssueId,
	formatDateTime,
	getErrorMessage,
	renderIssueMessage,
} from '../utils';
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';


import type {
	ApiResponse,
	AttentionIssue,
	AttentionIssuesResponse,
	DataViewsField,
} from '../types';

/**
 * Props for the AttentionDrawer component.
 */
interface AttentionDrawerProps {
	ajaxurl: string;
	nonce: string;
	onClose: () => void;
	onChanged?: () => void;
}

/**
 * Side drawer that lists open attention issues and supports Retry.
 *
 * @param {AttentionDrawerProps} props Component props.
 */
const AttentionDrawer = ( {
	ajaxurl,
	nonce,
	onClose,
	onChanged,
}: AttentionDrawerProps ): JSX.Element => {
	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: DEFAULT_ITEMS_PER_PAGE,
		page: 1,
		sort: { field: 'first_detected_gmt', direction: 'desc' },
		fields: [ 'message', 'severity', 'source_site_url', 'first_detected_gmt' ],
		titleField: 'affected_title',
		layout: { density: 'compact' },
	} );

	const [ items, setItems ] = useState< AttentionIssue[] >( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ hasFetchedOnce, setHasFetchedOnce ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	// Retry outcomes get their own banner so they carry a warning/error
	// severity, separate from the list-load `error` notice.
	const [ retryNotice, setRetryNotice ] = useState< RetryNotice | null >( null );
	const [ refreshNonce, setRefreshNonce ] = useState( 0 );

	const refresh = useCallback( () => {
		setRefreshNonce( ( previous ) => previous + 1 );
		onChanged?.();
	}, [ onChanged ] );

	useEffect( () => {
		const controller = new AbortController();

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_list_attention_issues' );
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
					response.json() as Promise< ApiResponse< AttentionIssuesResponse > >
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
							__( 'Failed to load attention issues.', 'safe-publish' )
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
					__( 'Network error while loading attention issues.', 'safe-publish' )
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

	const fields: DataViewsField< AttentionIssue >[] = [
		{
			id: 'affected_title',
			label: __( 'Content', 'safe-publish' ),
			enableSorting: false,
			render: ( { item } ) => {
				const label =
					'' !== item.affected_title
						? item.affected_title
						: sprintf(
							/* translators: %d: post ID */
							__( '#%d', 'safe-publish' ),
							item.affected_post_id
						);
				return '' !== item.affected_edit_url ? (
					<a href={ item.affected_edit_url }>{ label }</a>
				) : (
					<span>{ label }</span>
				);
			},
		},
		{
			id: 'message',
			label: __( 'Issue', 'safe-publish' ),
			enableSorting: false,
			render: ( { item } ) => <span>{ renderIssueMessage( item ) }</span>,
		},
		{
			id: 'severity',
			label: __( 'Severity', 'safe-publish' ),
			enableSorting: false,
			render: ( { item } ) => (
				<span
					className={ `safe-publish-issue-severity safe-publish-issue-severity--${ item.severity }` }
				>
					{ 'error' === item.severity
						? __( 'Error', 'safe-publish' )
						: __( 'Warning', 'safe-publish' ) }
				</span>
			),
		},
		{
			id: 'source_site_url',
			label: __( 'Source', 'safe-publish' ),
			enableSorting: false,
			render: ( { item } ) => (
				<span title={ item.source_site_url }>{ item.source_site_url }</span>
			),
		},
		{
			id: 'first_detected_gmt',
			label: __( 'Detected', 'safe-publish' ),
			enableSorting: false,
			getValue: ( { item } ) => item.first_detected_gmt,
			render: ( { item } ) => (
				<span>{ formatDateTime( item.first_detected_gmt ) }</span>
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

	const actions = createAttentionIssueActions( refresh, {
		ajaxurl,
		nonce,
		onNotice: setRetryNotice,
	} );

	return (
		<Modal
			title={ __( 'Needs attention', 'safe-publish' ) }
			onRequestClose={ onClose }
			className="safe-publish-attention-drawer"
			isFullScreen={ false }
			size="medium"
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
				<div className="safe-publish-controls-row">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Close', 'safe-publish' ) }
					</Button>
				</div>
				{ error && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
				{ retryNotice && (
					<Notice
						status={ retryNotice.status }
						onRemove={ () => setRetryNotice( null ) }
					>
						{ retryNotice.message }
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
						<p>{ __( 'Nothing needs attention.', 'safe-publish' ) }</p>
					</div>
				) }
				{ hasFetchedOnce && items.length > 0 && (
					<DataViews
						getItemId={ attentionIssueId }
						data={ items }
						fields={ fields }
						view={ view }
						onChangeView={ ( next: View ) => setView( next ) }
						paginationInfo={ paginationInfo }
						defaultLayouts={ { [ LAYOUT_TABLE ]: {} } }
						actions={ actions }
					/>
				) }
			</div>
		</Modal>
	);
};

export default AttentionDrawer;
