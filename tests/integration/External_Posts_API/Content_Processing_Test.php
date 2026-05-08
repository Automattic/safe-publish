<?php
/**
 * Content Processing Tests for External Posts API
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\External_Posts_API;

/**
 * Tests HTML content transformations and processing.
 *
 * Verifies that content is correctly transformed, URLs are converted, and
 * WordPress-specific classes and attributes are applied.
 */
class Content_Processing_Test extends External_Posts_API_Test_Base {

	/**
	 * Data provider for responsive image structures.
	 *
	 * @return array<string, array{content: string, expected_strings: string[], not_expected_strings: string[], description: string}>
	 */
	public static function responsive_image_structures_provider(): array {
		return array(
			'srcset_attributes' => array(
				'content'              => '<img src="https://example.com/image.jpg" srcset="https://example.com/image-300.jpg 300w, https://example.com/image-600.jpg 600w" alt="Responsive">',
				'expected_strings'     => array( '<img', 'srcset', 'Responsive', '300w', '600w' ),
				'not_expected_strings' => array( 'https://example.com/image-300.jpg', 'https://example.com/image-600.jpg' ),
				'description'          => 'Image with srcset attribute',
			),
			'figure_element'    => array(
				'content'              => '
					<figure class="wp-block-image">
						<img src="https://example.com/img.jpg" alt="Figure">
						<figcaption>Image caption</figcaption>
					</figure>
				',
				'expected_strings'     => array( 'figure', 'figcaption', 'Image caption', 'wp-block-image' ),
				'not_expected_strings' => array(),
				'description'          => 'Figure with figcaption',
			),
			'picture_element'   => array(
				'content'              => '
					<picture>
						<source srcset="https://example.com/img.webp" type="image/webp">
						<source srcset="https://example.com/img.jpg" type="image/jpeg">
						<img src="https://example.com/img.jpg" alt="Picture">
					</picture>
				',
				'expected_strings'     => array( 'picture', 'source', 'srcset', 'Picture' ),
				'not_expected_strings' => array( 'srcset="https://example.com/img.webp"', 'srcset="https://example.com/img.jpg"' ),
				'description'          => 'Picture with source elements',
			),
		);
	}

	/**
	 * Verifies that responsive image structures are processed correctly.
	 *
	 * @dataProvider responsive_image_structures_provider
	 *
	 * @param string   $content              Content to process.
	 * @param string[] $expected_strings     Strings expected in output.
	 * @param string[] $not_expected_strings Strings that must not appear in output.
	 * @param string   $description          Test case description.
	 */
	public function test_responsive_image_structures(
		string $content,
		array $expected_strings,
		array $not_expected_strings,
		string $description
	): void {
		// ARRANGE: Prepare content based on data provider.
		$source_site_url    = 'https://example.com';
		$attachments_before = $this->get_attachment_count();

		// ACT: Process content.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify expected elements are present.
		$this->assertIsString(
			$processed_content,
			"Content should be a string for: {$description}"
		);
		$this->assertNotSame(
			'',
			$processed_content,
			"Content should not be empty for: {$description}"
		);

		foreach ( $expected_strings as $expected ) {
			$this->assertStringContainsString( $expected, $processed_content, "Should contain '{$expected}' for: {$description}" );
		}

		foreach ( $not_expected_strings as $not_expected ) {
			$this->assertStringNotContainsString( $not_expected, $processed_content, "Should not contain '{$not_expected}' for: {$description}" );
		}

		// ASSERT: Verify structure is preserved (HTML elements intact).
		$this->assertStringContainsString( '<img', $processed_content, 'Should preserve img tags' );

		// ASSERT: Verify images were actually imported.
		$attachments_after = $this->get_attachment_count();
		$this->assertGreaterThan(
			$attachments_before,
			$attachments_after,
			"Should create attachments for images in: {$description}"
		);
	}

	/**
	 * Verifies that relative URLs are preserved as-is during content processing.
	 *
	 * Relative links (root-relative and path-relative) must not be converted
	 * to absolute URLs — their meaning is "within this site" and is preserved
	 * unchanged on the destination.
	 */
	public function test_relative_urls_preserved_as_is(): void {
		// ARRANGE: Create content with multiple relative URL patterns.
		$source_site_url = 'https://example.com';
		$content         = '
			<div>
				<a href="/root-relative">Root</a>
				<a href="relative-path">Relative</a>
				<a href="https://external.com/page">Already Absolute</a>
			</div>
		';

		// ACT: Process content.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify root-relative URL is preserved as-is.
		$this->assertStringContainsString( 'href="/root-relative"', $processed_content );
		$this->assertStringNotContainsString( 'https://example.com/root-relative', $processed_content );

		// ASSERT: Verify relative path is preserved as-is.
		$this->assertStringContainsString( 'href="relative-path"', $processed_content );
		$this->assertStringNotContainsString( 'https://example.com/relative-path', $processed_content );

		// ASSERT: Verify already-absolute URL unchanged.
		$this->assertStringContainsString( 'https://external.com/page', $processed_content );
	}

	/**
	 * Data provider for media elements tests.
	 *
	 * @return array<string, array{element: string, url: string}>
	 */
	public static function media_elements_provider(): array {
		return array(
			'video' => array(
				'element' => 'video',
				'url'     => 'https://example.com/video.mp4',
			),
			'audio' => array(
				'element' => 'audio',
				'url'     => 'https://example.com/audio.mp3',
			),
		);
	}

	/**
	 * Verifies that media elements are preserved without adding extra
	 * attributes or classes.
	 *
	 * @dataProvider media_elements_provider
	 *
	 * @param string $element Media element type (video or audio).
	 * @param string $url     Media URL.
	 */
	public function test_media_elements_preserved_without_extra_attributes(
		string $element,
		string $url
	): void {
		// ARRANGE: Create media element from data provider.
		$source_site_url = 'https://example.com';
		$content         = sprintf(
			'<%s src="%s" controls></%s>',
			$element,
			$url,
			$element
		);

		// ACT: Process content.
		$processed_content = $this->content_media_processor->process_content(
			$content,
			$source_site_url
		);

		// ASSERT: Verify source controls attribute is preserved.
		$this->assertStringContainsString(
			'controls',
			$processed_content
		);

		// ASSERT: Verify no extra classes were injected.
		$this->assertStringNotContainsString(
			'wp-video-shortcode',
			$processed_content
		);
		$this->assertStringNotContainsString(
			'wp-audio-shortcode',
			$processed_content
		);

		// ASSERT: Verify no extra attributes were injected.
		$this->assertStringNotContainsString(
			'preload="metadata"',
			$processed_content
		);
	}

	/**
	 * Verifies that iframes are preserved without adding extra attributes.
	 */
	public function test_iframe_embeds_preserved_without_extra_attributes(): void {
		// ARRANGE: Create content with YouTube iframe embed.
		$source_site_url = 'https://example.com';
		$content         = '<iframe src="https://www.youtube.com/embed/abc123"></iframe>';

		// ACT: Process content.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify iframe src is preserved.
		$this->assertStringContainsString( 'youtube.com', $processed_content );

		// ASSERT: Verify no extra attributes or classes were injected.
		$this->assertStringNotContainsString( 'wp-embedded-content', $processed_content );
		$this->assertStringNotContainsString( 'loading="lazy"', $processed_content );
		$this->assertStringNotContainsString( 'referrerpolicy=', $processed_content );
	}

	/**
	 * Verifies that complex content with mixed media types processes correctly.
	 *
	 * Tests that the processor handles multiple different element types correctly
	 * without crashes or data loss, applying proper HTML transformations.
	 */
	public function test_complex_mixed_media_content_processes_correctly(): void {
		// ARRANGE: Create complex content with multiple media types.
		$source_site_url    = 'https://example.com';
		$attachments_before = $this->get_attachment_count();
		$content            = '
			<div>
				<h2>Article with Media</h2>
				<img src="https://example.com/header.jpg" alt="Header">
				<p>Some <a href="/internal-link">text</a> content.</p>
				<video src="https://example.com/clip.mp4" controls></video>
				<audio src="https://example.com/podcast.mp3" controls></audio>
				<iframe src="https://www.youtube.com/embed/xyz"></iframe>
				<img src="https://example.com/footer.jpg" alt="Footer">
			</div>
		';

		// ACT: Process complex content.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify all elements are present and transformed.
		$this->assertIsString( $processed_content );
		$this->assertNotSame( '', $processed_content );
		$this->assertStringContainsString( 'Article with Media', $processed_content );
		$this->assertStringContainsString( 'Header', $processed_content );
		$this->assertStringContainsString( 'Footer', $processed_content );

		// ASSERT: Verify relative link is preserved as-is.
		$this->assertStringContainsString( 'href="/internal-link"', $processed_content );
		$this->assertStringNotContainsString( 'href="https://example.com/internal-link"', $processed_content );

		// ASSERT: Verify no extra classes were injected on media elements.
		$this->assertStringNotContainsString( 'wp-video-shortcode', $processed_content );
		$this->assertStringNotContainsString( 'wp-audio-shortcode', $processed_content );

		// ASSERT: Verify images were actually imported (2 images: header + footer).
		$attachments_after = $this->get_attachment_count();
		$this->assertSame(
			$attachments_before + 2,
			$attachments_after,
			'Should create 2 attachments for header and footer images'
		);
	}

	/**
	 * Verifies that data URLs are not processed as external media.
	 *
	 * Data URIs should be left untouched, not treated as downloadable media.
	 */
	public function test_data_urls_not_processed_as_media(): void {
		// ARRANGE: Create content with data URL.
		$source_site_url = 'https://example.com';
		$data_url        = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
		$content         = sprintf( '<img src="%s" alt="Data URL">', $data_url );

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content with data URL.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify data URL is preserved (not processed as external media).
		$this->assertStringContainsString( 'data:image/png;base64', $processed_content );
		$this->assertStringContainsString( 'alt="Data URL"', $processed_content );

		// ASSERT: Verify no attachment was created.
		$this->assert_no_new_attachments( $attachments_before );
	}

	/**
	 * Data provider for URL edge cases.
	 *
	 * @return array<string, array{content: string, expected_strings: string[], description: string}>
	 */
	public static function url_edge_cases_provider(): array {
		return array(
			'query_parameters'   => array(
				'content'          => '
					<img src="https://example.com/image.jpg?v=123&w=800" alt="With Query">
					<a href="/page?id=456">Link with query</a>
				',
				'expected_strings' => array(
					'With Query',
					'href="/page?id=456"',
				),
				'description'      => 'URLs with query parameters',
			),
			'protocol_relative'  => array(
				'content'          => '<img src="//cdn.example.com/image.jpg" alt="CDN Image">',
				'expected_strings' => array(
					// Protocol-relative URLs are imported since domain filtering is disabled.
					'wp-content/uploads',
					'CDN Image',
				),
				'description'      => 'Protocol-relative URLs',
			),
			'special_characters' => array(
				'content'          => '<img src="https://example.com/image%20with%20spaces.jpg" alt="Special"><a href="/page#section">Fragment</a>',
				'expected_strings' => array(
					'Special',
					'href="/page#section"',
				),
				'description'      => 'URLs with special characters and fragments',
			),
		);
	}

	/**
	 * Verifies that URL edge cases are handled correctly.
	 *
	 * @dataProvider url_edge_cases_provider
	 *
	 * @param string   $content          Content to process.
	 * @param string[] $expected_strings Strings expected in output.
	 * @param string   $description      Test case description.
	 */
	public function test_url_edge_cases( string $content, array $expected_strings, string $description ): void {
		// ARRANGE: Prepare content from data provider.
		$source_site_url = 'https://example.com';

		// ACT: Process content.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify expected strings present.
		foreach ( $expected_strings as $expected ) {
			$this->assertStringContainsString( $expected, $processed_content, "Should contain '{$expected}' for: {$description}" );
		}
	}
}
