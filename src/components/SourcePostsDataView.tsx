/**
 * DataViews component for source posts.
 *
 * Renders a DataViews table/grid/list of posts fetched from a source
 * WordPress site, with search, sort, pagination, and per-row actions.
 *
 * @file This file defines the SourcePostsDataView component.
 */
import { update } from '@wordpress/icons';

import AuthStatusNotice from './AuthStatusNotice';
import { useDataViewsResult } from './hooks/useDataViewsResult';
import {
	getPostTypeLabel,
	getPublishStatusLabel,
	getSyncStatusLabel,
	getSyncStatusOrder,
} from './post-fields';
import { createActions } from '../actions';
import {
	DEFAULT_ITEMS_PER_PAGE,
	LAYOUT_GRID,
	LAYOUT_LIST,
	LAYOUT_TABLE,
	MAX_POSTS_COUNT,
	MIN_POSTS_COUNT,
	NUMBER_POSTS_DEBOUNCE_DELAY,
} from '../constants';
import { PostTypeSelector } from '../post-type-selector';
import {
	extractUrlPath,
	formatDateTime,
	getErrorMessage,
	PUBLISH_STATUS_LABELS,
	sanitizePosts,
	SYNC_STATUS_LABELS,
} from '../utils';
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
	SourcePostsDataViewProps,
	Post,
} from '../types';

/**
 * DataViews component for source posts.
 *
 * Renders a DataViews table with search, sort, and pagination capabilities
 * for displaying posts fetched from source WordPress sites.
 *
 * @param {Object} props               Component props.
 * @param {Post[]} props.initialPosts  Posts to display on initial load.
 * @param {string} props.sourceSiteUrl Source site URL.
 * @param {number} props.numberPosts   Number of posts to fetch.
 *
 * @return {JSX.Element} Rendered DataViews component.
 */
export function SourcePostsDataView( { initialPosts, sourceSiteUrl, numberPosts }: SourcePostsDataViewProps ): JSX.Element {
	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: DEFAULT_ITEMS_PER_PAGE,
		page: 1,
		sort: {
			field: 'modified_gmt',
			direction: 'desc',
		},
		search: '',
		filters: [],
		fields: [ 'permalink', 'modified_gmt', 'sync_status', 'publish_status' ],
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

	const isAuthorized = 'authorized' === authStatus;

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
			getValue: ( { item }: { item: Post } ): string =>
				getPostTypeLabel( item ),
			render: ( { item }: { item: Post } ): JSX.Element => (
				<span>{ getPostTypeLabel( item ) }</span>
			),
		},
		{
			id: 'permalink',
			label: __( 'Permalink', 'safe-publish' ),
			enableSorting: false,
			enableGlobalSearch: true,
			// 'permalink' has no matching property; search by item.link.
			getValue: ( { item }: { item: Post } ): string => item.link ?? '',
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
			id: 'modified_gmt',
			label: __( 'Last Modified', 'safe-publish' ),
			enableSorting: true,
			enableGlobalSearch: true,
			// Match raw ISO and formatted date (e.g. "2024-07" or "July").
			getValue: ( { item }: { item: Post } ): string =>
				`${ item.modified_gmt } ${ formatDateTime( item.modified_gmt ) }`,
			sort: ( postA: Post, postB: Post, direction ): number => {
				// Sort by raw ISO; formatted strings won't sort chronologically.
				const diff = postA.modified_gmt.localeCompare( postB.modified_gmt );
				return 'asc' === direction ? diff : -diff;
			},
			render: ( { item }: { item: Post } ): JSX.Element => (
				<span>{ formatDateTime( item.modified_gmt ) }</span>
			),
		},
		{
			id: 'sync_status',
			label: __( 'Sync Status', 'safe-publish' ),
			enableSorting: true,
			enableGlobalSearch: true,
			// Virtual field derived from is_imported + has_update;
			// default getValue and sort would miss the derivation.
			getValue: ( { item }: { item: Post } ): string =>
				getSyncStatusLabel( item ),
			sort: ( postA: Post, postB: Post, direction ): number => {
				const diff = getSyncStatusOrder( postA ) - getSyncStatusOrder( postB );
				return 'asc' === direction ? diff : -diff;
			},
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
			getValue: ( { item }: { item: Post } ): string =>
				getPublishStatusLabel( item ),
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

	const { data: filteredData, paginationInfo } =
		useDataViewsResult( allPosts, view, fields );

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
		if ( ! sourceSiteUrl ) {
			return;
		}

		setIsLoadingPosts( true );
		setPostTypeError( null );

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_fetch_posts' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'source_site_url', sourceSiteUrl );
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

				// Memo re-derives from the new posts and view automatically.
				setView( { ...view, page: 1, search: '' } );
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
					sourceSiteUrl={ sourceSiteUrl }
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
					onChangeView={ setView }
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
