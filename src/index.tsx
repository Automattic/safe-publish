/**
 * DataViews implementation for displaying external posts in the admin.
 *
 * Main entry point for the external posts DataViews component that provides
 * a table, grid, or list view of posts from external WordPress sites.
 *
 * @file This file defines the main DataViews component for the Safe Publish plugin.
 */
import { createRoot } from 'react-dom/client';

import { actions } from './actions';
import { LAYOUT_GRID, LAYOUT_LIST, LAYOUT_TABLE } from './constants';
import { PostTypeSelector } from './post-type-selector';
import {
	extractUrlPath,
	getErrorMessage,
	getPaginationInfo,
	paginatePosts,
	sanitizePosts,
	searchPosts,
	sortPosts,
} from './utils';
import { DataViews, View } from '@wordpress/dataviews';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type {
	ApiResponse,
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
		perPage: 10,
		page: 1,
		sort: {
			field: 'modified',
			direction: 'desc',
		},
		search: '',
		filters: [],
		fields: [ 'permalink', 'modified' ],
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
	const [ fetchError, setFetchError ] = useState< string | null >( null );
	const [ filteredData, setFilteredData ] = useState< Post[] >( initialPosts );
	const [ paginationInfo, setPaginationInfo ] = useState< PaginationInfo >( {
		totalItems: initialPosts.length,
		totalPages: Math.ceil( initialPosts.length / 10 ),
	} );

	// Fields configuration for DataViews (simplified for debugging).
	const fields: DataViewsField<Post>[] = [
		{
			id: 'title',
			label: __( 'Title', 'safe-publish' ),
			enableSorting: true,
			enableGlobalSearch: true,
		},
		{
			id: 'post_type',
			label: __( 'Type', 'safe-publish' ),
			enableSorting: true,
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
			filtered = sortPosts( filtered, newView.sort.field as keyof Post, newView.sort.direction );
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

	/**
	 * Fetches posts for the given post type and updates the DataViews.
	 *
	 * @param {string} postType Post type slug to fetch.
	 * @return {Promise<void>} Resolves when fetch completes.
	 */
	const fetchPostsByType = async ( postType: string ): Promise< void > => {
		if ( ! siteUrl ) {
			return;
		}

		setIsLoadingPosts( true );
		setFetchError( null );

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_fetch_posts' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'site_url', siteUrl );
		formData.append( 'number_of_posts', numberPosts.toString() );
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
					filtered = sortPosts( filtered, resetView.sort.field as keyof Post, resetView.sort.direction );
				}
				setPaginationInfo( getPaginationInfo( filtered.length, resetView.perPage as number ) );
				setFilteredData( paginatePosts( filtered, 1, resetView.perPage as number ) );
			} else {
				setFetchError( getErrorMessage( result, __( 'Unknown error', 'safe-publish' ) ) );
			}
		} catch {
			setFetchError( __( 'Network error while loading posts.', 'safe-publish' ) );
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
		fetchPostsByType( postType ).catch( ( error ) => {
			// Only unexpected errors reach here.
			// eslint-disable-next-line no-console
			console.error( 'Unexpected error in fetchPostsByType:', error );
		} );
	};

	return (
		<div className="safe-publish-dataviews-wrapper">
			<PostTypeSelector
				siteUrl={ siteUrl }
				selectedPostType={ selectedPostType }
				onPostTypeChange={ handlePostTypeChange }
			/>
			{ isLoadingPosts && (
				<div className="safe-publish-loading">
					<p>{ __( 'Loading posts…', 'safe-publish' ) }</p>
				</div>
			) }
			{ ! isLoadingPosts && fetchError && (
				<p className="safe-publish-error-message">{ fetchError }</p>
			) }
			{ ! isLoadingPosts && ! fetchError && 0 === allPosts.length && (
				<div className="safe-publish-no-data">
					<p>{ __( 'No posts available for the selected post type.', 'safe-publish' ) }</p>
				</div>
			) }
			{ ! isLoadingPosts && ! fetchError && allPosts.length > 0 && (
				<DataViews
					getItemId={ ( item: Post ) => item.id.toString() }
					data={ filteredData }
					fields={ fields }
					view={ view }
					onChangeView={ onChangeView }
					paginationInfo={ paginationInfo }
					defaultLayouts={ defaultLayouts }
					actions={ actions }
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
	const numberPosts = window.safePublishAdminData?.numPosts || 10;

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
