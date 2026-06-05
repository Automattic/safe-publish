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
 * contextual pill the user can clear. A `?focus_source=N` deep-link narrows
 * the listing to the single imported row matching that source ID (server-side
 * filter), surfaced as a pill that returns to the full listing on Clear.
 *
 * @file This file defines the ImportedPostsDataView component.
 */
import { update } from '@wordpress/icons';

import AuthStatusNotice from './AuthStatusNotice';
import { ImportedPostsEmptyState } from './ImportedPostsEmptyState';
import { useAuthStatus } from './hooks/useAuthStatus';
import { useDelayedFlag } from './hooks/useDelayedFlag';
import { useStepBackWhenPageEmpties } from './hooks/useStepBackWhenPageEmpties';
import { getImportedSyncStatusLabel } from './post-fields';
import { createImportedActions } from '../actions';
import {
	DEFAULT_ITEMS_PER_PAGE,
	LAYOUT_TABLE,
	SEARCH_DEBOUNCE_MS,
} from '../constants';
import {
	composeOutdatedLabel,
	extractUrlPath,
	formatDateTime,
	getErrorMessage,
	PUBLISH_STATUS_LABELS,
} from '../utils';
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
import type { ReactNode } from 'react';

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
 * Reads a URL search parameter as a positive integer.
 *
 * Used by mount-time `useState` initializers that hydrate state from a
 * deep-link query string (`?batch=N`, `?focus_source=N`).
 *
 * @param {string} name Parameter name to read.
 *
 * @return {number} The parsed positive integer, or 0 if absent/invalid.
 */
const readPositiveIntParam = ( name: string ): number => {
	const param = new URLSearchParams( window.location.search ).get( name );
	const parsed = param ? parseInt( param, 10 ) : 0;
	return Number.isFinite( parsed ) && parsed > 0 ? parsed : 0;
};

/**
 * Removes a URL search parameter without reloading the page.
 *
 * Pairs with `readPositiveIntParam` so a Clear action removes the deep-link
 * param it consumed, preventing a reload or share from reapplying it.
 *
 * @param {string} name Parameter name to remove.
 */
const stripUrlParam = ( name: string ): void => {
	const url = new URL( window.location.href );
	if ( url.searchParams.has( name ) ) {
		url.searchParams.delete( name );
		window.history.replaceState( null, '', url.toString() );
	}
};

/**
 * Contextual pill with a tertiary Clear button.
 *
 * Used to surface deep-link state (batch session, focused source) that the
 * user can dismiss; both consumers share styling via the
 * `safe-publish-dismissible-pill` class.
 *
 * @param {Object}     props         Component props.
 * @param {ReactNode}  props.text    Text to display.
 * @param {() => void} props.onClear Handler for the Clear button.
 *
 * @return {JSX.Element} Rendered dismissible pill.
 */
function DismissiblePill( {
	text,
	onClear,
}: {
	text: ReactNode;
	onClear: () => void;
} ): JSX.Element {
	return (
		<div
			className="safe-publish-dismissible-pill"
			role="status"
			aria-live="polite"
		>
			<span>{ text }</span>
			<Button variant="tertiary" onClick={ onClear }>
				{ __( 'Clear', 'safe-publish' ) }
			</Button>
		</div>
	);
}

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
		fields: [ 'post_type', 'import_date_gmt', 'local_status', 'sync_status' ],
		titleField: 'title',
		layout: { density: 'compact' },
	} );

	const defaultLayouts = useMemo(
		() => ( { [ LAYOUT_TABLE ]: {} } ),
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
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const [ hasRenderedGrid, setHasRenderedGrid ] = useState( false );
	const [ syncStatuses, setSyncStatuses ] = useState<
		Record< number, { status: ImportSyncStatus; modified_gmt?: string } >
	>( {} );

	const authStatus = useAuthStatus();

	// Batch is set from `?batch=N` on mount and clears via the contextual pill
	// below; it isn't surfaced as a user filter (sessions aren't a UI noun)
	// but is forwarded to the listing endpoint as `session_id`.
	//
	// Read directly from the URL on mount rather than from the PHP-passed
	// initial value, so a tab switch that re-mounts this component picks up
	// a previous Clear that updated the URL but not the inline global.
	const [ batchSessionId, setBatchSessionId ] = useState< number >( () =>
		readPositiveIntParam( 'batch' )
	);

	// Focus is set from `?focus_source=N` on mount and applied server-side as
	// a filter: the listing returns just the matching imported row, or none
	// when no import exists for that source ID. Overrides search/filters/sort
	// for the duration; Clear strips the URL param and restores the full list.
	const [ focusSourceId, setFocusSourceId ] = useState< number >( () =>
		readPositiveIntParam( 'focus_source' )
	);

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
		batchSessionId > 0 ||
		focusSourceId > 0;

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
		if ( focusSourceId > 0 ) {
			formData.append( 'focus_source_id', String( focusSourceId ) );
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
		focusSourceId,
		refreshNonce,
	] );

	// Sorted-join of pageItems' unique source_post_ids — stable across
	// content-equal replacements so the sync-status effect below doesn't
	// re-request on every render.
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
			// id is a server-supplied numeric key.
			// eslint-disable-next-line security/detect-object-injection
			sourceIds.forEach( ( id ) => { next[ id ] = { status: 'loading' }; } );
			return next;
		} );

		const markAll = ( verdict: ImportSyncStatus ): void => {
			setSyncStatuses( ( current ) => {
				const next = { ...current };
				// id is a server-supplied numeric key.
				// eslint-disable-next-line security/detect-object-injection
				sourceIds.forEach( ( id ) => { next[ id ] = { status: verdict }; } );
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
						// id is a server-supplied numeric key.
						// eslint-disable-next-line security/detect-object-injection
						next[ id ] = result.data.statuses[ id ] ?? { status: 'unreachable' };
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
	}, [ sourceIdsKey, refreshNonce ] );

	const fields: DataViewsField< ImportedPost >[] = useMemo(
		() => [
			{
				id: 'title',
				label: __( 'Title', 'safe-publish' ),
				enableSorting: true,
				render: ( { item }: { item: ImportedPost } ): JSX.Element => {
					// Drafts return the source home URL — show plain text
					// rather than a misleading link.
					const path = extractUrlPath( item.source_link );
					if ( '' === item.source_link || '/' === path ) {
						return <span>{ item.title }</span>;
					}
					return (
						<a
							href={ item.source_link }
							target="_blank"
							rel="noopener noreferrer"
							title={ item.source_link }
							aria-label={
								/* translators: %s: post title */
								sprintf( __( '%s (opens in new tab)', 'safe-publish' ), item.title )
							}
						>
							{ item.title }
						</a>
					);
				},
			},
			{
				id: 'sync_status',
				label: __( 'Sync Status', 'safe-publish' ),
				enableSorting: false,
				getValue: ( { item }: { item: ImportedPost } ): string =>
					getImportedSyncStatusLabel(
						syncStatuses[ item.source_post_id ]?.status ?? null
					),
				render: ( { item }: { item: ImportedPost } ): JSX.Element => {
					const entry = syncStatuses[ item.source_post_id ];
					const status = entry?.status ?? 'loading';
					const label = 'outdated' === status && entry?.modified_gmt
						? composeOutdatedLabel( entry.modified_gmt )
						: getImportedSyncStatusLabel( status );
					return (
						<span
							className={ `safe-publish-status-badge safe-publish-status-badge--${ status }` }
						>
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
				id: 'local_status',
				label: __( 'Local Status', 'safe-publish' ),
				enableSorting: false,
				elements: STATUS_FILTER_ELEMENTS,
				filterBy: { operators: [ 'isAny' ], isPrimary: true },
				getValue: ( { item }: { item: ImportedPost } ): string =>
					PUBLISH_STATUS_LABELS[ item.local_status ] ?? item.local_status,
				render: ( { item }: { item: ImportedPost } ): JSX.Element => {
					const label =
						PUBLISH_STATUS_LABELS[ item.local_status ] ?? item.local_status;
					const modifierClass = `safe-publish-status-badge--${ item.local_status }`;
					return (
						<span className={ `safe-publish-status-badge safe-publish-status-badge--quiet ${ modifierClass }` }>
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
		],
		[ facets, syncStatuses ]
	);

	const refresh = useCallback(
		() => setRefreshNonce( ( nonce ) => nonce + 1 ),
		[]
	);

	const setPage = useCallback(
		( next: number ): void =>
			setView( ( current ) => ( { ...current, page: next } ) ),
		[]
	);

	// Delete can shrink the listing past the current page.
	useStepBackWhenPageEmpties( {
		hasFetchedOnce,
		isLoading,
		fetchError,
		isEmpty: 0 === pageItems.length,
		page: view.page,
		setPage,
	} );

	const actions = useMemo(
		() =>
			createImportedActions(
				refresh,
				{
					ajaxurl: window.safePublishAdminData.ajaxurl,
					nonce: window.safePublishAdminData.nonce,
					restNonce: window.safePublishAdminData.restNonce,
				},
				syncStatuses
			),
		[ refresh, syncStatuses ]
	);

	const clearBatch = useCallback( (): void => {
		setBatchSessionId( 0 );
		// Match handleViewChange's filter-change behavior so the listing lands
		// on page 1 of the unfiltered set rather than mid-pagination.
		setView( ( current ) => ( { ...current, page: 1 } ) );
		stripUrlParam( 'batch' );
	}, [] );

	const clearFocus = useCallback( (): void => {
		setFocusSourceId( 0 );
		// Match handleViewChange's filter-change behavior so the listing lands
		// on page 1 of the unfiltered set rather than mid-pagination.
		setView( ( current ) => ( { ...current, page: 1 } ) );
		stripUrlParam( 'focus_source' );
	}, [] );

	const handleViewChange = useCallback( ( next: View ): void => {
		const sortChanged =
			next.sort?.field !== view.sort?.field ||
			next.sort?.direction !== view.sort?.direction;
		const perPageChanged = next.perPage !== view.perPage;
		const searchChanged = ( next.search ?? '' ) !== ( view.search ?? '' );
		const filtersChanged =
			JSON.stringify( next.filters ?? [] ) !==
			JSON.stringify( view.filters ?? [] );
		const browseGesture =
			sortChanged || perPageChanged || searchChanged || filtersChanged;

		// Search/filter/sort/perPage expresses intent to browse — if a deep
		// link is still narrowing the listing via focus, dismiss it so the
		// gesture applies to the full set rather than a single-row view.
		if ( browseGesture && focusSourceId > 0 ) {
			clearFocus();
		}

		// Search/filter/sort/perPage changes reset to page 1; layout-only
		// changes keep the current page.
		setView( {
			...next,
			page: browseGesture ? 1 : ( next.page ?? view.page ?? 1 ),
		} );
	}, [ view, focusSourceId, clearFocus ] );

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

	// Suppress "Updating…" when the refetch completes within a frame or two.
	const showRefetch = useDelayedFlag( isLoading && hasFetchedOnce, 200 );

	// Three-state pill text so the deep link's user doesn't see a confident
	// "Viewing …" claim before the server confirms a match. In focus mode the
	// server returns just the resolved row (or none), so pageItems[0] carries
	// the title we surface to anchor the pill to a visible row.
	let focusPillText;
	if ( ! hasFetchedOnce ) {
		focusPillText = sprintf(
			/* translators: %d: source post id */
			__( 'Looking up source #%d…', 'safe-publish' ),
			focusSourceId
		);
	} else if ( 0 === pageItems.length ) {
		focusPillText = sprintf(
			/* translators: %d: source post id */
			__( 'No imported post for source #%d', 'safe-publish' ),
			focusSourceId
		);
	} else {
		focusPillText = sprintf(
			/* translators: %s: imported post title */
			__( 'Viewing import for: %s', 'safe-publish' ),
			pageItems[ 0 ].title
		);
	}

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
				<DismissiblePill
					text={ sprintf(
						/* translators: %d: import batch number */
						__( 'Showing imports from batch #%d', 'safe-publish' ),
						batchSessionId
					) }
					onClear={ clearBatch }
				/>
			) }
			{ focusSourceId > 0 && (
				<DismissiblePill text={ focusPillText } onClear={ clearFocus } />
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
				/>
			) }
			{ showRefetch && (
				<div
					className="safe-publish-refetch-indicator"
					role="status"
					aria-live="polite"
				>
					<Spinner />
					<span>{ __( 'Updating…', 'safe-publish' ) }</span>
				</div>
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
					header={
						<Button
							variant="tertiary"
							isBusy={ isLoading }
							disabled={ isLoading }
							icon={ update }
							label={ __( 'Refresh', 'safe-publish' ) }
							onClick={ refresh }
						/>
					}
				/>
			) }
		</div>
	);
}
