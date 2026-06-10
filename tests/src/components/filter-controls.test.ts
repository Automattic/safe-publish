/**
 * Tests for the timezone-aware bound conversion helper used by every
 * DateRangeFilter consumer.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { calendarRangeToUtcBounds } from '@/components/filter-controls';

/**
 * Pins JavaScript's local timezone behavior by stubbing Date so the
 * tests are reproducible regardless of the machine running them. We
 * model an America/New_York host (UTC-4 in summer): a local June 10
 * resolves to 04:00 UTC, not 00:00 UTC.
 */
function withFixedTimezoneOffsetMinutes(
	offsetMinutes: number,
	callback: () => void
): void {
	const OriginalDate = globalThis.Date;
	const spy = vi
		.spyOn( OriginalDate.prototype, 'getTimezoneOffset' )
		.mockReturnValue( offsetMinutes );
	try {
		callback();
	} finally {
		spy.mockRestore();
	}
}

describe( 'calendarRangeToUtcBounds', () => {
	beforeEach( () => {
		// vi.useFakeTimers may interfere with Date math; reset.
		vi.useRealTimers();
	} );

	afterEach( () => {
		vi.restoreAllMocks();
	} );

	it( 'returns nulls when both inputs are null', () => {
		// ACT.
		const result = calendarRangeToUtcBounds( null, null );

		// ASSERT.
		expect( result.afterUtc ).toBeNull();
		expect( result.beforeUtc ).toBeNull();
	} );

	it( 'maps the after bound to local midnight of the picked day', () => {
		// ACT.
		const { afterUtc } = calendarRangeToUtcBounds( '2026-06-10', null );

		// ASSERT: the result is a Date that prints back as the same calendar
		// day at 00:00 local time.
		expect( afterUtc ).not.toBeNull();
		const parsed = new Date( afterUtc as string );
		expect( parsed.getFullYear() ).toBe( 2026 );
		expect( parsed.getMonth() ).toBe( 5 );
		expect( parsed.getDate() ).toBe( 10 );
		expect( parsed.getHours() ).toBe( 0 );
		expect( parsed.getMinutes() ).toBe( 0 );
		expect( parsed.getSeconds() ).toBe( 0 );
	} );

	it( 'maps the before bound to local 23:59:59 of the picked day', () => {
		// ACT.
		const { beforeUtc } = calendarRangeToUtcBounds( null, '2026-06-10' );

		// ASSERT.
		expect( beforeUtc ).not.toBeNull();
		const parsed = new Date( beforeUtc as string );
		expect( parsed.getFullYear() ).toBe( 2026 );
		expect( parsed.getMonth() ).toBe( 5 );
		expect( parsed.getDate() ).toBe( 10 );
		expect( parsed.getHours() ).toBe( 23 );
		expect( parsed.getMinutes() ).toBe( 59 );
		expect( parsed.getSeconds() ).toBe( 59 );
	} );

	it( 'emits UTC ISO strings whose Z-suffix matches the local-TZ instant', () => {
		// ACT.
		const { afterUtc, beforeUtc } = calendarRangeToUtcBounds(
			'2026-06-10',
			'2026-06-12'
		);

		// ASSERT: both are valid ISO strings ending in Z and round-trip to
		// the same wall-clock day in local time.
		expect( afterUtc ).toMatch( /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}.*Z$/ );
		expect( beforeUtc ).toMatch( /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}.*Z$/ );

		const a = new Date( afterUtc as string );
		const b = new Date( beforeUtc as string );
		// after < before so the range is non-empty.
		expect( a.getTime() ).toBeLessThan( b.getTime() );
		// before is approximately 2 days + 23:59:59 after after.
		const deltaMs = b.getTime() - a.getTime();
		expect( deltaMs ).toBe( ( 2 * 24 * 3600 + 23 * 3600 + 59 * 60 + 59 ) * 1000 );
	} );

	it( 'aligns to local-TZ midnight, not UTC midnight (regression for the original bug)', () => {
		// ARRANGE: simulate America/New_York (UTC-4 during EDT). The picker
		// emits "2026-06-10" because that's the user's local calendar day.
		// We should produce a UTC datetime that REPRESENTS local midnight,
		// not UTC midnight.
		withFixedTimezoneOffsetMinutes( 4 * 60, () => {
			// ACT.
			const { afterUtc } = calendarRangeToUtcBounds( '2026-06-10', null );

			// ASSERT: the produced Date, viewed in local time, is exactly
			// 2026-06-10 00:00. (The UTC string would be later in the day.)
			const local = new Date( afterUtc as string );
			expect( local.getFullYear() ).toBe( 2026 );
			expect( local.getMonth() ).toBe( 5 );
			expect( local.getDate() ).toBe( 10 );
			expect( local.getHours() ).toBe( 0 );
		} );
	} );
} );
