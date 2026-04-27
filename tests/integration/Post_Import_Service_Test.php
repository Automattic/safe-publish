<?php
/**
 * Post_Import_Service integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Closure;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Renderer;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Import_History;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\Admin\Session_Formatter;
use Safe_Publish\Admin\Session_Rollback_Service;
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
			new Content_Media_Processor( $media_importer )
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
		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );
		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		$update_result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Update path also aborts correctly.
		$this->assertFalse( $update_result['success'], 'Re-import should fail when no source URL is configured.' );
		$this->assertNotEmpty( $update_result['error'] );
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

	/**
	 * Verifies that partially-downloaded attachments are cleaned up when an
	 * import is aborted due to a media failure.
	 *
	 * All blocks are processed independently before the failure check runs, so
	 * any successful downloads that preceded a failure will have created real
	 * attachments. All of those must be deleted on abort to leave the media
	 * library in a clean state.
	 */
	public function test_orphaned_attachments_are_deleted_when_import_is_aborted(): void {
		// ARRANGE: Content with two images — first succeeds, second fails (nonexistent).
		$good_url   = 'https://source.example.com/real-image.jpg';
		$broken_url = 'https://source.example.com/nonexistent-partial.jpg';

		$this->mock_post_overrides = array(
			'content' => '<p>'
				. '<img src="' . $good_url . '" alt="good">'
				. '<img src="' . $broken_url . '" alt="broken">'
				. '</p>',
		);

		$session_id         = $this->import_history->create_session( 'https://source.example.com', 'bulk' );
		$attachments_before = $this->get_attachment_count();

		$post_data = array(
			'id'        => 8301,
			'title'     => 'Post With Partial Media Failure',
			'content'   => '<p>Stale content.</p>',
			'link'      => 'https://source.example.com/partial-media-failure',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import aborted due to the broken image.
		$this->assertFalse(
			$result['success'],
			'Import should fail when one of multiple images cannot be downloaded.'
		);
		$this->assertStringContainsString( 'nonexistent-partial.jpg', $result['error'] );

		// ASSERT: No post was created.
		$this->assertEmpty(
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_EXTERNAL_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '8301',
				)
			),
			'No post should be created when a media import fails.'
		);

		// ASSERT: The attachment created for the successful image was cleaned up.
		$this->assert_no_new_attachments(
			$attachments_before,
			'Attachments created before the failure must be deleted when the import is aborted.'
		);
	}

	/**
	 * Verifies that sideloaded attachments (including featured image) are deleted
	 * when the import fails at the terms-update step on the create path.
	 */
	public function test_sideloaded_attachments_cleaned_up_when_terms_update_fails(): void {
		// ARRANGE: Fresh content includes a featured image so one attachment is
		// sideloaded before wp_insert_post runs. An unknown taxonomy in the terms
		// data triggers the failure after the post is written.
		$this->mock_post_overrides = array(
			'featured_media' => 100,
			'terms'          => array( 'nonexistent_taxonomy_xyz' => array( 'Some Term' ) ),
		);

		$session_id         = $this->import_history->create_session( 'https://source.example.com', 'bulk' );
		$attachments_before = $this->get_attachment_count();

		$post_data = array(
			'id'        => 9210,
			'title'     => 'Post With Unknown Taxonomy',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/unknown-taxonomy-test',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import failed due to the unknown taxonomy.
		$this->assertFalse( $result['success'], 'Import should fail when a term taxonomy does not exist.' );
		$this->assertStringContainsString( 'nonexistent_taxonomy_xyz', $result['error'] );

		// ASSERT: No post was created.
		$this->assertEmpty(
			get_posts(
				array(
					'post_type'        => 'post',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_EXTERNAL_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '9210',
				)
			),
			'No post should remain after a failed import.'
		);

		// ASSERT: The sideloaded featured image attachment was cleaned up.
		$this->assert_no_new_attachments(
			$attachments_before,
			'Sideloaded attachments must be deleted when the terms update fails.'
		);
	}

	/**
	 * Verifies that the import aborts without creating a draft when the
	 * featured image cannot be imported.
	 *
	 * The featured image is sideloaded before the post is inserted, so a
	 * failure here means no post is ever written to the DB.
	 */
	public function test_import_aborts_and_deletes_draft_when_featured_image_fails(): void {
		// ARRANGE: Fresh-content response includes featured_media > 0 so the
		// import path attempts to fetch the featured image. The fail filter
		// runs at priority 6 — after mock_media_api_request (priority 5) — so
		// it can override that response and return a 404, causing
		// import_featured_image() to return false.
		$this->mock_post_overrides = array( 'featured_media' => 100 );

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'        => 9101,
			'title'     => 'Post With Failed Featured Image',
			'content'   => '<p>Content.</p>',
			'link'      => 'https://source.example.com/failed-featured-image',
			'post_type' => 'posts',
		);

		// ACT: Attempt to import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_http_request', $fail_media_api, 6 );

		// ASSERT: Import must fail with a featured image error.
		$this->assertFalse( $result['success'], 'Import should fail when the featured image cannot be imported.' );
		$this->assertStringContainsString( 'featured image', $result['error'] );

		// ASSERT: The orphaned draft must have been deleted.
		$this->assertEmpty(
			get_posts(
				array(
					'post_type'        => 'post',
					'post_status'      => 'any',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
					'meta_key'         => Options::META_EXTERNAL_POST_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => '9101',
				)
			),
			'The post must not exist when the featured image import fails before insertion.'
		);
	}

	/**
	 * Verifies that the import aborts without modifying the post when the
	 * featured image cannot be imported on re-import.
	 *
	 * The featured image is sideloaded before the post is written, so a failure
	 * here leaves the existing post untouched.
	 */
	public function test_import_aborts_without_deleting_post_when_featured_image_fails_on_update(): void {
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

		// ARRANGE: Import the post once with no featured image so it exists in
		// the DB.
		$post_data = array(
			'id'        => 9102,
			'title'     => 'Post For Featured Image Update Test',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/featured-image-update-test',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );
		$post_id = $first['post_id'];

		// ARRANGE: Re-import with featured_media > 0, but make the media API fail.
		// The fail filter runs at priority 6 — after mock_media_api_request
		// (priority 5) — so it can override that response and return a 404.
		$this->mock_post_overrides = array( 'featured_media' => 100 );

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_http_request', $fail_media_api, 6 );

		// ASSERT: Import must fail with a featured image error.
		$this->assertFalse( $result['success'], 'Re-import should fail when the featured image cannot be imported.' );
		$this->assertStringContainsString( 'featured image', $result['error'] );

		// ASSERT: The existing post must still be present in the DB.
		$this->assertNotNull(
			get_post( $post_id ),
			'The existing post must not be deleted when featured image import fails on the update path.'
		);
	}

	/**
	 * Verifies that the existing post is not modified when the featured image
	 * import fails on the bulk update path.
	 *
	 * The featured image is sideloaded before the post is written, so a failure
	 * aborts the import before any DB write. Title, content, and tracking meta
	 * must all be identical to their values before the import attempt began.
	 */
	public function test_import_restores_post_on_featured_image_failure_during_bulk_update(): void {
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

		// ARRANGE: Import the post once with clean content so it exists in the DB.
		$post_data = array(
			'id'        => 9103,
			'title'     => 'Original Title',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/restore-on-failure-test',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );
		$post_id = $first['post_id'];

		$original_title   = get_post_field( 'post_title', $post_id );
		$original_content = get_post_field( 'post_content', $post_id );
		$original_link    = get_post_meta( $post_id, Options::META_EXTERNAL_LINK, true );
		$original_date    = get_post_meta( $post_id, Options::META_IMPORT_DATE, true );

		// ARRANGE: Fresh content will return updated title/content and a featured
		// image. The fail filter makes the media API return 404 to trigger failure.
		$this->mock_post_overrides = array(
			'title'          => 'Updated Title',
			'content'        => '<p>Updated content that must not be saved.</p>',
			'featured_media' => 100,
		);

		$fail_media_api = $this->make_featured_image_fail_filter();
		add_filter( 'pre_http_request', $fail_media_api, 6, 3 );

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'pre_http_request', $fail_media_api, 6 );

		// ASSERT: Import must fail.
		$this->assertFalse( $result['success'], 'Re-import should fail when the featured image cannot be imported.' );
		$this->assertStringContainsString( 'featured image', $result['error'] );

		// ASSERT: Post fields and tracking meta must be unchanged: the import
		// aborted before any DB write.
		$this->assertSame( $original_title, get_post_field( 'post_title', $post_id ), 'Title must be unchanged after failed update.' );
		$this->assertSame( $original_content, get_post_field( 'post_content', $post_id ), 'Content must be unchanged after failed update.' );
		$this->assertSame( $original_link, get_post_meta( $post_id, Options::META_EXTERNAL_LINK, true ), 'External link meta must be unchanged after failed update.' );
		$this->assertSame( $original_date, get_post_meta( $post_id, Options::META_IMPORT_DATE, true ), 'Import date meta must be unchanged after failed update.' );
	}

	/**
	 * Verifies that the bulk update path returns a failure and rolls back the
	 * post when the tracking meta write fails.
	 *
	 * If update_post_meta fails for META_IMPORT_DATE (e.g., a DB error), the
	 * import must report failure and restore the post to its pre-update state.
	 * The filter blocks all writes for this key, so the rollback's own restore
	 * of META_IMPORT_DATE is also blocked; only the post fields and external
	 * link meta are verified here.
	 */
	public function test_bulk_update_fails_when_tracking_meta_write_fails(): void {
		$session_id = $this->import_history->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import the post once to create it in the DB.
		$post_data = array(
			'id'        => 9120,
			'title'     => 'Post For Tracking Meta Failure Test',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/tracking-meta-failure-test',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'], 'Initial import should succeed.' );
		$post_id = $first['post_id'];

		// ARRANGE: Capture pre-update values for rollback assertions.
		$original_title   = get_post_field( 'post_title', $post_id );
		$original_content = get_post_field( 'post_content', $post_id );
		$original_link    = get_post_meta(
			$post_id,
			Options::META_EXTERNAL_LINK,
			true
		);

		// ARRANGE: Fresh content returns updated fields so the update path
		// writes new values before the meta failure triggers.
		$this->mock_post_overrides = array(
			'title'   => 'Updated Tracking Meta Title',
			'content' => '<p>Updated content.</p>',
		);

		// ARRANGE: Block update_post_meta for META_IMPORT_DATE to simulate a DB
		// failure.
		$block_meta = function (
			$check,
			$object_id,
			$meta_key,
			$meta_value,
			$prev_value
		) {
			unset( $object_id, $meta_value, $prev_value );
			if ( Options::META_IMPORT_DATE === $meta_key ) {
				return false;
			}
			return $check;
		};
		add_filter( 'update_post_metadata', $block_meta, 10, 5 );

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'update_post_metadata', $block_meta, 10 );

		// ASSERT: Import must report failure.
		$this->assertFalse(
			$result['success'],
			'Update import should fail when tracking meta cannot be written.'
		);
		$this->assertStringContainsString(
			'tracking metadata',
			$result['error']
		);

		// ASSERT: Post fields must be rolled back.
		$this->assertSame(
			$original_title,
			get_post_field( 'post_title', $post_id ),
			'Title must be restored after tracking meta failure.'
		);
		$this->assertSame(
			$original_content,
			get_post_field( 'post_content', $post_id ),
			'Content must be restored after tracking meta failure.'
		);
		$this->assertSame(
			$original_link,
			get_post_meta(
				$post_id,
				Options::META_EXTERNAL_LINK,
				true
			),
			'External link meta must be restored after tracking meta failure.'
		);
	}

	/**
	 * Verifies that the update path returns an error when wp_update_post()
	 * fails silently by returning 0.
	 *
	 * The wp_insert_post_empty_content filter forces wp_update_post() to return
	 * 0 before any DB write occurs. The function must surface a WP_Error and
	 * leave the post unchanged instead of proceeding to write meta against a
	 * stale post.
	 */
	public function test_bulk_update_fails_on_silent_post_update_failure(): void {
		$session_id = $this->import_history->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import the post once to create it in the DB.
		$post_data = array(
			'id'        => 9150,
			'title'     => 'Post For Silent Update Failure Test',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/silent-update-failure',
			'post_type' => 'posts',
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue(
			$first['success'],
			'Initial import should succeed.'
		);
		$post_id = $first['post_id'];

		// ARRANGE: Capture pre-update values.
		$original_title   = get_post_field( 'post_title', $post_id );
		$original_content = get_post_field( 'post_content', $post_id );
		$original_date    = get_post_meta(
			$post_id,
			Options::META_IMPORT_DATE,
			true
		);

		// ARRANGE: Fresh content returns updated fields.
		$this->mock_post_overrides = array(
			'title'   => 'Updated Silent Failure Title',
			'content' => '<p>Updated content.</p>',
		);

		// ARRANGE: Force wp_update_post() to return 0 by short-circuiting the
		// empty-content check inside wp_insert_post().
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'wp_insert_post_empty_content', '__return_true' );

		// ASSERT: Import must report failure.
		$this->assertFalse(
			$result['success'],
			'Update import should fail when wp_update_post returns 0.'
		);

		// ASSERT: Post fields and tracking meta must remain at their
		// pre-update values; no writes should have hit the stale post.
		$this->assertSame(
			$original_title,
			get_post_field( 'post_title', $post_id ),
			'Title must remain unchanged when wp_update_post fails silently.'
		);
		$this->assertSame(
			$original_content,
			get_post_field( 'post_content', $post_id ),
			'Content must remain unchanged when wp_update_post fails silently.'
		);
		$this->assertSame(
			$original_date,
			get_post_meta(
				$post_id,
				Options::META_IMPORT_DATE,
				true
			),
			'Import date must remain unchanged when wp_update_post fails silently.'
		);
	}

	/**
	 * Verifies that the post is fully rolled back when custom meta update fails
	 * on the bulk update path.
	 *
	 * The filter blocks writes for a specific custom meta key, causing
	 * Meta_Terms_Manager::update_meta() to return a WP_Error. Post fields,
	 * tracking meta, and featured image must all be restored to their
	 * pre-update values.
	 */
	public function test_bulk_update_rolls_back_post_on_custom_meta_failure(): void {
		$session_id = $this->import_history->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import the post with initial meta and a featured image.
		$post_data = array(
			'id'             => 9130,
			'title'          => 'Post For Meta Rollback Test',
			'content'        => '<p>Original content.</p>',
			'link'           => 'https://source.example.com/meta-rollback-test',
			'post_type'      => 'posts',
			'featured_media' => 100,
			'meta'           => array( 'custom_field' => 'original_value' ),
		);

		$this->mock_post_overrides = array(
			'featured_media' => 100,
			'meta'           => array( 'custom_field' => 'original_value' ),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue(
			$first['success'],
			'Initial import should succeed.'
		);
		$post_id = $first['post_id'];

		// ARRANGE: Capture pre-update values for rollback assertions.
		$original_title        = get_post_field( 'post_title', $post_id );
		$original_content      = get_post_field( 'post_content', $post_id );
		$original_thumbnail_id = (int) get_post_thumbnail_id( $post_id );
		$original_link         = get_post_meta(
			$post_id,
			Options::META_EXTERNAL_LINK,
			true
		);
		$original_date         = get_post_meta(
			$post_id,
			Options::META_IMPORT_DATE,
			true
		);
		$original_meta         = get_post_meta(
			$post_id,
			'custom_field',
			true
		);

		$this->assertGreaterThan(
			0,
			$original_thumbnail_id,
			'Initial import should set a featured image.'
		);

		// ARRANGE: Fresh content returns updated fields, meta, and a new
		// featured image so the rollback has a thumbnail change to restore.
		$this->mock_post_overrides = array(
			'title'          => 'Updated Meta Rollback Title',
			'content'        => '<p>Updated content.</p>',
			'featured_media' => 200,
			'meta'           => array( 'custom_field' => 'updated_value' ),
		);

		// ARRANGE: Block update_post_meta for 'custom_field' to simulate a DB
		// failure during Meta_Terms_Manager::update_meta.
		$block_meta = function (
			$check,
			$object_id,
			$meta_key,
			$meta_value,
			$prev_value
		) {
			unset( $object_id, $meta_value, $prev_value );
			if ( 'custom_field' === $meta_key ) {
				return false;
			}
			return $check;
		};
		add_filter( 'update_post_metadata', $block_meta, 10, 5 );

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'update_post_metadata', $block_meta, 10 );

		// ASSERT: Import must fail with a meta error.
		$this->assertFalse(
			$result['success'],
			'Update import should fail when custom meta cannot be written.'
		);
		$this->assertStringContainsString(
			'custom_field',
			$result['error']
		);

		// ASSERT: Post fields must be rolled back.
		$this->assertSame(
			$original_title,
			get_post_field( 'post_title', $post_id ),
			'Title must be restored after custom meta failure.'
		);
		$this->assertSame(
			$original_content,
			get_post_field( 'post_content', $post_id ),
			'Content must be restored after custom meta failure.'
		);

		// ASSERT: Featured image must be rolled back to the original.
		$this->assertSame(
			$original_thumbnail_id,
			(int) get_post_thumbnail_id( $post_id ),
			'Featured image must be restored after custom meta failure.'
		);

		// ASSERT: Tracking meta must be rolled back.
		$this->assertSame(
			$original_link,
			get_post_meta(
				$post_id,
				Options::META_EXTERNAL_LINK,
				true
			),
			'External link meta must be restored after custom meta failure.'
		);
		$this->assertSame(
			$original_date,
			get_post_meta(
				$post_id,
				Options::META_IMPORT_DATE,
				true
			),
			'Import date meta must be restored after custom meta failure.'
		);

		// ASSERT: Custom meta must be unchanged. The filter blocked both the
		// import write and the rollback write for this key, but since the value
		// was already 'original_value' in the DB before the import, it remains
		// correct.
		$this->assertSame(
			$original_meta,
			get_post_meta( $post_id, 'custom_field', true ),
			'Custom meta must remain at its original value.'
		);
	}

	/**
	 * Verifies that the post is fully rolled back when term assignment fails on
	 * the bulk update path.
	 *
	 * An unknown taxonomy in the terms data causes
	 * Meta_Terms_Manager::update_terms() to return a WP_Error. Post fields,
	 * tracking meta, and custom meta must all be restored to their pre-update
	 * values.
	 */
	public function test_bulk_update_rolls_back_post_on_term_failure(): void {
		$session_id = $this->import_history->create_session(
			'https://source.example.com',
			'bulk'
		);

		// ARRANGE: Import the post with initial meta and a category.
		$post_data = array(
			'id'        => 9140,
			'title'     => 'Post For Term Rollback Test',
			'content'   => '<p>Original content.</p>',
			'link'      => 'https://source.example.com/term-rollback-test',
			'post_type' => 'posts',
			'meta'      => array( 'my_field' => 'original' ),
			'terms'     => array(
				'category' => array( 'Rollback Test Category' ),
			),
		);

		$this->mock_post_overrides = array(
			'meta'  => array( 'my_field' => 'original' ),
			'terms' => array(
				'category' => array( 'Rollback Test Category' ),
			),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue(
			$first['success'],
			'Initial import should succeed.'
		);
		$post_id = $first['post_id'];

		// ARRANGE: Capture pre-update values for rollback assertions.
		$original_title   = get_post_field( 'post_title', $post_id );
		$original_content = get_post_field( 'post_content', $post_id );
		$original_date    = get_post_meta(
			$post_id,
			Options::META_IMPORT_DATE,
			true
		);
		$original_meta    = get_post_meta( $post_id, 'my_field', true );
		$original_terms   = wp_get_object_terms(
			$post_id,
			'category',
			array( 'fields' => 'ids' )
		);

		// ARRANGE: Fresh content returns updated fields, meta, and an unknown
		// taxonomy to trigger term failure.
		$this->mock_post_overrides = array(
			'title'   => 'Updated Term Rollback Title',
			'content' => '<p>Updated content.</p>',
			'meta'    => array( 'my_field' => 'updated' ),
			'terms'   => array(
				'category'                  => array(
					'Rollback Test Category',
				),
				'nonexistent_taxonomy_term' => array(
					'Some Term',
				),
			),
		);

		// ACT: Re-import the same post (hits the update path).
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Import must fail with a taxonomy error.
		$this->assertFalse(
			$result['success'],
			'Update import should fail for unknown taxonomy.'
		);
		$this->assertStringContainsString(
			'nonexistent_taxonomy_term',
			$result['error']
		);

		// ASSERT: Post fields must be rolled back.
		$this->assertSame(
			$original_title,
			get_post_field( 'post_title', $post_id ),
			'Title must be restored after term failure.'
		);
		$this->assertSame(
			$original_content,
			get_post_field( 'post_content', $post_id ),
			'Content must be restored after term failure.'
		);

		// ASSERT: Tracking meta must be rolled back.
		$this->assertSame(
			$original_date,
			get_post_meta(
				$post_id,
				Options::META_IMPORT_DATE,
				true
			),
			'Import date meta must be restored after term failure.'
		);

		// ASSERT: Custom meta must be rolled back.
		$this->assertSame(
			$original_meta,
			get_post_meta( $post_id, 'my_field', true ),
			'Custom meta must be restored after term failure.'
		);

		// ASSERT: Term assignments must be rolled back.
		$restored_terms = wp_get_object_terms(
			$post_id,
			'category',
			array( 'fields' => 'ids' )
		);
		$this->assertSame(
			$original_terms,
			$restored_terms,
			'Category terms must be restored after term failure.'
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

		$session_id = $this->import_history->create_session(
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

		$session_id = $this->import_history->create_session(
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
		$this->assertEmpty(
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

		$session_id = $this->import_history->create_session(
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
		$session_id = $this->import_history->create_session(
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

		$session_id = $this->import_history->create_session(
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
		$session_id = $this->import_history->create_session(
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

		$session_id = $this->import_history->create_session(
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
		$session_id = $this->import_history->create_session(
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
	 * Returns a pre_http_request filter that makes the media JSON API return 404.
	 *
	 * Registered at priority 6 so it runs after the mock at priority 5 and
	 * overrides the normal mock response to simulate a failed API request.
	 *
	 * @return Closure
	 */
	private function make_featured_image_fail_filter(): Closure {
		return function ( $preempt, array $args, string $url ) {
			unset( $args );
			if ( str_contains( $url, 'wp-json/wp/v2/media/' ) ) {
				return array(
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'body'     => 'Not Found',
					'headers'  => array(),
				);
			}
			return $preempt;
		};
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
	 * Verifies that the bulk import path writes a row to the import log when
	 * post-type resolution returns a WP_Error.
	 */
	public function test_bulk_import_logs_failure_when_post_type_unregistered(): void {
		// ARRANGE: Bulk session targeting an unregistered post type.
		$session_id = $this->import_history->create_session(
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

		// ASSERT: A log row was written for the session.
		$logs = get_posts(
			array(
				'post_type'      => History_Repository::LOG_POST_TYPE,
				'post_status'    => 'publish',
				'post_parent'    => $session_id,
				'posts_per_page' => -1,
			)
		);

		$this->assertCount(
			1,
			$logs,
			'Bulk import must log a row when post-type resolution fails.'
		);
		$this->assertSame(
			'error',
			get_post_meta( $logs[0]->ID, 'status', true ),
			'Logged row must record the import as an error.'
		);
		$this->assertSame(
			9803,
			(int) get_post_meta( $logs[0]->ID, 'external_id', true ),
			'Logged row must reference the failing external post ID.'
		);
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
