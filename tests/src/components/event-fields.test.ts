/**
 * Tests for ExportEvent column helpers used by the export-history
 * DataViews.
 */
import { describe, expect, it } from 'vitest';

import {
	getDestinationLabel,
	getStatusLabel,
	getUserLabel,
} from '@/components/event-fields';

import type { ExportEvent } from '@/types';

const baseEvent: ExportEvent = {
	id: 1,
	date: '2026-05-21T10:30:00Z',
	level: 'info',
	event: 'CONTENT_EXPORTED',
	actor_user_id: 0,
	actor_display_name: '',
	actor_source: 'rest',
	destination_site_url: 'https://example.com',
	post_ids: [ 1, 2 ],
	post_count: 2,
};

describe( 'getUserLabel', () => {
	it( 'should return the display name when actor is a logged-in user', () => {
		// ARRANGE: event with a positive user id and a display name.
		const event: ExportEvent = {
			...baseEvent,
			actor_user_id: 42,
			actor_display_name: 'Alex',
		};
		// ACT + ASSERT.
		expect( getUserLabel( event ) ).toBe( 'Alex' );
	} );

	it( 'should fall back to "User #ID" when display name is empty', () => {
		// ARRANGE: positive user id but no captured display name.
		const event: ExportEvent = {
			...baseEvent,
			actor_user_id: 42,
			actor_display_name: '',
		};
		// ACT + ASSERT: sprintf renders the numeric fallback.
		expect( getUserLabel( event ) ).toBe( 'User #42' );
	} );

	it( 'should label system actors with their source', () => {
		// ARRANGE: system-triggered event (actor_user_id = 0).
		const event: ExportEvent = { ...baseEvent, actor_user_id: 0, actor_source: 'cron' };
		// ACT + ASSERT.
		expect( getUserLabel( event ) ).toBe( 'System (cron)' );
	} );
} );

describe( 'getDestinationLabel', () => {
	it( 'should return the URL when destination_site_url is set', () => {
		// ARRANGE: event with a destination URL.
		const event: ExportEvent = {
			...baseEvent,
			destination_site_url: 'https://destination.test',
		};
		// ACT + ASSERT.
		expect( getDestinationLabel( event ) ).toBe( 'https://destination.test' );
	} );

	it( 'should fall back to "Unknown destination" when URL is empty', () => {
		// ARRANGE: event with no destination URL recorded.
		const event: ExportEvent = { ...baseEvent, destination_site_url: '' };
		// ACT + ASSERT.
		expect( getDestinationLabel( event ) ).toBe( 'Unknown destination' );
	} );
} );

describe( 'getStatusLabel', () => {
	it( 'should return "Failed" for error-level events', () => {
		// ARRANGE: error-level event.
		const event: ExportEvent = { ...baseEvent, level: 'error' };
		// ACT + ASSERT.
		expect( getStatusLabel( event ) ).toBe( 'Failed' );
	} );

	it( 'should return "Exported" for info-level events', () => {
		// ARRANGE: info-level event.
		const event: ExportEvent = { ...baseEvent, level: 'info' };
		// ACT + ASSERT.
		expect( getStatusLabel( event ) ).toBe( 'Exported' );
	} );
} );
