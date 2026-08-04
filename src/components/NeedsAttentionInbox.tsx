/**
 * Needs attention inbox — one server-paginated list of import failures and open
 * degradations for the connected source, with Remove (failures) and a
 * self-verifying Retry (degradations). Failures sort before degradations.
 *
 * @file This file defines the NeedsAttentionInbox component.
 */
import { update } from '@wordpress/icons';

import { useStepBackWhenPageEmpties } from './hooks/useStepBackWhenPageEmpties';
import { createNeedsAttentionActions, type ActionNotice } from '../actions';
import { DEFAULT_ITEMS_PER_PAGE, LAYOUT_TABLE } from '../constants';
import {
	attentionIssueLabel,
	formatDateTime,
	getErrorMessage,
	renderIssueMessage,
} from '../utils';
import {
	Button,
	Notice,
	Spinner,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	DataViewsField,
	NeedsAttentionResponse,
	NeedsAttentionRow,
	NeedsAttentionView,
} from '../types';

/**
 * Props for the NeedsAttentionInbox component.
 */
interface NeedsAttentionInboxProps {
	ajaxurl: string;
	nonce: string;
	onCountChange?: ( count: number ) => void;
}

/**
 * Renders the Content column: The row's title, linked to the edit screen when a
 * live destination post exists (failed updates and degradations have one;
 * first-import failures do not).
 *
 * @param {NeedsAttentionRow} item Inbox row.
 * @return {JSX.Element} Rendered content cell.
 */
const contentCell = ( item: NeedsAttentionRow ): JSX.Element => {
	const title =
		'failure' === item.kind ? item.title : attentionIssueLabel( item );
	const editUrl =
		'failure' === item.kind ? item.edit_url : item.affected_edit_url;

	return '' !== editUrl ? (
		<a href={ editUrl } target="_blank" rel="noreferrer">
			{ title }
		</a>
	) : (
		<span>{ title }</span>
	);
};

/**
 * Needs attention inbox tab body.
 *
 * @param {NeedsAttentionInboxProps} props Component props.
 */
const NeedsAttentionInbox = ( {
	ajaxurl,
	nonce,
	onCountChange,
}: NeedsAttentionInboxProps ): JSX.Element => {
	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: DEFAULT_ITEMS_PER_PAGE,
		page: 1,
		fields: [ 'type', 'detail', 'severity', 'when' ],
		titleField: 'content',
		layout: { density: 'compact' },
	} );

	// Open (default) excludes ignored rows; Ignored shows only them. Drives a
	// query param, mirroring how StateSelect drives the Posts query.
	const [ viewMode, setViewMode ] = useState< NeedsAttentionView >( 'open' );

	const [ items, setItems ] = useState< NeedsAttentionRow[] >( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ hasFetchedOnce, setHasFetchedOnce ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	// Retry/ignore outcomes get their own banner, separate from list-load error.
	const [ actionNotice, setActionNotice ] = useState< ActionNotice | null >(
		null
	);
	// Degradations with a retry in flight, so the action drops concurrent
	// submits.
	const inFlightRetries = useRef< Set< string > >( new Set() );
	const [ refreshNonce, setRefreshNonce ] = useState( 0 );

	const refresh = useCallback(
		() => setRefreshNonce( ( previous ) => previous + 1 ),
		[]
	);

	useEffect( () => {
		const controller = new AbortController();

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_list_needs_attention' );
		formData.append( 'nonce', nonce );
		formData.append( 'page', String( view.page ?? 1 ) );
		formData.append(
			'per_page',
			String( view.perPage ?? DEFAULT_ITEMS_PER_PAGE )
		);
		formData.append( 'view', viewMode );

		setIsLoading( true );
		setError( null );

		fetch( ajaxurl, {
			method: 'POST',
			body: formData,
			signal: controller.signal,
		} )
			.then(
				( response ) =>
					response.json() as Promise<
						ApiResponse< NeedsAttentionResponse >
					>
			)
			.then( ( result ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( result.success ) {
					setItems( result.data.items );
					setHasMore( Boolean( result.data.has_more ) );
					onCountChange?.( result.data.needs_attention_count );
				} else {
					setError(
						getErrorMessage(
							result,
							__(
								'Failed to load the Needs attention list.',
								'safe-publish'
							)
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
					__(
						'Network error while loading the Needs attention list.',
						'safe-publish'
					)
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
	}, [
		ajaxurl,
		nonce,
		view.page,
		view.perPage,
		viewMode,
		refreshNonce,
		onCountChange,
	] );

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

	const fields: DataViewsField< NeedsAttentionRow >[] = [
		{
			id: 'content',
			label: __( 'Content', 'safe-publish' ),
			enableSorting: false,
			render: ( { item } ) => contentCell( item ),
		},
		{
			id: 'type',
			label: __( 'Type', 'safe-publish' ),
			enableSorting: false,
			render: ( { item } ) => (
				<span
					className={ `safe-publish-inbox-type safe-publish-inbox-type--${ item.kind }` }
				>
					{ 'failure' === item.kind
						? __( 'Failed', 'safe-publish' )
						: __( 'Degraded', 'safe-publish' ) }
				</span>
			),
		},
		{
			id: 'detail',
			label: __( 'Detail', 'safe-publish' ),
			enableSorting: false,
			render: ( { item } ) =>
				'failure' === item.kind ? (
					<span title={ item.error_message }>
						{ item.error_message }
					</span>
				) : (
					<span>
						{ renderIssueMessage( item ) }
						{ 'open' === viewMode && item.retryable && (
							<span
								className={ `safe-publish-inbox-resolvable safe-publish-inbox-resolvable--${
									item.resolvable ? 'ready' : 'waiting'
								}` }
							>
								{ item.resolvable
									? __( 'Resolvable now', 'safe-publish' )
									: __( 'Waiting on import', 'safe-publish' ) }
							</span>
						) }
					</span>
				),
		},
		{
			id: 'severity',
			label: __( 'Severity', 'safe-publish' ),
			enableSorting: false,
			render: ( { item } ) => {
				const severity =
					'failure' === item.kind ? 'error' : item.severity;
				return (
					<span
						className={ `safe-publish-issue-severity safe-publish-issue-severity--${ severity }` }
					>
						{ 'error' === severity
							? __( 'Error', 'safe-publish' )
							: __( 'Warning', 'safe-publish' ) }
					</span>
				);
			},
		},
		{
			id: 'when',
			label: __( 'When', 'safe-publish' ),
			enableSorting: false,
			render: ( { item } ) => (
				<span>
					{ formatDateTime(
						'failure' === item.kind
							? item.import_date_gmt
							: item.first_detected_gmt
					) }
				</span>
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

	const actions = createNeedsAttentionActions(
		refresh,
		{
			ajaxurl,
			nonce,
			onNotice: setActionNotice,
			inFlight: inFlightRetries.current,
		},
		viewMode
	);

	return (
		<div
			className="safe-publish-dataviews-wrapper safe-publish-dataviews-wrapper--approx-pagination"
			style={
				{
					'--safe-publish-page-text': `"${ pageStatusText }"`,
				} as React.CSSProperties
			}
		>
			<div className="safe-publish-inbox-view-toggle">
				<ToggleGroupControl
					__nextHasNoMarginBottom
					isBlock
					hideLabelFromVision
					label={ __( 'View', 'safe-publish' ) }
					value={ viewMode }
					onChange={ ( value ) => {
						setViewMode(
							'ignored' === value ? 'ignored' : 'open'
						);
						setView( ( current ) => ( {
							...current,
							page: 1,
						} ) );
						// Drop the old rows so none stays actionable during the
						// refetch.
						setItems( [] );
						setActionNotice( null );
					} }
				>
					<ToggleGroupControlOption
						value="open"
						label={ __( 'Open', 'safe-publish' ) }
					/>
					<ToggleGroupControlOption
						value="ignored"
						label={ __( 'Ignored', 'safe-publish' ) }
					/>
				</ToggleGroupControl>
			</div>
			{ error && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			{ actionNotice && (
				<Notice
					status={ actionNotice.status }
					onRemove={ () => setActionNotice( null ) }
				>
					{ actionNotice.message }
				</Notice>
			) }
			{ isLoading && ! hasFetchedOnce && (
				<div
					className="safe-publish-loading"
					role="status"
					aria-live="polite"
				>
					<Spinner />
					<p>{ __( 'Loading…', 'safe-publish' ) }</p>
				</div>
			) }
			{ hasFetchedOnce && ! error && 0 === items.length && ! isLoading && (
				<div className="safe-publish-no-data" role="status">
					<p>
						{ 'ignored' === viewMode
							? __( 'No ignored items.', 'safe-publish' )
							: __(
									'Nothing needs attention.',
									'safe-publish'
							  ) }
					</p>
				</div>
			) }
			{ hasFetchedOnce && items.length > 0 && (
				<DataViews
					getItemId={ ( item: NeedsAttentionRow ) => item.row_id }
					data={ items }
					fields={ fields }
					view={ view }
					onChangeView={ ( next: View ) =>
						setView( {
							...next,
							page: next.perPage !== view.perPage ? 1 : next.page,
						} )
					}
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
	);
};

export default NeedsAttentionInbox;
