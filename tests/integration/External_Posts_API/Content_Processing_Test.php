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
	 * @return array<string, array{content: string, expected_strings: string[], description: string}>
	 */
	public static function responsive_image_structures_provider(): array {
		// TODO: There's probably a bug with replacement of srcset that needs to
		// be looked into, as well as <picture> - <source> support.

		return array(
			'srcset_attributes' => array(
				'content'          => '<img src="https://example.com/image.jpg" srcset="https://example.com/image-300.jpg 300w, https://example.com/image-600.jpg 600w" alt="Responsive">',
				'expected_strings' => array( 'img', 'srcset', 'Responsive' ),
				'description'      => 'Image with srcset attribute',
			),
			'figure_element'    => array(
				'content'          => '
					<figure class="wp-block-image">
						<img src="https://example.com/img.jpg" alt="Figure">
						<figcaption>Image caption</figcaption>
					</figure>
				',
				'expected_strings' => array( 'figure', 'figcaption', 'Image caption', 'wp-block-image' ),
				'description'      => 'Figure with figcaption',
			),
			'picture_element'   => array(
				'content'          => '
					<picture>
						<source srcset="https://example.com/img.webp" type="image/webp">
						<source srcset="https://example.com/img.jpg" type="image/jpeg">
						<img src="https://example.com/img.jpg" alt="Picture">
					</picture>
				',
				'expected_strings' => array( 'picture', 'source', 'srcset', 'Picture' ),
				'description'      => 'Picture with source elements',
			),
		);
	}

	/**
	 * Verifies that responsive image structures are processed correctly.
	 *
	 * @dataProvider responsive_image_structures_provider
	 *
	 * @param string   $content          Content to process.
	 * @param string[] $expected_strings Strings expected in output.
	 * @param string   $description      Test case description.
	 */
	public function test_responsive_image_structures(
		string $content,
		array $expected_strings,
		string $description
	): void {
		// ARRANGE: Prepare content based on data provider.
		$source_site        = 'https://example.com';
		$attachments_before = $this->get_attachment_count();

		// ACT: Process content.
		$processed_content = $this->api->process_and_import_media( $content, $source_site );

		// ASSERT: Verify expected elements are present.
		$this->assertNotEmpty( $processed_content, "Content should not be empty for: {$description}" );

		foreach ( $expected_strings as $expected ) {
			$this->assertStringContainsString( $expected, $processed_content, "Should contain '{$expected}' for: {$description}" );
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
	 * Verifies that relative URLs are converted to absolute URLs.
	 *
	 * Tests various relative URL patterns including root-relative, relative
	 * paths, and already-absolute URLs.
	 */
	public function test_relative_urls_converted_to_absolute(): void {
		// ARRANGE: Create content with multiple relative URL patterns.
		$source_site = 'https://example.com';
		$content     = '
			<div>
				<a href="/root-relative">Root</a>
				<a href="relative-path">Relative</a>
				<a href="https://external.com/page">Already Absolute</a>
			</div>
		';

		// ACT: Process content.
		$processed_content = $this->api->process_and_import_media( $content, $source_site );

		// ASSERT: Verify root-relative URL converted to absolute.
		$this->assertStringContainsString( 'https://example.com/root-relative', $processed_content );
		$this->assertStringNotContainsString( 'href="/root-relative"', $processed_content );

		// ASSERT: Verify relative path converted to absolute.
		$this->assertStringContainsString( 'https://example.com/relative-path', $processed_content );

		// ASSERT: Verify already-absolute URL unchanged.
		$this->assertStringContainsString( 'https://external.com/page', $processed_content );
	}

	/**
	 * Data provider for media elements tests.
	 *
	 * @return array<string, array{element: string, url: string, wp_class: string, extra_attributes: array<string>}>
	 */
	public static function media_elements_provider(): array {
		return array(
			'video' => array(
				'element'          => 'video',
				'url'              => 'https://example.com/video.mp4',
				'wp_class'         => 'wp-video-shortcode',
				'extra_attributes' => array( 'preload' ),
			),
			'audio' => array(
				'element'          => 'audio',
				'url'              => 'https://example.com/audio.mp3',
				'wp_class'         => 'wp-audio-shortcode',
				'extra_attributes' => array(),
			),
		);
	}

	/**
	 * Verifies that media elements are processed with WordPress classes.
	 *
	 * Tests video and audio elements to ensure WordPress classes and attributes
	 * are added correctly.
	 *
	 * @dataProvider media_elements_provider
	 *
	 * @param string   $element          Media element type (video or audio).
	 * @param string   $url              Media URL.
	 * @param string   $wp_class         Expected WordPress class.
	 * @param string[] $extra_attributes Additional attributes to verify.
	 */
	public function test_media_elements_processed_correctly(
		string $element,
		string $url,
		string $wp_class,
		array $extra_attributes
	): void {
		// ARRANGE: Create media element from data provider.
		$source_site = 'https://example.com';
		$content     = sprintf( '<%s src="%s" controls></%s>', $element, $url, $element );

		// ACT: Process content.
		$processed_content = $this->api->process_and_import_media( $content, $source_site );

		// ASSERT: Verify WordPress class was added.
		$this->assertStringContainsString( $wp_class, $processed_content );

		// ASSERT: Verify controls attribute was added.
		$this->assertStringContainsString( 'controls', $processed_content );

		// ASSERT: Verify any extra attributes.
		foreach ( $extra_attributes as $attribute ) {
			$this->assertStringContainsString( $attribute, $processed_content );
		}
	}

	/**
	 * Verifies that iframes for embeds are processed correctly.
	 */
	public function test_iframe_embeds_processed_correctly(): void {
		// ARRANGE: Create content with YouTube iframe embed.
		$source_site = 'https://example.com';
		$content     = '<iframe src="https://www.youtube.com/embed/abc123"></iframe>';

		// ACT: Process content.
		$processed_content = $this->api->process_and_import_media( $content, $source_site );

		// ASSERT: Verify iframe processed with security attributes.
		$this->assertStringContainsString( 'youtube.com', $processed_content );

		// ASSERT: Verify WordPress embed class was added.
		$this->assertStringContainsString( 'wp-embedded-content', $processed_content );

		// ASSERT: Verify security attributes were added.
		$this->assertStringContainsString( 'loading="lazy"', $processed_content );
		$this->assertStringContainsString( 'referrerpolicy="no-referrer-when-downgrade"', $processed_content );
	}

	/**
	 * Verifies that complex content with mixed media types processes correctly.
	 *
	 * Tests that the processor handles multiple different element types correctly
	 * without crashes or data loss, applying proper HTML transformations.
	 */
	public function test_complex_mixed_media_content_processes_correctly(): void {
		// ARRANGE: Create complex content with multiple media types.
		$source_site        = 'https://example.com';
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
		$processed_content = $this->api->process_and_import_media( $content, $source_site );

		// ASSERT: Verify all elements are present and transformed.
		$this->assertNotEmpty( $processed_content );
		$this->assertStringContainsString( 'Article with Media', $processed_content );
		$this->assertStringContainsString( 'Header', $processed_content );
		$this->assertStringContainsString( 'Footer', $processed_content );

		// ASSERT: Verify relative link was made absolute.
		$this->assertStringContainsString( 'https://example.com/internal-link', $processed_content );
		$this->assertStringNotContainsString( 'href="/internal-link"', $processed_content );

		// ASSERT: Verify WordPress classes were added to media elements.
		$this->assertStringContainsString( 'wp-video-shortcode', $processed_content );
		$this->assertStringContainsString( 'wp-audio-shortcode', $processed_content );
		$this->assertStringContainsString( 'wp-embedded-content', $processed_content );

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
		$source_site = 'https://example.com';
		$data_url    = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
		$content     = sprintf( '<img src="%s" alt="Data URL">', $data_url );

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content with data URL.
		$processed_content = $this->api->process_and_import_media( $content, $source_site );

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
					'https://example.com/page?id=456',
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
					'https://example.com/page#section',
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
		$source_site = 'https://example.com';

		// ACT: Process content.
		$processed_content = $this->api->process_and_import_media( $content, $source_site );

		// ASSERT: Verify expected strings present.
		foreach ( $expected_strings as $expected ) {
			$this->assertStringContainsString( $expected, $processed_content, "Should contain '{$expected}' for: {$description}" );
		}
	}
}
