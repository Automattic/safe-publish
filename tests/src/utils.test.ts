/**
 * Tests for utility functions
 */
import { describe, expect, it } from 'vitest';
import {
	formatDate,
	formatDateTime,
	isValidPost,
	sanitizePosts,
	searchPosts,
	sortPosts,
	paginatePosts,
	getPaginationInfo,
	extractUrlPath,
} from '@/utils';
import type { Post } from '@/types';

describe( 'formatDate', () => {
	it( 'should format a valid date string', () => {
		const result = formatDate( '2024-03-15T10:30:00' );
		expect( result ).toContain( '2024' );
	} );

	it( 'should return "Invalid Date" for invalid date string', () => {
		expect( formatDate( 'not-a-date' ) ).toBe( 'Invalid Date' );
	} );

	it( 'should return "Invalid Date" for empty string', () => {
		expect( formatDate( '' ) ).toBe( 'Invalid Date' );
	} );
} );

describe( 'formatDateTime', () => {
	it( 'should format a valid date string with time', () => {
		const result = formatDateTime( '2024-03-15T10:30:00' );
		expect( result ).toContain( '2024' );
	} );

	it( 'should return "Invalid Date" for invalid date string', () => {
		expect( formatDateTime( 'not-a-date' ) ).toBe( 'Invalid Date' );
	} );
} );

describe( 'isValidPost', () => {
	it( 'should return true for valid post object', () => {
		const validPost: Post = {
			id: 1,
			link: 'https://example.com/post',
			title: 'Test Post',
			modified: '2024-03-15T10:30:00',
		};
		expect( isValidPost( validPost ) ).toBe( true );
	} );

	it( 'should return false for post missing id', () => {
		const invalidPost = {
			link: 'https://example.com/post',
			title: 'Test Post',
			modified: '2024-03-15T10:30:00',
		};
		expect( isValidPost( invalidPost ) ).toBe( false );
	} );

	it( 'should return false for post missing link', () => {
		const invalidPost = {
			id: 1,
			title: 'Test Post',
			modified: '2024-03-15T10:30:00',
		};
		expect( isValidPost( invalidPost ) ).toBe( false );
	} );

	it( 'should return false for post missing title', () => {
		const invalidPost = {
			id: 1,
			link: 'https://example.com/post',
			modified: '2024-03-15T10:30:00',
		};
		expect( isValidPost( invalidPost ) ).toBe( false );
	} );

	it( 'should return false for post missing modified', () => {
		const invalidPost = {
			id: 1,
			link: 'https://example.com/post',
			title: 'Test Post',
		};
		expect( isValidPost( invalidPost ) ).toBe( false );
	} );

	it( 'should return false for null', () => {
		expect( isValidPost( null ) ).toBe( false );
	} );

	it( 'should return false for undefined', () => {
		expect( isValidPost( undefined ) ).toBe( false );
	} );

	it( 'should return false for non-object types', () => {
		expect( isValidPost( 'string' ) ).toBe( false );
		expect( isValidPost( 123 ) ).toBe( false );
		expect( isValidPost( true ) ).toBe( false );
	} );
} );

describe( 'sanitizePosts', () => {
	it( 'should filter out invalid posts', () => {
		const posts = [
			{ id: 1, link: 'https://example.com/1', title: 'Post 1', modified: '2024-03-15' },
			{ id: 2, link: 'https://example.com/2', title: 'Post 2' }, // Missing modified.
			{ id: 3, link: 'https://example.com/3', title: 'Post 3', modified: '2024-03-16' },
			null,
			{ title: 'Invalid' }, // Missing required fields.
		];

		const result = sanitizePosts( posts as any );
		expect( result ).toHaveLength( 2 );
		expect( result[ 0 ].id ).toBe( 1 );
		expect( result[ 1 ].id ).toBe( 3 );
	} );

	it( 'should return empty array for non-array input', () => {
		expect( sanitizePosts( null as any ) ).toEqual( [] );
		expect( sanitizePosts( undefined as any ) ).toEqual( [] );
		expect( sanitizePosts( {} as any ) ).toEqual( [] );
		expect( sanitizePosts( 'not-array' as any ) ).toEqual( [] );
	} );

	it( 'should return empty array for empty array', () => {
		expect( sanitizePosts( [] ) ).toEqual( [] );
	} );
} );

describe( 'searchPosts', () => {
	const posts: Post[] = [
		{ id: 1, link: 'https://example.com/1', title: 'React Tutorial', modified: '2024-03-15' },
		{ id: 2, link: 'https://example.com/2', title: 'TypeScript Guide', modified: '2024-03-16' },
		{ id: 3, link: 'https://example.com/3', title: 'React Testing', modified: '2024-03-17' },
	];

	it( 'should return all posts for empty search term', () => {
		expect( searchPosts( posts, '' ) ).toEqual( posts );
		expect( searchPosts( posts, '   ' ) ).toEqual( posts );
	} );

	it( 'should filter posts by title (case-insensitive)', () => {
		const result = searchPosts( posts, 'react' );
		expect( result ).toHaveLength( 2 );
		expect( result[ 0 ].title ).toContain( 'React' );
		expect( result[ 1 ].title ).toContain( 'React' );
	} );

	it( 'should handle uppercase search term', () => {
		const result = searchPosts( posts, 'TYPESCRIPT' );
		expect( result ).toHaveLength( 1 );
		expect( result[ 0 ].title ).toContain( 'TypeScript' );
	} );

	it( 'should return empty array for no matches', () => {
		expect( searchPosts( posts, 'nonexistent' ) ).toEqual( [] );
	} );
} );

describe( 'sortPosts', () => {
	const posts: Post[] = [
		{ id: 2, link: 'https://example.com/2', title: 'Beta Post', modified: '2024-03-15' },
		{ id: 1, link: 'https://example.com/1', title: 'Alpha Post', modified: '2024-03-17' },
		{ id: 3, link: 'https://example.com/3', title: 'Gamma Post', modified: '2024-03-16' },
	];

	it( 'should sort posts by id ascending', () => {
		const result = sortPosts( posts, 'id', 'asc' );
		expect( result[ 0 ].id ).toBe( 1 );
		expect( result[ 1 ].id ).toBe( 2 );
		expect( result[ 2 ].id ).toBe( 3 );
	} );

	it( 'should sort posts by id descending', () => {
		const result = sortPosts( posts, 'id', 'desc' );
		expect( result[ 0 ].id ).toBe( 3 );
		expect( result[ 1 ].id ).toBe( 2 );
		expect( result[ 2 ].id ).toBe( 1 );
	} );

	it( 'should sort posts by title ascending', () => {
		const result = sortPosts( posts, 'title', 'asc' );
		expect( result[ 0 ].title ).toBe( 'Alpha Post' );
		expect( result[ 1 ].title ).toBe( 'Beta Post' );
		expect( result[ 2 ].title ).toBe( 'Gamma Post' );
	} );

	it( 'should sort posts by modified date descending (default)', () => {
		const result = sortPosts( posts, 'modified' );
		expect( result[ 0 ].modified ).toBe( '2024-03-17' );
		expect( result[ 1 ].modified ).toBe( '2024-03-16' );
		expect( result[ 2 ].modified ).toBe( '2024-03-15' );
	} );

	it( 'should sort posts by modified date ascending', () => {
		const result = sortPosts( posts, 'modified', 'asc' );
		expect( result[ 0 ].modified ).toBe( '2024-03-15' );
		expect( result[ 1 ].modified ).toBe( '2024-03-16' );
		expect( result[ 2 ].modified ).toBe( '2024-03-17' );
	} );

	it( 'should not mutate original array', () => {
		const original = [ ...posts ];
		sortPosts( posts, 'id', 'asc' );
		expect( posts ).toEqual( original );
	} );
} );

describe( 'paginatePosts', () => {
	const posts: Post[] = Array.from( { length: 25 }, ( _, i ) => ( {
		id: i + 1,
		link: `https://example.com/${ i + 1 }`,
		title: `Post ${ i + 1 }`,
		modified: '2024-03-15',
	} ) );

	it( 'should return first page of posts', () => {
		const result = paginatePosts( posts, 1, 10 );
		expect( result ).toHaveLength( 10 );
		expect( result[ 0 ].id ).toBe( 1 );
		expect( result[ 9 ].id ).toBe( 10 );
	} );

	it( 'should return second page of posts', () => {
		const result = paginatePosts( posts, 2, 10 );
		expect( result ).toHaveLength( 10 );
		expect( result[ 0 ].id ).toBe( 11 );
		expect( result[ 9 ].id ).toBe( 20 );
	} );

	it( 'should return last page with remaining posts', () => {
		const result = paginatePosts( posts, 3, 10 );
		expect( result ).toHaveLength( 5 );
		expect( result[ 0 ].id ).toBe( 21 );
		expect( result[ 4 ].id ).toBe( 25 );
	} );

	it( 'should return empty array for page beyond available data', () => {
		const result = paginatePosts( posts, 10, 10 );
		expect( result ).toEqual( [] );
	} );

	it( 'should handle different page sizes', () => {
		const result = paginatePosts( posts, 1, 5 );
		expect( result ).toHaveLength( 5 );
	} );
} );

describe( 'getPaginationInfo', () => {
	it( 'should calculate pagination info correctly', () => {
		const result = getPaginationInfo( 25, 10 );
		expect( result.totalItems ).toBe( 25 );
		expect( result.totalPages ).toBe( 3 );
	} );

	it( 'should handle exact division', () => {
		const result = getPaginationInfo( 30, 10 );
		expect( result.totalItems ).toBe( 30 );
		expect( result.totalPages ).toBe( 3 );
	} );

	it( 'should handle single page', () => {
		const result = getPaginationInfo( 5, 10 );
		expect( result.totalItems ).toBe( 5 );
		expect( result.totalPages ).toBe( 1 );
	} );

	it( 'should handle zero items', () => {
		const result = getPaginationInfo( 0, 10 );
		expect( result.totalItems ).toBe( 0 );
		expect( result.totalPages ).toBe( 0 );
	} );
} );

describe( 'extractUrlPath', () => {
	it( 'should extract path from valid URL', () => {
		expect( extractUrlPath( 'https://example.com/blog/post-1' ) ).toBe( '/blog/post-1' );
	} );

	it( 'should extract path with query string', () => {
		expect( extractUrlPath( 'https://example.com/page?id=123' ) ).toBe( '/page' );
	} );

	it( 'should return "/" for domain only URL', () => {
		expect( extractUrlPath( 'https://example.com' ) ).toBe( '/' );
	} );

	it( 'should handle URL with port', () => {
		expect( extractUrlPath( 'https://example.com:8080/path' ) ).toBe( '/path' );
	} );

	it( 'should handle invalid URL gracefully', () => {
		const result = extractUrlPath( 'not-a-url' );
		expect( result ).toBe( 'not-a-url' );
	} );

	it( 'should handle URL with hash', () => {
		expect( extractUrlPath( 'https://example.com/page#section' ) ).toBe( '/page' );
	} );
} );
