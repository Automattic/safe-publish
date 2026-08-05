/**
 * Tests for TypeScript type definitions and interfaces
 */
import { describe, expect, it } from 'vitest';
import type { UnifiedPostRow } from '@/types';

describe( 'Type validation', () => {
	describe( 'UnifiedPostRow type', () => {
		// ARRANGE: A fully-populated catalog-primary row used as a baseline.
		const baseRow: UnifiedPostRow = {
			id: 1,
			source_post_id: 1,
			link: 'https://example.com/post',
			title: 'Test Post',
			date_gmt: '2024-03-15T10:30:00Z',
			modified_gmt: '2024-03-15T10:30:00Z',
			post_type: 'post',
			status: 'publish',
			local_state: 'available',
			is_imported: false,
			wp_post_status: null,
			item_id: null,
			post_id: null,
			import_date_gmt: null,
			has_previous_content: false,
			edit_url: '',
		};

		it( 'Verifies that the source-primary row shape is well-typed', () => {
			// ACT + ASSERT: Confirm the baseline row carries its identity fields
			// and starts at the Available state.
			expect( baseRow.id ).toBe( 1 );
			expect( baseRow.source_post_id ).toBe( 1 );
			expect( baseRow.local_state ).toBe( 'available' );
		} );

		it( 'Verifies that imported routing carries local metadata', () => {
			// ARRANGE: An imported row with item-table metadata threaded through.
			const importedRow: UnifiedPostRow = {
				...baseRow,
				local_state: 'up-to-date',
				is_imported: true,
				wp_post_status: null,
				item_id: 42,
				post_id: 1024,
				import_date_gmt: '2024-03-15 10:30:00',
				edit_url: 'https://destination.example/wp-admin/post.php?post=1024',
			};

			// ASSERT: Routing label flips, is_imported follows, and the active
			// items-row id surfaces for downstream actions.
			expect( importedRow.local_state ).toBe( 'up-to-date' );
			expect( importedRow.is_imported ).toBe( true );
			expect( importedRow.item_id ).toBe( 42 );
		} );
	} );
} );
