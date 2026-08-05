/**
 * Tests for the site-timezone-aware bound conversion helper used by every
 * DateRangeFilter consumer.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { getSettings, setSettings } from '@wordpress/date';
import type { DateSettings } from '@wordpress/date/build-types/types';

import { calendarRangeToUtcBounds } from '@/components/filter-controls';

describe( 'calendarRangeToUtcBounds', () => {
	let originalSettings: DateSettings;

	beforeEach( () => {
		originalSettings = getSettings();
	} );

	afterEach( () => {
		setSettings( originalSettings );
	} );

	/**
	 * Pins @wordpress/date's site timezone so a test's produced UTC moments
	 * don't depend on the runner's host clock.
	 *
	 * @param {string}   zone     IANA zone string (e.g. 'America/New_York').
	 * @param {number}   offset   Fallback fixed offset in hours, used when
	 *                            the runtime has no zone data for `zone`.
	 * @param {Function} callback Test body that runs under the pinned zone.
	 */
	function withSiteTimezone(
		zone: string,
		offset: number,
		callback: () => void
	): void {
		setSettings( {
			...originalSettings,
			timezone: {
				offset,
				offsetFormatted: String( offset ),
				string: zone,
				abbr: zone,
			},
		} );
		callback();
	}

	it( 'returns nulls when both inputs are null', () => {
		// ACT: Convert with both bounds unset.
		const result = calendarRangeToUtcBounds( null, null );

		// ASSERT: Both UTC bounds come back null.
		expect( result.afterUtc ).toBeNull();
		expect( result.beforeUtc ).toBeNull();
	} );

	it( 'maps the after bound to site-local midnight in UTC', () => {
		// ARRANGE: Site zone = UTC so local midnight is UTC midnight.
		withSiteTimezone( 'UTC', 0, () => {
			// ACT: Convert a single after bound.
			const { afterUtc } = calendarRangeToUtcBounds( '2026-06-10', null );

			// ASSERT: The picked day's 00:00 lands at the same UTC moment.
			expect( afterUtc ).toBe( '2026-06-10T00:00:00Z' );
		} );
	} );

	it( 'maps the before bound to site-local 23:59:59 in UTC', () => {
		// ARRANGE: Site zone = UTC so end-of-day is UTC 23:59:59.
		withSiteTimezone( 'UTC', 0, () => {
			// ACT: Convert a single before bound.
			const { beforeUtc } = calendarRangeToUtcBounds( null, '2026-06-10' );

			// ASSERT: The picked day's 23:59:59 lands at the same UTC moment.
			expect( beforeUtc ).toBe( '2026-06-10T23:59:59Z' );
		} );
	} );

	it( 'anchors a same-day pick to a full site-local day in UTC', () => {
		// ARRANGE: Pick 2026-06-10 on both bounds with site zone NY (EDT,
		// UTC-4 in June). The local day spans UTC 04:00 → next-day 03:59:59.
		withSiteTimezone( 'America/New_York', -5, () => {
			// ACT: Convert the same picked day on both bounds.
			const { afterUtc, beforeUtc } = calendarRangeToUtcBounds(
				'2026-06-10',
				'2026-06-10'
			);

			// ASSERT: Bounds straddle the full NY-local day, expressed in UTC.
			expect( afterUtc ).toBe( '2026-06-10T04:00:00Z' );
			expect( beforeUtc ).toBe( '2026-06-11T03:59:59Z' );
		} );
	} );

	it( 'follows the site zone across daylight saving, not a fixed offset', () => {
		// ARRANGE: Two picks across the DST boundary in NY — EST (UTC-5) in
		// January and EDT (UTC-4) in July.
		withSiteTimezone( 'America/New_York', -5, () => {
			// ACT: Convert one winter pick and one summer pick.
			const winter = calendarRangeToUtcBounds( '2026-01-15', null );
			const summer = calendarRangeToUtcBounds( '2026-07-15', null );

			// ASSERT: Identical picker input resolves to different UTC offsets.
			expect( winter.afterUtc ).toBe( '2026-01-15T05:00:00Z' );
			expect( summer.afterUtc ).toBe( '2026-07-15T04:00:00Z' );
		} );
	} );
} );
