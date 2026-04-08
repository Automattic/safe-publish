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
use Safe_Publish\Admin\History_Renderer;
use Safe_Publish\Admin\Import_History;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\Admin\Session_Formatter;
use Safe_Publish\Admin\Session_Rollback_Service;
use Safe_Publish\API\External_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Embed_Processor;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\External_Posts_API\External_Posts_API_Test_Base;

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
	 * Import history coordinator instance.
	 *
	 * @var Import_History
	 */
	private Import_History $import_history;

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

		$history_repository   = new History_Repository();
		$this->import_history = new Import_History(
			$history_repository,
			new History_Renderer(),
			new Session_Formatter(),
			new Session_Rollback_Service( $history_repository )
		);

		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer, new Embed_Processor() )
		);

		$this->import_service = new Post_Import_Service(
			new External_Posts_API( new HTTP_Client() ),
			$media_importer,
			$content_processor,
			$this->import_history,
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

		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

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
		$this->assertEmpty(
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => \Safe_Publish\Utils\Options::META_EXTERNAL_POST_ID,
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

		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

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
		$this->assertEmpty(
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => \Safe_Publish\Utils\Options::META_EXTERNAL_POST_ID,
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
	 * The core/video block is processed by Content_Processor::process_video_block(),
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

		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

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
		$this->assertEmpty(
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => \Safe_Publish\Utils\Options::META_EXTERNAL_POST_ID,
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
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

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
	 * The core/audio block is processed by Content_Processor::process_audio_block(),
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

		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

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
		$this->assertEmpty(
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => \Safe_Publish\Utils\Options::META_EXTERNAL_POST_ID,
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

		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

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
		$this->assertEmpty(
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => \Safe_Publish\Utils\Options::META_EXTERNAL_POST_ID,
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
		delete_option( \Safe_Publish\Utils\Options::OPTION_CONNECTED_SITE_URL );

		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

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
		$this->assertNotEmpty( $result['error'], 'A non-empty error message should be returned.' );
		$this->assertEmpty(
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
		update_option( \Safe_Publish\Utils\Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );
		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		delete_option( \Safe_Publish\Utils\Options::OPTION_CONNECTED_SITE_URL );
		$update_result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Update path also aborts correctly.
		$this->assertFalse( $update_result['success'], 'Re-import should fail when no source URL is configured.' );
		$this->assertNotEmpty( $update_result['error'] );
	}

	/**
	 * Verifies that the featured image is imported when bulk re-importing an
	 * existing post.
	 *
	 * This guards the update path in handle_imported_post(): if
	 * apply_media_and_taxonomy() were accidentally removed there, the thumbnail
	 * would silently stop being set on re-import and no other test would catch it.
	 */
	public function test_featured_image_is_imported_on_bulk_reimport(): void {
		// ARRANGE: Import a post with a featured image for the first time.
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

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
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

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
	 * Content_Processor::process_video_block() must update attrs['src']/attrs['id'],
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

		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

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
	 * Content_Processor::process_audio_block() must update attrs['src']/attrs['id'],
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

		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

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
}
