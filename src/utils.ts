/**
 * Utility functions for the Compliant Content Publisher plugin
 */

import type { Post } from './types';

/**
 * Formats a date string for display
 * @param dateString
 */
export function formatDate( dateString: string ): string {
	const date = new Date( dateString );
	if ( isNaN( date.getTime() ) ) {
		return 'Invalid Date';
	}
	return date.toLocaleDateString();
}

/**
 * Formats a date string with time for display
 * @param dateString
 */
export function formatDateTime( dateString: string ): string {
	const date = new Date( dateString );
	if ( isNaN( date.getTime() ) ) {
		return 'Invalid Date';
	}
	return date.toLocaleString();
}

/**
 * Validates if a post object has required properties
 * @param post
 */
export function isValidPost( post: any ): post is Post {
	return (
		typeof post === 'object' &&
		post !== null &&
		typeof post.id === 'number' &&
		typeof post.link === 'string' &&
		typeof post.title === 'string' &&
		typeof post.modified === 'string'
	);
}

/**
 * Sanitizes posts array, filtering out invalid posts
 * @param posts
 */
export function sanitizePosts( posts: any[] ): Post[] {
	if ( ! Array.isArray( posts ) ) {
		return [];
	}

	return posts.filter( isValidPost );
}

/**
 * Searches posts by title
 * @param posts
 * @param searchTerm
 */
export function searchPosts( posts: Post[], searchTerm: string ): Post[] {
	if ( ! searchTerm.trim() ) {
		return posts;
	}

	const searchLower = searchTerm.toLowerCase();
	return posts.filter( post => post.title.toLowerCase().includes( searchLower ) );
}

/**
 * Sorts posts by a given field
 * @param posts
 * @param field
 * @param direction
 */
export function sortPosts(
	posts: Post[],
	field: keyof Post,
	direction: 'asc' | 'desc' = 'desc'
): Post[] {
	return [ ...posts ].sort( ( a, b ) => {
		const aVal = a[ field ];
		const bVal = b[ field ];

		// Special handling for dates
		if ( field === 'modified' ) {
			const dateA = new Date( aVal as string );
			const dateB = new Date( bVal as string );
			return direction === 'asc'
				? dateA.getTime() - dateB.getTime()
				: dateB.getTime() - dateA.getTime();
		}

		// Default string comparison
		if ( direction === 'asc' ) {
			return String( aVal ).localeCompare( String( bVal ) );
		}
		return String( bVal ).localeCompare( String( aVal ) );
	} );
}

/**
 * Paginates an array of posts
 * @param posts
 * @param page
 * @param perPage
 */
export function paginatePosts( posts: Post[], page: number, perPage: number ): Post[] {
	const startIndex = ( page - 1 ) * perPage;
	const endIndex = startIndex + perPage;
	return posts.slice( startIndex, endIndex );
}

/**
 * Calculates pagination information
 * @param totalItems
 * @param perPage
 */
export function getPaginationInfo( totalItems: number, perPage: number ) {
	return {
		totalItems,
		totalPages: Math.ceil( totalItems / perPage ),
	};
}

/**
 * Extracts the path from a URL for display
 * @param url - The full URL
 * @returns The path portion of the URL
 */
export function extractUrlPath( url: string ): string {
	try {
		const urlObj = new URL( url );
		return urlObj.pathname;
	} catch {
		// If URL parsing fails, try to extract path manually
		const match = url.match( /https?:\/\/[^/]+(.*)/ );
		return match ? match[1] || '/' : url;
	}
}
