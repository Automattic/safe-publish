/**
 * Type definitions for Compliant Content Publisher plugin
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

export interface ExternalPostsDataViewProps {
	posts: Post[];
}

export interface AdminData {
	ajaxurl: string;
	nonce: string;
	restNonce: string;
	siteUrl: string;
	settingsUrl: string;
	numPosts: number;
	containerId: string;
	postsData: any[]; // Raw posts data from PHP
	strings: {
		loading: string;
		error: string;
		noResults: string;
		title: string;
		lastModified: string;
		link: string;
	};
}

export interface DataViewsField {
	id: string;
	label: string;
	render?: ( args: { item: any } ) => JSX.Element;
	enableSorting?: boolean;
	enableGlobalSearch?: boolean;
	enableHiding?: boolean;
	sort?: ( a: any, b: any, direction: 'asc' | 'desc' ) => number;
}

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

export interface PaginationInfo {
	totalItems: number;
	totalPages: number;
}

// Import History Types
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
