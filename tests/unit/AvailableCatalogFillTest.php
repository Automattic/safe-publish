<?php
/**
 * Unit tests for the Available-chip catalog page fill.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Safe_Publish\Admin\Admin_Ajax_Controller;
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
		// ARRANGE: three catalog pages whose imported rows thin each page, so a
		// 3-row Available page only fills by pulling all three.
		$api        = new Fake_Catalog_Source_Posts_API();
		$api->pages = array(
			1 => $this->page( array( 1, 2, 3 ), true ),
			2 => $this->page( array( 4, 5, 6 ), true ),
			3 => $this->page( array( 7, 8, 9 ), true ),
		);

		$import                      = new Fake_Import_Status_Service();
		$import->imported_source_ids = array( 1, 2, 4, 8, 9 );

		// ACT: request the first Available page of three.
		$result = $this->list_available( $api, $import, 1, 3 );

		// ASSERT: the page holds the first three non-imported ids in order.
		$this->assertSame( array( 3, 5, 6 ), $this->ids( $result ) );

		// ASSERT: a fourth non-imported row (id 7) remains, so has_more is
		// true.
		$this->assertTrue( $result['has_more'] );

		// ASSERT: three fetches were needed, each at the source max page size.
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
		// ARRANGE: the source runs out on page two with no surplus row.
		$api        = new Fake_Catalog_Source_Posts_API();
		$api->pages = array(
			1 => $this->page( array( 1, 2, 3 ), true ),
			2 => $this->page( array( 4, 5 ), false ),
		);

		$import                      = new Fake_Import_Status_Service();
		$import->imported_source_ids = array( 1, 4 );

		// ACT: request a 3-row Available page.
		$result = $this->list_available( $api, $import, 1, 3 );

		// ASSERT: the three non-imported rows fill the page with none to spare.
		$this->assertSame( array( 2, 3, 5 ), $this->ids( $result ) );
		$this->assertFalse( $result['has_more'] );
		$this->assertSame( 2, count( $api->requested_per_pages ) );
	}

	/**
	 * Verifies that the scan stops at the fetch cap when non-imported rows are
	 * too sparse, returning an empty page that still reports has_more.
	 */
	public function test_caps_the_scan_when_non_imported_rows_are_too_sparse(): void {
		// ARRANGE: every page is fully imported and the source always claims
		// more, so the page can never fill.
		$api               = new Fake_Catalog_Source_Posts_API();
		$api->default_page = $this->page( array( 1, 2 ), true );

		$import                    = new Fake_Import_Status_Service();
		$import->mark_all_imported = true;

		// ACT: request a page that can never be filled.
		$result = $this->list_available( $api, $import, 1, 3 );

		// ASSERT: the scan stops at the cap and returns an empty page.
		$this->assertSame( array(), $result['items'] );
		$this->assertSame(
			Admin_Ajax_Controller::AVAILABLE_FILL_MAX_FETCHES,
			count( $api->requested_per_pages )
		);

		// ASSERT: has_more stays true so the client can page past the cap.
		$this->assertTrue( $result['has_more'] );
	}

	/**
	 * Verifies that a later page returns its own window of non-imported rows.
	 */
	public function test_returns_the_requested_window_for_a_later_page(): void {
		// ARRANGE: six non-imported rows spread across two catalog pages.
		$api        = new Fake_Catalog_Source_Posts_API();
		$api->pages = array(
			1 => $this->page( array( 10, 11, 12, 13 ), true ),
			2 => $this->page( array( 14, 15 ), false ),
		);

		$import = new Fake_Import_Status_Service();

		// ACT: request the second page of two.
		$result = $this->list_available( $api, $import, 2, 2 );

		// ASSERT: the window is the third and fourth non-imported rows.
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
	 * Invokes the private Available fill with the given doubles and paging.
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
		$controller = ( new ReflectionClass( Admin_Ajax_Controller::class ) )
			->newInstanceWithoutConstructor();
		set_private_property(
			Admin_Ajax_Controller::class,
			$controller,
			'api',
			$api
		);
		set_private_property(
			Admin_Ajax_Controller::class,
			$controller,
			'post_import_service',
			$import
		);

		$method = get_private_method(
			Admin_Ajax_Controller::class,
			'list_available_via_catalog'
		);

		$result = $method->invoke(
			$controller,
			'https://source.example.com',
			array(),
			array(
				'page'      => $page,
				'per_page'  => $per_page,
				'post_type' => 'post',
			)
		);

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
