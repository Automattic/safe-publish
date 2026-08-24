<?php
/**
 * Import sanitization integration tests
 *
 * Tests the Sanitizes_Content trait behavior (kses filtering, content
 * preservation, error messages, and custom allowed tags) exercised through
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
 * Import Sanitization Test Class.
 */
class Import_Sanitization_Test extends Integration_Test_Case {

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
	 * Verifies that bulk import preserves content with script tags when kses is
	 * disabled (default).
	 */
	public function test_bulk_import_preserves_content_by_default(): void {
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
	 * Verifies that bulk import preserves excerpts with script tags when kses
	 * is disabled (default).
	 */
	public function test_bulk_import_preserves_excerpt_by_default(): void {
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
	 * Verifies that the capability-based default applies wp_kses for a caller
	 * that does not hold unfiltered_html, aborting the import when the
	 * source content contains HTML kses strips.
	 */
	public function test_kses_runs_by_default_for_caller_without_unfiltered_html(): void {
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

		// ACT: Import without setting the safe_publish_import_kses filter so
		// the capability-based default decides.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: kses ran and aborted the import with a descriptive error.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'modified by sanitization',
			$result['error']
		);
		$this->assertStringContainsString(
			'<script>',
			$result['error']
		);
	}

	/**
	 * Verifies that the capability-based default skips wp_kses for any caller
	 * that holds unfiltered_html, regardless of role, so source HTML kses
	 * would otherwise strip imports verbatim.
	 */
	public function test_kses_skipped_by_default_for_caller_with_unfiltered_html(): void {
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

		// ACT: Import without setting the safe_publish_import_kses filter so
		// the capability-based default decides.
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
	 * Provides stripping scenarios with content and expected error substrings.
	 *
	 * @return array[] Test cases keyed by label.
	 */
	public function provide_stripping_scenarios(): array {
		return array(
			'stripped tag'               => array(
				'<p>Text.</p><script>alert("xss")</script>',
				array( '<script>' ),
			),
			'stripped tag with attrs'    => array(
				'<!-- wp:html -->'
					. '<iframe src="https://youtube.com/embed/abc"'
					. ' width="560" height="315"></iframe>'
					. '<!-- /wp:html -->',
				array( '<iframe', 'src=' ),
			),
			'stripped attr on kept tag'  => array(
				'<p><img src="http://localhost/img.jpg"'
					. ' alt="Photo" decoding="async"/></p>',
				array( '<img', 'decoding=' ),
			),
			'multiple stripped elements' => array(
				'<!-- wp:html -->'
					. '<svg viewBox="0 0 100 100">'
					. '<circle cx="50" cy="50" r="40"/>'
					. '</svg>'
					. '<!-- /wp:html -->',
				array( '<svg', '<circle' ),
			),
		);
	}

	/**
	 * Verifies that the sanitization error message describes the specific HTML
	 * that was stripped for different stripping types when kses is enabled via
	 * filter.
	 *
	 * @dataProvider provide_stripping_scenarios
	 *
	 * @param string   $content           Content with strippable HTML.
	 * @param string[] $expected_in_error Substrings that must appear
	 *                                    in the error message.
	 */
	public function test_sanitization_error_describes_stripped_html(
		string $content,
		array $expected_in_error
	): void {
		// ARRANGE: Enable kses via filter, then import content that kses would
		// modify.
		add_filter( 'safe_publish_import_kses', '__return_true' );

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

		remove_filter( 'safe_publish_import_kses', '__return_true' );

		// ASSERT: Import failed with a descriptive error.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'modified by sanitization',
			$result['error']
		);

		foreach ( $expected_in_error as $expected ) {
			$this->assertStringContainsString(
				$expected,
				$result['error'],
				"Error should mention: $expected"
			);
		}
	}

	/**
	 * Verifies that bulk import succeeds when kses-enabled sanitization only
	 * makes cosmetic whitespace changes (no false positives).
	 */
	public function test_bulk_import_succeeds_with_cosmetic_whitespace_changes(): void {
		// ARRANGE: Enable kses, then import content with inline styles that
		// kses normalizes (e.g. removes space after semicolons) but does not
		// meaningfully modify.
		add_filter( 'safe_publish_import_kses', '__return_true' );

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

		remove_filter( 'safe_publish_import_kses', '__return_true' );

		// ASSERT: Import succeeded despite cosmetic changes.
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Verifies that bulk import strips script tags from excerpts when kses is
	 * enabled via the safe_publish_import_kses filter.
	 */
	public function test_bulk_import_sanitizes_excerpt(): void {
		// ARRANGE: Enable kses, then import an excerpt with a script tag.
		add_filter( 'safe_publish_import_kses', '__return_true' );

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

		remove_filter( 'safe_publish_import_kses', '__return_true' );

		// ASSERT: Import failed due to excerpt sanitization.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'excerpt',
			$result['error']
		);
		$this->assertStringContainsString(
			'<script>',
			$result['error']
		);
	}

	/**
	 * Verifies that reimporting a post with kses enabled fails when the updated
	 * content contains tags that kses would strip.
	 */
	public function test_bulk_reimport_sanitizes_post_content(): void {
		// ARRANGE: First import clean content, then reimport with a script tag
		// while kses is enabled.
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

		// ACT: Reimport with kses enabled and a script tag.
		add_filter( 'safe_publish_import_kses', '__return_true' );

		$dirty_content = '<p>Updated.</p>'
			. '<script>alert("xss")</script>';

		$this->mock_post_overrides = array(
			'content' => $dirty_content,
		);

		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		remove_filter( 'safe_publish_import_kses', '__return_true' );

		// ASSERT: Reimport failed due to sanitization.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'modified by sanitization',
			$result['error']
		);
	}

	/**
	 * Verifies that the safe_publish_import_kses_allowed_html filter lets
	 * developers customize which tags are allowed when kses is enabled.
	 */
	public function test_bulk_import_uses_custom_allowed_tags(): void {
		// ARRANGE: Enable kses and add <iframe> to allowed tags.
		add_filter( 'safe_publish_import_kses', '__return_true' );

		$allow_iframes = static function ( array $allowed ): array {
			$allowed['iframe'] = array(
				'src'    => true,
				'width'  => true,
				'height' => true,
			);
			return $allowed;
		};
		add_filter(
			'safe_publish_import_kses_allowed_html',
			$allow_iframes
		);

		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);

		$content = '<p>Watch this:</p>'
			. '<iframe src="https://youtube.com/embed/abc"'
			. ' width="560" height="315"></iframe>';

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

		remove_filter( 'safe_publish_import_kses', '__return_true' );
		remove_filter(
			'safe_publish_import_kses_allowed_html',
			$allow_iframes
		);

		// ASSERT: Import succeeded — iframe is in the custom allowlist.
		$this->assertTrue( $result['success'] );

		$post = get_post( $result['post_id'] );
		$this->assertStringContainsString(
			'<iframe',
			$post->post_content
		);
	}
}
