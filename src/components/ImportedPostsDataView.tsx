/**
 * DataViews component for the Imports → Posts tab.
 *
 * Lists locally-imported posts via the destination-side
 * `safe_publish_list_imported_posts` AJAX action (a purely local query) and
 * then issues a second `safe_publish_sync_status_batch` call per page to fill
 * the Sync Status column from the source's modified_gmt. Supports server-side
 * search, filtering (Local Status, Type), and sorting across the full dataset,
 * plus row actions (Edit, Update, Diff, Delete, and Rollback — single or bulk).
 *
 * When invoked via the post-import notice's `?batch=N` deep-link, the
 * accompanying session id is applied as a hidden filter and surfaced as a
 * contextual pill the user can clear.
 *
 * @file This file defines the ImportedPostsDataView component.
 */
import AuthStatusNotice from './AuthStatusNotice';
import { ImportedPostsEmptyState } from './ImportedPostsEmptyState';
import { useAuthStatus } from './hooks/useAuthStatus';
import { getImportedSyncStatusLabel } from './post-fields';
import { createImportedActions } from '../actions';
import {
	DEFAULT_ITEMS_PER_PAGE,
	LAYOUT_GRID,
	LAYOUT_LIST,
	LAYOUT_TABLE,
	SEARCH_DEBOUNCE_MS,
} from '../constants';
import { extractUrlPath, formatDateTime, getErrorMessage, PUBLISH_STATUS_LABELS } from '../utils';
import { Button, Notice, Spinner } from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import { useState, useEffect, useMemo, useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	DataViewsField,
	ImportedPost,
	ImportedPostsFacets,
	ImportedPostsResponse,
	ImportSyncStatus,
	SyncStatusBatchResponse,
} from '../types';

/**
 * Local Status filter options, derived from the shared status labels.
 */
const STATUS_FILTER_ELEMENTS = Object.entries( PUBLISH_STATUS_LABELS ).map(
	( [ value, label ] ) => ( { value, label } )
);

/**
 * Reads a multi-select filter's values from the view by field id.
 *
 * @param {View['filters']} filters Active view filters.
 * @param {string}          field   Field id to read.
 *
 * @return {string[]} Selected values, or an empty array.
 */
const getMultiFilterValues = (
	filters: View[ 'filters' ],
	field: string
): string[] => {
	const filter = filters?.find( ( entry ) => entry.field === field );
	if ( ! filter || ! Array.isArray( filter.value ) ) {
		return [];
	}
	return filter.value.map( String );
};

/**
 * Derives which of the listing's mutually-exclusive display states to show.
 *
 * `showGrid` latches via hasRenderedGrid so a refetch that briefly empties the
 * results can't unmount DataViews mid-interaction.
 *
 * @param {Object}  flags                  Current fetch/filter flags.
 * @param {boolean} flags.hasFetchedOnce   Whether the first fetch completed.
 * @param {boolean} flags.hasRenderedGrid  Whether the grid has been shown.
 * @param {boolean} flags.hasError         Whether a fetch error is showing.
 * @param {boolean} flags.isEmpty          Whether the current page has no rows.
 * @param {boolean} flags.isLoading        Whether a fetch is in flight.
 * @param {boolean} flags.hasActiveFilters Whether search/filters are active.
 *
 * @return {{ showLoading: boolean, showEmptyState: boolean, showGrid: boolean }}
 *         The display state to render.
 */
const getDisplayState = ( flags: {
	hasFetchedOnce: boolean;
	hasRenderedGrid: boolean;
	hasError: boolean;
	isEmpty: boolean;
	isLoading: boolean;
	hasActiveFilters: boolean;
} ): { showLoading: boolean; showEmptyState: boolean; showGrid: boolean } => ( {
	showLoading: flags.isLoading && ! flags.hasFetchedOnce,
	showEmptyState:
		flags.hasFetchedOnce &&
		! flags.hasRenderedGrid &&
		! flags.hasError &&
		flags.isEmpty &&
		! flags.isLoading &&
		! flags.hasActiveFilters,
	showGrid:
		flags.hasFetchedOnce &&
		( flags.hasRenderedGrid || ! flags.isEmpty || flags.hasActiveFilters ),
} );

/**
 * ImportedPostsDataView component.
 *
 * @return {JSX.Element} Rendered DataViews component for imported posts.
 */
export function ImportedPostsDataView(): JSX.Element {
	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: DEFAULT_ITEMS_PER_PAGE,
		page: 1,
		sort: { field: 'import_date_gmt', direction: 'desc' },
		fields: [ 'permalink', 'sync_status', 'local_status', 'rolled_back', 'import_date_gmt' ],
		titleField: 'title',
	} );

	const defaultLayouts = useMemo(
		() => ( {
			[ LAYOUT_TABLE ]: {},
			[ LAYOUT_GRID ]: {},
			[ LAYOUT_LIST ]: {},
		} ),
		[]
	);

	const [ pageItems, setPageItems ] = useState< ImportedPost[] >( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ hasFetchedOnce, setHasFetchedOnce ] = useState( false );
	const [ fetchError, setFetchError ] = useState< string | null >( null );
	const [ refreshNonce, setRefreshNonce ] = useState( 0 );
	const [ facets, setFacets ] = useState< ImportedPostsFacets >( {
		post_types: [],
	} );
	// Sent by the listing endpoint on first load alongside facets, so the
	// empty state can surface "see Failures" without a separate roundtrip.
	const [ failedCount, setFailedCount ] = useState< number | null >( null );
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const [ hasRenderedGrid, setHasRenderedGrid ] = useState( false );
	const [ syncStatuses, setSyncStatuses ] = useState<
		Record< number, ImportSyncStatus >
	>( {} );

	const authStatus = useAuthStatus();

	// Batch is set from `?batch=N` on mount and clears via the contextual pill
	// below; it isn't surfaced as a user filter (sessions aren't a UI noun)
	// but is forwarded to the listing endpoint as `session_id`.
	//
	// Read directly from the URL on mount rather than from the PHP-passed
	// initial value, so a tab switch that re-mounts this component picks up
	// a previous Clear that updated the URL but not the inline global.
	const [ batchSessionId, setBatchSessionId ] = useState< number >( () => {
		const batchParam = new URLSearchParams( window.location.search ).get( 'batch' );
		const parsed = batchParam ? parseInt( batchParam, 10 ) : 0;
		return Number.isFinite( parsed ) && parsed > 0 ? parsed : 0;
	} );

	const facetsLoadedRef = useRef( false );

	const search = view.search ?? '';
	const statuses = useMemo(
		() => getMultiFilterValues( view.filters, 'local_status' ),
		[ view.filters ]
	);
	const postTypes = useMemo(
		() => getMultiFilterValues( view.filters, 'post_type' ),
		[ view.filters ]
	);
	const orderby = 'title' === view.sort?.field ? 'title' : 'import_date';
	const order = 'asc' === view.sort?.direction ? 'asc' : 'desc';

	const hasActiveFilters =
		'' !== debouncedSearch ||
		statuses.length > 0 ||
		postTypes.length > 0 ||
		batchSessionId > 0;

	// Debounce the search box so a fast typist doesn't fire a request per
	// keystroke.
	useEffect( () => {
		const timer = setTimeout( () => {
			setDebouncedSearch( search.trim() );
		}, SEARCH_DEBOUNCE_MS );

		return () => clearTimeout( timer );
	}, [ search ] );

	// Latch DataViews mounted once it first appears, so a refetch that briefly
	// empties the result set (e.g. clearing a search) can't unmount it and pull
	// focus out of the search box.
	useEffect( () => {
		if ( pageItems.length > 0 || hasActiveFilters ) {
			setHasRenderedGrid( true );
		}
	}, [ pageItems.length, hasActiveFilters ] );

	const statusesKey = statuses.join( '|' );
	const postTypesKey = postTypes.join( '|' );

	useEffect( () => {
		const controller = new AbortController();

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_list_imported_posts' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'page', String( view.page ?? 1 ) );
		formData.append( 'per_page', String( view.perPage ?? DEFAULT_ITEMS_PER_PAGE ) );
		formData.append( 'orderby', orderby );
		formData.append( 'order', order );

		if ( '' !== debouncedSearch ) {
			formData.append( 'search', debouncedSearch );
		}
		statuses.forEach( ( status ) => formData.append( 'statuses[]', status ) );
		postTypes.forEach( ( type ) => formData.append( 'post_types[]', type ) );
		if ( batchSessionId > 0 ) {
			formData.append( 'session_id', String( batchSessionId ) );
		}
		if ( ! facetsLoadedRef.current ) {
			formData.append( 'with_facets', '1' );
		}

		setIsLoading( true );
		setFetchError( null );

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
			signal: controller.signal,
		} )
			.then( ( response ) =>
				response.json() as Promise< ApiResponse< ImportedPostsResponse > >
			)
			.then( ( result ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( result.success ) {
					setPageItems( result.data.items );
					setHasMore( Boolean( result.data.has_more ) );
					if ( result.data.facets ) {
						setFacets( result.data.facets );
						facetsLoadedRef.current = true;
					}
					if ( 'number' === typeof result.data.failed_count ) {
						setFailedCount( result.data.failed_count );
					}
				} else {
					setFetchError(
						getErrorMessage(
							result,
							__( 'Failed to load imported posts.', 'safe-publish' )
						)
					);
					setPageItems( [] );
					setHasMore( false );
				}
			} )
			.catch( ( error: unknown ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( error instanceof DOMException && 'AbortError' === error.name ) {
					return;
				}
				setFetchError(
					__( 'Network error while loading imported posts.', 'safe-publish' )
				);
				setPageItems( [] );
				setHasMore( false );
			} )
			.finally( () => {
				if ( controller.signal.aborted ) {
					return;
				}
				setIsLoading( false );
				setHasFetchedOnce( true );
			} );

		return () => {
			controller.abort();
		};
		// Keyed on the value-based statusesKey/postTypesKey rather than the
		// memoized arrays, whose identity changes on every view update.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		view.page,
		view.perPage,
		orderby,
		order,
		debouncedSearch,
		statusesKey,
		postTypesKey,
		batchSessionId,
		refreshNonce,
	] );

	// Sorted-join of the page's unique source_post_ids — stable across
	// content-equal pageItems replacements so the sync-status effect below
	// doesn't re-request on every render.
	const sourceIdsKey = useMemo(
		() =>
			Array.from(
				new Set(
					pageItems
						.map( ( item ) => item.source_post_id )
						.filter( ( id ): id is number => id > 0 )
				)
			)
				.sort( ( left, right ) => left - right )
				.join( ',' ),
		[ pageItems ]
	);

	// Refill the Sync Status column after every listing refresh by asking the
	// destination's sync-status endpoint to compare each row's source
	// modified_gmt against its import_date_gmt.
	useEffect( () => {
		if ( '' === sourceIdsKey ) {
			return;
		}

		const sourceIds = sourceIdsKey.split( ',' ).map( Number );
		const controller = new AbortController();

		setSyncStatuses( ( current ) => {
			const next = { ...current };
			// id is a server-supplied numeric key from pageItems.
			// eslint-disable-next-line security/detect-object-injection
			sourceIds.forEach( ( id ) => { next[ id ] = 'loading'; } );
			return next;
		} );

		const markAll = ( verdict: ImportSyncStatus ): void => {
			setSyncStatuses( ( current ) => {
				const next = { ...current };
				// id is a server-supplied numeric key from pageItems.
				// eslint-disable-next-line security/detect-object-injection
				sourceIds.forEach( ( id ) => { next[ id ] = verdict; } );
				return next;
			} );
		};

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_sync_status_batch' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		sourceIds.forEach( ( id ) =>
			formData.append( 'source_ids[]', String( id ) )
		);

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
			signal: controller.signal,
		} )
			.then(
				( response ) =>
					response.json() as Promise<
						ApiResponse< SyncStatusBatchResponse >
					>
			)
			.then( ( result ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( ! result.success ) {
					markAll( 'unreachable' );
					return;
				}
				setSyncStatuses( ( current ) => {
					const next = { ...current };
					sourceIds.forEach( ( id ) => {
						// id is a server-supplied numeric key from pageItems.
						// eslint-disable-next-line security/detect-object-injection
						next[ id ] = result.data.statuses[ id ] ?? 'unreachable';
					} );
					return next;
				} );
			} )
			.catch( ( error: unknown ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( error instanceof DOMException && 'AbortError' === error.name ) {
					return;
				}
				markAll( 'unreachable' );
			} );

		return () => {
			controller.abort();
		};
	}, [ sourceIdsKey ] );

	const fields: DataViewsField< ImportedPost >[] = useMemo(
		() => [
			{
				id: 'title',
				label: __( 'Title', 'safe-publish' ),
				enableSorting: true,
				render: ( { item }: { item: ImportedPost } ): JSX.Element => {
					if ( '' !== item.edit_url ) {
						return (
							<a
								href={ item.edit_url }
								target="_blank"
								rel="noreferrer"
								aria-label={
									/* translators: %s: post title */
									sprintf( __( '%s (opens in new tab)', 'safe-publish' ), item.title )
								}
							>
								{ item.title }
							</a>
						);
					}
					return <span>{ item.title }</span>;
				},
			},
			{
				id: 'sync_status',
				label: __( 'Sync Status', 'safe-publish' ),
				enableSorting: false,
				getValue: ( { item }: { item: ImportedPost } ): string =>
					getImportedSyncStatusLabel(
						syncStatuses[ item.source_post_id ] ?? null
					),
				render: ( { item }: { item: ImportedPost } ): JSX.Element => {
					const status = syncStatuses[ item.source_post_id ] ?? 'loading';
					return (
						<span
							className={ `safe-publish-status-badge safe-publish-status-badge--${ status }` }
						>
							<span
								className="safe-publish-status-badge__dot"
								aria-hidden="true"
							/>
							{ getImportedSyncStatusLabel( status ) }
						</span>
					);
				},
			},
			{
				id: 'local_status',
				label: __( 'Local Status', 'safe-publish' ),
				enableSorting: false,
				elements: STATUS_FILTER_ELEMENTS,
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item }: { item: ImportedPost } ): string =>
					PUBLISH_STATUS_LABELS[ item.local_status ] ?? item.local_status,
				render: ( { item }: { item: ImportedPost } ): JSX.Element => {
					const label =
						PUBLISH_STATUS_LABELS[ item.local_status ] ?? item.local_status;
					const modifierClass = `safe-publish-status-badge--${ item.local_status }`;
					return (
						<span className={ `safe-publish-status-badge ${ modifierClass }` }>
							<span
								className="safe-publish-status-badge__dot"
								aria-hidden="true"
							/>
							{ label }
						</span>
					);
				},
			},
			{
				id: 'rolled_back',
				label: __( 'Rollback', 'safe-publish' ),
				enableSorting: false,
				getValue: ( { item }: { item: ImportedPost } ): string =>
					item.rolled_back ? __( 'Rolled back', 'safe-publish' ) : '',
				render: ( { item }: { item: ImportedPost } ): JSX.Element => {
					if ( ! item.rolled_back ) {
						return <span>—</span>;
					}
					return (
						<span className="safe-publish-status-badge safe-publish-status-badge--rolled-back">
							<span
								className="safe-publish-status-badge__dot"
								aria-hidden="true"
							/>
							{ __( 'Rolled back', 'safe-publish' ) }
						</span>
					);
				},
			},
			{
				id: 'post_type',
				label: __( 'Type', 'safe-publish' ),
				enableSorting: false,
				elements: facets.post_types,
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item }: { item: ImportedPost } ): string => {
					const match = facets.post_types.find(
						( option ) => option.value === item.post_type
					);
					return match ? match.label : item.post_type;
				},
			},
			{
				id: 'import_date_gmt',
				label: __( 'Last Imported', 'safe-publish' ),
				enableSorting: true,
				getValue: ( { item }: { item: ImportedPost } ): string =>
					item.import_date_gmt ?? '',
				render: ( { item }: { item: ImportedPost } ): JSX.Element => {
					const value = item.import_date_gmt;
					if ( null === value || '' === value ) {
						return <span>—</span>;
					}
					// Items-table values are MySQL datetime in UTC; append Z so
					// formatDateTime parses them as such.
					return <span>{ formatDateTime( `${ value.replace( ' ', 'T' ) }Z` ) }</span>;
				},
			},
			{
				id: 'permalink',
				label: __( 'Permalink', 'safe-publish' ),
				enableSorting: false,
				getValue: ( { item }: { item: ImportedPost } ): string => item.source_link,
				render: ( { item }: { item: ImportedPost } ): JSX.Element => {
					// Drafts capture the source home URL (path '/'); show an
					// em-dash rather than a misleading link.
					const path = extractUrlPath( item.source_link );
					if ( '' === item.source_link || '/' === path ) {
						return <span>—</span>;
					}
					return (
						<a
							href={ item.source_link }
							target="_blank"
							rel="noopener noreferrer"
							title={ item.source_link }
						>
							{ path }
						</a>
					);
				},
			},
		],
		[ facets, syncStatuses ]
	);

	const refresh = useCallback(
		() => setRefreshNonce( ( nonce ) => nonce + 1 ),
		[]
	);
	const actions = useMemo(
		() => createImportedActions( refresh ),
		[ refresh ]
	);

	const handleViewChange = useCallback( ( next: View ): void => {
		setView( ( current ) => {
			const sortChanged =
				next.sort?.field !== current.sort?.field ||
				next.sort?.direction !== current.sort?.direction;
			const perPageChanged = next.perPage !== current.perPage;
			const searchChanged = ( next.search ?? '' ) !== ( current.search ?? '' );
			const filtersChanged =
				JSON.stringify( next.filters ?? [] ) !==
				JSON.stringify( current.filters ?? [] );

			// Search/filter/sort/perPage changes reset to page 1; layout-only
			// changes keep the current page.
			return {
				...next,
				page:
					sortChanged || perPageChanged || searchChanged || filtersChanged
						? 1
						: ( next.page ?? current.page ?? 1 ),
			};
		} );
	}, [] );

	const currentPage = view.page ?? 1;
	const currentPerPage = view.perPage ?? DEFAULT_ITEMS_PER_PAGE;
	const paginationInfo = useMemo(
		() =>
			! hasMore
				? {
						totalItems: ( currentPage - 1 ) * currentPerPage + pageItems.length,
						totalPages: currentPage,
				  }
				: {
						totalItems: currentPage * currentPerPage + 1,
						totalPages: currentPage + 1,
				  },
		[ currentPage, currentPerPage, hasMore, pageItems.length ]
	);

	const pageStatusText = sprintf(
		/* translators: %d: current page number */
		__( 'Page %d', 'safe-publish' ),
		currentPage
	);

	const { showLoading, showEmptyState, showGrid } = getDisplayState( {
		hasFetchedOnce,
		hasRenderedGrid,
		hasError: null !== fetchError,
		isEmpty: 0 === pageItems.length,
		isLoading,
		hasActiveFilters,
	} );

	// Stable for the lifetime of the mount — derived from the URL the page
	// was loaded with, so the empty-state link routes through ImportsApp's
	// own ?tab=... handling.
	const failuresHref = useMemo( (): string => {
		const url = new URL( window.location.href );
		url.searchParams.set( 'tab', 'failures' );
		url.searchParams.delete( 'batch' );
		return url.toString();
	}, [] );

	const clearBatch = useCallback( (): void => {
		setBatchSessionId( 0 );
		// Match handleViewChange's filter-change behavior so the listing lands
		// on page 1 of the unfiltered set rather than mid-pagination.
		setView( ( current ) => ( { ...current, page: 1 } ) );
		// Strip ?batch=N so a reload or share doesn't reapply the filter.
		const url = new URL( window.location.href );
		if ( url.searchParams.has( 'batch' ) ) {
			url.searchParams.delete( 'batch' );
			window.history.replaceState( null, '', url.toString() );
		}
	}, [] );

	return (
		<div
			className="safe-publish-dataviews-wrapper safe-publish-dataviews-wrapper--with-builtins"
			style={
				{
					'--safe-publish-page-text': `"${ pageStatusText }"`,
				} as React.CSSProperties
			}
		>
			<AuthStatusNotice
				status={ authStatus }
				settingsUrl={ window.safePublishAdminData?.settingsUrl }
			/>
			{ batchSessionId > 0 && (
				<div
					className="safe-publish-batch-pill"
					role="status"
					aria-live="polite"
				>
					<span>
						{ sprintf(
							/* translators: %d: import batch number */
							__( 'Showing imports from batch #%d', 'safe-publish' ),
							batchSessionId
						) }
					</span>
					<Button variant="tertiary" onClick={ clearBatch }>
						{ __( 'Clear', 'safe-publish' ) }
					</Button>
				</div>
			) }
			{ fetchError && (
				<Notice
					className="safe-publish-post-type-error"
					status="error"
					onRemove={ () => setFetchError( null ) }
				>
					{ fetchError }
				</Notice>
			) }
			{ showLoading && (
				<div className="safe-publish-loading" role="status" aria-live="polite">
					<Spinner />
					<p>{ __( 'Loading imported posts…', 'safe-publish' ) }</p>
				</div>
			) }
			{ showEmptyState && (
				<ImportedPostsEmptyState
					sourcePostsUrl={ window.safePublishAdminData?.sourcePostsUrl }
					failedCount={ failedCount }
					failuresHref={ failuresHref }
				/>
			) }
			{ showGrid && (
				<DataViews
					getItemId={ ( item: ImportedPost ) => item.id.toString() }
					data={ pageItems }
					fields={ fields }
					view={ view }
					onChangeView={ handleViewChange }
					paginationInfo={ paginationInfo }
					defaultLayouts={ defaultLayouts }
					actions={ actions }
					searchLabel={ __( 'Search by title', 'safe-publish' ) }
				/>
			) }
		</div>
	);
}
