<?php
/**
 * Bulk-import source-scoping integration tests for a subsite connection.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Options;
use WP_Ajax_UnitTestCase;

/**
 * Guards the bulk-import path for a subsite connection: Imports must be tagged
 * with the path-bearing source identity, and in-batch parent resolution must
 * scope by it. Mirrors the single-import scoping coverage so both paths stay in
 * step.
 */
class Subsite_Bulk_Import_Scoping_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;
	use Per_Source_Id_Post_Api_Mock_Trait;
	use Bulk_Import_Ajax_Trait;

	/**
	 * Subsite connection URL, which is also the identity tagged onto imports.
	 */
	private const BLOG_URL = 'https://source.example.com/blog';

	/**
	 * Sets up the bulk-import harness against the subsite connection.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->set_up_bulk_import_harness( self::BLOG_URL );
	}

	/**
	 * Tears down the bulk-import harness.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->tear_down_bulk_import_harness();
		parent::tearDown();
	}

	/**
	 * Verifies that a bulk batch imported from a subsite connection tags every
	 * post with the path-bearing identity and resolves an in-batch parent
	 * scoped by it.
	 */
	public function test_bulk_import_from_subsite_tags_identity_and_resolves_parent(): void {
		// ARRANGE: A parent page (10) and its child (20).
		$this->source_payloads = array(
			10 => array( 'parent' => 0 ),
			20 => array( 'parent' => 10 ),
		);

		// ACT: Dispatch the bulk import.
		$data = $this->dispatch_bulk_import(
			array(
				$this->payload_entry( 10 ),
				$this->payload_entry( 20 ),
			)
		);

		// ASSERT: Both succeed, are tagged with the subsite identity, and the
		// child is reparented to the in-batch parent.
		$this->assertSame( 2, $data['successful'] );
		$this->assertSame( 0, $data['failed'] );

		$parent_dest = $this->destination_id_for( 10 );
		$child_dest  = $this->destination_id_for( 20 );
		$this->assertNotSame( 0, $parent_dest );
		$this->assertNotSame( 0, $child_dest );

		$this->assertSame(
			self::BLOG_URL,
			get_post_meta( $parent_dest, Options::META_SOURCE_SITE_URL, true )
		);
		$this->assertSame(
			self::BLOG_URL,
			get_post_meta( $child_dest, Options::META_SOURCE_SITE_URL, true )
		);
		$this->assertSame(
			$parent_dest,
			(int) get_post( $child_dest )->post_parent
		);
	}

	/**
	 * Builds the per-source-id REST body for the trait, based on the subsite
	 * connection.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		if ( ! isset( $this->source_payloads[ $source_id ] ) ) {
			return null;
		}

		return $this->bulk_mock_body( $source_id, self::BLOG_URL );
	}

	/**
	 * Builds a posts_data entry for the given source ID.
	 *
	 * @param int $source_id Source post ID.
	 * @return array Payload entry.
	 */
	private function payload_entry( int $source_id ): array {
		return array(
			'id'        => $source_id,
			'title'     => "Source Post {$source_id}",
			'link'      => self::BLOG_URL . "/post-{$source_id}",
			'post_type' => 'pages',
		);
	}

	/**
	 * Returns the destination post ID tagged with the subsite identity for a
	 * source ID, or 0 when none exists.
	 *
	 * @param int $source_id Source post ID.
	 * @return int Destination post ID, or 0.
	 */
	private function destination_id_for( int $source_id ): int {
		$posts = get_posts(
			array(
				'post_type'        => 'page',
				'posts_per_page'   => 1,
				'post_status'      => 'any',
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => Options::META_SOURCE_POST_ID,
						'value' => $source_id,
					),
					array(
						'key'   => Options::META_SOURCE_SITE_URL,
						'value' => self::BLOG_URL,
					),
				),
			)
		);

		return count( $posts ) > 0 ? (int) $posts[0]->ID : 0;
	}
}
