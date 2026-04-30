/**
 * Tests for TypeScript type definitions and interfaces
 */
import { describe, expect, it } from 'vitest';
import type { Post, ImportSession, ImportItem, PaginationInfo } from '@/types';

describe( 'Type validation', () => {
	describe( 'Post type', () => {
		it( 'should accept valid post object', () => {
			const post: Post = {
				id: 1,
				link: 'https://example.com/post',
				title: 'Test Post',
				modified: '2024-03-15T10:30:00',
			};

			expect( post.id ).toBe( 1 );
			expect( post.link ).toBe( 'https://example.com/post' );
			expect( post.title ).toBe( 'Test Post' );
			expect( post.modified ).toBe( '2024-03-15T10:30:00' );
		} );

		it( 'should accept post with optional fields', () => {
			const post: Post = {
				id: 1,
				link: 'https://example.com/post',
				title: 'Test Post',
				modified: '2024-03-15T10:30:00',
				content: 'Post content',
				excerpt: 'Post excerpt',
				author: 'John Doe',
				status: 'publish',
				featured_media: 123,
				post_type: 'post',
				meta: [ { key: 'value' } ],
				terms: [ { taxonomy: 'category', name: 'News' } ],
			};

			expect( post.content ).toBe( 'Post content' );
			expect( post.excerpt ).toBe( 'Post excerpt' );
			expect( post.author ).toBe( 'John Doe' );
			expect( post.status ).toBe( 'publish' );
			expect( post.featured_media ).toBe( 123 );
			expect( post.post_type ).toBe( 'post' );
			expect( post.meta ).toBeDefined();
			expect( post.terms ).toBeDefined();
		} );
	} );

	describe( 'ImportSession type', () => {
		it( 'should accept valid import session object', () => {
			const session: ImportSession = {
				id: 1,
				date: '2024-03-15T10:30:00',
				user: 'admin',
				total_items: 10,
				successful: 8,
				failed: 2,
				updated: 5,
				status: 'completed',
				status_label: 'Completed',
				source_url: 'https://example.com',
				can_rollback: true,
			};

			expect( session.id ).toBe( 1 );
			expect( session.status ).toBe( 'completed' );
			expect( session.successful ).toBe( 8 );
			expect( session.failed ).toBe( 2 );
		} );

		it( 'should accept all valid status values', () => {
			const statuses: Array< ImportSession[ 'status' ] > = [
				'in_progress',
				'completed',
				'failed',
				'rolled_back',
			];

			statuses.forEach( ( status ) => {
				const session: ImportSession = {
					id: 1,
					date: '2024-03-15',
					user: 'admin',
					total_items: 10,
					successful: 10,
					failed: 0,
					updated: 0,
					status,
					status_label: status,
					source_url: 'https://example.com',
					can_rollback: true,
				};
				expect( session.status ).toBe( status );
			} );
		} );
	} );

	describe( 'ImportItem type', () => {
		it( 'should accept valid import item object', () => {
			const item: ImportItem = {
				id: 1,
				title: 'Test Post',
				status: 'success',
				status_label: 'Success',
				external_id: '123',
				post_id: 456,
				has_changes: true,
				edit_url: 'https://example.com/edit',
				can_rollback: true,
				is_rolled_back: false,
				rollback_action: 'delete',
			};

			expect( item.id ).toBe( 1 );
			expect( item.status ).toBe( 'success' );
			expect( item.post_id ).toBe( 456 );
		} );

		it( 'should accept all valid status values', () => {
			const statuses: Array< ImportItem[ 'status' ] > = [ 'success', 'updated', 'error' ];

			statuses.forEach( ( status ) => {
				const item: ImportItem = {
					id: 1,
					title: 'Test',
					status,
					status_label: status,
					external_id: '123',
					has_changes: false,
					can_rollback: false,
					is_rolled_back: false,
					rollback_action: 'delete',
				};
				expect( item.status ).toBe( status );
			} );
		} );

		it( 'should accept both rollback action types', () => {
			const actions: Array< ImportItem[ 'rollback_action' ] > = [ 'delete', 'restore' ];

			actions.forEach( ( action ) => {
				const item: ImportItem = {
					id: 1,
					title: 'Test',
					status: 'success',
					status_label: 'Success',
					external_id: '123',
					has_changes: false,
					can_rollback: true,
					is_rolled_back: false,
					rollback_action: action,
				};
				expect( item.rollback_action ).toBe( action );
			} );
		} );
	} );

	describe( 'PaginationInfo type', () => {
		it( 'should accept valid pagination info', () => {
			const info: PaginationInfo = {
				totalItems: 100,
				totalPages: 10,
			};

			expect( info.totalItems ).toBe( 100 );
			expect( info.totalPages ).toBe( 10 );
		} );
	} );
} );
