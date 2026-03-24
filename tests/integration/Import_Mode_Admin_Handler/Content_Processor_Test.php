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

/**
 * Content Processor Integration Test Class.
 *
 * Tests the admin Content_Processor's HTML transformation pipeline.
 */
class Content_Processor_Test extends Integration_Test_Case {

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
	 * attributes.
	 *
	 * Iframes (e.g. YouTube embeds) should receive the wp-embedded-content
	 * class and security attributes such as loading="lazy".
	 */
	public function test_process_content_handles_iframe_embeds_with_security_attributes(): void {
		// ARRANGE: Classic HTML with a YouTube iframe embed.
		$source_site = 'https://source.example.com';
		$content     = '<iframe src="https://www.youtube.com/embed/abc123"></iframe>';

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: Iframe is preserved and WordPress embed class was applied.
		$this->assertStringContainsString(
			'youtube.com',
			$processed,
			'YouTube embed URL should be preserved'
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
			'wp:paragraph',
			$processed,
			'Block comment delimiters should be preserved by the Gutenberg processor'
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
			'wp:paragraph',
			$processed,
			'Block comment delimiters should survive the full parse-serialize cycle'
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
}
