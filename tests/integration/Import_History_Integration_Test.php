<?php
/**
 * Integration tests for import history tracking
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Session_Formatter;

/**
 * Import History Integration Test Class.
 *
 * Tests the complete history tracking workflow end-to-end.
 */
class Import_History_Integration_Test extends Integration_Test_Case {

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Session formatter instance.
	 *
	 * @var Session_Formatter
	 */
	private Session_Formatter $formatter;

	/**
	 * Set up test environment.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new History_Repository();
		$this->formatter  = new Session_Formatter();
	}

	/**
	 * Verifies that complete import session workflow succeeds.
	 */
	public function test_complete_import_session_workflow_succeeds(): void {
		// Arrange - Create a test session.
		$source_url = 'https://example.com';

		// Act - Create session.
		$session_id = $this->repository->create_session( $source_url, 'bulk' );

		// Assert - Session created successfully.
		$this->assertIsInt( $session_id );
		$this->assertGreaterThan( 0, $session_id );

		$session = $this->repository->get_session( $session_id );
		$this->assertNotNull( $session );
		$this->assertEquals( 'sp_import_session', $session->post_type );
	}

	/**
	 * Verifies that logging import actions creates log entries.
	 */
	public function test_logging_import_actions_creates_log_entries(): void {
		// Arrange - Create session.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// Act - Log successful import.
		$log_id = $this->repository->log_import_action(
			$session_id,
			123,
			'Test Post',
			'success',
			456,
			null,
			array()
		);

		// Assert - Log created.
		$this->assertIsInt( $log_id );
		$this->assertGreaterThan( 0, $log_id );

		$log = $this->repository->get_log( $log_id );
		$this->assertNotNull( $log );
		$this->assertEquals( 'sp_import_log', $log->post_type );
		$this->assertEquals( 'Test Post', $log->post_title );
	}

	/**
	 * Verifies that session stats are updated correctly.
	 */
	public function test_session_stats_update_correctly(): void {
		// Arrange - Create session and log multiple actions.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// Act - Log various import outcomes.
		$this->repository->log_import_action(
			$session_id,
			1,
			'Success Post',
			'success',
			101
		);

		$this->repository->log_import_action(
			$session_id,
			2,
			'Updated Post',
			'updated',
			102
		);

		$this->repository->log_import_action(
			$session_id,
			3,
			'Failed Post',
			'error',
			null,
			'Import failed'
		);

		// Update stats.
		$this->repository->update_session_stats( $session_id, 'success' );
		$this->repository->update_session_stats( $session_id, 'updated' );
		$this->repository->update_session_stats( $session_id, 'error' );

		// Assert - Stats updated correctly.
		$session    = $this->repository->get_session( $session_id );
		$successful = (int) get_post_meta( $session->ID, 'successful', true );
		$updated    = (int) get_post_meta( $session->ID, 'updated', true );
		$failed     = (int) get_post_meta( $session->ID, 'failed', true );
		$total      = (int) get_post_meta( $session->ID, 'total_items', true );

		$this->assertEquals( 2, $successful ); // Both 'success' and 'updated' count as successful.
		$this->assertEquals( 1, $updated );
		$this->assertEquals( 1, $failed );
		$this->assertEquals( 3, $total );
	}

	/**
	 * Verifies that session completion marks session as complete.
	 */
	public function test_complete_session_updates_status(): void {
		// Arrange - Create session.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// Act - Complete the session.
		$this->repository->complete_session( $session_id );

		// Assert - Status is completed.
		$status = get_post_meta( $session_id, 'status', true );
		$this->assertEquals( 'completed', $status );

		$end_time = get_post_meta( $session_id, 'end_time', true );
		$this->assertNotEmpty( $end_time );
	}

	/**
	 * Verifies that retrieving session logs returns correct logs.
	 */
	public function test_retrieve_session_logs_returns_correct_logs(): void {
		// Arrange - Create session and logs.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// Create multiple logs.
		$this->repository->log_import_action( $session_id, 1, 'Post 1', 'success', 101 );
		$this->repository->log_import_action( $session_id, 2, 'Post 2', 'success', 102 );
		$this->repository->log_import_action( $session_id, 3, 'Post 3', 'error', null, 'Failed' );

		// Act - Retrieve logs.
		$logs = $this->repository->get_session_logs( $session_id );

		// Assert - All logs retrieved.
		$this->assertCount( 3, $logs );
		$this->assertEquals( 'Post 1', $logs[0]->post_title );
		$this->assertEquals( 'Post 2', $logs[1]->post_title );
		$this->assertEquals( 'Post 3', $logs[2]->post_title );
	}

	/**
	 * Verifies that retrieving logs by status filters correctly.
	 */
	public function test_retrieve_logs_by_status_filters_correctly(): void {
		// Arrange - Create logs with different statuses.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$this->repository->log_import_action( $session_id, 1, 'Success 1', 'success', 101 );
		$this->repository->log_import_action( $session_id, 2, 'Success 2', 'success', 102 );
		$this->repository->log_import_action( $session_id, 3, 'Error 1', 'error', null, 'Failed' );

		// Act - Retrieve only successful logs.
		$success_logs = $this->repository->get_session_logs_by_status( $session_id, array( 'success' ) );

		// Assert - Only success logs returned.
		$this->assertCount( 2, $success_logs );
		foreach ( $success_logs as $log ) {
			$status = get_post_meta( $log->ID, 'status', true );
			$this->assertEquals( 'success', $status );
		}
	}

	/**
	 * Verifies that session formatter formats data correctly.
	 */
	public function test_session_formatter_formats_session_data(): void {
		// Arrange - Create and complete session.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$this->repository->log_import_action( $session_id, 1, 'Post 1', 'success', 101 );
		$this->repository->update_session_stats( $session_id, 'success' );
		$this->repository->complete_session( $session_id );

		// Act - Format session.
		$session           = $this->repository->get_session( $session_id );
		$formatted_session = $this->formatter->format_session( $session );

		// Assert - Formatted correctly.
		$this->assertArrayHasKey( 'id', $formatted_session );
		$this->assertArrayHasKey( 'status', $formatted_session );
		$this->assertArrayHasKey( 'total_items', $formatted_session );
		$this->assertArrayHasKey( 'successful', $formatted_session );
		$this->assertEquals( $session_id, $formatted_session['id'] );
		$this->assertEquals( 'completed', $formatted_session['status'] );
		$this->assertEquals( 1, $formatted_session['total_items'] );
		$this->assertEquals( 1, $formatted_session['successful'] );
	}
}
