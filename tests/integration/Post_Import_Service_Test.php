<?php
/**
 * Post_Import_Service integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\Admin\Session_Rollback_Service;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\Source_Posts_API\Source_Posts_API_Test_Base;
use Safe_Publish\Utils\Options;
use WP_Error;

/**
 * Post Import Service Test Class.
 *
 * Extends the media-aware base class so that image downloads are intercepted
 * by the existing HTTP mock infrastructure.
 */
class Post_Import_Service_Test extends Source_Posts_API_Test_Base {

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

		// Intercept wp/v2/media JSON API calls at higher priority than the
		// base-class image mock (priority 10) so the API endpoint returns
		// valid JSON before the image URL itself is fetched.
		add_filter( 'pre_http_request', array( $this, 'mock_media_api_request' ), 5, 3 );

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
			new Meta_Terms_Manager()
		);
	}

	/**
	 * Tears down test state.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_media_api_request' ), 5 );
		parent::tearDown();
	}

	/**
	 * Intercepts wp/v2/media JSON API requests and returns a mock response
	 * whose `source_url` points to a `.jpg` URL that the base-class image mock
	 * can then serve as a real fixture file.
	 *
	 * Registered at priority 5 — runs before the base-class image mock at 10.
	 *
	 * @param false|array|WP_Error $preempt Early-return value passed by WP.
	 * @param array                $args    Request arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error Preemptive response or false to let later filters run.
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
	 * Verifies that import_post() fails when an inline image cannot be downloaded.
	 *
	 * When processed content contains an image whose download returns a non-200
	 * response, the import must return success: false with a descriptive error and
	 * must not create a post with the broken staging URL left in the content.
	 */
	public function test_import_fails_when_image_cannot_be_downloaded(): void {
		// ARRANGE: Configure fresh-content response to include a broken image URL.
		// The base-class HTTP mock returns 404 for any URL containing 'nonexistent'.
		$this->mock_post_overrides = array(
			'content' => '<p>See image: <img src="https://source.example.com/nonexistent-broken.jpg" alt="broken"></p>',
		);

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'        => 8001,
			'title'     => 'Post With Broken Image',
			'content'   => '<p>Stale snapshot content.</p>',
			'link'      => 'https://source.example.com/post-with-broken-image',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import must fail with a message identifying the broken URL.
		$this->assertFalse( $result['success'], 'Import should fail when an image cannot be downloaded.' );
		$this->assertStringContainsString(
			'nonexistent-broken.jpg',
			$result['error'],
			'Error message should include the failing image URL.'
		);

		// ASSERT: No post should have been created with the broken staging URL in its content.
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '8001',
				)
			),
			'No post should be created when an image import fails.'
		);
	}

	/**
	 * Verifies that import fails when a Gutenberg HTML block contains an image
	 * that cannot be downloaded.
	 *
	 * Failures from images processed through content_media_processor inside block
	 * handlers (html, text, embed, default) must be propagated to the import
	 * service so the import can be aborted.
	 */
	public function test_import_fails_when_gutenberg_html_block_image_cannot_be_downloaded(): void {
		// ARRANGE: Configure fresh-content response with a Gutenberg custom HTML block
		// wrapping a broken image. The base-class HTTP mock returns 404 for any URL
		// containing 'nonexistent', causing content_media_processor to record the failure.
		$broken_url                = 'https://source.example.com/nonexistent-gutenberg-html.jpg';
		$this->mock_post_overrides = array(
			'content' => "<!-- wp:html -->\n<img src=\"{$broken_url}\" alt=\"Broken\" />\n<!-- /wp:html -->",
		);

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'        => 8002,
			'title'     => 'Post With Broken Gutenberg HTML Block Image',
			'content'   => '<p>Stale snapshot content.</p>',
			'link'      => 'https://source.example.com/post-with-broken-gutenberg-image',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import must fail with a message identifying the broken URL.
		$this->assertFalse( $result['success'], 'Import should fail when a Gutenberg HTML block image cannot be downloaded.' );
		$this->assertStringContainsString(
			'nonexistent-gutenberg-html.jpg',
			$result['error'],
			'Error message should include the failing image URL.'
		);

		// ASSERT: No post should have been created.
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '8002',
				)
			),
			'No post should be created when a Gutenberg HTML block image import fails.'
		);
	}

	/**
	 * Verifies that import fails when a Gutenberg core/video block contains a
	 * video that cannot be downloaded.
	 *
	 * The core/video block is processed by Content_Processor::process_media_block(),
	 * which calls import_source_media_as_attachment() directly and must track
	 * failures in Content_Processor::$failed_media so the import service aborts.
	 */
	public function test_import_fails_when_gutenberg_video_block_cannot_be_downloaded(): void {
		// ARRANGE: A Gutenberg core/video block whose src URL will return 404.
		$broken_url                = 'https://source.example.com/nonexistent-video.mp4';
		$this->mock_post_overrides = array(
			'content' => '<!-- wp:video {"src":"' . $broken_url . '"} -->'
				. "\n<figure class=\"wp-block-video\"><video controls src=\"{$broken_url}\"></video></figure>"
				. "\n<!-- /wp:video -->",
		);

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'        => 8003,
			'title'     => 'Post With Broken Gutenberg Video Block',
			'content'   => '<p>Stale snapshot content.</p>',
			'link'      => 'https://source.example.com/post-with-broken-gutenberg-video',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import must fail with a message identifying the broken URL.
		$this->assertFalse( $result['success'], 'Import should fail when a Gutenberg video block source cannot be downloaded.' );
		$this->assertStringContainsString(
			'nonexistent-video.mp4',
			$result['error'],
			'Error message should include the failing video URL.'
		);

		// ASSERT: No post should have been created.
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '8003',
				)
			),
			'No post should be created when a Gutenberg video block import fails.'
		);
	}

	/**
	 * Verifies that import fails when re-importing an existing post whose fresh
	 * content contains a broken image.
	 *
	 * The update path (handle_imported_post) must abort before saving and must
	 * leave the existing post content unchanged.
	 */
	public function test_import_fails_on_update_path_when_image_cannot_be_downloaded(): void {
		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		// ARRANGE: Import the post once with clean content so it exists in the DB.
		$post_data = array(
			'id'        => 8004,
			'title'     => 'Post For Update-Path Test',
			'content'   => '<p>Original clean content.</p>',
			'link'      => 'https://source.example.com/post-update-path-test',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'First import should succeed.' );
		$post_id          = $first['post_id'];
		$original_content = get_post_field( 'post_content', $post_id );

		// ARRANGE: Configure fresh content to include a broken image URL.
		$broken_url                = 'https://source.example.com/nonexistent-update-path.jpg';
		$this->mock_post_overrides = array(
			'content' => '<p>New content: <img src="' . $broken_url . '" alt="broken"></p>',
		);

		// ACT: Re-import the same post — hits the update path (handle_imported_post).
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import must fail with a message identifying the broken URL.
		$this->assertFalse( $result['success'], 'Update import should fail when an image cannot be downloaded.' );
		$this->assertStringContainsString(
			'nonexistent-update-path.jpg',
			$result['error'],
			'Error message should include the failing image URL.'
		);

		// ASSERT: The existing post must not have been overwritten.
		$this->assertSame(
			$original_content,
			get_post_field( 'post_content', $post_id ),
			'Existing post content must remain unchanged when update import fails.'
		);
	}

	/**
	 * Verifies that import fails when a Gutenberg core/audio block contains an
	 * audio file that cannot be downloaded.
	 *
	 * The core/audio block is processed by Content_Processor::process_media_block(),
	 * which calls import_source_media_as_attachment() directly and must track
	 * failures in Content_Processor::$failed_media so the import service aborts.
	 */
	public function test_import_fails_when_gutenberg_audio_block_cannot_be_downloaded(): void {
		// ARRANGE: A Gutenberg core/audio block whose src URL will return 404.
		$broken_url                = 'https://source.example.com/nonexistent-audio.mp3';
		$this->mock_post_overrides = array(
			'content' => '<!-- wp:audio {"src":"' . $broken_url . '"} -->'
				. "\n<figure class=\"wp-block-audio\"><audio controls src=\"{$broken_url}\"></audio></figure>"
				. "\n<!-- /wp:audio -->",
		);

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'        => 8005,
			'title'     => 'Post With Broken Gutenberg Audio Block',
			'content'   => '<p>Stale snapshot content.</p>',
			'link'      => 'https://source.example.com/post-with-broken-gutenberg-audio',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import must fail with a message identifying the broken URL.
		$this->assertFalse( $result['success'], 'Import should fail when a Gutenberg audio block source cannot be downloaded.' );
		$this->assertStringContainsString(
			'nonexistent-audio.mp3',
			$result['error'],
			'Error message should include the failing audio URL.'
		);

		// ASSERT: No post should have been created.
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '8005',
				)
			),
			'No post should be created when a Gutenberg audio block import fails.'
		);
	}

	/**
	 * Verifies that import fails when a Gutenberg core/gallery block contains an
	 * image that cannot be downloaded.
	 *
	 * The traditional gallery format stores image URLs in attrs['images'].
	 * Content_Processor::process_gallery_block() must track the failure in
	 * $failed_media so the import service aborts.
	 */
	public function test_import_fails_when_gutenberg_gallery_block_cannot_be_downloaded(): void {
		// ARRANGE: A Gutenberg core/gallery block (traditional attrs format) with a broken image URL.
		$broken_url = 'https://source.example.com/nonexistent-gallery.jpg';
		$attrs_json = wp_json_encode(
			array(
				'images' => array(
					array(
						'url' => $broken_url,
						'id'  => 1,
					),
				),
			)
		);

		$this->mock_post_overrides = array(
			'content' => '<!-- wp:gallery ' . $attrs_json . ' -->'
				. "\n<figure class=\"wp-block-gallery\"><ul class=\"blocks-gallery-grid\">"
				. "<li class=\"blocks-gallery-item\"><figure><img src=\"{$broken_url}\" alt=\"\" /></figure></li>"
				. '</ul></figure>'
				. "\n<!-- /wp:gallery -->",
		);

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'        => 8006,
			'title'     => 'Post With Broken Gutenberg Gallery Block',
			'content'   => '<p>Stale snapshot content.</p>',
			'link'      => 'https://source.example.com/post-with-broken-gutenberg-gallery',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import must fail with a message identifying the broken URL.
		$this->assertFalse( $result['success'], 'Import should fail when a Gutenberg gallery image cannot be downloaded.' );
		$this->assertStringContainsString(
			'nonexistent-gallery.jpg',
			$result['error'],
			'Error message should include the failing gallery image URL.'
		);

		// ASSERT: No post should have been created.
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '8006',
				)
			),
			'No post should be created when a Gutenberg gallery block image import fails.'
		);
	}

	/**
	 * Verifies that import aborts when no source site URL is configured.
	 *
	 * Covers both the create path (handle_new_post) and the update path
	 * (handle_imported_post) to confirm both abort correctly.
	 */
	public function test_import_aborts_when_source_site_url_is_not_configured(): void {
		// ARRANGE: Remove the source URL configured by the base setUp().
		delete_option( Options::OPTION_CONNECTED_SITE_URL );

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'        => 9901,
			'title'     => 'Fetch Failure Test Post',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/fetch-failure-test',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import a new post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import fails and no post was created.
		$this->assertFalse( $result['success'], 'Import should fail when no source URL is configured.' );
		$this->assertIsString(
			$result['error'],
			'An error message should be returned.'
		);
		$this->assertNotSame(
			'',
			$result['error'],
			'A non-empty error message should be returned.'
		);
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'      => 'post',
					'posts_per_page' => 1,
					'meta_key'       => 'safe_publish_source_post_id',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'     => '9901',
				)
			),
			'No post should be created when the fetch fails.'
		);

		// ACT: Simulate an existing post by re-configuring the URL, importing
		// once to create it, then removing the URL and re-importing.
		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );
		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		$update_result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Update path also aborts correctly.
		$this->assertFalse( $update_result['success'], 'Re-import should fail when no source URL is configured.' );
		$this->assertIsString( $update_result['error'] );
		$this->assertNotSame( '', $update_result['error'] );
	}

	/**
	 * Verifies that the featured image is imported when bulk re-importing an
	 * existing post.
	 *
	 * This guards the update path in handle_imported_post(): if the
	 * set_post_thumbnail() call were accidentally removed there, the thumbnail
	 * would silently stop being set on re-import and no other test would catch it.
	 */
	public function test_featured_image_is_imported_on_bulk_reimport(): void {
		// ARRANGE: Import a post with a featured image for the first time.
		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'             => 9001,
			'title'          => 'Post With Featured Image',
			'content'        => '<p>Content.</p>',
			'link'           => 'https://source.example.com/featured-image-post',
			'featured_media' => 100,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array( 'featured_media' => 100 );

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		// Remove the thumbnail so that only the re-import can restore it.
		delete_post_thumbnail( $first['post_id'] );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $first['post_id'] ) );

		// ACT: Re-import the same post (hits the update path).
		$second = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: The update path re-applies the featured image.
		$this->assertTrue( $second['success'] );
		$this->assertTrue( $second['existing'] );
		$this->assertGreaterThan(
			0,
			(int) get_post_thumbnail_id( $second['post_id'] ),
			'Bulk re-import must re-apply the featured image via the update path.'
		);
	}

	/**
	 * Verifies that a failed media import from one post does not bleed into the
	 * next post in a bulk import sequence.
	 *
	 * When the import service instance is reused across multiple posts (as in a
	 * bulk loop), a previous post's failed_media must be reset before the next
	 * post is processed. Posts with empty content must not inherit failures from
	 * the preceding import.
	 */
	public function test_failed_media_does_not_bleed_into_subsequent_import(): void {
		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		// ARRANGE: Post 8101 — has a broken image; import must fail.
		$broken_url                = 'https://source.example.com/nonexistent-bleed-test.jpg';
		$this->mock_post_overrides = array(
			'content' => '<p><img src="' . $broken_url . '" alt="broken"></p>',
		);

		$result_a = $this->import_service->import_post(
			array(
				'id'        => 8101,
				'title'     => 'Post With Broken Image',
				'content'   => '<p>Stale content.</p>',
				'link'      => 'https://source.example.com/post-bleed-a',
				'post_type' => 'posts',
			),
			$session_id
		);

		$this->assertFalse( $result_a['success'], 'First import should fail due to broken image.' );

		// ARRANGE: Post 8102 — empty content; import must succeed without inheriting
		// Post 8101's failed_media.
		$this->mock_post_overrides = array( 'content' => '' );

		// ACT: Import the second post using the same service instance (simulating bulk loop).
		$result_b = $this->import_service->import_post(
			array(
				'id'        => 8102,
				'title'     => 'Post With Empty Content',
				'content'   => '<p>Stale content.</p>',
				'link'      => 'https://source.example.com/post-bleed-b',
				'post_type' => 'posts',
			),
			$session_id
		);

		// ASSERT: The second import must succeed — stale failures must not bleed over.
		$this->assertTrue(
			$result_b['success'],
			'Second import must succeed and must not inherit the previous post\'s failed media.'
		);
	}

	/**
	 * Verifies that the production URL is present in both the block comment
	 * JSON attrs and innerHTML after a successful core/video block import.
	 *
	 * Content_Processor::process_media_block() must update attrs['src']/attrs['id'],
	 * innerHTML, and innerContent so the staging URL is fully replaced.
	 */
	public function test_video_block_innerHTML_is_updated_after_successful_import(): void {
		// ARRANGE: Use a .jpg URL so the existing HTTP/fixture mocking handles the
		// sideload end-to-end. The test is about innerHTML updating, not file type.
		$source_url                = 'https://source.example.com/uploads/clip.jpg';
		$this->mock_post_overrides = array(
			'content' => '<!-- wp:video {"src":"' . $source_url . '"} -->'
				. "\n<figure class=\"wp-block-video\"><video controls src=\"{$source_url}\"></video></figure>"
				. "\n<!-- /wp:video -->",
		);

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'        => 8201,
			'title'     => 'Post With Gutenberg Video Block',
			'content'   => '<p>Stale content.</p>',
			'link'      => 'https://source.example.com/post-with-video-block',
			'post_type' => 'posts',
		);

		// ACT: Import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import must succeed.
		$this->assertTrue( $result['success'], 'Import should succeed for a valid video block.' );

		$saved_content = get_post_field( 'post_content', $result['post_id'] );

		// ASSERT: Production URL must appear in the block comment JSON attrs.
		$this->assertStringContainsString(
			'"src":"' . get_site_url(),
			$saved_content,
			'Production URL must appear in the block comment JSON attrs after a successful video block import.'
		);

		// ASSERT: Production URL must appear in an HTML src attribute.
		$this->assertStringContainsString(
			'src="' . get_site_url(),
			$saved_content,
			'Production URL must appear in an HTML src attribute in saved post content after a successful video block import.'
		);

		// ASSERT: The staging URL must not appear anywhere in saved post content.
		$this->assertStringNotContainsString(
			'source.example.com',
			$saved_content,
			'Staging URL must not remain in saved post content after a successful video block import.'
		);
	}

	/**
	 * Verifies that the production URL is present in both the block comment
	 * JSON attrs and innerHTML after a successful core/audio block import.
	 *
	 * Content_Processor::process_media_block() must update attrs['src']/attrs['id'],
	 * innerHTML, and innerContent so the staging URL is fully replaced.
	 */
	public function test_audio_block_innerHTML_is_updated_after_successful_import(): void {
		// ARRANGE: Use a .jpg URL so the existing HTTP/fixture mocking handles the
		// sideload end-to-end. The test is about innerHTML updating, not file type.
		$source_url                = 'https://source.example.com/uploads/track.jpg';
		$this->mock_post_overrides = array(
			'content' => '<!-- wp:audio {"src":"' . $source_url . '"} -->'
				. "\n<figure class=\"wp-block-audio\"><audio controls src=\"{$source_url}\"></audio></figure>"
				. "\n<!-- /wp:audio -->",
		);

		$session_id = $this->repository->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'        => 8202,
			'title'     => 'Post With Gutenberg Audio Block',
			'content'   => '<p>Stale content.</p>',
			'link'      => 'https://source.example.com/post-with-audio-block',
			'post_type' => 'posts',
		);

		// ACT: Import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import must succeed.
		$this->assertTrue( $result['success'], 'Import should succeed for a valid audio block.' );

		$saved_content = get_post_field( 'post_content', $result['post_id'] );

		// ASSERT: Production URL must appear in the block comment JSON attrs.
		$this->assertStringContainsString(
			'"src":"' . get_site_url(),
			$saved_content,
			'Production URL must appear in the block comment JSON attrs after a successful audio block import.'
		);

		// ASSERT: Production URL must appear in an HTML src attribute.
		$this->assertStringContainsString(
			'src="' . get_site_url(),
			$saved_content,
			'Production URL must appear in an HTML src attribute in saved post content after a successful audio block import.'
		);

		// ASSERT: The staging URL must not appear anywhere in saved post content.
		$this->assertStringNotContainsString(
			'source.example.com',
			$saved_content,
			'Staging URL must not remain in saved post content after a successful audio block import.'
		);
	}

	/**
	 * Verifies that a post with empty content is imported with empty content.
	 */
	public function test_import_preserves_empty_content(): void {
		// ARRANGE: Source post with empty content.
		$this->mock_post_overrides = array(
			'content' => '',
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9300,
			'title'     => 'Empty Content Post',
			'content'   => '',
			'link'      => 'https://source.example.com/empty-content',
			'post_type' => 'posts',
		);

		// ACT: Import the post.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Import must succeed.
		$this->assertTrue(
			$result['success'],
			'Import should succeed for a post with empty content.'
		);

		// ASSERT: Stored content must be empty, not a placeholder.
		$this->assertSame(
			'',
			get_post_field( 'post_content', $result['post_id'] ),
			'Empty source content must remain empty on the destination.'
		);
	}

	/**
	 * Verifies that import fails when the API response lacks raw field values
	 * (view context instead of edit context).
	 *
	 * Without raw values, the rendered variants would silently bake in
	 * display-filter artifacts (smart quotes, wpautop, etc.), breaking
	 * data parity.
	 */
	public function test_import_fails_when_raw_fields_unavailable(): void {
		// ARRANGE: Override the post API response to only include
		// rendered fields (no raw), simulating a view-context response.
		$rendered_only = function ( $preempt, $args, $url ) {
			unset( $args );

			if ( ! str_contains( $url, 'wp-json/wp/v2/posts/' ) ) {
				return $preempt;
			}

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => (string) wp_json_encode(
					array(
						'id'      => 1,
						'title'   => array(
							'rendered' => 'Rendered Title',
						),
						'content' => array(
							'rendered' => '<p>Rendered content.</p>',
						),
						'excerpt' => array(
							'rendered' => '<p>Rendered excerpt.</p>',
						),
						'link'    => 'https://source.example.com/test',
						'meta'    => array(),
					)
				),
				'headers'  => array(),
			);
		};

		add_filter( 'pre_http_request', $rendered_only, 4, 3 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9200,
			'title'     => 'Should Not Import',
			'content'   => '<p>Stale snapshot.</p>',
			'link'      => 'https://source.example.com/rendered-only',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		remove_filter( 'pre_http_request', $rendered_only, 4 );

		// ASSERT: Import must fail.
		$this->assertFalse(
			$result['success'],
			'Import should fail when raw fields are unavailable.'
		);

		// ASSERT: Error message identifies the fetch as the failure point.
		$this->assertStringContainsString(
			'Could not fetch fresh content',
			$result['error'],
			'Error should indicate fresh content fetch failed.'
		);

		// ASSERT: No post should have been created.
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '9200',
				)
			),
			'No post should be created when raw fields are missing.'
		);
	}

	/**
	 * Verifies that slug, comment_status, ping_status, and menu_order are
	 * preserved when importing a new post via the bulk path.
	 */
	public function test_import_preserves_slug_and_post_fields(): void {
		// ARRANGE: Source post with specific field values.
		$this->mock_post_overrides = array(
			'slug'           => 'custom-slug',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'menu_order'     => 5,
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9400,
			'title'     => 'Post With Custom Fields',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/custom-slug',
			'post_type' => 'posts',
		);

		// ACT: Import the post.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Import must succeed.
		$this->assertTrue(
			$result['success'],
			'Import should succeed.'
		);

		$post = get_post( $result['post_id'] );

		// ASSERT: Fields must match the source values.
		$this->assertSame(
			'custom-slug',
			$post->post_name,
			'Slug must be preserved from the source post.'
		);
		$this->assertSame(
			'closed',
			$post->comment_status,
			'Comment status must be preserved from the source post.'
		);
		$this->assertSame(
			'closed',
			$post->ping_status,
			'Ping status must be preserved from the source post.'
		);
		$this->assertSame(
			5,
			$post->menu_order,
			'Menu order must be preserved from the source post.'
		);
	}

	/**
	 * Verifies that slug, comment_status, ping_status, and menu_order are
	 * updated when re-importing an existing post via the bulk path.
	 */
	public function test_reimport_updates_slug_and_post_fields(): void {
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import once with default field values.
		$post_data = array(
			'id'        => 9401,
			'title'     => 'Post For Field Update Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/field-update-test',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post(
			$post_data,
			$session_id
		);
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );

		// ARRANGE: Re-import with updated field values.
		$this->mock_post_overrides = array(
			'slug'           => 'updated-slug',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'menu_order'     => 3,
		);

		// ACT: Re-import the same post.
		$second = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Re-import must succeed.
		$this->assertTrue( $second['success'], 'Re-import should succeed.' );
		$this->assertTrue( $second['existing'], 'Should be flagged as existing.' );

		$post = get_post( $second['post_id'] );

		// ASSERT: Fields must reflect the updated source values.
		$this->assertSame(
			'updated-slug',
			$post->post_name,
			'Slug must be updated on re-import.'
		);
		$this->assertSame(
			'closed',
			$post->comment_status,
			'Comment status must be updated on re-import.'
		);
		$this->assertSame(
			'closed',
			$post->ping_status,
			'Ping status must be updated on re-import.'
		);
		$this->assertSame(
			3,
			$post->menu_order,
			'Menu order must be updated on re-import.'
		);
	}

	/**
	 * Verifies that import error results use the fresh title from the source
	 * site, not the stale snapshot title from the listing page.
	 *
	 * When the fresh content fetch succeeds but a later step (content
	 * sanitization) fails, the returned error must carry the freshly fetched
	 * title so that log entries and UI messages reference the correct,
	 * up-to-date post name.
	 */
	public function test_import_error_uses_fresh_title_not_snapshot_title(): void {
		// ARRANGE: Enable kses so that the <form> content triggers a
		// sanitization error. The listing snapshot uses a stale title,
		// but the fresh content endpoint returns an updated title.
		add_filter( 'safe_publish_import_kses', '__return_true' );

		$this->mock_post_overrides = array(
			'title'   => 'Fresh Title From Source',
			'content' => '<p>OK</p><form action="x"><input></form>',
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9501,
			'title'     => 'Stale Snapshot Title',
			'content'   => '<p>Stale content.</p>',
			'link'      => 'https://source.example.com/fresh-title-test',
			'post_type' => 'posts',
		);

		// ACT: Import the post — sanitization will reject the <form>.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		remove_filter( 'safe_publish_import_kses', '__return_true' );

		// ASSERT: Import must fail due to sanitization.
		$this->assertFalse(
			$result['success'],
			'Import should fail when content is stripped by sanitization.'
		);

		// ASSERT: The error result must carry the fresh title, not the stale
		// snapshot title passed in $post_data.
		$this->assertSame(
			'Fresh Title From Source',
			$result['title'],
			'Error result must use the freshly fetched title, '
				. 'not the stale snapshot title.'
		);
	}

	/**
	 * Verifies that the bulk update path preserves the existing post status
	 * instead of resetting it to 'draft'.
	 *
	 * This is the key behavioral difference from the single-import path: bulk
	 * re-imports must not silently unpublish live posts.
	 */
	public function test_bulk_reimport_preserves_post_status(): void {
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import a post, then manually publish it.
		$post_data = array(
			'id'        => 9502,
			'title'     => 'Publishable Post',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/preserve-status',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post(
			$post_data,
			$session_id
		);
		$this->assertTrue( $first['success'] );

		wp_update_post(
			array(
				'ID'          => $first['post_id'],
				'post_status' => 'publish',
			)
		);
		$this->assertSame(
			'publish',
			get_post_status( $first['post_id'] )
		);

		// ACT: Bulk re-import the same post.
		$second = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Re-import succeeds and post stays published.
		$this->assertTrue( $second['success'] );
		$this->assertTrue( $second['existing'] );
		$this->assertSame(
			'publish',
			get_post_status( $second['post_id'] ),
			'Bulk re-import must not change post status.'
		);
	}

	/**
	 * Verifies that post_password is preserved when importing a new
	 * password-protected post via the bulk path.
	 */
	public function test_import_preserves_post_password(): void {
		// ARRANGE: Source post with a password.
		$this->mock_post_overrides = array(
			'password' => 's3cret',
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9601,
			'title'     => 'Password Protected Post',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/password-post',
			'post_type' => 'posts',
		);

		// ACT: Import the post.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Import must succeed.
		$this->assertTrue(
			$result['success'],
			'Import should succeed.'
		);

		$post = get_post( $result['post_id'] );

		// ASSERT: Password must match the source value.
		$this->assertSame(
			's3cret',
			$post->post_password,
			'Post password must be preserved from the source post.'
		);
	}

	/**
	 * Verifies that post_password is updated when re-importing an existing post
	 * via the bulk path.
	 */
	public function test_reimport_updates_post_password(): void {
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import once with a password.
		$this->mock_post_overrides = array(
			'password' => 'original',
		);

		$post_data = array(
			'id'        => 9602,
			'title'     => 'Post For Password Update Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/password-update',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post(
			$post_data,
			$session_id
		);
		$this->assertTrue(
			$first['success'],
			'Initial import should succeed.'
		);
		$this->assertSame(
			'original',
			get_post( $first['post_id'] )->post_password
		);

		// ARRANGE: Re-import with an updated password.
		$this->mock_post_overrides = array(
			'password' => 'changed',
		);

		// ACT: Re-import the same post.
		$second = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Re-import must succeed.
		$this->assertTrue(
			$second['success'],
			'Re-import should succeed.'
		);
		$this->assertTrue(
			$second['existing'],
			'Should be flagged as existing.'
		);

		// ASSERT: Password must reflect the updated source value.
		$this->assertSame(
			'changed',
			get_post( $second['post_id'] )->post_password,
			'Post password must be updated on re-import.'
		);
	}

	/**
	 * Verifies that import fails when the source post type is not registered on
	 * the destination site.
	 */
	public function test_import_fails_for_unregistered_post_type(): void {
		// ARRANGE: Use a post type that is not registered.
		$post_data = array(
			'id'        => 9801,
			'title'     => 'Unregistered CPT Post',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/unregistered',
			'post_type' => 'gadgets',
		);

		// ACT: Attempt import.
		$result = $this->import_service->import_post(
			$post_data
		);

		// ASSERT: Import fails with a descriptive error.
		$this->assertFalse(
			$result['success'],
			'Import should fail for an unregistered post type.'
		);
		$this->assertStringContainsString(
			'gadgets',
			$result['error'],
			'Error should name the unregistered post type.'
		);
	}

	/**
	 * Verifies that the bulk import path writes an item row when
	 * post-type resolution returns a WP_Error.
	 */
	public function test_bulk_import_logs_failure_when_post_type_unregistered(): void {
		// ARRANGE: Bulk session targeting an unregistered post type.
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9803,
			'title'     => 'Bulk Import With Unregistered CPT',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/bulk-unregistered',
			'post_type' => 'gadgets',
		);

		// ACT: Run the import through the bulk path (real session_id).
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Import fails for the unregistered post type.
		$this->assertFalse(
			$result['success'],
			'Import should fail for an unregistered post type.'
		);

		// ASSERT: An item row was written for the session.
		$items = ( new History_Repository() )->get_session_items( $session_id );

		$this->assertCount(
			1,
			$items,
			'Bulk import must write an item row when post-type resolution fails.'
		);
		$this->assertSame(
			'error',
			$items[0]['status'],
			'Item row must record the import as an error.'
		);
		$this->assertSame(
			9803,
			(int) $items[0]['source_post_id'],
			'Item row must reference the failing source post ID.'
		);
	}

	/**
	 * Verifies that the bulk import path writes an item row and that session
	 * counts reflect the failure when required fields are missing.
	 *
	 * Without this, a malformed payload entry would silently disappear from
	 * the session's projected counts because they are aggregated from the
	 * items table at read time.
	 */
	public function test_bulk_import_logs_failure_for_missing_required_fields(): void {
		// ARRANGE: Bulk session and two malformed payloads — one missing
		// the title, one missing the source post ID.
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$missing_title = array(
			'id'        => 9810,
			'title'     => '',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/no-title',
			'post_type' => 'posts',
		);

		$missing_id = array(
			'id'        => 0,
			'title'     => 'Has Title But No ID',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/no-id',
			'post_type' => 'posts',
		);

		// ACT: Import both malformed payloads.
		$result_missing_title = $this->import_service->import_post(
			$missing_title,
			$session_id
		);
		$result_missing_id    = $this->import_service->import_post(
			$missing_id,
			$session_id
		);

		// ASSERT: Both imports fail.
		$this->assertFalse(
			$result_missing_title['success'],
			'Import should fail when title is empty.'
		);
		$this->assertFalse(
			$result_missing_id['success'],
			'Import should fail when source post ID is zero.'
		);

		// ASSERT: Two item rows were written, both with status 'error'.
		$items = $this->repository->get_session_items( $session_id );

		$this->assertCount(
			2,
			$items,
			'Bulk import must write one item row per validation failure.'
		);
		$this->assertSame( 'error', $items[0]['status'] );
		$this->assertSame( 'error', $items[1]['status'] );

		// ASSERT: source_post_id is preserved when present (missing_title)
		// and stored as null when the source payload lacks an id (missing_id).
		$this->assertSame( 9810, (int) $items[0]['source_post_id'] );
		$this->assertNull( $items[1]['source_post_id'] );

		// ASSERT: Session counts project the failures from the items table.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 2, (int) $session['total_items'] );
		$this->assertSame( 2, (int) $session['failed'] );
		$this->assertSame( 0, (int) $session['successful'] );
	}

	/**
	 * Verifies that import fails when the current user lacks the capability
	 * required for the target post type.
	 */
	public function test_import_fails_when_user_lacks_capability(): void {
		// ARRANGE: Switch to a user without edit_posts.
		wp_set_current_user(
			self::factory()->user->create(
				array( 'role' => 'subscriber' )
			)
		);

		$post_data = array(
			'id'        => 9802,
			'title'     => 'Subscriber Import Attempt',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/subscriber',
			'post_type' => 'posts',
		);

		// ACT: Attempt import.
		$result = $this->import_service->import_post(
			$post_data
		);

		// ASSERT: Import fails with a permission error.
		$this->assertFalse(
			$result['success'],
			'Import should fail for a user without edit_posts.'
		);
		$this->assertStringContainsString(
			'permission',
			$result['error'],
			'Error should mention insufficient permission.'
		);
	}

	/**
	 * Verifies that the resolved source author is used as post_author when a
	 * matching destination user exists.
	 */
	public function test_import_sets_post_author_from_matched_destination_user(): void {
		// ARRANGE: Destination user that matches the source author email.
		$destination_author_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'jane@source.example',
				'user_login' => 'jane-dest',
			)
		);

		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'jane@source.example',
				'login'        => 'jane-source',
				'display_name' => 'Jane Doe',
			),
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9900,
			'title'     => 'Post With Author Resolution',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/author-resolution',
			'post_type' => 'posts',
		);

		// ACT: Run the bulk import path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Post is attributed to the matched destination user.
		$this->assertTrue( $result['success'], 'Import should succeed when author matches.' );
		$post = get_post( $result['post_id'] );
		$this->assertSame(
			$destination_author_id,
			(int) $post->post_author,
			'post_author must be the matched destination user ID.'
		);
	}

	/**
	 * Verifies that re-importing a post updates post_author to the freshly
	 * matched destination user.
	 */
	public function test_reimport_updates_post_author_from_matched_destination_user(): void {
		// ARRANGE: Two destination users with different emails.
		$first_author_id  = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'first@source.example',
			)
		);
		$second_author_id = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'second@source.example',
			)
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9901,
			'title'     => 'Author Update Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/author-update',
			'post_type' => 'posts',
		);

		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'first@source.example',
				'login'        => 'first',
				'display_name' => 'First',
			),
		);

		// ACT: Initial import with the first author.
		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );
		$this->assertSame(
			$first_author_id,
			(int) get_post( $first['post_id'] )->post_author
		);

		// ARRANGE: Source now reports the second author.
		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'second@source.example',
				'login'        => 'second',
				'display_name' => 'Second',
			),
		);

		// ACT: Re-import.
		$second = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: post_author reflects the new match.
		$this->assertTrue( $second['success'], 'Re-import should succeed.' );
		$this->assertSame(
			$second_author_id,
			(int) get_post( $second['post_id'] )->post_author,
			'post_author must be updated to the freshly matched destination user.'
		);
	}

	/**
	 * Verifies that import aborts with a specific error when no destination
	 * user matches the source author email.
	 */
	public function test_import_aborts_when_source_author_has_no_match(): void {
		// ARRANGE: Source author whose email has no destination match.
		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'unknown@source.example',
				'login'        => 'unknown',
				'display_name' => 'Unknown User',
			),
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9902,
			'title'     => 'Unmatched Author Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/unmatched-author',
			'post_type' => 'posts',
		);

		// ACT: Run the bulk import path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import fails with a message naming display name, email, and login.
		$this->assertFalse( $result['success'], 'Import must fail when author cannot be matched.' );
		$this->assertStringContainsString( 'Unknown User', $result['error'] );
		$this->assertStringContainsString( 'unknown@source.example', $result['error'] );
		$this->assertStringContainsString( 'login: unknown', $result['error'] );

		// ASSERT: No post was created.
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '9902',
				)
			)
		);
	}

	/**
	 * Verifies that import aborts with a distinct error when the source post
	 * has no resolvable author (deleted user or user ID 0 on the source).
	 */
	public function test_import_aborts_when_source_author_is_deleted(): void {
		// ARRANGE: Empty author payload — source post had no resolvable author.
		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => '',
				'login'        => '',
				'display_name' => '',
			),
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9903,
			'title'     => 'Deleted Author Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/deleted-author',
			'post_type' => 'posts',
		);

		// ACT: Run the bulk import path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Failure with the "deleted on source" message.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'deleted on the source', $result['error'] );
	}

	/**
	 * Verifies that author resolution runs before media download so a failed
	 * resolution does not leave orphan attachments on the destination.
	 */
	public function test_author_resolution_fails_before_media_is_downloaded(): void {
		// ARRANGE: Source content references an importable image, but the
		// safe_publish_author payload does not match any destination user.
		$this->mock_post_overrides = array(
			'content'             => '<p><img src="https://source.example.com/test-image.jpg" alt=""></p>',
			'safe_publish_author' => array(
				'email'        => 'noone@source.example',
				'login'        => 'noone',
				'display_name' => 'No One',
			),
		);

		$attachments_before = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9905,
			'title'     => 'Media Skipped Test',
			'content'   => '<p>Stale.</p>',
			'link'      => 'https://source.example.com/media-skipped',
			'post_type' => 'posts',
		);

		// ACT: Run the bulk import path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import fails with the no-match error.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No One', $result['error'] );

		// ASSERT: No new attachments were created.
		$attachments_after = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);
		$this->assertCount(
			count( $attachments_before ),
			$attachments_after,
			'Author-resolution failure must not produce orphan attachments.'
		);
	}

	/**
	 * Verifies that a Subscriber on the destination is attributed as
	 * post_author when matched by email — no capability check is applied to
	 * the matched user.
	 */
	public function test_subscriber_match_is_attributed_as_post_author(): void {
		// ARRANGE: Destination Subscriber whose email matches the source author.
		$subscriber_id = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'subscriber@source.example',
			)
		);

		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'subscriber@source.example',
				'login'        => 'sub-source',
				'display_name' => 'Sub Source',
			),
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9906,
			'title'     => 'Subscriber Author Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/subscriber-author',
			'post_type' => 'posts',
		);

		// ACT: Run the bulk import path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import succeeds and post_author is the Subscriber.
		$this->assertTrue(
			$result['success'],
			'Subscriber match must succeed — post_author has no capability requirement.'
		);
		$this->assertSame(
			$subscriber_id,
			(int) get_post( $result['post_id'] )->post_author
		);
	}

	/**
	 * Verifies that diagnostic source author meta is written on insert and
	 * refreshed on update.
	 */
	public function test_diagnostic_meta_is_written_and_refreshed(): void {
		// ARRANGE: Two destination users matching two different source emails.
		self::factory()->user->create(
			array(
				'user_email' => 'first@source.example',
			)
		);
		self::factory()->user->create(
			array(
				'user_email' => 'second@source.example',
			)
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'first@source.example',
				'login'        => 'first',
				'display_name' => 'First',
			),
		);

		$post_data = array(
			'id'        => 9907,
			'title'     => 'Diagnostic Meta Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/diagnostic-meta',
			'post_type' => 'posts',
		);

		// ACT: Initial import writes meta from the first source author.
		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		// ASSERT: Meta stored from initial import.
		$this->assertSame(
			'first@source.example',
			get_post_meta( $first['post_id'], Options::META_SOURCE_AUTHOR_EMAIL, true )
		);
		$this->assertSame(
			'first',
			get_post_meta( $first['post_id'], Options::META_SOURCE_AUTHOR_LOGIN, true )
		);

		// ARRANGE: Source now reports a different author.
		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'second@source.example',
				'login'        => 'second',
				'display_name' => 'Second',
			),
		);

		// ACT: Re-import refreshes the meta.
		$second = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $second['success'] );

		// ASSERT: Meta now reflects the second source author.
		$this->assertSame(
			'second@source.example',
			get_post_meta( $second['post_id'], Options::META_SOURCE_AUTHOR_EMAIL, true )
		);
		$this->assertSame(
			'second',
			get_post_meta( $second['post_id'], Options::META_SOURCE_AUTHOR_LOGIN, true )
		);
	}

	/**
	 * Verifies that the diagnostic source author meta is restored to its
	 * pre-update value when a re-import is rolled back.
	 *
	 * Mirrors the existing tracking_meta rollback behavior so that the meta
	 * describes the post's most recent SUCCESSFUL import, not a failed
	 * attempt.
	 */
	public function test_source_author_meta_is_restored_on_rollback(): void {
		// ARRANGE: Two destination users matching two source emails.
		self::factory()->user->create(
			array( 'user_email' => 'first@source.example' )
		);
		self::factory()->user->create(
			array( 'user_email' => 'second@source.example' )
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9909,
			'title'     => 'Rollback Meta Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/rollback-meta',
			'post_type' => 'posts',
			'meta'      => array( 'custom_field' => 'original' ),
		);

		$this->mock_post_overrides = array(
			'meta'                => array( 'custom_field' => 'original' ),
			'safe_publish_author' => array(
				'email'        => 'first@source.example',
				'login'        => 'first',
				'display_name' => 'First',
			),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		// ARRANGE: Fresh content reports the second source author, but a
		// custom-meta write will fail and trigger rollback.
		$this->mock_post_overrides = array(
			'meta'                => array( 'custom_field' => 'updated' ),
			'safe_publish_author' => array(
				'email'        => 'second@source.example',
				'login'        => 'second',
				'display_name' => 'Second',
			),
		);

		$block_meta = function ( $check, $object_id, $meta_key ) {
			unset( $object_id );
			if ( 'custom_field' === $meta_key ) {
				return false;
			}
			return $check;
		};
		add_filter( 'update_post_metadata', $block_meta, 10, 3 );

		// ACT: Re-import — must fail and roll back.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'update_post_metadata', $block_meta, 10 );

		// ASSERT: Re-import failed.
		$this->assertFalse( $result['success'] );

		// ASSERT: source_author meta is restored to first-import values.
		$this->assertSame(
			'first@source.example',
			get_post_meta( $first['post_id'], Options::META_SOURCE_AUTHOR_EMAIL, true ),
			'Source author email meta must be rolled back to the pre-update value.'
		);
		$this->assertSame(
			'first',
			get_post_meta( $first['post_id'], Options::META_SOURCE_AUTHOR_LOGIN, true ),
			'Source author login meta must be rolled back to the pre-update value.'
		);
	}

	/**
	 * Verifies that the diagnostic source author meta keys never appear in the
	 * public REST response for a destination post.
	 *
	 * The underscore prefix marks the meta as private and we deliberately do
	 * not register it with show_in_rest, so REST consumers cannot read
	 * imported author PII from the destination site.
	 */
	public function test_diagnostic_meta_is_absent_from_public_rest_response(): void {
		// ARRANGE: Destination user matching the source author and a successful
		// import that writes the diagnostic meta.
		self::factory()->user->create(
			array(
				'user_email' => 'visible@source.example',
			)
		);

		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'visible@source.example',
				'login'        => 'visible',
				'display_name' => 'Visible',
			),
		);

		$post_data = array(
			'id'        => 9908,
			'title'     => 'REST Visibility Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/rest-visibility',
			'post_type' => 'posts',
		);

		$result = $this->import_service->import_post( $post_data );
		$this->assertTrue( $result['success'] );

		// ACT: Boot a fresh REST server and fetch the destination post.
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Squiz.PHP.DisallowMultipleAssignments.Found
		$server = $wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		$response = $server->dispatch(
			new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $result['post_id'] )
		);

		$data = $response->get_data();
		$meta = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		// ASSERT: Neither private meta key appears in the response or its
		// meta sub-object.
		$encoded = (string) wp_json_encode( $data );
		$this->assertStringNotContainsString(
			Options::META_SOURCE_AUTHOR_EMAIL,
			$encoded,
			'Source author email meta key must not appear in public REST response.'
		);
		$this->assertStringNotContainsString(
			Options::META_SOURCE_AUTHOR_LOGIN,
			$encoded,
			'Source author login meta key must not appear in public REST response.'
		);
		$this->assertArrayNotHasKey(
			Options::META_SOURCE_AUTHOR_EMAIL,
			$meta
		);
		$this->assertArrayNotHasKey(
			Options::META_SOURCE_AUTHOR_LOGIN,
			$meta
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wp_rest_server = null;
	}

	/**
	 * Verifies that with the author fallback filter disabled (default), an
	 * unmatched source author aborts the import and persists no warning.
	 */
	public function test_unmatched_author_with_filter_disabled_aborts_without_warning(): void {
		// ARRANGE: Source author with no destination match; author fallback
		// filter not added.
		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'absent@source.example',
				'login'        => 'absent',
				'display_name' => 'Absent',
			),
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9910,
			'title'     => 'Filter Off Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/filter-off',
			'post_type' => 'posts',
		);

		// ACT: Run the bulk import path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import fails and the item row carries no warnings.
		$this->assertFalse( $result['success'] );
		$items = $this->repository->get_session_items_by_status(
			$session_id,
			array( 'error' )
		);
		$this->assertCount( 1, $items );
		$this->assertNull( $items[0]['warnings'] );
	}

	/**
	 * Verifies that enabling the author fallback filter has no effect when the
	 * source author already matches a destination user, and no warning is
	 * recorded.
	 */
	public function test_fallback_filter_does_not_warn_when_author_matches(): void {
		// ARRANGE: Destination user that matches the source author email.
		$matched_user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'present@source.example',
			)
		);

		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'present@source.example',
				'login'        => 'present',
				'display_name' => 'Present',
			),
		);

		add_filter( 'safe_publish_import_allow_author_fallback', '__return_true' );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9911,
			'title'     => 'Fallback No-Op Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/fallback-noop',
			'post_type' => 'posts',
		);

		// ACT: Run the bulk import path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter(
			'safe_publish_import_allow_author_fallback',
			'__return_true'
		);

		// ASSERT: Match path used, no warnings on the result or item row.
		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertSame(
			$matched_user_id,
			(int) get_post( $result['post_id'] )->post_author
		);

		$items = $this->repository->get_session_items_by_status(
			$session_id,
			array( 'success' )
		);
		$this->assertCount( 1, $items );
		$this->assertNull( $items[0]['warnings'] );
	}

	/**
	 * Verifies that with the author fallback filter enabled, a new post with
	 * an unmatched author is attributed to the importing user, and the warning
	 * records that user's ID.
	 */
	public function test_fallback_attributes_new_post_to_importing_user(): void {
		// ARRANGE: Importing user; source author with no destination match.
		$importing_user_id = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
		wp_set_current_user( $importing_user_id );

		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'orphan@source.example',
				'login'        => 'orphan',
				'display_name' => 'Orphan',
			),
		);

		add_filter( 'safe_publish_import_allow_author_fallback', '__return_true' );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9912,
			'title'     => 'Insert Fallback Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/insert-fallback',
			'post_type' => 'posts',
		);

		// ACT: Run the bulk import path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter(
			'safe_publish_import_allow_author_fallback',
			'__return_true'
		);

		// ASSERT: Import succeeded and the post is attributed to the importer.
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			$importing_user_id,
			(int) get_post( $result['post_id'] )->post_author
		);

		// ASSERT: Warning is on the result with the importer's id.
		$this->assertCount( 1, $result['warnings'] );
		$this->assertSame(
			array(
				'type'             => 'author_fallback_applied',
				'source'           => array(
					'email'        => 'orphan@source.example',
					'login'        => 'orphan',
					'display_name' => 'Orphan',
				),
				'fallback_user_id' => $importing_user_id,
			),
			$result['warnings'][0]
		);

		// ASSERT: Warning is persisted on the item row, encoded as JSON.
		$items = $this->repository->get_session_items_by_status(
			$session_id,
			array( 'success' )
		);
		$this->assertCount( 1, $items );
		$persisted = json_decode( (string) $items[0]['warnings'], true );
		$this->assertSame( $result['warnings'], $persisted );
	}

	/**
	 * Verifies that with the author fallback filter enabled, re-importing a
	 * post with an unmatched author preserves the destination's existing
	 * post_author. The warning's fallback_user_id is null in this case.
	 */
	public function test_fallback_preserves_existing_author_on_update(): void {
		// ARRANGE: First import succeeds via a matched destination user.
		$matched_user_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'incumbent@source.example',
			)
		);

		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'incumbent@source.example',
				'login'        => 'incumbent',
				'display_name' => 'Incumbent',
			),
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9913,
			'title'     => 'Update Fallback Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/update-fallback',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );
		$this->assertSame(
			$matched_user_id,
			(int) get_post( $first['post_id'] )->post_author
		);

		// ARRANGE: Source now reports an unmatched author; enable the author
		// fallback filter.
		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'gone@source.example',
				'login'        => 'gone',
				'display_name' => 'Gone',
			),
		);

		add_filter( 'safe_publish_import_allow_author_fallback', '__return_true' );

		// ACT: Re-import the same source post.
		$second = $this->import_service->import_post( $post_data, $session_id );

		remove_filter(
			'safe_publish_import_allow_author_fallback',
			'__return_true'
		);

		// ASSERT: post_author is unchanged from the first import.
		$this->assertTrue( $second['success'] );
		$this->assertSame(
			$matched_user_id,
			(int) get_post( $second['post_id'] )->post_author,
			'Update path with fallback must preserve the existing post_author.'
		);

		// ASSERT: Warning carries null fallback_user_id (kept-author semantic).
		$this->assertCount( 1, $second['warnings'] );
		$this->assertNull( $second['warnings'][0]['fallback_user_id'] );
		$this->assertSame(
			'gone@source.example',
			$second['warnings'][0]['source']['email']
		);
	}

	/**
	 * Verifies that a post whose author was set via the author fallback is
	 * preserved across subsequent updates that also trigger the fallback —
	 * even when the importing user differs from the one who triggered the
	 * original fallback.
	 */
	public function test_repeated_fallback_on_update_does_not_churn_author(): void {
		// ARRANGE: First importer triggers the author fallback on insert and
		// becomes the post's author.
		$first_importer_id = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
		wp_set_current_user( $first_importer_id );

		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'first-unmatched@source.example',
				'login'        => 'first-unmatched',
				'display_name' => 'First Unmatched',
			),
		);

		add_filter( 'safe_publish_import_allow_author_fallback', '__return_true' );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9914,
			'title'     => 'Repeated Fallback Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/repeated-fallback',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );
		$this->assertSame(
			$first_importer_id,
			(int) get_post( $first['post_id'] )->post_author
		);

		// ARRANGE: Switch to a *different* importing user; source still
		// reports an unmatched author (different email to make sure the
		// update path runs).
		$second_importer_id = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
		wp_set_current_user( $second_importer_id );

		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'second-unmatched@source.example',
				'login'        => 'second-unmatched',
				'display_name' => 'Second Unmatched',
			),
		);

		// ACT: Re-import the same source post under the second importer.
		$second = $this->import_service->import_post( $post_data, $session_id );

		remove_filter(
			'safe_publish_import_allow_author_fallback',
			'__return_true'
		);

		// ASSERT: post_author is still the first importer — match-or-keep
		// preserved the previously-fallback'd attribution.
		$this->assertTrue( $second['success'] );
		$this->assertSame(
			$first_importer_id,
			(int) get_post( $second['post_id'] )->post_author,
			'Update fallback must not overwrite a previously-applied fallback.'
		);
		$this->assertNull( $second['warnings'][0]['fallback_user_id'] );
	}

	/**
	 * Verifies that the source_author_unresolved error (deleted user on source)
	 * aborts the import even when the author fallback filter is enabled.
	 */
	public function test_fallback_does_not_relax_source_author_unresolved(): void {
		// ARRANGE: Source author with an empty email; author fallback filter on.
		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => '',
				'login'        => '',
				'display_name' => '',
			),
		);

		add_filter( 'safe_publish_import_allow_author_fallback', '__return_true' );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9916,
			'title'     => 'Unresolved Fallback Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/unresolved-fallback',
			'post_type' => 'posts',
		);

		// ACT: Run the bulk import path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter(
			'safe_publish_import_allow_author_fallback',
			'__return_true'
		);

		// ASSERT: Import aborts with the deleted-author message.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'deleted on the source',
			$result['error']
		);
	}

	/**
	 * Verifies that a hierarchical post with source parent 0 is imported as
	 * top-level and stores no diagnostic parent meta.
	 */
	public function test_top_level_hierarchical_post_imports_without_parent_meta(): void {
		// ARRANGE: Page with source parent 0.
		$this->mock_post_overrides = array( 'parent' => 0 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9920,
			'title'     => 'Top Level Page',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/top-level-page',
			'post_type' => 'pages',
		);

		// ACT: Run the import.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Imported as top-level with no diagnostic meta.
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			0,
			(int) get_post( $result['post_id'] )->post_parent
		);
		$this->assertSame(
			'',
			get_post_meta(
				$result['post_id'],
				Options::META_SOURCE_POST_PARENT_ID,
				true
			)
		);
		$this->assertSame( array(), $result['warnings'] );
	}

	/**
	 * Verifies that a non-hierarchical post with a non-zero source parent is
	 * imported as top-level with no diagnostic meta — the field is ignored.
	 */
	public function test_non_hierarchical_post_ignores_source_parent(): void {
		// ARRANGE: 'posts' (non-hierarchical) with a non-zero parent in source.
		$this->mock_post_overrides = array( 'parent' => 555 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9921,
			'title'     => 'Non Hierarchical Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/non-hierarchical',
			'post_type' => 'posts',
		);

		// ACT: Run the import.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: post_parent stays 0 and diagnostic meta is absent.
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			0,
			(int) get_post( $result['post_id'] )->post_parent
		);
		$this->assertSame(
			'',
			get_post_meta(
				$result['post_id'],
				Options::META_SOURCE_POST_PARENT_ID,
				true
			)
		);
	}

	/**
	 * Verifies that a hierarchical post whose source parent matches an
	 * already-imported destination post sets post_parent and writes the
	 * diagnostic parent meta.
	 */
	public function test_resolvable_parent_sets_post_parent_and_meta(): void {
		// ARRANGE: A destination page that was previously imported with
		// source post id 700.
		$existing_parent_id = self::factory()->post->create(
			array( 'post_type' => 'page' )
		);
		update_post_meta(
			$existing_parent_id,
			Options::META_SOURCE_POST_ID,
			700
		);

		$this->mock_post_overrides = array( 'parent' => 700 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9922,
			'title'     => 'Child Page',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/child',
			'post_type' => 'pages',
		);

		// ACT: Run the import.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: post_parent matches the existing destination post and the
		// diagnostic meta stores the source parent id.
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			$existing_parent_id,
			(int) get_post( $result['post_id'] )->post_parent
		);
		$this->assertSame(
			'700',
			get_post_meta(
				$result['post_id'],
				Options::META_SOURCE_POST_PARENT_ID,
				true
			)
		);
	}

	/**
	 * Verifies that an unresolvable parent aborts the import with the
	 * "has not been imported" message when the parent is not part of the
	 * current batch.
	 */
	public function test_unresolvable_parent_not_in_batch_aborts(): void {
		// ARRANGE: Hierarchical post whose source parent has no destination
		// match and is not part of any batch context.
		$this->mock_post_overrides = array( 'parent' => 8888 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9923,
			'title'     => 'Orphaned Child',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/orphaned',
			'post_type' => 'pages',
		);

		// ACT: Run the import.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Failure with the no-match message and no post created.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'has not been imported on this site',
			$result['error']
		);
		$this->assertStringNotContainsString( '"', $result['error'] );

		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'page',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '9923',
				)
			)
		);
	}

	/**
	 * Verifies that when the parent is part of the current bulk batch but
	 * never made it to a successful destination import, the error message
	 * uses the in-batch variant carrying the parent's title.
	 */
	public function test_unresolvable_parent_in_batch_uses_failed_in_batch_message(): void {
		// ARRANGE: Pretend the parent's pass-1 fresh data is in scope by
		// passing $batch_fresh_data directly to import_post().
		$batch_fresh_data = array(
			800 => array( 'title' => 'Pending Parent Page' ),
		);

		$this->mock_post_overrides = array( 'parent' => 800 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9924,
			'title'     => 'Child Pending On Parent',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/child-pending',
			'post_type' => 'pages',
		);

		// ACT: Import with batch context but no destination match for 800.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id,
			array( 'batch_fresh_data' => $batch_fresh_data )
		);

		// ASSERT: Failure with the in-batch message including the title.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'failed to import earlier in this batch',
			$result['error']
		);
		$this->assertStringContainsString( 'Pending Parent Page', $result['error'] );
	}

	/**
	 * Verifies that the orphan fallback filter relaxes resolution: the post
	 * imports as top-level and records a parent_orphaned warning with
	 * reason 'not_imported' and a null title.
	 */
	public function test_orphan_fallback_not_imported_emits_warning(): void {
		// ARRANGE: Hierarchical post with unresolvable parent + fallback on.
		$this->mock_post_overrides = array( 'parent' => 9999 );

		add_filter( 'safe_publish_import_allow_orphans', '__return_true' );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9925,
			'title'     => 'Orphan Fallback Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/orphan-fallback',
			'post_type' => 'pages',
		);

		// ACT: Run the import.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'safe_publish_import_allow_orphans', '__return_true' );

		// ASSERT: Imported as top-level with a parent_orphaned warning.
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			0,
			(int) get_post( $result['post_id'] )->post_parent
		);
		$this->assertCount( 1, $result['warnings'] );
		$this->assertSame( 'parent_orphaned', $result['warnings'][0]['type'] );
		$this->assertSame( 'not_imported', $result['warnings'][0]['reason'] );
		$this->assertSame( 9999, $result['warnings'][0]['source']['parent_id'] );
		$this->assertNull( $result['warnings'][0]['source']['parent_title'] );
	}

	/**
	 * Verifies that the orphan fallback for an in-batch parent records the
	 * 'failed_in_batch' reason with the parent's title populated.
	 */
	public function test_orphan_fallback_failed_in_batch_includes_title(): void {
		// ARRANGE: In-batch parent with no destination match + fallback on.
		$batch_fresh_data = array(
			801 => array( 'title' => 'Failed Parent' ),
		);

		$this->mock_post_overrides = array( 'parent' => 801 );

		add_filter( 'safe_publish_import_allow_orphans', '__return_true' );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9926,
			'title'     => 'Child With Batch Parent',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/child-batch',
			'post_type' => 'pages',
		);

		// ACT: Import with batch context.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id,
			array( 'batch_fresh_data' => $batch_fresh_data )
		);

		remove_filter( 'safe_publish_import_allow_orphans', '__return_true' );

		// ASSERT: Top-level orphan with failed_in_batch warning carrying the
		// parent's title.
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			0,
			(int) get_post( $result['post_id'] )->post_parent
		);
		$this->assertSame( 'parent_orphaned', $result['warnings'][0]['type'] );
		$this->assertSame( 'failed_in_batch', $result['warnings'][0]['reason'] );
		$this->assertSame( 801, $result['warnings'][0]['source']['parent_id'] );
		$this->assertSame(
			'Failed Parent',
			$result['warnings'][0]['source']['parent_title']
		);
	}

	/**
	 * Verifies that re-importing a page with the source parent unchanged
	 * leaves post_parent and the diagnostic meta in place.
	 */
	public function test_reimport_with_unchanged_parent_preserves_state(): void {
		// ARRANGE: Destination parent post.
		$parent_dest_id = self::factory()->post->create(
			array( 'post_type' => 'page' )
		);
		update_post_meta(
			$parent_dest_id,
			Options::META_SOURCE_POST_ID,
			900
		);

		$this->mock_post_overrides = array( 'parent' => 900 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9927,
			'title'     => 'Stable Child',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/stable-child',
			'post_type' => 'pages',
		);

		// ACT: Initial import + re-import with no source changes.
		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		$second = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Same post id, same parent, same diagnostic meta.
		$this->assertTrue( $second['success'] );
		$this->assertSame( $first['post_id'], $second['post_id'] );
		$this->assertSame(
			$parent_dest_id,
			(int) get_post( $second['post_id'] )->post_parent
		);
		$this->assertSame(
			'900',
			get_post_meta(
				$second['post_id'],
				Options::META_SOURCE_POST_PARENT_ID,
				true
			)
		);
	}

	/**
	 * Verifies that re-importing with a changed source parent refreshes both
	 * post_parent and the diagnostic meta.
	 */
	public function test_reimport_with_changed_parent_refreshes_state(): void {
		// ARRANGE: Two destination parent posts.
		$first_parent_id = self::factory()->post->create(
			array( 'post_type' => 'page' )
		);
		update_post_meta(
			$first_parent_id,
			Options::META_SOURCE_POST_ID,
			910
		);
		$second_parent_id = self::factory()->post->create(
			array( 'post_type' => 'page' )
		);
		update_post_meta(
			$second_parent_id,
			Options::META_SOURCE_POST_ID,
			920
		);

		$this->mock_post_overrides = array( 'parent' => 910 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9928,
			'title'     => 'Migrating Child',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/migrating',
			'post_type' => 'pages',
		);

		// ACT: Initial import then re-import with a new parent.
		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		$this->mock_post_overrides = array( 'parent' => 920 );
		$second                    = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: post_parent and meta refreshed.
		$this->assertTrue( $second['success'] );
		$this->assertSame(
			$second_parent_id,
			(int) get_post( $second['post_id'] )->post_parent
		);
		$this->assertSame(
			'920',
			get_post_meta(
				$second['post_id'],
				Options::META_SOURCE_POST_PARENT_ID,
				true
			)
		);
	}

	/**
	 * Verifies that re-importing a page that becomes top-level on the source
	 * clears the previously-stored diagnostic parent meta so the meta tracks
	 * the current source state.
	 */
	public function test_reimport_clears_parent_meta_when_source_becomes_top_level(): void {
		// ARRANGE: Destination parent + a first import that writes the meta.
		$parent_dest_id = self::factory()->post->create(
			array( 'post_type' => 'page' )
		);
		update_post_meta(
			$parent_dest_id,
			Options::META_SOURCE_POST_ID,
			950
		);

		$this->mock_post_overrides = array( 'parent' => 950 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9933,
			'title'     => 'Now Top-Level',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/now-top-level',
			'post_type' => 'pages',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );
		$this->assertSame(
			'950',
			get_post_meta(
				$first['post_id'],
				Options::META_SOURCE_POST_PARENT_ID,
				true
			),
			'First import should write the diagnostic parent meta.'
		);

		// ARRANGE: Source post now reports parent = 0 (top-level).
		$this->mock_post_overrides = array( 'parent' => 0 );

		// ACT: Re-import.
		$second = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: post_parent is back to 0 and the diagnostic meta is gone.
		$this->assertTrue( $second['success'] );
		$this->assertSame( $first['post_id'], $second['post_id'] );
		$this->assertSame(
			0,
			(int) get_post( $second['post_id'] )->post_parent
		);
		$this->assertSame(
			'',
			get_post_meta(
				$second['post_id'],
				Options::META_SOURCE_POST_PARENT_ID,
				true
			),
			'Diagnostic parent meta must be cleared when the source post is now top-level.'
		);
	}

	/**
	 * Verifies that re-importing a previously-orphaned post links it to its
	 * source parent once that parent has been imported on the destination.
	 */
	public function test_reimport_links_orphan_once_parent_exists(): void {
		// ARRANGE: First import with the fallback enabled and no destination
		// match for the parent — the child is imported as an orphan.
		$this->mock_post_overrides = array( 'parent' => 940 );

		add_filter( 'safe_publish_import_allow_orphans', '__return_true' );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9932,
			'title'     => 'Late-Bound Child',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/late-bound',
			'post_type' => 'pages',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		remove_filter( 'safe_publish_import_allow_orphans', '__return_true' );

		$this->assertTrue( $first['success'] );
		$this->assertSame( 0, (int) get_post( $first['post_id'] )->post_parent );

		// ARRANGE: Parent is now imported on the destination.
		$parent_dest_id = self::factory()->post->create(
			array( 'post_type' => 'page' )
		);
		update_post_meta(
			$parent_dest_id,
			Options::META_SOURCE_POST_ID,
			940
		);

		// ACT: Re-import the same source post — strict resolution should now
		// succeed because the parent exists.
		$second = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: post_parent and diagnostic meta now point at the parent.
		$this->assertTrue( $second['success'] );
		$this->assertSame( $first['post_id'], $second['post_id'] );
		$this->assertSame(
			$parent_dest_id,
			(int) get_post( $second['post_id'] )->post_parent
		);
		$this->assertSame(
			'940',
			get_post_meta(
				$second['post_id'],
				Options::META_SOURCE_POST_PARENT_ID,
				true
			)
		);
	}

	/**
	 * Verifies that a strict re-import with an unresolvable parent aborts and
	 * leaves the destination post untouched.
	 */
	public function test_strict_reimport_with_unresolvable_parent_aborts(): void {
		// ARRANGE: Destination parent + successful first import.
		$parent_dest_id = self::factory()->post->create(
			array( 'post_type' => 'page' )
		);
		update_post_meta(
			$parent_dest_id,
			Options::META_SOURCE_POST_ID,
			930
		);

		$this->mock_post_overrides = array( 'parent' => 930 );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9929,
			'title'     => 'Strict Reimport Child',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/strict-reimport',
			'post_type' => 'pages',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );
		$first_title = get_post( $first['post_id'] )->post_title;

		// ARRANGE: Source now reports an unresolvable parent.
		$this->mock_post_overrides = array(
			'parent' => 4242,
			'title'  => 'Should Not Apply',
		);

		// ACT: Re-import must abort and leave state untouched.
		$second = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Failure, original parent preserved, title unchanged.
		$this->assertFalse( $second['success'] );
		$post_after = get_post( $first['post_id'] );
		$this->assertSame(
			$parent_dest_id,
			(int) $post_after->post_parent,
			'Update must not retarget post_parent on a failed re-import.'
		);
		$this->assertSame( $first_title, $post_after->post_title );
	}

	/**
	 * Verifies that an author fallback and a parent orphan applied to the
	 * same import are both persisted as discrete warnings.
	 */
	public function test_author_and_parent_fallback_emit_two_warnings(): void {
		// ARRANGE: No destination user matches the source author and the
		// source parent is unresolvable. Both fallback filters on.
		$this->mock_post_overrides = array(
			'parent'              => 7777,
			'safe_publish_author' => array(
				'email'        => 'nobody@source.example',
				'login'        => 'nobody',
				'display_name' => 'Nobody',
			),
		);

		add_filter( 'safe_publish_import_allow_author_fallback', '__return_true' );
		add_filter( 'safe_publish_import_allow_orphans', '__return_true' );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'        => 9930,
			'title'     => 'Both Fallbacks Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/both-fallbacks',
			'post_type' => 'pages',
		);

		// ACT: Run the import.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'safe_publish_import_allow_author_fallback', '__return_true' );
		remove_filter( 'safe_publish_import_allow_orphans', '__return_true' );

		// ASSERT: Both warnings present.
		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $result['warnings'] );

		$types = array_column( $result['warnings'], 'type' );
		$this->assertContains( 'author_fallback_applied', $types );
		$this->assertContains( 'parent_orphaned', $types );
	}

	/**
	 * Verifies that the diagnostic source parent meta key never appears in
	 * the public REST response for a destination post.
	 */
	public function test_source_parent_meta_is_absent_from_public_rest_response(): void {
		// ARRANGE: Hierarchical import with a resolvable parent so the
		// diagnostic meta is written.
		$parent_dest_id = self::factory()->post->create(
			array( 'post_type' => 'page' )
		);
		update_post_meta(
			$parent_dest_id,
			Options::META_SOURCE_POST_ID,
			950
		);

		$this->mock_post_overrides = array( 'parent' => 950 );

		$post_data = array(
			'id'        => 9931,
			'title'     => 'Parent Meta Visibility Test',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/parent-rest-visibility',
			'post_type' => 'pages',
		);

		$result = $this->import_service->import_post( $post_data );
		$this->assertTrue( $result['success'] );

		// ACT: Boot a fresh REST server and fetch the destination page.
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Squiz.PHP.DisallowMultipleAssignments.Found
		$server = $wp_rest_server = new \WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		$response = $server->dispatch(
			new \WP_REST_Request( 'GET', '/wp/v2/pages/' . $result['post_id'] )
		);

		$data    = $response->get_data();
		$meta    = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();
		$encoded = (string) wp_json_encode( $data );

		// ASSERT: Key is absent from both the encoded body and the meta map.
		$this->assertStringNotContainsString(
			Options::META_SOURCE_POST_PARENT_ID,
			$encoded
		);
		$this->assertArrayNotHasKey(
			Options::META_SOURCE_POST_PARENT_ID,
			$meta
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wp_rest_server = null;
	}

	/**
	 * Verifies that rolling back a bulk-reimported post restores its previous
	 * title, content, and excerpt.
	 *
	 * The bulk update path captures the pre-update state alongside the single
	 * update path, so rollback is non-destructive in both flows.
	 */
	public function test_bulk_reimport_rollback_restores_previous_post_fields(): void {
		// ARRANGE: Initial import seeds the post in the destination.
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$this->mock_post_overrides = array(
			'title'   => 'Original Title',
			'content' => '<p>Original content.</p>',
			'excerpt' => 'Original excerpt.',
		);

		$post_data = array(
			'id'        => 9601,
			'title'     => 'Snapshot Title',
			'content'   => '<p>Snapshot content.</p>',
			'link'      => 'https://source.example.com/rollback-restore-test',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );
		$post_id = (int) $first['post_id'];

		$pre_update_title   = get_post_field( 'post_title', $post_id );
		$pre_update_content = get_post_field( 'post_content', $post_id );
		$pre_update_excerpt = get_post_field( 'post_excerpt', $post_id );

		// ARRANGE: Re-importing with new values exercises the bulk update path.
		$this->mock_post_overrides = array(
			'title'   => 'Updated Title',
			'content' => '<p>Updated content.</p>',
			'excerpt' => 'Updated excerpt.',
		);

		// ACT: Re-import the same source post.
		$second = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $second['success'], 'Re-import should succeed.' );
		$this->assertTrue(
			$second['existing'],
			'Re-import should flag the post as existing.'
		);
		$this->assertSame(
			'Updated Title',
			get_post_field( 'post_title', $post_id ),
			'Pre-rollback sanity: post must reflect the updated values.'
		);

		// ACT: Roll back the most recent item logged for this post.
		$item             = $this->repository->get_item_for_post( $post_id );
		$rollback_service = new Session_Rollback_Service( $this->repository );
		$result           = $rollback_service->rollback_item( (int) $item['id'] );

		// ASSERT: Rollback restored the post instead of deleting it.
		$this->assertIsArray( $result );
		$this->assertSame( 'restored', $result['action'] );
		$this->assertNotNull(
			get_post( $post_id ),
			'Rollback must not delete the post on the bulk update path.'
		);

		// ASSERT: Post fields match the pre-update state.
		$this->assertSame(
			$pre_update_title,
			get_post_field( 'post_title', $post_id )
		);
		$this->assertSame(
			$pre_update_content,
			get_post_field( 'post_content', $post_id )
		);
		$this->assertSame(
			$pre_update_excerpt,
			get_post_field( 'post_excerpt', $post_id )
		);
	}

	/**
	 * Verifies that import_post() bails with a concurrent-import error when
	 * the per-source-post lock is already held by another request, and that
	 * no destination post is created.
	 */
	public function test_import_post_blocks_when_lock_already_held(): void {
		// ARRANGE: Pre-acquire the per-source-post import lock to simulate
		// another concurrent import mid-flight.
		$source_id = 9100;
		wp_cache_add(
			Post_Import_Service::IMPORT_LOCK_KEY_PREFIX . $source_id,
			1,
			Post_Import_Service::IMPORT_LOCK_GROUP,
			// IMPORT_LOCK_TTL is 300 seconds.
			// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined
			Post_Import_Service::IMPORT_LOCK_TTL
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'single'
		);

		$post_data = array(
			'id'        => $source_id,
			'title'     => 'Locked Post',
			'link'      => 'https://source.example.com/locked-post',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import while the lock is held.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import is rejected with the concurrent-import message and
		// no destination post is created.
		$this->assertFalse(
			$result['success'],
			'Import should be rejected when the lock is held.'
		);
		$this->assertStringContainsString(
			'currently being imported',
			$result['error']
		);

		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'        => 'any',
					'post_status'      => 'any',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_SOURCE_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => (string) $source_id,
				)
			),
			'No post should be created when the lock blocks the import.'
		);
	}

	/**
	 * Verifies that persist_new_post() detects a sibling created concurrently
	 * with the same source post ID, force-deletes its own insert, and returns
	 * a duplicate_import error so subsequent find_imported_post() lookups stay
	 * deterministic.
	 *
	 * Calls persist_new_post() directly to exercise the safety net that
	 * activates when the wp_cache_add lock degrades (no persistent object
	 * cache, TTL expiry).
	 */
	public function test_persist_new_post_discards_concurrent_duplicate(): void {
		// ARRANGE: A sibling post already exists for this source post ID,
		// standing in for the winner of a concurrent race.
		$source_id = 9101;
		$winner_id = self::factory()->post->create(
			array(
				'post_title'  => 'Concurrent winner',
				'post_status' => 'draft',
				'meta_input'  => array(
					Options::META_SOURCE_POST_ID => $source_id,
				),
			)
		);

		// ACT: Persist a new post for the same source post ID. The new insert
		// gets a higher post ID than the winner, so the verification routes
		// it to cleanup.
		$result = $this->import_service->persist_new_post(
			array(
				'post_title'   => 'Concurrent loser',
				'post_content' => '',
				'post_status'  => 'draft',
				'post_type'    => 'post',
				'meta_input'   => array(
					Options::META_SOURCE_POST_ID => $source_id,
					Options::META_SOURCE_LINK    => 'https://source.example.com/loser',
					Options::META_IMPORTED_FROM  => Options::META_IMPORTED_FROM_VALUE,
				),
			),
			0,
			array(),
			array(),
			array(),
			0
		);

		// ASSERT: persist_new_post returns the duplicate_import WP_Error and
		// surfaces the winner's ID in the error data.
		$this->assertWPError(
			$result,
			'Expected a WP_Error when a lower-ID sibling already exists.'
		);
		$this->assertSame( 'duplicate_import', $result->get_error_code() );

		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertSame( $winner_id, (int) $error_data['winning_post_id'] );

		// ASSERT: Winner survives and is the sole post for this source ID.
		$this->assertNotNull(
			get_post( $winner_id ),
			'Winner post should not be affected.'
		);

		$siblings = get_posts(
			array(
				'meta_key'         => Options::META_SOURCE_POST_ID,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => (string) $source_id,
				'post_type'        => 'any',
				'post_status'      => 'any',
				'posts_per_page'   => 2,
				'suppress_filters' => false,
			)
		);
		$this->assertCount(
			1,
			$siblings,
			'Loser must be force-deleted, leaving only the winner.'
		);
		$this->assertSame( $winner_id, $siblings[0]->ID );
	}
}
