<?php
/**
 * Import content filtering integration tests
 *
 * Tests WordPress save filtering and persisted-content verification through
 * the import workflow.
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
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;

/**
 * Import Content Filtering Test Class.
 */
class Import_Sanitization_Test extends Integration_Test_Case {

	use Image_Byte_Mock_Trait;
	use Mock_Post_API_Trait;

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

		$this->repository = new History_Repository();

		$http_client       = new HTTP_Client();
		$media_importer    = new Media_Importer( $http_client );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		$this->import_service = new Post_Import_Service(
			new Source_Posts_API( $http_client ),
			$media_importer,
			$content_processor,
			$this->repository,
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter(),
			new Attention_Issues_Repository()
		);

		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			'https://source.example.com'
		);

		add_filter( 'pre_http_request', array( $this, 'mock_post_api' ), 1, 3 );
	}

	/**
	 * Tears down test dependencies.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_post_api' ), 1 );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Intercepts HTTP requests to the single-post REST endpoint.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $_args   HTTP request arguments.
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error Mocked response or prior value.
	 */
	public function mock_post_api(
		false|array|WP_Error $preempt,
		array $_args,
		string $url
	): false|array|WP_Error {
		if ( false !== $preempt || ! preg_match( '#/wp-json/wp/v2/posts/\d+#', $url ) ) {
			return $preempt;
		}

		return $this->build_mock_post_response();
	}

	/**
	 * Verifies that bulk import preserves content for an unfiltered user.
	 */
	public function test_bulk_import_preserves_content_for_unfiltered_user(): void {
		// ARRANGE: Content with a script tag that kses would strip.
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$content = '<p>Safe content.</p>'
			. '<script>alert("xss")</script>';

		$post_data = array(
			'id'             => 8001,
			'title'          => 'Sanitization Test Post',
			'content'        => $content,
			'link'           => 'https://source.example.com/sanitization-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => $content,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Import succeeded with content preserved.
		$this->assertTrue( $result['success'] );

		$post = get_post( $result['post_id'] );
		$this->assertStringContainsString(
			'<script>',
			$post->post_content
		);
	}

	/**
	 * Verifies that bulk import preserves excerpts for an unfiltered user.
	 */
	public function test_bulk_import_preserves_excerpt_for_unfiltered_user(): void {
		// ARRANGE: Excerpt with a script tag that kses would strip.
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$excerpt = '<em>Summary.</em><script>xss</script>';

		$post_data = array(
			'id'             => 8002,
			'title'          => 'Excerpt Sanitization Test',
			'content'        => '<p>Clean content.</p>',
			'link'           => 'https://source.example.com/excerpt-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => $excerpt,
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => '<p>Clean content.</p>',
			'excerpt' => $excerpt,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Import succeeded with excerpt preserved.
		$this->assertTrue( $result['success'] );

		$post = get_post( $result['post_id'] );
		$this->assertStringContainsString(
			'<script>',
			$post->post_excerpt
		);
	}

	/**
	 * Verifies that a filtered new import fails without leaving a local post.
	 */
	public function test_filtered_new_import_fails_without_leaving_a_post(): void {
		// ARRANGE: An author (no `unfiltered_html` by default on single-site)
		// imports content containing a script tag. The base test case sets up
		// an administrator by default; switch to a user without the capability.
		$author_id = self::factory()->user->create(
			array( 'role' => 'author' )
		);
		wp_set_current_user( $author_id );

		$content = '<p>Safe content.</p>'
			. '<script>alert("xss")</script>';

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'             => 8060,
			'title'          => 'Default Kses Without Capability',
			'content'        => $content,
			'link'           => 'https://source.example.com/default-kses-no-cap',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => $content,
		);

		// ACT: Import as the acting user WordPress applies kses to.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: The filtered write is reported and its new post is removed.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'WordPress filtered the requested post content',
			$result['error']
		);
		$this->assertNull(
			$this->import_service->find_imported_post(
				8060,
				'https://source.example.com'
			)
		);

		$items = $this->repository->get_session_items( $session_id );
		$this->assertCount( 1, $items );
		$this->assertSame( 'error', $items[0]['status'] );
		$this->assertSame( 0, (int) $items[0]['post_id'] );
	}

	/**
	 * Verifies that a vetoed filtered-import cleanup reports the surviving post.
	 */
	public function test_filtered_new_import_reports_vetoed_cleanup(): void {
		// ARRANGE: Filter the imported content and veto deletion of its new post.
		$author_id = self::factory()->user->create(
			array( 'role' => 'author' )
		);
		wp_set_current_user( $author_id );
		$content                   = '<p><img src="https://source.example.com/'
			. 'wp-content/uploads/2025/01/cleanup.jpg"></p>'
			. '<script>alert("xss")</script>';
		$session_id                = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);
		$post_data                 = array(
			'id'             => 8064,
			'title'          => 'Vetoed Cleanup',
			'content'        => $content,
			'link'           => 'https://source.example.com/vetoed-cleanup',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);
		$this->mock_post_overrides = array( 'content' => $content );
		$veto_delete               = static fn(): bool => false;
		add_filter( 'pre_delete_post', $veto_delete );
		add_filter( 'pre_delete_attachment', $veto_delete );
		$this->add_image_byte_response_mock();

		try {
			// ACT: Import while WordPress refuses to delete the filtered draft.
			$result = $this->import_service->import_post( $post_data, $session_id );
		} finally {
			$this->remove_image_byte_response_mock();
			remove_filter( 'pre_delete_attachment', $veto_delete );
			remove_filter( 'pre_delete_post', $veto_delete );
		}

		// ASSERT: The combined failure identifies the still-mapped draft.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'WordPress filtered the requested post content',
			$result['error']
		);
		$this->assertStringContainsString( 'Cleanup was incomplete', $result['error'] );
		$surviving_post = $this->import_service->find_imported_post(
			8064,
			'https://source.example.com'
		);
		$this->assertNotNull( $surviving_post );
		$this->assertStringContainsString(
			'post ID ' . $surviving_post->ID,
			$result['error']
		);
		$this->assertSame( $surviving_post->ID, $result['post_id'] );
		$this->assertSame( 'content_filtered', $result['original_error_code'] );
		$this->assertCount( 1, $result['media_ids'] );
		$this->assertStringContainsString(
			'attachment IDs ' . $result['media_ids'][0],
			$result['error']
		);
		$this->assertNotNull( get_post( $result['media_ids'][0] ) );

		$items = $this->repository->get_session_items( $session_id );
		$this->assertCount( 1, $items );
		$this->assertSame( 'error', $items[0]['status'] );
		$this->assertSame( $surviving_post->ID, (int) $items[0]['post_id'] );
		$stored_item = $this->repository->get_item( (int) $items[0]['id'] );
		$this->assertNotNull( $stored_item );
		$this->assertSame(
			'content_cleanup_failed',
			History_Repository::decode_item_changes(
				$stored_item['content_changes']
			)['action']
		);
		wp_delete_post( $surviving_post->ID, true );
		wp_delete_attachment( $result['media_ids'][0], true );
	}

	/**
	 * Verifies that a filtered single import reports the persisted mismatch.
	 */
	public function test_filtered_single_import_reports_failure(): void {
		// ARRANGE: A single import is run by a user without unfiltered_html.
		$author_id = self::factory()->user->create(
			array( 'role' => 'author' )
		);
		wp_set_current_user( $author_id );
		$session_id                = $this->repository->create_session(
			'https://source.example.com',
			'single'
		);
		$content                   = '<p>Safe.</p><script>alert("filtered")</script>';
		$post_data                 = array(
			'id'             => 8062,
			'title'          => 'Single Filter Test',
			'content'        => $content,
			'link'           => 'https://source.example.com/single-filter-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);
		$this->mock_post_overrides = array( 'content' => $content );

		// ACT: Import through the service used by the single AJAX action.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: The single result is a clear failure, not false success.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'WordPress filtered the requested post content',
			$result['error']
		);
	}

	/**
	 * Verifies that a filtered user can import content WordPress leaves intact.
	 */
	public function test_filtered_user_imports_unaffected_content(): void {
		// ARRANGE: An author imports markup allowed by WordPress.
		$author_id = self::factory()->user->create(
			array( 'role' => 'author' )
		);
		wp_set_current_user( $author_id );
		$session_id                = $this->repository->create_session(
			'https://source.example.com',
			'single'
		);
		$post_data                 = array(
			'id'             => 8063,
			'title'          => 'Allowed Content Test',
			'content'        => '<p>Allowed content.</p>',
			'link'           => 'https://source.example.com/allowed-content-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '<em>Allowed excerpt.</em>',
			'meta'           => array(),
			'terms'          => array(),
		);
		$this->mock_post_overrides = array(
			'content' => $post_data['content'],
			'excerpt' => $post_data['excerpt'],
		);

		// ACT: Import through WordPress' normal save filters.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Unchanged content succeeds for the filtered user.
		$this->assertTrue( $result['success'] );
		$this->assertSame(
			$post_data['content'],
			get_post( $result['post_id'] )->post_content
		);
	}

	/**
	 * Verifies that a caller granted unfiltered_html imports content unchanged.
	 */
	public function test_import_succeeds_for_caller_with_unfiltered_html(): void {
		// ARRANGE: A non-administrator role (author, which does not normally
		// hold `unfiltered_html`) is granted the capability explicitly. The
		// capability — not the role — must decide.
		$author_id = self::factory()->user->create(
			array( 'role' => 'author' )
		);
		$author    = get_user_by( 'id', $author_id );
		$author->add_cap( 'unfiltered_html' );
		wp_set_current_user( $author_id );

		$content = '<p>Safe content.</p>'
			. '<script>alert("trusted")</script>';

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'             => 8061,
			'title'          => 'Default Kses With Capability',
			'content'        => $content,
			'link'           => 'https://source.example.com/default-kses-with-cap',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => $content,
		);

		// ACT: Import as the explicitly trusted user.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: kses did not run; the script tag survived in storage.
		$this->assertTrue( $result['success'] );
		$post = get_post( $result['post_id'] );
		$this->assertStringContainsString(
			'<script>',
			$post->post_content
		);
	}

	/**
	 * Provides content shapes WordPress filters for an author.
	 *
	 * @return array[] Test cases keyed by label.
	 */
	public function provide_stripping_scenarios(): array {
		return array(
			'stripped tag'               => array(
				'<p>Text.</p><script>alert("xss")</script>',
			),
			'stripped tag with attrs'    => array(
				'<!-- wp:html -->'
					. '<iframe src="https://youtube.com/embed/abc"'
					. ' width="560" height="315"></iframe>'
					. '<!-- /wp:html -->',
			),
			'stripped attr on kept tag'  => array(
				'<p><img src="http://localhost/img.jpg"'
					. ' alt="Photo" decoding="async"/></p>',
			),
			'multiple stripped elements' => array(
				'<!-- wp:html -->'
					. '<svg viewBox="0 0 100 100">'
					. '<circle cx="50" cy="50" r="40"/>'
					. '</svg>'
					. '<!-- /wp:html -->',
			),
		);
	}

	/**
	 * Verifies that WordPress filtering is detected for several content shapes.
	 *
	 * @dataProvider provide_stripping_scenarios
	 *
	 * @param string $content Content with strippable HTML.
	 */
	public function test_import_reports_filtered_content_shapes(
		string $content
	): void {
		// ARRANGE: Import content that kses modifies as a filtered user.
		$author_id = self::factory()->user->create(
			array( 'role' => 'author' )
		);
		wp_set_current_user( $author_id );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'             => 8020,
			'title'          => 'Stripping Scenario Test',
			'content'        => $content,
			'link'           => 'https://source.example.com/strip-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => $content,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Import failed with a descriptive field-level error.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'WordPress filtered the requested post content',
			$result['error']
		);
	}

	/**
	 * Verifies that save-time formatting changes are reported.
	 */
	public function test_bulk_import_reports_save_time_formatting_changes(): void {
		// ARRANGE: Import content whose inline style is normalized by kses.
		$author_id = self::factory()->user->create(
			array( 'role' => 'author' )
		);
		wp_set_current_user( $author_id );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$content = '<!-- wp:button -->'
			. '<div class="wp-block-button">'
			. '<a class="wp-block-button__link"'
			. ' style="background-color: #ff0000; color: #ffffff">'
			. 'Click Me</a></div>'
			. '<!-- /wp:button -->';

		$post_data = array(
			'id'             => 8010,
			'title'          => 'Cosmetic Whitespace Test',
			'content'        => $content,
			'link'           => 'https://source.example.com/style-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => $content,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Any persisted difference is reported.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'post content', $result['error'] );
	}

	/**
	 * Verifies that bulk import reports filtered excerpt content.
	 */
	public function test_bulk_import_reports_filtered_excerpt(): void {
		// ARRANGE: Import an excerpt with a script tag as a filtered user.
		$author_id = self::factory()->user->create(
			array( 'role' => 'author' )
		);
		wp_set_current_user( $author_id );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$excerpt = '<em>Summary.</em><script>xss</script>';

		$post_data = array(
			'id'             => 8031,
			'title'          => 'Kses Filter Excerpt Test',
			'content'        => '<p>Clean content.</p>',
			'link'           => 'https://source.example.com/kses-excerpt',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => $excerpt,
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => '<p>Clean content.</p>',
			'excerpt' => $excerpt,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Import failed because the persisted excerpt differs.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'excerpt',
			$result['error']
		);
	}

	/**
	 * Verifies that a filtered update fails and restores the prior post.
	 */
	public function test_bulk_reimport_restores_post_after_filtering(): void {
		// ARRANGE: First import clean content, then use a filtered acting user.
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'             => 8032,
			'title'          => 'Reimport Sanitization Test',
			'content'        => '<p>Clean content.</p>',
			'link'           => 'https://source.example.com/reimport-kses',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => '<p>Clean content.</p>',
		);

		$first_result = $this->import_service->import_post(
			$post_data,
			$session_id
		);
		$this->assertTrue( $first_result['success'] );

		// ACT: Reimport with a script tag as a filtered user.
		$author_id = self::factory()->user->create(
			array( 'role' => 'author' )
		);
		wp_set_current_user( $author_id );

		$dirty_content = '<p>Updated.</p>'
			. '<script>alert("xss")</script>';

		$this->mock_post_overrides = array(
			'content' => $dirty_content,
		);

		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Reimport failed and compensation restored the old content.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'WordPress filtered the requested post content',
			$result['error']
		);
		$this->assertSame(
			'<p>Clean content.</p>',
			get_post( $first_result['post_id'] )->post_content
		);
	}

	/**
	 * Verifies that a filtered compensation keeps the original error context.
	 */
	public function test_update_reports_filtered_compensation(): void {
		// ARRANGE: Import content only an unfiltered user can save.
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);
		$post_data  = array(
			'id'             => 8034,
			'title'          => 'Compensation Test',
			'content'        => '<p>Initial.</p>',
			'link'           => 'https://source.example.com/compensation-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);
		$unsafe     = '<!-- wp:html --><iframe src="https://example.com/embed">'
			. '</iframe><!-- /wp:html -->';

		$this->mock_post_overrides = array( 'content' => $unsafe );
		$first_result              = $this->import_service->import_post(
			$post_data,
			$session_id
		);
		$this->assertTrue( $first_result['success'] );

		$author_id = self::factory()->user->create(
			array( 'role' => 'author' )
		);
		wp_set_current_user( $author_id );
		$this->mock_post_overrides = array(
			'content' => '<p>Allowed replacement.</p>',
			'meta'    => array( 'blocked_key' => 'new value' ),
		);
		$block_meta                = static function (
			mixed $check,
			int $_object_id,
			string $meta_key
		): mixed {
			return 'blocked_key' === $meta_key ? false : $check;
		};
		add_filter( 'update_post_metadata', $block_meta, 10, 3 );

		// ACT: Fail after the update, forcing filtered compensation.
		$result = $this->import_service->import_post( $post_data, $session_id );

		remove_filter( 'update_post_metadata', $block_meta, 10 );

		// ASSERT: Both failures are clear and the post holds the filtered snapshot.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'blocked_key', $result['error'] );
		$this->assertStringContainsString(
			'failed update could not be fully reversed',
			$result['error']
		);
		$this->assertStringContainsString(
			'WordPress filtered the restored post content',
			$result['error']
		);
		$this->assertSame(
			'<!-- wp:html --><!-- /wp:html -->',
			get_post( $first_result['post_id'] )->post_content
		);
	}

	/**
	 * Verifies that failed-update compensation reports surviving attachments.
	 */
	public function test_update_reports_vetoed_attachment_cleanup(): void {
		// ARRANGE: Import a post, then fail its update after sideloading media.
		$session_id                = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);
		$post_data                 = array(
			'id'             => 8035,
			'title'          => 'Update Media Cleanup',
			'content'        => '<p>Original.</p>',
			'link'           => 'https://source.example.com/update-media-cleanup',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);
		$this->mock_post_overrides = array( 'content' => '<p>Original.</p>' );
		$first_result              = $this->import_service->import_post(
			$post_data,
			$session_id
		);
		$this->assertTrue( $first_result['success'] );

		$this->mock_post_overrides = array(
			'content' => '<p><img src="https://source.example.com/'
				. 'wp-content/uploads/2025/01/update-cleanup.jpg"></p>',
			'meta'    => array( 'blocked_key' => 'new value' ),
		);
		$block_meta                = static function (
			mixed $check,
			int $_object_id,
			string $meta_key
		): mixed {
			return 'blocked_key' === $meta_key ? false : $check;
		};
		$veto_delete               = static fn(): bool => false;
		add_filter( 'update_post_metadata', $block_meta, 10, 3 );
		add_filter( 'pre_delete_attachment', $veto_delete );
		$this->add_image_byte_response_mock();

		try {
			// ACT: Fail the update while attachment deletion is vetoed.
			$result = $this->import_service->import_post( $post_data, $session_id );
		} finally {
			$this->remove_image_byte_response_mock();
			remove_filter( 'pre_delete_attachment', $veto_delete );
			remove_filter( 'update_post_metadata', $block_meta, 10 );
		}

		// ASSERT: Original and cleanup failures plus recovery IDs are retained.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'blocked_key', $result['error'] );
		$this->assertStringContainsString( 'attachment IDs', $result['error'] );
		$this->assertCount( 1, $result['media_ids'] );
		$this->assertNotNull( get_post( $result['media_ids'][0] ) );
		$this->assertSame(
			'<p>Original.</p>',
			get_post( $first_result['post_id'] )->post_content
		);

		$items       = $this->repository->get_session_items( $session_id );
		$stored_item = $this->repository->get_item(
			(int) $items[ count( $items ) - 1 ]['id']
		);
		$this->assertNotNull( $stored_item );
		$changes = History_Repository::decode_item_changes(
			$stored_item['content_changes']
		);
		$this->assertSame( 'content_cleanup_failed', $changes['action'] );
		$this->assertSame( $result['media_ids'], $changes['media_ids'] );
		wp_delete_attachment( $result['media_ids'][0], true );
	}

	/**
	 * Verifies that normal WordPress save filters run and are detected.
	 */
	public function test_bulk_import_runs_custom_save_filters(): void {
		// ARRANGE: A site filter changes otherwise allowed content during save.
		$filter = static fn( string $content ): string => $content . '<p>Filtered.</p>';
		add_filter( 'content_save_pre', $filter );

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$content = '<p>Original.</p>';

		$post_data = array(
			'id'             => 8033,
			'title'          => 'Custom Allowed Tags Test',
			'content'        => $content,
			'link'           => 'https://source.example.com/custom-tags',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => $content,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		remove_filter( 'content_save_pre', $filter );

		// ASSERT: The save filter ran and the filtered post was removed.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'post content', $result['error'] );
		$this->assertNull(
			$this->import_service->find_imported_post(
				8033,
				'https://source.example.com'
			)
		);
	}
}
