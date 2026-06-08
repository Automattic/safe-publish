/**
 * DataViews component for the Imports → Failures tab.
 *
 * Lists items with status 'error' from `safe_publish_list_failed_imports`. A
 * small custom toolbar drives the title search and Attempted date range;
 * DataViews handles only the table render and Prev/Next pagination. Recovery
 * happens by fixing the source and re-importing from Source Posts;
 * acknowledged failures can also be removed inline via the Remove action.
 *
 * @file This file defines the FailedImportsDataView component.
 */
import { update } from '@wordpress/icons';

import { DateRangeFilter } from './filter-controls';
import { useDelayedFlag } from './hooks/useDelayedFlag';
import { useStepBackWhenPageEmpties } from './hooks/useStepBackWhenPageEmpties';
import { createFailedImportsActions } from '../actions';
import {
	DEFAULT_ITEMS_PER_PAGE,
	LAYOUT_TABLE,
	SEARCH_DEBOUNCE_MS,
} from '../constants';
import { formatDateTime, getErrorMessage } from '../utils';
import {
	BaseControl,
	Button,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import {
	useState,
	useEffect,
	useMemo,
	useCallback,
	useRef,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	DataViewsField,
	FailedImport,
	FailedImportsResponse,
} from '../types';

/**
 * Builds the FormData payload for the failed-imports listing request.
 * Extracted from the fetch effect to keep the component body under the
 * complexity budget.
 *
 * @param {Object}      params                 Request parameters.
 * @param {string}      params.nonce           AJAX nonce.
 * @param {number}      params.page            1-indexed page number.
 * @param {number}      params.perPage         Items per page.
 * @param {string}      params.debouncedSearch Debounced title search.
 * @param {string|null} params.attemptedAfter  Lower bound on import_date_gmt.
 * @param {string|null} params.attemptedBefore Upper bound on import_date_gmt.
 *
 * @return {FormData} Populated payload.
 */
function buildListingFormData( params: {
	nonce: string;
	page: number;
	perPage: number;
	debouncedSearch: string;
	attemptedAfter: string | null;
	attemptedBefore: string | null;
} ): FormData {
	const formData = new FormData();
	formData.append( 'action', 'safe_publish_list_failed_imports' );
	formData.append( 'nonce', params.nonce );
	formData.append( 'page', String( params.page ) );
	formData.append( 'per_page', String( params.perPage ) );
	if ( '' !== params.debouncedSearch ) {
		formData.append( 'search', params.debouncedSearch );
	}
	if ( null !== params.attemptedAfter ) {
		formData.append( 'attempted_after', params.attemptedAfter );
	}
	if ( null !== params.attemptedBefore ) {
		formData.append( 'attempted_before', params.attemptedBefore );
	}
	return formData;
}

/**
 * Derives which of the listing's mutually-exclusive display states to show.
 * Mirrors the Imports → Posts helper so the component body stays under the
 * complexity budget.
 *
 * @param {Object}  flags                  Current fetch/filter flags.
 * @param {boolean} flags.hasFetchedOnce   Whether the first fetch completed.
 * @param {boolean} flags.isLoading        Whether a fetch is in flight.
 * @param {boolean} flags.isEmpty          Whether the current page has no rows.
 * @param {boolean} flags.hasError         Whether a fetch error is showing.
 * @param {boolean} flags.hasActiveFilters Whether search/filters are active.
 *
 * @return {{ showLoading: boolean, showEmptyState: boolean, showNoMatches: boolean }}
 *         The display state to render.
 */
function getDisplayState( flags: {
	hasFetchedOnce: boolean;
	isLoading: boolean;
	isEmpty: boolean;
	hasError: boolean;
	hasActiveFilters: boolean;
} ): {
	showLoading: boolean;
	showEmptyState: boolean;
	showNoMatches: boolean;
} {
	const hasEmptyResult =
		flags.hasFetchedOnce &&
		! flags.isLoading &&
		flags.isEmpty &&
		! flags.hasError;
	return {
		showLoading: flags.isLoading && ! flags.hasFetchedOnce,
		showEmptyState: hasEmptyResult && ! flags.hasActiveFilters,
		showNoMatches: hasEmptyResult && flags.hasActiveFilters,
	};
}

/**
 * FailedImportsDataView component.
 *
 * @return {JSX.Element} Rendered DataViews component for failed imports.
 */
export function FailedImportsDataView(): JSX.Element {
	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: DEFAULT_ITEMS_PER_PAGE,
		page: 1,
		fields: [ 'error_message', 'import_date_gmt' ],
		titleField: 'title',
		layout: { density: 'compact' },
	} );

	const defaultLayouts = useMemo(
		() => ( { [ LAYOUT_TABLE ]: {} } ),
		[]
	);

	const [ pageItems, setPageItems ] = useState< FailedImport[] >( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ hasFetchedOnce, setHasFetchedOnce ] = useState( false );
	const [ fetchError, setFetchError ] = useState< string | null >( null );
	const [ refreshNonce, setRefreshNonce ] = useState( 0 );
	const [ searchTerm, setSearchTerm ] = useState( '' );
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const [ attemptedAfter, setAttemptedAfter ] = useState< string | null >( null );
	const [ attemptedBefore, setAttemptedBefore ] = useState< string | null >( null );

	const searchDebounceRef = useRef< ReturnType< typeof setTimeout > | null >( null );

	// Read live searchTerm so the Clear button and "no matches" message
	// respond immediately rather than during the 400 ms debounce.
	const hasActiveFilters =
		'' !== searchTerm ||
		null !== attemptedAfter ||
		null !== attemptedBefore;

	useEffect( () => {
		const controller = new AbortController();

		const formData = buildListingFormData( {
			nonce: window.safePublishAdminData.nonce,
			page: view.page ?? 1,
			perPage: view.perPage ?? DEFAULT_ITEMS_PER_PAGE,
			debouncedSearch,
			attemptedAfter,
			attemptedBefore,
		} );

		setIsLoading( true );
		setFetchError( null );

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
			signal: controller.signal,
		} )
			.then( ( response ) =>
				response.json() as Promise< ApiResponse< FailedImportsResponse > >
			)
			.then( ( result ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( result.success ) {
					setPageItems( result.data.items );
					setHasMore( Boolean( result.data.has_more ) );
				} else {
					setFetchError(
						getErrorMessage(
							result,
							__( 'Failed to load failed imports.', 'safe-publish' )
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
					__( 'Network error while loading failed imports.', 'safe-publish' )
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
	}, [
		view.page,
		view.perPage,
		debouncedSearch,
		attemptedAfter,
		attemptedBefore,
		refreshNonce,
	] );

	useEffect( () => () => {
		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
		}
	}, [] );

	const setPage = useCallback(
		( next: number ): void =>
			setView( ( current ) => ( { ...current, page: next } ) ),
		[]
	);

	const resetPage = useCallback( (): void => {
		setView( ( current ) => ( { ...current, page: 1 } ) );
	}, [] );

	// Remove can shrink the listing past the current page.
	useStepBackWhenPageEmpties( {
		hasFetchedOnce,
		isLoading,
		fetchError,
		isEmpty: 0 === pageItems.length,
		page: view.page,
		setPage,
	} );

	const fields: DataViewsField< FailedImport >[] = useMemo(
		() => [
			{
				id: 'title',
				label: __( 'Title', 'safe-publish' ),
				enableSorting: false,
				render: ( { item }: { item: FailedImport } ): JSX.Element => (
					<span>{ item.title }</span>
				),
			},
			{
				id: 'source',
				label: __( 'Source', 'safe-publish' ),
				enableSorting: false,
				getValue: ( { item }: { item: FailedImport } ): string =>
					item.source_site_url,
				render: ( { item }: { item: FailedImport } ): JSX.Element => {
					if ( '' === item.source_site_url ) {
						return <span>—</span>;
					}
					return (
						<a
							href={ item.source_site_url }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ item.source_site_url }
						</a>
					);
				},
			},
			{
				id: 'error_message',
				label: __( 'Error', 'safe-publish' ),
				enableSorting: false,
				render: ( { item }: { item: FailedImport } ): JSX.Element => (
					<span className="safe-publish-failed-error">
						{ item.error_message || __( 'Unknown error', 'safe-publish' ) }
					</span>
				),
			},
			{
				id: 'import_date_gmt',
				label: __( 'Attempted', 'safe-publish' ),
				enableSorting: false,
				render: ( { item }: { item: FailedImport } ): JSX.Element => (
					<span>
						{ formatDateTime(
							`${ item.import_date_gmt.replace( ' ', 'T' ) }Z`
						) }
					</span>
				),
			},
		],
		[]
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

	const handleViewChange = useCallback( ( next: View ): void => {
		setView( ( current ) => {
			// No sort/filter controls inside DataViews; perPage is the only
			// trigger that should reset pagination from here.
			const perPageChanged = next.perPage !== current.perPage;
			return {
				...next,
				page: perPageChanged ? 1 : ( next.page ?? current.page ?? 1 ),
			};
		} );
	}, [] );

	const handleSearchChange = useCallback(
		( raw: string ): void => {
			setSearchTerm( raw );

			if ( searchDebounceRef.current ) {
				clearTimeout( searchDebounceRef.current );
			}

			searchDebounceRef.current = setTimeout( () => {
				setDebouncedSearch( raw.trim() );
				resetPage();
			}, SEARCH_DEBOUNCE_MS );
		},
		[ resetPage ]
	);

	const handleDateRangeChange = useCallback(
		( next: { after: string | null; before: string | null } ): void => {
			setAttemptedAfter( next.after );
			setAttemptedBefore( next.before );
			resetPage();
		},
		[ resetPage ]
	);

	const handleClearFilters = useCallback( (): void => {
		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
			searchDebounceRef.current = null;
		}

		setSearchTerm( '' );
		setDebouncedSearch( '' );
		setAttemptedAfter( null );
		setAttemptedBefore( null );
		resetPage();
	}, [ resetPage ] );

	const refresh = useCallback(
		() => setRefreshNonce( ( nonce ) => nonce + 1 ),
		[]
	);
	const actions = useMemo(
		() =>
			createFailedImportsActions( refresh, {
				ajaxurl: window.safePublishAdminData.ajaxurl,
				nonce: window.safePublishAdminData.nonce,
			} ),
		[ refresh ]
	);

	const { showLoading, showEmptyState, showNoMatches } = getDisplayState( {
		hasFetchedOnce,
		isLoading,
		isEmpty: 0 === pageItems.length,
		hasError: null !== fetchError,
		hasActiveFilters,
	} );

	// Suppress "Updating…" when the refetch completes within a frame or two.
	const showRefetch = useDelayedFlag( isLoading && hasFetchedOnce, 200 );

	return (
		<div
			className="safe-publish-dataviews-wrapper"
			style={
				{
					'--safe-publish-page-text': `"${ pageStatusText }"`,
				} as React.CSSProperties
			}
		>
			<div className="safe-publish-controls-row">
				<div className="safe-publish-control safe-publish-control--search">
					<BaseControl
						__nextHasNoMarginBottom
						label={ __( 'Title', 'safe-publish' ) }
						id="safe-publish-failures-search-input"
					>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							id="safe-publish-failures-search-input"
							label={ __( 'Search titles', 'safe-publish' ) }
							hideLabelFromVision
							value={ searchTerm }
							onChange={ handleSearchChange }
						/>
					</BaseControl>
				</div>
				<DateRangeFilter
					label={ __( 'Attempted', 'safe-publish' ) }
					id="safe-publish-failures-date"
					after={ attemptedAfter }
					before={ attemptedBefore }
					onChange={ handleDateRangeChange }
				/>
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
					<p>{ __( 'Loading failed imports…', 'safe-publish' ) }</p>
				</div>
			) }
			{ showEmptyState && (
				<div className="safe-publish-no-data" role="status" aria-live="polite">
					<p>{ __( 'No failed imports.', 'safe-publish' ) }</p>
				</div>
			) }
			{ showNoMatches && (
				<div className="safe-publish-no-data" role="status" aria-live="polite">
					<p>{ __( 'No failed imports matched these filters.', 'safe-publish' ) }</p>
				</div>
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
			{ hasFetchedOnce && ( pageItems.length > 0 || null !== fetchError ) && (
				<DataViews
					getItemId={ ( item: FailedImport ) => item.id.toString() }
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
