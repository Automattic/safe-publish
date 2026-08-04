/**
 * Type definitions for Safe Publish plugin.
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
 */
export type JsonValue = JsonPrimitive | JsonArray | JsonObject;

/**
 * Routing label for the unified Posts listing.
 *
 * Derived from the active-row rule per source_post_id. Up to date and Outdated
 * each map to a chip; rolled-back, locally-deleted, and error-only source posts
 * fold into Available. Failures surface in the Needs attention inbox, not here.
 */
export type LocalState = 'available' | 'up-to-date' | 'outdated';

/**
 * Chip value, including 'all' which the local-state allowlist excludes.
 */
export type ChipState = LocalState | 'all';

/**
 * Per-row entry in the unified Posts listing.
 *
 * Whether the listing was assembled catalog-primary (state=all|available) or
 * local-primary (state=up-to-date|outdated), the shape is the same — the
 * controller normalizes both paths into this row before serializing.
 *
 * @property {number}      id                   Source post ID (or 0 when unknown).
 * @property {number|null} source_post_id       Source post ID; null only for orphan failures.
 * @property {string}      title                Display title (live source title on catalog-primary,
 *                                              snapshot fallback when the source is gone).
 * @property {string}      link                 Source URL; empty when unknown.
 * @property {string}      date_gmt             Source published date (ISO 8601 UTC).
 * @property {string}      modified_gmt         Source modified date (ISO 8601 UTC).
 * @property {string}      post_type            Source post type slug.
 * @property {string}      status               Source post_status; empty when unknown.
 * @property {LocalState}  local_state          Routing label per active-row rule.
 * @property {boolean}     is_imported          True when local_state is 'up-to-date' or 'outdated'.
 * @property {string|null} wp_post_status       Local post's post_status when present, null otherwise.
 * @property {number|null} item_id              Active items-table row id.
 * @property {number|null} post_id              Local post id when the post exists.
 * @property {string|null} import_date_gmt      Active item's import_date_gmt.
 * @property {boolean}     has_previous_content Whether the active item captured pre-update content
 *                                              for rollback restore.
 * @property {string}      edit_url             Local wp-admin edit URL when the post is present.
 */
export interface UnifiedPostRow {
	id: number;
	source_post_id: number | null;
	title: string;
	link: string;
	date_gmt: string;
	modified_gmt: string;
	post_type: string;
	status: string;
	local_state: LocalState;
	is_imported: boolean;
	wp_post_status: string | null;
	item_id: number | null;
	post_id: number | null;
	import_date_gmt: string | null;
	has_previous_content: boolean;
	edit_url: string;
}

/**
 * Envelope returned by safe_publish_list_posts.
 *
 * `focused_state` and `focused_source_post_id` echo the focus_source_id input
 * when the endpoint resolved it; the frontend uses them to swap the active
 * chip + highlight the focused row in the rendered list.
 *
 * `needs_attention_count` is populated only when the request set
 * with_needs_attention_count=1.
 */
export interface PostsResponse {
	items: UnifiedPostRow[];
	has_more: boolean;
	state: ChipState;
	focused_state?: LocalState;
	focused_source_post_id?: number;
	needs_attention_count?: number;
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
 */
export interface SyncStatusEntry {
	status: Exclude< ImportSyncStatus, 'loading' >;
}

/**
 * Envelope returned by safe_publish_sync_status_batch.
 */
export interface SyncStatusBatchResponse {
	statuses: Record< number, SyncStatusEntry >;
}

/**
 * Tracked degradation issue types surfaced on the Needs attention tab.
 */
export type AttentionIssueType =
	| 'unmapped_block_reference'
	| 'unmapped_gallery_reference'
	| 'nav_ref_rewrite_failed'
	| 'parent_orphaned';

/**
 * One open degradation issue, keyed by (affected_post_id, issue_type,
 * target_ref, target_kind). `retryable` is the server's signal that the row's
 * reconciliation can run; `resolvable` is the batched hint that its target is
 * imported, so a Retry would reconcile it now.
 */
export interface AttentionIssue {
	affected_post_id: number;
	issue_type: AttentionIssueType;
	target_ref: number;
	target_kind: 'post' | 'term';
	target_is_reusable_block: boolean;
	severity: 'warning' | 'error';
	source_site_url: string;
	first_detected_gmt: string;
	last_seen_gmt: string;
	affected_title: string;
	affected_edit_url: string;
	retryable: boolean;
	resolvable: boolean;
}

/**
 * A failure row in the Needs attention inbox: an import error, either orphan
 * (no source_post_id) or the latest attempt for a source whose most recent row
 * is still an error. `edit_url` is set only for a failed update whose
 * destination post is still live.
 */
export interface InboxFailure {
	kind: 'failure';
	row_id: string;
	item_id: number;
	source_post_id: number | null;
	title: string;
	error_message: string;
	import_date_gmt: string;
	source_site_url: string;
	edit_url: string;
}

/**
 * A degradation row in the Needs attention inbox — an open attention issue
 * carried through with a discriminant and a stable row id.
 */
export interface InboxDegradation extends AttentionIssue {
	kind: 'degradation';
	row_id: string;
}

/**
 * Discriminated union of Needs attention inbox rows.
 */
export type NeedsAttentionRow = InboxFailure | InboxDegradation;

/**
 * Envelope returned by safe_publish_list_needs_attention.
 */
export interface NeedsAttentionResponse {
	items: NeedsAttentionRow[];
	has_more: boolean;
	needs_attention_count: number;
}

/**
 * Inbox view: Open (active) excludes ignored rows; Ignored shows only them.
 */
export type NeedsAttentionView = 'open' | 'ignored';

/**
 * Envelope returned by safe_publish_set_needs_attention_ignored.
 */
export interface SetIgnoredResponse {
	updated: number;
}

/**
 * Envelope returned by safe_publish_retry_attention_issue.
 *
 * Map the UI off the bounded `outcome`; `detail` is an internal, untranslated
 * diagnostic, not for display.
 */
export interface RetryAttentionIssueResponse {
	resolved: boolean;
	outcome: 'resolved' | 'target_absent' | 'write_failed' | 'unresolved';
	detail: string;
}

/**
 * Envelope returned by safe_publish_bulk_retry_attention_issues: per-outcome
 * counts across the batch. `skipped` is a malformed or unrecognized descriptor.
 */
export interface BulkRetryAttentionResponse {
	resolved: number;
	target_absent: number;
	write_failed: number;
	unresolved: number;
	skipped: number;
}

/**
 * Generic API response wrapper.
 *
 * Discriminated union type for WordPress AJAX/REST API responses.
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
 * Surfaced when a block (e.g. core/navigation-link, core/navigation,
 * core/block) carries a post- or term-ID reference whose source ID could not
 * be resolved on the destination, so the attribute was left at the source
 * value. The admin can fix it by importing the referenced content and retrying.
 */
export interface UnmappedBlockReferenceWarning {
	type: 'unmapped_block_reference';
	kind: 'post' | 'term';
	block: string;
	source_id: number;
}

/**
 * Surfaced when a navigation menu was imported but one or more destination
 * posts that reference it by the source ID could not be updated automatically.
 * The listed posts keep their stale ref until the menu is re-imported, which
 * retries the rewrite.
 */
export interface NavRefRewriteFailedWarning {
	type: 'nav_ref_rewrite_failed';
	failed_post_ids: number[];
}

/**
 * Surfaced when a [gallery] or [playlist] shortcode references a source
 * attachment ID that could not be resolved on the destination (deleted on the
 * source, or private without auth), so the ID was left at the source value.
 */
export interface UnmappedShortcodeReferenceWarning {
	type: 'unmapped_shortcode_reference';
	source_id: number;
}

/**
 * Surfaced when a [gallery] or [playlist] shortcode references another source
 * post by id and that post is not on the destination, so the id was left at the
 * source value. Importing the post and retrying remaps the reference.
 */
export interface UnmappedGalleryReferenceWarning {
	type: 'unmapped_gallery_reference';
	source_id: number;
}

/**
 * Discriminated union of all import warning types.
 */
export type Warning =
	| AuthorFallbackWarning
	| ParentOrphanedWarning
	| UnmappedBlockReferenceWarning
	| NavRefRewriteFailedWarning
	| UnmappedShortcodeReferenceWarning
	| UnmappedGalleryReferenceWarning;

/**
 * Response from create draft post operation.
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
 */
export interface BulkImportResponse {
	total: number;
	successful: number;
	failed: number;
	results: BulkImportResult[];
}

/**
 * Connection test result data.
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
	| 'blocked'
	| 'unreachable'
	| 'url_unset';

/**
 * Auth probe result returned by safe_publish_auth_status.
 */
export interface AuthStatusData {
	status: AuthStatus;
	code?: number;
	message?: string;
}

/**
 * Diff HTML response data.
 */
export interface DiffHtmlData {
	diff_html: string;
}

/**
 * Props for the PostsDataView component.
 */
export interface PostsDataViewProps {
	sourceSiteUrl: string;
	// Reports failures + open degradations after each list fetch, keeping the
	// Manage tab label fresh.
	onNeedsAttentionCountChange?: ( count: number ) => void;
}

/**
 * Admin data passed from PHP via wp_add_inline_script.
 *
 * @property {string}    ajaxurl               WordPress AJAX URL.
 * @property {string}    nonce                 Security nonce for AJAX requests.
 * @property {string}    sourceSiteUrl         Source site URL.
 * @property {string}    settingsUrl           URL to the plugin settings page.
 * @property {string}    homeUrl               Destination home URL for slug detection.
 * @property {string}    containerId           Container element ID.
 * @property {ChipState} [initialState]        Chip state from ?state=... on load.
 * @property {number}    [needsAttentionCount] Failures + open degradations at render.
 */
export interface AdminData {
	ajaxurl: string;
	nonce: string;
	sourceSiteUrl: string;
	settingsUrl: string;
	homeUrl?: string;
	containerId: string;
	initialState?: ChipState;
	needsAttentionCount?: number;
	knownChannels?: string[];
	knownLevels?: string[];
}

/**
 * Field configuration for DataViews columns.
 */
export interface DataViewsField< T = UnifiedPostRow > {
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

/**
 * Represents a single audit log event of any channel.
 */
export interface AuditEvent {
	id: number;
	channel: string;
	level: 'info' | 'warning' | 'error';
	event: string;
	date: string;
	actor_user_id: number;
	actor_display_name: string;
	actor_source: ActorSource;
	data: JsonObject;
}

/**
 * Envelope returned by the audit-events AJAX endpoint.
 */
export interface AuditEventsResponse {
	items: AuditEvent[];
	total: number;
}

declare global {
	interface Window {
		safePublishAdminData: AdminData;
	}

	interface HTMLElement {
		dataset: DOMStringMap;
	}
}
