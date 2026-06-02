/**
 * Export History React component using DataViews.
 *
 * Displays a table of export events logged when content is served to
 * destination sites via the REST API.
 *
 * @file This file defines the ExportHistory component.
 */
import { getDestinationLabel, getStatusLabel, getUserLabel } from './event-fields';
import { useDataViewsResult } from './hooks/useDataViewsResult';
import { DEFAULT_ITEMS_PER_PAGE } from '../constants';
import { formatDateTime, getErrorMessage } from '../utils';
import {
	Button,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	Spinner,
} from '@wordpress/components';
import { DataViews, View } from '@wordpress/dataviews';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { ApiResponse, DataViewsField, ExportEvent } from '../types';

/**
 * Export History component.
 *
 * Renders a DataViews table of export events with filtering and sorting.
 *
 * @return {JSX.Element} Rendered export history table.
 */
export function ExportHistory(): JSX.Element {
	const [ events, setEvents ] = useState< ExportEvent[] >( [] );
	const [ isLoading, setIsLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );

	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: DEFAULT_ITEMS_PER_PAGE,
		page: 1,
		sort: {
			field: 'date',
			direction: 'desc',
		},
		search: '',
		filters: [],
		fields: [ 'date', 'user', 'destination', 'status', 'posts' ],
	} );

	useEffect( () => {
		void loadExportEvents();
	}, [] );

	/**
	 * Loads export events from the backend.
	 *
	 * @return {Promise<void>} Resolves when events are loaded.
	 */
	const loadExportEvents = async (): Promise< void > => {
		setIsLoading( true );
		setError( null );

		try {
			const formData = new FormData();
			formData.append( 'action', 'safe_publish_get_export_events' );
			formData.append( 'nonce', window.safePublishAdminData.nonce );

			const response = await fetch( window.safePublishAdminData.ajaxurl, {
				method: 'POST',
				body: formData,
			} );

			const result = await response.json() as ApiResponse< ExportEvent[] >;

			if ( result.success ) {
				setEvents( result.data );
			} else {
				setError( getErrorMessage( result, __( 'Failed to load export events.', 'safe-publish' ) ) );
			}
		} catch ( err ) {
			setError( __( 'Network error while loading export events.', 'safe-publish' ) );
		} finally {
			setIsLoading( false );
		}
	};

	const fields: DataViewsField< ExportEvent >[] = [
		{
			id: 'date',
			label: __( 'Date', 'safe-publish' ),
			enableSorting: true,
			enableGlobalSearch: true,
			// Match raw ISO and formatted date (e.g. "2026-05" or "May").
			getValue: ( { item }: { item: ExportEvent } ): string =>
				`${ item.date } ${ formatDateTime( item.date ) }`,
			sort: (
				eventA: ExportEvent,
				eventB: ExportEvent,
				direction
			): number => {
				// Sort by raw ISO; formatted strings won't sort chronologically.
				const diff = eventA.date.localeCompare( eventB.date );
				return 'asc' === direction ? diff : -diff;
			},
			render: ( { item }: { item: ExportEvent } ): JSX.Element => (
				<span>{ formatDateTime( item.date ) }</span>
			),
		},
		{
			id: 'user',
			label: __( 'User', 'safe-publish' ),
			enableSorting: true,
			enableGlobalSearch: true,
			getValue: ( { item }: { item: ExportEvent } ): string =>
				getUserLabel( item ),
			render: ( { item }: { item: ExportEvent } ): JSX.Element => (
				<span>{ getUserLabel( item ) }</span>
			),
		},
		{
			id: 'destination',
			label: __( 'Destination', 'safe-publish' ),
			enableSorting: false,
			enableGlobalSearch: true,
			getValue: ( { item }: { item: ExportEvent } ): string =>
				getDestinationLabel( item ),
			render: ( { item }: { item: ExportEvent } ): JSX.Element => (
				<span>{ getDestinationLabel( item ) }</span>
			),
		},
		{
			id: 'status',
			label: __( 'Status', 'safe-publish' ),
			enableSorting: true,
			enableGlobalSearch: true,
			getValue: ( { item }: { item: ExportEvent } ): string =>
				getStatusLabel( item ),
			sort: (
				eventA: ExportEvent,
				eventB: ExportEvent,
				direction
			): number => {
				const diff = eventA.level.localeCompare( eventB.level );
				return 'asc' === direction ? diff : -diff;
			},
			render: ( { item }: { item: ExportEvent } ): JSX.Element => {
				const isError = 'error' === item.level;
				return (
					<span
						className={ `safe-publish-status-${
							isError ? 'error' : 'completed'
						}` }
					>
						{ getStatusLabel( item ) }
					</span>
				);
			},
		},
		{
			id: 'posts',
			label: __( 'Posts', 'safe-publish' ),
			enableSorting: false,
			render: ( { item }: { item: ExportEvent } ): JSX.Element => (
				<span>{ item.post_count }</span>
			),
		},
	];

	const { data: filteredEvents, paginationInfo } =
		useDataViewsResult( events, view, fields );

	if ( isLoading ) {
		return (
			<VStack>
				<HStack>
					<Spinner />
					<Text>{ __( 'Loading export events…', 'safe-publish' ) }</Text>
				</HStack>
			</VStack>
		);
	}

	if ( error ) {
		return (
			<VStack>
				<Text style={ { color: '#d63638' } }>
					{ /* translators: %s is the error message */
					__( 'Error: %s', 'safe-publish' ).replace( '%s', error ) }
				</Text>
				<Button variant="secondary" onClick={ () => void loadExportEvents() }>
					{ __( 'Retry', 'safe-publish' ) }
				</Button>
			</VStack>
		);
	}

	return (
		<div className="safe-publish-exports">
			<VStack spacing={ 4 }>
				<Text>
					{ __( 'Events logged when posts are served to destination sites.', 'safe-publish' ) }
				</Text>

				{ events.length === 0 ? (
					<Text>{ __( 'No export events found.', 'safe-publish' ) }</Text>
				) : (
					<DataViews
						data={ filteredEvents }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						actions={ [] }
						getItemId={ ( item: ExportEvent ) => item.id.toString() }
						paginationInfo={ paginationInfo }
						defaultLayouts={ {
							table: {},
						} }
					/>
				) }
			</VStack>
		</div>
	);
}
