<?php
/**
 * Error Handling Tests for External Posts API
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\External_Posts_API;

/**
 * Tests error scenarios and edge cases.
 *
 * Verifies that the API handles errors gracefully, preserves content even when
 * media fails to import, and handles various edge cases.
 */
class Error_Handling_Test extends External_Posts_API_Test_Base {

	/**
	 * Verifies that failed media import is handled gracefully.
	 *
	 * When media fails to import (404), content processing should continue and
	 * preserve the original URL without creating attachments.
	 */
	public function test_failed_media_import_handled_gracefully(): void {
		// ARRANGE: Create content with image that will fail to import (404).
		$source_site = 'https://example.com';
		$content     = '<p>Content with <img src="https://example.com/nonexistent-404.jpg" alt="Broken"> broken image</p>';

		$attachments_before = $this->get_attachment_count();

		// ACT: Process content with broken media (mock returns 404).
		$processed_content = $this->api->process_and_import_media( $content, $source_site );

		// ASSERT: Verify content processed despite failed media.
		$this->assertNotEmpty( $processed_content );
		$this->assertStringContainsString( 'Content with', $processed_content );
		$this->assertStringContainsString( 'broken image', $processed_content );
		$this->assertStringContainsString( 'alt="Broken"', $processed_content );

		// ASSERT: Verify original URL is preserved when import fails.
		$this->assertStringContainsString(
			'https://example.com/nonexistent-404.jpg',
			$processed_content,
			'Original URL should be preserved when import fails'
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
		$source_site        = 'https://example.com';
		$featured_image_url = 'https://example.com/nonexistent-featured.jpg';

		$attachments_before = $this->get_attachment_count();

		// ACT: Try to import media from non-existent URL.
		$imported_url = $this->api->import_external_media( $featured_image_url, $source_site );

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
		$source_site = 'https://example.com';
		$content     = '<p>Unclosed paragraph<div>Mixed tags</p></div>';

		// ACT: Process malformed content.
		$processed_content = $this->api->process_and_import_media( $content, $source_site );

		// ASSERT: Verify content returned without errors.
		$this->assertNotEmpty( $processed_content );
		$this->assertStringContainsString( 'Unclosed paragraph', $processed_content );
		$this->assertStringContainsString( 'Mixed tags', $processed_content );
	}

	/**
	 * Verifies that UTF-8 content is preserved correctly.
	 */
	public function test_utf8_content_preserved(): void {
		// ARRANGE: Create content with UTF-8 characters and emoji.
		$source_site = 'https://example.com';
		$content     = '<p>Testing UTF-8: 你好世界 🌍</p>';

		// ACT: Process UTF-8 content.
		$processed_content = $this->api->process_and_import_media( $content, $source_site );

		// ASSERT: Verify UTF-8 characters preserved.
		$this->assertStringContainsString( '你好世界', $processed_content );
		$this->assertStringContainsString( '🌍', $processed_content );
	}

	/**
	 * Verifies that empty and whitespace-only content is handled without errors.
	 */
	public function test_empty_content_handled_gracefully(): void {
		// ARRANGE: Prepare edge case inputs.
		$source_site = 'https://example.com';

		// ACT & ASSERT: Test empty string.
		$processed = $this->api->process_and_import_media( '', $source_site );
		$this->assertSame( '', $processed, 'Empty string should return empty string' );

		// ACT & ASSERT: Test whitespace-only content.
		$processed = $this->api->process_and_import_media( '   ', $source_site );
		$this->assertNotNull( $processed, 'Whitespace content should not return null' );

		// ACT & ASSERT: Test newlines only.
		$processed = $this->api->process_and_import_media( "\n\n", $source_site );
		$this->assertNotNull( $processed, 'Newline content should not return null' );
	}

	/**
	 * Verifies that server 500 errors are handled gracefully.
	 *
	 * When the remote server returns a 500 error, the import should continue
	 * processing and preserve the original URL.
	 */
	public function test_import_handles_500_server_error(): void {
		// ARRANGE: Create content with image that returns 500 error (mocked in base class).
		$source_site        = 'https://example.com';
		$content            = '<p>Content with <img src="https://example.com/server-error.jpg" alt="Server Error"> failed image</p>';
		$attachments_before = $this->get_attachment_count();

		// ACT: Process content with server error.
		$processed_content = $this->api->process_and_import_media( $content, $source_site );

		// ASSERT: Verify content processed despite server error.
		$this->assertNotEmpty( $processed_content );
		$this->assertStringContainsString( 'Content with', $processed_content );
		$this->assertStringContainsString( 'failed image', $processed_content );
		$this->assertStringContainsString( 'alt="Server Error"', $processed_content );

		// ASSERT: Verify original URL preserved when server errors.
		$this->assertStringContainsString(
			'https://example.com/server-error.jpg',
			$processed_content,
			'Original URL should be preserved when server returns 500 error'
		);

		// ASSERT: Verify no attachment created for server error.
		$this->assert_no_new_attachments( $attachments_before, 'Should not create attachment when server returns 500' );
	}

	/**
	 * Verifies that WP_Error from network failures is handled gracefully.
	 *
	 * When wp_remote_get() returns a WP_Error (network timeout, DNS failure,
	 * SSL error, etc.), the import should continue processing and preserve
	 * the original URL.
	 */
	public function test_import_handles_wp_error_from_network_failure(): void {
		// ARRANGE: Create content with image URL.
		$source_site        = 'https://example.com';
		$content            = '<p>Content with <img src="https://example.com/network-timeout.jpg" alt="Network Error"> failed image</p>';
		$attachments_before = $this->get_attachment_count();

		// Mock network failure that returns WP_Error.
		$filter_callback = static function ( $preempt, $args, $url ) {
			if ( str_contains( $url, 'network-timeout.jpg' ) ) {
				return new \WP_Error(
					'http_request_failed',
					'Operation timed out after 30000 milliseconds'
				);
			}
			return $preempt;
		};

		add_filter( 'pre_http_request', $filter_callback, 11, 3 );

		try {
			// ACT: Process content with network failure.
			$processed_content = $this->api->process_and_import_media( $content, $source_site );

			// ASSERT: Verify content processed despite WP_Error.
			$this->assertNotEmpty( $processed_content );
			$this->assertStringContainsString( 'Content with', $processed_content );
			$this->assertStringContainsString( 'failed image', $processed_content );
			$this->assertStringContainsString( 'alt="Network Error"', $processed_content );

			// ASSERT: Verify original URL preserved when network fails.
			$this->assertStringContainsString(
				'https://example.com/network-timeout.jpg',
				$processed_content,
				'Original URL should be preserved when network request returns WP_Error'
			);

			// ASSERT: Verify no attachment created for network error.
			$this->assert_no_new_attachments( $attachments_before, 'Should not create attachment when network request fails' );
		} finally {
			// Cleanup: Always remove filter, even if test fails.
			remove_filter( 'pre_http_request', $filter_callback, 11 );
		}
	}

	/**
	 * Verifies that invalid image data is handled gracefully.
	 *
	 * When media_handle_sideload() encounters an error (e.g., exceeds upload
	 * limits, fails validation), the import should preserve the original URL
	 * without crashing.
	 */
	public function test_import_handles_invalid_image_data(): void {
		// ARRANGE: Create content with image URL.
		$source_site        = 'https://example.com';
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
			$processed_content = $this->api->process_and_import_media( $content, $source_site );

			// ASSERT: Verify content processed despite sideload failure.
			$this->assertNotEmpty( $processed_content );
			$this->assertStringContainsString( 'Content with', $processed_content );
			$this->assertStringContainsString( 'broken image', $processed_content );
			$this->assertStringContainsString( 'alt="Invalid Data"', $processed_content );

			// ASSERT: Verify original URL preserved when sideload fails.
			$this->assertStringContainsString(
				'https://example.com/trigger-sideload-error.jpg',
				$processed_content,
				'Original URL should be preserved when media_handle_sideload fails'
			);

			// ASSERT: Verify no attachment created for failed sideload.
			$this->assert_no_new_attachments( $attachments_before, 'Should not create attachment when sideload fails' );
		} finally {
			// Cleanup: Always remove filters, even if test fails.
			remove_filter( 'pre_http_request', $http_callback, 11 );
			remove_filter( 'wp_handle_sideload_prefilter', $sideload_callback, 999 );
		}
	}
}
