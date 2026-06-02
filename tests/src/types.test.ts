/**
 * Tests for TypeScript type definitions and interfaces
 */
import { describe, expect, it } from 'vitest';
import type { Post } from '@/types';

describe( 'Type validation', () => {
	describe( 'Post type', () => {
		it( 'should accept valid post object', () => {
			const post: Post = {
				id: 1,
				link: 'https://example.com/post',
				title: 'Test Post',
				date_gmt: '2024-03-15T10:30:00Z',
				modified_gmt: '2024-03-15T10:30:00Z',
				post_type: 'post',
				status: 'publish',
			};

			expect( post.id ).toBe( 1 );
			expect( post.link ).toBe( 'https://example.com/post' );
			expect( post.title ).toBe( 'Test Post' );
			expect( post.date_gmt ).toBe( '2024-03-15T10:30:00Z' );
			expect( post.modified_gmt ).toBe( '2024-03-15T10:30:00Z' );
			expect( post.post_type ).toBe( 'post' );
			expect( post.status ).toBe( 'publish' );
		} );

		it( 'should accept post with annotation fields from the destination', () => {
			const post: Post = {
				id: 1,
				link: 'https://example.com/post',
				title: 'Test Post',
				date_gmt: '2024-03-15T10:30:00Z',
				modified_gmt: '2024-03-15T10:30:00Z',
				post_type: 'post',
				status: 'publish',
				is_imported: true,
				has_update: true,
				local_status: 'draft',
				local_edit_url: 'https://destination.example/wp-admin/post.php?post=99',
			};

			expect( post.is_imported ).toBe( true );
			expect( post.has_update ).toBe( true );
			expect( post.local_status ).toBe( 'draft' );
			expect( post.local_edit_url ).toBe(
				'https://destination.example/wp-admin/post.php?post=99'
			);
		} );
	} );

} );
