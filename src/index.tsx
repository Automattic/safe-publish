/**
 * DataViews implementation for displaying external posts in the admin.
 *
 * Main entry point for the external posts DataViews component that provides
 * a table, grid, or list view of posts from external WordPress sites.
 *
 * @file This file defines the main DataViews component for the CCP plugin.
 */
import { createRoot } from 'react-dom/client';

import { actions } from './actions';
import { AdminTools } from './admin-tools';
import { LAYOUT_GRID, LAYOUT_LIST, LAYOUT_TABLE } from './constants';
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
 * @param {Object} props       Component props.
 * @param {Post[]} props.posts Posts to display.
 *
 * @return {JSX.Element} Rendered DataViews component.
 */
function ExternalPostsDataView( { posts }: ExternalPostsDataViewProps ): JSX.Element {
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

	const [ filteredData, setFilteredData ] = useState< Post[] >( posts );
	const [ paginationInfo, setPaginationInfo ] = useState< PaginationInfo >( {
		totalItems: posts.length,
		totalPages: Math.ceil( posts.length / 10 ),
	} );

	// Fields configuration for DataViews (simplified for debugging).
	const fields: DataViewsField<Post>[] = [
		{
			id: 'title',
			label: __( 'Title', 'ccp' ),
			enableSorting: true,
			enableGlobalSearch: true,
		},
		{
			id: 'post_type',
			label: __( 'Type', 'ccp' ),
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
			label: __( 'Permalink', 'ccp' ),
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
			label: __( 'Last Modified', 'ccp' ),
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
		let filtered: Post[] = searchPosts( posts, newView.search || '' );

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

	// If we have no data, show a message.
	if ( 0 === filteredData.length && 0 === posts.length ) {
		return (
			<div className="ccp-no-data">
				<p>{ __( 'No posts available to display.', 'ccp' ) }</p>
			</div>
		);
	}

	return (
		<div className="ccp-dataviews-wrapper">
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
		</div>
	);
}

/**
 * Initializes the DataViews component on page load.
 */
document.addEventListener( 'DOMContentLoaded', (): void => {
	/**
	 * Refreshes posts data from the external site.
	 *
	 * Called by the AdminTools component when the post type changes
	 * or when a manual refresh is triggered.
	 *
	 * @param {string} [postType] Post type to fetch. Default 'posts'.
	 */
	function refreshPostsData( postType: string = 'posts' ): void {
		const dataviewContainer = document.getElementById( 'ccp-dataviews-container' );
		// Use saved settings from window.ccpAdminData instead of input field values.
		const siteUrl = window.ccpAdminData?.siteUrl || '';
		const numberPosts = window.ccpAdminData?.numPosts?.toString() || '10';

		if ( ! dataviewContainer || ! siteUrl ) {
			return;
		}

		// Show loading.
		dataviewContainer.innerHTML = `<div class="ccp-loading"><p>${ __( 'Loading posts…', 'ccp' ) }</p></div>`;

		// Make request to fetch posts with selected post type.
		const formData = new FormData();
		formData.append( 'action', 'ccp_fetch_posts' );
		formData.append( 'nonce', window.ccpAdminData.nonce );
		formData.append( 'site_url', siteUrl );
		formData.append( 'number_of_posts', numberPosts );
		formData.append( 'post_type', postType );

		fetch( window.ccpAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
		} )
			.then( response => response.json() )
			.then( ( result: ApiResponse<Post[]> ) => {
				if ( result.success ) {
					const posts = sanitizePosts( result.data );
					if ( 0 === posts.length ) {
						dataviewContainer.innerHTML =
							`<p class="ccp-no-posts">${ __( 'No posts available for the selected post type.', 'ccp' ) }</p>`;
					} else {
						dataviewContainer.innerHTML = '';
						createRoot( dataviewContainer ).render( <ExternalPostsDataView posts={ posts } /> );
					}
				} else {
					const errorMessage = getErrorMessage( result, __( 'Unknown error', 'ccp' ) );
					dataviewContainer.innerHTML =
						/* translators: %s is the error message */
						`<p class="ccp-error-message">${ __( 'Failed to load posts: %s', 'ccp' ).replace( '%s', errorMessage ) }</p>`;
				}
			} )
			.catch( () => {
				dataviewContainer.innerHTML =
					`<p class="ccp-error-message">${ __( 'Network error while loading posts.', 'ccp' ) }</p>`;
			} );
	}

	// Expose refresh function globally for the AdminTools component.
	window.ccpRefreshPosts = refreshPostsData;

	// Initialize the AdminTools React component.
	const adminToolsContainer = document.getElementById( 'ccp-admin-tools-container' );
	if ( adminToolsContainer ) {
		// Clear the loading placeholder.
		adminToolsContainer.innerHTML = '';

		createRoot( adminToolsContainer ).render(
			<AdminTools
				siteUrl={ window.ccpAdminData?.siteUrl || '' }
				numberPosts={ window.ccpAdminData?.numPosts || 10 }
			/>
		);
	}

	// Initialize the DataViews component.
	const dataviewContainer = document.getElementById( 'ccp-dataviews-container' );

	if ( ! dataviewContainer ) {
		return;
	}

	// Get posts data from localized script.
	let posts: Post[] = [];
	try {
		if ( window.ccpAdminData && window.ccpAdminData.postsData ) {
			const rawPosts = window.ccpAdminData.postsData;
			posts = sanitizePosts( rawPosts );
		}
	} catch ( error ) {
		dataviewContainer.innerHTML = `<p class="ccp-error-message">${ __( 'Failed to load posts data.', 'ccp' ) }</p>`;
		return;
	}

	// If no posts, show the message from PHP and exit.
	if ( 0 === posts.length ) {
		dataviewContainer.innerHTML = `<p class="ccp-no-posts">${ __( 'No posts available to display.', 'ccp' ) }</p>`;
		return;
	}

	// Clear container and render DataViews.
	dataviewContainer.innerHTML = '';

	// Render the DataViews component.
	createRoot( dataviewContainer ).render( <ExternalPostsDataView posts={ posts } /> );
} );
