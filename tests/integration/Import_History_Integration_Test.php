<?php
/**
 * Integration tests for import history tracking
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Session_Formatter;
use Safe_Publish\Utils\Imports_Table;

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
		$this->assertIsArray( $session );

		// ASSERT: Session row was stored correctly.
		$this->assertSame( 'https://example.com', $session['source_url'] );
		$this->assertSame( 'bulk', $session['session_type'] );
		$this->assertSame( 'in_progress', $session['status'] );
	}

	/**
	 * Verifies that logging import actions creates item rows.
	 */
	public function test_logging_import_actions_creates_item_rows(): void {
		// ARRANGE: Create session.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// ACT: Log successful import.
		$item_id = $this->repository->log_import_action(
			$session_id,
			123,
			'Test Post',
			'success',
			456,
			null,
			array()
		);

		// ASSERT: Item created.
		$this->assertIsInt( $item_id );
		$this->assertGreaterThan( 0, $item_id );

		$item = $this->repository->get_item( $item_id );
		$this->assertIsArray( $item );
		$this->assertSame( 'Test Post', $item['title'] );

		// ASSERT: Item columns were stored correctly.
		$this->assertSame( 'success', $item['status'] );
		$this->assertSame( 123, (int) $item['external_id'] );
		$this->assertSame( $session_id, (int) $item['session_id'] );
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
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 2, (int) $session['successful'] ); // success + updated.
		$this->assertSame( 1, (int) $session['updated'] );
		$this->assertSame( 1, (int) $session['failed'] );
		$this->assertSame( 3, (int) $session['total_items'] );
	}

	/**
	 * Verifies that session completion marks session as complete.
	 */
	public function test_complete_session_updates_status(): void {
		// ARRANGE: Create session.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// ACT: Complete the session.
		$this->repository->complete_session( $session_id );

		// ASSERT: Status is completed and end_time is set.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 'completed', $session['status'] );
		$this->assertNotEmpty( $session['end_time'] );
	}

	/**
	 * Verifies that retrieving session items returns correct items.
	 */
	public function test_retrieve_session_items_returns_correct_items(): void {
		// ARRANGE: Create session and items.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$this->repository->log_import_action( $session_id, 1, 'Post 1', 'success', 101 );
		$this->repository->log_import_action( $session_id, 2, 'Post 2', 'success', 102 );
		$this->repository->log_import_action( $session_id, 3, 'Post 3', 'error', null, 'Failed' );

		// ACT: Retrieve items.
		$items = $this->repository->get_session_items( $session_id );

		// ASSERT: All items retrieved with correct titles and statuses.
		$this->assertCount( 3, $items );
		$this->assertSame( 'Post 1', $items[0]['title'] );
		$this->assertSame( 'Post 2', $items[1]['title'] );
		$this->assertSame( 'Post 3', $items[2]['title'] );
		$this->assertSame( 'success', $items[0]['status'] );
		$this->assertSame( 'success', $items[1]['status'] );
		$this->assertSame( 'error', $items[2]['status'] );
	}

	/**
	 * Verifies that retrieving items by status filters correctly.
	 */
	public function test_retrieve_items_by_status_filters_correctly(): void {
		// ARRANGE: Create items with different statuses.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$this->repository->log_import_action( $session_id, 1, 'Success 1', 'success', 101 );
		$this->repository->log_import_action( $session_id, 2, 'Success 2', 'success', 102 );
		$this->repository->log_import_action( $session_id, 3, 'Error 1', 'error', null, 'Failed' );

		// ACT: Retrieve only successful items.
		$success_items = $this->repository->get_session_items_by_status( $session_id, array( 'success' ) );

		// ASSERT: Only success items returned.
		$this->assertCount( 2, $success_items );
		foreach ( $success_items as $item ) {
			$this->assertSame( 'success', $item['status'] );
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

	/**
	 * Verifies that Imports_Table::count() reflects the number of session rows.
	 */
	public function test_imports_table_count_reflects_session_rows(): void {
		// ARRANGE: Empty table.
		$this->assertSame( 0, Imports_Table::count() );

		// ACT: Create three sessions.
		$this->repository->create_session( 'https://a.example.com', 'bulk' );
		$this->repository->create_session( 'https://b.example.com', 'single' );
		$this->repository->create_session( 'https://c.example.com', 'bulk' );

		// ASSERT: Count matches the number of inserted rows.
		$this->assertSame( 3, Imports_Table::count() );
	}
}
