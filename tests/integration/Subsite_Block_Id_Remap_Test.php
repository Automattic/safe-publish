<?php
/**
 * Block-ID remap integration tests for a subsite source connection.
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
 * Guards the subsite ref-remap path: When the connected source URL carries a
 * subsite path (e.g. https://host/blog), block-ID references in imported
 * content must remap to destination IDs scoped to that subsite. The import
 * tags content with the path-bearing source identity, and the remap lookups
 * scope by the same value. Exercised through the real import path so the
 * wiring is covered.
 */
class Subsite_Block_Id_Remap_Test extends Source_Posts_API_Test_Base {

	/**
	 * Connection URL standing in for a subdirectory-subsite source. The import
	 * derives the source identity from it, so it doubles as the meta value
	 * tagged onto imported content.
	 */
	private const SUBSITE_SOURCE_URL = 'https://source.example.com/blog';

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
	 * Sets up test dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		// Simulate a subdirectory-subsite source: Connection URL with a path.
		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			self::SUBSITE_SOURCE_URL
		);

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
	 * Verifies that post and term block-ID references remap to destination IDs
	 * when the connected source URL carries a subsite path.
	 *
	 * The destination targets are tagged with the path-bearing identity, exactly
	 * as the import service writes them. A host-only lookup would miss them and
	 * leave the references pointing at the source IDs.
	 */
	public function test_remaps_post_and_term_references_on_subsite_source(): void {
		// ARRANGE: Destination targets tagged with the subsite identity, plus a
		// post whose nav content references their source IDs.
		$post_source_id = 99201;
		$term_source_id = 99202;

		$dest_page = self::factory()->post->create(
			array( 'post_type' => 'page' )
		);
		update_post_meta(
			$dest_page,
			Options::META_SOURCE_POST_ID,
			$post_source_id
		);
		update_post_meta(
			$dest_page,
			Options::META_SOURCE_SITE_URL,
			self::SUBSITE_SOURCE_URL
		);

		$dest_term = self::factory()->term->create(
			array( 'taxonomy' => 'category' )
		);
		update_term_meta(
			$dest_term,
			Options::META_SOURCE_TERM_ID,
			$term_source_id
		);
		update_term_meta(
			$dest_term,
			Options::META_SOURCE_TERM_URL,
			self::SUBSITE_SOURCE_URL
		);

		$this->mock_post_overrides = array(
			'content' => $this->nav_block_content(
				$post_source_id,
				$term_source_id
			),
		);

		$session_id = $this->repository->create_session(
			self::SUBSITE_SOURCE_URL,
			'bulk'
		);

		$post_data = array(
			'id'        => 7301,
			'title'     => 'Menu Host Post',
			'content'   => '<p>Stale snapshot.</p>',
			'link'      => 'https://source.example.com/blog/menu-host',
			'post_type' => 'posts',
		);

		// ACT: Import the post through the real import path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Both references were remapped to their destination IDs.
		$this->assertTrue( $result['success'], 'Import should succeed.' );

		$saved_content = (string) get_post_field(
			'post_content',
			$result['post_id']
		);

		$this->assertStringContainsString(
			'"id":' . $dest_page . ',',
			$saved_content,
			'Post reference must remap to the destination page ID.'
		);
		$this->assertStringContainsString(
			'"id":' . $dest_term . ',',
			$saved_content,
			'Term reference must remap to the destination term ID.'
		);
		$this->assertStringNotContainsString(
			'"id":' . $post_source_id . ',',
			$saved_content,
			'Source post ID must not survive the remap.'
		);
		$this->assertStringNotContainsString(
			'"id":' . $term_source_id . ',',
			$saved_content,
			'Source term ID must not survive the remap.'
		);
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
