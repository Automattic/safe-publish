<?php
/**
 * Integration tests for the unified Posts listing repository methods.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;

require_once __DIR__ . '/Integration_Test_Case.php';

/**
 * Verifies that the active-row rule produces the expected routing label and
 * the list_*_source_rows methods scope the page correctly.
 */
final class Posts_Source_Rows_Test extends Integration_Test_Case {

	/**
	 * Subject under test.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Test setUp.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->repository = new History_Repository();
	}

	/**
	 * Inserts a session and returns its id.
	 *
	 * @return int Session id.
	 */
	private function create_session(): int {
		$id = $this->repository->create_session( 'https://source.test', 'bulk' );
		return is_int( $id ) ? $id : 0;
	}

	/**
	 * Inserts a single items-table row with arbitrary fields.
	 *
	 * @param array $overrides Field overrides.
	 * @return int Item id.
	 */
	private function insert_item( array $overrides ): int {
		global $wpdb;

		$defaults = array(
			'session_id'           => 0,
			'title'                => 'Test',
			'source_post_id'       => null,
			'status'               => 'success',
			'post_id'              => null,
			'error_message'        => null,
			'content_changes'      => null,
			'warnings'             => null,
			'has_previous_content' => 0,
			'rolled_back'          => 0,
			'import_date_gmt'      => current_time( 'mysql', true ),
			'source_modified_gmt'  => null,
		);

		$row = array_merge( $defaults, $overrides );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Import_Items_Table::table_name(),
			$row,
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Verifies that resolve_source_post_state reports available with no history.
	 */
	public function test_resolves_available_when_no_history_exists(): void {
		// ACT: resolve a source post id that has never been imported.
		$state = $this->repository->resolve_source_post_state( 42 );

		// ASSERT: the resolver returns Available with no history badge.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that the most-recent error row routes to failed.
	 */
	public function test_resolves_failed_when_most_recent_is_error(): void {
		// ARRANGE: a prior success followed by a later error event.
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 101,
				'status'          => 'success',
				'post_id'         => 555,
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 101,
				'status'          => 'error',
				'error_message'   => 'Boom',
				'import_date_gmt' => '2026-02-01 00:00:00',
			)
		);

		// ACT: resolve the source post's routing state.
		$state = $this->repository->resolve_source_post_state( 101 );

		// ASSERT: the most-recent event wins and routes to Failed.
		$this->assertSame( 'failed', $state );
	}

	/**
	 * Verifies that a rolled-back most-recent row routes to available.
	 */
	public function test_resolves_available_when_most_recent_is_rolled_back(): void {
		// ARRANGE: a single success row that was later rolled back.
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 102,
				'status'          => 'success',
				'post_id'         => 600,
				'rolled_back'     => 1,
				'import_date_gmt' => '2026-01-15 00:00:00',
			)
		);

		// ACT: resolve the source post's routing state.
		$state = $this->repository->resolve_source_post_state( 102 );

		// ASSERT: a rolled-back active row folds into Available.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that a deleted-locally source post folds into available.
	 */
	public function test_resolves_available_when_local_post_missing(): void {
		// ARRANGE: a success row pointing at a wp_posts id that doesn't exist.
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 103,
				'status'          => 'success',
				'post_id'         => 999999,
				'import_date_gmt' => '2026-03-01 00:00:00',
			)
		);

		// ACT: resolve the source post's routing state.
		$state = $this->repository->resolve_source_post_state( 103 );

		// ASSERT: a missing local post folds into Available with the
		// deleted_locally badge.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that an imported row that exceeded its source_modified_gmt is
	 * Outdated.
	 */
	public function test_resolves_outdated_when_source_modified_exceeds_import_date(): void {
		// ARRANGE: a local post whose source_modified_gmt is newer than its
		// import_date_gmt.
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'          => $session,
				'source_post_id'      => 104,
				'status'              => 'success',
				'post_id'             => $post_id,
				'import_date_gmt'     => '2026-01-01 00:00:00',
				'source_modified_gmt' => '2026-02-01 00:00:00',
			)
		);

		// ACT: resolve the source post's routing state.
		$state = $this->repository->resolve_source_post_state( 104 );

		// ASSERT: the stored source_modified_gmt drives the Outdated verdict.
		$this->assertSame( 'outdated', $state );
	}

	/**
	 * Verifies that a trashed local post folds into available.
	 */
	public function test_treats_trashed_post_as_deleted_locally(): void {
		// ARRANGE: an imported post whose local copy has been trashed.
		$post_id = self::factory()->post->create();
		wp_trash_post( $post_id );

		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 105,
				'status'          => 'success',
				'post_id'         => $post_id,
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);

		// ACT: resolve the source post's routing state.
		$state = $this->repository->resolve_source_post_state( 105 );

		// ASSERT: trash counts as deleted_locally, not Imported.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that a rolled-back active row folds into Available.
	 */
	public function test_derive_active_state_rolled_back_folds_to_available(): void {
		// ARRANGE: an active row whose rolled_back flag is set.
		$active_row = array(
			'rolled_back' => 1,
			'status'      => 'success',
		);

		// ACT: derive the routing state from the row.
		$state = History_Repository::derive_active_state( $active_row, true );

		// ASSERT: rolled-back rows route to Available regardless of post presence.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that a success row whose local post is gone folds into Available.
	 */
	public function test_derive_active_state_deleted_locally_folds_to_available(): void {
		// ARRANGE: a success row whose local post is no longer present.
		$active_row = array(
			'rolled_back'         => 0,
			'status'              => 'success',
			'post_id'             => 999,
			'import_date_gmt'     => '2026-01-01 00:00:00',
			'source_modified_gmt' => '2026-01-01 00:00:00',
		);

		// ACT: derive the routing state with local_post_present=false.
		$state = History_Repository::derive_active_state( $active_row, false );

		// ASSERT: missing local post routes to Available.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that list_imported_source_rows aggregates by source_post_id.
	 */
	public function test_list_imported_source_rows_returns_one_row_per_source_id(): void {
		// ARRANGE: two events for the same source_post_id; expect one row.
		$post_id = self::factory()->post->create();
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 200,
				'status'          => 'success',
				'post_id'         => $post_id,
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 200,
				'status'          => 'updated',
				'post_id'         => $post_id,
				'import_date_gmt' => '2026-02-01 00:00:00',
			)
		);

		// ACT: list the imported source rows.
		$rows = $this->repository->list_imported_source_rows();

		// ASSERT: the active-row rule collapses the two events to one row,
		// carrying the latest event's status.
		$this->assertCount( 1, $rows );
		$this->assertSame( 200, (int) $rows[0]['source_post_id'] );
		$this->assertSame( 'updated', (string) $rows[0]['status'] );
	}

	/**
	 * Verifies that list_imported_source_rows partitions on the freshness arg.
	 */
	public function test_list_imported_source_rows_freshness_filters_by_stored_modified_time(): void {
		// ARRANGE: one fresh and one stale imported row.
		$fresh_post = self::factory()->post->create();
		$stale_post = self::factory()->post->create();
		$session    = $this->create_session();
		$this->insert_item(
			array(
				'session_id'          => $session,
				'source_post_id'      => 300,
				'status'              => 'success',
				'post_id'             => $fresh_post,
				'import_date_gmt'     => '2026-01-01 00:00:00',
				'source_modified_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'          => $session,
				'source_post_id'      => 301,
				'status'              => 'success',
				'post_id'             => $stale_post,
				'import_date_gmt'     => '2026-01-01 00:00:00',
				'source_modified_gmt' => '2026-03-01 00:00:00',
			)
		);

		// ACT + ASSERT: outdated filter returns only the stale row.
		$outdated = $this->repository->list_imported_source_rows(
			1,
			20,
			array( 'freshness' => 'outdated' )
		);
		$this->assertCount( 1, $outdated );
		$this->assertSame( 301, (int) $outdated[0]['source_post_id'] );

		// ACT + ASSERT: up-to-date filter returns only the fresh row.
		$fresh = $this->repository->list_imported_source_rows(
			1,
			20,
			array( 'freshness' => 'up-to-date' )
		);
		$this->assertCount( 1, $fresh );
		$this->assertSame( 300, (int) $fresh[0]['source_post_id'] );

		// ACT + ASSERT: default (any) returns both — guards the umbrella path.
		$both = $this->repository->list_imported_source_rows( 1, 20 );
		$this->assertCount( 2, $both );
	}

	/**
	 * Verifies that list_imported_source_rows filters by destination slug
	 * when a `name` arg is provided.
	 */
	public function test_list_imported_source_rows_filters_by_post_name(): void {
		// ARRANGE: two imported posts with distinct slugs on the destination.
		$alpha_post = self::factory()->post->create( array( 'post_name' => 'alpha' ) );
		$beta_post  = self::factory()->post->create( array( 'post_name' => 'beta' ) );
		$session    = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 700,
				'status'          => 'success',
				'post_id'         => $alpha_post,
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 701,
				'status'          => 'success',
				'post_id'         => $beta_post,
				'import_date_gmt' => '2026-01-02 00:00:00',
			)
		);

		// ACT + ASSERT: matching slug returns the one corresponding row.
		$matched = $this->repository->list_imported_source_rows(
			1,
			20,
			array( 'name' => 'beta' )
		);
		$this->assertCount( 1, $matched );
		$this->assertSame( 701, (int) $matched[0]['source_post_id'] );

		// ACT + ASSERT: unknown slug returns nothing (rather than the full list).
		$miss = $this->repository->list_imported_source_rows(
			1,
			20,
			array( 'name' => 'gamma' )
		);
		$this->assertCount( 0, $miss );
	}

	/**
	 * Verifies that list_failed_source_rows excludes orphan failures.
	 */
	public function test_list_failed_source_rows_excludes_orphans(): void {
		// ARRANGE: one failed row with a source_post_id, one orphan failure.
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 400,
				'status'          => 'error',
				'error_message'   => 'Source-known failure',
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => null,
				'status'          => 'error',
				'error_message'   => 'Orphan failure',
				'import_date_gmt' => '2026-01-02 00:00:00',
			)
		);

		// ACT: list the failed source rows.
		$rows = $this->repository->list_failed_source_rows();

		// ASSERT: orphans are excluded; the source-known row passes through.
		$this->assertCount( 1, $rows );
		$this->assertSame( 400, (int) $rows[0]['source_post_id'] );
	}

	/**
	 * Verifies that count_orphan_failures only counts rows without
	 * a source_post_id.
	 */
	public function test_count_orphan_failures_only_counts_orphans(): void {
		// ARRANGE: one source-known failure plus two orphans.
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 500,
				'status'          => 'error',
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => null,
				'status'          => 'error',
				'import_date_gmt' => '2026-01-02 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => null,
				'status'          => 'error',
				'import_date_gmt' => '2026-01-03 00:00:00',
			)
		);

		// ACT: count the orphan failures.
		$count = $this->repository->count_orphan_failures();

		// ASSERT: the count covers only the two orphans, not the
		// source-known failure.
		$this->assertSame( 2, $count );
	}

	/**
	 * Verifies that delete_failed_items removes error rows regardless of
	 * source_post_id but leaves non-error rows untouched.
	 */
	public function test_delete_failed_items_removes_error_rows_but_not_others(): void {
		// ARRANGE: one error row with a source_post_id, one orphan error, one
		// non-error row that should be left alone.
		$session     = $this->create_session();
		$source_err  = $this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 600,
				'status'          => 'error',
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$orphan_err  = $this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => null,
				'status'          => 'error',
				'import_date_gmt' => '2026-01-02 00:00:00',
			)
		);
		$success_row = $this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 601,
				'status'          => 'success',
				'post_id'         => 1,
				'import_date_gmt' => '2026-01-03 00:00:00',
			)
		);

		// ACT: try to delete all three.
		$deleted = $this->repository->delete_failed_items(
			array( $source_err, $orphan_err, $success_row )
		);

		// ASSERT: both error rows go; the success row is untouched.
		$this->assertSame( 2, $deleted );
		$this->assertNull( $this->repository->get_item( $source_err ) );
		$this->assertNull( $this->repository->get_item( $orphan_err ) );
		$this->assertNotNull( $this->repository->get_item( $success_row ) );
	}

	/**
	 * Verifies that the source_post_ids path clears every failure attempt for
	 * the source — without it, the listing's deduped row resurfaces an older
	 * sibling on refresh.
	 */
	public function test_delete_failed_items_by_source_clears_all_attempts(): void {
		// ARRANGE: two failure rows for the same source_post_id, plus an
		// unrelated failure that must be left alone.
		$session = $this->create_session();
		$older   = $this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 800,
				'status'          => 'error',
				'import_date_gmt' => '2026-02-01 00:00:00',
			)
		);
		$newer   = $this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 800,
				'status'          => 'error',
				'import_date_gmt' => '2026-02-02 00:00:00',
			)
		);
		$other   = $this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 801,
				'status'          => 'error',
				'import_date_gmt' => '2026-02-03 00:00:00',
			)
		);

		// ACT: dismiss source 800 — both its attempts should go.
		$deleted = $this->repository->delete_failed_items(
			array(),
			array( 800 )
		);

		// ASSERT: both 800 rows gone; 801 untouched.
		$this->assertSame( 2, $deleted );
		$this->assertNull( $this->repository->get_item( $older ) );
		$this->assertNull( $this->repository->get_item( $newer ) );
		$this->assertNotNull( $this->repository->get_item( $other ) );
	}

	/**
	 * Verifies that update_source_modified_gmt_bulk fans out updates correctly.
	 */
	public function test_update_source_modified_gmt_bulk_writes_each_row(): void {
		// ARRANGE: two imported rows with stale source_modified_gmt values.
		$session = $this->create_session();
		$item_a  = $this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 700,
				'status'          => 'success',
				'post_id'         => 1,
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$item_b  = $this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 701,
				'status'          => 'success',
				'post_id'         => 2,
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);

		// ACT: flush a batch update with one value per item.
		$this->repository->update_source_modified_gmt_bulk(
			array(
				$item_a => '2026-04-01 00:00:00',
				$item_b => '2026-05-01 00:00:00',
			)
		);

		// ASSERT: each row picks up its mapped value via the CASE-WHEN UPDATE.
		$row_a = $this->repository->get_item( $item_a );
		$row_b = $this->repository->get_item( $item_b );
		$this->assertNotNull( $row_a );
		$this->assertNotNull( $row_b );
		$this->assertSame( '2026-04-01 00:00:00', (string) $row_a['source_modified_gmt'] );
		$this->assertSame( '2026-05-01 00:00:00', (string) $row_b['source_modified_gmt'] );
	}
}
