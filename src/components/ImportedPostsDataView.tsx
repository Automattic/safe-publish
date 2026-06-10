/**
 * DataViews component for the Imports → Posts tab.
 *
 * Lists locally-imported posts via the destination-side
 * `safe_publish_list_imported_posts` AJAX action (a purely local query) and
 * then issues a second `safe_publish_sync_status_batch` call per page to fill
 * the Sync Status column from the source's modified_gmt. A custom toolbar
 * drives search/filter changes; DataViews handles only the table render,
 * sortable column clicks, layout switcher, and Prev/Next pagination.
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
import {
	calendarRangeToUtcBounds,
	DateRangeFilter,
	detectSlugFromInput,
} from './filter-controls';
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
import {
	BaseControl,
	Button,
	FormTokenField,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import { useState, useEffect, useMemo, useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	DataViewsField,
	FilterOption,
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
const STATUS_FILTER_ELEMENTS: FilterOption[] = Object.entries(
	PUBLISH_STATUS_LABELS
).map( ( [ value, label ] ) => ( { value, label } ) );

const STATUS_LABEL_SUGGESTIONS = STATUS_FILTER_ELEMENTS.map(
	( option ) => option.label
);

/**
 * Maps a list of selected option values to their display labels, falling
 * back to the value when no matching option exists.
 *
 * @param {string[]}       values  Selected option values.
 * @param {FilterOption[]} options Available options (value + label pairs).
 *
 * @return {string[]} Labels in the same order as the input values.
 */
function valuesToLabels(
	values: string[],
	options: FilterOption[]
): string[] {
	return values.map( ( value ) => {
		const match = options.find( ( option ) => option.value === value );
		return match ? match.label : value;
	} );
}

/**
 * Maps labels emitted by FormTokenField back to the underlying option
 * values, dropping any that aren't in the allowlist.
 *
 * @param {(string|{value:string})[]} tokens  Tokens from FormTokenField.
 * @param {FilterOption[]}            options Available options.
 *
 * @return {string[]} Allowlisted option values.
 */
function labelsToValues(
	tokens: ( string | { value: string } )[],
	options: FilterOption[]
): string[] {
	const allowed = options.map( ( option ) => option.value );
	return tokens
		.map( ( token ) =>
			'string' === typeof token ? token : token.value
		)
		.map( ( label ) => {
			const match = options.find( ( option ) => option.label === label );
			return match ? match.value : label;
		} )
		.filter( ( value ) => allowed.includes( value ) );
}

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
 * Builds the FormData payload for the imported-posts listing request.
 * Extracted from the fetch effect to keep the component body under the
 * complexity budget.
 *
 * @param {Object}      params                 Request parameters.
 * @param {string}      params.nonce           AJAX nonce.
 * @param {number}      params.page            1-indexed page number.
 * @param {number}      params.perPage         Items per page.
 * @param {string}      params.orderby         Sort field.
 * @param {string}      params.order           Sort direction.
 * @param {string}      params.debouncedSearch Debounced title search.
 * @param {string|null} params.slugFromUrl     Detected slug from a pasted URL.
 * @param {string[]}    params.statuses        Local post_status filter values.
 * @param {string[]}    params.postTypes       Post type filter values.
 * @param {string|null} params.importedAfter   Lower bound on import_date_gmt.
 * @param {string|null} params.importedBefore  Upper bound on import_date_gmt.
 * @param {number}      params.batchSessionId  Session deep-link filter, or 0.
 * @param {number}      params.focusSourceId   Source-post deep-link filter, or 0.
 * @param {boolean}     params.withFacets      Whether to request first-load facets.
 *
 * @return {FormData} Populated payload.
 */
function buildListingFormData( params: {
	nonce: string;
	page: number;
	perPage: number;
	orderby: string;
	order: string;
	debouncedSearch: string;
	slugFromUrl: string | null;
	statuses: string[];
	postTypes: string[];
	importedAfter: string | null;
	importedBefore: string | null;
	batchSessionId: number;
	focusSourceId: number;
	withFacets: boolean;
} ): FormData {
	const formData = new FormData();
	formData.append( 'action', 'safe_publish_list_imported_posts' );
	formData.append( 'nonce', params.nonce );
	formData.append( 'page', String( params.page ) );
	formData.append( 'per_page', String( params.perPage ) );
	formData.append( 'orderby', params.orderby );
	formData.append( 'order', params.order );

	if ( null !== params.slugFromUrl ) {
		formData.append( 'name', params.slugFromUrl );
	} else if ( '' !== params.debouncedSearch ) {
		formData.append( 'search', params.debouncedSearch );
	}
	params.statuses.forEach( ( status ) =>
		formData.append( 'statuses[]', status )
	);
	params.postTypes.forEach( ( type ) =>
		formData.append( 'post_types[]', type )
	);
	const { afterUtc, beforeUtc } = calendarRangeToUtcBounds(
		params.importedAfter,
		params.importedBefore
	);
	if ( null !== afterUtc ) {
		formData.append( 'imported_after', afterUtc );
	}
	if ( null !== beforeUtc ) {
		formData.append( 'imported_before', beforeUtc );
	}
	if ( params.batchSessionId > 0 ) {
		formData.append( 'session_id', String( params.batchSessionId ) );
	}
	if ( params.focusSourceId > 0 ) {
		formData.append( 'focus_source_id', String( params.focusSourceId ) );
	}
	if ( params.withFacets ) {
		formData.append( 'with_facets', '1' );
	}
	return formData;
}

/**
 * Returns true when any user-driven filter (toolbar input or deep-link pill)
 * is narrowing the listing. Reads live `searchTerm` rather than the debounced
 * value so the Clear-filters button and "no matches" message respond
 * immediately rather than during the 400 ms debounce.
 *
 * @param {Object}      state                   Filter state.
 * @param {string}      state.searchTerm        Live search input.
 * @param {string[]}    state.selectedStatuses  Selected Local Status values.
 * @param {string[]}    state.selectedPostTypes Selected Post Type values.
 * @param {string|null} state.importedAfter     Date-range after bound.
 * @param {string|null} state.importedBefore    Date-range before bound.
 * @param {number}      state.batchSessionId    Session deep-link filter, or 0.
 * @param {number}      state.focusSourceId     Source-post deep-link filter, or 0.
 *
 * @return {boolean} Whether at least one filter is active.
 */
function computeHasActiveFilters( state: {
	searchTerm: string;
	selectedStatuses: string[];
	selectedPostTypes: string[];
	importedAfter: string | null;
	importedBefore: string | null;
	batchSessionId: number;
	focusSourceId: number;
} ): boolean {
	return (
		'' !== state.searchTerm ||
		state.selectedStatuses.length > 0 ||
		state.selectedPostTypes.length > 0 ||
		null !== state.importedAfter ||
		null !== state.importedBefore ||
		state.batchSessionId > 0 ||
		state.focusSourceId > 0
	);
}

/**
 * Composes the focus-pill text for the ?focus_source=N deep-link flow.
 * Three-state so the user doesn't see a confident "Viewing …" claim before
 * the server confirms a match.
 *
 * @param {Object}  params                Pill input state.
 * @param {boolean} params.hasFetchedOnce Whether the first fetch completed.
 * @param {number}  params.itemsCount     Items in the current page.
 * @param {string}  params.firstTitle     Title of the first item, when present.
 * @param {number}  params.focusSourceId  Source post id from the deep link.
 *
 * @return {string} Translated pill text.
 */
function composeFocusPillText( params: {
	hasFetchedOnce: boolean;
	itemsCount: number;
	firstTitle: string;
	focusSourceId: number;
} ): string {
	if ( ! params.hasFetchedOnce ) {
		return sprintf(
			/* translators: %d: source post id */
			__( 'Looking up source #%d…', 'safe-publish' ),
			params.focusSourceId
		);
	}
	if ( 0 === params.itemsCount ) {
		return sprintf(
			/* translators: %d: source post id */
			__( 'No imported post for source #%d', 'safe-publish' ),
			params.focusSourceId
		);
	}
	return sprintf(
		/* translators: %s: imported post title */
		__( 'Viewing import for: %s', 'safe-publish' ),
		params.firstTitle
	);
}

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
		fields: [ 'import_date_gmt', 'local_status', 'post_type', 'sync_status' ],
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
	const [ searchTerm, setSearchTerm ] = useState( '' );
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const [ slugFromUrl, setSlugFromUrl ] = useState< string | null >( null );
	const [ selectedStatuses, setSelectedStatuses ] = useState< string[] >( [] );
	const [ selectedPostTypes, setSelectedPostTypes ] = useState< string[] >( [] );
	const [ importedAfter, setImportedAfter ] = useState< string | null >( null );
	const [ importedBefore, setImportedBefore ] = useState< string | null >( null );
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
	const searchDebounceRef = useRef< ReturnType< typeof setTimeout > | null >( null );

	const homeUrl = window.safePublishAdminData?.homeUrl ?? '';

	const orderby = 'title' === view.sort?.field ? 'title' : 'import_date';
	const order = 'asc' === view.sort?.direction ? 'asc' : 'desc';

	const hasActiveFilters = computeHasActiveFilters( {
		searchTerm,
		selectedStatuses,
		selectedPostTypes,
		importedAfter,
		importedBefore,
		batchSessionId,
		focusSourceId,
	} );

	// Latch DataViews mounted once it first appears, so a refetch that briefly
	// empties the result set (e.g. clearing a search) can't unmount it and pull
	// focus out of the search box.
	useEffect( () => {
		if ( pageItems.length > 0 || hasActiveFilters ) {
			setHasRenderedGrid( true );
		}
	}, [ pageItems.length, hasActiveFilters ] );

	const statusesKey = selectedStatuses.join( '|' );
	const postTypesKey = selectedPostTypes.join( '|' );

	useEffect( () => {
		const controller = new AbortController();

		const formData = buildListingFormData( {
			nonce: window.safePublishAdminData.nonce,
			page: view.page ?? 1,
			perPage: view.perPage ?? DEFAULT_ITEMS_PER_PAGE,
			orderby,
			order,
			debouncedSearch,
			slugFromUrl,
			statuses: selectedStatuses,
			postTypes: selectedPostTypes,
			importedAfter,
			importedBefore,
			batchSessionId,
			focusSourceId,
			withFacets: ! facetsLoadedRef.current,
		} );

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
		slugFromUrl,
		statusesKey,
		postTypesKey,
		importedAfter,
		importedBefore,
		batchSessionId,
		focusSourceId,
		refreshNonce,
	] );

	useEffect( () => () => {
		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
		}
	}, [] );

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
				id: 'post_type',
				label: __( 'Type', 'safe-publish' ),
				enableSorting: false,
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

	const resetPage = useCallback( (): void => {
		setView( ( current ) => ( { ...current, page: 1 } ) );
	}, [] );

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
		resetPage();
		stripUrlParam( 'batch' );
	}, [ resetPage ] );

	const clearFocus = useCallback( (): void => {
		setFocusSourceId( 0 );
		resetPage();
		stripUrlParam( 'focus_source' );
	}, [ resetPage ] );

	// A browse gesture while focus is active dismisses the deep link so the
	// gesture applies to the full listing, not the single focused row.
	const dismissFocusOnBrowse = useCallback( (): void => {
		if ( focusSourceId > 0 ) {
			clearFocus();
		}
	}, [ focusSourceId, clearFocus ] );

	const handleSearchChange = useCallback(
		( raw: string ): void => {
			setSearchTerm( raw );

			if ( searchDebounceRef.current ) {
				clearTimeout( searchDebounceRef.current );
			}

			searchDebounceRef.current = setTimeout( () => {
				const trimmed = raw.trim();
				// Skip URL detection if homeUrl is absent (older bundles)
				// rather than running it unvalidated.
				const slug = '' !== homeUrl
					? detectSlugFromInput( trimmed, homeUrl )
					: null;

				if ( null !== slug ) {
					setSlugFromUrl( slug );
					setDebouncedSearch( '' );
				} else {
					setSlugFromUrl( null );
					setDebouncedSearch( trimmed );
				}
				dismissFocusOnBrowse();
				resetPage();
			}, SEARCH_DEBOUNCE_MS );
		},
		[ homeUrl, dismissFocusOnBrowse, resetPage ]
	);

	const handleStatusesChange = useCallback(
		( tokens: ( string | { value: string } )[] ): void => {
			setSelectedStatuses( labelsToValues( tokens, STATUS_FILTER_ELEMENTS ) );
			dismissFocusOnBrowse();
			resetPage();
		},
		[ dismissFocusOnBrowse, resetPage ]
	);

	const handlePostTypesChange = useCallback(
		( tokens: ( string | { value: string } )[] ): void => {
			setSelectedPostTypes( labelsToValues( tokens, facets.post_types ) );
			dismissFocusOnBrowse();
			resetPage();
		},
		[ facets.post_types, dismissFocusOnBrowse, resetPage ]
	);

	const handleDateRangeChange = useCallback(
		( next: { after: string | null; before: string | null } ): void => {
			setImportedAfter( next.after );
			setImportedBefore( next.before );
			dismissFocusOnBrowse();
			resetPage();
		},
		[ dismissFocusOnBrowse, resetPage ]
	);

	const handleClearFilters = useCallback( (): void => {
		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
			searchDebounceRef.current = null;
		}

		setSearchTerm( '' );
		setDebouncedSearch( '' );
		setSlugFromUrl( null );
		setSelectedStatuses( [] );
		setSelectedPostTypes( [] );
		setImportedAfter( null );
		setImportedBefore( null );
		if ( batchSessionId > 0 ) {
			setBatchSessionId( 0 );
			stripUrlParam( 'batch' );
		}
		if ( focusSourceId > 0 ) {
			setFocusSourceId( 0 );
			stripUrlParam( 'focus_source' );
		}
		resetPage();
	}, [ batchSessionId, focusSourceId, resetPage ] );

	const handleViewChange = useCallback( ( next: View ): void => {
		const sortChanged =
			next.sort?.field !== view.sort?.field ||
			next.sort?.direction !== view.sort?.direction;
		const perPageChanged = next.perPage !== view.perPage;
		const browseGesture = sortChanged || perPageChanged;

		if ( browseGesture ) {
			dismissFocusOnBrowse();
		}

		// Sort/perPage changes reset to page 1; layout-only changes keep the
		// current page.
		setView( {
			...next,
			page: browseGesture ? 1 : ( next.page ?? view.page ?? 1 ),
		} );
	}, [ view, dismissFocusOnBrowse ] );

	const statusTokenValues = useMemo(
		() => valuesToLabels( selectedStatuses, STATUS_FILTER_ELEMENTS ),
		[ selectedStatuses ]
	);

	const postTypeTokenValues = useMemo(
		() => valuesToLabels( selectedPostTypes, facets.post_types ),
		[ selectedPostTypes, facets.post_types ]
	);

	const postTypeSuggestions = useMemo(
		() => facets.post_types.map( ( option ) => option.label ),
		[ facets.post_types ]
	);

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

	const focusPillText = composeFocusPillText( {
		hasFetchedOnce,
		itemsCount: pageItems.length,
		firstTitle: pageItems[ 0 ]?.title ?? '',
		focusSourceId,
	} );

	return (
		<div
			className="safe-publish-dataviews-wrapper"
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
			<div className="safe-publish-controls-row">
				<div className="safe-publish-control safe-publish-control--search">
					<BaseControl
						__nextHasNoMarginBottom
						label={ __( 'Title or URL', 'safe-publish' ) }
						id="safe-publish-imports-search-input"
					>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							id="safe-publish-imports-search-input"
							label={ __( 'Search titles', 'safe-publish' ) }
							hideLabelFromVision
							value={ searchTerm }
							onChange={ handleSearchChange }
						/>
					</BaseControl>
				</div>
				<DateRangeFilter
					label={ __( 'Last Imported', 'safe-publish' ) }
					id="safe-publish-imports-date"
					after={ importedAfter }
					before={ importedBefore }
					onChange={ handleDateRangeChange }
				/>
				<div className="safe-publish-control safe-publish-control--statuses">
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						__experimentalExpandOnFocus
						__experimentalShowHowTo={ false }
						label={ __( 'Local Status', 'safe-publish' ) }
						placeholder={ __( 'All statuses', 'safe-publish' ) }
						value={ statusTokenValues }
						suggestions={ STATUS_LABEL_SUGGESTIONS }
						onChange={ handleStatusesChange }
					/>
				</div>
				<div className="safe-publish-control safe-publish-control--statuses">
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						__experimentalExpandOnFocus
						__experimentalShowHowTo={ false }
						label={ __( 'Type', 'safe-publish' ) }
						placeholder={ __( 'All types', 'safe-publish' ) }
						value={ postTypeTokenValues }
						suggestions={ postTypeSuggestions }
						onChange={ handlePostTypesChange }
					/>
				</div>
				{ hasActiveFilters && (
					<Button variant="tertiary" onClick={ handleClearFilters }>
						{ __( 'Clear filters', 'safe-publish' ) }
					</Button>
				) }
			</div>
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
