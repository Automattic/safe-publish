<?php
/**
 * Integration tests for the Imported Posts listing repository queries.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Utils\Import_Items_Table;

/**
 * Imported Posts Listing Test Class.
 *
 * Covers History_Repository::list_imported_post_ids() and
 * get_items_for_posts(), which back the Imported Posts page. Both query the
 * items table only (no wp_posts join), so the tests seed arbitrary post IDs.
 */
class Imported_Posts_Listing_Test extends Integration_Test_Case {

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Session ID shared by the seeded items.
	 *
	 * @var int
	 */
	private int $session_id;

	/**
	 * Set up test environment.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new History_Repository();

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);
		if ( is_wp_error( $session_id ) ) {
			$this->fail( 'Failed to create the test import session.' );
		}
		$this->session_id = $session_id;
	}

	/**
	 * Inserts an import item with a controlled import_date_gmt.
	 *
	 * Lets tests set import_date_gmt directly — log_import_action() only
	 * stamps current_time(), which can't drive ordering scenarios.
	 *
	 * @param int|null $post_id         Local post ID, or null for a failed import.
	 * @param string   $import_date_gmt MySQL datetime to store.
	 * @param string   $status          Item status.
	 * @return int Inserted item ID.
	 */
	private function insert_item(
		?int $post_id,
		string $import_date_gmt,
		string $status = 'success'
	): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Import_Items_Table::table_name(),
			array(
				'session_id'           => $this->session_id,
				'title'                => 'Test Item',
				'status'               => $status,
				'post_id'              => $post_id,
				'has_previous_content' => 0,
				'rolled_back'          => 0,
				'import_date_gmt'      => $import_date_gmt,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $wpdb->insert_id;
	}

	/**
	 * Verifies that posts are listed by their import date, newest first.
	 */
	public function test_lists_post_ids_newest_import_first(): void {
		// ARRANGE: Three posts imported on different dates.
		$this->insert_item( 101, '2024-01-01 00:00:00' );
		$this->insert_item( 102, '2024-03-01 00:00:00' );
		$this->insert_item( 103, '2024-02-01 00:00:00' );

		// ACT: List the first page.
		$result = $this->repository->list_imported_post_ids( 1, 20 );

		// ASSERT: Newest import first (102), then 103, then 101.
		$this->assertSame( array( 102, 103, 101 ), $result );
	}

	/**
	 * Verifies that ordering uses each post's most recent item, and that a
	 * post with multiple items appears only once.
	 */
	public function test_orders_by_each_posts_most_recent_item(): void {
		// ARRANGE: Post 401 has an old and a newer item; 402 has one in
		// between.
		$this->insert_item( 401, '2024-01-01 00:00:00' );
		$this->insert_item( 401, '2024-09-01 00:00:00' );
		$this->insert_item( 402, '2024-05-01 00:00:00' );

		// ACT: List the first page.
		$result = $this->repository->list_imported_post_ids( 1, 20 );

		// ASSERT: 401 (newest item 2024-09) outranks 402 and is not duplicated.
		$this->assertSame( array( 401, 402 ), $result );
	}

	/**
	 * Verifies that items without a local post (failed imports) are excluded.
	 */
	public function test_excludes_items_with_null_post_id(): void {
		// ARRANGE: One real import and one failed import (null post_id).
		$this->insert_item( 501, '2024-01-01 00:00:00' );
		$this->insert_item( null, '2024-02-01 00:00:00', 'error' );

		// ACT: List the first page.
		$result = $this->repository->list_imported_post_ids( 1, 20 );

		// ASSERT: Only the post with a local ID is listed.
		$this->assertSame( array( 501 ), $result );
	}

	/**
	 * Verifies that pagination returns up to per_page+1 IDs (the has_more
	 * probe) and that later pages resume at the correct offset.
	 */
	public function test_paginates_with_has_more_probe(): void {
		// ARRANGE: Four posts, newest to oldest so order is 601, 602, 603, 604.
		$this->insert_item( 601, '2024-04-01 00:00:00' );
		$this->insert_item( 602, '2024-03-01 00:00:00' );
		$this->insert_item( 603, '2024-02-01 00:00:00' );
		$this->insert_item( 604, '2024-01-01 00:00:00' );

		// ACT: Fetch both pages at per_page = 2.
		$page_one = $this->repository->list_imported_post_ids( 1, 2 );
		$page_two = $this->repository->list_imported_post_ids( 2, 2 );

		// ASSERT: Page one returns 3 IDs (2 + the has_more probe).
		$this->assertSame( array( 601, 602, 603 ), $page_one );

		// ASSERT: Page two re-includes the probe item (603), then the
		// remainder.
		$this->assertSame( array( 603, 604 ), $page_two );
	}

	/**
	 * Verifies that ties on import date are broken by the higher post ID.
	 */
	public function test_breaks_ordering_ties_by_highest_post_id(): void {
		// ARRANGE: Two posts imported at the same moment.
		$this->insert_item( 701, '2024-06-01 00:00:00' );
		$this->insert_item( 702, '2024-06-01 00:00:00' );

		// ACT: List the first page.
		$result = $this->repository->list_imported_post_ids( 1, 20 );

		// ASSERT: Higher post ID wins the tie.
		$this->assertSame( array( 702, 701 ), $result );
	}

	/**
	 * Verifies that get_items_for_posts returns each post's most recent item,
	 * keyed by post ID.
	 */
	public function test_get_items_returns_most_recent_item_per_post(): void {
		// ARRANGE: Post 801 updated after its initial import; 802 imported
		// once.
		$this->insert_item( 801, '2024-01-01 00:00:00', 'success' );
		$this->insert_item( 801, '2024-05-01 00:00:00', 'updated' );
		$this->insert_item( 802, '2024-03-01 00:00:00', 'success' );

		// ACT: Fetch the items for both posts.
		$result = $this->repository->get_items_for_posts( array( 801, 802 ) );

		// ASSERT: 801 resolves to its most recent (updated) item.
		$this->assertSame( 'updated', $result[801]['status'] );
		$this->assertSame(
			'2024-05-01 00:00:00',
			$result[801]['import_date_gmt']
		);

		// ASSERT: 802 resolves to its single item.
		$this->assertSame( 'success', $result[802]['status'] );
	}

	/**
	 * Verifies that get_items_for_posts returns an empty array for empty input.
	 */
	public function test_get_items_returns_empty_array_for_empty_input(): void {
		// ARRANGE: A seeded item that must not be returned.
		$this->insert_item( 901, '2024-01-01 00:00:00' );

		// ACT: Request items for no posts.
		$result = $this->repository->get_items_for_posts( array() );

		// ASSERT: Empty in, empty out.
		$this->assertSame( array(), $result );
	}

	/**
	 * Verifies that get_items_for_posts breaks same-timestamp ties on the
	 * higher item ID (the later-inserted row wins).
	 */
	public function test_get_items_breaks_ties_by_highest_id(): void {
		// ARRANGE: Two items for one post sharing an import date.
		$this->insert_item( 1001, '2024-01-01 00:00:00', 'success' );
		$later_id = $this->insert_item(
			1001,
			'2024-01-01 00:00:00',
			'updated'
		);

		// ACT: Fetch the item for the post.
		$result = $this->repository->get_items_for_posts( array( 1001 ) );

		// ASSERT: The later-inserted row (higher ID) wins the tie.
		$this->assertSame( $later_id, (int) $result[1001]['id'] );
		$this->assertSame( 'updated', $result[1001]['status'] );
	}

	/**
	 * Verifies that get_items_for_posts returns only the requested post IDs.
	 */
	public function test_get_items_returns_only_requested_posts(): void {
		// ARRANGE: Three imported posts.
		$this->insert_item( 1101, '2024-01-01 00:00:00' );
		$this->insert_item( 1102, '2024-02-01 00:00:00' );
		$this->insert_item( 1103, '2024-03-01 00:00:00' );

		// ACT: Request only two of them.
		$result = $this->repository->get_items_for_posts( array( 1101, 1103 ) );

		// ASSERT: Exactly the requested posts come back (key order is not
		// guaranteed — the consumer looks up by post ID — so sort first).
		$keys = array_keys( $result );
		sort( $keys );
		$this->assertSame( array( 1101, 1103 ), $keys );
	}
}
