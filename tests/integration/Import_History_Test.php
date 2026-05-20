<?php
/**
 * Integration tests for import history tracking
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Session_Formatter;
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
	 * Verifies that a null source_post_id round-trips through storage and the
	 * formatter. Source data sometimes lacks a usable id (malformed payload
	 * or unexpected exception); the schema and API surface must preserve
	 * that null instead of forcing a 0 sentinel.
	 */
	public function test_null_source_post_id_round_trips_through_formatter(): void {
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

		// ASSERT: Formatter exposes source_post_id as null at the API boundary.
		$formatted = $this->formatter->format_item( $item );
		$this->assertNull( $formatted['source_post_id'] );
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
	 * Verifies that session completion marks session as complete.
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
	 * Verifies that session formatter formats data correctly.
	 */
	public function test_session_formatter_formats_session_data(): void {
		// ARRANGE: Create and complete session.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$this->repository->log_import_action( $session_id, 1, 'Post 1', 'success', 101 );
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

		// ASSERT: Date is ISO 8601 with explicit UTC marker so JS parses it
		// in browser-local time, not as site-local.
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$formatted_session['date']
		);
	}

	/**
	 * Verifies that get_sessions() projects counts independently per session,
	 * including for sessions that have no items.
	 */
	public function test_get_sessions_projects_counts_per_session(): void {
		// ARRANGE: Create three sessions with different item populations.
		$session_a = $this->repository->create_session(
			'https://a.example.com',
			'bulk'
		);
		$session_b = $this->repository->create_session(
			'https://b.example.com',
			'bulk'
		);
		$this->repository->create_session( 'https://c.example.com', 'bulk' );

		$this->repository->log_import_action( $session_a, 1, 'A1', 'success', 101 );
		$this->repository->log_import_action( $session_a, 2, 'A2', 'updated', 102 );
		$this->repository->log_import_action(
			$session_b,
			3,
			'B1',
			'error',
			null,
			'fail'
		);

		// ACT: Fetch all sessions.
		$sessions = $this->repository->get_sessions( 50 );

		// ASSERT: All three sessions returned with independent per-row counts.
		$this->assertCount( 3, $sessions );

		$by_url = array();
		foreach ( $sessions as $row ) {
			$by_url[ (string) $row['source_site_url'] ] = $row;
		}

		$this->assertSame( 2, (int) $by_url['https://a.example.com']['total_items'] );
		$this->assertSame( 2, (int) $by_url['https://a.example.com']['successful'] );
		$this->assertSame( 1, (int) $by_url['https://a.example.com']['updated'] );
		$this->assertSame( 0, (int) $by_url['https://a.example.com']['failed'] );

		$this->assertSame( 1, (int) $by_url['https://b.example.com']['total_items'] );
		$this->assertSame( 0, (int) $by_url['https://b.example.com']['successful'] );
		$this->assertSame( 0, (int) $by_url['https://b.example.com']['updated'] );
		$this->assertSame( 1, (int) $by_url['https://b.example.com']['failed'] );

		$this->assertSame( 0, (int) $by_url['https://c.example.com']['total_items'] );
		$this->assertSame( 0, (int) $by_url['https://c.example.com']['successful'] );
		$this->assertSame( 0, (int) $by_url['https://c.example.com']['updated'] );
		$this->assertSame( 0, (int) $by_url['https://c.example.com']['failed'] );
	}

	/**
	 * Verifies that format_items() surfaces rolled-back items so the session
	 * details modal can render them with the right per-item state.
	 *
	 * Error items remain ineligible for rollback and must not be flagged.
	 */
	public function test_format_items_returns_rolled_back_session_items(): void {
		// ARRANGE: Real WP posts back the success/updated items so can_rollback
		// would otherwise return true; the only thing keeping it false is the
		// is_rolled_back flag.
		$success_post = $this->factory()->post->create();
		$updated_post = $this->factory()->post->create();

		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);

		$success_id = $this->repository->log_import_action(
			$session_id,
			1,
			'Success Post',
			'success',
			$success_post
		);
		$updated_id = $this->repository->log_import_action(
			$session_id,
			2,
			'Updated Post',
			'updated',
			$updated_post
		);
		$error_id   = $this->repository->log_import_action(
			$session_id,
			3,
			'Failed Post',
			'error',
			null,
			'Import failed'
		);

		$this->repository->complete_session( $session_id );
		$this->repository->mark_session_rolled_back( $session_id );

		// ACT: Format the items as the AJAX handler would.
		$items     = $this->repository->get_session_items( $session_id );
		$formatted = $this->formatter->format_items( $items );

		// ASSERT: All items are returned.
		$this->assertCount( 3, $formatted );

		$by_id = array();
		foreach ( $formatted as $item ) {
			$by_id[ $item['id'] ] = $item;
		}

		// ASSERT: Success/updated items are flagged; error item is not.
		$this->assertTrue( $by_id[ $success_id ]['is_rolled_back'] );
		$this->assertTrue( $by_id[ $updated_id ]['is_rolled_back'] );
		$this->assertFalse( $by_id[ $error_id ]['is_rolled_back'] );

		// ASSERT: Rolled-back items cannot be rolled back again.
		$this->assertFalse( $by_id[ $success_id ]['can_rollback'] );
		$this->assertFalse( $by_id[ $updated_id ]['can_rollback'] );
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
