/**
 * Audit Log DataViews component.
 *
 * Server-paginated browser of the audit log table backed by the
 * `safe_publish_get_audit_events` AJAX endpoint. A custom toolbar drives
 * channel/level/event/date filtering; DataViews handles the table render
 * and pagination controls.
 *
 * @file This file defines the AuditLogDataView component.
 */
import {
	BaseControl,
	Button,
	FormTokenField,
	Notice,
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
import { __ } from '@wordpress/i18n';

import { getChannelLabel, getEventLabel, getUserLabel } from './event-fields';
import {
	calendarRangeToUtcBounds,
	DateRangeFilter,
} from './filter-controls';
import { DEFAULT_ITEMS_PER_PAGE, SEARCH_DEBOUNCE_MS } from '../constants';
import { formatDateTime, getErrorMessage } from '../utils';

import type {
	ApiResponse,
	AuditEvent,
	AuditEventsResponse,
	DataViewsField,
	JsonObject,
	JsonValue,
} from '../types';

/**
 * Logger-injected payload keys hidden from the Details summary preview.
 * The actor fields are shown in the User column; event/timestamp in the
 * Event/Date columns; site_url/user_agent/request_uri are forensic
 * context that only matters once expanded. Skipping them keeps the
 * summary focused on event-specific payload bits.
 */
const RESERVED_PAYLOAD_KEYS = new Set( [
	'event',
	'timestamp',
	'site_url',
	'user_agent',
	'request_uri',
	'actor_user_id',
	'actor_display_name',
	'actor_source',
] );

/**
 * Maximum entries shown in the Details column summary; the rest are
 * elided behind a "…" marker and only revealed on expansion.
 */
const SUMMARY_ENTRY_LIMIT = 2;

/**
 * Maximum chars shown per string value in the summary preview.
 */
const SUMMARY_VALUE_MAX = 30;

/**
 * Formats a single JSON value into a compact summary token.
 *
 * @param {JsonValue} value JSON value from the audit payload.
 * @return {string} Short token suitable for inline display.
 */
function formatSummaryValue( value: JsonValue ): string {
	if ( null === value ) {
		return 'null';
	}
	if ( 'string' === typeof value ) {
		return value.length > SUMMARY_VALUE_MAX
			? `${ value.slice( 0, SUMMARY_VALUE_MAX ) }…`
			: value;
	}
	if ( 'number' === typeof value || 'boolean' === typeof value ) {
		return String( value );
	}
	if ( Array.isArray( value ) ) {
		return `[${ value.length }]`;
	}
	return '{…}';
}

/**
 * Builds the compact "key=value, key=value, …" summary shown inline next
 * to the expansion caret. Returns null when the payload carries nothing
 * event-specific so callers can render a fallback label.
 *
 * @param {JsonObject} data Full event payload.
 * @return {string|null} Summary text or null when none can be built.
 */
function buildDetailsSummary( data: JsonObject ): string | null {
	const entries = Object.entries( data ).filter(
		( [ key ] ) => ! RESERVED_PAYLOAD_KEYS.has( key )
	);

	if ( 0 === entries.length ) {
		return null;
	}

	const shown = entries
		.slice( 0, SUMMARY_ENTRY_LIMIT )
		.map( ( [ key, value ] ) => `${ key }=${ formatSummaryValue( value ) }` )
		.join( ', ' );

	return entries.length > SUMMARY_ENTRY_LIMIT ? `${ shown }, …` : shown;
}

/**
 * Renders the expandable Details cell for an AuditEvent row.
 *
 * Uses native `<details>/<summary>` so expansion needs no internal state
 * and respects keyboard navigation out of the box.
 *
 * @param {Object}     props      Component props.
 * @param {AuditEvent} props.item Event row to render.
 * @return {JSX.Element} Rendered details cell.
 */
function DetailsCell( { item }: { item: AuditEvent } ): JSX.Element {
	const summary = buildDetailsSummary( item.data )
		?? __( '(no details)', 'safe-publish' );
	const json = JSON.stringify( item.data, null, 2 );

	return (
		<details className="safe-publish-audit-details">
			<summary className="safe-publish-audit-details__summary">
				{ summary }
			</summary>
			<pre className="safe-publish-audit-details__json">{ json }</pre>
		</details>
	);
}

/**
 * AuditLogDataView component.
 *
 * @return {JSX.Element} Rendered audit log table.
 */
export function AuditLogDataView(): JSX.Element {
	const knownChannels = window.safePublishAdminData?.knownChannels ?? [];
	const knownLevels = window.safePublishAdminData?.knownLevels ?? [];

	const [ view, setView ] = useState< View >( {
		type: 'table',
		perPage: DEFAULT_ITEMS_PER_PAGE,
		page: 1,
		fields: [ 'date', 'channel', 'level', 'event', 'user', 'details' ],
		layout: { density: 'compact' },
	} );

	const [ events, setEvents ] = useState< AuditEvent[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ fetchError, setFetchError ] = useState< string | null >( null );
	const [ hasFetchedOnce, setHasFetchedOnce ] = useState( false );

	const [ selectedChannels, setSelectedChannels ] = useState< string[] >( [] );
	const [ selectedLevels, setSelectedLevels ] = useState< string[] >( [] );
	const [ eventSearch, setEventSearch ] = useState( '' );
	const [ debouncedEventSearch, setDebouncedEventSearch ] = useState( '' );
	const [ after, setAfter ] = useState< string | null >( null );
	const [ before, setBefore ] = useState< string | null >( null );

	const searchDebounceRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const abortRef = useRef< AbortController | null >( null );

	// Join selections into stable keys so the effect re-fires on add/remove
	// without re-firing on identical lists wrapped in a fresh array.
	const channelKey = selectedChannels.join( '|' );
	const levelKey = selectedLevels.join( '|' );

	useEffect( () => {
		abortRef.current?.abort();
		const controller = new AbortController();
		abortRef.current = controller;

		const currentPerPage = view.perPage ?? DEFAULT_ITEMS_PER_PAGE;

		const formData = new FormData();
		formData.append( 'action', 'safe_publish_get_audit_events' );
		formData.append( 'nonce', window.safePublishAdminData.nonce );
		formData.append( 'page', String( view.page ?? 1 ) );
		formData.append( 'per_page', String( currentPerPage ) );

		selectedChannels.forEach( ( channel ) => {
			formData.append( 'channels[]', channel );
		} );
		selectedLevels.forEach( ( level ) => {
			formData.append( 'levels[]', level );
		} );
		if ( '' !== debouncedEventSearch ) {
			formData.append( 'event_search', debouncedEventSearch );
		}
		const { afterUtc, beforeUtc } = calendarRangeToUtcBounds( after, before );
		if ( null !== afterUtc ) {
			formData.append( 'after', afterUtc );
		}
		if ( null !== beforeUtc ) {
			formData.append( 'before', beforeUtc );
		}

		setIsLoading( true );
		setFetchError( null );

		fetch( window.safePublishAdminData.ajaxurl, {
			method: 'POST',
			body: formData,
			signal: controller.signal,
		} )
			.then( ( response ) =>
				response.json() as Promise< ApiResponse< AuditEventsResponse > >
			)
			.then( ( result ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( result.success ) {
					setEvents( result.data.items );
					setTotal( result.data.total );
				} else {
					setFetchError(
						getErrorMessage(
							result,
							__( 'Failed to load audit events.', 'safe-publish' )
						)
					);
					setEvents( [] );
					setTotal( 0 );
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
					__( 'Network error while loading audit events.', 'safe-publish' )
				);
				setEvents( [] );
				setTotal( 0 );
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
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		view.page,
		view.perPage,
		channelKey,
		levelKey,
		debouncedEventSearch,
		after,
		before,
	] );

	// Cleanup outstanding fetch + debounce timer on unmount.
	useEffect( () => () => {
		abortRef.current?.abort();
		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
		}
	}, [] );

	const resetPage = useCallback(
		() => setView( ( current ) => ( { ...current, page: 1 } ) ),
		[]
	);

	const handleSearchChange = ( raw: string ): void => {
		setEventSearch( raw );

		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
		}

		searchDebounceRef.current = setTimeout( () => {
			setDebouncedEventSearch( raw.trim() );
			resetPage();
		}, SEARCH_DEBOUNCE_MS );
	};

	const handleChannelsChange = ( tokens: ( string | { value: string } )[] ): void => {
		const next = tokens
			.map( ( token ) => ( 'string' === typeof token ? token : token.value ) )
			.filter( ( value ) => knownChannels.includes( value ) );
		setSelectedChannels( next );
		resetPage();
	};

	const handleLevelsChange = ( tokens: ( string | { value: string } )[] ): void => {
		const next = tokens
			.map( ( token ) => ( 'string' === typeof token ? token : token.value ) )
			.filter( ( value ) => knownLevels.includes( value ) );
		setSelectedLevels( next );
		resetPage();
	};

	const handleDateRangeChange = (
		next: { after: string | null; before: string | null }
	): void => {
		setAfter( next.after );
		setBefore( next.before );
		resetPage();
	};

	const handleClearFilters = (): void => {
		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
			searchDebounceRef.current = null;
		}

		setSelectedChannels( [] );
		setSelectedLevels( [] );
		setEventSearch( '' );
		setDebouncedEventSearch( '' );
		setAfter( null );
		setBefore( null );
		resetPage();
	};

	const handleViewChange = ( next: View ): void => {
		const perPageChanged = next.perPage !== view.perPage;
		setView( {
			...next,
			page: perPageChanged ? 1 : ( next.page ?? view.page ?? 1 ),
		} );
	};

	const hasActiveFilters =
		selectedChannels.length > 0 ||
		selectedLevels.length > 0 ||
		'' !== eventSearch ||
		null !== after ||
		null !== before;

	const fields: DataViewsField< AuditEvent >[] = useMemo( () => [
		{
			id: 'date',
			label: __( 'Date', 'safe-publish' ),
			enableSorting: false,
			render: ( { item }: { item: AuditEvent } ): JSX.Element => (
				<span>{ formatDateTime( item.date ) }</span>
			),
		},
		{
			id: 'channel',
			label: __( 'Channel', 'safe-publish' ),
			enableSorting: false,
			render: ( { item }: { item: AuditEvent } ): JSX.Element => (
				<span className="safe-publish-audit-channel">
					{ getChannelLabel( item.channel ) }
				</span>
			),
		},
		{
			id: 'level',
			label: __( 'Level', 'safe-publish' ),
			enableSorting: false,
			render: ( { item }: { item: AuditEvent } ): JSX.Element => {
				const labels: Record< AuditEvent[ 'level' ], string > = {
					info: __( 'Info', 'safe-publish' ),
					warning: __( 'Warning', 'safe-publish' ),
					error: __( 'Error', 'safe-publish' ),
				};
				const label = labels[ item.level ];
				return (
					<span
						className={ `safe-publish-status-badge safe-publish-status-badge--${ item.level }` }
					>
						<span className="safe-publish-status-badge__dot" aria-hidden="true" />
						{ label }
					</span>
				);
			},
		},
		{
			id: 'event',
			label: __( 'Event', 'safe-publish' ),
			enableSorting: false,
			render: ( { item }: { item: AuditEvent } ): JSX.Element => (
				<span>{ getEventLabel( item.event ) }</span>
			),
		},
		{
			id: 'user',
			label: __( 'User', 'safe-publish' ),
			enableSorting: false,
			render: ( { item }: { item: AuditEvent } ): JSX.Element => (
				<span>{ getUserLabel( item ) }</span>
			),
		},
		{
			id: 'details',
			label: __( 'Details', 'safe-publish' ),
			enableSorting: false,
			enableHiding: false,
			render: ( { item }: { item: AuditEvent } ): JSX.Element => (
				<DetailsCell item={ item } />
			),
		},
	], [] );

	const currentPerPage = view.perPage ?? DEFAULT_ITEMS_PER_PAGE;
	const paginationInfo = useMemo(
		() => ( {
			totalItems: total,
			totalPages: Math.max( 1, Math.ceil( total / currentPerPage ) ),
		} ),
		[ total, currentPerPage ]
	);

	return (
		<div className="safe-publish-dataviews-wrapper">
			<div className="safe-publish-controls-row">
				<div className="safe-publish-control safe-publish-control--statuses">
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						__experimentalExpandOnFocus
						__experimentalShowHowTo={ false }
						label={ __( 'Channel', 'safe-publish' ) }
						placeholder={ __( 'All channels', 'safe-publish' ) }
						value={ selectedChannels }
						suggestions={ knownChannels }
						onChange={ handleChannelsChange }
					/>
				</div>
				<div className="safe-publish-control safe-publish-control--statuses">
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						__experimentalExpandOnFocus
						__experimentalShowHowTo={ false }
						label={ __( 'Level', 'safe-publish' ) }
						placeholder={ __( 'All levels', 'safe-publish' ) }
						value={ selectedLevels }
						suggestions={ knownLevels }
						onChange={ handleLevelsChange }
					/>
				</div>
				<div className="safe-publish-control safe-publish-control--search">
					<BaseControl
						__nextHasNoMarginBottom
						label={ __( 'Event', 'safe-publish' ) }
						id="safe-publish-audit-event-search"
					>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							id="safe-publish-audit-event-search"
							label={ __( 'Event name contains', 'safe-publish' ) }
							hideLabelFromVision
							value={ eventSearch }
							onChange={ handleSearchChange }
						/>
					</BaseControl>
				</div>
				<DateRangeFilter
					label={ __( 'Date', 'safe-publish' ) }
					id="safe-publish-audit-date"
					after={ after }
					before={ before }
					onChange={ handleDateRangeChange }
				/>
				{ hasActiveFilters && (
					<Button
						variant="tertiary"
						onClick={ handleClearFilters }
					>
						{ __( 'Clear filters', 'safe-publish' ) }
					</Button>
				) }
			</div>
			{ fetchError && (
				<Notice
					status="error"
					onRemove={ () => setFetchError( null ) }
				>
					{ fetchError }
				</Notice>
			) }
			{ isLoading && ! hasFetchedOnce && (
				<div className="safe-publish-loading" role="status" aria-live="polite">
					<p>{ __( 'Loading audit log…', 'safe-publish' ) }</p>
				</div>
			) }
			{ hasFetchedOnce && ! fetchError && 0 === events.length && ! isLoading && (
				<div className="safe-publish-no-data" role="status" aria-live="polite">
					<p>
						{ hasActiveFilters
							? __( 'No events matched these filters.', 'safe-publish' )
							: __( 'No audit events have been recorded yet.', 'safe-publish' )
						}
					</p>
				</div>
			) }
			{ hasFetchedOnce && events.length > 0 && (
				<DataViews
					getItemId={ ( item: AuditEvent ) => item.id.toString() }
					data={ events }
					fields={ fields }
					view={ view }
					onChangeView={ handleViewChange }
					paginationInfo={ paginationInfo }
					actions={ [] }
					defaultLayouts={ { table: {} } }
				/>
			) }
		</div>
	);
}
