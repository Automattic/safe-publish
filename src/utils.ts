/**
 * Utility functions for the Safe Publish plugin.
 *
 * Provides helper functions for date formatting, post validation, searching,
 * sorting, pagination, and URL manipulation.
 *
 * @file This file defines utility functions for the Safe Publish plugin.
 */

import { __ } from '@wordpress/i18n';

import type { Post, JsonValue } from './types';

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
 * @param {string} dateString ISO date string to format.
 *
 * @return {string} Formatted date string, or 'Invalid Date' if parsing fails.
 */
export function formatDate( dateString: string ): string {
	const date = new Date( dateString );
	if ( isNaN( date.getTime() ) ) {
		return __( 'Invalid Date', 'safe-publish' );
	}

	return date.toLocaleDateString();
}

/**
 * Formats a date string with time for display.
 *
 * @param {string} dateString ISO date string to format.
 *
 * @return {string} Formatted date/time string, or 'Invalid Date' on failure.
 */
export function formatDateTime( dateString: string ): string {
	const date = new Date( dateString );
	if ( isNaN( date.getTime() ) ) {
		return __( 'Invalid Date', 'safe-publish' );
	}

	return date.toLocaleString();
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

	const postRecord = post as Record<string, unknown>;
	return (
		typeof postRecord.id === 'number' &&
		typeof postRecord.link === 'string' &&
		typeof postRecord.title === 'string' &&
		typeof postRecord.modified === 'string'
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
 * Searches posts by title.
 *
 * @param {Post[]} posts      Posts to search.
 * @param {string} searchTerm Search term to match against post titles.
 *
 * @return {Post[]} Posts matching the search term.
 */
export function searchPosts( posts: Post[], searchTerm: string ): Post[] {
	if ( ! searchTerm.trim() ) {
		return posts;
	}

	const searchLower = searchTerm.toLowerCase();
	return posts.filter( post => post.title.toLowerCase().includes( searchLower ) );
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
	field: keyof Post,
	direction: 'asc' | 'desc' = 'desc'
): Post[] {
	return [ ...posts ].sort( ( postA, postB ) => {
		/* eslint-disable security/detect-object-injection */
		// TypeScript ensures 'field' is a valid Post key, making this type-safe.
		const aVal = postA[ field ];
		const bVal = postB[ field ];
		/* eslint-enable security/detect-object-injection */

		// Special handling for dates.
		if ( field === 'modified' ) {
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
