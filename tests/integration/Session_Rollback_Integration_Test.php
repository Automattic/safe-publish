<?php
/**
 * Integration tests for session rollback functionality
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Session_Rollback_Service;

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

		// ASSERT: Posts were deleted.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'deleted_count', $result );
		$this->assertSame( 2, $result['deleted_count'] );

		// ASSERT: Verify posts are deleted.
		$this->assertNull( get_post( $post_id_1 ) );
		$this->assertNull( get_post( $post_id_2 ) );

		// ASSERT: Verify session marked as rolled back.
		$status = get_post_meta( $session_id, 'status', true );
		$this->assertSame( 'rolled_back', $status );
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

		$log_id_2 = $this->repository->log_import_action(
			$session_id,
			2,
			'Delete This',
			'success',
			$post_id_2
		);

		// ACT: Rollback only second item.
		$result = $this->rollback_service->rollback_item( $log_id_2 );

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
	 * Verifies that rollback with nonexistent log returns error.
	 */
	public function test_rollback_nonexistent_log_returns_error(): void {
		// ARRANGE: Use nonexistent log ID.
		$fake_log_id = 999999;

		// ACT: Attempt rollback.
		$result = $this->rollback_service->rollback_item( $fake_log_id );

		// ASSERT: Returns WP_Error.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'log_not_found', $result->get_error_code() );
	}

	/**
	 * Verifies that rollback only affects successful/updated imports.
	 */
	public function test_rollback_ignores_failed_imports(): void {
		// ARRANGE: Create session with success and failed imports.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$post_id = $this->factory()->post->create( array( 'post_title' => 'Success' ) );

		// Success log.
		$this->repository->log_import_action(
			$session_id,
			1,
			'Success',
			'success',
			$post_id
		);

		// Failed log (no post created).
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

		// ASSERT: Only successful import rolled back.
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['deleted_count'] );
		$this->assertSame( 0, $result['restored_count'] );
	}
}
