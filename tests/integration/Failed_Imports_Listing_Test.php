<?php
/**
 * Integration tests for the Failed Imports listing repository query.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Utils\Import_Items_Table;

/**
 * Failed Imports Listing Test Class.
 *
 * Covers History_Repository::list_failed_items(), which joins the sessions
 * table to surface the source site URL alongside each failed item. Seeds
 * error rows directly so the date column is deterministic.
 */
class Failed_Imports_Listing_Test extends Integration_Test_Case {

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
	 * Inserts a failed item with a controlled import_date_gmt and title.
	 *
	 * @param string $title           Item title.
	 * @param string $import_date_gmt MySQL datetime to store.
	 * @return int Inserted item ID.
	 */
	private function insert_failed_item(
		string $title,
		string $import_date_gmt
	): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Import_Items_Table::table_name(),
			array(
				'session_id'           => $this->session_id,
				'title'                => $title,
				'status'               => 'error',
				'error_message'        => 'Something broke',
				'has_previous_content' => 0,
				'rolled_back'          => 0,
				'import_date_gmt'      => $import_date_gmt,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $wpdb->insert_id;
	}

	/**
	 * Verifies that the search filter matches against the item title using a
	 * case-insensitive substring.
	 */
	public function test_search_matches_title_substring(): void {
		// ARRANGE: Two failed items with distinct titles.
		$match = $this->insert_failed_item(
			'Quarterly Report',
			'2024-01-01 00:00:00'
		);
		$this->insert_failed_item(
			'Unrelated Memo',
			'2024-02-01 00:00:00'
		);

		// ACT: Search for a substring of the first title, in another case.
		$rows = $this->repository->list_failed_items(
			1,
			20,
			array( 'search' => 'quarterly' )
		);

		// ASSERT: Only the matching item is returned.
		$ids = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
		$this->assertSame( array( $match ), $ids );
	}

	/**
	 * Verifies that the attempted_after bound is inclusive of timestamps
	 * exactly matching it, and excludes earlier failures.
	 */
	public function test_filters_by_attempted_after(): void {
		// ARRANGE: Four failures — one before, one exactly at the bound,
		// and two after.
		$this->insert_failed_item( 'Old', '2024-01-15 12:00:00' );
		$on_bound = $this->insert_failed_item( 'On bound', '2024-02-01 00:00:00' );
		$middle   = $this->insert_failed_item( 'Middle', '2024-02-15 12:00:00' );
		$latest   = $this->insert_failed_item( 'Latest', '2024-03-15 12:00:00' );

		// ACT: Filter to failures on or after Feb 1.
		$rows = $this->repository->list_failed_items(
			1,
			20,
			array( 'attempted_after' => '2024-02-01 00:00:00' )
		);

		// ASSERT: The Jan row is excluded; the boundary row is included.
		$ids = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
		$this->assertSame( array( $latest, $middle, $on_bound ), $ids );
	}

	/**
	 * Verifies that the attempted_before bound is inclusive of timestamps
	 * exactly matching it, and excludes later failures.
	 */
	public function test_filters_by_attempted_before(): void {
		// ARRANGE: Four failures — one early, one in the middle, one exactly
		// at the bound, and one after.
		$earliest = $this->insert_failed_item( 'Earliest', '2024-01-15 12:00:00' );
		$middle   = $this->insert_failed_item( 'Middle', '2024-02-15 12:00:00' );
		$on_bound = $this->insert_failed_item( 'On bound', '2024-02-29 23:59:59' );
		$this->insert_failed_item( 'Latest', '2024-03-15 12:00:00' );

		// ACT: Filter to failures on or before Feb 29 end-of-day.
		$rows = $this->repository->list_failed_items(
			1,
			20,
			array( 'attempted_before' => '2024-02-29 23:59:59' )
		);

		// ASSERT: The March row is excluded; the boundary row is included.
		$ids = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
		$this->assertSame( array( $on_bound, $middle, $earliest ), $ids );
	}

	/**
	 * Verifies that search and date-range filters compose on the same query.
	 */
	public function test_combines_search_and_date_range(): void {
		// ARRANGE: Four failures — only one matches both filters.
		$this->insert_failed_item( 'Quarterly Report', '2024-01-15 12:00:00' );
		$match = $this->insert_failed_item( 'Quarterly Report', '2024-02-15 12:00:00' );
		$this->insert_failed_item( 'Other Title', '2024-02-15 12:00:00' );
		$this->insert_failed_item( 'Quarterly Report', '2024-03-15 12:00:00' );

		// ACT: Filter to "quarterly" titles attempted in February.
		$rows = $this->repository->list_failed_items(
			1,
			20,
			array(
				'search'           => 'quarterly',
				'attempted_after'  => '2024-02-01 00:00:00',
				'attempted_before' => '2024-02-29 23:59:59',
			)
		);

		// ASSERT: Only the single intersecting failure is returned.
		$ids = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
		$this->assertSame( array( $match ), $ids );
	}

	/**
	 * Verifies that successful items are never returned even when their title
	 * matches the search.
	 */
	public function test_excludes_non_error_items(): void {
		global $wpdb;

		// ARRANGE: One failed item and one successful item with the same title.
		$failed = $this->insert_failed_item(
			'Quarterly Report',
			'2024-01-01 00:00:00'
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Import_Items_Table::table_name(),
			array(
				'session_id'           => $this->session_id,
				'title'                => 'Quarterly Report',
				'status'               => 'success',
				'has_previous_content' => 0,
				'rolled_back'          => 0,
				'import_date_gmt'      => '2024-02-01 00:00:00',
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// ACT: Search for the shared title.
		$rows = $this->repository->list_failed_items(
			1,
			20,
			array( 'search' => 'quarterly' )
		);

		// ASSERT: Only the failed item is returned; the success row is
		// dropped by the hard-coded status filter.
		$ids = array_map( static fn( array $row ): int => (int) $row['id'], $rows );
		$this->assertSame( array( $failed ), $ids );
	}
}
