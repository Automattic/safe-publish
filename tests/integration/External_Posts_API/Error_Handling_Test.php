<?php
/**
 * Error Handling Tests for External Posts API
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\External_Posts_API;

use WP_Error;

/**
 * Tests error scenarios and edge cases in the content processor layer.
 *
 * Verifies that the content processor does not crash on media download failures,
 * that failed image URLs are tracked for the import service to act on, and that
 * edge-case inputs are handled correctly.
 */
class Error_Handling_Test extends External_Posts_API_Test_Base {

	/**
	 * Verifies that content processing continues when a media download returns 404.
	 *
	 * The processor must not crash or lose content. The failed URL is tracked in
	 * get_failed_media() so the import service can fail the import.
	 */
	public function test_failed_media_import_handled_gracefully(): void {
		// ARRANGE: Create content with image that will fail to import (404).
		$source_site_url = 'https://example.com';
		$content         = '<p>Content with <img src="https://example.com/nonexistent-404.jpg" alt="Broken"> broken image</p>';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content with broken media (mock returns 404).
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify content processed despite failed media.
		$this->assertIsString( $processed_content );
		$this->assertNotSame( '', $processed_content );
		$this->assertStringContainsString( 'Content with', $processed_content );
		$this->assertStringContainsString( 'broken image', $processed_content );
		$this->assertStringContainsString( 'alt="Broken"', $processed_content );

		// ASSERT: The URL is left unchanged in processor output (tracking it, not swapping it).
		$this->assertStringContainsString(
			'https://example.com/nonexistent-404.jpg',
			$processed_content,
			'URL should remain in processor output so the import service can report it'
		);

		// ASSERT: The failed URL is tracked so the import service can fail the import.
		$this->assertSame(
			array( 'https://example.com/nonexistent-404.jpg' ),
			$this->content_media_processor->get_failed_media(),
			'Failed image URL should be recorded for the import service to act on'
		);

		// ASSERT: A failed download must not also be reported as unprocessable
		// markup, otherwise the user sees the same URL twice with conflicting
		// reasons.
		$this->assertSame(
			array(),
			$this->content_media_processor->get_unprocessable_media(),
			'Failed download URL must not also be recorded as unprocessable'
		);

		// ASSERT: Verify no attachment was created for 404 response.
		$this->assert_no_new_attachments( $attachments_before, 'Should not create attachment when download returns 404' );
	}

	/**
	 * Verifies that import_external_media() handles failures gracefully.
	 *
	 * Tests that calling import_external_media() with a non-existent URL
	 * returns false without crashing.
	 */
	public function test_import_external_media_handles_failure(): void {
		// ARRANGE: Prepare non-existent URL.
		$source_site_url    = 'https://example.com';
		$featured_image_url = 'https://example.com/nonexistent-featured.jpg';

		$attachments_before = $this->get_attachment_count();

		// ACT: Try to import media from non-existent URL.
		$imported_url = $this->media_importer->import_external_media( $featured_image_url, $source_site_url );

		// ASSERT: Verify import returns false for non-existent URL.
		$this->assertFalse(
			$imported_url,
			'Should return false when download fails'
		);

		// ASSERT: Verify no attachment was created.
		$this->assert_no_new_attachments( $attachments_before );
	}

	/**
	 * Verifies that malformed HTML is handled gracefully.
	 */
	public function test_malformed_html_handled_gracefully(): void {
		// ARRANGE: Create malformed HTML content.
		$source_site_url = 'https://example.com';
		$content         = '<p>Unclosed paragraph<div>Mixed tags</p></div>';

		// ACT: Process malformed content.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify content returned without errors.
		$this->assertIsString( $processed_content );
		$this->assertNotSame( '', $processed_content );
		$this->assertStringContainsString( 'Unclosed paragraph', $processed_content );
		$this->assertStringContainsString( 'Mixed tags', $processed_content );
	}

	/**
	 * Verifies that UTF-8 content is preserved correctly.
	 */
	public function test_utf8_content_preserved(): void {
		// ARRANGE: Create content with UTF-8 characters and emoji.
		$source_site_url = 'https://example.com';
		$content         = '<p>Testing UTF-8: 你好世界 🌍</p>';

		// ACT: Process UTF-8 content.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify UTF-8 characters preserved.
		$this->assertStringContainsString( '你好世界', $processed_content );
		$this->assertStringContainsString( '🌍', $processed_content );
	}

	/**
	 * Verifies that empty and whitespace-only content is handled without errors.
	 */
	public function test_empty_content_handled_gracefully(): void {
		// ARRANGE: Prepare edge case inputs.
		$source_site_url = 'https://example.com';

		// ACT & ASSERT: Test empty string.
		$processed = $this->content_media_processor->process_content( '', $source_site_url );
		$this->assertSame( '', $processed, 'Empty string should return empty string' );

		// ACT & ASSERT: Test whitespace-only content.
		$processed = $this->content_media_processor->process_content( '   ', $source_site_url );
		$this->assertNotNull( $processed, 'Whitespace content should not return null' );

		// ACT & ASSERT: Test newlines only.
		$processed = $this->content_media_processor->process_content( "\n\n", $source_site_url );
		$this->assertNotNull( $processed, 'Newline content should not return null' );
	}

	/**
	 * Verifies that content processing continues when a media download returns a 500 error.
	 *
	 * The processor must not crash or lose content. The failed URL is tracked in
	 * get_failed_media() so the import service can fail the import.
	 */
	public function test_import_handles_500_server_error(): void {
		// ARRANGE: Create content with image that returns 500 error (mocked in base class).
		$source_site_url    = 'https://example.com';
		$content            = '<p>Content with <img src="https://example.com/server-error.jpg" alt="Server Error"> failed image</p>';
		$attachments_before = $this->get_attachment_count();

		// ACT: Process content with server error.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Verify content processed despite server error.
		$this->assertIsString( $processed_content );
		$this->assertNotSame( '', $processed_content );
		$this->assertStringContainsString( 'Content with', $processed_content );
		$this->assertStringContainsString( 'failed image', $processed_content );
		$this->assertStringContainsString( 'alt="Server Error"', $processed_content );

		// ASSERT: The URL is left unchanged in processor output (tracking it, not swapping it).
		$this->assertStringContainsString(
			'https://example.com/server-error.jpg',
			$processed_content,
			'URL should remain in processor output so the import service can report it'
		);

		// ASSERT: The failed URL is tracked so the import service can fail the import.
		$this->assertSame(
			array( 'https://example.com/server-error.jpg' ),
			$this->content_media_processor->get_failed_media(),
			'Failed image URL should be recorded for the import service to act on'
		);

		// ASSERT: Verify no attachment created for server error.
		$this->assert_no_new_attachments( $attachments_before, 'Should not create attachment when server returns 500' );
	}

	/**
	 * Verifies that content processing continues when a network request returns a WP_Error.
	 *
	 * When download_url() encounters a network failure (timeout, DNS error, SSL error,
	 * etc.), the processor must not crash or lose content. The failed URL is tracked in
	 * get_failed_media() so the import service can fail the import.
	 */
	public function test_import_handles_wp_error_from_network_failure(): void {
		// ARRANGE: Create content with image URL.
		$source_site_url    = 'https://example.com';
		$content            = '<p>Content with <img src="https://example.com/network-timeout.jpg" alt="Network Error"> failed image</p>';
		$attachments_before = $this->get_attachment_count();

		// Mock network failure that returns WP_Error.
		$filter_callback = static function ( $preempt, $args, $url ) {
			if ( str_contains( $url, 'network-timeout.jpg' ) ) {
				return new WP_Error(
					'http_request_failed',
					'Operation timed out after 30000 milliseconds'
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $filter_callback, 11, 3 );

		try {
			// ACT: Process content with network failure.
			$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

			// ASSERT: Verify content processed despite WP_Error.
			$this->assertIsString( $processed_content );
			$this->assertNotSame( '', $processed_content );
			$this->assertStringContainsString( 'Content with', $processed_content );
			$this->assertStringContainsString( 'failed image', $processed_content );
			$this->assertStringContainsString( 'alt="Network Error"', $processed_content );

			// ASSERT: The URL is left unchanged in processor output (tracking it, not swapping it).
			$this->assertStringContainsString(
				'https://example.com/network-timeout.jpg',
				$processed_content,
				'URL should remain in processor output so the import service can report it'
			);

			// ASSERT: The failed URL is tracked so the import service can fail the import.
			$this->assertSame(
				array( 'https://example.com/network-timeout.jpg' ),
				$this->content_media_processor->get_failed_media(),
				'Failed image URL should be recorded for the import service to act on'
			);

			// ASSERT: Verify no attachment created for network error.
			$this->assert_no_new_attachments( $attachments_before, 'Should not create attachment when network request fails' );
		} finally {
			// Cleanup: Always remove filter, even if test fails.
			remove_filter( 'pre_http_request', $filter_callback, 11 );
		}
	}

	/**
	 * Verifies that content processing continues when media_handle_sideload() fails.
	 *
	 * When sideload fails (e.g., file type validation, upload limits), the processor
	 * must not crash or lose content. The failed URL is tracked in get_failed_media()
	 * so the import service can fail the import.
	 */
	public function test_import_handles_invalid_image_data(): void {
		// ARRANGE: Create content with image URL.
		$source_site_url    = 'https://example.com';
		$content            = '<p>Content with <img src="https://example.com/trigger-sideload-error.jpg" alt="Invalid Data"> broken image</p>';
		$attachments_before = $this->get_attachment_count();

		// Mock response that will cause media_handle_sideload to fail.
		// We'll use a filter on wp_handle_sideload to force an error.
		$http_callback = static function ( $preempt, $args, $url ) {
			if ( str_contains( $url, 'trigger-sideload-error.jpg' ) ) {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => str_repeat( 'X', 100 ), // Some content.
					'headers'  => array( 'content-type' => 'image/jpeg' ),
				);
			}
			return $preempt;
		};

		// Force media_handle_sideload to return an error.
		$sideload_callback = static function ( $file ) {
			if ( isset( $file['name'] ) && str_contains( $file['name'], 'trigger-sideload-error' ) ) {
				return array(
					'error' => 'File type validation failed for testing purposes.',
				);
			}
			return $file;
		};

		add_filter( 'pre_http_request', $http_callback, 11, 3 );
		add_filter( 'wp_handle_sideload_prefilter', $sideload_callback, 999, 1 );

		try {
			// ACT: Process content with file that will fail sideload.
			$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

			// ASSERT: Verify content processed despite sideload failure.
			$this->assertIsString( $processed_content );
			$this->assertNotSame( '', $processed_content );
			$this->assertStringContainsString( 'Content with', $processed_content );
			$this->assertStringContainsString( 'broken image', $processed_content );
			$this->assertStringContainsString( 'alt="Invalid Data"', $processed_content );

			// ASSERT: The URL is left unchanged in processor output (tracking it, not swapping it).
			$this->assertStringContainsString(
				'https://example.com/trigger-sideload-error.jpg',
				$processed_content,
				'URL should remain in processor output so the import service can report it'
			);

			// ASSERT: The failed URL is tracked so the import service can fail the import.
			$this->assertSame(
				array( 'https://example.com/trigger-sideload-error.jpg' ),
				$this->content_media_processor->get_failed_media(),
				'Failed image URL should be recorded for the import service to act on'
			);

			// ASSERT: Verify no attachment created for failed sideload.
			$this->assert_no_new_attachments( $attachments_before, 'Should not create attachment when sideload fails' );
		} finally {
			// Cleanup: Always remove filters, even if test fails.
			remove_filter( 'pre_http_request', $http_callback, 11 );
			remove_filter( 'wp_handle_sideload_prefilter', $sideload_callback, 999 );
		}
	}

	/**
	 * Verifies that a failed srcset variant import is tracked and aborts the
	 * import.
	 *
	 * A browser typically requests a srcset variant rather than the base src on
	 * retina/HiDPI displays. Leaving a failed srcset URL pointing at the external
	 * staging site would silently defeat the whole purpose of the import. Failed
	 * srcset URLs must be tracked in get_failed_media() exactly like base src failures.
	 */
	public function test_failed_srcset_image_tracked_as_failed(): void {
		// ARRANGE: An <img> whose base src succeeds but whose srcset variant returns 404.
		$source_site_url = 'https://example.com';
		$content         = '<p><img src="https://example.com/image.jpg"'
			. ' srcset="https://example.com/nonexistent-2x.jpg 2x" alt="Test"></p>';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content — base src will succeed, srcset variant will 404.
		$processed_content = $this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: Processor output is non-empty and the alt text is intact.
		$this->assertIsString( $processed_content );
		$this->assertNotSame( '', $processed_content );
		$this->assertStringContainsString( 'alt="Test"', $processed_content );

		// ASSERT: The failed srcset URL is tracked so the import service can abort.
		$this->assertSame(
			array( 'https://example.com/nonexistent-2x.jpg' ),
			$this->content_media_processor->get_failed_media(),
			'Failed srcset URL must be recorded in failed_media'
		);

		// ASSERT: The base src image was still imported successfully.
		$this->assertGreaterThan( $attachments_before, $this->get_attachment_count(), 'Base src attachment should have been created' );
	}

	/**
	 * Verifies that a failed classic-HTML video src import is tracked as a failure.
	 *
	 * A video with a staging URL left in its src attribute would serve the file
	 * from the external site. The failed URL must be tracked exactly like a
	 * broken image src so the import service can abort.
	 */
	public function test_failed_video_src_tracked_as_failed(): void {
		// ARRANGE: Content with a <video> that points at a non-existent file.
		$source_site_url = 'https://example.com';
		$content         = '<p><video src="https://example.com/nonexistent-clip.mp4" controls></video></p>';

		// ACT: Process content — the video URL contains 'nonexistent' so the mock returns 404.
		$this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: The failed video URL is tracked so the import service can abort.
		$this->assertSame(
			array( 'https://example.com/nonexistent-clip.mp4' ),
			$this->content_media_processor->get_failed_media(),
			'Failed video src URL must be recorded in failed_media'
		);
	}

	/**
	 * Verifies that a failed classic-HTML video poster import is tracked as a failure.
	 *
	 * The poster attribute is an image displayed before playback. Leaving a staging URL
	 * in the poster attribute while correctly importing the video is inconsistent and
	 * will silently reference the external site.
	 */
	public function test_failed_video_poster_tracked_as_failed(): void {
		// ARRANGE: A video with a working src but a non-existent poster image.
		$source_site_url = 'https://example.com';
		$content         = '<p><video src="https://example.com/clip.mp4" poster="https://example.com/nonexistent-poster.jpg" controls></video></p>';

		// ACT: Process content — clip.mp4 succeeds; poster returns 404 (contains 'nonexistent').
		$this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: The failed poster URL is tracked.
		$this->assertContains(
			'https://example.com/nonexistent-poster.jpg',
			$this->content_media_processor->get_failed_media(),
			'Failed video poster URL must be recorded in failed_media'
		);
	}

	/**
	 * Verifies that a failed classic-HTML audio src import is tracked as a failure.
	 *
	 * An audio element with a staging URL left in its src attribute would stream
	 * audio from the external site. The failed URL must abort the import.
	 */
	public function test_failed_audio_src_tracked_as_failed(): void {
		// ARRANGE: Content with an <audio> that points at a non-existent file.
		$source_site_url = 'https://example.com';
		$content         = '<p><audio src="https://example.com/nonexistent-track.mp3" controls></audio></p>';

		// ACT: Process content — audio URL contains 'nonexistent' so the mock returns 404.
		$this->content_media_processor->process_content( $content, $source_site_url );

		// ASSERT: The failed audio URL is tracked so the import service can abort.
		$this->assertSame(
			array( 'https://example.com/nonexistent-track.mp3' ),
			$this->content_media_processor->get_failed_media(),
			'Failed audio src URL must be recorded in failed_media'
		);
	}
}
