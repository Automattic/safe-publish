<?php
/**
 * Integration tests for import history tracking
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Utils\Imports_Table;

/**
 * Import History Test Class.
 */
class Import_History_Test extends Integration_Test_Case {

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Set up test environment.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new History_Repository();
	}

	/**
	 * Verifies that complete import session workflow succeeds.
	 */
	public function test_complete_import_session_workflow_succeeds(): void {
		// ARRANGE: Create a test session.
		$source_site_url = 'https://example.com';

		// ACT: Create session.
		$session_id = $this->repository->create_session( $source_site_url, 'bulk' );

		// ASSERT: Session created successfully.
		$this->assertIsInt( $session_id );
		$this->assertGreaterThan( 0, $session_id );

		$session = $this->repository->get_session( $session_id );
		$this->assertIsArray( $session );

		// ASSERT: Session row was stored correctly.
		$this->assertSame( 'https://example.com', $session['source_site_url'] );
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
		$this->assertSame( 123, (int) $item['source_post_id'] );
		$this->assertSame( $session_id, (int) $item['session_id'] );
	}

	/**
	 * Verifies that unencodable changes do not create an empty history row.
	 */
	public function test_logging_rejects_unencodable_changes(): void {
		// ARRANGE: Create a session and a recursive JSON value.
		$session_id           = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$changes              = array();
		$changes['recursive'] = &$changes;

		// ACT: Attempt to log changes that cannot be JSON encoded.
		$result = $this->repository->log_import_action(
			$session_id,
			123,
			'Unencodable Changes',
			'updated',
			456,
			null,
			$changes
		);

		// ASSERT: The failure is explicit and no misleading item is stored.
		$this->assertWPError( $result );
		$this->assertSame( 'changes_encoding_failed', $result->get_error_code() );
		$this->assertSame(
			array(),
			$this->repository->get_session_items( $session_id )
		);
	}

	/**
	 * Verifies that a null source_post_id is preserved end to end.
	 *
	 * Source data sometimes lacks a usable id (malformed payload or unexpected
	 * exception); the schema must preserve that null instead of forcing a 0
	 * sentinel.
	 */
	public function test_null_source_post_id_round_trips_through_storage(): void {
		// ARRANGE: Create session.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// ACT: Log an error item with null source_post_id (e.g. from
		// build_exception_result when the source payload had no id).
		$item_id = $this->repository->log_import_action(
			$session_id,
			null,
			'Malformed Source',
			'error',
			null,
			'Source data missing id',
			array()
		);

		// ASSERT: Item was created and source_post_id stored as null.
		$this->assertIsInt( $item_id );

		$item = $this->repository->get_item( $item_id );
		$this->assertIsArray( $item );
		$this->assertNull( $item['source_post_id'] );
	}

	/**
	 * Verifies that session counts are projected from logged items.
	 */
	public function test_session_counts_project_from_items(): void {
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

		// ASSERT: Counts are projected from the items table at read time.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 2, (int) $session['successful'] ); // success + updated.
		$this->assertSame( 1, (int) $session['updated'] );
		$this->assertSame( 1, (int) $session['failed'] );
		$this->assertSame( 3, (int) $session['total_items'] );
	}

	/**
	 * Verifies that an empty session reports zero counts.
	 */
	public function test_empty_session_reports_zero_counts(): void {
		// ARRANGE: Create a session with no items.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// ACT: Read the session row.
		$session = $this->repository->get_session( $session_id );

		// ASSERT: All four counters are zero.
		$this->assertSame( 0, (int) $session['total_items'] );
		$this->assertSame( 0, (int) $session['successful'] );
		$this->assertSame( 0, (int) $session['updated'] );
		$this->assertSame( 0, (int) $session['failed'] );
	}

	/**
	 * Verifies that an in-progress session reflects partial item counts.
	 */
	public function test_in_progress_session_reflects_partial_counts(): void {
		// ARRANGE: Create a session and log two items without completing it.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$this->repository->log_import_action( $session_id, 1, 'P1', 'success', 101 );
		$this->repository->log_import_action( $session_id, 2, 'P2', 'error', null, 'fail' );

		// ACT: Read the session row while still in progress.
		$session = $this->repository->get_session( $session_id );

		// ASSERT: Status is unfinished but counters reflect logged items.
		$this->assertSame( 'in_progress', $session['status'] );
		$this->assertSame( 2, (int) $session['total_items'] );
		$this->assertSame( 1, (int) $session['successful'] );
		$this->assertSame( 1, (int) $session['failed'] );
	}

	/**
	 * Verifies that rolled-back items remain in the per-status counters.
	 *
	 * The session-level rollback flow flips the `rolled_back` flag on the items
	 * but leaves their `status` column untouched, so the historical counts must
	 * continue to include them.
	 */
	public function test_rolled_back_items_still_counted_by_status(): void {
		// ARRANGE: Log two successful items and roll one back.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$kept_id        = $this->repository->log_import_action(
			$session_id,
			1,
			'Kept',
			'success',
			101
		);
		$rolled_back_id = $this->repository->log_import_action(
			$session_id,
			2,
			'Rolled back',
			'success',
			102
		);
		$this->assertIsInt( $kept_id );
		$this->assertIsInt( $rolled_back_id );

		$this->repository->mark_item_rolled_back( $rolled_back_id );

		// ACT: Read the session row.
		$session = $this->repository->get_session( $session_id );

		// ASSERT: Both items still count toward `successful`.
		$this->assertSame( 2, (int) $session['total_items'] );
		$this->assertSame( 2, (int) $session['successful'] );

		// ASSERT: The flag landed on the right item.
		$rolled_back_item = $this->repository->get_item( $rolled_back_id );
		$kept_item        = $this->repository->get_item( $kept_id );
		$this->assertSame( 1, (int) $rolled_back_item['rolled_back'] );
		$this->assertSame( 0, (int) $kept_item['rolled_back'] );
	}

	/**
	 * Verifies that completing a session with no items derives status
	 * 'completed'.
	 */
	public function test_complete_session_updates_status(): void {
		// ARRANGE: Create session.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// ACT: Complete the session.
		$this->repository->complete_session( $session_id );

		// ASSERT: Status is completed and ended_at_gmt is set.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 'completed', $session['status'] );
		$this->assertIsString( $session['ended_at_gmt'] );
		$this->assertNotSame( '', $session['ended_at_gmt'] );
	}

	/**
	 * Verifies that a session whose items all succeeded derives status
	 * 'completed'.
	 */
	public function test_complete_session_all_succeeded_is_completed(): void {
		// ARRANGE: Log only success/updated items.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$this->repository->log_import_action( $session_id, 1, 'P1', 'success', 101 );
		$this->repository->log_import_action( $session_id, 2, 'P2', 'updated', 102 );

		// ACT: Complete the session.
		$this->repository->complete_session( $session_id );

		// ASSERT: Status derives to completed.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 'completed', $session['status'] );
	}

	/**
	 * Verifies that a session mixing succeeded and failed items derives status
	 * 'partial'.
	 */
	public function test_complete_session_mixed_is_partial(): void {
		// ARRANGE: Log a mix of success/updated and error items.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$this->repository->log_import_action( $session_id, 1, 'P1', 'success', 101 );
		$this->repository->log_import_action( $session_id, 2, 'P2', 'updated', 102 );
		$this->repository->log_import_action( $session_id, 3, 'P3', 'error', null, 'Failed' );

		// ACT: Complete the session.
		$this->repository->complete_session( $session_id );

		// ASSERT: Status derives to partial.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 'partial', $session['status'] );
	}

	/**
	 * Verifies that a session whose items all failed derives status 'failed'.
	 */
	public function test_complete_session_all_failed_is_failed(): void {
		// ARRANGE: Log only error items.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$this->repository->log_import_action( $session_id, 1, 'P1', 'error', null, 'Failed 1' );
		$this->repository->log_import_action( $session_id, 2, 'P2', 'error', null, 'Failed 2' );

		// ACT: Complete the session.
		$this->repository->complete_session( $session_id );

		// ASSERT: Status derives to failed.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 'failed', $session['status'] );
	}

	/**
	 * Verifies that timestamps are written as GMT regardless of the site's
	 * configured timezone, so the API contract stays browser-friendly.
	 */
	public function test_timestamps_are_stored_in_gmt(): void {
		// ARRANGE: Configure the site to a non-UTC timezone.
		update_option( 'timezone_string', 'America/New_York' );
		$now = time();

		// ACT: Create a session, an item, and complete the session.
		$sid = $this->repository->create_session( 'https://example.com', 'bulk' );
		$this->repository->log_import_action( $sid, 1, 'P', 'success', 1 );
		$this->repository->complete_session( $sid );

		// ASSERT: All three timestamps parse as GMT (within a small tolerance)
		// — i.e. they are NOT shifted by the site's UTC offset.
		$session = $this->repository->get_session( $sid );
		$items   = $this->repository->get_session_items( $sid );

		$created = strtotime( $session['created_at_gmt'] . ' UTC' );
		$ended   = strtotime( $session['ended_at_gmt'] . ' UTC' );
		$dated   = strtotime( $items[0]['import_date_gmt'] . ' UTC' );

		$this->assertEqualsWithDelta( $now, $created, 5 );
		$this->assertEqualsWithDelta( $now, $ended, 5 );
		$this->assertEqualsWithDelta( $now, $dated, 5 );

		// CLEANUP.
		delete_option( 'timezone_string' );
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
