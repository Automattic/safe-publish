<?php
/**
 * Public posts reader integration contracts.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\Admin\Posts_Read_Service;
use Safe_Publish\API\Post_Type_Fetcher;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Options;
use WP_Error;

require_once __DIR__ . '/Integration_Test_Case.php';

/**
 * Verifies that direct service calls preserve fallback and persistence.
 */
class Posts_Read_Service_Test extends Integration_Test_Case {

	/**
	 * Verifies that partial sync failures preserve successful timestamp writes.
	 */
	public function test_partial_sync_persists_returned_timestamps(): void {
		// ARRANGE: Three imported posts across two catalog type groups.
		$history = new History_Repository();
		$first   = $this->seed_item( $history, 41, 'post' );
		$missing = $this->seed_item( $history, 42, 'post' );
		$failed  = $this->seed_item( $history, 43, 'page' );
		$import  = $this->createMock( Post_Import_Service::class );
		$import->expects( $this->once() )
			->method( 'fetch_imported_posts_by_source_ids' )
			->with( array( 41, 42, 43 ), 'https://source.example.com/blog' )
			->willReturn(
				array(
					41 => get_post( $first ),
					42 => get_post( $missing ),
					43 => get_post( $failed ),
				)
			);
		$api = $this->createMock( Source_Posts_API::class );
		$api->expects( $this->exactly( 2 ) )->method( 'fetch_posts' )
			->willReturnCallback(
				static function (
					string $url,
					array $_auth,
					array $args
				): array|WP_Error {
					self::assertSame(
						'https://source.example.com/blog/',
						$url
					);
					if ( 'page' === $args['post_type'] ) {
						return new WP_Error( 'offline', 'Source unavailable.' );
					}
					self::assertSame( array( 41, 42 ), $args['include'] );
					return array(
						'items' => array(
							array(
								'id'           => 41,
								'modified_gmt' => '2026-02-02T00:00:00Z',
							),
						),
					);
				}
			);
		$service = $this->service( $api, $history, $import );

		// ACT: Duplicates are removed before fetching and persisting results.
		$result = $service->sync_status_batch(
			array( 'source_ids' => array( 41, '42', 43, 41, 0 ) )
		);

		// ASSERT: Successful groups survive another group's network failure.
		$this->assertSame(
			array(
				'statuses' => array(
					41 => array( 'status' => 'outdated' ),
					42 => array( 'status' => 'missing' ),
					43 => array( 'status' => 'unreachable' ),
				),
			),
			$result
		);
		$items = $history->get_items_for_posts(
			array( $first, $missing, $failed )
		);
		$this->assertSame(
			'2026-02-02 00:00:00',
			$items[ $first ]['source_modified_gmt']
		);
		$this->assertSame(
			'2025-01-01 00:00:00',
			$items[ $missing ]['source_modified_gmt']
		);
		$this->assertSame(
			'2025-01-01 00:00:00',
			$items[ $failed ]['source_modified_gmt']
		);
	}

	/**
	 * Verifies that local rows remain visible when their source is unreachable.
	 */
	public function test_local_listing_preserves_fallback_and_filters(): void {
		// ARRANGE: A connected subsite has matching and nonmatching imports.
		$history = new History_Repository();
		$post_id = $this->seed_item( $history, 51, 'post' );
		$this->seed_item( $history, 52, 'page' );
		$this->seed_item( $history, 53, 'post', 'Different item' );
		$api = $this->createMock( Source_Posts_API::class );
		$api->expects( $this->once() )->method( 'fetch_posts' )
			->with(
				'https://source.example.com/blog/',
				$this->anything(),
				array(
					'post_type' => 'post',
					'include'   => array( 51 ),
				)
			)
			->willReturn( new WP_Error( 'offline', 'Source unavailable.' ) );
		$service = $this->service(
			$api,
			$history,
			$this->createMock( Post_Import_Service::class )
		);

		// ACT: Query the imported Posts chip through its public interface.
		$result = $service->list_posts(
			array(
				'source_site_url' => 'https://source.example.com/blog/',
				'state'           => 'up-to-date',
				'post_type'       => 'post',
				'search'          => 'Neutral',
				'per_page'        => 1,
			)
		);

		// ASSERT: The local row remains usable and the page filter applies.
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 51, $result['items'][0]['source_post_id'] );
		$this->assertSame( $post_id, $result['items'][0]['post_id'] );
		$this->assertSame( 'Neutral item', $result['items'][0]['title'] );
		$this->assertSame( '', $result['items'][0]['link'] );
		$this->assertSame( 'up-to-date', $result['state'] );
		$this->assertFalse( $result['has_more'] );
	}

	/**
	 * Seeds a neutral successful import under the connected subsite.
	 *
	 * @param History_Repository $history Import repository.
	 * @param int                $source_id Source post ID.
	 * @param string             $type Post type.
	 * @param string             $title Item title.
	 * @return int Destination post ID.
	 */
	private function seed_item(
		History_Repository $history,
		int $source_id,
		string $type,
		string $title = 'Neutral item'
	): int {
		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			'https://source.example.com/blog/'
		);
		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', 'posts-service-test-secret' );
		}
		$session = $history->create_session(
			'https://source.example.com/blog/'
		);
		$this->assertIsInt( $session );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => $type,
				'post_status' => 'publish',
			)
		);
		$this->assertIsInt( $post_id );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			Import_Items_Table::table_name(),
			array(
				'session_id'          => $session,
				'title'               => $title,
				'source_post_id'      => $source_id,
				'status'              => 'success',
				'post_id'             => $post_id,
				'import_date_gmt'     => '2026-01-01 00:00:00',
				'source_modified_gmt' => '2025-01-01 00:00:00',
			)
		);
		return $post_id;
	}

	/**
	 * Constructs the reader without AJAX registration or request globals.
	 *
	 * @param Source_Posts_API    $api Catalog double.
	 * @param History_Repository  $history Real import repository.
	 * @param Post_Import_Service $import Import lookup double.
	 * @return Posts_Read_Service Reader.
	 */
	private function service(
		Source_Posts_API $api,
		History_Repository $history,
		Post_Import_Service $import
	): Posts_Read_Service {
		return new Posts_Read_Service(
			$api,
			$history,
			$import,
			$this->createMock( Post_Type_Fetcher::class ),
			new Attention_Issues_Repository()
		);
	}
}
