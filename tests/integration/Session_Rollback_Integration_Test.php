<?php
/**
 * Integration tests for session rollback functionality
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Session_Rollback_Service;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use WP_Error;

/**
 * Session Rollback Integration Test Class.
 *
 * Tests rollback operations for import sessions.
 */
class Session_Rollback_Integration_Test extends Integration_Test_Case {

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Rollback service instance.
	 *
	 * @var Session_Rollback_Service
	 */
	private Session_Rollback_Service $rollback_service;

	/**
	 * Set up test environment.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->repository       = new History_Repository();
		$this->rollback_service = new Session_Rollback_Service( $this->repository );

		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'import' );
	}

	/**
	 * Verifies that rolling back a complete session deletes imported posts.
	 */
	public function test_rollback_session_deletes_imported_posts(): void {
		// ARRANGE: Create session and import posts.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// Create actual WordPress posts.
		$post_id_1 = $this->factory()->post->create( array( 'post_title' => 'Imported Post 1' ) );
		$post_id_2 = $this->factory()->post->create( array( 'post_title' => 'Imported Post 2' ) );

		// Log the imports.
		$item_id_1 = $this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post 1',
			'success',
			$post_id_1
		);

		$item_id_2 = $this->repository->log_import_action(
			$session_id,
			2,
			'Imported Post 2',
			'success',
			$post_id_2
		);

		$this->repository->complete_session( $session_id );

		// ASSERT: Verify posts exist.
		$this->assertNotNull( get_post( $post_id_1 ) );
		$this->assertNotNull( get_post( $post_id_2 ) );

		// ACT: Rollback the session.
		$result = $this->rollback_service->rollback_session( $session_id );

		// ASSERT: Posts were deleted with no failures.
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['deleted_count'] );
		$this->assertSame( 0, $result['failed_count'] );

		// ASSERT: Verify posts are deleted.
		$this->assertNull( get_post( $post_id_1 ) );
		$this->assertNull( get_post( $post_id_2 ) );

		// ASSERT: Verify session marked as rolled back.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 'rolled_back', $session['status'] );

		// ASSERT: Each item row is flagged so the per-item rolled_back state
		// stays consistent with the item-level rollback path.
		$item_1 = $this->repository->get_item( $item_id_1 );
		$item_2 = $this->repository->get_item( $item_id_2 );
		$this->assertSame( 1, (int) $item_1['rolled_back'] );
		$this->assertSame( 1, (int) $item_2['rolled_back'] );
	}

	/**
	 * Verifies that rolling back single item deletes only that post.
	 */
	public function test_rollback_item_deletes_single_post(): void {
		// ARRANGE: Create session with two posts.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$post_id_1 = $this->factory()->post->create( array( 'post_title' => 'Keep This' ) );
		$post_id_2 = $this->factory()->post->create( array( 'post_title' => 'Delete This' ) );

		$this->repository->log_import_action(
			$session_id,
			1,
			'Keep This',
			'success',
			$post_id_1
		);

		$item_id_2 = $this->repository->log_import_action(
			$session_id,
			2,
			'Delete This',
			'success',
			$post_id_2
		);

		// ACT: Rollback only second item.
		$result = $this->rollback_service->rollback_item( $item_id_2 );

		// ASSERT: Only second post deleted.
		$this->assertIsArray( $result );
		$this->assertSame( 'deleted', $result['action'] );
		$this->assertSame( $post_id_2, $result['post_id'] );

		// ASSERT: Verify first post still exists.
		$this->assertNotNull( get_post( $post_id_1 ) );

		// ASSERT: Verify second post deleted.
		$this->assertNull( get_post( $post_id_2 ) );
	}

	/**
	 * Verifies that rollback with nonexistent session returns error.
	 */
	public function test_rollback_nonexistent_session_returns_error(): void {
		// ARRANGE: Use nonexistent session ID.
		$fake_session_id = 999999;

		// ACT: Attempt rollback.
		$result = $this->rollback_service->rollback_session( $fake_session_id );

		// ASSERT: Returns WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'session_not_found', $result->get_error_code() );
	}

	/**
	 * Verifies that rollback with nonexistent item returns error.
	 */
	public function test_rollback_nonexistent_item_returns_error(): void {
		// ARRANGE: Use nonexistent item ID.
		$fake_item_id = 999999;

		// ACT: Attempt rollback.
		$result = $this->rollback_service->rollback_item( $fake_item_id );

		// ASSERT: Returns WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'item_not_found', $result->get_error_code() );
	}

	/**
	 * Verifies that rollback only affects successful/updated imports.
	 */
	public function test_rollback_ignores_failed_imports(): void {
		// ARRANGE: Create session with success and failed imports.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$post_id = $this->factory()->post->create( array( 'post_title' => 'Success' ) );

		// Success item.
		$this->repository->log_import_action(
			$session_id,
			1,
			'Success',
			'success',
			$post_id
		);

		// Failed item (no post created).
		$this->repository->log_import_action(
			$session_id,
			2,
			'Failed',
			'error',
			null,
			'Import failed'
		);

		$this->repository->complete_session( $session_id );

		// ACT: Rollback session.
		$result = $this->rollback_service->rollback_session( $session_id );

		// ASSERT: Only the successful import was rolled back; the failed one
		// was ignored.
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['deleted_count'] );
		$this->assertSame( 0, $result['restored_count'] );
		$this->assertSame( 0, $result['failed_count'] );

		// ASSERT: The successfully imported post was deleted.
		$this->assertNull( get_post( $post_id ) );

		// ASSERT: Session is marked as rolled back.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 'rolled_back', $session['status'] );
	}

	/**
	 * Verifies that partial rollback tracks failed count and
	 * does not mark session as rolled back.
	 */
	public function test_partial_rollback_tracks_failures(): void {
		// ARRANGE: Create session with two posts, then delete one
		// before rollback to simulate a failure.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);

		$post_id_1 = $this->factory()->post->create(
			array( 'post_title' => 'Survives' )
		);
		$post_id_2 = $this->factory()->post->create(
			array( 'post_title' => 'Already Gone' )
		);

		$this->repository->log_import_action(
			$session_id,
			1,
			'Survives',
			'success',
			$post_id_1
		);

		$this->repository->log_import_action(
			$session_id,
			2,
			'Already Gone',
			'success',
			$post_id_2
		);

		$this->repository->complete_session( $session_id );

		// ACT: Delete one post before rollback to cause a failure.
		wp_delete_post( $post_id_2, true );
		$result = $this->rollback_service->rollback_session(
			$session_id
		);

		// ASSERT: One succeeded, one failed.
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['deleted_count'] );
		$this->assertSame( 0, $result['restored_count'] );
		$this->assertSame( 1, $result['failed_count'] );

		// ASSERT: Session is NOT marked as rolled back.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 'completed', $session['status'] );
	}

	/**
	 * Verifies that a successful session rollback emits a SESSION_ROLLED_BACK
	 * audit event with the session ID.
	 */
	public function test_rollback_session_emits_audit_event(): void {
		// ARRANGE: Create session with a single imported post.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);

		$post_id = $this->factory()->post->create(
			array( 'post_title' => 'Imported Post' )
		);

		$this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);

		$this->repository->complete_session( $session_id );

		// ACT: Roll back the session.
		$this->rollback_service->rollback_session( $session_id );

		// ASSERT: One SESSION_ROLLED_BACK event was emitted with the session ID
		// in its payload.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'info', $events[0]['level'] );
		$this->assertSame( $session_id, $events[0]['data']['session_id'] );
	}

	/**
	 * Verifies that marking an already-rolled-back session emits a
	 * SESSION_ALREADY_ROLLED_BACK event instead of SESSION_ROLLED_BACK.
	 */
	public function test_mark_session_rolled_back_emits_already_rolled_back_when_no_row_changed(): void {
		// ARRANGE: Session that is already in the rolled_back state.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$this->repository->mark_session_rolled_back( $session_id );
		Audit_Log_Table::clear( 'import' );

		// ACT: Mark the session as rolled back again.
		$this->repository->mark_session_rolled_back( $session_id );

		// ASSERT: A SESSION_ALREADY_ROLLED_BACK event was emitted, not a
		// SESSION_ROLLED_BACK event.
		$already_rolled_back_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_ALREADY_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $already_rolled_back_events );
		$this->assertSame( 'info', $already_rolled_back_events[0]['level'] );
		$this->assertSame(
			$session_id,
			$already_rolled_back_events[0]['data']['session_id']
		);

		$success_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_ROLLED_BACK',
			)
		);
		$this->assertCount( 0, $success_events );
	}

	/**
	 * Verifies that a successful single-item rollback emits an ITEM_ROLLED_BACK
	 * audit event with the item ID.
	 */
	public function test_rollback_item_emits_audit_event(): void {
		// ARRANGE: Create session with a single imported post.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);

		$post_id = $this->factory()->post->create(
			array( 'post_title' => 'Imported Post' )
		);

		$item_id = $this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);

		// ACT: Roll back just this item.
		$this->rollback_service->rollback_item( $item_id );

		// ASSERT: One ITEM_ROLLED_BACK event was emitted with the item ID,
		// session ID, and post ID in its payload.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'info', $events[0]['level'] );
		$this->assertSame( $item_id, $events[0]['data']['item_id'] );
		$this->assertSame( $session_id, $events[0]['data']['session_id'] );
		$this->assertSame( $post_id, $events[0]['data']['post_id'] );
	}

	/**
	 * Verifies that marking an already-rolled-back item emits an
	 * ITEM_ALREADY_ROLLED_BACK event instead of ITEM_ROLLED_BACK.
	 */
	public function test_mark_item_rolled_back_emits_already_rolled_back_when_no_row_changed(): void {
		// ARRANGE: Item that is already flagged as rolled_back.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$post_id    = $this->factory()->post->create(
			array( 'post_title' => 'Imported Post' )
		);
		$item_id    = $this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);
		$this->repository->mark_item_rolled_back( $item_id );
		Audit_Log_Table::clear( 'import' );

		// ACT: Mark the same item as rolled back again.
		$this->repository->mark_item_rolled_back( $item_id );

		// ASSERT: An ITEM_ALREADY_ROLLED_BACK event was emitted, not an
		// ITEM_ROLLED_BACK event.
		$already_rolled_back_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ALREADY_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $already_rolled_back_events );
		$this->assertSame( 'info', $already_rolled_back_events[0]['level'] );
		$this->assertSame( $item_id, $already_rolled_back_events[0]['data']['item_id'] );
		$this->assertSame( $session_id, $already_rolled_back_events[0]['data']['session_id'] );
		$this->assertSame( $post_id, $already_rolled_back_events[0]['data']['post_id'] );

		$success_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ROLLED_BACK',
			)
		);
		$this->assertCount( 0, $success_events );
	}

	/**
	 * Verifies that a failed session rollback (SQL-layer failure) emits a
	 * SESSION_ROLLBACK_FAILED audit event with the wpdb error captured.
	 */
	public function test_mark_session_rolled_back_emits_failed_when_update_errors(): void {
		global $wpdb;

		// ARRANGE: Create a session, then force the next UPDATE on the
		// imports table to fail at the SQL layer by rewriting it to invalid
		// SQL via the 'query' filter. try/finally guarantees filter removal.
		$session_id      = $this->repository->create_session( 'https://example.com', 'bulk' );
		$imports_table   = Imports_Table::table_name();
		$filter_callback = function ( string $query ) use ( $imports_table ): string {
			if ( 0 === strpos( $query, "UPDATE `{$imports_table}`" ) ) {
				return 'UPDATE safe_publish_nonexistent_table_for_test SET x = 1';
			}
			return $query;
		};
		add_filter( 'query', $filter_callback );
		$wpdb->suppress_errors( true );

		try {
			// ACT: Roll back the session.
			$this->repository->mark_session_rolled_back( $session_id );

			// ASSERT: A SESSION_ROLLBACK_FAILED error event was emitted with
			// the session ID and a non-empty wpdb_error string.
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_ROLLBACK_FAILED',
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( 'error', $events[0]['level'] );
			$this->assertSame( $session_id, $events[0]['data']['session_id'] );
			$this->assertNotEmpty( $events[0]['data']['wpdb_error'] );

			// ASSERT: No success event was recorded.
			$success_events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_ROLLED_BACK',
				)
			);
			$this->assertCount( 0, $success_events );
		} finally {
			remove_filter( 'query', $filter_callback );
			$wpdb->suppress_errors( false );
		}
	}

	/**
	 * Verifies that a failed bulk items UPDATE inside
	 * mark_session_rolled_back() emits SESSION_ROLLBACK_FAILED and leaves
	 * the session row untouched so a retry can heal the partial rollback.
	 */
	public function test_mark_session_rolled_back_emits_failed_when_items_update_errors(): void {
		global $wpdb;

		// ARRANGE: Create a session with a success item, then force the bulk
		// UPDATE on the items table to fail at the SQL layer by rewriting it
		// via the 'query' filter. try/finally guarantees filter removal.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$this->factory()->post->create()
		);
		$items_table     = Import_Items_Table::table_name();
		$filter_callback = function ( string $query ) use ( $items_table ): string {
			if ( 0 === strpos( $query, "UPDATE `{$items_table}`" ) ) {
				return 'UPDATE safe_publish_nonexistent_table_for_test SET x = 1';
			}
			return $query;
		};
		add_filter( 'query', $filter_callback );
		$wpdb->suppress_errors( true );

		try {
			// ACT: Roll back the session; the items UPDATE fails first.
			$this->repository->mark_session_rolled_back( $session_id );

			// ASSERT: A SESSION_ROLLBACK_FAILED error event was emitted with
			// the session ID and a non-empty wpdb_error string.
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_ROLLBACK_FAILED',
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( 'error', $events[0]['level'] );
			$this->assertSame( $session_id, $events[0]['data']['session_id'] );
			$this->assertNotEmpty( $events[0]['data']['wpdb_error'] );

			// ASSERT: The session row was not flipped — the early return
			// must run before the session UPDATE so a retry can heal the
			// partial rollback.
			$session = $this->repository->get_session( $session_id );
			$this->assertSame( 'in_progress', $session['status'] );

			// ASSERT: No success event was recorded.
			$success_events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_ROLLED_BACK',
				)
			);
			$this->assertCount( 0, $success_events );
		} finally {
			remove_filter( 'query', $filter_callback );
			$wpdb->suppress_errors( false );
		}
	}

	/**
	 * Verifies that a failed item rollback (SQL-layer failure) emits an
	 * ITEM_ROLLBACK_FAILED audit event with the wpdb error captured.
	 */
	public function test_mark_item_rolled_back_emits_failed_when_update_errors(): void {
		global $wpdb;

		// ARRANGE: Create a session with one item, then force the next UPDATE
		// on the items table to fail at the SQL layer by rewriting it via
		// the 'query' filter. try/finally guarantees filter removal.
		$session_id      = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id         = $this->factory()->post->create( array( 'post_title' => 'Imported Post' ) );
		$item_id         = $this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);
		$items_table     = Import_Items_Table::table_name();
		$filter_callback = function ( string $query ) use ( $items_table ): string {
			if ( 0 === strpos( $query, "UPDATE `{$items_table}`" ) ) {
				return 'UPDATE safe_publish_nonexistent_table_for_test SET x = 1';
			}
			return $query;
		};
		add_filter( 'query', $filter_callback );
		$wpdb->suppress_errors( true );

		try {
			// ACT: Roll back the item.
			$this->repository->mark_item_rolled_back( $item_id );

			// ASSERT: An ITEM_ROLLBACK_FAILED error event was emitted with the
			// item ID, the snapshotted session_id and post_id (SELECT runs
			// before the filtered UPDATE, so these are real values), and a
			// non-empty wpdb_error string.
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'ITEM_ROLLBACK_FAILED',
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( 'error', $events[0]['level'] );
			$this->assertSame( $item_id, $events[0]['data']['item_id'] );
			$this->assertSame( $session_id, $events[0]['data']['session_id'] );
			$this->assertSame( $post_id, $events[0]['data']['post_id'] );
			$this->assertNotEmpty( $events[0]['data']['wpdb_error'] );

			// ASSERT: No success event was recorded.
			$success_events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'ITEM_ROLLED_BACK',
				)
			);
			$this->assertCount( 0, $success_events );
		} finally {
			remove_filter( 'query', $filter_callback );
			$wpdb->suppress_errors( false );
		}
	}

	/**
	 * Verifies that deleting a session emits a SESSION_DELETED audit event
	 * with the session ID, source site URL snapshot, and items_deleted count.
	 */
	public function test_delete_session_emits_audit_event(): void {
		// ARRANGE: Create a session with two items.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id_1  = $this->factory()->post->create( array( 'post_title' => 'Imported Post 1' ) );
		$post_id_2  = $this->factory()->post->create( array( 'post_title' => 'Imported Post 2' ) );
		$this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post 1',
			'success',
			$post_id_1
		);
		$this->repository->log_import_action(
			$session_id,
			2,
			'Imported Post 2',
			'success',
			$post_id_2
		);

		// ACT: Delete the session.
		$deleted = $this->repository->delete_session( $session_id );

		// ASSERT: The repository reports success.
		$this->assertTrue( $deleted );

		// ASSERT: One SESSION_DELETED event was emitted with the snapshotted
		// source URL and the count of items removed alongside the session.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_DELETED',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'info', $events[0]['level'] );
		$this->assertSame( $session_id, $events[0]['data']['session_id'] );
		$this->assertSame( 'https://example.com', $events[0]['data']['source_site_url'] );
		$this->assertSame( 2, $events[0]['data']['items_deleted'] );
	}

	/**
	 * Verifies that attempting to delete a non-existent session does not emit
	 * a SESSION_DELETED audit event.
	 */
	public function test_delete_session_emits_no_event_when_session_missing(): void {
		// ARRANGE: A session ID that does not exist.
		$nonexistent_id = 9999999;

		// ACT: Try to delete it.
		$deleted = $this->repository->delete_session( $nonexistent_id );

		// ASSERT: The repository reports no deletion happened.
		$this->assertFalse( $deleted );

		// ASSERT: No SESSION_DELETED event was emitted.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_DELETED',
			)
		);
		$this->assertCount( 0, $events );
	}

	/**
	 * Verifies that a session deletion whose items-table DELETE fails at the
	 * SQL layer emits a SESSION_DELETE_FAILED audit event, bails out before
	 * the imports-table DELETE, and leaves the session row intact.
	 */
	public function test_delete_session_emits_failed_when_items_delete_errors(): void {
		global $wpdb;

		// ARRANGE: Create a session with one item, then force the next DELETE
		// on the items table to fail at the SQL layer by rewriting it to
		// invalid SQL via the 'query' filter. try/finally guarantees filter
		// removal.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id    = $this->factory()->post->create( array( 'post_title' => 'Imported Post' ) );
		$this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);
		$items_table     = Import_Items_Table::table_name();
		$filter_callback = function ( $query ) use ( $items_table ) {
			if ( 0 === strpos( $query, "DELETE FROM `{$items_table}`" ) ) {
				return 'DELETE FROM safe_publish_nonexistent_table_for_test WHERE x = 1';
			}
			return $query;
		};
		add_filter( 'query', $filter_callback );
		$wpdb->suppress_errors( true );

		try {
			// ACT: Attempt to delete the session.
			$deleted = $this->repository->delete_session( $session_id );

			// ASSERT: The repository reports no deletion.
			$this->assertFalse( $deleted );

			// ASSERT: A SESSION_DELETE_FAILED error event was emitted with the
			// session ID, the snapshotted source_site_url, and a non-empty
			// wpdb_error string.
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_DELETE_FAILED',
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( 'error', $events[0]['level'] );
			$this->assertSame( $session_id, $events[0]['data']['session_id'] );
			$this->assertSame( 'https://example.com', $events[0]['data']['source_site_url'] );
			$this->assertNotEmpty( $events[0]['data']['wpdb_error'] );

			// ASSERT: No success event was recorded.
			$success_events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_DELETED',
				)
			);
			$this->assertCount( 0, $success_events );

			// ASSERT: The session row was not deleted (the bail-out preserves
			// it so the caller can retry).
			$session = $this->repository->get_session( $session_id );
			$this->assertNotNull( $session );
		} finally {
			remove_filter( 'query', $filter_callback );
			$wpdb->suppress_errors( false );
		}
	}

	/**
	 * Verifies that a session deletion whose imports-table DELETE fails at the
	 * SQL layer (after the items-table DELETE has already succeeded) emits a
	 * SESSION_DELETE_FAILED audit event.
	 */
	public function test_delete_session_emits_failed_when_imports_delete_errors(): void {
		global $wpdb;

		// ARRANGE: Create a session with one item, then force the next DELETE
		// on the imports table to fail at the SQL layer. The items-table
		// DELETE runs first and succeeds.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id    = $this->factory()->post->create( array( 'post_title' => 'Imported Post' ) );
		$this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);
		$imports_table   = Imports_Table::table_name();
		$filter_callback = function ( $query ) use ( $imports_table ) {
			if ( 0 === strpos( $query, "DELETE FROM `{$imports_table}`" ) ) {
				return 'DELETE FROM safe_publish_nonexistent_table_for_test WHERE x = 1';
			}
			return $query;
		};
		add_filter( 'query', $filter_callback );
		$wpdb->suppress_errors( true );

		try {
			// ACT: Attempt to delete the session.
			$deleted = $this->repository->delete_session( $session_id );

			// ASSERT: The repository reports no deletion.
			$this->assertFalse( $deleted );

			// ASSERT: A SESSION_DELETE_FAILED error event was emitted with the
			// session ID, the snapshotted source_site_url, and a non-empty
			// wpdb_error string.
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_DELETE_FAILED',
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( 'error', $events[0]['level'] );
			$this->assertSame( $session_id, $events[0]['data']['session_id'] );
			$this->assertSame( 'https://example.com', $events[0]['data']['source_site_url'] );
			$this->assertNotEmpty( $events[0]['data']['wpdb_error'] );

			// ASSERT: No success event was recorded.
			$success_events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_DELETED',
				)
			);
			$this->assertCount( 0, $success_events );
		} finally {
			remove_filter( 'query', $filter_callback );
			$wpdb->suppress_errors( false );
		}
	}
}
