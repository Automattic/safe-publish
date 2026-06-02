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
 * Covers History_Repository::list_imported_post_ids(), which joins wp_posts so
 * search/filter/sort act on every imported post, get_imported_filter_facets(),
 * and get_items_for_posts(). list_imported_post_ids() requires real posts (the
 * join drops missing ones); get_items_for_posts() is items-only and seeds
 * arbitrary post IDs.
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
	 * @param int|null $session_id      Owning session, or null for the default.
	 * @return int Inserted item ID.
	 */
	private function insert_item(
		?int $post_id,
		string $import_date_gmt,
		string $status = 'success',
		?int $session_id = null
	): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Import_Items_Table::table_name(),
			array(
				'session_id'           => $session_id ?? $this->session_id,
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
	 * Creates a real post and an import item for it, returning both IDs.
	 *
	 * @param string   $import_date_gmt MySQL datetime to store on the item.
	 * @param string   $status          Item status.
	 * @param array    $post_args       Overrides for the created post.
	 * @param int|null $session_id    Owning session, or null for the default.
	 * @return array{0: int, 1: int} [ post_id, item_id ].
	 */
	private function insert_post_item(
		string $import_date_gmt,
		string $status = 'success',
		array $post_args = array(),
		?int $session_id = null
	): array {
		$post_id = $this->factory()->post->create(
			array_merge(
				array(
					'post_status' => 'publish',
					'post_type'   => 'post',
				),
				$post_args
			)
		);

		$item_id = $this->insert_item(
			$post_id,
			$import_date_gmt,
			$status,
			$session_id
		);

		return array( $post_id, $item_id );
	}

	/**
	 * Verifies that posts are listed by their import date, newest first.
	 */
	public function test_lists_post_ids_newest_import_first(): void {
		// ARRANGE: Three posts imported on different dates.
		list( $oldest ) = $this->insert_post_item( '2024-01-01 00:00:00' );
		list( $newest ) = $this->insert_post_item( '2024-03-01 00:00:00' );
		list( $middle ) = $this->insert_post_item( '2024-02-01 00:00:00' );

		// ACT: List the first page.
		$result = $this->repository->list_imported_post_ids( 1, 20 );

		// ASSERT: Newest import first, then middle, then oldest.
		$this->assertSame( array( $newest, $middle, $oldest ), $result );
	}

	/**
	 * Verifies that ordering uses each post's most recent item, and that a
	 * post with multiple items appears only once.
	 */
	public function test_orders_by_each_posts_most_recent_item(): void {
		// ARRANGE: $multi has an old and a newer item; $between has one in
		// between.
		list( $multi ) = $this->insert_post_item( '2024-01-01 00:00:00' );
		$this->insert_item( $multi, '2024-09-01 00:00:00' );
		list( $between ) = $this->insert_post_item( '2024-05-01 00:00:00' );

		// ACT: List the first page.
		$result = $this->repository->list_imported_post_ids( 1, 20 );

		// ASSERT: $multi (newest item 2024-09) outranks $between and is not
		// duplicated.
		$this->assertSame( array( $multi, $between ), $result );
	}

	/**
	 * Verifies that items without a local post (failed imports) are excluded.
	 */
	public function test_excludes_items_with_null_post_id(): void {
		// ARRANGE: One real import and one failed import (null post_id).
		list( $imported ) = $this->insert_post_item( '2024-01-01 00:00:00' );
		$this->insert_item( null, '2024-02-01 00:00:00', 'error' );

		// ACT: List the first page.
		$result = $this->repository->list_imported_post_ids( 1, 20 );

		// ASSERT: Only the post with a local ID is listed.
		$this->assertSame( array( $imported ), $result );
	}

	/**
	 * Verifies that an item whose post was deleted is excluded by the join.
	 */
	public function test_excludes_items_whose_post_no_longer_exists(): void {
		// ARRANGE: A live imported post and an item pointing at a deleted post.
		list( $live ) = $this->insert_post_item( '2024-02-01 00:00:00' );
		list( $gone ) = $this->insert_post_item( '2024-01-01 00:00:00' );
		wp_delete_post( $gone, true );

		// ACT: List the first page.
		$result = $this->repository->list_imported_post_ids( 1, 20 );

		// ASSERT: The orphaned item is dropped by the wp_posts join.
		$this->assertSame( array( $live ), $result );
	}

	/**
	 * Verifies that posts hidden from search (e.g. trashed) are excluded, so
	 * the page count can't include rows the get_posts( 'any' ) hydration drops.
	 */
	public function test_excludes_non_displayable_statuses(): void {
		// ARRANGE: A live imported post and a trashed one.
		list( $live ) = $this->insert_post_item(
			'2024-02-01 00:00:00',
			'success',
			array( 'post_status' => 'publish' )
		);
		$this->insert_post_item(
			'2024-01-01 00:00:00',
			'success',
			array( 'post_status' => 'trash' )
		);

		// ACT: List the first page with no status filter.
		$result = $this->repository->list_imported_post_ids( 1, 20 );

		// ASSERT: Only the live post is listed.
		$this->assertSame( array( $live ), $result );
	}

	/**
	 * Verifies that pagination returns up to per_page+1 IDs (the has_more
	 * probe) and that later pages resume at the correct offset.
	 */
	public function test_paginates_with_has_more_probe(): void {
		// ARRANGE: Four posts, newest to oldest.
		list( $first )  = $this->insert_post_item( '2024-04-01 00:00:00' );
		list( $second ) = $this->insert_post_item( '2024-03-01 00:00:00' );
		list( $third )  = $this->insert_post_item( '2024-02-01 00:00:00' );
		list( $fourth ) = $this->insert_post_item( '2024-01-01 00:00:00' );

		// ACT: Fetch both pages at per_page = 2.
		$page_one = $this->repository->list_imported_post_ids( 1, 2 );
		$page_two = $this->repository->list_imported_post_ids( 2, 2 );

		// ASSERT: Page one returns 3 IDs (2 + the has_more probe).
		$this->assertSame( array( $first, $second, $third ), $page_one );

		// ASSERT: Page two re-includes the probe item, then the remainder.
		$this->assertSame( array( $third, $fourth ), $page_two );
	}

	/**
	 * Verifies that ties on import date are broken by the higher post ID.
	 */
	public function test_breaks_ordering_ties_by_highest_post_id(): void {
		// ARRANGE: Two posts imported at the same moment ($higher created last).
		list( $lower )  = $this->insert_post_item( '2024-06-01 00:00:00' );
		list( $higher ) = $this->insert_post_item( '2024-06-01 00:00:00' );

		// ACT: List the first page.
		$result = $this->repository->list_imported_post_ids( 1, 20 );

		// ASSERT: Higher post ID wins the tie.
		$this->assertSame( array( $higher, $lower ), $result );
	}

	/**
	 * Verifies that the search term matches post titles (case-insensitively).
	 */
	public function test_search_matches_post_title(): void {
		// ARRANGE: Two imported posts with distinct titles.
		list( $match ) = $this->insert_post_item(
			'2024-01-01 00:00:00',
			'success',
			array( 'post_title' => 'Quarterly Report' )
		);
		$this->insert_post_item(
			'2024-02-01 00:00:00',
			'success',
			array( 'post_title' => 'Unrelated Memo' )
		);

		// ACT: Search for a substring of the first title, in another case.
		$result = $this->repository->list_imported_post_ids(
			1,
			20,
			array( 'search' => 'quarterly' )
		);

		// ASSERT: Only the matching post is returned.
		$this->assertSame( array( $match ), $result );
	}

	/**
	 * Verifies that the status filter limits results to the given post_status.
	 */
	public function test_filters_by_local_status(): void {
		// ARRANGE: A published and a draft imported post.
		$this->insert_post_item(
			'2024-01-01 00:00:00',
			'success',
			array( 'post_status' => 'publish' )
		);
		list( $draft ) = $this->insert_post_item(
			'2024-02-01 00:00:00',
			'success',
			array( 'post_status' => 'draft' )
		);

		// ACT: Filter to drafts only.
		$result = $this->repository->list_imported_post_ids(
			1,
			20,
			array( 'statuses' => array( 'draft' ) )
		);

		// ASSERT: Only the draft is returned.
		$this->assertSame( array( $draft ), $result );
	}

	/**
	 * Verifies that the post_type filter limits results to the given types.
	 */
	public function test_filters_by_post_type(): void {
		// ARRANGE: An imported post and an imported page.
		$this->insert_post_item(
			'2024-01-01 00:00:00',
			'success',
			array( 'post_type' => 'post' )
		);
		list( $page ) = $this->insert_post_item(
			'2024-02-01 00:00:00',
			'success',
			array( 'post_type' => 'page' )
		);

		// ACT: Filter to pages only.
		$result = $this->repository->list_imported_post_ids(
			1,
			20,
			array( 'post_types' => array( 'page' ) )
		);

		// ASSERT: Only the page is returned.
		$this->assertSame( array( $page ), $result );
	}

	/**
	 * Verifies that the session filter matches a post's most recent item's
	 * session, not any earlier item in a different session.
	 */
	public function test_filters_by_most_recent_session(): void {
		// ARRANGE: A second session, and a post first imported under the
		// default session then re-imported under the second.
		$other_session = $this->repository->create_session(
			'https://other.example.com',
			'bulk'
		);
		if ( is_wp_error( $other_session ) ) {
			$this->fail( 'Failed to create the second import session.' );
		}

		list( $reimported ) = $this->insert_post_item(
			'2024-01-01 00:00:00',
			'success',
			array(),
			$this->session_id
		);
		$this->insert_item(
			$reimported,
			'2024-06-01 00:00:00',
			'updated',
			$other_session
		);

		list( $default_only ) = $this->insert_post_item(
			'2024-02-01 00:00:00',
			'success',
			array(),
			$this->session_id
		);

		// ACT + ASSERT: Filtering by the newer session returns the re-imported
		// post (its most recent item lives there).
		$this->assertSame(
			array( $reimported ),
			$this->repository->list_imported_post_ids(
				1,
				20,
				array( 'session_id' => $other_session )
			)
		);

		// ACT + ASSERT: Filtering by the default session returns only the post
		// whose most recent item still belongs to it.
		$this->assertSame(
			array( $default_only ),
			$this->repository->list_imported_post_ids(
				1,
				20,
				array( 'session_id' => $this->session_id )
			)
		);
	}

	/**
	 * Verifies that title sorting orders alphabetically in both directions.
	 */
	public function test_sorts_by_title(): void {
		// ARRANGE: Alphabetical order runs opposite to import-date order
		// (Alpha is newer), so a title sort can't be satisfied by the default
		// date sort.
		list( $alpha ) = $this->insert_post_item(
			'2024-03-01 00:00:00',
			'success',
			array( 'post_title' => 'Alpha' )
		);
		list( $beta )  = $this->insert_post_item(
			'2024-01-01 00:00:00',
			'success',
			array( 'post_title' => 'Beta' )
		);

		// ACT + ASSERT: Ascending by title.
		$this->assertSame(
			array( $alpha, $beta ),
			$this->repository->list_imported_post_ids(
				1,
				20,
				array(
					'orderby' => 'title',
					'order'   => 'asc',
				)
			)
		);

		// ACT + ASSERT: Descending by title.
		$this->assertSame(
			array( $beta, $alpha ),
			$this->repository->list_imported_post_ids(
				1,
				20,
				array(
					'orderby' => 'title',
					'order'   => 'desc',
				)
			)
		);
	}

	/**
	 * Verifies that facets list the post types and sessions present among
	 * imported posts.
	 */
	public function test_filter_facets_list_present_types_and_sessions(): void {
		// ARRANGE: A second session, plus a post and a page across both.
		$other_session = $this->repository->create_session(
			'https://other.example.com',
			'bulk'
		);
		if ( is_wp_error( $other_session ) ) {
			$this->fail( 'Failed to create the second import session.' );
		}
		$this->insert_post_item(
			'2024-01-01 00:00:00',
			'success',
			array( 'post_type' => 'post' ),
			$this->session_id
		);
		$this->insert_post_item(
			'2024-02-01 00:00:00',
			'success',
			array( 'post_type' => 'page' ),
			$other_session
		);

		// ACT: Read the filter facets.
		$facets = $this->repository->get_imported_filter_facets();

		// ASSERT: Both imported post types appear as options.
		$type_values = array_column( $facets['post_types'], 'value' );
		sort( $type_values );
		$this->assertSame( array( 'page', 'post' ), $type_values );

		// ASSERT: Both sessions that produced posts appear as options.
		$session_values = array_column( $facets['sessions'], 'value' );
		$this->assertContains( (string) $this->session_id, $session_values );
		$this->assertContains( (string) $other_session, $session_values );
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
