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
		$content_media_processor = new Content_Media_Processor( $media_importer );

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
	public function mock_http_request(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
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
		$source_site_url = 'https://source.example.com';
		$content         = '<a href="/about-us">About Us</a>';

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site_url );

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
		$source_site_url = 'https://source.example.com';
		$third_party     = 'https://third-party.example.org/page';
		$content         = '<a href="' . $third_party . '">Third party</a>';

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site_url );

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
		$source_site_url = 'https://source.example.com';
		$content         = '<a href="https://source.example.com/the-article">Read more</a>';
		$current_url     = get_site_url();

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site_url );

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
	 * Verifies that iframe embeds have their src preserved exactly without
	 * adding extra attributes or classes.
	 */
	public function test_process_content_preserves_iframe_embeds_without_extra_attributes(): void {
		// ARRANGE: Classic HTML with a YouTube iframe embed.
		$source_site_url = 'https://source.example.com';
		$content         = '<iframe src="https://www.youtube.com/embed/abc123"></iframe>';

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site_url );

		// ASSERT: Iframe src is preserved unchanged.
		$this->assertStringContainsString(
			'src="https://www.youtube.com/embed/abc123"',
			$processed,
			'YouTube embed src should be preserved exactly'
		);

		// ASSERT: No extra attributes or classes were injected.
		$this->assertStringNotContainsString(
			'wp-embedded-content',
			$processed,
			'No extra classes should be added to the iframe'
		);
		$this->assertStringNotContainsString(
			'loading="lazy"',
			$processed,
			'No extra attributes should be added to the iframe'
		);
	}

	/**
	 * Verifies that URL replacement does not alter surrounding markup.
	 *
	 * Self-closing tags, whitespace, entities, and SVG attributes must survive
	 * the replacement pass unchanged.
	 */
	public function test_replace_external_urls_preserves_markup(): void {
		// ARRANGE: Content with markup that DOMDocument would alter,
		// plus a source-site link to trigger replacement.
		$source_site_url = 'https://source.example.com';
		$current_url     = get_site_url();
		$content         = '<p>Hello &amp; world</p>'
			. '<img src="/local.jpg" alt="test"/>'
			. '<br/>'
			. '<a href="https://source.example.com/page">Link</a>';

		// ACT: Call replace_external_urls() directly.
		$processed = $this->processor->replace_external_urls(
			$content,
			$source_site_url
		);

		// ASSERT: Link was replaced.
		$this->assertStringContainsString(
			'href="' . $current_url . '/page"',
			$processed,
			'Source URL should be replaced'
		);

		// ASSERT: Self-closing tags are preserved.
		$this->assertStringContainsString(
			'alt="test"/>',
			$processed,
			'Self-closing img tag must not be altered'
		);
		$this->assertStringContainsString(
			'<br/>',
			$processed,
			'Self-closing br tag must not be altered'
		);

		// ASSERT: Entity encoding is preserved.
		$this->assertStringContainsString(
			'&amp;',
			$processed,
			'HTML entities must not be decoded'
		);
	}

	/**
	 * Verifies that legacy http:// URLs are replaced when the source site
	 * uses https://.
	 */
	public function test_replace_external_urls_replaces_http_variant(): void {
		// ARRANGE: Source site is HTTPS, but content has a legacy HTTP link.
		$source_site_url = 'https://source.example.com';
		$current_url     = get_site_url();
		$content         = '<a href="http://source.example.com/old-page">Old link</a>';

		// ACT: Call replace_external_urls() directly.
		$processed = $this->processor->replace_external_urls(
			$content,
			$source_site_url
		);

		// ASSERT: The HTTP URL was replaced with the current site URL.
		$this->assertStringContainsString(
			'href="' . $current_url . '/old-page"',
			$processed,
			'Legacy http:// URL must be replaced with the current site URL'
		);
		$this->assertStringNotContainsString(
			'source.example.com',
			$processed,
			'Source domain must not remain in the output'
		);
	}

	/**
	 * Verifies that a domain that starts with the source domain but continues
	 * with more characters is not replaced.
	 */
	public function test_replace_external_urls_does_not_replace_longer_domain(): void {
		// ARRANGE: Content with a URL to a longer domain that starts with the
		// source domain string.
		$source_site_url = 'https://source.example.com';
		$longer_url      = 'https://source.example.company.com/page';
		$content         = '<a href="' . $longer_url . '">Link</a>';

		// ACT: Call replace_external_urls() directly.
		$processed = $this->processor->replace_external_urls(
			$content,
			$source_site_url
		);

		// ASSERT: The longer domain URL is unchanged.
		$this->assertStringContainsString(
			'href="' . $longer_url . '"',
			$processed,
			'URL with a longer domain must not be rewritten'
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
		$source_site_url = 'https://source.example.com';
		$content         = '<!-- wp:paragraph --><p>Hello Gutenberg.</p><!-- /wp:paragraph -->';

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site_url );

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
		$source_site_url = 'https://source.example.com';
		$content         = '<!-- wp:paragraph --><p><a href="https://source.example.com/the-article">Read more</a></p><!-- /wp:paragraph -->';
		$current_url     = get_site_url();

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content( $content, $source_site_url );

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
		$source_site_url = 'https://source.example.com';
		$external_url    = 'https://source.example.com/photo.jpg';
		$content         = '<!-- wp:image {"url":"' . $external_url . '"} -->'
			. '<figure class="wp-block-image"><img src="' . $external_url . '" alt="A photo"/></figure>'
			. '<!-- /wp:image -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process the block content through the full Gutenberg path.
		$processed = $this->processor->process_content( $content, $source_site_url );

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
	 * Verifies that a Gutenberg image block with "Link to Media File" sideloads
	 * both the thumbnail in <img src> and the full-size file in <a href> when
	 * the two URLs differ.
	 */
	public function test_process_image_block_with_link_to_media_sideloads_anchor_href(): void {
		// ARRANGE: A core/image block where <img src> is a thumbnail and
		// <a href> is the full-size image (linkDestination: media).
		$source_site_url = 'https://source.example.com';
		$thumbnail_url   = 'https://source.example.com/photo-300x200.jpg';
		$fullsize_url    = 'https://source.example.com/photo.jpg';
		$content         = '<!-- wp:image {"url":"' . $thumbnail_url . '","linkDestination":"media"} -->'
			. '<figure class="wp-block-image">'
			. '<a href="' . $fullsize_url . '">'
			. '<img src="' . $thumbnail_url . '" alt="A photo"/>'
			. '</a></figure>'
			. '<!-- /wp:image -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process the block content through the full Gutenberg path.
		$processed = $this->processor->process_content( $content, $source_site_url );

		// ASSERT: Two attachments were created (thumbnail + full-size).
		$this->assertSame(
			$attachments_before + 2,
			$this->get_attachment_count(),
			'Should create two attachments'
		);

		// ASSERT: Neither external URL appears in the output.
		$this->assertStringNotContainsString(
			$thumbnail_url,
			$processed,
			'Thumbnail URL should be replaced'
		);
		$this->assertStringNotContainsString(
			$fullsize_url,
			$processed,
			'Full-size URL should be replaced'
		);

		// ASSERT: The source domain no longer appears anywhere.
		$this->assertStringNotContainsString(
			'source.example.com',
			$processed,
			'Source domain should not remain'
		);

		// ASSERT: No failure was recorded.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'No media failures should be recorded'
		);
	}

	/**
	 * Verifies that a source-domain anchor href is sideloaded even when the
	 * primary <img src> belongs to a third-party domain and is therefore
	 * skipped by the importer.
	 */
	public function test_process_image_block_with_third_party_src_sideloads_anchor_href(): void {
		// ARRANGE: An image block whose <img src> is a third-party CDN URL,
		// wrapped in an <a href> pointing at a source-hosted file.
		$source_site_url = 'https://source.example.com';
		$cdn_url         = 'https://cdn.example.com/photo-300x200.jpg';
		$href_url        = 'https://source.example.com/photo.jpg';
		$content         = '<!-- wp:image {"url":"' . $cdn_url . '","linkDestination":"media"} -->'
			. '<figure class="wp-block-image">'
			. '<a href="' . $href_url . '">'
			. '<img src="' . $cdn_url . '" alt="A photo"/>'
			. '</a></figure>'
			. '<!-- /wp:image -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process the block content through the full Gutenberg path.
		$processed = $this->processor->process_content( $content, $source_site_url );

		// ASSERT: One attachment was created (from the anchor href only).
		$this->assertSame(
			$attachments_before + 1,
			$this->get_attachment_count(),
			'Anchor href should be sideloaded even when <img src> is third-party'
		);

		// ASSERT: The third-party CDN URL is left intact.
		$this->assertStringContainsString(
			$cdn_url,
			$processed,
			'Third-party <img src> should be left unchanged'
		);

		// ASSERT: The source-domain href no longer appears.
		$this->assertStringNotContainsString(
			$href_url,
			$processed,
			'Source-domain anchor href should be replaced'
		);

		// ASSERT: No failure was recorded.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'No media failures should be recorded'
		);
	}

	/**
	 * Verifies that an anchor href is still sideloaded when the primary
	 * <img src> import fails — failure on the src must not skip the
	 * innerHTML anchor pass.
	 */
	public function test_process_image_block_with_failed_src_still_sideloads_anchor_href(): void {
		// ARRANGE: A source-domain <img src> forced to fail, plus a separate
		// source-domain <a href> that should still be sideloaded.
		$source_site_url = 'https://source.example.com';
		$broken_url      = 'https://source.example.com/broken-photo.jpg';
		$href_url        = 'https://source.example.com/photo.jpg';
		$content         = '<!-- wp:image {"url":"' . $broken_url . '","linkDestination":"media"} -->'
			. '<figure class="wp-block-image">'
			. '<a href="' . $href_url . '">'
			. '<img src="' . $broken_url . '" alt="A photo"/>'
			. '</a></figure>'
			. '<!-- /wp:image -->';

		$force_failure = static function (
			$preempt,
			array $args,
			string $url
		) use ( $broken_url ) {
			unset( $args );
			if ( $url === $broken_url ) {
				return new WP_Error(
					'http_request_failed',
					'forced failure for test'
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $force_failure, 5, 3 );

		$attachments_before = $this->get_attachment_count();

		try {
			// ACT: Process the block content.
			$processed = $this->processor->process_content( $content, $source_site_url );
		} finally {
			remove_filter( 'pre_http_request', $force_failure, 5 );
		}

		// ASSERT: One attachment was created (from the anchor href only).
		$this->assertSame(
			$attachments_before + 1,
			$this->get_attachment_count(),
			'Anchor href should be sideloaded even when <img src> import fails'
		);

		// ASSERT: Neither source URL appears verbatim in the output.
		$this->assertStringNotContainsString(
			$href_url,
			$processed,
			'Source-domain anchor href should be replaced'
		);
		$this->assertStringNotContainsString(
			$broken_url,
			$processed,
			'Failing src URL host should still be rewritten by replace_external_urls'
		);

		// ASSERT: The failing src URL is recorded as a failure.
		$this->assertSame(
			array( $broken_url ),
			$this->processor->get_failed_media(),
			'Failing <img src> should be recorded in failed_media'
		);
	}

	/**
	 * Verifies that a source-domain anchor href is sideloaded even when a
	 * core/video block's primary src belongs to a third-party domain and is
	 * therefore skipped by the importer.
	 */
	public function test_process_video_block_with_third_party_src_sideloads_anchor_href(): void {
		// ARRANGE: A video block whose src is a third-party CDN URL, with a
		// source-domain <a href> in innerHTML pointing at a related file.
		$source_site_url = 'https://source.example.com';
		$cdn_url         = 'https://cdn.example.com/video.mp4';
		$href_url        = 'https://source.example.com/poster.jpg';
		$content         = '<!-- wp:video {"src":"' . $cdn_url . '"} -->'
			. '<figure class="wp-block-video">'
			. '<a href="' . $href_url . '">Poster</a>'
			. '<video controls src="' . $cdn_url . '"></video>'
			. '</figure>'
			. '<!-- /wp:video -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process the block content through the full Gutenberg path.
		$processed = $this->processor->process_content( $content, $source_site_url );

		// ASSERT: One attachment was created (from the anchor href only).
		$this->assertSame(
			$attachments_before + 1,
			$this->get_attachment_count(),
			'Anchor href should be sideloaded even when video src is third-party'
		);

		// ASSERT: The third-party CDN URL is left intact.
		$this->assertStringContainsString(
			$cdn_url,
			$processed,
			'Third-party video src should be left unchanged'
		);

		// ASSERT: The source-domain href no longer appears.
		$this->assertStringNotContainsString(
			$href_url,
			$processed,
			'Source-domain anchor href should be replaced'
		);

		// ASSERT: No failure was recorded.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'No media failures should be recorded'
		);
	}

	/**
	 * Verifies that a source-domain anchor href in an image block's innerHTML
	 * is sideloaded even when the block carries no primary image URL at all
	 * (no attrs.url and no <img src>).
	 */
	public function test_process_image_block_with_empty_url_still_sideloads_anchor_href(): void {
		// ARRANGE: An image block with no <img> and no attrs.url, only a
		// source-domain <a href> in innerHTML.
		$source_site_url = 'https://source.example.com';
		$href_url        = 'https://source.example.com/file.jpg';
		$content         = '<!-- wp:image -->'
			. '<figure class="wp-block-image">'
			. '<a href="' . $href_url . '">Download</a>'
			. '</figure>'
			. '<!-- /wp:image -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process the block content.
		$processed = $this->processor->process_content( $content, $source_site_url );

		// ASSERT: One attachment was created (from the anchor href).
		$this->assertSame(
			$attachments_before + 1,
			$this->get_attachment_count(),
			'Anchor href should be sideloaded even when the block has no primary URL'
		);

		// ASSERT: The source-domain href no longer appears.
		$this->assertStringNotContainsString(
			$href_url,
			$processed,
			'Source-domain anchor href should be replaced'
		);

		// ASSERT: No failure was recorded.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'No media failures should be recorded'
		);
	}

	/**
	 * Verifies that a source-domain anchor href in a core/video block's
	 * innerHTML is sideloaded even when the block carries no attrs.src.
	 */
	public function test_process_video_block_with_empty_src_still_sideloads_anchor_href(): void {
		// ARRANGE: A video block with no attrs.src, only a source-domain
		// <a href> in innerHTML.
		$source_site_url = 'https://source.example.com';
		$href_url        = 'https://source.example.com/file.jpg';
		$content         = '<!-- wp:video -->'
			. '<figure class="wp-block-video">'
			. '<a href="' . $href_url . '">Download</a>'
			. '</figure>'
			. '<!-- /wp:video -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process the block content.
		$processed = $this->processor->process_content( $content, $source_site_url );

		// ASSERT: One attachment was created (from the anchor href).
		$this->assertSame(
			$attachments_before + 1,
			$this->get_attachment_count(),
			'Anchor href should be sideloaded even when video block has no src'
		);

		// ASSERT: The source-domain href no longer appears.
		$this->assertStringNotContainsString(
			$href_url,
			$processed,
			'Source-domain anchor href should be replaced'
		);

		// ASSERT: No failure was recorded.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'No media failures should be recorded'
		);
	}

	/**
	 * Verifies that an anchor href is still sideloaded when a video block's
	 * primary src download fails — failure on the src must not skip the
	 * innerHTML anchor pass.
	 */
	public function test_process_video_block_with_failed_src_still_sideloads_anchor_href(): void {
		// ARRANGE: A source-domain video src forced to fail, plus a separate
		// source-domain <a href> that should still be sideloaded.
		$source_site_url = 'https://source.example.com';
		$broken_url      = 'https://source.example.com/broken-video.mp4';
		$href_url        = 'https://source.example.com/poster.jpg';
		$content         = '<!-- wp:video {"src":"' . $broken_url . '"} -->'
			. '<figure class="wp-block-video">'
			. '<a href="' . $href_url . '">Poster</a>'
			. '<video controls src="' . $broken_url . '"></video>'
			. '</figure>'
			. '<!-- /wp:video -->';

		$force_failure = static function (
			$preempt,
			array $args,
			string $url
		) use ( $broken_url ) {
			unset( $args );
			if ( $url === $broken_url ) {
				return new WP_Error(
					'http_request_failed',
					'forced failure for test'
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $force_failure, 5, 3 );

		$attachments_before = $this->get_attachment_count();

		try {
			// ACT: Process the block content.
			$processed = $this->processor->process_content( $content, $source_site_url );
		} finally {
			remove_filter( 'pre_http_request', $force_failure, 5 );
		}

		// ASSERT: One attachment was created (from the anchor href only).
		$this->assertSame(
			$attachments_before + 1,
			$this->get_attachment_count(),
			'Anchor href should be sideloaded even when video src import fails'
		);

		// ASSERT: The href URL no longer appears in the output.
		$this->assertStringNotContainsString(
			$href_url,
			$processed,
			'Source-domain anchor href should be replaced'
		);

		// ASSERT: The failing src URL is recorded as a failure.
		$this->assertSame(
			array( $broken_url ),
			$this->processor->get_failed_media(),
			'Failing video src should be recorded in failed_media'
		);
	}

	/**
	 * Verifies that an anchor href in the outer wrapper of a block-based
	 * gallery (innerBlocks only, no attrs.images) is sideloaded via the
	 * tail-call helper at the gallery level.
	 */
	public function test_process_gallery_block_with_inner_blocks_sideloads_outer_anchor_href(): void {
		// ARRANGE: A block-based gallery whose outer innerHTML contains a
		// standalone source-domain <a href>, alongside a child image block.
		$source_site_url = 'https://source.example.com';
		$image_url       = 'https://source.example.com/photo.jpg';
		$href_url        = 'https://source.example.com/download.jpg';
		$content         = '<!-- wp:gallery {"linkTo":"none"} -->'
			. '<figure class="wp-block-gallery has-nested-images">'
			. '<a href="' . $href_url . '">Download all</a>'
			. '<!-- wp:image {"id":1} -->'
			. '<figure class="wp-block-image">'
			. '<img src="' . $image_url . '" alt=""/>'
			. '</figure>'
			. '<!-- /wp:image -->'
			. '</figure>'
			. '<!-- /wp:gallery -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process the block content.
		$processed = $this->processor->process_content( $content, $source_site_url );

		// ASSERT: Two attachments were created — the inner image and the
		// gallery wrapper href.
		$this->assertSame(
			$attachments_before + 2,
			$this->get_attachment_count(),
			'Inner image and outer anchor href should both be sideloaded'
		);

		// ASSERT: Neither source URL appears in the output.
		$this->assertStringNotContainsString(
			$image_url,
			$processed,
			'Inner image URL should be replaced'
		);
		$this->assertStringNotContainsString(
			$href_url,
			$processed,
			'Outer anchor href should be replaced'
		);

		// ASSERT: No failure was recorded.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'No media failures should be recorded'
		);
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
		$source_site_url = 'https://source.example.com';
		$image_url       = 'https://source.example.com/photo.heic';

		$content = '<!-- wp:image {"url":"' . $image_url . '"} -->'
			. '<figure class="wp-block-image">'
			. '<img src="' . $image_url . '" alt="HEIC photo"/>'
			. '</figure>'
			. '<!-- /wp:image -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process through the full Gutenberg path.
		$processed = $this->processor->process_content( $content, $source_site_url );

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
		$source_site_url = 'https://source.example.com';
		$cdn_image_url   = 'https://third-party.example.com/photo-123.jpg';
		$content         = '<p>Hello</p><img src="' . $cdn_image_url . '" alt="stock photo">';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process classic HTML content containing the third-party image.
		$processed = $this->processor->process_content( $content, $source_site_url );

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
		$source_site_url = 'https://source.example.com';
		$cdn_image_url   = 'https://third-party.example.com/photo-456.jpg';
		$content         = '<!-- wp:image {"url":"' . $cdn_image_url . '"} -->'
			. '<figure class="wp-block-image"><img src="' . $cdn_image_url . '" alt="stock"/></figure>'
			. '<!-- /wp:image -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process the block content containing the third-party image URL.
		$processed = $this->processor->process_content( $content, $source_site_url );

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
	 * Verifies that a custom block storing media exclusively in attrs (not in
	 * innerHTML) has its source-domain URL sideloaded and the attr replaced.
	 *
	 * This exercises the generic replace_urls_in_attrs() path in the default
	 * switch case, covering third-party block types that use arbitrary attr
	 * keys such as backgroundUrl, poster, src, etc.
	 */
	public function test_process_custom_block_imports_media_from_attrs(): void {
		// ARRANGE: A custom block storing media only in attrs, not in innerHTML.
		$source_site_url = 'https://source.example.com';
		$external_url    = 'https://source.example.com/hero-bg.jpg';
		$content         = '<!-- wp:my-plugin/hero {"backgroundUrl":"' . $external_url . '"} -->'
			. '<div class="wp-block-my-plugin-hero"></div>'
			. '<!-- /wp:my-plugin/hero -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process through the full Gutenberg path.
		$processed = $this->processor->process_content( $content, $source_site_url );

		// ASSERT: Exactly one attachment was created for the attrs media URL.
		$this->assertSame(
			$attachments_before + 1,
			$this->get_attachment_count(),
			'Custom block attrs media should be sideloaded as an attachment'
		);

		// ASSERT: The external URL no longer appears anywhere in the output.
		$this->assertStringNotContainsString(
			$external_url,
			$processed,
			'External URL in custom block attrs should be replaced with the local URL'
		);

		// ASSERT: A local upload URL appears in the serialized block comment.
		$this->assertStringContainsString(
			'wp-content/uploads',
			$processed,
			'Local upload URL should appear in the processed output'
		);

		// ASSERT: No failures recorded.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'No media failures should be recorded for a successful sideload'
		);
	}

	/**
	 * Verifies that classic content with an [embed] shortcode preserves it
	 * as-is instead of pre-rendering it into HTML.
	 *
	 * WordPress handles [embed] shortcodes at display time via the_content
	 * filters. Pre-rendering them during import would alter the stored
	 * content compared to the source database.
	 */
	public function test_process_classic_content_preserves_embed_shortcodes(): void {
		// ARRANGE: Classic content with an [embed] shortcode.
		$source_site_url = 'https://source.example.com';
		$embed_url       = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
		$content         = '<p>Watch this:</p>' . "\n"
			. '[embed]' . $embed_url . '[/embed]';

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content(
			$content,
			$source_site_url
		);

		// ASSERT: The [embed] shortcode is preserved in the output.
		$this->assertStringContainsString(
			'[embed]' . $embed_url . '[/embed]',
			$processed,
			'[embed] shortcode must be preserved, not pre-rendered'
		);

		// ASSERT: No iframe was generated (shortcode was not executed).
		$this->assertStringNotContainsString(
			'<iframe',
			$processed,
			'[embed] shortcode must not be converted to an iframe'
		);
	}

	/**
	 * Verifies that classic content with a bare oEmbed provider URL on its
	 * own line preserves it as-is instead of converting it to embed HTML.
	 *
	 * WordPress' autoembed runs at display time via the_content filters.
	 * Converting bare URLs during import would alter the stored content
	 * compared to the source database.
	 */
	public function test_process_classic_content_preserves_bare_oembed_urls(): void {
		// ARRANGE: Classic content with a bare YouTube URL on its own line.
		$source_site_url = 'https://source.example.com';
		$video_url       = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
		$content         = '<p>Check out this video:</p>' . "\n"
			. $video_url;

		// ACT: Process content against the source site.
		$processed = $this->processor->process_content(
			$content,
			$source_site_url
		);

		// ASSERT: The bare URL is preserved in the output.
		$this->assertStringContainsString(
			$video_url,
			$processed,
			'Bare oEmbed URL must be preserved, not pre-rendered'
		);

		// ASSERT: No iframe or [embed] wrapper was generated.
		$this->assertStringNotContainsString(
			'<iframe',
			$processed,
			'Bare URL must not be converted to an iframe'
		);
		$this->assertStringNotContainsString(
			'[embed]',
			$processed,
			'Bare URL must not be wrapped in [embed] shortcode'
		);
	}

	/**
	 * Verifies that a third-party URL stored in a custom block's attrs is left
	 * unchanged and not recorded as a failure.
	 *
	 * The replace_urls_in_attrs() method must skip URLs whose domain does not
	 * match the source site (import_external_media_as_attachment returns null),
	 * without treating this as a sideload failure.
	 */
	public function test_process_custom_block_skips_third_party_url_in_attrs(): void {
		// ARRANGE: A custom block with a third-party CDN URL in attrs only.
		$source_site_url = 'https://source.example.com';
		$cdn_image_url   = 'https://third-party.example.com/banner.jpg';
		$content         = '<!-- wp:my-plugin/hero {"backgroundUrl":"' . $cdn_image_url . '"} -->'
			. '<div class="wp-block-my-plugin-hero"></div>'
			. '<!-- /wp:my-plugin/hero -->';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process through the full Gutenberg path.
		$processed = $this->processor->process_content( $content, $source_site_url );

		// ASSERT: No attachment was created for the third-party URL.
		$this->assertSame(
			$attachments_before,
			$this->get_attachment_count(),
			'Third-party URL in custom block attrs must not be sideloaded'
		);

		// ASSERT: The third-party URL is unchanged in the output.
		$this->assertStringContainsString(
			$cdn_image_url,
			$processed,
			'Third-party URL in custom block attrs should remain unchanged'
		);

		// ASSERT: No failure was recorded for the skipped URL.
		$this->assertSame(
			array(),
			$this->processor->get_failed_media(),
			'Skipped third-party URL in attrs must not be recorded as a failure'
		);
	}
}
