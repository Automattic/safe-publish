/**
 * DataViews implementation for displaying external posts in the admin.
 *
 * Main entry point for the external posts DataViews component that provides
 * a table, grid, or list view of posts from external WordPress sites.
 *
 * @file This file defines the main DataViews component for the Safe Publish plugin.
 */
import { update } from '@wordpress/icons';
import { createRoot } from 'react-dom/client';

import { createActions } from './actions';
import AuthStatusNotice from './components/AuthStatusNotice';
import {
	DEFAULT_POSTS_PER_PAGE,
	LAYOUT_GRID,
	LAYOUT_LIST,
	LAYOUT_TABLE,
	MAX_POSTS_COUNT,
	MIN_POSTS_COUNT,
	NUMBER_POSTS_DEBOUNCE_DELAY,
} from './constants';
import { PostTypeSelector } from './post-type-selector';
import {
	extractUrlPath,
	formatDateTime,
	getErrorMessage,
	getPaginationInfo,
	paginatePosts,
	PUBLISH_STATUS_LABELS,
	sanitizePosts,
	searchPosts,
	sortPosts,
	SYNC_STATUS_LABELS,
} from './utils';
import {
	__experimentalNumberControl as NumberControl,
	Button,
	Notice,
} from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	AuthStatus,
	AuthStatusData,
	DataViewsField,
	ExternalPostsDataViewProps,
	PaginationInfo,
	Post,
} from './types';

import './style.scss';

/**
 * DataViews component for external posts.
 *
 * Renders a DataViews table with search, sort, and pagination capabilities
 * for displaying posts fetched from external WordPress sites.
 *
 * @param {Object} props              Component props.
 * @param {Post[]} props.initialPosts Posts to display on initial load.
 * @param {string} props.siteUrl      External site URL.
 * @param {number} props.numberPosts  Number of posts to fetch.
 *
 * @return {JSX.Element} Rendered DataViews component.
 */
function ExternalPostsDataView( { initialPosts, siteUrl, numberPosts }: ExternalPostsDataViewProps ): JSX.Element {
	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: DEFAULT_POSTS_PER_PAGE,
		page: 1,
		sort: {
			field: 'modified',
			direction: 'desc',
		},
		search: '',
		filters: [],
		fields: [ 'permalink', 'modified', 'sync_status', 'publish_status' ],
		titleField: 'title',
		descriptionField: 'description',
		mediaField: 'image',
	} );

	const defaultLayouts = {
		[ LAYOUT_TABLE ]: {},
		[ LAYOUT_GRID ]: {},
		[ LAYOUT_LIST ]: {},
	};

	const [ allPosts, setAllPosts ] = useState< Post[] >( initialPosts );
	const [ selectedPostType, setSelectedPostType ] = useState( 'posts' );
	const [ isLoadingPosts, setIsLoadingPosts ] = useState( false );
	const [ postTypeError, setPostTypeError ] = useState< string | null >( null );
	const [ authStatus, setAuthStatus ] = useState< AuthStatus | null >( null );
	const [ numberPostsState, setNumberPostsState ] = useState( numberPosts );
	const [ numberPostsInput, setNumberPostsInput ] = useState( String( numberPosts ) );
	const numberPostsTimerRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const [ filteredData, setFilteredData ] = useState< Post[] >( initialPosts );
	const [ paginationInfo, setPaginationInfo ] = useState< PaginationInfo >( {
		totalItems: initialPosts.length,
		totalPages: Math.ceil( initialPosts.length / DEFAULT_POSTS_PER_PAGE ),
	} );

	const isAuthorized = 'authorized' === authStatus;

	// Fields configuration for DataViews.
	const fields: DataViewsField<Post>[] = [
		{
			id: 'title',
			label: __( 'Title', 'safe-publish' ),
			enableSorting: true,
			enableGlobalSearch: true,
			render: ( { item }: { item: Post } ): JSX.Element => {
				if ( item.local_edit_url ) {
					return (
						<a
							href={ item.local_edit_url }
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
			id: 'post_type',
			label: __( 'Type', 'safe-publish' ),
			enableSorting: true,
			enableGlobalSearch: true,
			render: ( { item }: { item: Post } ): JSX.Element => {
				const postType = item.post_type || 'post';
				// Capitalize first letter for display.
				const displayType = postType.charAt( 0 ).toUpperCase() + postType.slice( 1 );
				return <span>{ displayType }</span>;
			},
		},
		{
			id: 'permalink',
			label: __( 'Permalink', 'safe-publish' ),
			enableSorting: false,
			enableGlobalSearch: true,
			render: ( { item }: { item: Post } ): JSX.Element => {
				const path = extractUrlPath( item.link );
				return (
					<a
						href={ item.link }
						target="_blank"
						rel="noopener noreferrer"
						title={ item.link }
					>
						{ path }
					</a>
				);
			},
		},
		{
			id: 'modified',
			label: __( 'Last Modified', 'safe-publish' ),
			enableSorting: true,
			enableGlobalSearch: true,
			render: ( { item }: { item: Post } ): JSX.Element => {
				return <span>{ formatDateTime( item.modified ) }</span>;
			},
		},
		{
			id: 'sync_status',
			label: __( 'Sync Status', 'safe-publish' ),
			enableSorting: true,
			enableGlobalSearch: true,
			render: ( { item }: { item: Post } ): JSX.Element => {
				if ( item.is_imported && item.has_update ) {
					return (
						<span className="safe-publish-status-badge safe-publish-status-badge--outdated">
							<span className="safe-publish-status-badge__dot" aria-hidden="true" />
							{ SYNC_STATUS_LABELS.outdated }
						</span>
					);
				}
				if ( item.is_imported ) {
					return (
						<span className="safe-publish-status-badge safe-publish-status-badge--up-to-date">
							<span className="safe-publish-status-badge__dot" aria-hidden="true" />
							{ SYNC_STATUS_LABELS.upToDate }
						</span>
					);
				}
				return (
					<span className="safe-publish-status-badge safe-publish-status-badge--available">
						<span className="safe-publish-status-badge__dot" aria-hidden="true" />
						{ SYNC_STATUS_LABELS.available }
					</span>
				);
			},
		},
		{
			id: 'publish_status',
			label: __( 'Publish Status', 'safe-publish' ),
			enableSorting: false,
			enableGlobalSearch: true,
			render: ( { item }: { item: Post } ): JSX.Element => {
				if ( ! item.is_imported || ! item.local_status ) {
					return <span className="safe-publish-status-badge safe-publish-status-badge--empty">—</span>;
				}
				const label = PUBLISH_STATUS_LABELS[ item.local_status ] ?? item.local_status;
				const modifierClass = `safe-publish-status-badge--${ item.local_status }`;
				return (
					<span className={ `safe-publish-status-badge ${ modifierClass }` }>
						<span className="safe-publish-status-badge__dot" aria-hidden="true" />
						{ label }
					</span>
				);
			},
		},
	];

	/**
	 * Handles view state changes.
	 *
	 * Applies search filtering, sorting, and updates pagination when the
	 * DataViews view state changes.
	 *
	 * @param {View} newView Updated view state from DataViews.
	 */
	const onChangeView = ( newView: View ): void => {
		setView( newView );

		// Apply search filter.
		let filtered: Post[] = searchPosts( allPosts, newView.search || '' );

		// Apply sorting.
		if ( newView.sort?.field ) {
			filtered = sortPosts(
				filtered,
				newView.sort.field as keyof Post | 'sync_status',
				newView.sort.direction
			);
		}

		// Update pagination info.
		const paginationData = getPaginationInfo( filtered.length, newView.perPage as number );
		setPaginationInfo( paginationData );

		// Get paginated data.
		const paginatedData = paginatePosts(
			filtered,
			newView.page as number,
			newView.perPage as number
		);

		setFilteredData( paginatedData );
	};

	// Initialize filtered data.
	useEffect( () => {
		onChangeView( view );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] ); // Only run on mount.

	// Probe live auth state so the banner and import buttons reflect whether
	// the source site will accept signed requests before any user action.
	useEffect( () => {
		const formData = new FormData();
		formData.append( 'action', 'safe_publish_auth_status' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
		} )
			.then(
				( response ) =>
					response.json() as Promise< ApiResponse< AuthStatusData > >
			)
			.then( ( result ) => {
				if ( result.success ) {
					setAuthStatus( result.data.status );
				} else {
					setAuthStatus( 'unreachable' );
				}
			} )
			.catch( () => {
				setAuthStatus( 'unreachable' );
			} );
	}, [] );

	/**
	 * Fetches posts for the given post type and updates the DataViews.
	 *
	 * @param {string} postType Post type slug to fetch.
	 * @param {number} numPosts Number of posts to fetch.
	 * @return {Promise<void>} Resolves when fetch completes.
	 */
	const fetchPostsByType = async ( postType: string, numPosts: number ): Promise< void > => {
		if ( ! siteUrl ) {
			return;
		}

		setIsLoadingPosts( true );
		setPostTypeError( null );

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_fetch_posts' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'site_url', siteUrl );
		formData.append( 'number_of_posts', numPosts.toString() );
		formData.append( 'post_type', postType );

		try {
			const response = await fetch( window.safePublishAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );
			const result = await response.json() as ApiResponse< Post[] >;

			if ( result.success ) {
				const newPosts = sanitizePosts( result.data );
				setAllPosts( newPosts );

				// Reset to page 1 and re-apply the current sort with the fresh data.
				const resetView = { ...view, page: 1, search: '' };
				setView( resetView );
				let filtered = searchPosts( newPosts, '' );
				if ( resetView.sort?.field ) {
					filtered = sortPosts(
						filtered,
						resetView.sort.field as keyof Post | 'sync_status',
						resetView.sort.direction
					);
				}
				setPaginationInfo( getPaginationInfo( filtered.length, resetView.perPage as number ) );
				setFilteredData( paginatePosts( filtered, 1, resetView.perPage as number ) );
			} else {
				setPostTypeError( getErrorMessage( result, __( 'Unknown error', 'safe-publish' ) ) );
			}
		} catch {
			setPostTypeError( __( 'Network error while loading posts.', 'safe-publish' ) );
		} finally {
			setIsLoadingPosts( false );
		}
	};

	/**
	 * Handles post type selection change from PostTypeSelector.
	 *
	 * @param {string} postType Newly selected post type.
	 */
	const handlePostTypeChange = ( postType: string ): void => {
		setSelectedPostType( postType );
		fetchPostsByType( postType, numberPostsState ).catch( ( error ) => {
			// Only unexpected errors reach here.
			// eslint-disable-next-line no-console
			console.error( 'Unexpected error in fetchPostsByType:', error );
		} );
	};

	/**
	 * Commits the number of posts input value after debouncing.
	 *
	 * @param {string} rawValue Raw input value from the NumberControl.
	 */
	const commitNumberPosts = ( rawValue: string ): void => {
		if ( numberPostsTimerRef.current ) {
			clearTimeout( numberPostsTimerRef.current );
		}

		const val = Math.min(
			MAX_POSTS_COUNT,
			Math.max( MIN_POSTS_COUNT, parseInt( rawValue, 10 ) || numberPostsState )
		);
		setNumberPostsInput( String( val ) );
		handleNumberOfPostsChange( val );
	};

	/**
	 * Handles number of posts changes and triggers a re-fetch.
	 *
	 * @param {number} numPosts New number of posts value.
	 */
	const handleNumberOfPostsChange = ( numPosts: number ): void => {
		if ( numPosts === numberPostsState ) {
			return;
		}

		setNumberPostsState( numPosts );
		fetchPostsByType( selectedPostType, numPosts ).catch( ( error ) => {
			// Only unexpected errors reach here.
			// eslint-disable-next-line no-console
			console.error( 'Unexpected error in fetchPostsByType:', error );
		} );
	};

	return (
		<div className="safe-publish-dataviews-wrapper">
			<AuthStatusNotice
				status={ authStatus }
				settingsUrl={ window.safePublishAdminData?.settingsUrl }
			/>
			<div className="safe-publish-controls-row">
				<PostTypeSelector
					siteUrl={ siteUrl }
					selectedPostType={ selectedPostType }
					onPostTypeChange={ handlePostTypeChange }
					onError={ setPostTypeError }
				/>
				<NumberControl
					label={ __( 'Count', 'safe-publish' ) }
					value={ numberPostsInput }
					min={ MIN_POSTS_COUNT }
					max={ MAX_POSTS_COUNT }
					className="safe-publish-number-of-posts-control"
					onChange={ ( val ) => {
						setNumberPostsInput( val ?? '' );
						if ( numberPostsTimerRef.current ) {
							clearTimeout( numberPostsTimerRef.current );
						}

						const parsed = parseInt( val ?? '', 10 );
						if ( ! isNaN( parsed ) ) {
							numberPostsTimerRef.current = setTimeout( () => {
									commitNumberPosts( String( parsed ) );
							}, NUMBER_POSTS_DEBOUNCE_DELAY );
						}
					} }
					onBlur={ ( event ) => commitNumberPosts(
						( event.target as HTMLInputElement ).value )
					}
					onKeyDown={ ( event ) => {
						if ( 'Enter' === event.key ) {
							event.preventDefault();
							commitNumberPosts(
								( event.target as HTMLInputElement ).value
							);
						}
					} }
				/>
				<Button
					variant="tertiary"
					isBusy={ isLoadingPosts }
					disabled={ isLoadingPosts || ! isAuthorized }
					icon={ update }
					label={ __( 'Refresh', 'safe-publish' ) }
					style={ { height: '32px', width: '32px', minWidth: 0 } }
					onClick={ () => {
						fetchPostsByType( selectedPostType, numberPostsState ).catch( () => {} );
					} }
				/>
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
			{ isLoadingPosts && (
				<div className="safe-publish-loading">
					<p>{ __( 'Loading posts…', 'safe-publish' ) }</p>
				</div>
			) }
			{ ! isLoadingPosts && ! postTypeError && 0 === allPosts.length && (
				<div className="safe-publish-no-data">
					<p>{ __( 'No posts available for the selected post type.', 'safe-publish' ) }</p>
				</div>
			) }
			{ ! isLoadingPosts && ! postTypeError && allPosts.length > 0 && (
				<DataViews
					getItemId={ ( item: Post ) => item.id.toString() }
					data={ filteredData }
					fields={ fields }
					view={ view }
					onChangeView={ onChangeView }
					paginationInfo={ paginationInfo }
					defaultLayouts={ defaultLayouts }
					actions={ createActions( () => {
						fetchPostsByType( selectedPostType, numberPostsState ).catch( () => {} );
					}, isAuthorized ) }
				/>
			) }
		</div>
	);
}

/**
 * Initializes the DataViews component on page load.
 */
document.addEventListener( 'DOMContentLoaded', (): void => {
	const dataviewContainer = document.getElementById( 'safe-publish-dataviews-container' );

	if ( ! dataviewContainer ) {
		return;
	}

	const siteUrl = window.safePublishAdminData?.siteUrl || '';
	const numberPosts = window.safePublishAdminData?.numPosts ?? 0;

	// Get posts data from localized script.
	let initialPosts: Post[] = [];
	try {
		if ( window.safePublishAdminData && window.safePublishAdminData.postsData ) {
			initialPosts = sanitizePosts( window.safePublishAdminData.postsData );
		}
	} catch ( error ) {
		dataviewContainer.innerHTML = `<p class="safe-publish-error-message">${ __( 'Failed to load posts data.', 'safe-publish' ) }</p>`;
		return;
	}

	// Clear container and render DataViews.
	dataviewContainer.innerHTML = '';

	createRoot( dataviewContainer ).render(
		<ExternalPostsDataView
			initialPosts={ initialPosts }
			siteUrl={ siteUrl }
			numberPosts={ numberPosts }
		/>
	);
} );
