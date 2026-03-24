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
 * Represents a post from an external WordPress site.
 *
 * @property {number}                   id               Unique post ID.
 * @property {string}                   link             Permalink URL of the post.
 * @property {string}                   title            Post title.
 * @property {string}                   modified         Last modified date in ISO format.
 * @property {string}                   [content]        Full post content.
 * @property {string}                   [excerpt]        Post excerpt.
 * @property {string}                   [author]         Post author name.
 * @property {string}                   [status]         Post status.
 * @property {number}                   [featured_media] Featured image attachment ID.
 * @property {string}                   [post_type]      Post type slug.
 * @property {JsonObject}               [meta]           Post meta fields.
 * @property {Record<string, string[]>} [terms]          Taxonomy terms assigned to the post.
 */
export interface Post {
	id: number;
	link: string;
	title: string;
	modified: string;
	content?: string;
	excerpt?: string;
	author?: string;
	status?: string;
	featured_media?: number;
	post_type?: string;
	meta?: JsonObject;
	terms?: Record< string, string[] >;
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
 * Response from create draft post operation.
 *
 * @property {number}  post_id          Created/updated post ID.
 * @property {string}  edit_url         URL to edit the post.
 * @property {string}  message          Success message.
 * @property {boolean} [existing]       Whether post already existed.
 * @property {string}  [post_title]     Post title.
 * @property {string}  [confirm_action] Action requiring confirmation.
 */
export interface CreateDraftResponse {
	post_id: number;
	edit_url: string;
	message: string;
	existing?: boolean;
	post_title?: string;
	confirm_action?: string;
}

/**
 * Individual result from bulk import operation.
 *
 * @property {number}  external_id External post ID.
 * @property {string}  title       Post title.
 * @property {boolean} success     Whether import succeeded.
 * @property {number}  [post_id]   Local post ID if successful.
 * @property {string}  [edit_url]  URL to edit the post.
 * @property {string}  [error]     Error message if failed.
 * @property {boolean} [existing]  Whether post already existed.
 */
export interface BulkImportResult {
	external_id: number;
	title: string;
	success: boolean;
	post_id?: number;
	edit_url?: string;
	error?: string;
	existing?: boolean;
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
 * @property {boolean} success         Whether connection succeeded.
 * @property {string}  message         Test result message.
 * @property {number}  [response_time] Response time in milliseconds.
 */
export interface ConnectionTestData {
	success: boolean;
	message: string;
	response_time?: number;
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
 * Rollback session response data.
 *
 * @property {number} deleted_count  Number of posts deleted.
 * @property {number} restored_count Number of posts restored.
 */
export interface RollbackSessionData {
	deleted_count: number;
	restored_count: number;
}

/**
 * Delete session response data.
 *
 * @property {string} message Success message.
 */
export interface DeleteSessionData {
	message: string;
}

/**
 * Session details response data.
 *
 * @property {ImportLog[]} logs Array of import log entries.
 */
export interface SessionDetailsData {
	logs: ImportLog[];
}

/**
 * Rollback item response data.
 *
 * @property {string} action Action performed ('restored' or 'deleted').
 */
export interface RollbackItemData {
	action: 'restored' | 'deleted';
}

/**
 * Props for the ExternalPostsDataView component.
 *
 * @property {Post[]} initialPosts Posts to display on initial load.
 * @property {string} siteUrl      External site URL.
 * @property {number} numberPosts  Number of posts to fetch.
 */
export interface ExternalPostsDataViewProps {
	initialPosts: Post[];
	siteUrl: string;
	numberPosts: number;
}

/**
 * Admin data passed from PHP via wp_add_inline_script.
 *
 * @property {string}    ajaxurl     WordPress AJAX URL.
 * @property {string}    nonce       Security nonce for AJAX requests.
 * @property {string}    restNonce   Security nonce for REST API requests.
 * @property {string}    siteUrl     External site URL.
 * @property {string}    settingsUrl URL to the plugin settings page.
 * @property {number}    numPosts    Number of posts to fetch.
 * @property {string}    containerId Container element ID.
 * @property {JsonArray} postsData   Raw posts data from PHP.
 */
export interface AdminData {
	ajaxurl: string;
	nonce: string;
	restNonce: string;
	siteUrl: string;
	settingsUrl: string;
	numPosts: number;
	containerId: string;
	postsData: JsonArray;
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
 */
export interface DataViewsField<T = Post> {
	id: string;
	label: string;
	render?: ( args: { item: T } ) => JSX.Element;
	enableSorting?: boolean;
	enableGlobalSearch?: boolean;
	enableHiding?: boolean;
	sort?: ( a: T, b: T, direction: 'asc' | 'desc' ) => number;
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
 * Pagination information for DataViews.
 *
 * @property {number} totalItems Total number of items.
 * @property {number} totalPages Total number of pages.
 */
export interface PaginationInfo {
	totalItems: number;
	totalPages: number;
}

/**
 * Represents an import session record.
 *
 * @property {number}  id           Unique session ID.
 * @property {string}  date         Date of the import.
 * @property {string}  user         User who performed the import.
 * @property {number}  total_items  Total items in the session.
 * @property {number}  successful   Number of successful imports.
 * @property {number}  failed       Number of failed imports.
 * @property {number}  updated      Number of updated posts.
 * @property {string}  status       Session status.
 * @property {string}  status_label Human-readable status label.
 * @property {string}  source_url   URL of the external source.
 * @property {boolean} can_rollback Whether the session can be rolled back.
 */
export interface ImportSession {
	id: number;
	date: string;
	user: string;
	total_items: number;
	successful: number;
	failed: number;
	updated: number;
	status: 'in_progress' | 'completed' | 'failed' | 'rolled_back';
	status_label: string;
	source_url: string;
	can_rollback: boolean;
}

/**
 * Represents an individual import log entry.
 *
 * @property {number}  id              Unique log entry ID.
 * @property {string}  title           Title of the imported post.
 * @property {string}  status          Import status.
 * @property {string}  status_label    Human-readable status label.
 * @property {string}  external_id     ID from the external source.
 * @property {number}  [post_id]       Local WordPress post ID.
 * @property {string}  [error]         Error message if failed.
 * @property {boolean} has_changes     Whether changes were detected.
 * @property {string}  [edit_url]      URL to edit the post.
 * @property {boolean} can_rollback    Whether the item can be rolled back.
 * @property {boolean} is_rolled_back  Whether the item has been rolled back.
 * @property {string}  rollback_action Type of rollback action.
 */
export interface ImportLog {
	id: number;
	title: string;
	status: 'success' | 'updated' | 'error';
	status_label: string;
	external_id: string;
	post_id?: number;
	error?: string;
	has_changes: boolean;
	edit_url?: string;
	can_rollback: boolean;
	is_rolled_back: boolean;
	rollback_action: 'delete' | 'restore';
}

declare global {
	interface Window {
		safePublishAdminData: AdminData;
	}

	interface HTMLElement {
		dataset: DOMStringMap;
	}
}
