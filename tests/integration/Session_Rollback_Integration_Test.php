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
		$this->assertInstanceOf( \WP_Error::class, $result );
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
		$this->assertInstanceOf( \WP_Error::class, $result );
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
	 * SESSION_ROLLBACK_NOOP event instead of SESSION_ROLLED_BACK.
	 */
	public function test_mark_session_rolled_back_emits_noop_when_no_row_changed(): void {
		// ARRANGE: Session that is already in the rolled_back state.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$this->repository->mark_session_rolled_back( $session_id );
		Audit_Log_Table::clear( 'import' );

		// ACT: Mark the session as rolled back again.
		$this->repository->mark_session_rolled_back( $session_id );

		// ASSERT: A SESSION_ROLLBACK_NOOP event was emitted, not a
		// SESSION_ROLLED_BACK event.
		$noop_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_ROLLBACK_NOOP',
			)
		);
		$this->assertCount( 1, $noop_events );
		$this->assertSame( 'info', $noop_events[0]['level'] );
		$this->assertSame(
			$session_id,
			$noop_events[0]['data']['session_id']
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

		// ASSERT: One ITEM_ROLLED_BACK event was emitted with the item ID in
		// its payload.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'info', $events[0]['level'] );
		$this->assertSame( $item_id, $events[0]['data']['item_id'] );
	}

	/**
	 * Verifies that marking an already-rolled-back item emits an
	 * ITEM_ROLLBACK_NOOP event instead of ITEM_ROLLED_BACK.
	 */
	public function test_mark_item_rolled_back_emits_noop_when_no_row_changed(): void {
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

		// ASSERT: An ITEM_ROLLBACK_NOOP event was emitted, not an
		// ITEM_ROLLED_BACK event.
		$noop_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ROLLBACK_NOOP',
			)
		);
		$this->assertCount( 1, $noop_events );
		$this->assertSame( 'info', $noop_events[0]['level'] );
		$this->assertSame( $item_id, $noop_events[0]['data']['item_id'] );

		$success_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ROLLED_BACK',
			)
		);
		$this->assertCount( 0, $success_events );
	}
}
