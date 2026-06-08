/**
 * Type definitions for Safe Publish plugin.
 *
 * Contains all TypeScript interfaces and type definitions used throughout the
 * plugin's frontend components.
 *
 * @file This file defines the TypeScript types for the Safe Publish plugin.
 */

/**
 * JSON-serializable primitive values.
 */
export type JsonPrimitive = string | number | boolean | null;

/**
 * JSON-serializable array.
 */
export type JsonArray = JsonValue[];

/**
 * JSON-serializable object.
 */
export type JsonObject = { [key: string]: JsonValue };

/**
 * Any valid JSON value.
 *
 * Represents data that can be safely serialized to JSON and sent to/from WordPress REST API.
 * This matches what WordPress meta fields, REST API responses, and AJAX handlers support.
 */
export type JsonValue = JsonPrimitive | JsonArray | JsonObject;

/**
 * Represents a post item returned by the source catalog endpoint.
 *
 * Listing-context only — the import path re-fetches via wp/v2/{type}/{id}
 * to obtain raw content/meta/terms, so this shape stays minimal.
 *
 * @property {number} id           Unique post ID on the source.
 * @property {string} link         Permalink URL on the source.
 * @property {string} title        Sanitized post title.
 * @property {string} date_gmt     Published date in ISO 8601 UTC.
 * @property {string} modified_gmt Last modified date in ISO 8601 UTC.
 * @property {string} post_type    Post type slug.
 * @property {string} status       Source post status (publish, draft, …).
 */
export interface Post {
	id: number;
	link: string;
	title: string;
	date_gmt: string;
	modified_gmt: string;
	post_type: string;
	status: string;
	is_imported?: boolean;
	sync_status?: SourcePostsSyncStatus;
	local_status?: string | null;
}

/**
 * Sync-status verdict surfaced by annotate_posts_with_import_status.
 *
 * `unknown` covers cases where either timestamp can't be parsed — typically a
 * missing items row (pruned history, legacy data) or a malformed source date.
 */
export type SourcePostsSyncStatus =
	| 'available'
	| 'up-to-date'
	| 'outdated'
	| 'unknown';

/**
 * Envelope returned by the source catalog endpoint.
 *
 * `has_more` lets the UI render a Next button without computing a total
 * page count (the source skips SQL_CALC_FOUND_ROWS for huge sites).
 */
export interface CatalogResponse {
	items: Post[];
	has_more: boolean;
}

/**
 * Represents a row in the Imports → Posts tab listing.
 *
 * Joins the local post (title, status, edit url) with the most recent
 * items-table row for the same post (session id, rollback status, import
 * date) — the page's data source is local; no source roundtrip on listing.
 *
 * @property {number}      id                   Local WordPress post ID.
 * @property {number}      source_post_id       Source post ID this row was imported from.
 * @property {string}      title                Local post title.
 * @property {string}      post_type            Local post type slug.
 * @property {string}      local_status         Local post_status.
 * @property {string}      edit_url             Local wp-admin edit URL.
 * @property {string}      source_link          Source post permalink (from META_SOURCE_LINK).
 * @property {number|null} item_id              Most recent import's items-table row ID, or null.
 * @property {number|null} session_id           Session ID of the most recent import event, or null.
 * @property {string|null} rollback_status      Items-table status (success/updated/error), or null.
 * @property {boolean}     has_previous_content Whether the row has a pre-update snapshot for rollback restore.
 * @property {string|null} import_date_gmt      Most recent import_date_gmt from the items table, or null.
 */
export interface ImportedPost {
	id: number;
	source_post_id: number;
	title: string;
	post_type: string;
	local_status: string;
	edit_url: string;
	source_link: string;
	item_id: number | null;
	session_id: number | null;
	rollback_status: string | null;
	has_previous_content: boolean;
	import_date_gmt: string | null;
}

/**
 * A selectable option for a DataViews filter (value + display label).
 */
export interface FilterOption {
	value: string;
	label: string;
}

/**
 * Filter facets for the Imports → Posts listing, computed over the full
 * imported set so the dropdowns stay complete as results are narrowed.
 */
export interface ImportedPostsFacets {
	post_types: FilterOption[];
}

/**
 * Envelope returned by the destination-side imported-posts listing endpoint.
 * `facets` is present only on the first load (requested via with_facets);
 * they cover the listing's full set, not the current page.
 */
export interface ImportedPostsResponse {
	items: ImportedPost[];
	has_more: boolean;
	facets?: ImportedPostsFacets;
}

/**
 * Per-row verdicts returned by safe_publish_sync_status_batch.
 *
 * `invalid` flags a destination-side timestamp that didn't parse — a local
 * data bug, distinct from the network-level `unreachable`. `loading` is a
 * client-only placeholder shown while the batch request is in flight; it
 * is never returned by the server.
 */
export type ImportSyncStatus =
	| 'up-to-date'
	| 'outdated'
	| 'missing'
	| 'unreachable'
	| 'invalid'
	| 'loading';

/**
 * Per-row entry in the sync-status batch response.
 *
 * `modified_gmt` is set only for `outdated` and `up-to-date` — i.e., when
 * the source returned a parseable timestamp.
 *
 * @property {string} status         One of up-to-date, outdated, missing, unreachable, invalid.
 * @property {string} [modified_gmt] Source post's last modification time in ISO 8601 UTC.
 */
export interface SyncStatusEntry {
	status: Exclude< ImportSyncStatus, 'loading' >;
	modified_gmt?: string;
}

/**
 * Envelope returned by safe_publish_sync_status_batch.
 *
 * Keys are source post IDs; absent IDs (e.g. the destination has no matching
 * local post for a requested ID) are simply omitted.
 */
export interface SyncStatusBatchResponse {
	statuses: Record< number, SyncStatusEntry >;
}

/**
 * Represents a failed import item shown on the Imports → Failures tab.
 *
 * Failed items have no local post (the import errored before insert) — so the
 * row carries only what the items table recorded plus the source site URL from
 * the parent session.
 *
 * @property {number}      id              Items-table row id.
 * @property {number}      session_id      Parent session id.
 * @property {string}      title           Post title attempted.
 * @property {number|null} source_post_id  Source post id, or null when missing.
 * @property {string}      source_site_url Source site URL from the session.
 * @property {string}      error_message   Failure reason recorded by the import.
 * @property {string}      import_date_gmt MySQL datetime (UTC) of the attempt.
 */
export interface FailedImport {
	id: number;
	session_id: number;
	title: string;
	source_post_id: number | null;
	source_site_url: string;
	error_message: string;
	import_date_gmt: string;
}

/**
 * Envelope returned by the destination-side failed-imports listing endpoint.
 */
export interface FailedImportsResponse {
	items: FailedImport[];
	has_more: boolean;
}

/**
 * Generic API response wrapper.
 *
 * Discriminated union type for WordPress AJAX/REST API responses.
 * When success is true, data contains the response payload.
 * When success is false, error message may be in either 'error' or 'data' field
 * (WordPress wp_send_json_error uses 'data', custom handlers may use 'error').
 *
 * @template T Type of the data payload on success.
 */
export type ApiResponse< T = unknown > =
	| { success: true; data: T }
	| { success: false; data?: JsonValue; error?: string };

/**
 * Surfaced when the opt-in author fallback was applied during import.
 *
 * `fallback_user_id` is non-null for inserts (the importing user) and null
 * for updates (where the destination's existing author was preserved).
 */
export interface AuthorFallbackWarning {
	type: 'author_fallback_applied';
	source: {
		email: string;
		login: string;
		display_name: string;
	};
	fallback_user_id: number | null;
}

/**
 * Surfaced when a hierarchical post was imported as orphan because its source
 * parent could not be resolved on the destination.
 *
 * `parent_title` is null for `not_imported` (the title is not available
 * without an extra REST call to the source). For `failed_in_batch` the
 * parent's title is always present because pass-1 fetched its REST data.
 */
export interface ParentOrphanedWarning {
	type: 'parent_orphaned';
	source: {
		parent_id: number;
		parent_title: string | null;
	};
	reason: 'not_imported' | 'failed_in_batch';
}

/**
 * Discriminated union of all import warning types.
 */
export type Warning = AuthorFallbackWarning | ParentOrphanedWarning;

/**
 * Response from create draft post operation.
 *
 * @property {number}    post_id          Created/updated post ID.
 * @property {string}    edit_url         URL to edit the post.
 * @property {string}    message          Success message.
 * @property {boolean}   [existing]       Whether post already existed.
 * @property {string}    [post_title]     Post title.
 * @property {string}    [confirm_action] Action requiring confirmation.
 * @property {Warning[]} [warnings]       Non-fatal warnings raised during import.
 */
export interface CreateDraftResponse {
	post_id: number;
	edit_url: string;
	message: string;
	existing?: boolean;
	post_title?: string;
	confirm_action?: string;
	warnings?: Warning[];
}

/**
 * Individual result from bulk import operation.
 *
 * @property {number|null} source_post_id Source post ID, or null if not provided.
 * @property {string}      title          Post title.
 * @property {boolean}     success        Whether import succeeded.
 * @property {number}      [post_id]      Local post ID if successful.
 * @property {string}      [edit_url]     URL to edit the post.
 * @property {string}      [error]        Error message if failed.
 * @property {boolean}     [existing]     Whether post already existed.
 * @property {Warning[]}   [warnings]     Non-fatal warnings raised during import.
 */
export interface BulkImportResult {
	source_post_id: number | null;
	title: string;
	success: boolean;
	post_id?: number;
	edit_url?: string;
	error?: string;
	existing?: boolean;
	warnings?: Warning[];
}

/**
 * Response from bulk import operation.
 *
 * @property {number}             total      Total posts processed.
 * @property {number}             successful Number of successful imports.
 * @property {number}             failed     Number of failed imports.
 * @property {BulkImportResult[]} results    Individual result objects.
 */
export interface BulkImportResponse {
	total: number;
	successful: number;
	failed: number;
	results: BulkImportResult[];
}

/**
 * Connection test result data.
 *
 * @property {boolean}    success         Whether connection succeeded.
 * @property {string}     message         Test result message.
 * @property {AuthStatus} [status]        Probe status returned by the backend.
 * @property {number}     [response_time] Response time in milliseconds.
 */
export interface ConnectionTestData {
	success: boolean;
	message: string;
	status?: AuthStatus;
	response_time?: number;
}

/**
 * Auth probe status values returned by safe_publish_auth_status.
 */
export type AuthStatus =
	| 'authorized'
	| 'unauthorized'
	| 'unreachable'
	| 'url_unset';

/**
 * Auth probe result returned by safe_publish_auth_status.
 *
 * @property {AuthStatus} status    Probe status.
 * @property {number}     [code]    HTTP response code from the probe request.
 * @property {string}     [message] Underlying error message when applicable.
 */
export interface AuthStatusData {
	status: AuthStatus;
	code?: number;
	message?: string;
}

/**
 * Diff HTML response data.
 *
 * @property {string} diff_html Rendered HTML diff.
 */
export interface DiffHtmlData {
	diff_html: string;
}

/**
 * Props for the SourcePostsDataView component.
 *
 * @property {string} sourceSiteUrl Source site URL; component fetches the
 *                                  first page on mount.
 */
export interface SourcePostsDataViewProps {
	sourceSiteUrl: string;
}

/**
 * Admin data passed from PHP via wp_add_inline_script. Shared by the Source
 * Posts and Imports pages; `sourceSiteUrl` and `importsUrl` are only set by
 * Source Posts; `sourcePostsUrl`, `homeUrl`, and `initialTab` are only set
 * by Imports.
 *
 * @property {string} ajaxurl          WordPress AJAX URL.
 * @property {string} nonce            Security nonce for AJAX requests.
 * @property {string} restNonce        Security nonce for REST API requests.
 * @property {string} [sourceSiteUrl]  Source site URL (Source Posts only).
 * @property {string} settingsUrl      URL to the plugin settings page.
 * @property {string} [sourcePostsUrl] URL to the Source Posts admin page (Imports only).
 * @property {string} [importsUrl]     URL to the Imports admin page (Source Posts only).
 * @property {string} [homeUrl]        Destination home URL for URL-paste slug detection (Imports only).
 * @property {string} containerId      Container element ID.
 * @property {string} [initialTab]     Imports: 'posts' or 'failures' from ?tab=...
 */
export interface AdminData {
	ajaxurl: string;
	nonce: string;
	restNonce: string;
	sourceSiteUrl?: string;
	settingsUrl: string;
	sourcePostsUrl?: string;
	importsUrl?: string;
	homeUrl?: string;
	containerId: string;
	initialTab?: 'posts' | 'failures';
}

/**
 * Field configuration for DataViews columns.
 *
 * @property {string}   id                   Unique field identifier.
 * @property {string}   label                Display label.
 * @property {Function} [render]             Custom render function.
 * @property {boolean}  [enableSorting]      Whether sorting is enabled.
 * @property {boolean}  [enableGlobalSearch] Whether to include in global search.
 * @property {boolean}  [enableHiding]       Whether the field can be hidden.
 * @property {Function} [sort]               Custom sort function.
 * @property {Function} [getValue]           Returns the searchable/sortable value.
 */
export interface DataViewsField<T = Post> {
	id: string;
	label: string;
	render?: ( args: { item: T } ) => JSX.Element;
	enableSorting?: boolean;
	enableGlobalSearch?: boolean;
	enableHiding?: boolean;
	sort?: ( a: T, b: T, direction: 'asc' | 'desc' ) => number;
	getValue?: ( args: { item: T } ) => string;
}

/**
 * State object for DataViews component.
 *
 * @property {'table'|'grid'|'list'} type               Current view type.
 * @property {number}                perPage            Items per page.
 * @property {number}                page               Current page number.
 * @property {Object}                sort               Sort configuration.
 * @property {string}                search             Current search term.
 * @property {JsonArray}             filters            Active filters.
 * @property {string[]}              hiddenFields       Hidden field IDs.
 * @property {Object}                layout             Layout configuration.
 * @property {string[]}              fields             Visible field IDs.
 * @property {string}                [titleField]       Title field ID.
 * @property {string}                [descriptionField] Description field ID.
 * @property {string}                [mediaField]       Media field ID.
 */
export interface DataViewsState {
	type: 'table' | 'grid' | 'list';
	perPage: number;
	page: number;
	sort: {
		field: string;
		direction: 'asc' | 'desc';
	};
	search: string;
	filters: JsonArray;
	hiddenFields: string[];
	layout: {
		primaryField?: string;
		mediaField?: string | null;
	};
	fields: string[];
	titleField?: string;
	descriptionField?: string;
	mediaField?: string;
}

/**
 * Invocation context identifier captured for every audit log event.
 *
 * Matches the precedence resolved server-side by `Logger::detect_actor_source()`.
 */
export type ActorSource =
	| 'cli'
	| 'cron'
	| 'hmac'
	| 'xmlrpc'
	| 'ajax'
	| 'rest'
	| 'admin'
	| 'front'
	| 'unknown';

/**
 * Represents a single export event from the audit log table.
 *
 * `actor_user_id` is 0 for system-triggered events (cron, cli, hmac, etc.);
 * `actor_display_name` is the snapshot taken when the event was recorded;
 * `actor_source` disambiguates the invocation context for system events.
 *
 * @property {number}         id                   Unique event ID.
 * @property {string}         date                 Date the event was recorded.
 * @property {'info'|'error'} level                Event severity level.
 * @property {string}         event                Event type (e.g. CONTENT_EXPORTED).
 * @property {number}         actor_user_id        Acting user ID; 0 if system.
 * @property {string}         actor_display_name   Snapshotted display name at log time.
 * @property {ActorSource}    actor_source         Invocation context (cli, cron, hmac, etc.).
 * @property {string}         destination_site_url URL of the destination site.
 * @property {number[]}       post_ids             IDs of the exported posts.
 * @property {number}         post_count           Number of exported posts.
 */
export interface ExportEvent {
	id: number;
	date: string;
	level: 'info' | 'error';
	event: string;
	actor_user_id: number;
	actor_display_name: string;
	actor_source: ActorSource;
	destination_site_url: string;
	post_ids: number[];
	post_count: number;
}

declare global {
	interface Window {
		safePublishAdminData: AdminData;
	}

	interface HTMLElement {
		dataset: DOMStringMap;
	}
}
