/**
 * Tests for the shared filter toolbar primitives.
 */
import { describe, expect, it } from 'vitest';

import {
	detectSlugFromInput,
	formatDateRangeLabel,
	toCalendarDay,
} from '@/components/filter-controls';

describe( 'detectSlugFromInput', () => {
	const SOURCE_URL = 'https://source.example.com';
	const DEST_URL = 'https://destination.example.com';

	it( 'should return null for a plain title search', () => {
		// ARRANGE + ACT: input is plain text, not a URL.
		const result = detectSlugFromInput( 'quarterly report', [ SOURCE_URL ] );

		// ASSERT: no slug detected.
		expect( result ).toBeNull();
	} );

	it( 'should extract the slug from a full URL matching the validation host', () => {
		// ARRANGE + ACT: matching host with a slug at the path's tail.
		const result = detectSlugFromInput(
			'https://source.example.com/2026/03/my-post/',
			[ SOURCE_URL ]
		);

		// ASSERT: returns the trailing slug.
		expect( result ).toBe( 'my-post' );
	} );

	it( 'should accept any URL whose host matches one of several validation URLs', () => {
		// ARRANGE + ACT: destination URL paste with both source + destination
		// supplied for validation.
		const result = detectSlugFromInput(
			'https://destination.example.com/2026/03/my-post/',
			[ SOURCE_URL, DEST_URL ]
		);

		// ASSERT: destination host matches, slug returned.
		expect( result ).toBe( 'my-post' );
	} );

	it( 'should return null for a full URL on a different host', () => {
		// ARRANGE + ACT: URL host differs from every validation URL host.
		const result = detectSlugFromInput(
			'https://other.example.com/my-post/',
			[ SOURCE_URL, DEST_URL ]
		);

		// ASSERT: cross-host pastes are rejected so we don't query for a
		// slug from a site we're not connected to.
		expect( result ).toBeNull();
	} );

	it( 'should accept a bare absolute path without host validation', () => {
		// ARRANGE + ACT: input is just a path, no host to check against.
		const result = detectSlugFromInput( '/posts/my-post/', [ SOURCE_URL ] );

		// ASSERT: returns the trailing slug.
		expect( result ).toBe( 'my-post' );
	} );

	it( 'should drop query strings and hashes when extracting the slug', () => {
		// ARRANGE + ACT: URL has a tracking query and a hash.
		const result = detectSlugFromInput(
			'https://source.example.com/my-post/?utm=email#section',
			[ SOURCE_URL ]
		);

		// ASSERT: query/hash are ignored; pathname's last segment wins.
		expect( result ).toBe( 'my-post' );
	} );

	it( 'should return null for the site root', () => {
		// ARRANGE + ACT: URL points to the homepage; no slug to extract.
		const result = detectSlugFromInput(
			'https://source.example.com/',
			[ SOURCE_URL ]
		);

		// ASSERT: empty path yields null.
		expect( result ).toBeNull();
	} );

	it( 'should skip host validation when no validation URL is parseable', () => {
		// ARRANGE + ACT: every supplied URL fails to parse, so the function
		// falls through rather than blocking.
		const result = detectSlugFromInput(
			'https://source.example.com/my-post/',
			[ 'not a url' ]
		);

		// ASSERT: slug is still returned.
		expect( result ).toBe( 'my-post' );
	} );
} );

describe( 'toCalendarDay', () => {
	it( 'should serialize a date to its local YYYY-MM-DD form', () => {
		// ARRANGE: a deterministic date.
		const date = new Date( 2026, 3, 30 ); // Month is 0-indexed: April.

		// ACT: serialize to a calendar-day string.
		const result = toCalendarDay( date );

		// ASSERT: matches the local calendar day, not UTC.
		expect( result ).toBe( '2026-04-30' );
	} );

	it( 'should zero-pad single-digit months and days', () => {
		// ARRANGE: a date with single-digit components.
		const date = new Date( 2026, 0, 5 ); // January 5.

		// ACT: serialize.
		const result = toCalendarDay( date );

		// ASSERT: components are zero-padded to two digits.
		expect( result ).toBe( '2026-01-05' );
	} );
} );

describe( 'formatDateRangeLabel', () => {
	it( 'should return the "All dates" label when no bound is set', () => {
		// ARRANGE + ACT: no after or before.
		const result = formatDateRangeLabel( null, null );

		// ASSERT: catch-all label.
		expect( result ).toBe( 'All dates' );
	} );

	it( 'should collapse a same-day range to a single date', () => {
		// ARRANGE + ACT: after === before.
		const result = formatDateRangeLabel( '2026-04-30', '2026-04-30' );

		// ASSERT: returns the single date rather than "X – X".
		expect( result ).not.toContain( '–' );
		expect( result ).toContain( '2026' );
	} );
} );
