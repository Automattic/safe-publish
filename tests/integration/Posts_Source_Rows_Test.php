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
	 * Source identity most sessions are created under.
	 */
	private const SOURCE = 'https://source.test';

	/**
	 * A second source, for the cross-source scoping tests.
	 */
	private const OTHER_SOURCE = 'https://other.example.com';

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
	 * @param string $source_site_url Source the session imports from.
	 * @return int Session id.
	 */
	private function create_session(
		string $source_site_url = self::SOURCE
	): int {
		$id = $this->repository->create_session( $source_site_url, 'bulk' );
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
		// ACT: Resolve a source post id that has never been imported.
		$state = $this->repository->resolve_source_post_state( 42 );

		// ASSERT: The resolver returns Available with no history badge.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that a success followed by a newer error resolves to the
	 * success row's state, not failed.
	 */
	public function test_resolves_success_state_when_a_newer_row_is_error(): void {
		// ARRANGE: A real imported post whose success row is followed by a
		// newer error attempt for the same source.
		$post_id = self::factory()->post->create();
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 101,
				'status'          => 'success',
				'post_id'         => $post_id,
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

		// ACT: Resolve the source post's routing state.
		$state = $this->repository->resolve_source_post_state( 101 );

		// ASSERT: The latest non-error row wins; the error never routes Posts.
		$this->assertSame( 'up-to-date', $state );
	}

	/**
	 * Verifies that a source whose only row is an error resolves to available.
	 */
	public function test_resolves_available_when_only_row_is_error(): void {
		// ARRANGE: A single error row with no prior success for the source.
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 106,
				'status'          => 'error',
				'error_message'   => 'First-import failure',
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);

		// ACT: Resolve the source post's routing state.
		$state = $this->repository->resolve_source_post_state( 106 );

		// ASSERT: An error-only source is not imported; it stays available.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that a rolled-back most-recent row routes to available.
	 */
	public function test_resolves_available_when_most_recent_is_rolled_back(): void {
		// ARRANGE: A single success row that was later rolled back.
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

		// ACT: Resolve the source post's routing state.
		$state = $this->repository->resolve_source_post_state( 102 );

		// ASSERT: A rolled-back active row folds into Available.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that a deleted-locally source post folds into available.
	 */
	public function test_resolves_available_when_local_post_missing(): void {
		// ARRANGE: A success row pointing at a wp_posts id that doesn't exist.
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

		// ACT: Resolve the source post's routing state.
		$state = $this->repository->resolve_source_post_state( 103 );

		// ASSERT: A missing local post folds into Available with the
		// deleted_locally badge.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that an imported row that exceeded its source_modified_gmt is
	 * Outdated.
	 */
	public function test_resolves_outdated_when_source_modified_exceeds_import_date(): void {
		// ARRANGE: A local post whose source_modified_gmt is newer than its
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

		// ACT: Resolve the source post's routing state.
		$state = $this->repository->resolve_source_post_state( 104 );

		// ASSERT: The stored source_modified_gmt drives the Outdated verdict.
		$this->assertSame( 'outdated', $state );
	}

	/**
	 * Verifies that a trashed local post folds into available.
	 */
	public function test_treats_trashed_post_as_deleted_locally(): void {
		// ARRANGE: An imported post whose local copy has been trashed.
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

		// ACT: Resolve the source post's routing state.
		$state = $this->repository->resolve_source_post_state( 105 );

		// ASSERT: Trash counts as deleted_locally, not Imported.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that a rolled-back active row folds into Available.
	 */
	public function test_derive_active_state_rolled_back_folds_to_available(): void {
		// ARRANGE: An active row whose rolled_back flag is set.
		$active_row = array(
			'rolled_back' => 1,
			'status'      => 'success',
		);

		// ACT: Derive the routing state from the row.
		$state = History_Repository::derive_active_state( $active_row, true );

		// ASSERT: Rolled-back rows route to Available regardless of post presence.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that a success row whose local post is gone folds into Available.
	 */
	public function test_derive_active_state_deleted_locally_folds_to_available(): void {
		// ARRANGE: A success row whose local post is no longer present.
		$active_row = array(
			'rolled_back'         => 0,
			'status'              => 'success',
			'post_id'             => 999,
			'import_date_gmt'     => '2026-01-01 00:00:00',
			'source_modified_gmt' => '2026-01-01 00:00:00',
		);

		// ACT: Derive the routing state with local_post_present=false.
		$state = History_Repository::derive_active_state( $active_row, false );

		// ASSERT: Missing local post routes to Available.
		$this->assertSame( 'available', $state );
	}

	/**
	 * Verifies that list_imported_source_rows aggregates by source_post_id.
	 */
	public function test_list_imported_source_rows_returns_one_row_per_source_id(): void {
		// ARRANGE: Two events for the same source_post_id; expect one row.
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

		// ACT: List the imported source rows.
		$rows = $this->repository->list_imported_source_rows();

		// ASSERT: The active-row rule collapses the two events to one row,
		// carrying the latest event's status.
		$this->assertCount( 1, $rows );
		$this->assertSame( 200, (int) $rows[0]['source_post_id'] );
		$this->assertSame( 'updated', (string) $rows[0]['status'] );
	}

	/**
	 * Verifies that list_imported_source_rows keeps a success row that has a
	 * newer error sibling, so a failed re-import doesn't hide the post.
	 */
	public function test_list_imported_source_rows_keeps_success_with_newer_error(): void {
		// ARRANGE: An imported post whose later re-import errored.
		$post_id = self::factory()->post->create();
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 950,
				'status'          => 'success',
				'post_id'         => $post_id,
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 950,
				'status'          => 'error',
				'error_message'   => 'Re-import failed',
				'import_date_gmt' => '2026-02-01 00:00:00',
			)
		);

		// ACT: List the imported source rows.
		$rows = $this->repository->list_imported_source_rows();

		// ASSERT: The success row survives its newer error sibling.
		$this->assertCount( 1, $rows );
		$this->assertSame( 950, (int) $rows[0]['source_post_id'] );
		$this->assertSame( 'success', (string) $rows[0]['status'] );
	}

	/**
	 * Verifies that get_active_items_by_source_ids returns the latest non-error
	 * row per source, so a newer error can't become the active row.
	 */
	public function test_get_active_items_by_source_ids_ignores_newer_error(): void {
		// ARRANGE: A success followed by a newer error for the same source.
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 960,
				'status'          => 'success',
				'post_id'         => 111,
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 960,
				'status'          => 'error',
				'import_date_gmt' => '2026-02-01 00:00:00',
			)
		);

		// ACT: Fetch the active rows for the source.
		$active = $this->repository->get_active_items_by_source_ids(
			array( 960 ),
			null
		);

		// ASSERT: The success row is the active row, not the newer error.
		$this->assertArrayHasKey( 960, $active );
		$this->assertSame( 'success', (string) $active[960]['status'] );
	}

	/**
	 * Verifies that get_active_items_by_source_ids returns only the given
	 * source's active row, and keeps its cross-source result when passed null.
	 */
	public function test_get_active_items_by_source_ids_scopes_by_source(): void {
		// ARRANGE: One numeric source post id imported under two sources, the
		// other source's import being the newer one.
		$mine   = $this->create_session();
		$theirs = $this->create_session( self::OTHER_SOURCE );
		$this->insert_item(
			array(
				'session_id'      => $mine,
				'source_post_id'  => 970,
				'status'          => 'success',
				'post_id'         => 111,
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $theirs,
				'source_post_id'  => 970,
				'status'          => 'success',
				'post_id'         => 222,
				'import_date_gmt' => '2026-02-01 00:00:00',
			)
		);

		// ACT: Look the source id up scoped, then unscoped.
		$scoped   = $this->repository->get_active_items_by_source_ids(
			array( 970 ),
			self::SOURCE
		);
		$unscoped = $this->repository->get_active_items_by_source_ids(
			array( 970 ),
			null
		);

		// ASSERT: Scoped resolves this source's row; unscoped resolves the
		// newest across sources, which the Posts tab depends on for now.
		$this->assertSame( 111, (int) $scoped[970]['post_id'] );
		$this->assertSame( 222, (int) $unscoped[970]['post_id'] );
	}

	/**
	 * Verifies that list_imported_source_rows partitions on the freshness arg.
	 */
	public function test_list_imported_source_rows_freshness_filters_by_stored_modified_time(): void {
		// ARRANGE: One fresh and one stale imported row.
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

		// ACT + ASSERT: Outdated filter returns only the stale row.
		$outdated = $this->repository->list_imported_source_rows(
			1,
			20,
			array( 'freshness' => 'outdated' )
		);
		$this->assertCount( 1, $outdated );
		$this->assertSame( 301, (int) $outdated[0]['source_post_id'] );

		// ACT + ASSERT: Up-to-date filter returns only the fresh row.
		$fresh = $this->repository->list_imported_source_rows(
			1,
			20,
			array( 'freshness' => 'up-to-date' )
		);
		$this->assertCount( 1, $fresh );
		$this->assertSame( 300, (int) $fresh[0]['source_post_id'] );

		// ACT + ASSERT: Default (any) returns both — guards the umbrella path.
		$both = $this->repository->list_imported_source_rows( 1, 20 );
		$this->assertCount( 2, $both );
	}

	/**
	 * Verifies that list_imported_source_rows filters by destination slug
	 * when a name arg is provided.
	 */
	public function test_list_imported_source_rows_filters_by_post_name(): void {
		// ARRANGE: Two imported posts with distinct slugs on the destination.
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

		// ACT + ASSERT: Matching slug returns the one corresponding row.
		$matched = $this->repository->list_imported_source_rows(
			1,
			20,
			array( 'name' => 'beta' )
		);
		$this->assertCount( 1, $matched );
		$this->assertSame( 701, (int) $matched[0]['source_post_id'] );

		// ACT + ASSERT: Unknown slug returns nothing (rather than the full list).
		$miss = $this->repository->list_imported_source_rows(
			1,
			20,
			array( 'name' => 'gamma' )
		);
		$this->assertCount( 0, $miss );
	}

	/**
	 * Verifies that list_failures returns both orphan and source-linked
	 * failures.
	 */
	public function test_list_failures_returns_orphan_and_source_linked(): void {
		// ARRANGE: One source-linked failure and one newer orphan failure.
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

		// ACT: List the inbox failures (newest first).
		$rows = $this->repository->list_failures( self::SOURCE, 0, 20 );

		// ASSERT: The orphan and the source-linked row are both listed.
		$this->assertCount( 2, $rows );
		$this->assertNull( $rows[0]['source_post_id'] );
		$this->assertSame( 400, (int) $rows[1]['source_post_id'] );
	}

	/**
	 * Verifies that count_failures counts orphan and source-linked failures.
	 */
	public function test_count_failures_counts_orphan_and_source_linked(): void {
		// ARRANGE: One source-linked failure plus two orphans.
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

		// ACT: Count the inbox failures.
		$count = $this->repository->count_failures( self::SOURCE );

		// ASSERT: Every failure is counted, orphan or source-linked.
		$this->assertSame( 3, $count );
	}

	/**
	 * Verifies that a success followed by a newer error surfaces in
	 * list_failures even though Posts routes the source to a success state.
	 */
	public function test_list_failures_includes_source_hidden_from_posts(): void {
		// ARRANGE: A real imported post whose success is followed by a newer
		// error for the same source.
		$post_id = self::factory()->post->create();
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 900,
				'status'          => 'success',
				'post_id'         => $post_id,
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 900,
				'status'          => 'error',
				'error_message'   => 'Update failed',
				'import_date_gmt' => '2026-02-01 00:00:00',
			)
		);

		// ACT: Resolve the Posts state and list the inbox failures.
		$state = $this->repository->resolve_source_post_state( 900 );
		$rows  = $this->repository->list_failures( self::SOURCE, 0, 20 );

		// ASSERT: Posts hides the failure, but the inbox surfaces it.
		$this->assertSame( 'up-to-date', $state );
		$this->assertCount( 1, $rows );
		$this->assertSame( 900, (int) $rows[0]['source_post_id'] );
	}

	/**
	 * Verifies that a later success removes a source from list_failures.
	 */
	public function test_list_failures_excludes_source_with_later_success(): void {
		// ARRANGE: An error followed by a newer success for the same source.
		$post_id = self::factory()->post->create();
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 901,
				'status'          => 'error',
				'error_message'   => 'First attempt failed',
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 901,
				'status'          => 'success',
				'post_id'         => $post_id,
				'import_date_gmt' => '2026-02-01 00:00:00',
			)
		);

		// ACT: List the inbox failures.
		$rows = $this->repository->list_failures( self::SOURCE, 0, 20 );

		// ASSERT: The resolved source no longer appears as a failure.
		$this->assertCount( 0, $rows );
	}

	/**
	 * Verifies that list_failures and count_failures return only the failures
	 * belonging to the source they are given.
	 */
	public function test_failures_are_scoped_to_the_given_source(): void {
		// ARRANGE: A failure under the connected source, plus a source-linked
		// and an orphan failure under another source.
		$mine   = $this->create_session();
		$theirs = $this->create_session( self::OTHER_SOURCE );
		$this->insert_item(
			array(
				'session_id'      => $mine,
				'source_post_id'  => 910,
				'status'          => 'error',
				'error_message'   => 'Mine',
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $theirs,
				'source_post_id'  => 911,
				'status'          => 'error',
				'error_message'   => 'Theirs',
				'import_date_gmt' => '2026-01-02 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $theirs,
				'source_post_id'  => null,
				'status'          => 'error',
				'error_message'   => 'Their orphan',
				'import_date_gmt' => '2026-01-03 00:00:00',
			)
		);

		// ACT: List and count for the connected source.
		$rows  = $this->repository->list_failures( self::SOURCE, 0, 20 );
		$count = $this->repository->count_failures( self::SOURCE );

		// ASSERT: The other source's rows, orphan included, stay out.
		$this->assertCount( 1, $rows );
		$this->assertSame( 910, (int) $rows[0]['source_post_id'] );
		$this->assertSame( 1, $count );
	}

	/**
	 * Statuses a newer cross-source row can carry, each of which used to
	 * suppress this source's failure.
	 *
	 * @return array<string, array{string}> Status per case.
	 */
	public static function cross_source_newer_status_provider(): array {
		return array(
			'newer success' => array( 'success' ),
			'newer error'   => array( 'error' ),
		);
	}

	/**
	 * Verifies that a newer row for the same numeric source post id under a
	 * different source no longer hides this source's failure.
	 *
	 * @dataProvider cross_source_newer_status_provider
	 *
	 * @param string $other_status Status of the other source's newer row.
	 */
	public function test_cross_source_newer_row_does_not_mask_a_failure(
		string $other_status
	): void {
		// ARRANGE: A failure for source post 960 under one source, and a newer
		// row for the same numeric id under another.
		$mine   = $this->create_session();
		$theirs = $this->create_session( self::OTHER_SOURCE );
		$this->insert_item(
			array(
				'session_id'      => $mine,
				'source_post_id'  => 960,
				'status'          => 'error',
				'error_message'   => 'Mine failed',
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $theirs,
				'source_post_id'  => 960,
				'status'          => $other_status,
				'post_id'         => 'error' === $other_status ? null : 111,
				'import_date_gmt' => '2026-02-01 00:00:00',
			)
		);

		// ACT: List the failures for the first source.
		$rows = $this->repository->list_failures( self::SOURCE, 0, 20 );

		// ASSERT: The dedup only considers the same source, so it still shows.
		$this->assertCount( 1, $rows );
		$this->assertSame( 960, (int) $rows[0]['source_post_id'] );
		$this->assertSame( self::SOURCE, (string) $rows[0]['source_site_url'] );
	}

	/**
	 * Verifies that removing by source post id leaves another source's failure
	 * for the same numeric id in place.
	 */
	public function test_delete_failed_items_by_source_stays_in_scope(): void {
		// ARRANGE: The same numeric source post id fails under two sources.
		$mine   = $this->create_session();
		$theirs = $this->create_session( self::OTHER_SOURCE );
		$ours   = $this->insert_item(
			array(
				'session_id'      => $mine,
				'source_post_id'  => 930,
				'status'          => 'error',
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$other  = $this->insert_item(
			array(
				'session_id'      => $theirs,
				'source_post_id'  => 930,
				'status'          => 'error',
				'import_date_gmt' => '2026-01-02 00:00:00',
			)
		);

		// ACT: Remove source 930 for the connected source only.
		$deleted = $this->repository->delete_failed_items(
			array(),
			array( 930 ),
			self::SOURCE
		);

		// ASSERT: The other source's row survives.
		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->repository->get_item( $ours ) );
		$this->assertNotNull( $this->repository->get_item( $other ) );
	}

	/**
	 * Verifies that the source_post_id branch fails closed without a source,
	 * so the default can't reach across sources; the item id branch still runs.
	 */
	public function test_delete_failed_items_without_a_source_skips_sources(): void {
		// ARRANGE: One failure reachable by source post id, one by item id.
		$session   = $this->create_session();
		$by_source = $this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 940,
				'status'          => 'error',
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$by_id     = $this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => null,
				'status'          => 'error',
				'import_date_gmt' => '2026-01-02 00:00:00',
			)
		);

		// ACT: Remove both without naming a source, as the seeder does.
		$deleted = $this->repository->delete_failed_items(
			array( $by_id ),
			array( 940 )
		);

		// ASSERT: Only the item id branch acted.
		$this->assertSame( 1, $deleted );
		$this->assertNotNull( $this->repository->get_item( $by_source ) );
		$this->assertNull( $this->repository->get_item( $by_id ) );
	}

	/**
	 * Verifies that ignoring by source post id leaves another source's failure
	 * for the same numeric id unflagged.
	 */
	public function test_ignore_by_source_stays_in_scope(): void {
		// ARRANGE: The same numeric source post id fails under two sources.
		$mine   = $this->create_session();
		$theirs = $this->create_session( self::OTHER_SOURCE );
		$this->insert_item(
			array(
				'session_id'      => $mine,
				'source_post_id'  => 931,
				'status'          => 'error',
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		$other = $this->insert_item(
			array(
				'session_id'      => $theirs,
				'source_post_id'  => 931,
				'status'          => 'error',
				'import_date_gmt' => '2026-01-02 00:00:00',
			)
		);

		// ACT: Ignore source 931 for the connected source only.
		$ignored = $this->repository->set_failed_items_ignored(
			array(),
			array( 931 ),
			true,
			self::SOURCE
		);

		// ASSERT: One row flagged, and the other source stays open.
		$this->assertSame( 1, $ignored );
		$other_row = $this->repository->get_item( $other );
		$this->assertNotNull( $other_row );
		$this->assertNull( $other_row['ignored_gmt'] );
		$this->assertSame(
			1,
			$this->repository->count_failures( self::OTHER_SOURCE )
		);
	}

	/**
	 * Verifies that delete_failed_items removes error rows regardless of
	 * source_post_id but leaves non-error rows untouched.
	 */
	public function test_delete_failed_items_removes_error_rows_but_not_others(): void {
		// ARRANGE: One error row with a source_post_id, one orphan error, one
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

		// ACT: Try to delete all three.
		$deleted = $this->repository->delete_failed_items(
			array( $source_err, $orphan_err, $success_row )
		);

		// ASSERT: Both error rows go; the success row is untouched.
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
		// ARRANGE: Two failure rows for the same source_post_id, plus an
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

		// ACT: Dismiss source 800 — both its attempts should go.
		$deleted = $this->repository->delete_failed_items(
			array(),
			array( 800 ),
			self::SOURCE
		);

		// ASSERT: Both 800 rows gone; 801 untouched.
		$this->assertSame( 2, $deleted );
		$this->assertNull( $this->repository->get_item( $older ) );
		$this->assertNull( $this->repository->get_item( $newer ) );
		$this->assertNotNull( $this->repository->get_item( $other ) );
	}

	/**
	 * Verifies that ignoring an orphan failure by item id hides it from the open
	 * list and count and surfaces it under the ignored view; un-ignore restores.
	 */
	public function test_ignore_orphan_failure_round_trips(): void {
		// ARRANGE: A single orphan failure.
		$session = $this->create_session();
		$item    = $this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => null,
				'status'          => 'error',
				'error_message'   => 'Orphan failure',
				'import_date_gmt' => '2026-01-01 00:00:00',
			)
		);

		// ACT: Ignore the orphan by its item id.
		$ignored = $this->repository->set_failed_items_ignored(
			array( $item ),
			array(),
			true
		);

		// ASSERT: It drops from the open list/count and appears under ignored.
		$this->assertSame( 1, $ignored );
		$this->assertSame(
			0,
			$this->repository->count_failures( self::SOURCE )
		);
		$this->assertSame(
			1,
			$this->repository->count_failures( self::SOURCE, true )
		);
		$rows = $this->repository->list_failures( self::SOURCE, 0, 20, true );
		$this->assertCount( 1, $rows );
		$this->assertSame( $item, (int) $rows[0]['id'] );

		// ACT: Un-ignore it.
		$restored = $this->repository->set_failed_items_ignored(
			array( $item ),
			array(),
			false
		);

		// ASSERT: It returns to the open list and clears from ignored.
		$this->assertSame( 1, $restored );
		$this->assertSame(
			1,
			$this->repository->count_failures( self::SOURCE )
		);
		$this->assertSame(
			0,
			$this->repository->count_failures( self::SOURCE, true )
		);
	}

	/**
	 * Verifies that ignoring by source flags every error attempt for the source,
	 * so the deduped listing can't re-surface an older sibling.
	 */
	public function test_ignore_by_source_flags_every_attempt(): void {
		global $wpdb;

		// ARRANGE: Two error attempts for one source, plus an unrelated one.
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 800,
				'status'          => 'error',
				'import_date_gmt' => '2026-02-01 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 800,
				'status'          => 'error',
				'import_date_gmt' => '2026-02-02 00:00:00',
			)
		);
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 801,
				'status'          => 'error',
				'import_date_gmt' => '2026-02-03 00:00:00',
			)
		);

		// ACT: Ignore source 800.
		$ignored = $this->repository->set_failed_items_ignored(
			array(),
			array( 800 ),
			true,
			self::SOURCE
		);

		// ASSERT: Both 800 attempts carry the flag, so no sibling resurfaces;
		// the open list keeps only 801 and 800 shows once under ignored.
		$this->assertSame( 2, $ignored );

		$table = Import_Items_Table::table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$flagged = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}`"
					. ' WHERE source_post_id = %d AND ignored_gmt IS NOT NULL',
				800
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( 2, $flagged );
		$this->assertSame(
			1,
			$this->repository->count_failures( self::SOURCE )
		);
		$this->assertSame(
			1,
			$this->repository->count_failures( self::SOURCE, true )
		);
	}

	/**
	 * Verifies that a fresh failed attempt after ignoring a source re-surfaces it
	 * in the open view — a new unflagged error becomes the most recent row.
	 */
	public function test_new_failure_after_ignore_resurfaces_in_open(): void {
		// ARRANGE: An ignored source-linked failure.
		$session = $this->create_session();
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 802,
				'status'          => 'error',
				'import_date_gmt' => '2026-03-01 00:00:00',
			)
		);
		$this->repository->set_failed_items_ignored(
			array(),
			array( 802 ),
			true,
			self::SOURCE
		);

		// ACT: A later re-import for the same source fails afresh.
		$this->insert_item(
			array(
				'session_id'      => $session,
				'source_post_id'  => 802,
				'status'          => 'error',
				'import_date_gmt' => '2026-03-02 00:00:00',
			)
		);

		// ASSERT: The source is open again and no longer under ignored.
		$this->assertSame(
			1,
			$this->repository->count_failures( self::SOURCE )
		);
		$this->assertSame(
			0,
			$this->repository->count_failures( self::SOURCE, true )
		);
	}

	/**
	 * Verifies that update_source_modified_gmt_bulk fans out updates correctly.
	 */
	public function test_update_source_modified_gmt_bulk_writes_each_row(): void {
		// ARRANGE: Two imported rows with stale source_modified_gmt values.
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

		// ACT: Flush a batch update with one value per item.
		$this->repository->update_source_modified_gmt_bulk(
			array(
				$item_a => '2026-04-01 00:00:00',
				$item_b => '2026-05-01 00:00:00',
			)
		);

		// ASSERT: Each row picks up its mapped value via the CASE-WHEN UPDATE.
		$row_a = $this->repository->get_item( $item_a );
		$row_b = $this->repository->get_item( $item_b );
		$this->assertNotNull( $row_a );
		$this->assertNotNull( $row_b );
		$this->assertSame( '2026-04-01 00:00:00', (string) $row_a['source_modified_gmt'] );
		$this->assertSame( '2026-05-01 00:00:00', (string) $row_b['source_modified_gmt'] );
	}
}
