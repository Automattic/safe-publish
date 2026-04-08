<?php
/**
 * Integration Tests for Admin Content Processor
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Import_Mode_Admin_Handler;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Embed_Processor;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\Integration_Test_Case;
use Safe_Publish\Tests\Integration\Mock_Media_HTTP_Trait;

/**
 * Content Processor Integration Test Class.
 *
 * Tests the admin Content_Processor's HTML transformation pipeline.
 */
class Content_Processor_Test extends Integration_Test_Case {

	use Mock_Media_HTTP_Trait;

	/**
	 * Content Processor instance under test.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $processor;

	/**
	 * Sets up test environment and builds the processor with real dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$http_client             = new HTTP_Client();
		$media_importer          = new Media_Importer( $http_client );
		$content_media_processor = new Content_Media_Processor(
			$media_importer,
			new Embed_Processor()
		);

		$this->processor = new Content_Processor( $media_importer, $content_media_processor );

		add_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10, 3 );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'fix_empty_temp_files' ), 10, 1 );
	}

	/**
	 * Tears down test environment.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10 );
		remove_filter( 'wp_handle_sideload_prefilter', array( $this, 'fix_empty_temp_files' ), 10 );
		parent::tearDown();
	}

	/**
	 * Mocks HTTP requests for media downloads, serving real fixture files.
	 *
	 * @param false|array|\WP_Error $preempt A preemptive return value.
	 * @param array                 $args    HTTP request arguments.
	 * @param string                $url     The request URL.
	 * @return false|array|\WP_Error
	 */
	public function mock_http_request( $preempt, array $args, string $url ) {
		unset( $args );

		if ( false !== $preempt || ! str_contains( $url, 'source.example.com' ) ) {
			return $preempt;
		}

		$extension   = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$fixture_map = array(
			'jpg'  => array( 'test-1x1.jpg', 'image/jpeg' ),
			'jpeg' => array( 'test-1x1.jpg', 'image/jpeg' ),
			'png'  => array( 'test-1x1.png', 'image/png' ),
			'gif'  => array( 'test-1x1.gif', 'image/gif' ),
			'webp' => array( 'test-1x1.webp', 'image/webp' ),
		);

		if ( ! isset( $fixture_map[ $extension ] ) ) {
			return $preempt;
		}

		return $this->get_fixture_response( ...$fixture_map[ $extension ] );
	}

	/**
	 * Verifies that relative URLs in content are converted to absolute URLs.
	 *
	 * Root-relative paths (e.g. /about) should become fully qualified URLs
	 * after processing so the imported content has no broken relative links.
	 */
	public function test_process_content_converts_relative_urls_to_absolute(): void {
		// ARRANGE: Classic HTML with a root-relative anchor href.
		$source_site = 'https://source.example.com';
		$content     = '<a href="/about-us">About Us</a>';

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: Relative href has been replaced with an absolute URL.
		$this->assertStringNotContainsString(
			'href="/about-us"',
			$processed,
			'Relative href should be replaced with an absolute URL'
		);
		$this->assertStringContainsString(
			'href="' . get_site_url() . '/about-us"',
			$processed,
			'Relative href should be rewritten to an absolute URL on the current site'
		);
	}

	/**
	 * Verifies that absolute source-site URLs are replaced with the current
	 * site URL.
	 *
	 * Links pointing to the external source site should be rewritten to point
	 * to the current site so that imported content links stay internal.
	 */
	public function test_process_content_replaces_source_site_urls_with_current_site_url(): void {
		// ARRANGE: Content with an absolute link to the source site.
		$source_site = 'https://source.example.com';
		$content     = '<a href="https://source.example.com/the-article">Read more</a>';
		$current_url = get_site_url();

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: Source site URL was replaced with the current site URL.
		$this->assertStringNotContainsString(
			'href="https://source.example.com/the-article"',
			$processed,
			'Source site URL should be replaced'
		);
		$this->assertStringContainsString(
			$current_url . '/the-article',
			$processed,
			'Link should now point to the current site'
		);
	}

	/**
	 * Verifies that iframe embeds are processed with WordPress security
	 * attributes and have their src preserved exactly.
	 *
	 * Iframes (e.g. YouTube embeds) should receive the wp-embedded-content
	 * class and security attributes such as loading="lazy", and the original
	 * src URL must not be altered.
	 */
	public function test_process_content_handles_iframe_embeds_with_security_attributes(): void {
		// ARRANGE: Classic HTML with a YouTube iframe embed.
		$source_site = 'https://source.example.com';
		$content     = '<iframe src="https://www.youtube.com/embed/abc123"></iframe>';

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: Iframe src is preserved unchanged and WordPress embed class
		// was applied.
		$this->assertStringContainsString(
			'src="https://www.youtube.com/embed/abc123"',
			$processed,
			'YouTube embed src should be preserved exactly'
		);
		$this->assertStringContainsString(
			'wp-embedded-content',
			$processed,
			'WordPress embed class should be added to the iframe'
		);

		// ASSERT: Security attributes were added by the embed processor.
		$this->assertStringContainsString(
			'loading="lazy"',
			$processed,
			'Lazy loading attribute should be present'
		);
	}

	/**
	 * Verifies that Gutenberg block content is processed via the block path,
	 * preserving block comment delimiters rather than stripping them.
	 *
	 * The admin Content_Processor detects block markers and routes to
	 * process_gutenberg_blocks() instead of the classic HTML processor.
	 * When no external media is present the block structure must be returned
	 * unchanged.
	 */
	public function test_process_gutenberg_content_preserves_block_structure(): void {
		// ARRANGE: Minimal Gutenberg block content with no external media.
		$source_site = 'https://source.example.com';
		$content     = '<!-- wp:paragraph --><p>Hello Gutenberg.</p><!-- /wp:paragraph -->';

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: Block comment delimiters are preserved (Gutenberg path was used).
		$this->assertStringContainsString(
			'<!-- wp:paragraph -->',
			$processed,
			'Opening block comment delimiter should be preserved by the Gutenberg processor'
		);
		$this->assertStringContainsString(
			'<!-- /wp:paragraph -->',
			$processed,
			'Closing block comment delimiter should be preserved by the Gutenberg processor'
		);

		// ASSERT: Content text is not lost.
		$this->assertStringContainsString(
			'Hello Gutenberg',
			$processed,
			'Block content text should be preserved'
		);
	}

	/**
	 * Verifies that Gutenberg block content containing source-site links is
	 * processed through the full parse-transform-serialize pipeline.
	 *
	 * When the source domain appears in the content, content_needs_media_processing()
	 * returns true, forcing blocks to be parsed and re-serialized rather than
	 * returned early unchanged. Source-site URLs in text blocks should be
	 * rewritten to point to the current site after serialization.
	 */
	public function test_process_gutenberg_content_rewrites_source_site_links_in_blocks(): void {
		// ARRANGE: Paragraph block with a link to the source site; the presence
		// of the source domain forces the full parse-process-serialize path.
		$source_site = 'https://source.example.com';
		$content     = '<!-- wp:paragraph --><p><a href="https://source.example.com/the-article">Read more</a></p><!-- /wp:paragraph -->';
		$current_url = get_site_url();

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: Block comment delimiters are preserved after serialization.
		$this->assertStringContainsString(
			'<!-- wp:paragraph -->',
			$processed,
			'Opening block comment delimiter should survive the full parse-serialize cycle'
		);
		$this->assertStringContainsString(
			'<!-- /wp:paragraph -->',
			$processed,
			'Closing block comment delimiter should survive the full parse-serialize cycle'
		);

		// ASSERT: Source-site link was rewritten to the current site.
		$this->assertStringContainsString(
			$current_url . '/the-article',
			$processed,
			'Source-site URL should be rewritten to the current site URL'
		);
		$this->assertStringNotContainsString(
			'source.example.com',
			$processed,
			'No remaining references to the source site should exist after processing'
		);
	}

	/**
	 * Verifies that a Gutenberg core/image block has its external URL imported
	 * and its attrs updated with the local URL and attachment ID.
	 *
	 * This exercises process_image_block() through the full Content_Processor
	 * pipeline, confirming that import_external_media_as_attachment() is used
	 * and the block attrs are correctly rewritten.
	 */
	public function test_process_gutenberg_image_block_imports_media_and_updates_attrs(): void {
		// ARRANGE: A core/image block referencing an external image on the source site.
		$source_site  = 'https://source.example.com';
		$external_url = 'https://source.example.com/photo.jpg';
		$content      = '<!-- wp:image {"url":"' . $external_url . '"} -->'
			. '<figure class="wp-block-image"><img src="' . $external_url . '" alt="A photo"/></figure>'
			. '<!-- /wp:image -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process the block content through the full Gutenberg path.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: Exactly one attachment was created.
		$this->assertSame( $attachments_before + 1, $this->get_attachment_count(), 'Should create exactly one attachment' );

		// ASSERT: The external URL no longer appears anywhere in the output.
		$this->assertStringNotContainsString( $external_url, $processed, 'External URL should be replaced' );

		// ASSERT: The local upload URL appears in the output.
		$this->assertStringContainsString( 'wp-content/uploads', $processed, 'Local upload URL should be present' );

		// ASSERT: No failure was recorded — the import succeeded.
		$this->assertSame( array(), $this->processor->get_failed_media(), 'No media failures should be recorded' );

		// ASSERT: The block attrs contain a local upload URL (not the external one).
		$this->assertMatchesRegularExpression(
			'/"url":"[^"]*wp-content\/uploads[^"]*"/',
			$processed,
			'Block attrs url should point to local uploads'
		);

		// ASSERT: The block attrs contain the attachment ID.
		$this->assertMatchesRegularExpression( '/"id":\d+/', $processed, 'Block attrs should contain a numeric attachment ID' );
	}
}
