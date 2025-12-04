/**
 * Type definitions for Compliant Content Publisher plugin.
 *
 * Contains all TypeScript interfaces and type definitions used throughout the
 * plugin's frontend components.
 *
 * @file This file defines the TypeScript types for the CCP plugin.
 */

/**
 * Represents a post from an external WordPress site.
 *
 * @property {number}      id               Unique post ID.
 * @property {string}      link             Permalink URL of the post.
 * @property {string}      title            Post title.
 * @property {string}      modified         Last modified date in ISO format.
 * @property {string}      [content]        Full post content.
 * @property {string}      [excerpt]        Post excerpt.
 * @property {string}      [author]         Post author name.
 * @property {string}      [status]         Post status.
 * @property {number}      [featured_media] Featured image attachment ID.
 * @property {string}      [post_type]      Post type slug.
 * @property {Array<any>}  [meta]           Post meta fields.
 * @property {Array<any>}  [terms]          Taxonomy terms assigned to the post.
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
	meta?: Array<any>;
	terms?: Array<any>;
}

/**
 * Props for the ExternalPostsDataView component.
 *
 * @property {Post[]} posts Posts to display in DataViews.
 */
export interface ExternalPostsDataViewProps {
	posts: Post[];
}

/**
 * Admin data passed from PHP via wp_localize_script.
 *
 * @property {string}   ajaxurl     WordPress AJAX URL.
 * @property {string}   nonce       Security nonce for AJAX requests.
 * @property {string}   restNonce   Security nonce for REST API requests.
 * @property {string}   siteUrl     External site URL.
 * @property {string}   settingsUrl URL to the plugin settings page.
 * @property {number}   numPosts    Number of posts to fetch.
 * @property {string}   containerId Container element ID.
 * @property {any[]}    postsData   Raw posts data from PHP.
 * @property {Object}   strings     Localized UI strings.
 */
export interface AdminData {
	ajaxurl: string;
	nonce: string;
	restNonce: string;
	siteUrl: string;
	settingsUrl: string;
	numPosts: number;
	containerId: string;
	postsData: any[];
	strings: {
		loading: string;
		error: string;
		noResults: string;
		title: string;
		lastModified: string;
		link: string;
	};
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
export interface DataViewsField {
	id: string;
	label: string;
	render?: ( args: { item: any } ) => JSX.Element;
	enableSorting?: boolean;
	enableGlobalSearch?: boolean;
	enableHiding?: boolean;
	sort?: ( a: any, b: any, direction: 'asc' | 'desc' ) => number;
}

/**
 * State object for DataViews component.
 *
 * @property {'table'|'grid'|'list'} type             Current view type.
 * @property {number}                perPage          Items per page.
 * @property {number}                page             Current page number.
 * @property {Object}                sort             Sort configuration.
 * @property {string}                search           Current search term.
 * @property {any[]}                 filters          Active filters.
 * @property {string[]}              hiddenFields     Hidden field IDs.
 * @property {Object}                layout           Layout configuration.
 * @property {string[]}              fields           Visible field IDs.
 * @property {string}                [titleField]     Title field ID.
 * @property {string}                [descriptionField] Description field ID.
 * @property {string}                [mediaField]     Media field ID.
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
	filters: any[];
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
		ccpAdminData: AdminData;
		ccpRefreshPosts: ( postType?: string ) => void;
	}

	interface HTMLElement {
		dataset: DOMStringMap;
	}
}
