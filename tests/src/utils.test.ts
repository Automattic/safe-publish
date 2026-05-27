/**
 * Tests for utility functions
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { getSettings, setSettings } from '@wordpress/date';
import {
	formatDateTime,
	isValidPost,
	sanitizePosts,
	extractUrlPath,
	renderWarningMessage,
	renderWarningShortLabel,
} from '@/utils';
import type { AuthorFallbackWarning, ParentOrphanedWarning, Post } from '@/types';

// Pin WP date settings so format/timezone-sensitive tests are deterministic.
let originalDateSettings: ReturnType< typeof getSettings >;

beforeEach( () => {
	originalDateSettings = getSettings();
	setSettings( {
		...originalDateSettings,
		formats: {
			date:                'F j, Y',
			time:                'g:i a',
			datetime:            'F j, Y g:i a',
			datetimeAbbreviated: 'M j, Y g:i a',
		},
		timezone: {
			offset:          -4,
			offsetFormatted: '-4',
			string:          'America/New_York',
			abbr:            'EDT',
		},
	} );
} );

afterEach( () => {
	setSettings( originalDateSettings );
} );

describe( 'formatDateTime', () => {
	it( 'should render a Z-marked timestamp with full date and time', () => {
		// ARRANGE: Site set to America/New_York, format 'F j, Y g:i a'.
		// ACT: format a mid-day UTC timestamp (10:30 UTC -> 06:30 EDT).
		const result = formatDateTime( '2024-07-15T10:30:00Z' );
		// ASSERT: full date and time, EDT-shifted.
		expect( result ).toBe( 'July 15, 2024 6:30 am' );
	} );

	it( 'should pick up changes to the WP date and time formats', () => {
		// ARRANGE: switch to a distinctive format pair.
		setSettings( {
			...getSettings(),
			formats: {
				...getSettings().formats,
				date: 'Y-m-d',
				time: 'H:i',
			},
		} );
		// ACT: format with the new format pair active.
		const result = formatDateTime( '2024-07-15T10:30:00Z' );
		// ASSERT: ISO-style render in site timezone (06:30 EDT).
		expect( result ).toBe( '2024-07-15 06:30' );
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
			modified_gmt: '2024-03-15T10:30:00',
		};
		expect( isValidPost( validPost ) ).toBe( true );
	} );

	it( 'should return false for post missing id', () => {
		const invalidPost = {
			link: 'https://example.com/post',
			title: 'Test Post',
			modified_gmt: '2024-03-15T10:30:00',
		};
		expect( isValidPost( invalidPost ) ).toBe( false );
	} );

	it( 'should return false for post missing link', () => {
		const invalidPost = {
			id: 1,
			title: 'Test Post',
			modified_gmt: '2024-03-15T10:30:00',
		};
		expect( isValidPost( invalidPost ) ).toBe( false );
	} );

	it( 'should return false for post missing title', () => {
		const invalidPost = {
			id: 1,
			link: 'https://example.com/post',
			modified_gmt: '2024-03-15T10:30:00',
		};
		expect( isValidPost( invalidPost ) ).toBe( false );
	} );

	it( 'should return false for post missing modified_gmt', () => {
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
			{ id: 1, link: 'https://example.com/1', title: 'Post 1', modified_gmt: '2024-03-15' },
			{ id: 2, link: 'https://example.com/2', title: 'Post 2' }, // Missing modified_gmt.
			{ id: 3, link: 'https://example.com/3', title: 'Post 3', modified_gmt: '2024-03-16' },
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

describe( 'renderWarningMessage', () => {
	it( 'should render the "attributed to importer" message when the author fallback applies on insert', () => {
		// ARRANGE: New-post author fallback warning (non-null fallback_user_id).
		const warning: AuthorFallbackWarning = {
			type: 'author_fallback_applied',
			source: {
				email: 'orphan@example.com',
				login: 'orphan',
				display_name: 'Orphan',
			},
			fallback_user_id: 42,
		};
		// ACT: render the message.
		const message = renderWarningMessage( warning );
		// ASSERT: insert wording includes the source display name and email.
		expect( message ).toContain( 'Orphan' );
		expect( message ).toContain( 'orphan@example.com' );
		expect( message ).toContain( 'attributed to the current user' );
	} );

	it( 'should render the "keeping current author" message when the author fallback applies on update', () => {
		// ARRANGE: Update-path author fallback warning (null fallback_user_id).
		const warning: AuthorFallbackWarning = {
			type: 'author_fallback_applied',
			source: {
				email: 'gone@example.com',
				login: 'gone',
				display_name: 'Gone',
			},
			fallback_user_id: null,
		};
		// ACT: render the message.
		const message = renderWarningMessage( warning );
		// ASSERT: update wording mentions the email and the kept author.
		expect( message ).toContain( 'gone@example.com' );
		expect( message ).toContain( 'Keeping the current author' );
	} );

	it( 'should render the "not on this site" message for parent_orphaned when never imported', () => {
		// ARRANGE: parent never imported on destination.
		const warning: ParentOrphanedWarning = {
			type: 'parent_orphaned',
			source: { parent_id: 42, parent_title: null },
			reason: 'not_imported',
		};
		// ACT: render the message.
		const message = renderWarningMessage( warning );
		// ASSERT: includes the parent ID and "Imported as top-level" hint.
		expect( message ).toContain( '42' );
		expect( message ).toContain( 'is not on this site' );
		expect( message ).toContain( 'Imported as top-level' );
	} );

	it( 'should render the "failed earlier" message for parent_orphaned in a batch', () => {
		// ARRANGE: parent was part of the bulk batch but did not succeed.
		const warning: ParentOrphanedWarning = {
			type: 'parent_orphaned',
			source: { parent_id: 99, parent_title: 'Pending Parent' },
			reason: 'failed_in_batch',
		};
		// ACT: render the message.
		const message = renderWarningMessage( warning );
		// ASSERT: includes parent title, ID, and the failure phrasing.
		expect( message ).toContain( 'Pending Parent' );
		expect( message ).toContain( '99' );
		expect( message ).toContain( 'failed to import earlier' );
	} );
} );

describe( 'renderWarningShortLabel', () => {
	it( 'should return "author fallback" for author_fallback_applied', () => {
		// ARRANGE: any author fallback warning.
		const warning: AuthorFallbackWarning = {
			type: 'author_fallback_applied',
			source: { email: '', login: '', display_name: '' },
			fallback_user_id: null,
		};
		// ACT: render the short label.
		const label = renderWarningShortLabel( warning );
		// ASSERT: short label is the comma-joinable string used in the bulk modal.
		expect( label ).toBe( 'author fallback' );
	} );

	it( 'should return "parent orphaned" for parent_orphaned', () => {
		// ARRANGE: any parent_orphaned warning.
		const warning: ParentOrphanedWarning = {
			type: 'parent_orphaned',
			source: { parent_id: 1, parent_title: null },
			reason: 'not_imported',
		};
		// ACT: render the short label.
		const label = renderWarningShortLabel( warning );
		// ASSERT: short label is the comma-joinable string used in the bulk modal.
		expect( label ).toBe( 'parent orphaned' );
	} );
} );
