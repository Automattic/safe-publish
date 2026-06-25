<?php
/**
 * Media source URL derivation integration tests.
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
use WP_Error;

/**
 * Media Source URL Derivation Test Class.
 *
 * Guards VIPCMS-1987: on a subdirectory-multisite source the media import must
 * derive the source REST root from the configured connection URL (which carries
 * the subsite path, e.g. https://host/blog), not from the per-post
 * source_link via host-only parsing. The destination here is a single site; the
 * subsite source is simulated by configuring a connection URL that carries a
 * path, since the defect is in how the source URL string is derived.
 */
class Media_Source_Url_Derivation_Test extends Source_Posts_API_Test_Base {

	/**
	 * Connection URL standing in for a subdirectory-subsite source.
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
	 * REST URLs requested against the source wp/v2/media endpoint.
	 *
	 * @var array<int, string>
	 */
	private array $captured_media_urls = array();

	/**
	 * Sets up test dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		// Record media REST URLs at priority 1, before the priority-5 JSON
		// mock, then pass through so the mock still responds.
		add_filter(
			'pre_http_request',
			array( $this, 'record_media_api_url' ),
			1,
			3
		);

		// Return media JSON whose source_url is a .jpg the base-class image
		// mock serves, so the featured import resolves end-to-end.
		add_filter(
			'pre_http_request',
			array( $this, 'mock_media_api_request' ),
			5,
			3
		);

		// Simulate a subdirectory-subsite source: connection URL with a path.
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
	 * Tears down test state.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'pre_http_request',
			array( $this, 'record_media_api_url' ),
			1
		);
		remove_filter(
			'pre_http_request',
			array( $this, 'mock_media_api_request' ),
			5
		);
		parent::tearDown();
	}

	/**
	 * Records requests to the source wp/v2/media REST endpoint.
	 *
	 * Returns the unchanged preempt value so later filters still respond.
	 *
	 * @param false|array|WP_Error $preempt Early-return value passed by WP.
	 * @param array                $args    Request arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error The unchanged preempt value.
	 */
	public function record_media_api_url(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		unset( $args );

		if ( str_contains( $url, 'wp-json/wp/v2/media/' ) ) {
			$this->captured_media_urls[] = $url;
		}

		return $preempt;
	}

	/**
	 * Returns a mock wp/v2/media response with a fixture-backed source_url.
	 *
	 * @param false|array|WP_Error $preempt Early-return value passed by WP.
	 * @param array                $args    Request arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error Preemptive response or unchanged preempt.
	 */
	public function mock_media_api_request(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		unset( $args );

		if ( ! str_contains( $url, 'wp-json/wp/v2/media/' ) ) {
			return $preempt;
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array( 'source_url' => 'https://source.example.com/featured.jpg' )
			),
			'headers'  => array( 'content-type' => 'application/json' ),
		);
	}

	/**
	 * Verifies that the create path fetches the featured image from the subsite
	 * REST root, retaining the connection URL's path.
	 *
	 * On a subdirectory subsite the source REST root is .../blog/wp-json/. A
	 * host-only derivation drops the path and targets the network root site,
	 * where the media does not exist.
	 */
	public function test_create_path_featured_media_request_retains_subsite_path(): void {
		// ARRANGE: A new post on the subsite source with a featured image.
		$this->mock_post_overrides = array( 'featured_media' => 100 );

		$session_id = $this->repository->create_session(
			self::SUBSITE_SOURCE_URL,
			'bulk'
		);

		$post_data = array(
			'id'             => 7101,
			'title'          => 'Subsite Post With Featured Image',
			'content'        => '<p>Content.</p>',
			'link'           => 'https://source.example.com/blog/subsite-featured',
			'featured_media' => 100,
			'post_type'      => 'posts',
		);

		// ACT: Import the new post (create path / handle_new_post).
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import succeeds and the media request kept the subsite path.
		$this->assertTrue( $result['success'], 'Import should succeed.' );
		$this->assertContains(
			'https://source.example.com/blog/wp-json/wp/v2/media/100',
			$this->captured_media_urls,
			'Featured-media REST request must target the subsite REST root, not the network root.'
		);
	}

	/**
	 * Verifies that the update path fetches the featured image from the subsite
	 * REST root, retaining the connection URL's path.
	 *
	 * The attachment is deleted between imports so the second import re-fetches
	 * the media instead of reusing the cached attachment.
	 */
	public function test_update_path_featured_media_request_retains_subsite_path(): void {
		// ARRANGE: Import the post once so it exists on the destination.
		$this->mock_post_overrides = array( 'featured_media' => 100 );

		$session_id = $this->repository->create_session(
			self::SUBSITE_SOURCE_URL,
			'bulk'
		);

		$post_data = array(
			'id'             => 7102,
			'title'          => 'Subsite Post For Update Path',
			'content'        => '<p>Content.</p>',
			'link'           => 'https://source.example.com/blog/subsite-update',
			'featured_media' => 100,
			'post_type'      => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'First import should succeed.' );

		// Delete the imported attachment so the re-import re-fetches the media
		// instead of reusing the deduplicated attachment.
		wp_delete_attachment(
			(int) get_post_thumbnail_id( $first['post_id'] ),
			true
		);
		$this->captured_media_urls = array();

		// ACT: Re-import the same post (update path / handle_imported_post).
		$second = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Re-import succeeds and media request kept the subsite path.
		$this->assertTrue( $second['success'], 'Re-import should succeed.' );
		$this->assertTrue(
			$second['existing'],
			'Re-import should hit the update path.'
		);
		$this->assertContains(
			'https://source.example.com/blog/wp-json/wp/v2/media/100',
			$this->captured_media_urls,
			'Featured-media REST request must target the subsite REST root on the update path.'
		);
	}

	/**
	 * Verifies that content images are imported using the configured connection
	 * URL even when the per-post source_link is empty.
	 *
	 * An empty source_link must not silently skip media processing; the source
	 * REST root comes from the connection, so inline images are still
	 * sideloaded and their URLs rewritten to the destination library.
	 */
	public function test_content_images_imported_when_source_link_empty(): void {
		// ARRANGE: A post whose source_link is empty but whose content has an
		// inline image from the configured source.
		$this->mock_post_overrides = array(
			'content' => '<p><img src="https://source.example.com/inline-empty-link.jpg" alt="Inline"></p>',
		);

		$session_id = $this->repository->create_session(
			self::SUBSITE_SOURCE_URL,
			'bulk'
		);

		$post_data = array(
			'id'        => 7103,
			'title'     => 'Post With Empty Source Link',
			'content'   => '<p>Stale snapshot.</p>',
			'link'      => '',
			'post_type' => 'posts',
		);

		// ACT: Import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import succeeds and the inline image was sideloaded locally.
		$this->assertTrue( $result['success'], 'Import should succeed.' );

		$saved_content = get_post_field( 'post_content', $result['post_id'] );
		$this->assertStringNotContainsString(
			'source.example.com/inline-empty-link.jpg',
			$saved_content,
			'Inline image must be imported, not left pointing at the source.'
		);
		$this->assertStringContainsString(
			'wp-content/uploads',
			$saved_content,
			'Inline image URL must be rewritten to the destination library.'
		);
	}
}
