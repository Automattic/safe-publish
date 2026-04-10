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
use WP_Error;

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
			'heic' => array( 'test-1x1.jpg', 'image/jpeg' ),
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
	 * Verifies that relative links are preserved exactly as-is after import.
	 *
	 * A relative href like /about-us means "within this site" and must not be
	 * converted to an absolute destination URL during import.
	 */
	public function test_process_content_preserves_relative_links(): void {
		// ARRANGE: Classic HTML with a root-relative anchor href.
		$source_site = 'https://source.example.com';
		$content     = '<a href="/about-us">About Us</a>';

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: Relative href is unchanged — not converted to an absolute URL.
		$this->assertStringContainsString(
			'href="/about-us"',
			$processed,
			'Relative href must be preserved exactly as-is after import'
		);
		$this->assertStringNotContainsString(
			'source.example.com',
			$processed,
			'Source domain must not appear in the preserved relative href'
		);
		$this->assertStringNotContainsString(
			'href="' . get_site_url() . '/about-us"',
			$processed,
			'Relative href must not be converted to an absolute destination URL'
		);
	}

	/**
	 * Verifies that absolute third-party links are left unchanged after import.
	 *
	 * Links pointing to external domains unrelated to the source site must
	 * not have their domain rewritten.
	 */
	public function test_process_content_preserves_third_party_links(): void {
		// ARRANGE: Content with a link to an unrelated third-party domain.
		$source_site = 'https://source.example.com';
		$third_party = 'https://third-party.example.org/page';
		$content     = '<a href="' . $third_party . '">Third party</a>';

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: Third-party href is unchanged.
		$this->assertStringContainsString(
			'href="' . $third_party . '"',
			$processed,
			'Third-party href must not be rewritten'
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

	/**
	 * Verifies that a Gutenberg image block referencing source-site media with
	 * a non-standard extension (.heic) is sideloaded correctly.
	 *
	 * The media pipeline must not be gated on a fixed extension allow-list;
	 * source-site media with any extension should be imported.
	 */
	public function test_process_source_site_image_with_non_standard_extension_imports_media(): void {
		// ARRANGE: A source-site image URL with .heic extension (absent from the
		// old extension allow-list and not a substring of any entry in it).
		$source_site = 'https://source.example.com';
		$image_url   = 'https://source.example.com/photo.heic';

		$content = '<!-- wp:image {"url":"' . $image_url . '"} -->'
			. '<figure class="wp-block-image">'
			. '<img src="' . $image_url . '" alt="HEIC photo"/>'
			. '</figure>'
			. '<!-- /wp:image -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process through the full Gutenberg path.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: Exactly one attachment was created.
		$this->assertSame(
			$attachments_before + 1,
			$this->get_attachment_count(),
			'Source-site media with a non-standard extension should be sideloaded'
		);

		// ASSERT: The source URL no longer appears in the output.
		$this->assertStringNotContainsString(
			$image_url,
			$processed,
			'Source URL should be replaced with the local upload URL'
		);

		// ASSERT: A local upload URL is present.
		$this->assertStringContainsString(
			'wp-content/uploads',
			$processed,
			'Local upload URL should be present in processed content'
		);

		// ASSERT: No import failures.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'No media failures should be recorded'
		);
	}

	/**
	 * Verifies that a third-party image URL in classic HTML content is left
	 * unchanged and no failure is recorded.
	 */
	public function test_process_classic_content_leaves_third_party_image_unchanged(): void {
		// ARRANGE: HTML with an img from an unrelated CDN (not source.example.com).
		$source_site   = 'https://source.example.com';
		$cdn_image_url = 'https://third-party.example.com/photo-123.jpg';
		$content       = '<p>Hello</p><img src="' . $cdn_image_url . '" alt="stock photo">';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process classic HTML content containing the third-party image.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: No attachment was created for the third-party image.
		$this->assertSame(
			$attachments_before,
			$this->get_attachment_count(),
			'Third-party image must not be sideloaded'
		);

		// ASSERT: The third-party URL is still present in the output.
		$this->assertStringContainsString(
			$cdn_image_url,
			$processed,
			'Third-party image URL should remain unchanged'
		);

		// ASSERT: No failure was recorded for the skipped URL.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'Skipped third-party media must not be recorded as a failure'
		);
	}

	/**
	 * Verifies that a third-party image URL in a Gutenberg core/image block
	 * is left unchanged and no failure is recorded.
	 */
	public function test_process_gutenberg_block_leaves_third_party_image_unchanged(): void {
		// ARRANGE: core/image block with a stock-photo CDN URL.
		$source_site   = 'https://source.example.com';
		$cdn_image_url = 'https://third-party.example.com/photo-456.jpg';
		$content       = '<!-- wp:image {"url":"' . $cdn_image_url . '"} -->'
			. '<figure class="wp-block-image"><img src="' . $cdn_image_url . '" alt="stock"/></figure>'
			. '<!-- /wp:image -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process the block content containing the third-party image URL.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: No attachment was created for the third-party image.
		$this->assertSame(
			$attachments_before,
			$this->get_attachment_count(),
			'Third-party image must not be sideloaded'
		);

		// ASSERT: The third-party URL is still present after processing.
		$this->assertStringContainsString(
			$cdn_image_url,
			$processed,
			'Third-party image URL should remain unchanged in block output'
		);

		// ASSERT: No failure was recorded for the skipped URL.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'Skipped third-party media must not be recorded as a failure'
		);
	}

	/**
	 * Verifies that source-domain media is sideloaded while third-party media
	 * in the same content is left unchanged and not recorded as a failure.
	 */
	public function test_process_classic_content_sideloads_source_domain_but_skips_third_party(): void {
		// ARRANGE: Content with one source-domain image and one third-party image.
		$source_site      = 'https://source.example.com';
		$source_image_url = 'https://source.example.com/image.jpg';
		$cdn_image_url    = 'https://third-party.example.com/photo-789.jpg';
		$content          = '<img src="' . $source_image_url . '" alt="local">'
			. '<img src="' . $cdn_image_url . '" alt="stock">';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process mixed content containing both image types.
		$processed = $this->processor->process_content( $content, $source_site );

		// ASSERT: Exactly one attachment was created (the source-domain image).
		$this->assertSame(
			$attachments_before + 1,
			$this->get_attachment_count(),
			'Only the source-domain image should be sideloaded'
		);

		// ASSERT: The source-domain URL was replaced with a local one.
		$this->assertStringNotContainsString(
			$source_image_url,
			$processed,
			'Source-domain URL should be replaced with the local upload URL'
		);
		$this->assertStringContainsString(
			'wp-content/uploads',
			$processed,
			'Local upload URL should be present'
		);

		// ASSERT: The third-party URL remains untouched.
		$this->assertStringContainsString(
			$cdn_image_url,
			$processed,
			'Third-party image URL should remain unchanged'
		);

		// ASSERT: No failures recorded.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'No media failures should be recorded'
		);
	}
}
