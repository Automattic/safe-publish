/**
 * DataViews component for the Imports → Failures tab.
 *
 * Lists items with status 'error' from `safe_publish_list_failed_imports`.
 * Read-only — there's no local post to act on, and recovery is fixing the
 * source then re-importing from Source Posts.
 *
 * @file This file defines the FailedImportsDataView component.
 */
import { DEFAULT_ITEMS_PER_PAGE, LAYOUT_TABLE } from '../constants';
import { formatDateTime, getErrorMessage } from '../utils';
import { Notice, Spinner } from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import { useState, useEffect, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type {
	ApiResponse,
	DataViewsField,
	FailedImport,
	FailedImportsResponse,
} from '../types';

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
		fields: [ 'source', 'error_message', 'import_date_gmt' ],
		titleField: 'title',
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

	useEffect( () => {
		const controller = new AbortController();

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_list_failed_imports' );
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
	}, [ view.page, view.perPage ] );

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

	const showLoading = isLoading && ! hasFetchedOnce;
	const showEmptyState =
		hasFetchedOnce && ! isLoading && 0 === pageItems.length && null === fetchError;

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
			{ showLoading && (
				<div className="safe-publish-loading" role="status" aria-live="polite">
					<Spinner />
					<p>{ __( 'Loading failed imports…', 'safe-publish' ) }</p>
				</div>
			) }
			{ showEmptyState && (
				<div className="safe-publish-no-data" role="status" aria-live="polite">
					<p>
						{ __(
							'No failed imports. Errors from past imports appear here for reference.',
							'safe-publish'
						) }
					</p>
				</div>
			) }
			{ hasFetchedOnce && ( pageItems.length > 0 || null !== fetchError ) && (
				<DataViews
					getItemId={ ( item: FailedImport ) => item.id.toString() }
					data={ pageItems }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					paginationInfo={ paginationInfo }
					defaultLayouts={ defaultLayouts }
					actions={ [] }
				/>
			) }
		</div>
	);
}
