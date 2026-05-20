<?php
/**
 * Tests for the modified field's GMT contract across the listing payload and
 * the has_update comparison.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;

/**
 * Modified Field Test Class.
 *
 * Cross-system contract: modified_gmt comparisons happen in GMT so has_update
 * is correct when source and destination sites live in different timezones.
 */
class Modified_Field_Test extends Source_Posts_API_Test_Base {

	/**
	 * Source Posts API instance.
	 *
	 * @var Source_Posts_API
	 */
	private Source_Posts_API $api;

	/**
	 * Post import service instance.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $import_service;

	/**
	 * Sets up service instances reused by the timezone-divergence cases.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->api = new Source_Posts_API( new HTTP_Client() );

		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer )
		);

		$this->import_service = new Post_Import_Service(
			$this->api,
			$media_importer,
			$content_processor,
			new History_Repository(),
			new Meta_Terms_Manager()
		);
	}

	/**
	 * Verifies that the prepared listing payload emits modified_gmt as a
	 * Z-marked GMT timestamp derived from the source's modified_gmt field,
	 * not from the source's site-local modified field.
	 */
	public function test_prepared_modified_gmt_is_zmarked(): void {
		// ARRANGE: Mock the listing endpoint with a post whose two modified
		// fields differ — modified is in NY local time, modified_gmt is UTC.
		$body = (string) wp_json_encode(
			array(
				array(
					'id'           => 555,
					'link'         => 'https://source.example.com/post',
					'title'        => array( 'rendered' => 'Test Post' ),
					'modified'     => '2024-07-15T11:00:00',
					'modified_gmt' => '2024-07-15T15:00:00',
				),
			)
		);

		$callback = static function ( $preempt, $args, $url ) use ( $body ) {
			unset( $args );
			if ( false !== $preempt ) {
				return $preempt;
			}
			if ( ! str_contains( $url, '/wp-json/wp/v2/posts?' ) ) {
				return $preempt;
			}
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => $body,
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $callback, 5, 3 );

		try {
			// ACT: Fetch posts through the public API.
			$result = $this->api->fetch_posts(
				'https://source.example.com',
				1
			);

			// ASSERT: modified_gmt is the Z-marked GMT timestamp, derived
			// from the source's modified_gmt field.
			$this->assertIsArray( $result );
			$this->assertCount( 1, $result );
			$this->assertSame(
				'2024-07-15T15:00:00Z',
				$result[0]['modified_gmt'],
				'Prepared modified_gmt must carry a Z marker.'
			);
		} finally {
			remove_filter( 'pre_http_request', $callback, 5 );
		}
	}

	/**
	 * Verifies that has_update is true when the source post is genuinely
	 * newer in UTC, even though the source's site-local modified string
	 * compares as earlier than the destination's site-local post_modified.
	 *
	 * This is the exact failure shape of the pre-fix comparison: source
	 * site in NY (UTC-4), destination site in Madrid (UTC+2), source
	 * modified at 15:00 UTC (= 11:00 NY), destination modified at 14:00
	 * UTC (= 16:00 Madrid). Raw site-local strings — "11:00" vs "16:00" —
	 * suggest the local copy is newer; UTC moments — 15:00 vs 14:00 —
	 * say the source is newer.
	 */
	public function test_has_update_correct_across_divergent_timezones(): void {
		global $wpdb;

		// ARRANGE: Pin the destination to Madrid so the scenario is concrete.
		update_option( 'timezone_string', 'Europe/Madrid' );

		try {
			// ARRANGE: Local post modified at 14:00 UTC (= 16:00 Madrid).
			$local_post_id = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Local Post',
					'meta_input'  => array(
						Options::META_SOURCE_POST_ID => 999,
					),
				)
			);

			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified'     => '2024-07-15 16:00:00',
					'post_modified_gmt' => '2024-07-15 14:00:00',
				),
				array( 'ID' => $local_post_id )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $local_post_id );

			// ARRANGE: Source post payload as emitted by
			// prepare_post_for_listing — modified_gmt is Z-marked GMT.
			// 15:00 UTC > 14:00 UTC, so the source is genuinely newer.
			$posts = array(
				array(
					'id'           => 999,
					'link'         => 'https://source.example.com/local-post',
					'title'        => 'Local Post',
					'modified_gmt' => '2024-07-15T15:00:00Z',
				),
			);

			// ACT: Annotate.
			$this->import_service->annotate_posts_with_import_status( $posts );

			// ASSERT: The post is recognized as imported and flagged as
			// having an update available — proving both sides were
			// compared in UTC.
			$this->assertTrue( $posts[0]['is_imported'] );
			$this->assertTrue(
				$posts[0]['has_update'],
				'has_update must be true when the source is newer in UTC.'
			);
		} finally {
			delete_option( 'timezone_string' );
		}
	}

	/**
	 * Verifies that has_update is false when the destination post is
	 * genuinely newer in UTC, even though the source's site-local modified
	 * string compares as later than the destination's site-local
	 * post_modified.
	 *
	 * Mirror of the previous case — same TZ divergence, opposite verdict —
	 * to guard against an off-by-direction regression.
	 */
	public function test_has_update_false_across_divergent_timezones(): void {
		global $wpdb;

		// ARRANGE: Destination = Madrid (UTC+2 in July).
		update_option( 'timezone_string', 'Europe/Madrid' );

		try {
			// ARRANGE: Local post modified at 16:00 UTC (= 18:00 Madrid).
			$local_post_id = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Local Post',
					'meta_input'  => array(
						Options::META_SOURCE_POST_ID => 1001,
					),
				)
			);

			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_modified'     => '2024-07-15 18:00:00',
					'post_modified_gmt' => '2024-07-15 16:00:00',
				),
				array( 'ID' => $local_post_id )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $local_post_id );

			// ARRANGE: Source modified at 15:00 UTC (= 11:00 NY).
			// 15:00 UTC < 16:00 UTC, so the local copy is genuinely newer.
			$posts = array(
				array(
					'id'           => 1001,
					'link'         => 'https://source.example.com/local-post',
					'title'        => 'Local Post',
					'modified_gmt' => '2024-07-15T15:00:00Z',
				),
			);

			// ACT: Annotate.
			$this->import_service->annotate_posts_with_import_status( $posts );

			// ASSERT: has_update is false; the local UTC moment is later.
			$this->assertTrue( $posts[0]['is_imported'] );
			$this->assertFalse(
				$posts[0]['has_update'],
				'has_update must be false when the local copy is newer in UTC.'
			);
		} finally {
			delete_option( 'timezone_string' );
		}
	}
}
