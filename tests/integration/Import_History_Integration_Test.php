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
		// ARRANGE: Create a test session.
		$source_url = 'https://example.com';

		// ACT: Create session.
		$session_id = $this->repository->create_session( $source_url, 'bulk' );

		// ASSERT: Session created successfully.
		$this->assertIsInt( $session_id );
		$this->assertGreaterThan( 0, $session_id );

		$session = $this->repository->get_session( $session_id );
		$this->assertNotNull( $session );
		$this->assertSame( 'sp_import_session', $session->post_type );

		// ASSERT: Session meta was stored correctly.
		$this->assertSame( 'https://example.com', get_post_meta( $session->ID, 'source_url', true ) );
		$this->assertSame( 'bulk', get_post_meta( $session->ID, 'session_type', true ) );
		$this->assertSame( 'in_progress', get_post_meta( $session->ID, 'status', true ) );
	}

	/**
	 * Verifies that logging import actions creates log entries.
	 */
	public function test_logging_import_actions_creates_log_entries(): void {
		// ARRANGE: Create session.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// ACT: Log successful import.
		$log_id = $this->repository->log_import_action(
			$session_id,
			123,
			'Test Post',
			'success',
			456,
			null,
			array()
		);

		// ASSERT: Log created.
		$this->assertIsInt( $log_id );
		$this->assertGreaterThan( 0, $log_id );

		$log = $this->repository->get_log( $log_id );
		$this->assertNotNull( $log );
		$this->assertSame( 'sp_import_log', $log->post_type );
		$this->assertSame( 'Test Post', $log->post_title );

		// ASSERT: Log meta was stored correctly.
		$this->assertSame( 'success', get_post_meta( $log->ID, 'status', true ) );
		$this->assertSame( 123, (int) get_post_meta( $log->ID, 'external_id', true ) );
		$this->assertSame( $session_id, (int) get_post_meta( $log->ID, 'session_id', true ) );
	}

	/**
	 * Verifies that session stats are updated correctly.
	 */
	public function test_session_stats_update_correctly(): void {
		// ARRANGE: Create session and log multiple actions.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// ACT: Log various import outcomes.
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

		// ASSERT: Stats updated correctly.
		$session    = $this->repository->get_session( $session_id );
		$successful = (int) get_post_meta( $session->ID, 'successful', true );
		$updated    = (int) get_post_meta( $session->ID, 'updated', true );
		$failed     = (int) get_post_meta( $session->ID, 'failed', true );
		$total      = (int) get_post_meta( $session->ID, 'total_items', true );

		$this->assertSame( 2, $successful ); // Both 'success' and 'updated' count as successful.
		$this->assertSame( 1, $updated );
		$this->assertSame( 1, $failed );
		$this->assertSame( 3, $total );
	}

	/**
	 * Verifies that session completion marks session as complete.
	 */
	public function test_complete_session_updates_status(): void {
		// ARRANGE: Create session.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// ACT: Complete the session.
		$this->repository->complete_session( $session_id );

		// ASSERT: Status is completed.
		$status = get_post_meta( $session_id, 'status', true );
		$this->assertSame( 'completed', $status );

		$end_time = get_post_meta( $session_id, 'end_time', true );
		$this->assertNotEmpty( $end_time );
	}

	/**
	 * Verifies that retrieving session logs returns correct logs.
	 */
	public function test_retrieve_session_logs_returns_correct_logs(): void {
		// ARRANGE: Create session and logs.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// Create multiple logs.
		$this->repository->log_import_action( $session_id, 1, 'Post 1', 'success', 101 );
		$this->repository->log_import_action( $session_id, 2, 'Post 2', 'success', 102 );
		$this->repository->log_import_action( $session_id, 3, 'Post 3', 'error', null, 'Failed' );

		// ACT: Retrieve logs.
		$logs = $this->repository->get_session_logs( $session_id );

		// ASSERT: All logs retrieved with correct titles and statuses.
		$this->assertCount( 3, $logs );
		$this->assertSame( 'Post 1', $logs[0]->post_title );
		$this->assertSame( 'Post 2', $logs[1]->post_title );
		$this->assertSame( 'Post 3', $logs[2]->post_title );
		$this->assertSame( 'success', get_post_meta( $logs[0]->ID, 'status', true ) );
		$this->assertSame( 'success', get_post_meta( $logs[1]->ID, 'status', true ) );
		$this->assertSame( 'error', get_post_meta( $logs[2]->ID, 'status', true ) );
	}

	/**
	 * Verifies that retrieving logs by status filters correctly.
	 */
	public function test_retrieve_logs_by_status_filters_correctly(): void {
		// ARRANGE: Create logs with different statuses.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$this->repository->log_import_action( $session_id, 1, 'Success 1', 'success', 101 );
		$this->repository->log_import_action( $session_id, 2, 'Success 2', 'success', 102 );
		$this->repository->log_import_action( $session_id, 3, 'Error 1', 'error', null, 'Failed' );

		// ACT: Retrieve only successful logs.
		$success_logs = $this->repository->get_session_logs_by_status( $session_id, array( 'success' ) );

		// ASSERT: Only success logs returned.
		$this->assertCount( 2, $success_logs );
		foreach ( $success_logs as $log ) {
			$status = get_post_meta( $log->ID, 'status', true );
			$this->assertSame( 'success', $status );
		}
	}

	/**
	 * Verifies that session formatter formats data correctly.
	 */
	public function test_session_formatter_formats_session_data(): void {
		// ARRANGE: Create and complete session.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$this->repository->log_import_action( $session_id, 1, 'Post 1', 'success', 101 );
		$this->repository->update_session_stats( $session_id, 'success' );
		$this->repository->complete_session( $session_id );

		// ACT: Format session.
		$session           = $this->repository->get_session( $session_id );
		$formatted_session = $this->formatter->format_session( $session );

		// ASSERT: Formatted correctly.
		$this->assertArrayHasKey( 'id', $formatted_session );
		$this->assertArrayHasKey( 'status', $formatted_session );
		$this->assertArrayHasKey( 'total_items', $formatted_session );
		$this->assertArrayHasKey( 'successful', $formatted_session );
		$this->assertSame( $session_id, $formatted_session['id'] );
		$this->assertSame( 'completed', $formatted_session['status'] );
		$this->assertSame( 1, $formatted_session['total_items'] );
		$this->assertSame( 1, $formatted_session['successful'] );
	}
}
