/**
 * Utility functions for the Safe Publish plugin.
 *
 * Provides helper functions for date formatting, post validation, searching,
 * sorting, pagination, and URL manipulation.
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
 * Formats a date string for display.
 *
 * Renders in the site's configured timezone using the WordPress `date_format`
 * option, matching how WP admin displays dates elsewhere.
 *
 * @param {string} dateString UTC ISO 8601 date string (trailing `Z`).
 *
 * @return {string} Formatted date string, or 'Invalid Date' if parsing fails.
 */
export function formatDate( dateString: string ): string {
	const date = new Date( dateString );
	if ( isNaN( date.getTime() ) ) {
		return __( 'Invalid Date', 'safe-publish' );
	}

	return dateI18n( getSettings().formats.date, dateString );
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
 *
 * Used by both rendering and search. Search helpers derive their values by
 * lowercasing these.
 */
export const SYNC_STATUS_LABELS = {
	outdated: __( 'Outdated', 'safe-publish' ),
	upToDate: __( 'Up to date', 'safe-publish' ),
	available: __( 'Available', 'safe-publish' ),
} as const;

/**
 * Display labels for publish statuses.
 *
 * Used by both rendering and search. Search helpers derive their values by
 * lowercasing these.
 */
export const PUBLISH_STATUS_LABELS: Record< string, string > = {
	publish: __( 'Published', 'safe-publish' ),
	draft:   __( 'Draft', 'safe-publish' ),
	pending: __( 'Pending Review', 'safe-publish' ),
	private: __( 'Private', 'safe-publish' ),
	future:  __( 'Scheduled', 'safe-publish' ),
};

/**
 * Returns the human-readable sync status text for a post.
 *
 * @param {Post} post Post to get sync status text for.
 *
 * @return {string} Sync status text.
 */
function getSyncStatusText( post: Post ): string {
	if ( post.is_imported && post.has_update ) {
		return SYNC_STATUS_LABELS.outdated.toLowerCase();
	}
	if ( post.is_imported ) {
		return SYNC_STATUS_LABELS.upToDate.toLowerCase();
	}
	return SYNC_STATUS_LABELS.available.toLowerCase();
}

/**
 * Returns the human-readable publish status text for a post.
 *
 * @param {Post} post Post to get publish status text for.
 *
 * @return {string} Publish status text.
 */
function getPublishStatusText( post: Post ): string {
	if ( ! post.is_imported || ! post.local_status ) {
		return '';
	}
	return ( PUBLISH_STATUS_LABELS[ post.local_status ] ?? post.local_status ).toLowerCase();
}

/**
 * Searches posts by title, post type, permalink, sync status, and publish status.
 *
 * @param {Post[]} posts      Posts to search.
 * @param {string} searchTerm Search term to match against post fields.
 *
 * @return {Post[]} Posts matching the search term.
 */
export function searchPosts( posts: Post[], searchTerm: string ): Post[] {
	if ( ! searchTerm.trim() ) {
		return posts;
	}

	const searchLower = searchTerm.toLowerCase();
	return posts.filter( post =>
		post.title.toLowerCase().includes( searchLower ) ||
		( post.post_type ?? '' ).toLowerCase().includes( searchLower ) ||
		( post.link ?? '' ).toLowerCase().includes( searchLower ) ||
		getSyncStatusText( post ).includes( searchLower ) ||
		getPublishStatusText( post ).includes( searchLower ) ||
		post.modified_gmt.toLowerCase().includes( searchLower ) ||
		formatDate( post.modified_gmt ).toLowerCase().includes( searchLower )
	);
}

/**
 * Sorts posts by a given field.
 *
 * @param {Post[]}         posts       Posts to sort.
 * @param {keyof Post}     field       Field to sort by.
 * @param {'asc' | 'desc'} [direction] Sort direction. Default 'desc'.
 *
 * @return {Post[]} New array of posts sorted by the specified field.
 */
export function sortPosts(
	posts: Post[],
	field: keyof Post | 'sync_status',
	direction: 'asc' | 'desc' = 'desc'
): Post[] {
	// Status sort: Available (0) < Outdated (1) < Up to date (2) — alphabetical order.
	const statusOrder = ( item: Post ): number => {
		if ( item.is_imported && item.has_update ) {
			return 1;
		}

		if ( item.is_imported ) {
			return 2;
		}

		return 0;
	};

	return [ ...posts ].sort( ( postA, postB ) => {
		if ( field === 'sync_status' ) {
			const diff = statusOrder( postA ) - statusOrder( postB );
			return direction === 'asc' ? diff : -diff;
		}

		/* eslint-disable security/detect-object-injection */
		// TypeScript ensures 'field' is a valid Post key, making this type-safe.
		const aVal = postA[ field ];
		const bVal = postB[ field ];
		/* eslint-enable security/detect-object-injection */

		// Special handling for dates.
		if ( field === 'modified_gmt' ) {
			const dateA = new Date( aVal as string );
			const dateB = new Date( bVal as string );
			return direction === 'asc'
				? dateA.getTime() - dateB.getTime()
				: dateB.getTime() - dateA.getTime();
		}

		// Default string comparison.
		if ( direction === 'asc' ) {
			return String( aVal ).localeCompare( String( bVal ) );
		}
		return String( bVal ).localeCompare( String( aVal ) );
	} );
}

/**
 * Paginates an array of posts.
 *
 * @param {Post[]} posts   Posts to paginate.
 * @param {number} page    Current page number (1-indexed).
 * @param {number} perPage Number of posts per page.
 *
 * @return {Post[]} Slice of posts for the specified page.
 */
export function paginatePosts( posts: Post[], page: number, perPage: number ): Post[] {
	const startIndex = ( page - 1 ) * perPage;
	const endIndex = startIndex + perPage;
	return posts.slice( startIndex, endIndex );
}

/**
 * Calculates pagination information.
 *
 * @param {number} totalItems Total number of items.
 * @param {number} perPage    Number of items per page.
 *
 * @return {Object} Object containing totalItems and totalPages.
 */
export function getPaginationInfo( totalItems: number, perPage: number ) {
	return {
		totalItems,
		totalPages: Math.ceil( totalItems / perPage ),
	};
}

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
		default: {
			const _exhaustive: never = warning.type;
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
		default: {
			const _exhaustive: never = warning.type;
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
