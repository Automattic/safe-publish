/**
 * Utility functions for the Safe Publish plugin.
 *
 * Provides helper functions for date formatting, post validation, status
 * labels, warning rendering, and URL manipulation.
 *
 * @file This file defines utility functions for the Safe Publish plugin.
 */

import { dateI18n, getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

import type { Post, JsonValue, Warning } from './types';

/**
 * Extracts a human-readable error message from an API response.
 *
 * @param {Object}    response         The API response object.
 * @param {boolean}   response.success Always false for error responses.
 * @param {JsonValue} [response.data]  Optional error data from WordPress.
 * @param {string}    [response.error] Optional custom error message.
 * @param {string}    [fallback]       Fallback message if no error found.
 *
 * @return {string} The extracted error message.
 */
export function getErrorMessage(
	response: { success: false; data?: JsonValue; error?: string },
	fallback: string = __( 'An unknown error occurred', 'safe-publish' )
): string {
	if ( typeof response.error === 'string' && response.error ) {
		return response.error;
	}

	// Check WordPress wp_send_json_error data field.
	if ( response.data !== undefined && response.data !== null ) {
		if ( typeof response.data === 'string' ) {
			return response.data;
		}

		// If it's an object with a message property, extract that.
		if (
			typeof response.data === 'object' &&
			! Array.isArray( response.data ) &&
			'message' in response.data &&
			typeof response.data.message === 'string'
		) {
			return response.data.message;
		}

		// For other objects/arrays, serialize to JSON.
		try {
			return JSON.stringify( response.data );
		} catch {
			return String( response.data );
		}
	}

	return fallback;
}

/**
 * Formats a date string with time for display.
 *
 * Renders in the site's configured timezone using the WordPress `date_format`
 * and `time_format` options, matching how WP admin displays dates elsewhere.
 *
 * @param {string} dateString UTC ISO 8601 date string (trailing `Z`).
 *
 * @return {string} Formatted date/time string, or 'Invalid Date' on failure.
 */
export function formatDateTime( dateString: string ): string {
	const date = new Date( dateString );
	if ( isNaN( date.getTime() ) ) {
		return __( 'Invalid Date', 'safe-publish' );
	}

	const { formats } = getSettings();
	return dateI18n( `${ formats.date } ${ formats.time }`, dateString );
}

/**
 * Validates if a post object has required properties.
 *
 * @param {unknown} post Object to validate as a Post.
 *
 * @return {boolean} True if the object is a valid Post, false otherwise.
 */
export function isValidPost( post: unknown ): post is Post {
	if ( typeof post !== 'object' || post === null ) {
		return false;
	}

	const postRecord = post as Record< string, unknown >;
	return (
		typeof postRecord.id === 'number' &&
		typeof postRecord.link === 'string' &&
		typeof postRecord.title === 'string' &&
		typeof postRecord.modified_gmt === 'string'
	);
}

/**
 * Sanitizes posts array, filtering out invalid posts.
 *
 * @param {unknown[]} posts Potential post objects to sanitize.
 *
 * @return {Post[]} Array containing only valid Post objects.
 */
export function sanitizePosts( posts: unknown[] ): Post[] {
	if ( ! Array.isArray( posts ) ) {
		return [];
	}

	return posts.filter( isValidPost );
}

/**
 * Display labels for sync statuses.
 */
export const SYNC_STATUS_LABELS = {
	outdated: __( 'Outdated', 'safe-publish' ),
	upToDate: __( 'Up to date', 'safe-publish' ),
	available: __( 'Available', 'safe-publish' ),
} as const;

/**
 * Display labels for publish statuses.
 */
export const PUBLISH_STATUS_LABELS: Record< string, string > = {
	publish: __( 'Published', 'safe-publish' ),
	draft:   __( 'Draft', 'safe-publish' ),
	pending: __( 'Pending Review', 'safe-publish' ),
	private: __( 'Private', 'safe-publish' ),
	future:  __( 'Scheduled', 'safe-publish' ),
};

/**
 * Renders an import warning as a long-form, user-facing message.
 *
 * @param {Warning} warning Warning to render.
 *
 * @return {string} Localized message text.
 */
export function renderWarningMessage( warning: Warning ): string {
	switch ( warning.type ) {
		case 'author_fallback_applied':
			return warning.fallback_user_id !== null
				? sprintf(
					/* translators: 1: display name, 2: email */
					__(
						'Original author %1$s (%2$s) was not found on this site. Post attributed to the current user.',
						'safe-publish'
					),
					warning.source.display_name,
					warning.source.email
				)
				: sprintf(
					/* translators: %s: email */
					__(
						'Source author %s was not found on this site. Keeping the current author.',
						'safe-publish'
					),
					warning.source.email
				);
		case 'parent_orphaned':
			if ( warning.reason === 'failed_in_batch' && warning.source.parent_title !== null ) {
				return sprintf(
					/* translators: 1: parent post title, 2: parent post ID */
					__(
						'Source parent post "%1$s" (ID %2$d) failed to import earlier in this batch. Imported as top-level.',
						'safe-publish'
					),
					warning.source.parent_title,
					warning.source.parent_id
				);
			}
			return sprintf(
				/* translators: %d: parent post ID */
				__(
					'Source parent post (ID %d) is not on this site. Imported as top-level.',
					'safe-publish'
				),
				warning.source.parent_id
			);
		default: {
			const _exhaustive: never = warning;
			return String( _exhaustive );
		}
	}
}

/**
 * Renders an import warning as a short label for the bulk results modal.
 *
 * Labels are joined inline with commas, so they should be lowercase phrases.
 *
 * @param {Warning} warning Warning to render.
 *
 * @return {string} Localized short label.
 */
export function renderWarningShortLabel( warning: Warning ): string {
	switch ( warning.type ) {
		case 'author_fallback_applied':
			return __( 'author fallback', 'safe-publish' );
		case 'parent_orphaned':
			return __( 'parent orphaned', 'safe-publish' );
		default: {
			const _exhaustive: never = warning;
			return String( _exhaustive );
		}
	}
}

/**
 * Extracts the path from a URL for display.
 *
 * @param {string} url Full URL to extract the path from.
 *
 * @return {string} Path portion of the URL.
 */
export function extractUrlPath( url: string ): string {
	try {
		const urlObj = new URL( url );
		return urlObj.pathname;
	} catch {
		// If URL parsing fails, try to extract path manually.
		const match = url.match( /https?:\/\/[^/]+(.*)/ );
		return match ? match[1] || '/' : url;
	}
}
