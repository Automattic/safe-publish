/**
 * Tests for the shared filter toolbar primitives.
 */
import { describe, expect, it } from 'vitest';

import {
	detectSlugFromInput,
	formatDateRangeLabel,
	slugMatchesChip,
	toCalendarDay,
} from '@/components/filter-controls';

describe( 'detectSlugFromInput', () => {
	const SOURCE_URL = 'https://source.example.com';
	const DEST_URL = 'https://destination.example.com';
	const urls = { sourceUrl: SOURCE_URL, destinationUrl: DEST_URL };

	it( 'should return null for a plain title search', () => {
		// ARRANGE + ACT: Input is plain text, not a URL.
		const result = detectSlugFromInput( 'quarterly report', urls );

		// ASSERT: No slug detected.
		expect( result ).toBeNull();
	} );

	it( 'should tag a source-host URL with the source origin', () => {
		// ARRANGE + ACT: Full URL on the source host.
		const result = detectSlugFromInput(
			'https://source.example.com/2026/03/my-post/',
			urls
		);

		// ASSERT: Trailing slug, attributed to the source.
		expect( result ).toEqual( { slug: 'my-post', origin: 'source' } );
	} );

	it( 'should tag a destination-host URL with the destination origin', () => {
		// ARRANGE + ACT: Full URL on the destination host.
		const result = detectSlugFromInput(
			'https://destination.example.com/2026/03/my-post/',
			urls
		);

		// ASSERT: Trailing slug, attributed to the destination.
		expect( result ).toEqual( {
			slug: 'my-post',
			origin: 'destination',
		} );
	} );

	it( 'should return null for a full URL on a foreign host', () => {
		// ARRANGE + ACT: URL host matches neither connected site.
		const result = detectSlugFromInput(
			'https://other.example.com/my-post/',
			urls
		);

		// ASSERT: Cross-host pastes are rejected so we don't query for a
		// slug from a site we're not connected to.
		expect( result ).toBeNull();
	} );

	it( 'should ignore a leading www. when matching the source host', () => {
		// ARRANGE + ACT: A browser-canonical www. permalink pasted against a
		// bare-host source — the same site after a canonical redirect.
		const result = detectSlugFromInput(
			'https://www.source.example.com/my-post/',
			urls
		);

		// ASSERT: www. is dropped on both sides, so it attributes to the
		// source instead of rejecting and falling back to a text search.
		expect( result ).toEqual( { slug: 'my-post', origin: 'source' } );
	} );

	it( 'should ignore a leading www. on the connected host', () => {
		// ARRANGE + ACT: Source is stored with a leading www.; the pasted
		// permalink omits it.
		const result = detectSlugFromInput(
			'https://source.example.com/my-post/',
			{
				sourceUrl: 'https://www.source.example.com',
				destinationUrl: DEST_URL,
			}
		);

		// ASSERT: www. is dropped on both sides, so the host still matches.
		expect( result ).toEqual( { slug: 'my-post', origin: 'source' } );
	} );

	it( 'should tag a bare absolute path with the unknown origin', () => {
		// ARRANGE + ACT: Input is just a path, no host to attribute.
		const result = detectSlugFromInput( '/posts/my-post/', urls );

		// ASSERT: Trailing slug, origin unknown (best-effort routing).
		expect( result ).toEqual( { slug: 'my-post', origin: 'unknown' } );
	} );

	it( 'should treat a shared source/destination host as unknown origin', () => {
		// ARRANGE + ACT: Subdirectory multisite — both sites share a host and
		// differ only by path, so the host can't attribute an origin.
		const result = detectSlugFromInput(
			'https://example.com/blog-b/my-post/',
			{
				sourceUrl: 'https://example.com/blog-a',
				destinationUrl: 'https://example.com',
			}
		);

		// ASSERT: Slug still routes best-effort under the unknown origin.
		expect( result ).toEqual( { slug: 'my-post', origin: 'unknown' } );
	} );

	it( 'should drop query strings and hashes when extracting the slug', () => {
		// ARRANGE + ACT: URL has a tracking query and a hash.
		const result = detectSlugFromInput(
			'https://source.example.com/my-post/?utm=email#section',
			urls
		);

		// ASSERT: query/hash are ignored; pathname's last segment wins.
		expect( result ).toEqual( { slug: 'my-post', origin: 'source' } );
	} );

	it( 'should return null for the site root', () => {
		// ARRANGE + ACT: URL points to the homepage; no slug to extract.
		const result = detectSlugFromInput(
			'https://source.example.com/',
			urls
		);

		// ASSERT: Empty path yields null.
		expect( result ).toBeNull();
	} );

	it( 'should skip host attribution when neither site URL is parseable', () => {
		// ARRANGE + ACT: Both connected URLs fail to parse, so the function
		// falls through rather than blocking.
		const result = detectSlugFromInput(
			'https://source.example.com/my-post/',
			{ sourceUrl: 'not a url', destinationUrl: 'also not a url' }
		);

		// ASSERT: Slug is still returned under the unknown origin.
		expect( result ).toEqual( { slug: 'my-post', origin: 'unknown' } );
	} );
} );

describe( 'slugMatchesChip', () => {
	it( 'should match a source slug on a catalog-primary chip', () => {
		// ARRANGE + ACT + ASSERT: Source origin routes to All/Available.
		expect( slugMatchesChip( 'source', true ) ).toBe( true );
	} );

	it( 'should not match a source slug on a local-primary chip', () => {
		// ARRANGE + ACT + ASSERT: Source origin can't resolve on Up to
		// date/Outdated.
		expect( slugMatchesChip( 'source', false ) ).toBe( false );
	} );

	it( 'should match a destination slug on a local-primary chip', () => {
		// ARRANGE + ACT + ASSERT: Destination origin routes to Up to
		// date/Outdated.
		expect( slugMatchesChip( 'destination', false ) ).toBe( true );
	} );

	it( 'should not match a destination slug on a catalog-primary chip', () => {
		// ARRANGE + ACT + ASSERT: Destination origin can't resolve on
		// All/Available.
		expect( slugMatchesChip( 'destination', true ) ).toBe( false );
	} );

	it( 'should match an unknown origin on any chip', () => {
		// ARRANGE + ACT + ASSERT: Bare-path best-effort matches either column.
		expect( slugMatchesChip( 'unknown', true ) ).toBe( true );
		expect( slugMatchesChip( 'unknown', false ) ).toBe( true );
	} );
} );

describe( 'toCalendarDay', () => {
	it( 'should serialize a date to its local YYYY-MM-DD form', () => {
		// ARRANGE: A deterministic date.
		const date = new Date( 2026, 3, 30 ); // Month is 0-indexed: April.

		// ACT: Serialize to a calendar-day string.
		const result = toCalendarDay( date );

		// ASSERT: Matches the local calendar day, not UTC.
		expect( result ).toBe( '2026-04-30' );
	} );

	it( 'should zero-pad single-digit months and days', () => {
		// ARRANGE: A date with single-digit components.
		const date = new Date( 2026, 0, 5 ); // January 5.

		// ACT: Serialize.
		const result = toCalendarDay( date );

		// ASSERT: Components are zero-padded to two digits.
		expect( result ).toBe( '2026-01-05' );
	} );
} );

describe( 'formatDateRangeLabel', () => {
	it( 'should return the "All dates" label when no bound is set', () => {
		// ARRANGE + ACT: No after or before.
		const result = formatDateRangeLabel( null, null );

		// ASSERT: Catch-all label.
		expect( result ).toBe( 'All dates' );
	} );

	it( 'should collapse a same-day range to a single date', () => {
		// ARRANGE + ACT: After === before.
		const result = formatDateRangeLabel( '2026-04-30', '2026-04-30' );

		// ASSERT: Returns the single date rather than "X – X".
		expect( result ).not.toContain( '–' );
		expect( result ).toContain( '2026' );
	} );
} );
