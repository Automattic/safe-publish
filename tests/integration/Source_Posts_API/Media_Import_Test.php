<?php
/**
 * Media Import Tests for Source Posts API
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

/**
 * Tests actual media downloads and attachment creation.
 *
 * Verifies that media files are correctly downloaded, stored in WordPress
 * media library, and tracked with proper metadata.
 *
 * phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.attachment_url_to_postid_attachment_url_to_postid
 */
class Media_Import_Test extends Source_Posts_API_Test_Base {

	/**
	 * Verifies that media is successfully imported and URL is replaced.
	 *
	 * Tests the complete import workflow: download from mocked HTTP, create
	 * attachment, replace source URL with local WordPress URL.
	 */
	public function test_successful_media_import_creates_attachment(): void {
		// ARRANGE: Create content with source image.
		$source_site_url    = 'https://example.com';
		$external_url       = 'https://example.com/test-image.jpg';
		$content            = sprintf( '<img src="%s" alt="Test Image">', $external_url );
		$attachments_before = $this->get_attachment_count();

		// ACT: Process content with media (HTTP mock serves real fixture file).
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify attachment was created.
		$attachments_after = $this->get_attachment_count();
		$this->assertSame( $attachments_before + 1, $attachments_after, 'Should create exactly one attachment' );

		// ASSERT: Verify source URL was replaced with local URL.
		$this->assertStringNotContainsString( $external_url, $processed_content, 'External URL should be replaced' );
		$this->assertStringContainsString( 'wp-content/uploads', $processed_content, 'Should contain local upload URL' );
		$this->assertStringContainsString( 'alt="Test Image"', $processed_content, 'Should preserve alt text' );
	}

	/**
	 * Verifies that imported media stores correct metadata.
	 *
	 * Tests that original URL and source site metadata are saved to attachment
	 * posts for tracking and duplicate detection. Also confirms the returned
	 * local URL is a non-empty string.
	 */
	public function test_imported_media_stores_metadata(): void {
		// ARRANGE: Prepare media import.
		$source_site_url = 'https://example.com';
		$external_url    = 'https://example.com/metadata-test.jpg';

		// ACT: Import media directly (HTTP mock serves real fixture file).
		$local_url = $this->media_importer->import_source_media( $external_url, $source_site_url );

		// ASSERT: Verify import succeeded and returned a non-empty URL string.
		$this->assertIsString( $local_url, 'Import should return local URL string' );
		$this->assertNotSame( '', $local_url, 'Import should return a non-empty URL' );

		// Find the attachment by the local URL.
		$attachment_id = attachment_url_to_postid( $local_url );
		$this->assertGreaterThan( 0, $attachment_id, 'Should find attachment by URL' );

		// ASSERT: Verify metadata stored correctly.
		$stored_original_url    = get_post_meta( $attachment_id, self::META_ORIGINAL_URL, true );
		$stored_source_site_url = get_post_meta( $attachment_id, self::META_IMPORTED_FROM, true );

		$this->assertSame( $external_url, $stored_original_url, 'Should store original URL as meta' );
		$this->assertSame( $source_site_url, $stored_source_site_url, 'Should store source site as meta' );
	}

	/**
	 * Verifies that duplicate media is not re-downloaded.
	 *
	 * Tests that importing the same URL twice uses the existing attachment
	 * instead of creating a duplicate.
	 */
	public function test_duplicate_detection_with_real_import(): void {
		// ARRANGE: Import media for the first time.
		$source_site_url    = 'https://example.com';
		$external_url       = 'https://example.com/duplicate-test.jpg';
		$attachments_before = $this->get_attachment_count();

		// ACT: Import media first time.
		$first_local_url = $this->media_importer->import_source_media( $external_url, $source_site_url );
		$this->assertNotFalse( $first_local_url, 'First import should succeed' );

		$attachments_after_first = $this->get_attachment_count();
		$this->assertSame( $attachments_before + 1, $attachments_after_first, 'First import should create attachment' );

		// ACT: Import same URL again (should use existing).
		$second_local_url = $this->media_importer->import_source_media( $external_url, $source_site_url );

		// ASSERT: Verify no new attachment created.
		$attachments_after_second = $this->get_attachment_count();
		$this->assertSame( $attachments_after_first, $attachments_after_second, 'Should not create duplicate attachment' );

		// ASSERT: Verify both imports return the same local URL.
		$this->assertSame( $first_local_url, $second_local_url, 'Should return same local URL for duplicate' );
	}

	/**
	 * Data provider for different image formats.
	 *
	 * @return array<string, array{url: string, format: string}>
	 */
	public static function image_formats_provider(): array {
		return array(
			'JPEG' => array(
				'url'    => 'https://example.com/image.jpg',
				'format' => 'jpeg',
			),
			'PNG'  => array(
				'url'    => 'https://example.com/image.png',
				'format' => 'png',
			),
			'GIF'  => array(
				'url'    => 'https://example.com/animation.gif',
				'format' => 'gif',
			),
			'WebP' => array(
				'url'    => 'https://example.com/modern.webp',
				'format' => 'webp',
			),
		);
	}

	/**
	 * Verifies that different image formats are imported correctly.
	 *
	 * Tests JPEG, PNG, GIF, and WebP formats to ensure proper handling,
	 * attachment creation, and the exact MIME type for each format.
	 *
	 * @dataProvider image_formats_provider
	 *
	 * @param string $url    Image URL.
	 * @param string $format Expected image format identifier.
	 */
	public function test_different_image_formats_imported( string $url, string $format ): void {
		// ARRANGE: Prepare image import.
		$source_site_url    = 'https://example.com';
		$attachments_before = $this->get_attachment_count();

		// ACT: Import image (HTTP mock serves real fixture file based on extension).
		$local_url = $this->media_importer->import_source_media( $url, $source_site_url );

		// ASSERT: Verify import succeeded.
		$this->assertIsString( $local_url, "Should successfully import {$format}" );
		$this->assertNotSame( '', $local_url, 'Should return a non-empty URL' );

		// ASSERT: Verify attachment created.
		$attachments_after = $this->get_attachment_count();
		$this->assertSame( $attachments_before + 1, $attachments_after, "Should create attachment for {$format}" );

		// ASSERT: Verify attachment has the exact expected MIME type for the format.
		$expected_mime_types = array(
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		);
		$attachment_id       = attachment_url_to_postid( $local_url );
		$mime_type           = get_post_mime_type( $attachment_id );
		$this->assertSame( $expected_mime_types[ $format ], $mime_type, "Should have correct MIME type for {$format}" );
	}

	/**
	 * Verifies that multiple images in content are all imported.
	 *
	 * Tests batch import scenario where content has multiple images that should
	 * all be downloaded and replaced.
	 */
	public function test_multiple_images_batch_import(): void {
		// ARRANGE: Create content with three different images.
		$source_site_url = 'https://example.com';
		$content         = '
			<div>
				<img src="https://example.com/image1.jpg" alt="First">
				<p>Some text between images</p>
				<img src="https://example.com/image2.png" alt="Second">
				<img src="https://example.com/image3.gif" alt="Third">
			</div>
		';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content (HTTP mock serves real fixtures for each format).
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify all three images imported.
		$attachments_after  = $this->get_attachment_count();
		$expected_new_count = 3;
		$this->assertSame(
			$attachments_before + $expected_new_count,
			$attachments_after,
			'Should create three attachments'
		);

		// ASSERT: Verify all source URLs replaced.
		$this->assertStringNotContainsString( 'example.com/image1.jpg', $processed_content );
		$this->assertStringNotContainsString( 'example.com/image2.png', $processed_content );
		$this->assertStringNotContainsString( 'example.com/image3.gif', $processed_content );

		// ASSERT: Verify local URLs present.
		$this->assertStringContainsString( 'wp-content/uploads', $processed_content );

		// ASSERT: Verify alt texts preserved.
		$this->assertStringContainsString( 'alt="First"', $processed_content );
		$this->assertStringContainsString( 'alt="Second"', $processed_content );
		$this->assertStringContainsString( 'alt="Third"', $processed_content );
	}

	/**
	 * Verifies that images with additional attributes are preserved.
	 *
	 * Tests that attributes like width, height, loading, class, etc. are
	 * maintained during content processing and that imports succeed.
	 */
	public function test_image_attributes_preserved(): void {
		// ARRANGE: Create image with multiple attributes.
		$source_site_url    = 'https://example.com';
		$content            = '<img src="https://example.com/img.jpg" alt="Test" width="600" height="400" class="featured" loading="lazy" decoding="async">';
		$attachments_before = $this->get_attachment_count();

		// ACT: Process content.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify all attributes preserved.
		$this->assertStringContainsString( 'alt="Test"', $processed_content );
		$this->assertStringContainsString( 'width="600"', $processed_content );
		$this->assertStringContainsString( 'height="400"', $processed_content );
		$this->assertStringContainsString( 'class="featured"', $processed_content );
		$this->assertStringContainsString( 'loading="lazy"', $processed_content );
		$this->assertStringContainsString( 'decoding="async"', $processed_content );

		// ASSERT: Verify exactly one attachment was imported.
		$attachments_after = $this->get_attachment_count();
		$this->assertSame( $attachments_before + 1, $attachments_after, 'Should create exactly one attachment for image' );

		// ASSERT: Verify source URL was replaced with local one.
		$this->assertStringNotContainsString( 'example.com/img.jpg', $processed_content, 'External URL should be replaced' );
	}

	/**
	 * Verifies that featured images are imported with proper metadata.
	 *
	 * Tests the featured image import workflow including attachment creation,
	 * URL replacement, and metadata storage for tracking.
	 */
	public function test_featured_image_import_and_metadata(): void {
		// ARRANGE: Prepare featured image import.
		$source_site_url    = 'https://example.com';
		$featured_image_url = 'https://example.com/featured-post-image.jpg';
		$attachments_before = $this->get_attachment_count();

		// ACT: Import featured image.
		$featured_image_local = $this->media_importer->import_source_media( $featured_image_url, $source_site_url );

		// ASSERT: Verify import succeeded.
		$this->assertNotFalse( $featured_image_local, 'Featured image should import successfully' );
		$this->assertIsString( $featured_image_local );
		$this->assertStringContainsString( 'wp-content/uploads', $featured_image_local );

		// ASSERT: Verify attachment created.
		$attachments_after = $this->get_attachment_count();
		$this->assertSame( $attachments_before + 1, $attachments_after, 'Should create exactly one attachment' );

		// ASSERT: Verify featured image has proper metadata for tracking.
		$featured_attachment_id = attachment_url_to_postid( $featured_image_local );
		$this->assertGreaterThan( 0, $featured_attachment_id, 'Should find attachment by URL' );
		$this->assertSame(
			$featured_image_url,
			get_post_meta( $featured_attachment_id, self::META_ORIGINAL_URL, true ),
			'Should store original URL as metadata'
		);
		$this->assertSame(
			$source_site_url,
			get_post_meta( $featured_attachment_id, self::META_IMPORTED_FROM, true ),
			'Should store source site as metadata'
		);
	}

	/**
	 * Verifies that inline images are batch imported with URL replacement.
	 *
	 * Tests that multiple images in content are all imported and their
	 * source URLs are replaced with local WordPress URLs.
	 */
	public function test_inline_images_batch_import_and_url_replacement(): void {
		// ARRANGE: Create content with multiple inline images.
		$source_site_url = 'https://example.com';
		$post_content    = '
			<div class="post-content">
				<p>Introduction paragraph with some text.</p>

				<figure class="wp-block-image">
					<img src="https://example.com/inline-image-1.jpg" alt="First inline image" class="featured-img">
					<figcaption>Caption for first image</figcaption>
				</figure>

				<p>More content between media elements.</p>

				<img src="https://example.com/inline-image-2.png" alt="Second inline image" width="800" height="600">
			</div>
		';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process post content with inline media.
		$processed_content = $this->content_media_processor->process_content( $post_content, $source_site_url );

		// ASSERT: Verify all inline images imported (2 images in content).
		$attachments_after = $this->get_attachment_count();
		$this->assertSame(
			$attachments_before + 2,
			$attachments_after,
			'Should create 2 attachments for inline images'
		);

		// ASSERT: Verify all inline image URLs replaced with local URLs.
		$this->assertStringNotContainsString( 'example.com/inline-image-1.jpg', $processed_content );
		$this->assertStringNotContainsString( 'example.com/inline-image-2.png', $processed_content );
		$this->assertStringContainsString( 'wp-content/uploads', $processed_content );

		// ASSERT: Verify HTML attributes preserved.
		$this->assertStringContainsString( 'alt="First inline image"', $processed_content );
		$this->assertStringContainsString( 'alt="Second inline image"', $processed_content );
		$this->assertStringContainsString( 'width="800"', $processed_content );
		$this->assertStringContainsString( 'height="600"', $processed_content );
		$this->assertStringContainsString( 'class="featured-img"', $processed_content );
	}

	/**
	 * Verifies that content transformations are preserved during import.
	 *
	 * Tests that WordPress-specific classes, relative links, and content
	 * structure are all preserved correctly during media processing.
	 */
	public function test_content_transformations_preserved(): void {
		// ARRANGE: Create content with various elements to transform.
		$source_site_url = 'https://example.com';
		$post_content    = '
			<div class="post-content">
				<p>Introduction paragraph with some text.</p>

				<figure class="wp-block-image">
					<img src="https://example.com/test-image.jpg" alt="Test image">
					<figcaption>Caption for image</figcaption>
				</figure>

				<p>Embedded video:</p>
				<video src="https://example.com/demo-video.mp4" controls></video>

				<p>Conclusion with <a href="/related-post">internal link</a>.</p>
			</div>
		';

		// ACT: Process content with transformations.
		$processed_content = $this->content_media_processor->process_content( $post_content, $source_site_url );

		// ASSERT: Verify content structure preserved.
		$this->assertStringContainsString( 'Introduction paragraph', $processed_content );
		$this->assertStringContainsString( 'Caption for image', $processed_content );
		$this->assertStringContainsString( 'Conclusion', $processed_content );

		// ASSERT: Verify source structure preserved.
		$this->assertStringContainsString( 'wp-block-image', $processed_content );
		$this->assertStringContainsString( 'figcaption', $processed_content );
		$this->assertStringNotContainsString( 'wp-video-shortcode', $processed_content );

		// ASSERT: Verify relative link is preserved as-is.
		$this->assertStringContainsString( 'href="/related-post"', $processed_content );
		$this->assertStringNotContainsString( 'href="https://example.com/related-post"', $processed_content );
	}

	/**
	 * Verifies that query parameters are stripped for deduplication.
	 *
	 * Tests that URLs with different query parameters (e.g., CDN cache busting)
	 * are treated as the same image to avoid duplicate imports.
	 */
	public function test_query_parameters_stripped_for_deduplication(): void {
		// ARRANGE: Import media with query parameters.
		$source_site_url    = 'https://example.com';
		$url_with_params_v1 = 'https://example.com/image.jpg?v=1&cache=bust';
		$url_with_params_v2 = 'https://example.com/image.jpg?v=2&t=12345';
		$url_without_params = 'https://example.com/image.jpg';
		$attachments_before = $this->get_attachment_count();

		// ACT: Import first URL with query parameters.
		$first_local_url = $this->media_importer->import_source_media( $url_with_params_v1, $source_site_url );
		$this->assertNotFalse( $first_local_url, 'First import should succeed' );

		$attachments_after_first = $this->get_attachment_count();
		$this->assertSame( $attachments_before + 1, $attachments_after_first, 'First import should create attachment' );

		// ACT: Import second URL with different query parameters.
		$second_local_url = $this->media_importer->import_source_media( $url_with_params_v2, $source_site_url );

		// ASSERT: Verify no new attachment created (query params stripped, treated as duplicate).
		$attachments_after_second = $this->get_attachment_count();
		$this->assertSame(
			$attachments_after_first,
			$attachments_after_second,
			'Should not create duplicate attachment when only query parameters differ'
		);

		// ASSERT: Verify both imports return the same local URL.
		$this->assertSame(
			$first_local_url,
			$second_local_url,
			'Should return same local URL for URLs differing only in query parameters'
		);

		// ACT: Import third URL without query parameters (should still match).
		$third_local_url = $this->media_importer->import_source_media( $url_without_params, $source_site_url );

		// ASSERT: Verify still using the same attachment.
		$attachments_after_third = $this->get_attachment_count();
		$this->assertSame(
			$attachments_after_first,
			$attachments_after_third,
			'Should not create duplicate attachment for URL without query parameters'
		);
		$this->assertSame(
			$first_local_url,
			$third_local_url,
			'Should return same local URL whether query parameters present or not'
		);

		// ASSERT: Verify the stored metadata has the URL without query params.
		$attachment_id       = attachment_url_to_postid( $first_local_url );
		$stored_original_url = get_post_meta( $attachment_id, self::META_ORIGINAL_URL, true );
		$this->assertSame(
			$url_without_params,
			$stored_original_url,
			'Should store URL without query parameters in metadata'
		);
	}

	/**
	 * Verifies that same filename from different domains creates separate
	 * attachments.
	 *
	 * Tests that images with identical filenames but from different source
	 * sites are correctly treated as different images and both are imported.
	 */
	public function test_same_filename_different_domains_creates_separate_attachments(): void {
		// ARRANGE: Prepare two different domains with same filename.
		$source_site_url_a  = 'https://site-a.example.com';
		$source_site_url_b  = 'https://site-b.example.com';
		$url_from_site_a    = 'https://site-a.example.com/logo.png';
		$url_from_site_b    = 'https://site-b.example.com/logo.png';
		$attachments_before = $this->get_attachment_count();

		// ACT: Import from first domain.
		$first_local_url = $this->media_importer->import_source_media( $url_from_site_a, $source_site_url_a );
		$this->assertNotFalse( $first_local_url, 'First import should succeed' );

		$attachments_after_first = $this->get_attachment_count();
		$this->assertSame( $attachments_before + 1, $attachments_after_first, 'First import should create attachment' );

		// Get the attachment ID and verify metadata.
		$first_attachment_id = attachment_url_to_postid( $first_local_url );
		$this->assertGreaterThan( 0, $first_attachment_id, 'Should find first attachment by URL' );
		$this->assertSame(
			$url_from_site_a,
			get_post_meta( $first_attachment_id, self::META_ORIGINAL_URL, true ),
			'First attachment should store correct original URL'
		);

		// ACT: Import from second domain with same filename.
		$second_local_url = $this->media_importer->import_source_media( $url_from_site_b, $source_site_url_b );
		$this->assertNotFalse( $second_local_url, 'Second import should succeed' );

		// ASSERT: Verify a NEW attachment was created (not treated as duplicate).
		$attachments_after_second = $this->get_attachment_count();
		$this->assertSame(
			$attachments_after_first + 1,
			$attachments_after_second,
			'Should create separate attachment for same filename from different domain'
		);

		// ASSERT: Verify the two local URLs are different.
		$this->assertNotSame(
			$first_local_url,
			$second_local_url,
			'Should return different local URLs for same filename from different domains'
		);

		// ASSERT: Verify second attachment has correct metadata.
		$second_attachment_id = attachment_url_to_postid( $second_local_url );
		$this->assertGreaterThan( 0, $second_attachment_id, 'Should find second attachment by URL' );
		$this->assertNotSame(
			$first_attachment_id,
			$second_attachment_id,
			'Should have different attachment IDs'
		);
		$this->assertSame(
			$url_from_site_b,
			get_post_meta( $second_attachment_id, self::META_ORIGINAL_URL, true ),
			'Second attachment should store correct original URL'
		);
	}

	/**
	 * Verifies URL normalization behavior for edge cases.
	 *
	 * Tests that minor URL variations are handled correctly. Some should be
	 * treated as the same image, others as different images.
	 */
	public function test_url_normalization_edge_cases(): void {
		// ARRANGE: Base case - import an image.
		$source_site_url    = 'https://example.com';
		$base_url           = 'https://example.com/assets/logo.png';
		$attachments_before = $this->get_attachment_count();

		// ACT: Import base URL.
		$base_local_url = $this->media_importer->import_source_media( $base_url, $source_site_url );
		$this->assertNotFalse( $base_local_url, 'Base import should succeed' );
		$attachments_after_base = $this->get_attachment_count();
		$this->assertSame( $attachments_before + 1, $attachments_after_base, 'Base import should create attachment' );

		// TEST 1: Different path, same filename - should create NEW attachment.
		// This is NOT the same image even though filename matches.
		$different_path_url   = 'https://example.com/images/logo.png';
		$different_path_local = $this->media_importer->import_source_media( $different_path_url, $source_site_url );
		$this->assertNotFalse( $different_path_local, 'Different path import should succeed' );
		$attachments_after_different_path = $this->get_attachment_count();
		$this->assertSame(
			$attachments_after_base + 1,
			$attachments_after_different_path,
			'Different path with same filename should create NEW attachment (different image)'
		);
		$this->assertNotSame(
			$base_local_url,
			$different_path_local,
			'Different paths should return different local URLs'
		);

		// TEST 2: Same exact URL imported twice - should return existing attachment.
		$duplicate_url   = $base_url; // Exact same URL as base.
		$duplicate_local = $this->media_importer->import_source_media( $duplicate_url, $source_site_url );
		$this->assertNotFalse( $duplicate_local, 'Duplicate URL import should succeed' );
		$attachments_after_duplicate = $this->get_attachment_count();
		$this->assertSame(
			$attachments_after_different_path,
			$attachments_after_duplicate,
			'Exact duplicate URL should NOT create new attachment (deduplication working)'
		);
		$this->assertSame(
			$base_local_url,
			$duplicate_local,
			'Duplicate URL should return same local URL as original'
		);
	}

	/**
	 * Verifies that URL path changes result in new imports.
	 *
	 * When a source site reorganizes or updates URLs, we want to import the
	 * new URL as a fresh attachment rather than reusing old ones. This ensures
	 * we get the latest version and handle URL migrations correctly.
	 */
	public function test_url_path_changes_create_new_attachment(): void {
		// ARRANGE: Import from original path.
		$source_site_url    = 'https://example.com';
		$original_url       = 'https://example.com/2023/uploads/hero.jpg';
		$attachments_before = $this->get_attachment_count();

		// ACT: Import from original URL.
		$original_local = $this->media_importer->import_source_media( $original_url, $source_site_url );
		$this->assertNotFalse( $original_local, 'Original import should succeed' );
		$attachments_after_original = $this->get_attachment_count();
		$this->assertSame( $attachments_before + 1, $attachments_after_original, 'Should create attachment for original' );

		// Simulate source site reorganizing files to a new path.
		$reorganized_url   = 'https://example.com/2024/reorganized/hero.jpg';
		$reorganized_local = $this->media_importer->import_source_media( $reorganized_url, $source_site_url );
		$this->assertNotFalse( $reorganized_local, 'Reorganized path import should succeed' );

		// ASSERT: Should create NEW attachment when path changes.
		$attachments_after_reorganized = $this->get_attachment_count();
		$this->assertSame(
			$attachments_after_original + 1,
			$attachments_after_reorganized,
			'URL path change should create new attachment (might be updated image)'
		);

		// ASSERT: Verify they have different local URLs.
		$this->assertNotSame(
			$original_local,
			$reorganized_local,
			'Different paths should have different local URLs'
		);

		// ASSERT: Verify metadata stores the correct original URLs.
		$original_attachment_id    = attachment_url_to_postid( $original_local );
		$reorganized_attachment_id = attachment_url_to_postid( $reorganized_local );

		$this->assertSame(
			$original_url,
			get_post_meta( $original_attachment_id, self::META_ORIGINAL_URL, true ),
			'Original attachment should track original URL'
		);
		$this->assertSame(
			$reorganized_url,
			get_post_meta( $reorganized_attachment_id, self::META_ORIGINAL_URL, true ),
			'Reorganized attachment should track new URL'
		);
	}

	/**
	 * Verifies that query parameters are restored on the local URL in the
	 * processed content.
	 */
	public function test_query_parameters_restored_in_processed_content(): void {
		// ARRANGE: Image with query parameters.
		$source_site_url = 'https://example.com';
		$external_url    = 'https://example.com/photo.jpg?w=800&h=450&crop=1';
		$content         = sprintf( '<img src="%s" alt="Cropped hero">', $external_url );

		// ACT: Process content (HTTP mock serves fixture, parameters stripped for download).
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: External domain replaced with local.
		$this->assertStringNotContainsString( 'example.com/photo.jpg', $processed_content );

		// ASSERT: Query parameters are present on the local URL.
		$this->assertStringContainsString( 'w=800', $processed_content, 'Width param should be restored' );
		$this->assertStringContainsString( 'h=450', $processed_content, 'Height param should be restored' );
		$this->assertStringContainsString( 'crop=1', $processed_content, 'Crop param should be restored' );

		// ASSERT: Alt text preserved.
		$this->assertStringContainsString( 'alt="Cropped hero"', $processed_content );
	}

	/**
	 * Verifies that different query parameters for the same base image produce
	 * different local URLs.
	 *
	 * The same attachment is reused, but each occurrence in content gets its
	 * own parameter set applied to the local URL.
	 */
	public function test_different_parameters_same_image_produce_different_local_urls(): void {
		// ARRANGE: Same image used at two different sizes in the same content.
		$source_site_url = 'https://example.com';
		$content         = '
			<img src="https://example.com/hero.jpg?w=1200&h=630" alt="Large hero">
			<img src="https://example.com/hero.jpg?w=400&h=225" alt="Thumbnail">
		';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Only one attachment created (base URL is the same after stripping).
		$attachments_after = $this->get_attachment_count();
		$this->assertSame( $attachments_before + 1, $attachments_after, 'Should deduplicate to a single attachment' );

		// ASSERT: Large variant retains its dimensions.
		$this->assertStringContainsString( 'w=1200', $processed_content, 'Large width param should be present' );
		$this->assertStringContainsString( 'h=630', $processed_content, 'Large height param should be present' );

		// ASSERT: Thumbnail variant retains its dimensions.
		$this->assertStringContainsString( 'w=400', $processed_content, 'Thumbnail width param should be present' );
		$this->assertStringContainsString( 'h=225', $processed_content, 'Thumbnail height param should be present' );
	}

	/**
	 * Verifies that query parameters are restored on the local URL for video
	 * elements in processed content.
	 *
	 * Uses a .jpg extension because the test mock infrastructure only serves
	 * image fixtures; the element type, not file format, is what this test
	 * exercises.
	 */
	public function test_query_parameters_restored_for_video_in_processed_content(): void {
		// ARRANGE: Video src with query parameters.
		$source_site_url = 'https://example.com';
		$external_url    = 'https://example.com/clip.jpg?t=30&quality=hd';
		$content         = sprintf(
			'<video src="%s"></video>',
			$external_url
		);

		// ACT: Process content.
		$processed = $this->content_media_processor
			->process_content( $content, $source_site_url );

		// ASSERT: External domain replaced with local.
		$this->assertStringNotContainsString(
			'example.com/clip.jpg',
			$processed
		);

		// ASSERT: Query parameters are present on the local URL.
		$this->assertStringContainsString(
			't=30',
			$processed,
			'Start-time param should be restored'
		);
		$this->assertStringContainsString(
			'quality=hd',
			$processed,
			'Quality param should be restored'
		);
	}

	/**
	 * Verifies that query parameters are restored on the local URL for audio
	 * elements in processed content.
	 *
	 * Uses a .jpg extension because the test mock infrastructure only serves
	 * image fixtures; the element type, not file format, is what this test
	 * exercises.
	 */
	public function test_query_parameters_restored_for_audio_in_processed_content(): void {
		// ARRANGE: Audio src with query parameters.
		$source_site_url = 'https://example.com';
		$external_url    = 'https://example.com/podcast.jpg?start=120';
		$content         = sprintf(
			'<audio src="%s"></audio>',
			$external_url
		);

		// ACT: Process content.
		$processed = $this->content_media_processor
			->process_content( $content, $source_site_url );

		// ASSERT: External domain replaced with local.
		$this->assertStringNotContainsString(
			'example.com/podcast.jpg',
			$processed
		);

		// ASSERT: Query parameter is present on the local URL.
		$this->assertStringContainsString(
			'start=120',
			$processed,
			'Start param should be restored'
		);
	}

	/**
	 * Verifies that query parameters are restored on video poster URLs in
	 * processed content.
	 */
	public function test_query_parameters_restored_for_video_poster_in_processed_content(): void {
		// ARRANGE: Video with a poster that has query parameters.
		$source_site_url = 'https://example.com';
		$poster_url      = 'https://example.com/thumb.jpg?w=800&h=450';
		$content         = sprintf(
			'<video poster="%s"></video>',
			$poster_url
		);

		// ACT: Process content.
		$processed = $this->content_media_processor
			->process_content( $content, $source_site_url );

		// ASSERT: External domain replaced with local.
		$this->assertStringNotContainsString(
			'example.com/thumb.jpg',
			$processed
		);

		// ASSERT: Query parameters are present on the local URL.
		$this->assertStringContainsString(
			'w=800',
			$processed,
			'Width param should be restored'
		);
		$this->assertStringContainsString(
			'h=450',
			$processed,
			'Height param should be restored'
		);
	}
}
