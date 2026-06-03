/**
 * Tests for Post column helpers used by the dashboard DataViews.
 */
import { describe, expect, it } from 'vitest';

import { getSyncStatusLabel } from '@/components/post-fields';
import { SYNC_STATUS_LABELS } from '@/utils';

import type { Post } from '@/types';

const basePost: Post = {
	id: 1,
	link: 'https://example.com/1',
	title: 'Example',
	date_gmt: '2024-07-15T10:30:00Z',
	modified_gmt: '2024-07-15T10:30:00Z',
	post_type: 'post',
	status: 'publish',
};

describe( 'getSyncStatusLabel', () => {
	it( 'should return the outdated label when sync_status is outdated', () => {
		// ARRANGE: imported post that has an update upstream.
		const post: Post = { ...basePost, is_imported: true, sync_status: 'outdated' };

		// ACT: derive the sync status label.
		const result = getSyncStatusLabel( post );

		// ASSERT: returns the localized "Outdated" label.
		expect( result ).toBe( SYNC_STATUS_LABELS.outdated );
	} );

	it( 'should return the up-to-date label when sync_status is up-to-date', () => {
		// ARRANGE: imported post without an update.
		const post: Post = { ...basePost, is_imported: true, sync_status: 'up-to-date' };

		// ACT: derive the sync status label.
		const result = getSyncStatusLabel( post );

		// ASSERT: returns the localized "Up to date" label.
		expect( result ).toBe( SYNC_STATUS_LABELS.upToDate );
	} );

	it( 'should return the unknown label when sync_status is unknown', () => {
		// ARRANGE: imported post whose verdict can't be computed.
		const post: Post = { ...basePost, is_imported: true, sync_status: 'unknown' };

		// ACT: derive the sync status label.
		const result = getSyncStatusLabel( post );

		// ASSERT: returns the localized "Unknown" label.
		expect( result ).toBe( SYNC_STATUS_LABELS.unknown );
	} );

	it( 'should return the available label when sync_status is available', () => {
		// ARRANGE: post not yet imported.
		const post: Post = { ...basePost, is_imported: false, sync_status: 'available' };

		// ACT: derive the sync status label.
		const result = getSyncStatusLabel( post );

		// ASSERT: returns the localized "Available" label.
		expect( result ).toBe( SYNC_STATUS_LABELS.available );
	} );
} );

