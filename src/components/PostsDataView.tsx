/**
 * Unified Posts listing. The chip row picks the data source: All/Available
 * are catalog-primary; Imported/Outdated/Failed are local-primary.
 *
 * @file This file defines the PostsDataView component.
 */
import { cancelCircleFilled, caution, help, update } from '@wordpress/icons';

import AttentionDrawer from './AttentionDrawer';
import AuthStatusNotice from './AuthStatusNotice';
import OrphanFailuresDrawer from './OrphanFailuresDrawer';
import {
	calendarRangeToUtcBounds,
	DateRangeFilter,
	detectSlugFromInput,
	slugMatchesChip,
	type SlugDetection,
} from './filter-controls';
import { useAuthStatus } from './hooks/useAuthStatus';
import { useStepBackWhenPageEmpties } from './hooks/useStepBackWhenPageEmpties';
import { createPostsActions, type ActionNotice } from '../actions';
import {
	DEFAULT_ITEMS_PER_PAGE,
	LAYOUT_TABLE,
	SEARCH_DEBOUNCE_MS,
} from '../constants';
import { PostTypeSelector } from '../post-type-selector';
import {
	extractUrlPath,
	formatBadgeTimestamp,
	getErrorMessage,
	PUBLISH_STATUS_LABELS,
} from '../utils';
import {
	BaseControl,
	Button,
	Dropdown,
	FormTokenField,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	ChipState,
	DataViewsField,
	ImportSyncStatus,
	LocalState,
	PostsDataViewProps,
	PostsResponse,
	SyncStatusBatchResponse,
	UnifiedPostRow,
} from '../types';

/**
 * Source-status filter allowlist; mirrors the catalog endpoint.
 */
const STATUS_VALUES: readonly string[] = [
	'publish',
	'draft',
	'pending',
	'private',
	'future',
];

const STATUS_LABEL_SUGGESTIONS = STATUS_VALUES.map(
	// value iterates the STATUS_VALUES allowlist.
	// eslint-disable-next-line security/detect-object-injection
	( value ) => PUBLISH_STATUS_LABELS[ value ] ?? value
);

/**
 * Reads a URL search parameter as a positive integer.
 *
 * @param {string} name Parameter name to read.
 * @return {number} Parsed positive integer, or 0 if absent/invalid.
 */
const readPositiveIntParam = ( name: string ): number => {
	const param = new URLSearchParams( window.location.search ).get( name );
	const parsed = param ? parseInt( param, 10 ) : 0;
	return Number.isFinite( parsed ) && parsed > 0 ? parsed : 0;
};

/**
 * Reads the ?state= deep-link as a chip value.
 *
 * @return {ChipState} Initial chip state.
 */
const readInitialState = (): ChipState => {
	const raw = new URLSearchParams( window.location.search ).get( 'state' );
	const allowed: ChipState[] = [
		'all',
		'available',
		'up-to-date',
		'outdated',
		'failed',
	];
	return allowed.includes( raw as ChipState ) ? ( raw as ChipState ) : 'all';
};

/**
 * Removes a URL search param without reloading.
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
 * Computes the orderby param for the listing endpoint.
 *
 * @param {string|undefined} sortField        DataViews sort field.
 * @param {boolean}          isCatalogPrimary Whether the chip is catalog-primary.
 * @return {string} Param value: 'title' | 'date' | 'import_date'.
 */
const computeOrderby = (
	sortField: string | undefined,
	isCatalogPrimary: boolean
): string => {
	if ( 'title' === sortField ) {
		return 'title';
	}
	return isCatalogPrimary ? 'date' : 'import_date';
};

/**
 * Computes which DataViews columns to show for a chip state.
 *
 * @param {boolean}   isCatalogPrimary Whether the chip is catalog-primary.
 * @param {ChipState} state            Active chip.
 * @return {string[]} Visible field ids.
 */
const computeVisibleFields = (
	isCatalogPrimary: boolean,
	state: ChipState
): string[] => {
	if ( isCatalogPrimary ) {
		return [
			'date_gmt',
			'local_state',
			'wp_post_status',
			'source_status',
		];
	}
	if ( 'failed' === state ) {
		return [ 'import_date_gmt', 'local_state' ];
	}
	return [
		'import_date_gmt',
		'local_state',
		'wp_post_status',
	];
};

/**
 * Local-state filter dropdown for the unified listing.
 *
 * @param  root0
 * @param  root0.value
 * @param  root0.onChange
 * @return {JSX.Element} Rendered select control.
 */
function StateSelect( {
	value,
	onChange,
}: {
	value: ChipState;
	onChange: ( next: ChipState ) => void;
} ): JSX.Element {
	return (
		<div className="safe-publish-control safe-publish-control--state">
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Local State', 'safe-publish' ) }
				value={ value }
				options={ [
					{ value: 'all', label: __( 'All', 'safe-publish' ) },
					{
						value: 'available',
						label: __( 'Available', 'safe-publish' ),
					},
					{
						value: 'up-to-date',
						label: __( 'Up to date', 'safe-publish' ),
					},
					{
						value: 'outdated',
						label: __( 'Outdated', 'safe-publish' ),
					},
					{ value: 'failed', label: __( 'Failed', 'safe-publish' ) },
				] }
				onChange={ ( next ) => onChange( next as ChipState ) }
			/>
		</div>
	);
}

/**
 * Inline help affordance for the search field — opens a popover with a
 * short explainer of the text vs URL-paste behavior.
 *
 * @return {JSX.Element} Rendered help button.
 */
function SearchHelpButton(): JSX.Element {
	return (
		<Dropdown
			// Content has nothing focusable; 'container' lets the wrapper
			// take focus so outside-click can dismiss.
			popoverProps={ {
				placement: 'bottom-start',
				focusOnMount: 'container',
			} }
			renderToggle={ ( {
				isOpen,
				onToggle,
			}: {
				isOpen: boolean;
				onToggle: () => void;
			} ) => (
				<Button
					size="small"
					icon={ help }
					iconSize={ 16 }
					label={ __( 'Search help', 'safe-publish' ) }
					showTooltip
					aria-expanded={ isOpen }
					// Prevent the click from bubbling to the wrapping
					// <label htmlFor=>, which would otherwise steal focus
					// into the search input the moment we open.
					onClick={ ( event: React.MouseEvent ) => {
						event.stopPropagation();
						onToggle();
					} }
				/>
			) }
			renderContent={ () => (
				<div className="safe-publish-search-help">
					<p>
						<strong>{ __( 'Type text', 'safe-publish' ) }</strong>
						{ ' — ' }
						{ __(
							'substring match against post titles.',
							'safe-publish'
						) }
					</p>
					<p>
						<strong>
							{ __( 'Paste a URL or /path', 'safe-publish' ) }
						</strong>
						{ ' — ' }
						{ __(
							'finds the exact post by slug. Source links match on All and Available; destination links on Up to date and Outdated.',
							'safe-publish'
						) }
					</p>
					<p>
						<em>
							{ __(
								'The URL needs https:// or a leading /, and the slug must match exactly — no partials.',
								'safe-publish'
							) }
						</em>
					</p>
				</div>
			) }
		/>
	);
}

/**
 * PostsDataView component.
 *
 * @param {PostsDataViewProps} props Component props.
 * @return {JSX.Element} Rendered Posts listing.
 */
// State-routed listing centralizes chip, filter, fetch, sync-status, drawer,
// and focus-source flows in one component.
// eslint-disable-next-line complexity
export function PostsDataView( {
	sourceSiteUrl,
}: PostsDataViewProps ): JSX.Element {
	const [ state, setStateValue ] = useState< ChipState >( readInitialState );
	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: DEFAULT_ITEMS_PER_PAGE,
		page: 1,
		sort: { field: 'date_gmt', direction: 'desc' },
		fields: [
			'date_gmt',
			'local_state',
			'wp_post_status',
			'source_status',
		],
		titleField: 'title',
		layout: { density: 'compact' },
	} );

	const [ rows, setRows ] = useState< UnifiedPostRow[] >( [] );
	const [ hasMore, setHasMore ] = useState( false );
	const [ orphanCount, setOrphanCount ] = useState(
		window.safePublishAdminData.orphanCount ?? 0
	);
	const [ attentionCount, setAttentionCount ] = useState(
		window.safePublishAdminData.attentionCount ?? 0
	);
	const [ isOrphanDrawerOpen, setIsOrphanDrawerOpen ] = useState( false );
	const [ isAttentionDrawerOpen, setIsAttentionDrawerOpen ] = useState( false );

	const [ selectedPostType, setSelectedPostType ] = useState( 'post' );
	const [ searchTerm, setSearchTerm ] = useState( '' );
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const [ detection, setDetection ] = useState< SlugDetection | null >( null );
	const [ selectedStatuses, setSelectedStatuses ] = useState< string[] >( [] );
	const [ publishedAfter, setPublishedAfter ] = useState< string | null >( null );
	const [ publishedBefore, setPublishedBefore ] = useState< string | null >(
		null
	);
	const [ importedAfter, setImportedAfter ] = useState< string | null >( null );
	const [ importedBefore, setImportedBefore ] = useState< string | null >( null );

	const [ focusSourceId, setFocusSourceId ] = useState( () =>
		readPositiveIntParam( 'focus_source' )
	);
	const [ focusedSourceId, setFocusedSourceId ] = useState< number | null >(
		null
	);

	const [ isLoading, setIsLoading ] = useState( false );
	const [ hasFetchedOnce, setHasFetchedOnce ] = useState( false );
	const [ fetchError, setFetchError ] = useState< string | null >( null );
	const [ postTypeError, setPostTypeError ] = useState< string | null >( null );
	const [ rollbackNotice, setRollbackNotice ] = useState< ActionNotice | null >(
		null
	);
	const [ refreshNonce, setRefreshNonce ] = useState( 0 );
	const [ syncStatuses, setSyncStatuses ] = useState<
		Record< number, { status: ImportSyncStatus } >
	>( {} );

	const refresh = useCallback(
		() => setRefreshNonce( ( nonce ) => nonce + 1 ),
		[]
	);

	// Consume the ?state= deep-link once; chip changes stay ephemeral.
	useEffect( () => stripUrlParam( 'state' ), [] );

	const searchDebounceRef = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);
	const abortRef = useRef< AbortController | null >( null );

	const authStatus = useAuthStatus();
	const isAuthorized = 'authorized' === authStatus;
	const refreshBlocked = 'unauthorized' === authStatus;

	const isCatalogPrimary = 'all' === state || 'available' === state;

	// A pasted URL whose origin doesn't match the active chip's slug column
	// can't be looked up here; the render shows a switch-chips hint instead.
	const slugChipMismatch =
		null !== detection
		&& 'unknown' !== detection.origin
		&& 'failed' !== state
		&& ! slugMatchesChip( detection.origin, isCatalogPrimary );

	const handleChipChange = useCallback(
		( next: ChipState ): void => {
			setStateValue( next );
			const nextIsCatalog = 'all' === next || 'available' === next;
			setView( ( current ) => ( {
				...( current ),
				page: 1,
				fields: computeVisibleFields( nextIsCatalog, next ),
				sort: {
					field: nextIsCatalog ? 'date_gmt' : 'import_date_gmt',
					direction: 'desc',
				},
			} ) );
		},
		[]
	);

	const statusKey = selectedStatuses.join( '|' );

	useEffect( () => {
		abortRef.current?.abort();

		// Nothing to fetch on a mismatch; the render shows the switch hint.
		if ( slugChipMismatch ) {
			setIsLoading( false );
			setFetchError( null );
			return;
		}

		const controller = new AbortController();
		abortRef.current = controller;

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_list_posts' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'source_site_url', sourceSiteUrl );
		formData.append( 'state', state );
		formData.append( 'page', String( view.page ?? 1 ) );
		formData.append(
			'per_page',
			String( view.perPage ?? DEFAULT_ITEMS_PER_PAGE )
		);
		formData.append( 'post_type', selectedPostType );

		formData.append(
			'orderby',
			computeOrderby( view.sort?.field, isCatalogPrimary )
		);
		formData.append( 'order', view.sort?.direction === 'asc' ? 'asc' : 'desc' );

		// Failed's query doesn't join wp_posts, so name= can't filter — fall
		// back to the raw text so the chip doesn't silently return everything.
		if ( null !== detection && 'failed' !== state ) {
			formData.append( 'name', detection.slug );
		} else if ( '' !== debouncedSearch ) {
			formData.append( 'search', debouncedSearch );
		}

		if ( isCatalogPrimary ) {
			selectedStatuses.forEach( ( status ) =>
				formData.append( 'status[]', status )
			);
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
		} else {
			const { afterUtc, beforeUtc } = calendarRangeToUtcBounds(
				importedAfter,
				importedBefore
			);
			if ( null !== afterUtc ) {
				formData.append( 'imported_after', afterUtc );
			}
			if ( null !== beforeUtc ) {
				formData.append( 'imported_before', beforeUtc );
			}
		}

		if ( focusSourceId > 0 ) {
			formData.append( 'focus_source_id', String( focusSourceId ) );
		}
		if ( ! hasFetchedOnce ) {
			formData.append( 'with_orphan_count', '1' );
			formData.append( 'with_attention_count', '1' );
		}

		setIsLoading( true );
		setFetchError( null );

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
			signal: controller.signal,
		} )
			.then(
				( response ) =>
					response.json() as Promise< ApiResponse< PostsResponse > >
			)
			.then( ( result ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( ! result.success ) {
					setFetchError(
						getErrorMessage(
							result,
							__( 'Failed to load posts.', 'safe-publish' )
						)
					);
					setRows( [] );
					setHasMore( false );
					return;
				}

				const { data } = result;
				setRows( data.items );
				setHasMore( Boolean( data.has_more ) );

				if ( typeof data.orphan_count === 'number' ) {
					setOrphanCount( data.orphan_count );
				}

				if ( typeof data.attention_count === 'number' ) {
					setAttentionCount( data.attention_count );
				}

				// Server resolves focus_source to a concrete state; swap the
				// chip if it differs from what the URL asked for.
				if ( focusSourceId > 0 && data.focused_state ) {
					if ( data.focused_state !== state && 'all' !== state ) {
						setStateValue( data.focused_state );
					}
					setFocusedSourceId( focusSourceId );
				}
			} )
			.catch( ( err: unknown ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( err instanceof DOMException && 'AbortError' === err.name ) {
					return;
				}
				setFetchError(
					__( 'Network error while loading posts.', 'safe-publish' )
				);
				setRows( [] );
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
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		state,
		sourceSiteUrl,
		selectedPostType,
		view.page,
		view.perPage,
		view.sort?.field,
		view.sort?.direction,
		debouncedSearch,
		detection?.slug,
		slugChipMismatch,
		statusKey,
		publishedAfter,
		publishedBefore,
		importedAfter,
		importedBefore,
		focusSourceId,
		refreshNonce,
	] );

	const sourceIds = useMemo(
		() =>
			rows
				.filter( ( row ) => row.is_imported && null !== row.source_post_id )
				.map( ( row ) => row.source_post_id as number ),
		[ rows ]
	);

	useEffect( () => {
		if ( 0 === sourceIds.length ) {
			setSyncStatuses( {} );
			return;
		}

		const controller = new AbortController();
		const formData = new FormData();
		formData.append( 'action', 'safe_publish_sync_status_batch' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		sourceIds.forEach( ( id ) =>
			formData.append( 'source_ids[]', String( id ) )
		);

		const loadingMap: Record< number, { status: ImportSyncStatus } > = {};
		sourceIds.forEach( ( id ) => {
			// id iterates sourceIds, which are absint-validated server-side.
			// eslint-disable-next-line security/detect-object-injection
			loadingMap[ id ] = { status: 'loading' };
		} );
		setSyncStatuses( loadingMap );

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
				if ( result.success ) {
					setSyncStatuses( result.data.statuses ?? {} );
				}
			} )
			.catch( () => {
				/* leave loading verdict; user can refresh */
			} );

		return () => controller.abort();
	}, [ sourceIds ] );

	useEffect(
		() => () => {
			abortRef.current?.abort();
			if ( searchDebounceRef.current ) {
				clearTimeout( searchDebounceRef.current );
			}
		},
		[]
	);

	const rowRefs = useRef< Map< number, HTMLElement > >( new Map() );

	useEffect( () => {
		if ( null === focusedSourceId ) {
			return;
		}
		const node = rowRefs.current.get( focusedSourceId );
		if ( ! node ) {
			return;
		}
		node.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		node.classList.add( 'is-focused' );
		const timer = setTimeout( () => {
			node.classList.remove( 'is-focused' );
			setFocusedSourceId( null );
			stripUrlParam( 'focus_source' );
			setFocusSourceId( 0 );
		}, 2000 );
		return () => clearTimeout( timer );
	}, [ focusedSourceId, rows ] );

	const setPage = useCallback(
		( next: number ): void =>
			setView( ( current ) => ( { ...current, page: next } ) ),
		[]
	);

	useStepBackWhenPageEmpties( {
		hasFetchedOnce,
		isLoading,
		fetchError,
		isEmpty: 0 === rows.length,
		page: view.page,
		setPage,
	} );

	const resetPage = useCallback(
		( next: Partial< View > = {} ): void => {
			// Cast: View is a discriminated union; the spread preserves `type`.
			setView(
				( current ) => ( { ...current, ...next, page: 1 } as View )
			);
		},
		[]
	);

	const handleSearchChange = ( raw: string ): void => {
		setSearchTerm( raw );

		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
		}

		searchDebounceRef.current = setTimeout( () => {
			const trimmed = raw.trim();
			// Keep both so a chip change can re-route without re-debouncing.
			setDetection(
				detectSlugFromInput( trimmed, {
					sourceUrl: sourceSiteUrl,
					destinationUrl: window.safePublishAdminData.homeUrl ?? '',
				} )
			);
			setDebouncedSearch( trimmed );
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
		setDetection( null );
		setSelectedStatuses( [] );
		setPublishedAfter( null );
		setPublishedBefore( null );
		setImportedAfter( null );
		setImportedBefore( null );
		handleChipChange( 'all' );
	};

	// Local State reads as a filter, so treat anything off "All" as dirty
	// and let the Clear button reset it alongside the other filters.
	const hasActiveFilters =
		'all' !== state
		|| '' !== searchTerm
		|| selectedStatuses.length > 0
		|| null !== publishedAfter
		|| null !== publishedBefore
		|| null !== importedAfter
		|| null !== importedBefore;

	const handleStatusesChange = (
		tokens: ( string | { value: string } )[]
	): void => {
		const next = tokens
			.map( ( token ) =>
				'string' === typeof token ? token : token.value
			)
			.map( ( label ) => {
				// value iterates the STATUS_VALUES allowlist.
				const match = STATUS_VALUES.find( ( value ) =>
					// eslint-disable-next-line security/detect-object-injection
					( PUBLISH_STATUS_LABELS[ value ] ?? value ) === label
				);
				return match ?? label;
			} )
			.filter( ( value ): value is string =>
				STATUS_VALUES.includes( value )
			);

		setSelectedStatuses( next );
		resetPage();
	};

	const handleViewChange = ( next: View ): void => {
		const sortChanged =
			next.sort?.field !== view.sort?.field
			|| next.sort?.direction !== view.sort?.direction;
		const perPageChanged = next.perPage !== view.perPage;

		setView( {
			...next,
			page: sortChanged || perPageChanged
				? 1
				: next.page ?? view.page ?? 1,
		} );
	};

	const dateFieldId = isCatalogPrimary ? 'date_gmt' : 'import_date_gmt';

	const fields: DataViewsField< UnifiedPostRow >[] = useMemo(
		() => [
			{
				id: 'title',
				label: __( 'Title', 'safe-publish' ),
				enableSorting: true,
				render: ( { item } ) => {
					const path = extractUrlPath( item.link );
					const linkable = '' !== item.link && '/' !== path;
					const titleNode = linkable ? (
						<a
							href={ item.link }
							target="_blank"
							rel="noopener noreferrer"
							title={ item.link }
							aria-label={ sprintf(
								/* translators: %s: post title */
								__( '%s (opens in new tab)', 'safe-publish' ),
								item.title
							) }
						>
							{ item.title }
						</a>
					) : (
						<span>{ item.title }</span>
					);

					return (
						<span
							ref={ ( node ) => {
								if ( node && null !== item.source_post_id ) {
									rowRefs.current.set(
										item.source_post_id,
										node
									);
								}
							} }
						>
							{ titleNode }
						</span>
					);
				},
			},
			{
				id: 'local_state',
				label: __( 'Local State', 'safe-publish' ),
				enableSorting: false,
				getValue: ( { item } ) => item.local_state,
				render: ( { item } ) => (
					<LocalStateCell
						item={ item }
						syncStatuses={ syncStatuses }
					/>
				),
			},
			{
				id: 'wp_post_status',
				label: __( 'Local Status', 'safe-publish' ),
				enableSorting: false,
				getValue: ( { item } ) =>
					null === item.wp_post_status
						? ''
						: PUBLISH_STATUS_LABELS[ item.wp_post_status ]
							?? item.wp_post_status,
				render: ( { item } ) => {
					if ( null === item.wp_post_status ) {
						return <span>—</span>;
					}
					const label =
						PUBLISH_STATUS_LABELS[ item.wp_post_status ]
							?? item.wp_post_status;
					const modifierClass = `safe-publish-status-badge--${ item.wp_post_status }`;
					return (
						<span
							className={ `safe-publish-status-badge safe-publish-status-badge--quiet ${ modifierClass }` }
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
				id: 'source_status',
				label: __( 'Source Status', 'safe-publish' ),
				enableSorting: false,
				getValue: ( { item } ) =>
					PUBLISH_STATUS_LABELS[ item.status ] ?? item.status,
				render: ( { item } ) => {
					if ( '' === item.status ) {
						return <span>—</span>;
					}
					const label =
						PUBLISH_STATUS_LABELS[ item.status ] ?? item.status;
					const modifierClass = `safe-publish-status-badge--${ item.status }`;
					return (
						<span
							className={ `safe-publish-status-badge safe-publish-status-badge--quiet ${ modifierClass }` }
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
				id: 'date_gmt',
				label: __( 'Published Date', 'safe-publish' ),
				enableSorting: true,
				getValue: ( { item } ) => item.date_gmt,
				render: ( { item } ) => (
					<span>
						{ '' === item.date_gmt
							? '—'
							: formatBadgeTimestamp( item.date_gmt ) }
					</span>
				),
			},
			{
				id: 'import_date_gmt',
				label: __( 'Imported Date', 'safe-publish' ),
				enableSorting: true,
				getValue: ( { item } ) =>
					'available' === item.local_state
						? ''
						: item.import_date_gmt ?? '',
				render: ( { item } ) => (
					<span>
						{ 'available' !== item.local_state
							&& item.import_date_gmt
							? formatBadgeTimestamp( item.import_date_gmt )
							: '—' }
					</span>
				),
			},
		],
		[ syncStatuses ]
	);

	// Don't override view.fields here — handleChipChange seeds them per chip;
	// any later toggle in DataViews' settings popover stays sticky.
	const effectiveView: View = {
		...view,
		sort: {
			field:
				view.sort?.field === 'title'
					? 'title'
					: dateFieldId,
			direction: view.sort?.direction ?? 'desc',
		},
	};

	const currentPage = view.page ?? 1;
	const currentPerPage = view.perPage ?? DEFAULT_ITEMS_PER_PAGE;
	const paginationInfo = useMemo(
		() =>
			! hasMore
				? {
						totalItems:
							( currentPage - 1 ) * currentPerPage + rows.length,
						totalPages: currentPage,
				  }
				: {
						totalItems: currentPage * currentPerPage + 1,
						totalPages: currentPage + 1,
				  },
		[ currentPage, currentPerPage, hasMore, rows.length ]
	);

	const tokenValues = useMemo(
		() =>
			selectedStatuses.map(
				// eslint-disable-next-line security/detect-object-injection
				( status ) => PUBLISH_STATUS_LABELS[ status ] ?? status
			),
		[ selectedStatuses ]
	);

	const pageStatusText = sprintf(
		/* translators: %d: current page number */
		__( 'Page %d', 'safe-publish' ),
		currentPage
	);

	const handleOrphanCountRefresh = useCallback( () => {
		const formData = new FormData();
		formData.append( 'action', 'safe_publish_list_posts' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'source_site_url', sourceSiteUrl );
		formData.append( 'state', 'all' );
		formData.append( 'page', '1' );
		formData.append( 'per_page', '1' );
		formData.append( 'post_type', selectedPostType );
		formData.append( 'with_orphan_count', '1' );

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
		} )
			.then(
				( response ) =>
					response.json() as Promise< ApiResponse< PostsResponse > >
			)
			.then( ( result ) => {
				if (
					result.success
					&& typeof result.data.orphan_count === 'number'
				) {
					setOrphanCount( result.data.orphan_count );
				}
			} )
			.catch( () => {
				/* leave count stale; next reload corrects */
			} );
	}, [ sourceSiteUrl, selectedPostType ] );

	const handleAttentionCountRefresh = useCallback( () => {
		const formData = new FormData();
		formData.append( 'action', 'safe_publish_list_posts' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'source_site_url', sourceSiteUrl );
		formData.append( 'state', 'all' );
		formData.append( 'page', '1' );
		formData.append( 'per_page', '1' );
		formData.append( 'post_type', selectedPostType );
		formData.append( 'with_attention_count', '1' );

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
		} )
			.then(
				( response ) =>
					response.json() as Promise< ApiResponse< PostsResponse > >
			)
			.then( ( result ) => {
				if (
					result.success
					&& typeof result.data.attention_count === 'number'
				) {
					setAttentionCount( result.data.attention_count );
				}
			} )
			.catch( () => {
				/* leave count stale; next reload corrects */
			} );
	}, [ sourceSiteUrl, selectedPostType ] );

	return (
		<div
			className="safe-publish-dataviews-wrapper safe-publish-dataviews-wrapper--approx-pagination"
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
			{ ( attentionCount > 0 || orphanCount > 0 ) && (
				<div className="safe-publish-issue-summary">
					{ attentionCount > 0 && (
						<Button
							className="safe-publish-issue-summary__button safe-publish-issue-summary__button--warning"
							variant="tertiary"
							icon={ caution }
							onClick={ () =>
								setIsAttentionDrawerOpen( true )
							}
						>
							{ sprintf(
								/* translators: %d: open attention issues count */
								__( 'Needs attention (%d)', 'safe-publish' ),
								attentionCount
							) }
						</Button>
					) }
					{ orphanCount > 0 && (
						<Button
							className="safe-publish-issue-summary__button safe-publish-issue-summary__button--error"
							variant="tertiary"
							icon={ cancelCircleFilled }
							onClick={ () => setIsOrphanDrawerOpen( true ) }
						>
							{ sprintf(
								/* translators: %d: orphan failures count */
								__( 'Orphan failures (%d)', 'safe-publish' ),
								orphanCount
							) }
						</Button>
					) }
				</div>
			) }
			<div className="safe-publish-controls-row">
				{ /* The items table doesn't snapshot post_type for error
				rows, so the filter can't be honored on the Failed chip. */ }
				{ 'failed' !== state && (
					<PostTypeSelector
						sourceSiteUrl={ sourceSiteUrl }
						selectedPostType={ selectedPostType }
						onPostTypeChange={ handlePostTypeChange }
						onError={ setPostTypeError }
					/>
				) }
				<div className="safe-publish-control safe-publish-control--search">
					<BaseControl
						__nextHasNoMarginBottom
						label={
							<span className="safe-publish-search-label">
								{ __( 'Title or URL', 'safe-publish' ) }
								<SearchHelpButton />
							</span>
						}
						id="safe-publish-search-input"
					>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							id="safe-publish-search-input"
							label={ __( 'Title or URL', 'safe-publish' ) }
							hideLabelFromVision
							value={ searchTerm }
							onChange={ handleSearchChange }
						/>
					</BaseControl>
				</div>
				{ isCatalogPrimary ? (
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
				) : (
					<DateRangeFilter
						label={ __( 'Imported Date', 'safe-publish' ) }
						id="safe-publish-imported-date"
						after={ importedAfter }
						before={ importedBefore }
						onChange={ ( next ) => {
							setImportedAfter( next.after );
							setImportedBefore( next.before );
							resetPage();
						} }
					/>
				) }
				<StateSelect value={ state } onChange={ handleChipChange } />
				{ isCatalogPrimary && (
					<div className="safe-publish-control safe-publish-control--statuses">
						<FormTokenField
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							__experimentalExpandOnFocus
							__experimentalShowHowTo={ false }
							label={ __( 'Source Status', 'safe-publish' ) }
							placeholder={ __(
								'All statuses',
								'safe-publish'
							) }
							value={ tokenValues }
							suggestions={ STATUS_LABEL_SUGGESTIONS }
							onChange={ handleStatusesChange }
						/>
					</div>
				) }
				{ hasActiveFilters && (
					<Button variant="tertiary" onClick={ handleClearFilters }>
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
			{ ! slugChipMismatch && fetchError && (
				<Notice
					className="safe-publish-post-type-error"
					status="error"
					onRemove={ () => setFetchError( null ) }
				>
					{ fetchError }
				</Notice>
			) }
			{ rollbackNotice && (
				<Notice
					status={ rollbackNotice.status }
					onRemove={ () => setRollbackNotice( null ) }
				>
					{ rollbackNotice.message }
				</Notice>
			) }
			{ slugChipMismatch && (
				<div
					className="safe-publish-no-data"
					role="status"
					aria-live="polite"
				>
					<p>
						{ 'source' === detection?.origin
							? __(
									'This looks like a source link. Switch to All or Available to find it.',
									'safe-publish'
							  )
							: __(
									'This looks like a destination link. Switch to Up to date or Outdated to find it.',
									'safe-publish'
							  ) }
					</p>
				</div>
			) }
			{ ! slugChipMismatch && isLoading && ! hasFetchedOnce && (
				<div
					className="safe-publish-loading"
					role="status"
					aria-live="polite"
				>
					<p>{ __( 'Loading posts…', 'safe-publish' ) }</p>
				</div>
			) }
			{ ! slugChipMismatch
				&& hasFetchedOnce
				&& ! fetchError
				&& 0 === rows.length
				&& ! isLoading && (
					<div
						className="safe-publish-no-data"
						role="status"
						aria-live="polite"
					>
						<p>{ emptyStateCopy( state, detection?.slug ?? null ) }</p>
					</div>
			) }
			{ ! slugChipMismatch && hasFetchedOnce && rows.length > 0 && (
				<DataViews
					getItemId={ ( item: UnifiedPostRow ) =>
						String( item.source_post_id ?? item.item_id ?? item.id )
					}
					data={ rows }
					fields={ fields }
					view={ effectiveView }
					onChangeView={ handleViewChange }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { [ LAYOUT_TABLE ]: {} } }
					actions={ createPostsActions(
						refresh,
						isAuthorized,
						{
							ajaxurl: window.safePublishAdminData.ajaxurl,
							nonce: window.safePublishAdminData.nonce,
							onNotice: setRollbackNotice,
						},
						syncStatuses,
						state
					) }
					header={
						<Button
							className="safe-publish-refresh-button"
							icon={ update }
							aria-busy={ isLoading }
							disabled={ isLoading || refreshBlocked }
							label={ __( 'Refresh', 'safe-publish' ) }
							onClick={ refresh }
						/>
					}
				/>
			) }
			{ isOrphanDrawerOpen && (
				<OrphanFailuresDrawer
					ajaxurl={ window.safePublishAdminData.ajaxurl }
					nonce={ window.safePublishAdminData.nonce }
					onClose={ () => setIsOrphanDrawerOpen( false ) }
					onRemoved={ handleOrphanCountRefresh }
				/>
			) }
			{ isAttentionDrawerOpen && (
				<AttentionDrawer
					ajaxurl={ window.safePublishAdminData.ajaxurl }
					nonce={ window.safePublishAdminData.nonce }
					onClose={ () => setIsAttentionDrawerOpen( false ) }
					onChanged={ handleAttentionCountRefresh }
				/>
			) }
		</div>
	);
}

/**
 * Local state cell — renders the routing label, optional history badge, and
 * (for imported/outdated) the live sync verdict's badge.
 * @param root0
 * @param root0.item
 * @param root0.syncStatuses
 */
function LocalStateCell( {
	item,
	syncStatuses,
}: {
	item: UnifiedPostRow;
	syncStatuses: Record< number, { status: ImportSyncStatus } >;
} ): JSX.Element {
	const stateLabel: Record< LocalState, string > = {
		'available': __( 'Available', 'safe-publish' ),
		'up-to-date': __( 'Up to date', 'safe-publish' ),
		'outdated': __( 'Outdated', 'safe-publish' ),
		'failed': __( 'Failed', 'safe-publish' ),
	};
	// eslint-disable-next-line security/detect-object-injection
	const label = stateLabel[ item.local_state ];

	// Imported only — the Outdated chip already says it.
	let syncBadge: JSX.Element | null = null;
	const liveStatus =
		null !== item.source_post_id
			? syncStatuses[ item.source_post_id ]?.status
			: undefined;
	if ( liveStatus === 'outdated' && 'up-to-date' === item.local_state ) {
		syncBadge = (
			<span className="safe-publish-status-badge safe-publish-status-badge--outdated">
				{ __( 'Outdated', 'safe-publish' ) }
			</span>
		);
	}

	const failureDetail =
		'failed' === item.local_state
			&& null !== item.error_message
			&& '' !== item.error_message
			? item.error_message
			: '';

	const outdatedSince =
		'outdated' === item.local_state && '' !== item.modified_gmt
			? sprintf(
					/* translators: %s: localized date when source was changed */
					__( 'Changed %s', 'safe-publish' ),
					formatBadgeTimestamp( item.modified_gmt )
			  )
			: '';

	const syncCheckFailed =
		'up-to-date' === item.local_state
		&& ( 'missing' === liveStatus
			|| 'unreachable' === liveStatus
			|| 'invalid' === liveStatus );

	return (
		<span className="safe-publish-local-state-cell">
			<span
				className={ `safe-publish-status-badge safe-publish-status-badge--${ item.local_state }` }
			>
				<span
					className="safe-publish-status-badge__dot"
					aria-hidden="true"
				/>
				{ label }
			</span>
			{ '' !== failureDetail && (
				<span
					className="safe-publish-history-badge"
					title={ failureDetail }
				>
					{ failureDetail }
				</span>
			) }
			{ '' !== outdatedSince && (
				<span className="safe-publish-history-badge">
					{ outdatedSince }
				</span>
			) }
			{ syncCheckFailed && (
				<span className="safe-publish-history-badge">
					{ __( 'Sync check failed', 'safe-publish' ) }
				</span>
			) }
			{ syncBadge }
		</span>
	);
}

/**
 * Empty-state copy per chip.
 *
 * @param state Active chip.
 * @param slug  Detected URL slug, or null when the user typed plain text.
 */
function emptyStateCopy( state: ChipState, slug: string | null ): string {
	// Failed's query can't join wp_posts to match a slug; redirect the user
	// at the moment they'd otherwise read "no results" as a bug.
	if ( 'failed' === state && null !== slug ) {
		return __(
			"URL search isn't supported on Failed. Switch to All to find imported posts by URL.",
			'safe-publish'
		);
	}
	switch ( state ) {
		case 'available':
			return __(
				'No source posts left to import here. Switch to All to browse the full catalog.',
				'safe-publish'
			);
		case 'up-to-date':
			return __( 'No posts up to date here.', 'safe-publish' );
		case 'outdated':
			return __(
				'No outdated rows. Some rows may show "outdated" badges before they appear here — refresh to update.',
				'safe-publish'
			);
		case 'failed':
			return __( 'No failed imports.', 'safe-publish' );
		default:
			return __( 'No posts matched these filters.', 'safe-publish' );
	}
}
