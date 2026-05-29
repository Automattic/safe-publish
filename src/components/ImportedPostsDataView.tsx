/**
 * DataViews component for the Imported Posts admin page.
 *
 * Lists locally-imported posts via the destination-side
 * `safe_publish_list_imported_posts` AJAX action — purely local query, no
 * source roundtrip. Rows support Edit, Update, Diff, Delete, and Rollback
 * (single or bulk); sync status lands in a follow-up PR.
 *
 * @file This file defines the ImportedPostsDataView component.
 */
import { createImportedActions } from '../actions';
import { DEFAULT_ITEMS_PER_PAGE, LAYOUT_GRID, LAYOUT_LIST, LAYOUT_TABLE } from '../constants';
import { extractUrlPath, formatDateTime, getErrorMessage, PUBLISH_STATUS_LABELS } from '../utils';
import { Notice, Spinner } from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	DataViewsField,
	ImportedPost,
	ImportedPostsResponse,
} from '../types';

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
		fields: [ 'permalink', 'local_status', 'import_date_gmt' ],
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

	useEffect( () => {
		const controller = new AbortController();

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_list_imported_posts' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'page', String( view.page ?? 1 ) );
		formData.append( 'per_page', String( view.perPage ?? DEFAULT_ITEMS_PER_PAGE ) );

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
	}, [ view.page, view.perPage, refreshNonce ] );

	const fields: DataViewsField< ImportedPost >[] = useMemo(
		() => [
			{
				id: 'title',
				label: __( 'Title', 'safe-publish' ),
				enableSorting: false,
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
				id: 'import_date_gmt',
				label: __( 'Last Imported', 'safe-publish' ),
				enableSorting: false,
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
		[]
	);

	const refresh = useCallback(
		() => setRefreshNonce( ( nonce ) => nonce + 1 ),
		[]
	);
	const actions = useMemo(
		() => createImportedActions( refresh ),
		[ refresh ]
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

	return (
		<div
			className="safe-publish-dataviews-wrapper"
			style={
				{
					'--safe-publish-page-text': `"${ pageStatusText }"`,
				} as React.CSSProperties
			}
		>
			{ fetchError && (
				<Notice
					className="safe-publish-post-type-error"
					status="error"
					onRemove={ () => setFetchError( null ) }
				>
					{ fetchError }
				</Notice>
			) }
			{ isLoading && ! hasFetchedOnce && (
				<div className="safe-publish-loading" role="status" aria-live="polite">
					<Spinner />
					<p>{ __( 'Loading imported posts…', 'safe-publish' ) }</p>
				</div>
			) }
			{ hasFetchedOnce && ! fetchError && 0 === pageItems.length && ! isLoading && (
				<div className="safe-publish-no-data" role="status" aria-live="polite">
					<p>
						{ __(
							'No posts have been imported yet.',
							'safe-publish'
						) }
					</p>
				</div>
			) }
			{ hasFetchedOnce && pageItems.length > 0 && (
				<DataViews
					getItemId={ ( item: ImportedPost ) => item.id.toString() }
					data={ pageItems }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					paginationInfo={ paginationInfo }
					defaultLayouts={ defaultLayouts }
					actions={ actions }
				/>
			) }
		</div>
	);
}
