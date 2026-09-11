<?php
/**
 * Unit tests for the Available-chip catalog page fill.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Admin\Posts_Read_Service;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\API\Post_Type_Fetcher;
use Safe_Publish\API\Catalog_REST_Controller;

/**
 * Available catalog fill test class.
 */
class AvailableCatalogFillTest extends TestCase {

	/**
	 * Verifies that the Available page fills across catalog pages, dropping
	 * imported rows, and reports has_more when a non-imported row remains.
	 */
	public function test_fills_requested_page_across_catalog_pages_and_flags_more(): void {
		// ARRANGE: Three catalog pages whose imported rows thin each page, so a
		// 3-row Available page only fills by pulling all three.
		$api        = new Fake_Catalog_Source_Posts_API();
		$api->pages = array(
			1 => $this->page( array( 1, 2, 3 ), true ),
			2 => $this->page( array( 4, 5, 6 ), true ),
			3 => $this->page( array( 7, 8, 9 ), true ),
		);

		$import                      = new Fake_Import_Status_Service();
		$import->imported_source_ids = array( 1, 2, 4, 8, 9 );

		// ACT: Request the first Available page of three.
		$result = $this->list_available( $api, $import, 1, 3 );

		// ASSERT: The page holds the first three non-imported ids in order.
		$this->assertSame( array( 3, 5, 6 ), $this->ids( $result ) );

		// ASSERT: A fourth non-imported row (id 7) remains, so has_more is
		// true.
		$this->assertTrue( $result['has_more'] );

		// ASSERT: Three fetches were needed, each at the source max page size.
		$this->assertSame(
			array(
				Catalog_REST_Controller::MAX_PER_PAGE,
				Catalog_REST_Controller::MAX_PER_PAGE,
				Catalog_REST_Controller::MAX_PER_PAGE,
			),
			$api->requested_per_pages
		);
	}

	/**
	 * Verifies that has_more is false when the source is exhausted without a
	 * non-imported row beyond the requested page.
	 */
	public function test_reports_no_more_when_source_exhausts_without_surplus(): void {
		// ARRANGE: The source runs out on page two with no surplus row.
		$api        = new Fake_Catalog_Source_Posts_API();
		$api->pages = array(
			1 => $this->page( array( 1, 2, 3 ), true ),
			2 => $this->page( array( 4, 5 ), false ),
		);

		$import                      = new Fake_Import_Status_Service();
		$import->imported_source_ids = array( 1, 4 );

		// ACT: Request a 3-row Available page.
		$result = $this->list_available( $api, $import, 1, 3 );

		// ASSERT: The three non-imported rows fill the page with none to spare.
		$this->assertSame( array( 2, 3, 5 ), $this->ids( $result ) );
		$this->assertFalse( $result['has_more'] );
		$this->assertSame( 2, count( $api->requested_per_pages ) );
	}

	/**
	 * Verifies that the scan stops at the fetch cap when non-imported rows are
	 * too sparse, returning an empty page that still reports has_more.
	 */
	public function test_caps_the_scan_when_non_imported_rows_are_too_sparse(): void {
		// ARRANGE: Every page is fully imported and the source always claims
		// more, so the page can never fill.
		$api               = new Fake_Catalog_Source_Posts_API();
		$api->default_page = $this->page( array( 1, 2 ), true );

		$import                    = new Fake_Import_Status_Service();
		$import->mark_all_imported = true;

		// ACT: Request a page that can never be filled.
		$result = $this->list_available( $api, $import, 1, 3 );

		// ASSERT: The scan stops at the cap and returns an empty page.
		$this->assertSame( array(), $result['items'] );
		$this->assertSame(
			Posts_Read_Service::AVAILABLE_FILL_MAX_FETCHES,
			count( $api->requested_per_pages )
		);

		// ASSERT: has_more stays true so the client can page past the cap.
		$this->assertTrue( $result['has_more'] );
	}

	/**
	 * Verifies that a later page returns its own window of non-imported rows.
	 */
	public function test_returns_the_requested_window_for_a_later_page(): void {
		// ARRANGE: Six non-imported rows spread across two catalog pages.
		$api        = new Fake_Catalog_Source_Posts_API();
		$api->pages = array(
			1 => $this->page( array( 10, 11, 12, 13 ), true ),
			2 => $this->page( array( 14, 15 ), false ),
		);

		$import = new Fake_Import_Status_Service();

		// ACT: Request the second page of two.
		$result = $this->list_available( $api, $import, 2, 2 );

		// ASSERT: The window is the third and fourth non-imported rows.
		$this->assertSame( array( 12, 13 ), $this->ids( $result ) );
		$this->assertTrue( $result['has_more'] );
	}

	/**
	 * Builds a canned catalog page from a list of source ids.
	 *
	 * @param int[] $ids      Source ids the page carries.
	 * @param bool  $has_more Whether the source reports further pages.
	 * @return array{items: list<array>, has_more: bool} Canned page.
	 */
	private function page( array $ids, bool $has_more ): array {
		return array(
			'items'    => array_map(
				static fn( int $id ): array => array( 'id' => $id ),
				$ids
			),
			'has_more' => $has_more,
		);
	}

	/**
	 * Calls the public listing service with the given doubles and paging.
	 *
	 * @param Fake_Catalog_Source_Posts_API $api      Source API double.
	 * @param Fake_Import_Status_Service    $import   Import status double.
	 * @param int                           $page     Requested page.
	 * @param int                           $per_page Requested page size.
	 * @return array{items: list<array>, has_more: bool} Listing payload.
	 */
	private function list_available(
		Fake_Catalog_Source_Posts_API $api,
		Fake_Import_Status_Service $import,
		int $page,
		int $per_page
	): array {
		$previous_secret = getenv( 'SAFE_PUBLISH_SHARED_SECRET' );
		set_test_env( 'SAFE_PUBLISH_SHARED_SECRET', 'posts-service-test-secret' );
		$service = new Posts_Read_Service(
			$api,
			new History_Repository(),
			$import,
			$this->createMock( Post_Type_Fetcher::class ),
			new Attention_Issues_Repository()
		);

		try {
			$result = $service->list_posts(
				array(
					'source_site_url' => 'https://source.example.com',
					'state'           => 'available',
					'page'            => $page,
					'per_page'        => $per_page,
					'post_type'       => 'post',
				)
			);
		} finally {
			set_test_env(
				'SAFE_PUBLISH_SHARED_SECRET',
				is_string( $previous_secret ) ? $previous_secret : null
			);
		}

		$this->assertIsArray( $result );

		return $result;
	}

	/**
	 * Extracts source_post_id values from a listing payload's rows.
	 *
	 * @param array $result Listing payload.
	 * @return int[] Source post ids in row order.
	 */
	private function ids( array $result ): array {
		return array_map(
			static fn( array $row ): int => $row['source_post_id'],
			$result['items']
		);
	}
}
