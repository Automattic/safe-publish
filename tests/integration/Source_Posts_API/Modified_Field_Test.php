<?php
/**
 * Tests for the modified field's GMT contract across the listing payload and
 * the sync_status comparison.
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
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Options;

/**
 * Modified Field Test Class.
 *
 * Cross-system contract: modified_gmt comparisons happen in GMT so sync_status
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
			new Content_Media_Processor( $media_importer )
		);

		$this->import_service = new Post_Import_Service(
			$this->api,
			$media_importer,
			$content_processor,
			$this->repository,
			new Meta_Terms_Manager()
		);
	}

	/**
	 * Verifies that the source-side listing preparer emits modified_gmt as
	 * a Z-marked GMT timestamp built from post_modified_gmt, so the
	 * destination's sync_status comparison stays correct across timezones.
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
	 * Verifies that sync_status is "outdated" when the source is newer in
	 * UTC, even when site-local strings would suggest otherwise.
	 *
	 * Source in NY (UTC-4) modified at 15:00 UTC; destination Madrid
	 * (UTC+2) imported at 14:00 UTC. Site-local "11:00" vs "16:00"
	 * misleads; UTC says source is newer.
	 */
	public function test_sync_status_outdated_across_divergent_timezones(): void {
		// ARRANGE: Pin the destination to Madrid so the scenario is concrete.
		update_option( 'timezone_string', 'Europe/Madrid' );

		try {
			// ARRANGE: Imported post with an items row anchoring the snapshot
			// at 14:00 UTC.
			$local_post_id = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Local Post',
					'meta_input'  => array(
						Options::META_SOURCE_POST_ID => 999,
					),
				)
			);
			$this->seed_items_row( $local_post_id, 999, '2024-07-15 14:00:00' );

			// ARRANGE: Source payload as emitted by
			// prepare_listing_payload_from_post — modified_gmt is Z-marked GMT.
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
			// outdated — proving both sides were compared in UTC.
			$this->assertTrue( $posts[0]['is_imported'] );
			$this->assertSame(
				'outdated',
				$posts[0]['sync_status'],
				'sync_status must be "outdated" when the source is newer in UTC.'
			);
		} finally {
			delete_option( 'timezone_string' );
		}
	}

	/**
	 * Verifies that sync_status is "up-to-date" when the import snapshot is
	 * newer in UTC than the source.
	 *
	 * Mirror of the previous case — opposite verdict — to guard against
	 * an off-by-direction regression.
	 */
	public function test_sync_status_up_to_date_across_divergent_timezones(): void {
		// ARRANGE: Destination = Madrid (UTC+2 in July).
		update_option( 'timezone_string', 'Europe/Madrid' );

		try {
			// ARRANGE: Imported post with an items row anchoring the snapshot
			// at 16:00 UTC.
			$local_post_id = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Local Post',
					'meta_input'  => array(
						Options::META_SOURCE_POST_ID => 1001,
					),
				)
			);
			$this->seed_items_row(
				$local_post_id,
				1001,
				'2024-07-15 16:00:00'
			);

			// ARRANGE: Source modified at 15:00 UTC.
			// 15:00 UTC < 16:00 UTC, so the local snapshot is genuinely newer.
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

			// ASSERT: sync_status is up-to-date; the import snapshot is later.
			$this->assertTrue( $posts[0]['is_imported'] );
			$this->assertSame(
				'up-to-date',
				$posts[0]['sync_status'],
				'sync_status must be "up-to-date" when import snapshot is newer in UTC.'
			);
		} finally {
			delete_option( 'timezone_string' );
		}
	}

	/**
	 * Verifies that sync_status is "up-to-date" for a freshly imported draft,
	 * whose post_modified_gmt is the MySQL zero-date sentinel.
	 *
	 * Old code anchored on post_modified_gmt; strtotime reads the zero-date
	 * as year 0000 (not false), so fresh imports surfaced as "Outdated".
	 * Anchoring on import_date_gmt closes the hole.
	 */
	public function test_sync_status_up_to_date_for_fresh_draft(): void {
		// ARRANGE: A draft mirrors what wp_insert_post produces for a fresh
		// import — date-floating drafts leave post_modified_gmt at the
		// zero-date sentinel.
		$local_post_id = wp_insert_post(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Fresh Draft',
				'meta_input'  => array(
					Options::META_SOURCE_POST_ID => 2002,
				),
			)
		);
		$this->assertIsInt( $local_post_id );
		$this->assertGreaterThan( 0, $local_post_id );

		$local_post = get_post( $local_post_id );
		$this->assertNotNull( $local_post );
		$this->assertSame(
			'0000-00-00 00:00:00',
			$local_post->post_modified_gmt,
			'Pre-condition: fresh draft has the MySQL zero-date sentinel.'
		);

		// ARRANGE: Items row stamped after the source was modified, mirroring
		// a normal import flow.
		$this->seed_items_row( $local_post_id, 2002, '2024-07-15 14:00:01' );

		$posts = array(
			array(
				'id'           => 2002,
				'link'         => 'https://source.example.com/fresh-draft',
				'title'        => 'Fresh Draft',
				'modified_gmt' => '2024-07-15T14:00:00Z',
			),
		);

		// ACT: Annotate.
		$this->import_service->annotate_posts_with_import_status( $posts );

		// ASSERT: Fresh imports must read as up-to-date, not outdated.
		$this->assertTrue( $posts[0]['is_imported'] );
		$this->assertSame(
			'up-to-date',
			$posts[0]['sync_status'],
			'sync_status must be "up-to-date" for a freshly imported draft.'
		);
	}

	/**
	 * Verifies that sync_status is "unknown" when the items row is missing.
	 *
	 * The row is still surfaced as imported (the meta marker is the truth)
	 * but lacks the anchor needed to compute a verdict — the comparator
	 * returns null, which maps to unknown.
	 */
	public function test_sync_status_unknown_when_items_row_missing(): void {
		// ARRANGE: Imported post with no items row at all.
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

		// ACT: Annotate.
		$this->import_service->annotate_posts_with_import_status( $posts );

		// ASSERT: Imported is true (meta is present), sync_status is unknown
		// (no anchor to compare against).
		$this->assertTrue( $posts[0]['is_imported'] );
		$this->assertSame(
			'unknown',
			$posts[0]['sync_status'],
			'sync_status must be "unknown" when no items row anchors the comparison.'
		);
	}

	/**
	 * Verifies that sync_status is "unknown" when the source's modified_gmt
	 * is empty — the listing payload emits empty strings for zero-date
	 * sentinels, which the comparator can't parse.
	 */
	public function test_sync_status_unknown_when_source_modified_gmt_is_empty(): void {
		// ARRANGE: Imported post with a valid items row.
		$local_post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Imported',
				'meta_input'  => array(
					Options::META_SOURCE_POST_ID => 4004,
				),
			)
		);
		$this->seed_items_row( $local_post_id, 4004, '2024-07-15 14:00:00' );

		// ARRANGE: Source payload with empty modified_gmt — the shape the
		// preparer emits when the source post carries the zero-date sentinel.
		$posts = array(
			array(
				'id'           => 4004,
				'link'         => 'https://source.example.com/imported',
				'title'        => 'Imported',
				'modified_gmt' => '',
			),
		);

		// ACT: Annotate.
		$this->import_service->annotate_posts_with_import_status( $posts );

		// ASSERT: Imported but verdict can't be reached.
		$this->assertTrue( $posts[0]['is_imported'] );
		$this->assertSame(
			'unknown',
			$posts[0]['sync_status'],
			'sync_status must be "unknown" when the source date can\'t be parsed.'
		);
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
