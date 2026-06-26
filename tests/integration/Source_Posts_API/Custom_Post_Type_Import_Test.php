<?php
/**
 * End-to-end import tests for custom post types whose rest_base differs from
 * their slug.
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
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\API\Source_Post_Type_Resolver;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;
use WP_Post;

/**
 * Drives Post_Import_Service::import_post() with a CPT exposed at a rest_base
 * that differs from its slug, asserting a destination post of that type is
 * actually created.
 *
 * The source mock serves the single-post endpoint only at the rest_base path
 * (wp/v2/sp_movies), never the slug path, so a successfully created post is
 * itself proof that the slug was resolved to its rest_base.
 */
class Custom_Post_Type_Import_Test extends Source_Posts_API_Test_Base {

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
	 * Registers the CPT, the source mock, and the import service.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Source_Post_Type_Resolver::reset_cache();

		// Serve the post-types map and the rest_base single-post endpoint
		// before the base class's posts|pages mock (priority 10) runs.
		add_filter(
			'pre_http_request',
			array( $this, 'mock_custom_cpt_source' ),
			5,
			3
		);

		register_post_type(
			'sp_movie',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'sp_movies',
			)
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
	 * Removes the source mock and unregisters the CPT.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'pre_http_request',
			array( $this, 'mock_custom_cpt_source' ),
			5
		);
		unregister_post_type( 'sp_movie' );
		parent::tearDown();
	}

	/**
	 * Serves the source's post-types map and the rest_base single-post
	 * endpoint, letting all other URLs fall through to the base mock.
	 *
	 * @param false|array|WP_Error $preempt Short-circuit value passed by WP.
	 * @param array                $args    Request args (unused).
	 * @param string               $url     Requested URL.
	 * @return false|array|WP_Error Mock response, or $preempt to defer.
	 */
	public function mock_custom_cpt_source(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		unset( $args );

		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( str_contains( $url, '/safe-publish/v1/catalog/post-types' ) ) {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => (string) wp_json_encode(
					array(
						array(
							'slug'      => 'sp_movie',
							'name'      => 'Movies',
							'label'     => 'Movies',
							'rest_base' => 'sp_movies',
						),
					)
				),
				'headers'  => array(),
			);
		}

		if ( 1 === preg_match( '#/wp-json/wp/v2/sp_movies/\d+#', $url ) ) {
			return $this->build_mock_post_response();
		}

		return $preempt;
	}

	/**
	 * Verifies that a single import creates a destination post of the custom
	 * post type, resolving its rest_base to reach the source.
	 */
	public function test_single_import_creates_custom_cpt_post(): void {
		// ARRANGE: Source returns the movie's fresh content.
		$this->mock_post_overrides = array(
			'title'   => 'Imported Movie',
			'content' => '<p>Movie content.</p>',
		);

		$post_data = array(
			'id'        => 7100,
			'title'     => 'Imported Movie',
			'content'   => '<p>Stale snapshot.</p>',
			'link'      => 'https://source.example.com/movies/imported-movie',
			'post_type' => 'sp_movie',
		);

		// ACT: Import via the single path (no session).
		$result = $this->import_service->import_post( $post_data );

		// ASSERT: The import succeeded and created an sp_movie post.
		$this->assertTrue(
			$result['success'],
			'Custom CPT import should succeed.'
		);

		$post = get_post( $result['post_id'] );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame(
			'sp_movie',
			$post->post_type,
			'Imported post must use the custom post type.'
		);
		$this->assertSame( 'Imported Movie', $post->post_title );
		$this->assertSame(
			7100,
			(int) get_post_meta(
				$post->ID,
				Options::META_SOURCE_POST_ID,
				true
			),
			'Imported post must record the source post ID.'
		);
	}

	/**
	 * Verifies that the bulk path imports a custom post type and logs a
	 * successful session item for it.
	 */
	public function test_bulk_import_creates_custom_cpt_post(): void {
		// ARRANGE: Source returns the movie's fresh content.
		$this->mock_post_overrides = array(
			'title'   => 'Bulk Movie',
			'content' => '<p>Bulk movie content.</p>',
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 7101,
			'title'     => 'Bulk Movie',
			'content'   => '<p>Stale snapshot.</p>',
			'link'      => 'https://source.example.com/movies/bulk-movie',
			'post_type' => 'sp_movie',
		);

		// ACT: Import via the bulk path (with a bulk session).
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: The import succeeded and created an sp_movie post.
		$this->assertTrue(
			$result['success'],
			'Bulk custom CPT import should succeed.'
		);

		$post = get_post( $result['post_id'] );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame(
			'sp_movie',
			$post->post_type,
			'Bulk-imported post must use the custom post type.'
		);
		$this->assertSame( 'Bulk Movie', $post->post_title );

		// ASSERT: A successful session item was logged for the source post.
		$items = $this->repository->get_session_items( $session_id );
		$this->assertCount( 1, $items );
		$this->assertSame( 'success', $items[0]['status'] );
		$this->assertSame( 7101, (int) $items[0]['source_post_id'] );
	}
}
