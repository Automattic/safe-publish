<?php
/**
 * Source-scoping integration tests for subsite connections.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

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
use Safe_Publish\Tests\Integration\Source_Posts_API\Source_Posts_API_Test_Base;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;

/**
 * Guards source scoping when a destination imports from two subsites of one
 * host in turn, by repointing the single connected-site setting.
 *
 * The identity is derived from the connection, not the post permalink, so the
 * subsites (e.g. /blog and /news) tag content with distinct path-bearing
 * identities. Overlapping source IDs no longer collide: Lookups, re-imports,
 * block-ID/term remaps, and parent resolution each resolve per subsite.
 */
class Subsite_Source_Scoping_Test extends Source_Posts_API_Test_Base {

	/**
	 * Connection URL for the first subsite of the shared host.
	 */
	private const BLOG_URL = 'https://source.example.com/blog';

	/**
	 * Connection URL for the second subsite of the shared host.
	 */
	private const NEWS_URL = 'https://source.example.com/news';

	/**
	 * Post import service under test.
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
	 * Sets up the import service with real dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new History_Repository();

		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		$this->import_service = new Post_Import_Service(
			new Source_Posts_API( new HTTP_Client() ),
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
	 * Verifies that importing one source ID from two subsites of the same host
	 * creates two distinct destination posts, each tagged with its own subsite
	 * identity, instead of the second import overwriting the first.
	 */
	public function test_overlapping_ids_across_subsites_create_distinct_posts(): void {
		// ARRANGE: The same source post ID lives on both subsites.
		$source_id = 500;

		// ACT: Import it from /blog, then from /news.
		$blog = $this->import_under(
			self::BLOG_URL,
			$source_id,
			array( 'title' => 'Blog 500' )
		);
		$news = $this->import_under(
			self::NEWS_URL,
			$source_id,
			array( 'title' => 'News 500' )
		);

		// ASSERT: Two new posts, each scoped to its subsite.
		$this->assertTrue( $blog['success'] );
		$this->assertTrue( $news['success'] );
		$this->assertFalse(
			$news['existing'],
			'The second subsite import must create, not update.'
		);
		$this->assertNotSame( $blog['post_id'], $news['post_id'] );

		$this->assertSame(
			self::BLOG_URL,
			$this->source_identity( $blog['post_id'] )
		);
		$this->assertSame(
			self::NEWS_URL,
			$this->source_identity( $news['post_id'] )
		);
	}

	/**
	 * Verifies that re-importing the same source ID within one subsite updates
	 * the existing post rather than creating a duplicate.
	 */
	public function test_reimport_within_subsite_updates_existing_post(): void {
		// ARRANGE + ACT: Import source ID 600 from /blog twice.
		$source_id = 600;
		$first     = $this->import_under(
			self::BLOG_URL,
			$source_id,
			array( 'title' => 'First' )
		);
		$second    = $this->import_under(
			self::BLOG_URL,
			$source_id,
			array( 'title' => 'Second' )
		);

		// ASSERT: The second import updates the first post in place.
		$this->assertTrue( $first['success'] );
		$this->assertTrue( $second['success'] );
		$this->assertFalse( $first['existing'] );
		$this->assertTrue(
			$second['existing'],
			'Re-import within the same subsite must update.'
		);
		$this->assertSame( $first['post_id'], $second['post_id'] );

		$scoped = $this->import_service->find_imported_post(
			$source_id,
			self::BLOG_URL
		);
		$this->assertNotNull( $scoped );
		$this->assertSame( $first['post_id'], (int) $scoped->ID );
	}

	/**
	 * Verifies that block-ID post and term references remap to the destination
	 * tagged with the importing subsite, not a same-source-ID target imported
	 * from a different subsite.
	 */
	public function test_block_id_references_remap_to_the_matching_subsite(): void {
		// ARRANGE: A /blog and a /news target sharing one source post ID, and
		// likewise one source term ID.
		$post_source_id = 700;
		$term_source_id = 701;

		$blog_page = $this->seed_target_post( $post_source_id, self::BLOG_URL );
		$news_page = $this->seed_target_post( $post_source_id, self::NEWS_URL );
		$blog_term = $this->seed_target_term( $term_source_id, self::BLOG_URL );
		$news_term = $this->seed_target_term( $term_source_id, self::NEWS_URL );

		// ACT: Import a /blog post whose nav content references the source IDs.
		$result = $this->import_under(
			self::BLOG_URL,
			7100,
			array(
				'content' => $this->nav_block_content(
					$post_source_id,
					$term_source_id
				),
			)
		);

		// ASSERT: References resolve to the /blog targets only.
		$this->assertTrue( $result['success'] );
		$saved = (string) get_post_field( 'post_content', $result['post_id'] );

		$this->assertStringContainsString( '"id":' . $blog_page . ',', $saved );
		$this->assertStringContainsString( '"id":' . $blog_term . ',', $saved );
		$this->assertStringNotContainsString(
			'"id":' . $news_page . ',',
			$saved,
			'Must not remap to the other subsite post.'
		);
		$this->assertStringNotContainsString(
			'"id":' . $news_term . ',',
			$saved,
			'Must not remap to the other subsite term.'
		);
	}

	/**
	 * Verifies that source-parent resolution selects the parent imported from
	 * the importing subsite when both subsites hold the same source parent ID.
	 */
	public function test_parent_resolution_scopes_to_the_matching_subsite(): void {
		// ARRANGE: A /blog and a /news parent sharing one source parent ID.
		$parent_source_id = 800;

		$blog_parent = $this->seed_target_post(
			$parent_source_id,
			self::BLOG_URL,
			'page'
		);
		$this->seed_target_post(
			$parent_source_id,
			self::NEWS_URL,
			'page'
		);

		// ACT: Import a /blog child page whose source parent is that ID.
		$result = $this->import_under(
			self::BLOG_URL,
			8100,
			array( 'parent' => $parent_source_id ),
			'pages'
		);

		// ASSERT: The child is reparented to the /blog parent.
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			$blog_parent,
			(int) get_post( $result['post_id'] )->post_parent
		);
	}

	/**
	 * Verifies that a plain single-site connection (no subsite path) still tags
	 * imports with the host-only identity, byte-identical to prior behavior.
	 */
	public function test_single_site_identity_stays_host_only(): void {
		// ARRANGE + ACT: Import under a path-less connection.
		$result = $this->import_under(
			'https://source.example.com',
			900,
			array( 'title' => 'Plain' )
		);

		// ASSERT: The stored identity is the bare host, as before the change.
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			'https://source.example.com',
			$this->source_identity( $result['post_id'] )
		);
	}

	/**
	 * Verifies that two different hosts remain separately scoped, preserving
	 * the existing different-host multi-source behavior.
	 */
	public function test_distinct_hosts_remain_separately_scoped(): void {
		// ARRANGE + ACT: Import one source ID from two different hosts.
		$source_id = 1000;
		$first     = $this->import_under(
			'https://source.example.com',
			$source_id,
			array( 'title' => 'Host A' )
		);
		$second    = $this->import_under(
			'https://other-source.example.com',
			$source_id,
			array( 'title' => 'Host B' )
		);

		// ASSERT: Distinct posts, each tagged with its own host.
		$this->assertNotSame( $first['post_id'], $second['post_id'] );
		$this->assertSame(
			'https://source.example.com',
			$this->source_identity( $first['post_id'] )
		);
		$this->assertSame(
			'https://other-source.example.com',
			$this->source_identity( $second['post_id'] )
		);
	}

	/**
	 * Imports a single source post under the given connection and returns the
	 * import result.
	 *
	 * @param string $connection Connected source site URL to import from.
	 * @param int    $source_id  Source post ID.
	 * @param array  $overrides  Mock fresh-fetch overrides (title, content,
	 *                           parent, etc.).
	 * @param string $post_type  REST post-type endpoint (posts or pages).
	 * @return array Import result.
	 */
	private function import_under(
		string $connection,
		int $source_id,
		array $overrides = array(),
		string $post_type = 'posts'
	): array {
		update_option( Options::OPTION_CONNECTED_SITE_URL, $connection );
		$this->mock_post_overrides = $overrides;

		$session_id = $this->repository->create_session( $connection, 'single' );

		return $this->import_service->import_post(
			array(
				'id'        => $source_id,
				'title'     => $overrides['title'] ?? 'Post ' . $source_id,
				'link'      => $connection . '/item-' . $source_id,
				'post_type' => $post_type,
			),
			$session_id
		);
	}

	/**
	 * Returns the source site identity meta stored on a destination post.
	 *
	 * @param int $post_id Destination post ID.
	 * @return string Stored identity meta value.
	 */
	private function source_identity( int $post_id ): string {
		return (string) get_post_meta(
			$post_id,
			Options::META_SOURCE_SITE_URL,
			true
		);
	}

	/**
	 * Creates a destination post tagged with a source post ID and identity.
	 *
	 * @param int    $source_id Source post ID meta value.
	 * @param string $identity  Source site identity meta value.
	 * @param string $post_type Destination post type.
	 * @return int Created post ID.
	 */
	private function seed_target_post(
		int $source_id,
		string $identity,
		string $post_type = 'page'
	): int {
		$post_id = self::factory()->post->create(
			array( 'post_type' => $post_type )
		);
		$this->assertIsInt( $post_id );
		update_post_meta( $post_id, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta( $post_id, Options::META_SOURCE_SITE_URL, $identity );

		return $post_id;
	}

	/**
	 * Creates a destination term tagged with a source term ID and identity.
	 *
	 * @param int    $source_id Source term ID meta value.
	 * @param string $identity  Source site identity meta value.
	 * @return int Created term ID.
	 */
	private function seed_target_term( int $source_id, string $identity ): int {
		$term_id = self::factory()->term->create(
			array( 'taxonomy' => 'category' )
		);
		$this->assertIsInt( $term_id );
		update_term_meta( $term_id, Options::META_SOURCE_TERM_ID, $source_id );
		update_term_meta( $term_id, Options::META_SOURCE_TERM_URL, $identity );

		return $term_id;
	}

	/**
	 * Builds a core/navigation block wrapping a post-type and a taxonomy
	 * nav-link that reference the given source IDs.
	 *
	 * @param int $post_source_id Source post ID in the post-type nav-link.
	 * @param int $term_source_id Source term ID in the taxonomy nav-link.
	 * @return string Block markup.
	 */
	private function nav_block_content(
		int $post_source_id,
		int $term_source_id
	): string {
		$post_link = wp_json_encode(
			array(
				'id'    => $post_source_id,
				'kind'  => 'post-type',
				'label' => 'About',
				'url'   => 'https://source.example.com/blog/about',
			)
		);
		$term_link = wp_json_encode(
			array(
				'id'    => $term_source_id,
				'kind'  => 'taxonomy',
				'type'  => 'category',
				'label' => 'News',
				'url'   => 'https://source.example.com/blog/category/news',
			)
		);

		return implode(
			"\n",
			array(
				'<!-- wp:navigation -->',
				'<!-- wp:navigation-link ' . $post_link . ' /-->',
				'<!-- wp:navigation-link ' . $term_link . ' /-->',
				'<!-- /wp:navigation -->',
			)
		);
	}
}
