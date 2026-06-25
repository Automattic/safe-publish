/**
 * Tests for utility functions
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getSettings, setSettings } from '@wordpress/date';
import {
	attentionIssueId,
	formatBadgeTimestamp,
	formatDateTime,
	extractUrlPath,
	renderIssueMessage,
	renderWarningMessage,
	renderWarningShortLabel,
} from '@/utils';
import type {
	AttentionIssue,
	AuthorFallbackWarning,
	NavRefRewriteFailedWarning,
	ParentOrphanedWarning,
} from '@/types';

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

describe( 'formatBadgeTimestamp', () => {
	beforeEach( () => {
		vi.useFakeTimers();
		// Pin "now" to mid-2024 so the current-year branch is deterministic.
		vi.setSystemTime( new Date( '2024-06-15T12:00:00Z' ) );
	} );

	afterEach( () => {
		vi.useRealTimers();
	} );

	it( 'should omit the year when the timestamp is in the current year', () => {
		// ARRANGE: pinned now = 2024-06-15; target also in 2024.
		// ACT: format a same-year UTC timestamp.
		const result = formatBadgeTimestamp( '2024-07-15T10:30:00Z' );
		// ASSERT: abbreviated month + day + WP time, no year.
		expect( result ).toBe( 'Jul 15 6:30 am' );
	} );

	it( 'should include the year when the timestamp is in a different year', () => {
		// ARRANGE: pinned now = 2024-06-15; target in 2023 (also summer, so
		// the same EDT offset applies as the current-year test above).
		// ACT: format a previous-year UTC timestamp.
		const result = formatBadgeTimestamp( '2023-07-15T22:30:00Z' );
		// ASSERT: abbreviated month + day + year + WP time (EDT shift).
		expect( result ).toBe( 'Jul 15, 2023 6:30 pm' );
	} );

	it( 'should respect a 24-hour WP time format', () => {
		// ARRANGE: switch to 24-hour time format.
		setSettings( {
			...getSettings(),
			formats: {
				...getSettings().formats,
				time: 'H:i',
			},
		} );
		// ACT: format with 24-hour time active.
		const result = formatBadgeTimestamp( '2024-07-15T22:30:00Z' );
		// ASSERT: month + day + 24-hour time (EDT shift puts 22:30 UTC at 18:30).
		expect( result ).toBe( 'Jul 15 18:30' );
	} );

	it( 'should return "Invalid Date" for invalid date string', () => {
		expect( formatBadgeTimestamp( 'not-a-date' ) ).toBe( 'Invalid Date' );
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

	it( 'should list the stale post IDs and a retry hint for nav_ref_rewrite_failed', () => {
		// ARRANGE: two posts could not be repointed after a menu import.
		const warning: NavRefRewriteFailedWarning = {
			type: 'nav_ref_rewrite_failed',
			failed_post_ids: [ 12, 34 ],
		};
		// ACT: render the message.
		const message = renderWarningMessage( warning );
		// ASSERT: the count, both IDs, and the retry instruction are present.
		expect( message ).toContain( '2 pages' );
		expect( message ).toContain( '12, 34' );
		expect( message ).toContain( 'Re-import the menu to retry' );
	} );

	it( 'should use the singular phrasing for a single nav_ref_rewrite_failed post', () => {
		// ARRANGE: a single post could not be repointed.
		const warning: NavRefRewriteFailedWarning = {
			type: 'nav_ref_rewrite_failed',
			failed_post_ids: [ 7 ],
		};
		// ACT: render the message.
		const message = renderWarningMessage( warning );
		// ASSERT: singular wording and the lone ID.
		expect( message ).toContain( '1 page' );
		expect( message ).not.toContain( '1 pages' );
		expect( message ).toContain( '7' );
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

	it( 'should return "nav reference update failed" for nav_ref_rewrite_failed', () => {
		// ARRANGE: any nav_ref_rewrite_failed warning.
		const warning: NavRefRewriteFailedWarning = {
			type: 'nav_ref_rewrite_failed',
			failed_post_ids: [ 5 ],
		};
		// ACT: render the short label.
		const label = renderWarningShortLabel( warning );
		// ASSERT: short label is the comma-joinable string used in the bulk modal.
		expect( label ).toBe( 'nav reference update failed' );
	} );
} );

/**
 * Builds an AttentionIssue fixture; tests override fields to match the case.
 */
function makeIssue( overrides: Partial< AttentionIssue > ): AttentionIssue {
	return {
		affected_post_id: 10,
		issue_type: 'unmapped_block_reference',
		target_ref: 700,
		target_kind: 'post',
		severity: 'warning',
		source_site_url: 'https://source.example.com',
		detail: {},
		first_detected_gmt: '2024-01-01 00:00:00',
		last_seen_gmt: '2024-01-01 00:00:00',
		affected_title: 'About',
		affected_edit_url: '',
		retryable: false,
		...overrides,
	};
}

describe( 'renderIssueMessage', () => {
	it( 'reuses the unmapped-post warning copy, reconstructed from detail', () => {
		// ARRANGE: an unmapped post-reference issue.
		const issue = makeIssue( {
			target_kind: 'post',
			target_ref: 700,
			detail: {
				block: 'core/navigation-link',
				kind: 'post',
				source_id: 700,
			},
		} );
		// ACT & ASSERT: identical to the import-warning renderer's output.
		expect( renderIssueMessage( issue ) ).toBe(
			renderWarningMessage( {
				type: 'unmapped_block_reference',
				kind: 'post',
				block: 'core/navigation-link',
				source_id: 700,
			} )
		);
	} );

	it( 'reuses the unmapped-term warning copy', () => {
		// ARRANGE: an unmapped term-reference issue.
		const issue = makeIssue( {
			target_kind: 'term',
			target_ref: 701,
			detail: { kind: 'term', source_id: 701 },
		} );
		// ACT: render the message.
		const message = renderIssueMessage( issue );
		// ASSERT: term phrasing and the source ID are present.
		expect( message ).toContain( '701' );
		expect( message ).toContain( 'term' );
	} );

	it( 'reuses the parent_orphaned warning copy from detail', () => {
		// ARRANGE: an orphaned-parent issue.
		const issue = makeIssue( {
			issue_type: 'parent_orphaned',
			target_ref: 42,
			detail: { parent_id: 42, parent_title: null, reason: 'not_imported' },
		} );
		// ACT & ASSERT: identical to the import-warning renderer's output.
		expect( renderIssueMessage( issue ) ).toBe(
			renderWarningMessage( {
				type: 'parent_orphaned',
				source: { parent_id: 42, parent_title: null },
				reason: 'not_imported',
			} )
		);
	} );

	it( 'renders a page-centric sentence for nav_ref_rewrite_failed', () => {
		// ARRANGE: a per-page navigation rewrite failure.
		const issue = makeIssue( {
			issue_type: 'nav_ref_rewrite_failed',
			target_ref: 8300,
			severity: 'error',
			detail: { source_nav_id: 8300 },
			retryable: true,
		} );
		// ACT: render the message.
		const message = renderIssueMessage( issue );
		// ASSERT: the menu ID and the retry hint are present.
		expect( message ).toContain( '8300' );
		expect( message ).toContain( 'Retry' );
	} );
} );

describe( 'attentionIssueId', () => {
	it( 'keeps a post and a term reference sharing a target_ref distinct', () => {
		// ARRANGE: same post, same source ref, different kind — the collision
		// the 4-column identity key guards against.
		const postRef = makeIssue( { target_kind: 'post', target_ref: 42 } );
		const termRef = makeIssue( { target_kind: 'term', target_ref: 42 } );
		// ACT & ASSERT: the ids differ, so DataViews rows stay unique.
		expect( attentionIssueId( postRef ) ).not.toBe(
			attentionIssueId( termRef )
		);
	} );

	it( 'is stable for the same identity', () => {
		// ARRANGE & ACT: two issues with the same identity.
		const id = attentionIssueId( makeIssue( {} ) );
		// ASSERT: the id is deterministic.
		expect( id ).toBe( attentionIssueId( makeIssue( {} ) ) );
	} );
} );
