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

		// ACT: derive the user column label.
		const result = getUserLabel( event );

		// ASSERT: display name passes through unchanged.
		expect( result ).toBe( 'Alex' );
	} );

	it( 'should fall back to "User #ID" when display name is empty', () => {
		// ARRANGE: positive user id but no captured display name.
		const event: ExportEvent = {
			...baseEvent,
			actor_user_id: 42,
			actor_display_name: '',
		};

		// ACT: derive the user column label.
		const result = getUserLabel( event );

		// ASSERT: sprintf renders the numeric fallback.
		expect( result ).toBe( 'User #42' );
	} );

	it( 'should label system actors with their source', () => {
		// ARRANGE: system-triggered event (actor_user_id = 0).
		const event: ExportEvent = { ...baseEvent, actor_user_id: 0, actor_source: 'cron' };

		// ACT: derive the user column label.
		const result = getUserLabel( event );

		// ASSERT: system actors render with their invocation source.
		expect( result ).toBe( 'System (cron)' );
	} );
} );

describe( 'getDestinationLabel', () => {
	it( 'should return the URL when destination_site_url is set', () => {
		// ARRANGE: event with a destination URL.
		const event: ExportEvent = {
			...baseEvent,
			destination_site_url: 'https://destination.test',
		};

		// ACT: derive the destination column label.
		const result = getDestinationLabel( event );

		// ASSERT: URL passes through unchanged.
		expect( result ).toBe( 'https://destination.test' );
	} );

	it( 'should fall back to "Unknown destination" when URL is empty', () => {
		// ARRANGE: event with no destination URL recorded.
		const event: ExportEvent = { ...baseEvent, destination_site_url: '' };

		// ACT: derive the destination column label.
		const result = getDestinationLabel( event );

		// ASSERT: empty URL falls back to the localized label.
		expect( result ).toBe( 'Unknown destination' );
	} );
} );

describe( 'getStatusLabel', () => {
	it( 'should return "Failed" for error-level events', () => {
		// ARRANGE: error-level event.
		const event: ExportEvent = { ...baseEvent, level: 'error' };

		// ACT: derive the status column label.
		const result = getStatusLabel( event );

		// ASSERT: error level renders as "Failed".
		expect( result ).toBe( 'Failed' );
	} );

	it( 'should return "Exported" for info-level events', () => {
		// ARRANGE: info-level event.
		const event: ExportEvent = { ...baseEvent, level: 'info' };

		// ACT: derive the status column label.
		const result = getStatusLabel( event );

		// ASSERT: info level renders as "Exported".
		expect( result ).toBe( 'Exported' );
	} );
} );
