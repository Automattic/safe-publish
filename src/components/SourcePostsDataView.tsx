/**
 * DataViews component for the source posts catalog.
 *
 * Server-paginated browser of the source site's catalog, backed by the
 * `safe-publish/v1/catalog/posts` endpoint. A custom toolbar drives all
 * filter/search/sort/page changes; DataViews handles only the table render,
 * sortable column clicks, layout switcher, and Prev/Next pagination.
 *
 * @file This file defines the SourcePostsDataView component.
 */
import { update } from '@wordpress/icons';

import AuthStatusNotice from './AuthStatusNotice';
import {
	calendarRangeToUtcBounds,
	DateRangeFilter,
	detectSlugFromInput,
} from './filter-controls';
import { useAuthStatus } from './hooks/useAuthStatus';
import { useStepBackWhenPageEmpties } from './hooks/useStepBackWhenPageEmpties';
import { getSyncStatusLabel } from './post-fields';
import { createActions } from '../actions';
import {
	DEFAULT_ITEMS_PER_PAGE,
	LAYOUT_TABLE,
	SEARCH_DEBOUNCE_MS,
} from '../constants';
import { PostTypeSelector } from '../post-type-selector';
import {
	composeOutdatedLabel,
	extractUrlPath,
	formatDateTime,
	getErrorMessage,
	PUBLISH_STATUS_LABELS,
	SYNC_STATUS_LABELS,
	UNKNOWN_SYNC_STATUS_TOOLTIP,
} from '../utils';
import {
	BaseControl,
	Button,
	FormTokenField,
	Notice,
	TextControl,
	Tooltip,
} from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import { useState, useEffect, useRef, useCallback, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	CatalogResponse,
	DataViewsField,
	SourcePostsDataViewProps,
	Post,
} from '../types';

/**
 * Source status slugs the catalog UI is allowed to filter on. Must match
 * the source-side controller's ALLOWED_STATUSES.
 */
const STATUS_VALUES: readonly string[] = [
	'publish',
	'draft',
	'pending',
	'private',
	'future',
];

/**
 * Pre-computed label list for the FormTokenField suggestions, hoisted so
 * we don't allocate a fresh array on every render.
 */
const STATUS_LABEL_SUGGESTIONS = STATUS_VALUES.map(
	// eslint-disable-next-line security/detect-object-injection -- value iterates STATUS_VALUES allowlist.
	( value ) => PUBLISH_STATUS_LABELS[ value ] ?? value
);

/**
 * SourcePostsDataView component.
 *
 * @param {Object} props               Component props.
 * @param {string} props.sourceSiteUrl Source site URL.
 *
 * @return {JSX.Element} Rendered DataViews component.
 */
export function SourcePostsDataView( {
	sourceSiteUrl,
}: SourcePostsDataViewProps ): JSX.Element {
	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: DEFAULT_ITEMS_PER_PAGE,
		page: 1,
		sort: {
			field: 'date_gmt',
			direction: 'desc',
		},
		fields: [
			'date_gmt',
			'source_status',
			'sync_status',
		],
		titleField: 'title',
		layout: { density: 'compact' },
	} );

	const defaultLayouts = { [ LAYOUT_TABLE ]: {} };

	const [ pagePosts, setPagePosts ] = useState< Post[] >( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ selectedPostType, setSelectedPostType ] = useState( 'post' );
	const [ searchTerm, setSearchTerm ] = useState( '' );
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const [ slugFromUrl, setSlugFromUrl ] = useState< string | null >( null );
	const [ selectedStatuses, setSelectedStatuses ] = useState< string[] >( [] );
	const [ publishedAfter, setPublishedAfter ] = useState< string | null >( null );
	const [ publishedBefore, setPublishedBefore ] = useState< string | null >( null );
	const [ isLoadingPosts, setIsLoadingPosts ] = useState( false );
	const [ hasFetchedOnce, setHasFetchedOnce ] = useState( false );
	const [ postTypeError, setPostTypeError ] = useState< string | null >( null );
	const [ fetchError, setFetchError ] = useState< string | null >( null );
	const [ refreshNonce, setRefreshNonce ] = useState( 0 );

	const refresh = useCallback(
		() => setRefreshNonce( ( nonce ) => nonce + 1 ),
		[]
	);

	const searchDebounceRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const abortRef = useRef< AbortController | null >( null );

	const authStatus = useAuthStatus();
	const isAuthorized = 'authorized' === authStatus;
	// Don't lock Refresh on transient probe failures — a network blip
	// shouldn't force the user to full-reload. Only a confirmed credential
	// rejection blocks retry.
	const refreshBlocked = 'unauthorized' === authStatus;

	// Statuses join into a stable key so the effect re-fires on add/remove
	// without re-firing on identical lists wrapped in a fresh array.
	const statusKey = selectedStatuses.join( '|' );

	useEffect( () => {
		if ( ! sourceSiteUrl ) {
			return;
		}

		// Abort any in-flight fetch so a fast typist doesn't see stale data
		// land after their newest query.
		abortRef.current?.abort();
		const controller = new AbortController();
		abortRef.current = controller;

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_fetch_posts' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'source_site_url', sourceSiteUrl );
		formData.append( 'post_type', selectedPostType );
		formData.append( 'page', String( view.page ?? 1 ) );
		formData.append( 'per_page', String( view.perPage ?? DEFAULT_ITEMS_PER_PAGE ) );
		formData.append( 'orderby', view.sort?.field === 'title' ? 'title' : 'date' );
		formData.append( 'order', view.sort?.direction === 'asc' ? 'asc' : 'desc' );

		if ( null !== slugFromUrl ) {
			formData.append( 'name', slugFromUrl );
		} else if ( '' !== debouncedSearch ) {
			formData.append( 'search', debouncedSearch );
		}

		selectedStatuses.forEach( ( status ) => {
			formData.append( 'status[]', status );
		} );

		const { afterUtc, beforeUtc } = calendarRangeToUtcBounds(
			publishedAfter,
			publishedBefore
		);
		if ( null !== afterUtc ) {
			formData.append( 'published_after', afterUtc );
		}
		if ( null !== beforeUtc ) {
			formData.append( 'published_before', beforeUtc );
		}

		setIsLoadingPosts( true );
		setFetchError( null );

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
			signal: controller.signal,
		} )
			.then( ( response ) =>
				response.json() as Promise< ApiResponse< CatalogResponse > >
			)
			.then( ( result ) => {
				// Aborted fetches that already resolved still flow into .then; bail.
				if ( controller.signal.aborted ) {
					return;
				}
				if ( result.success ) {
					setPagePosts( result.data.items );
					setHasMore( Boolean( result.data.has_more ) );
				} else {
					setFetchError(
						getErrorMessage(
							result,
							__( 'Failed to load posts.', 'safe-publish' )
						)
					);
					setPagePosts( [] );
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
				setFetchError( __( 'Network error while loading posts.', 'safe-publish' ) );
				setPagePosts( [] );
				setHasMore( false );
			} )
			.finally( () => {
				if ( controller.signal.aborted ) {
					return;
				}
				setIsLoadingPosts( false );
				setHasFetchedOnce( true );
			} );

		return () => {
			controller.abort();
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		sourceSiteUrl,
		selectedPostType,
		view.page,
		view.perPage,
		view.sort?.field,
		view.sort?.direction,
		debouncedSearch,
		slugFromUrl,
		statusKey,
		publishedAfter,
		publishedBefore,
		refreshNonce,
	] );

	// Cleanup outstanding fetch + debounce timer on unmount.
	useEffect( () => () => {
		abortRef.current?.abort();
		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
		}
	}, [] );

	const resetPage = useCallback( ( next: Partial< View > = {} ): void => {
		// Cast: the spread destructures the View discriminated union into
		// its base shape, but we always preserve `type` so reconstructing
		// is safe.
		setView( ( current ) => ( { ...current, ...next, page: 1 } as View ) );
	}, [] );

	const setPage = useCallback(
		( next: number ): void =>
			setView( ( current ) => ( { ...current, page: next } ) ),
		[]
	);

	// Upstream deletions can shrink the listing past the current page.
	useStepBackWhenPageEmpties( {
		hasFetchedOnce,
		isLoading: isLoadingPosts,
		fetchError,
		isEmpty: 0 === pagePosts.length,
		page: view.page,
		setPage,
	} );

	const handleSearchChange = ( raw: string ): void => {
		setSearchTerm( raw );

		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
		}

		searchDebounceRef.current = setTimeout( () => {
			const trimmed = raw.trim();
			const slug = detectSlugFromInput( trimmed, sourceSiteUrl );

			if ( null !== slug ) {
				setSlugFromUrl( slug );
				setDebouncedSearch( '' );
			} else {
				setSlugFromUrl( null );
				setDebouncedSearch( trimmed );
			}
			resetPage();
		}, SEARCH_DEBOUNCE_MS );
	};

	const handlePostTypeChange = ( postType: string ): void => {
		setSelectedPostType( postType );
		resetPage();
	};

	const handleClearFilters = (): void => {
		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
			searchDebounceRef.current = null;
		}

		setSearchTerm( '' );
		setDebouncedSearch( '' );
		setSlugFromUrl( null );
		setSelectedStatuses( [] );
		setPublishedAfter( null );
		setPublishedBefore( null );
		resetPage();
	};

	const hasActiveFilters =
		'' !== searchTerm ||
		selectedStatuses.length > 0 ||
		null !== publishedAfter ||
		null !== publishedBefore;

	const handleStatusesChange = ( tokens: ( string | { value: string } )[] ): void => {
		const next = tokens
			.map( ( token ) => ( 'string' === typeof token ? token : token.value ) )
			.map( ( label ) => {
				const match = STATUS_VALUES.find(
					// eslint-disable-next-line security/detect-object-injection -- value iterates STATUS_VALUES allowlist.
					( value ) => ( PUBLISH_STATUS_LABELS[ value ] ?? value ) === label
				);
				return match ?? label;
			} )
			.filter( ( value ): value is string => STATUS_VALUES.includes( value ) );

		setSelectedStatuses( next );
		resetPage();
	};

	const handleViewChange = ( next: View ): void => {
		const sortChanged =
			next.sort?.field !== view.sort?.field ||
			next.sort?.direction !== view.sort?.direction;
		const perPageChanged = next.perPage !== view.perPage;

		// Layout-only updates omit `page` from `next`; preserve the current
		// page rather than snapping the user back to 1.
		setView( {
			...next,
			page: sortChanged || perPageChanged ? 1 : ( next.page ?? view.page ?? 1 ),
		} );
	};

	// useMemo: the field render/getValue closures are pure, so a single
	// allocation is enough — recreating per render churns DataViews' prop
	// identity and defeats its internal memoization.
	const fields: DataViewsField< Post >[] = useMemo( () => [
		{
			id: 'title',
			label: __( 'Title', 'safe-publish' ),
			enableSorting: true,
			render: ( { item }: { item: Post } ): JSX.Element => {
				// Drafts return the source home URL — show plain text
				// rather than a misleading link.
				const path = extractUrlPath( item.link );
				if ( '' === item.link || '/' === path ) {
					return <span>{ item.title }</span>;
				}
				return (
					<a
						href={ item.link }
						target="_blank"
						rel="noopener noreferrer"
						title={ item.link }
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
			getValue: ( { item }: { item: Post } ): string =>
				getSyncStatusLabel( item ),
			render: ( { item }: { item: Post } ): JSX.Element => {
				switch ( item.sync_status ) {
					case 'outdated': {
						const label = '' === item.modified_gmt
							? __( 'Outdated', 'safe-publish' )
							: composeOutdatedLabel( item.modified_gmt );
						return (
							<span className="safe-publish-status-badge safe-publish-status-badge--outdated">
								<span className="safe-publish-status-badge__dot" aria-hidden="true" />
								{ label }
							</span>
						);
					}
					case 'up-to-date':
						return (
							<span className="safe-publish-status-badge safe-publish-status-badge--up-to-date">
								<span className="safe-publish-status-badge__dot" aria-hidden="true" />
								{ SYNC_STATUS_LABELS.upToDate }
							</span>
						);
					case 'unknown':
						return (
							<Tooltip text={ UNKNOWN_SYNC_STATUS_TOOLTIP }>
								<span
									className="safe-publish-status-badge safe-publish-status-badge--unknown"
									tabIndex={ 0 }
								>
									<span className="safe-publish-status-badge__dot" aria-hidden="true" />
									{ SYNC_STATUS_LABELS.unknown }
								</span>
							</Tooltip>
						);
					default:
						return (
							<span className="safe-publish-status-badge safe-publish-status-badge--available">
								<span className="safe-publish-status-badge__dot" aria-hidden="true" />
								{ SYNC_STATUS_LABELS.available }
							</span>
						);
				}
			},
		},
		{
			id: 'source_status',
			label: __( 'Source Status', 'safe-publish' ),
			enableSorting: false,
			getValue: ( { item }: { item: Post } ): string =>
				PUBLISH_STATUS_LABELS[ item.status ] ?? item.status,
			render: ( { item }: { item: Post } ): JSX.Element => {
				const label = PUBLISH_STATUS_LABELS[ item.status ] ?? item.status;
				const modifierClass = `safe-publish-status-badge--${ item.status }`;
				return (
					<span className={ `safe-publish-status-badge safe-publish-status-badge--quiet ${ modifierClass }` }>
						<span className="safe-publish-status-badge__dot" aria-hidden="true" />
						{ label }
					</span>
				);
			},
		},
		{
			id: 'date_gmt',
			label: __( 'Published Date', 'safe-publish' ),
			enableSorting: true,
			getValue: ( { item }: { item: Post } ): string => item.date_gmt,
			render: ( { item }: { item: Post } ): JSX.Element => (
				<span>{ '' === item.date_gmt ? '—' : formatDateTime( item.date_gmt ) }</span>
			),
		},
	], [] );

	// Server-side pagination: no total upfront. On the last page we compute
	// the true total from what's been seen; on earlier pages we overestimate
	// by 1 so DataViews keeps rendering Next.
	const currentPage = view.page ?? 1;
	const currentPerPage = view.perPage ?? DEFAULT_ITEMS_PER_PAGE;
	const paginationInfo = useMemo(
		() => ! hasMore
			? {
				totalItems: ( currentPage - 1 ) * currentPerPage + pagePosts.length,
				totalPages: currentPage,
			}
			: {
				totalItems: currentPage * currentPerPage + 1,
				totalPages: currentPage + 1,
			},
		[ currentPage, currentPerPage, hasMore, pagePosts.length ]
	);

	const tokenValues = useMemo(
		() => selectedStatuses.map(
			// status from STATUS_VALUES allowlist via handleStatusesChange,
			// not arbitrary input.
			// eslint-disable-next-line security/detect-object-injection
			( status ) => PUBLISH_STATUS_LABELS[ status ] ?? status
		),
		[ selectedStatuses ]
	);

	// Surface the current page via a CSS variable so the DataViews
	// pagination row can render "Page N" via ::before (see CSS). We replace
	// DataViews' built-in page-select because our has_more pagination
	// doesn't know the true total, so the SelectControl would list fake
	// pages and the "of Y" suffix would grow on every Next click.
	const pageStatusText = sprintf(
		/* translators: %d: current page number */
		__( 'Page %d', 'safe-publish' ),
		currentPage
	);

	return (
		<div
			className="safe-publish-dataviews-wrapper safe-publish-dataviews-wrapper--approx-pagination"
			style={ { '--safe-publish-page-text': `"${ pageStatusText }"` } as React.CSSProperties }
		>
			<AuthStatusNotice
				status={ authStatus }
				settingsUrl={ window.safePublishAdminData?.settingsUrl }
			/>
			<div className="safe-publish-controls-row">
				<PostTypeSelector
					sourceSiteUrl={ sourceSiteUrl }
					selectedPostType={ selectedPostType }
					onPostTypeChange={ handlePostTypeChange }
					onError={ setPostTypeError }
				/>
				<div className="safe-publish-control safe-publish-control--search">
					<BaseControl
						__nextHasNoMarginBottom
						label={ __( 'Title or URL', 'safe-publish' ) }
						id="safe-publish-search-input"
					>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							id="safe-publish-search-input"
							label={ __( 'Search titles', 'safe-publish' ) }
							hideLabelFromVision
							value={ searchTerm }
							onChange={ handleSearchChange }
						/>
					</BaseControl>
				</div>
				<DateRangeFilter
					label={ __( 'Published Date', 'safe-publish' ) }
					id="safe-publish-published-date"
					after={ publishedAfter }
					before={ publishedBefore }
					onChange={ ( next ) => {
						setPublishedAfter( next.after );
						setPublishedBefore( next.before );
						resetPage();
					} }
				/>
				<div className="safe-publish-control safe-publish-control--statuses">
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						__experimentalExpandOnFocus
						__experimentalShowHowTo={ false }
						label={ __( 'Source Status', 'safe-publish' ) }
						placeholder={ __( 'All statuses', 'safe-publish' ) }
						value={ tokenValues }
						suggestions={ STATUS_LABEL_SUGGESTIONS }
						onChange={ handleStatusesChange }
					/>
				</div>
				{ hasActiveFilters && (
					<Button
						variant="tertiary"
						onClick={ handleClearFilters }
					>
						{ __( 'Clear filters', 'safe-publish' ) }
					</Button>
				) }
			</div>
			{ postTypeError && (
				<Notice
					className="safe-publish-post-type-error"
					status="error"
					onRemove={ () => setPostTypeError( null ) }
				>
					{ postTypeError }
				</Notice>
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
			{ isLoadingPosts && ! hasFetchedOnce && (
				<div className="safe-publish-loading" role="status" aria-live="polite">
					<p>{ __( 'Loading posts…', 'safe-publish' ) }</p>
				</div>
			) }
			{ hasFetchedOnce && ! fetchError && 0 === pagePosts.length && ! isLoadingPosts && (
				<div className="safe-publish-no-data" role="status" aria-live="polite">
					<p>{ __( 'No posts matched these filters.', 'safe-publish' ) }</p>
				</div>
			) }
			{ hasFetchedOnce && pagePosts.length > 0 && (
				<DataViews
					getItemId={ ( item: Post ) => item.id.toString() }
					data={ pagePosts }
					fields={ fields }
					view={ view }
					onChangeView={ handleViewChange }
					paginationInfo={ paginationInfo }
					defaultLayouts={ defaultLayouts }
					actions={ createActions( refresh, isAuthorized, {
						ajaxurl: window.safePublishAdminData.ajaxurl,
						nonce: window.safePublishAdminData.nonce,
					} ) }
					header={
						<Button
							variant="tertiary"
							isBusy={ isLoadingPosts }
							disabled={ isLoadingPosts || refreshBlocked }
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
