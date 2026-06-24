<?php
/**
 * Tests for the modified field's GMT contract in the listing payload.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Navigation_Ref_Rewriter;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;

/**
 * Modified Field Test Class.
 *
 * Cross-system contract: the source-side preparer emits modified_gmt as a
 * Z-marked GMT timestamp so destination-side comparisons stay correct across
 * timezones.
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
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Sets up service instances reused by the timezone-divergence cases.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->api        = new Source_Posts_API( new HTTP_Client() );
		$this->repository = new History_Repository();

		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		$this->import_service = new Post_Import_Service(
			$this->api,
			$media_importer,
			$content_processor,
			$this->repository,
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter(),
			new Attention_Issues_Repository()
		);
	}

	/**
	 * Verifies that the source-side listing preparer emits modified_gmt as
	 * a Z-marked GMT timestamp built from post_modified_gmt, so destination
	 * comparisons stay correct across timezones.
	 */
	public function test_prepared_modified_gmt_is_zmarked(): void {
		global $wpdb;

		// ARRANGE: Local post with the divergent modified / modified_gmt
		// pair we used to assert against on the destination side. Direct
		// $wpdb writes are needed because factory()->post->create() rewrites
		// post_modified.
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Test Post',
			)
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'         => '2024-07-15 11:00:00',
				'post_date_gmt'     => '2024-07-15 15:00:00',
				'post_modified'     => '2024-07-15 11:00:00',
				'post_modified_gmt' => '2024-07-15 15:00:00',
			),
			array( 'ID' => $post_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $post_id );

		$post = get_post( $post_id );
		$this->assertNotNull( $post );

		// ACT: Run the source-side preparer directly.
		$prepared = Source_Posts_API::prepare_listing_payload_from_post( $post );

		// ASSERT: modified_gmt is the Z-marked GMT timestamp; date_gmt
		// likewise carries the Z marker.
		$this->assertSame( '2024-07-15T15:00:00Z', $prepared['modified_gmt'] );
		$this->assertSame( '2024-07-15T15:00:00Z', $prepared['date_gmt'] );
	}

	/**
	 * Verifies that posts with the MySQL zero-date sentinel yield empty
	 * strings rather than malformed ISO timestamps. The catalog allowlist
	 * shouldn't admit auto-drafts (which is when WP writes the sentinel),
	 * but corrupted-by-import or DB-manipulated posts can carry it too.
	 */
	public function test_prepared_payload_handles_zero_date_sentinel(): void {
		global $wpdb;

		// ARRANGE: A post whose dates are explicitly the MySQL zero sentinel.
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Zero Date',
			)
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'         => '0000-00-00 00:00:00',
				'post_date_gmt'     => '0000-00-00 00:00:00',
				'post_modified'     => '0000-00-00 00:00:00',
				'post_modified_gmt' => '0000-00-00 00:00:00',
			),
			array( 'ID' => $post_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $post_id );

		$post = get_post( $post_id );
		$this->assertNotNull( $post );

		// ACT: Run the preparer.
		$prepared = Source_Posts_API::prepare_listing_payload_from_post( $post );

		// ASSERT: Zero dates collapse to empty strings, not to '0000-00-00T...Z'.
		$this->assertSame( '', $prepared['date_gmt'] );
		$this->assertSame( '', $prepared['modified_gmt'] );
	}

	/**
	 * Verifies that a row with a META_SOURCE_POST_ID but no items row is
	 * treated as Available under the active-row rule.
	 *
	 * The items table is the source of truth for the unified listing's
	 * routing label; a meta marker without history routes to Available.
	 */
	public function test_no_history_routes_to_available_when_items_row_missing(): void {
		// ARRANGE: a local post with the meta marker but no items row.
		$local_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Orphaned Import',
				'meta_input'  => array(
					Options::META_SOURCE_POST_ID => 3003,
				),
			)
		);
		$this->assertIsInt( $local_post_id );

		$posts = array(
			array(
				'id'           => 3003,
				'link'         => 'https://source.example.com/orphaned-import',
				'title'        => 'Orphaned Import',
				'modified_gmt' => '2024-07-15T15:00:00Z',
			),
		);

		// ACT: annotate the catalog row.
		$this->import_service->annotate_posts_with_import_status( $posts );

		// ASSERT: the active-row rule folds the row into Available; no items
		// row means no routing label and no history badge.
		$this->assertFalse( $posts[0]['is_imported'] );
		$this->assertSame( 'available', $posts[0]['local_state'] );
	}

	/**
	 * Inserts an items row anchoring an import snapshot at a specific GMT
	 * datetime. The repository's log_import_action always stamps NOW, so
	 * timezone-divergence tests need to bypass it and write the row directly.
	 *
	 * @param int    $post_id         Local post ID the items row points at.
	 * @param int    $source_post_id  Source post ID the items row records.
	 * @param string $import_date_gmt MySQL datetime (`Y-m-d H:i:s`) in GMT.
	 */
	private function seed_items_row(
		int $post_id,
		int $source_post_id,
		string $import_date_gmt
	): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Import_Items_Table::table_name(),
			array(
				'session_id'           => 1,
				'title'                => 'Seeded',
				'source_post_id'       => $source_post_id,
				'status'               => 'success',
				'post_id'              => $post_id,
				'error_message'        => null,
				'content_changes'      => null,
				'warnings'             => null,
				'has_previous_content' => 0,
				'rolled_back'          => 0,
				'import_date_gmt'      => $import_date_gmt,
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}
}
