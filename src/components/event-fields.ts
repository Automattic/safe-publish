/**
 * Pure helpers for rendering audit event columns in DataViews.
 *
 * Centralized so column configs can share the same text between
 * `getValue` (search) and `render` (display), and so the helpers can
 * be unit-tested in isolation from the React tree.
 *
 * @file This file defines audit event column helpers.
 */

import { __, sprintf } from '@wordpress/i18n';

import type { AuditEvent } from '../types';

/**
 * Returns the display label for the user column.
 *
 * Used by both `getValue` and `render` so search matches displayed text.
 *
 * @param {AuditEvent} item Event carrying actor fields.
 *
 * @return {string} Human-readable actor label.
 */
export function getUserLabel( item: AuditEvent ): string {
	if ( item.actor_user_id > 0 ) {
		return item.actor_display_name || sprintf(
			/* translators: %d is the WordPress user ID. */
			__( 'User #%d', 'safe-publish' ),
			item.actor_user_id
		);
	}

	return sprintf(
		/* translators: %s is the actor source (e.g. cli, cron) */
		__( 'System (%s)', 'safe-publish' ),
		item.actor_source
	);
}

/**
 * Human-readable labels for audit event codes, keyed by the Log_Events
 * constant string.
 */
const EVENT_LABELS: Record< string, string > = {
	SOURCE_MEDIA_FETCH_FAILED: __( 'Source media fetch failed', 'safe-publish' ),
	SOURCE_MEDIA_URL_MISSING: __( 'Source media has no URL', 'safe-publish' ),
	INVALID_ATTACHMENT_ID: __( 'Invalid attachment ID', 'safe-publish' ),
	MEDIA_DOWNLOAD_FAILED: __( 'Media download failed', 'safe-publish' ),
	MEDIA_SIDELOAD_FAILED: __( 'Media sideload failed', 'safe-publish' ),
	MEDIA_UNSUPPORTED_FILE_TYPE: __( 'Unsupported media file type', 'safe-publish' ),
	CONTENT_FETCH_FAILED: __( 'Content fetch failed', 'safe-publish' ),
	CONTENT_FETCH_INVALID_RESPONSE: __( 'Content fetch: invalid response', 'safe-publish' ),
	CONTENT_FETCH_RAW_FIELDS_MISSING: __( 'Content fetch: raw fields missing', 'safe-publish' ),
	SECRET_NOT_CONFIGURED: __( 'Shared secret not configured', 'safe-publish' ),
	TIMESTAMP_EXPIRED: __( 'Request timestamp expired', 'safe-publish' ),
	CONTENT_HASH_MISSING: __( 'Content hash header missing', 'safe-publish' ),
	CONTENT_HASH_MISMATCH: __( 'Content hash mismatch', 'safe-publish' ),
	CONNECTED_URL_NOT_CONFIGURED: __( 'Connected site URL not configured', 'safe-publish' ),
	SITE_URL_HEADER_MISSING: __( 'Site URL header missing', 'safe-publish' ),
	SITE_URL_MISMATCH: __( 'Site URL mismatch', 'safe-publish' ),
	SIGNATURE_INVALID: __( 'Invalid signature', 'safe-publish' ),
	REQUEST_AUTHENTICATED: __( 'Request authenticated', 'safe-publish' ),
	REQUEST_ACTION_UNRECOGNIZED: __( 'Unrecognized request action', 'safe-publish' ),
	AUTHENTICATED_CONTEXT_INSTALLED: __( 'Authenticated context installed', 'safe-publish' ),
	PERMISSION_CHECK_INTERCEPTED: __( 'Permission check intercepted', 'safe-publish' ),
	META_CAP_OVERRIDDEN: __( 'Meta capability overridden', 'safe-publish' ),
	PERMISSION_OVERRIDE_APPLIED: __( 'Permission override applied', 'safe-publish' ),
	PERMISSION_CALLBACK_OVERRIDDEN: __( 'Permission callback overridden', 'safe-publish' ),
	COLLECTION_PARAMS_OVERRIDDEN: __( 'Collection params overridden', 'safe-publish' ),
	CONTEXT_ERROR_OVERRIDDEN: __( 'Context error overridden', 'safe-publish' ),
	EDIT_CONTEXT_ALLOWED: __( 'Edit context allowed', 'safe-publish' ),
	PERMISSION_ERROR_INTERCEPTED: __( 'Permission error intercepted', 'safe-publish' ),
	CONTENT_EXPORTED: __( 'Content exported', 'safe-publish' ),
	EXPORT_REQUEST_ERROR: __( 'Export request error', 'safe-publish' ),
	EXPORT_RESPONSE_BAD_STATUS: __( 'Export response: bad status', 'safe-publish' ),
	DISPATCH_REQUEST_ERROR: __( 'Dispatch request error', 'safe-publish' ),
	DISPATCH_RESPONSE_BAD_STATUS: __( 'Dispatch response: bad status', 'safe-publish' ),
	ITEM_ROLLED_BACK: __( 'Item rolled back', 'safe-publish' ),
	ITEM_ROLLED_BACK_WITH_OMISSIONS: __( 'Item rolled back with omissions', 'safe-publish' ),
	ITEM_ALREADY_ROLLED_BACK: __( 'Item already rolled back', 'safe-publish' ),
	ITEM_ROLLBACK_FAILED: __( 'Item rollback failed', 'safe-publish' ),
	SESSION_DELETED: __( 'Session deleted', 'safe-publish' ),
	SESSION_DELETE_FAILED: __( 'Session delete failed', 'safe-publish' ),
	IMPORT_ITEM_FAILED: __( 'Import item failed', 'safe-publish' ),
	CONNECTED_SITE_URL_CHANGED: __( 'Connected site URL changed', 'safe-publish' ),
	BASIC_AUTH_USERNAME_CHANGED: __( 'Basic Auth username changed', 'safe-publish' ),
	BASIC_AUTH_PASSWORD_CHANGED: __( 'Basic Auth password changed', 'safe-publish' ),
	SYNC_MODE_CHANGED: __( 'Sync mode changed', 'safe-publish' ),
	RECONCILE_RESOLVED: __( 'Reconciliation resolved', 'safe-publish' ),
	RECONCILE_UNRESOLVED: __( 'Reconciliation unresolved', 'safe-publish' ),
	RECONCILE_TARGET_ABSENT: __( 'Reconciliation target absent', 'safe-publish' ),
	RECONCILE_FAILED: __( 'Reconciliation failed', 'safe-publish' ),
};

/**
 * Returns the display label for an audit event code.
 *
 * @param {string} event Event code (a Log_Events constant).
 *
 * @return {string} Human-readable label, or the raw code when unmapped.
 */
export function getEventLabel( event: string ): string {
	// eslint-disable-next-line security/detect-object-injection
	return EVENT_LABELS[ event ] ?? event;
}

/**
 * Human-readable labels for audit channels, keyed by the channel slug.
 */
const CHANNEL_LABELS: Record< string, string > = {
	auth: __( 'Auth', 'safe-publish' ),
	content: __( 'Content', 'safe-publish' ),
	dispatch: __( 'Dispatch', 'safe-publish' ),
	export: __( 'Export', 'safe-publish' ),
	import: __( 'Import', 'safe-publish' ),
	media: __( 'Media', 'safe-publish' ),
	reconcile: __( 'Reconcile', 'safe-publish' ),
	settings: __( 'Settings', 'safe-publish' ),
};

/**
 * Returns the display label for an audit channel slug.
 *
 * @param {string} channel Channel slug (e.g. auth, media).
 *
 * @return {string} Human-readable label, or the raw slug when unmapped.
 */
export function getChannelLabel( channel: string ): string {
	// eslint-disable-next-line security/detect-object-injection
	return CHANNEL_LABELS[ channel ] ?? channel;
}
