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
use Safe_Publish\API\External_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\External_Posts_API\External_Posts_API_Test_Base;
use Safe_Publish\Utils\Options;

/**
 * Integration tests for Post_Import_Service.
 *
 * Extends the media-aware base class so that image downloads are intercepted
 * by the existing HTTP mock infrastructure.
 */
class Post_Import_Service_Test extends External_Posts_API_Test_Base {

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
			new Content_Media_Processor( $media_importer )
		);

		$this->import_service = new Post_Import_Service(
			new External_Posts_API( new HTTP_Client() ),
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
	 * @param false|array|\WP_Error $preempt Early-return value passed by WP.
	 * @param array                 $args    Request arguments (unused).
	 * @param string                $url     Request URL.
	 * @return false|array|\WP_Error Preemptive response or false to let later filters run.
	 */
	public function mock_media_api_request( $preempt, array $args, string $url ) {
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
	 * When processed content contains an image whose download returns a non-2xx
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
					'meta_key'         => Options::META_EXTERNAL_POST_ID,
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
					'meta_key'         => Options::META_EXTERNAL_POST_ID,
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
	 * which calls import_external_media_as_attachment() directly and must track
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
					'meta_key'         => Options::META_EXTERNAL_POST_ID,
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
	 * which calls import_external_media_as_attachment() directly and must track
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
					'meta_key'         => Options::META_EXTERNAL_POST_ID,
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
					'meta_key'         => Options::META_EXTERNAL_POST_ID,
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
					'meta_key'       => 'safe_publish_external_post_id',
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
					'meta_key'         => Options::META_EXTERNAL_POST_ID,
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
			(int) $items[0]['external_post_id'],
			'Item row must reference the failing external post ID.'
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
		// the title, one missing the external ID.
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
			'Import should fail when external ID is zero.'
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

		// ASSERT: external_post_id is preserved when present (missing_title)
		// and stored as null when the source payload lacks an id (missing_id).
		$this->assertSame( 9810, (int) $items[0]['external_post_id'] );
		$this->assertNull( $items[1]['external_post_id'] );

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
}
