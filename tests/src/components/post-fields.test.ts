/**
 * Tests for Post column helpers used by the dashboard DataViews.
 */
import { describe, expect, it } from 'vitest';

import {
	getPostTypeLabel,
	getSyncStatusLabel,
	getSyncStatusOrder,
	getPublishStatusLabel,
} from '@/components/post-fields';
import { PUBLISH_STATUS_LABELS, SYNC_STATUS_LABELS } from '@/utils';

import type { Post } from '@/types';

const basePost: Post = {
	id: 1,
	link: 'https://example.com/1',
	title: 'Example',
	modified_gmt: '2024-07-15T10:30:00Z',
};

describe( 'getPostTypeLabel', () => {
	it( 'should capitalize a known post type', () => {
		// ARRANGE: post with a lowercase post_type.
		const post: Post = { ...basePost, post_type: 'page' };

		// ACT: derive the displayed type label.
		const result = getPostTypeLabel( post );

		// ASSERT: first letter is uppercased.
		expect( result ).toBe( 'Page' );
	} );

	it( 'should default to "Post" when post_type is missing', () => {
		// ARRANGE: post without a post_type field.

		// ACT: derive the displayed type label.
		const result = getPostTypeLabel( basePost );

		// ASSERT: falls back to capitalized "Post".
		expect( result ).toBe( 'Post' );
	} );

	it( 'should default to "Post" when post_type is empty string', () => {
		// ARRANGE: post with empty post_type triggering the fallback.
		const post: Post = { ...basePost, post_type: '' };

		// ACT: derive the displayed type label.
		const result = getPostTypeLabel( post );

		// ASSERT: empty string falls through to "Post".
		expect( result ).toBe( 'Post' );
	} );
} );

describe( 'getSyncStatusLabel', () => {
	it( 'should return the outdated label when imported with an update', () => {
		// ARRANGE: imported post that has an update upstream.
		const post: Post = { ...basePost, is_imported: true, has_update: true };

		// ACT: derive the sync status label.
		const result = getSyncStatusLabel( post );

		// ASSERT: returns the localized "Outdated" label.
		expect( result ).toBe( SYNC_STATUS_LABELS.outdated );
	} );

	it( 'should return the up-to-date label when imported with no update', () => {
		// ARRANGE: imported post without an update.
		const post: Post = { ...basePost, is_imported: true, has_update: false };

		// ACT: derive the sync status label.
		const result = getSyncStatusLabel( post );

		// ASSERT: returns the localized "Up to date" label.
		expect( result ).toBe( SYNC_STATUS_LABELS.upToDate );
	} );

	it( 'should return the available label when not imported', () => {
		// ARRANGE: post not yet imported.
		const post: Post = { ...basePost, is_imported: false };

		// ACT: derive the sync status label.
		const result = getSyncStatusLabel( post );

		// ASSERT: returns the localized "Available" label.
		expect( result ).toBe( SYNC_STATUS_LABELS.available );
	} );
} );

describe( 'getSyncStatusOrder', () => {
	it( 'should rank available (0) below outdated (1) below up-to-date (2)', () => {
		// ARRANGE: one post in each sync state.
		const available: Post = { ...basePost, is_imported: false };
		const outdated: Post = { ...basePost, is_imported: true, has_update: true };
		const upToDate: Post = { ...basePost, is_imported: true, has_update: false };

		// ACT: compute sort keys.
		const availableOrder = getSyncStatusOrder( available );
		const outdatedOrder = getSyncStatusOrder( outdated );
		const upToDateOrder = getSyncStatusOrder( upToDate );

		// ASSERT: exact sort keys per the documented contract.
		expect( availableOrder ).toBe( 0 );
		expect( outdatedOrder ).toBe( 1 );
		expect( upToDateOrder ).toBe( 2 );
	} );

	it( 'should preserve relative order under numeric comparison', () => {
		// ARRANGE: same triplet of posts.
		const available: Post = { ...basePost, is_imported: false };
		const outdated: Post = { ...basePost, is_imported: true, has_update: true };
		const upToDate: Post = { ...basePost, is_imported: true, has_update: false };

		// ACT: compute sort keys.
		const availableOrder = getSyncStatusOrder( available );
		const outdatedOrder = getSyncStatusOrder( outdated );
		const upToDateOrder = getSyncStatusOrder( upToDate );

		// ASSERT: arithmetic order matches the documented contract.
		expect( availableOrder ).toBeLessThan( outdatedOrder );
		expect( outdatedOrder ).toBeLessThan( upToDateOrder );
	} );
} );

describe( 'getPublishStatusLabel', () => {
	it( 'should return an empty string when the post is not imported', () => {
		// ARRANGE: post not imported, local_status would otherwise resolve.
		const post: Post = { ...basePost, is_imported: false, local_status: 'publish' };

		// ACT: derive the publish status label.
		const result = getPublishStatusLabel( post );

		// ASSERT: not-imported short-circuits to empty.
		expect( result ).toBe( '' );
	} );

	it( 'should return an empty string when local_status is missing', () => {
		// ARRANGE: imported post with no local_status.
		const post: Post = { ...basePost, is_imported: true };

		// ACT: derive the publish status label.
		const result = getPublishStatusLabel( post );

		// ASSERT: missing local_status short-circuits to empty.
		expect( result ).toBe( '' );
	} );

	it( 'should return the localized label for a known status', () => {
		// ARRANGE: imported post with a known local_status.
		const post: Post = { ...basePost, is_imported: true, local_status: 'draft' };

		// ACT: derive the publish status label.
		const result = getPublishStatusLabel( post );

		// ASSERT: maps to the localized label from the constant.
		expect( result ).toBe( PUBLISH_STATUS_LABELS.draft );
	} );

	it( 'should fall back to the raw status when not in the label map', () => {
		// ARRANGE: imported post with an unknown local_status string.
		const post: Post = { ...basePost, is_imported: true, local_status: 'custom_status' };

		// ACT: derive the publish status label.
		const result = getPublishStatusLabel( post );

		// ASSERT: function passes the raw key through unchanged.
		expect( result ).toBe( 'custom_status' );
	} );
} );
