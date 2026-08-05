/**
 * Tests for audit and export event column helpers.
 */
import { describe, expect, it } from 'vitest';

import {
	getChannelLabel,
	getDestinationLabel,
	getEventLabel,
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
		// ARRANGE: Event with a positive user id and a display name.
		const event: ExportEvent = {
			...baseEvent,
			actor_user_id: 42,
			actor_display_name: 'Alex',
		};

		// ACT: Derive the user column label.
		const result = getUserLabel( event );

		// ASSERT: Display name passes through unchanged.
		expect( result ).toBe( 'Alex' );
	} );

	it( 'should fall back to "User #ID" when display name is empty', () => {
		// ARRANGE: Positive user id but no captured display name.
		const event: ExportEvent = {
			...baseEvent,
			actor_user_id: 42,
			actor_display_name: '',
		};

		// ACT: Derive the user column label.
		const result = getUserLabel( event );

		// ASSERT: sprintf renders the numeric fallback.
		expect( result ).toBe( 'User #42' );
	} );

	it( 'should label system actors with their source', () => {
		// ARRANGE: System-triggered event (actor_user_id = 0).
		const event: ExportEvent = { ...baseEvent, actor_user_id: 0, actor_source: 'cron' };

		// ACT: Derive the user column label.
		const result = getUserLabel( event );

		// ASSERT: System actors render with their invocation source.
		expect( result ).toBe( 'System (cron)' );
	} );
} );

describe( 'getDestinationLabel', () => {
	it( 'should return the URL when destination_site_url is set', () => {
		// ARRANGE: Event with a destination URL.
		const event: ExportEvent = {
			...baseEvent,
			destination_site_url: 'https://destination.test',
		};

		// ACT: Derive the destination column label.
		const result = getDestinationLabel( event );

		// ASSERT: URL passes through unchanged.
		expect( result ).toBe( 'https://destination.test' );
	} );

	it( 'should fall back to "Unknown destination" when URL is empty', () => {
		// ARRANGE: Event with no destination URL recorded.
		const event: ExportEvent = { ...baseEvent, destination_site_url: '' };

		// ACT: Derive the destination column label.
		const result = getDestinationLabel( event );

		// ASSERT: Empty URL falls back to the localized label.
		expect( result ).toBe( 'Unknown destination' );
	} );
} );

describe( 'getStatusLabel', () => {
	it( 'should return "Failed" for error-level events', () => {
		// ARRANGE: Error-level event.
		const event: ExportEvent = { ...baseEvent, level: 'error' };

		// ACT: Derive the status column label.
		const result = getStatusLabel( event );

		// ASSERT: Error level renders as "Failed".
		expect( result ).toBe( 'Failed' );
	} );

	it( 'should return "Exported" for info-level events', () => {
		// ARRANGE: Info-level event.
		const event: ExportEvent = { ...baseEvent, level: 'info' };

		// ACT: Derive the status column label.
		const result = getStatusLabel( event );

		// ASSERT: Info level renders as "Exported".
		expect( result ).toBe( 'Exported' );
	} );
} );

describe( 'getEventLabel', () => {
	it( 'should return the mapped label for a known event code', () => {
		// ARRANGE: A known Log_Events code.
		const event = 'ITEM_ROLLBACK_FAILED';

		// ACT: Derive the event column label.
		const result = getEventLabel( event );

		// ASSERT: The code maps to its human-readable label.
		expect( result ).toBe( 'Item rollback failed' );
	} );

	it( 'should fall back to the raw code for an unknown event', () => {
		// ARRANGE: A code with no mapping.
		const event = 'NOT_A_REAL_EVENT';

		// ACT: Derive the event column label.
		const result = getEventLabel( event );

		// ASSERT: Unmapped codes pass through unchanged.
		expect( result ).toBe( 'NOT_A_REAL_EVENT' );
	} );
} );

describe( 'getChannelLabel', () => {
	it( 'should return the mapped label for a known channel', () => {
		// ARRANGE: A known channel slug.
		const channel = 'media';

		// ACT: Derive the channel column label.
		const result = getChannelLabel( channel );

		// ASSERT: The slug maps to its human-readable label.
		expect( result ).toBe( 'Media' );
	} );

	it( 'should fall back to the raw slug for an unknown channel', () => {
		// ARRANGE: A slug with no mapping.
		const channel = 'not_a_channel';

		// ACT: Derive the channel column label.
		const result = getChannelLabel( channel );

		// ASSERT: Unmapped slugs pass through unchanged.
		expect( result ).toBe( 'not_a_channel' );
	} );
} );
