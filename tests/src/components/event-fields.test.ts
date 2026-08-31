/**
 * Tests for audit event column helpers.
 */
import { describe, expect, it } from 'vitest';

import {
	getChannelLabel,
	getEventLabel,
	getUserLabel,
} from '@/components/event-fields';

import type { AuditEvent } from '@/types';

const baseEvent: AuditEvent = {
	id: 1,
	channel: 'export',
	date: '2026-05-21T10:30:00Z',
	level: 'info',
	event: 'CONTENT_EXPORTED',
	actor_user_id: 0,
	actor_display_name: '',
	actor_source: 'rest',
	data: {},
};

describe( 'getUserLabel', () => {
	it( 'should return the display name when actor is a logged-in user', () => {
		// ARRANGE: Event with a positive user id and a display name.
		const event: AuditEvent = {
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
		const event: AuditEvent = {
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
		const event: AuditEvent = { ...baseEvent, actor_user_id: 0, actor_source: 'cron' };

		// ACT: Derive the user column label.
		const result = getUserLabel( event );

		// ASSERT: System actors render with their invocation source.
		expect( result ).toBe( 'System (cron)' );
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
