<?php
/**
 * Shared HTTP mocking utilities for media integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

/**
 * Provides reusable helpers for mocking HTTP media downloads in integration tests.
 *
 * Used across test classes that need to intercept wp_remote_get() calls and
 * serve real fixture files from tests/fixtures/images/ in place of live downloads.
 */
trait Mock_Media_HTTP_Trait {

	/**
	 * Fixes empty temp files created by download_url() before wp_handle_sideload()
	 * processes them.
	 *
	 * Since download_url() doesn't write mocked response bodies to temp files,
	 * this filter intercepts the file before sideload and populates it with
	 * fixture content.
	 *
	 * @param array $file File array with 'tmp_name' and 'name' keys.
	 * @return array Modified file array.
	 */
	public function fix_empty_temp_files( array $file ): array {
		$temp_path = $file['tmp_name'] ?? '';

		// Skip if file doesn't exist or already has content.
		if ( ! file_exists( $temp_path ) || filesize( $temp_path ) > 0 ) {
			return $file;
		}

		// Determine format from filename.
		$extension   = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$fixture_map = array(
			'heic' => 'test-1x1.jpg',
			'jpg'  => 'test-1x1.jpg',
			'jpeg' => 'test-1x1.jpg',
			'png'  => 'test-1x1.png',
			'gif'  => 'test-1x1.gif',
			'webp' => 'test-1x1.webp',
		);

		if ( ! isset( $fixture_map[ $extension ] ) ) {
			return $file;
		}

		// Copy fixture content to empty temp file.
		$fixture_file = $this->get_fixtures_dir() . '/' . $fixture_map[ $extension ];

		if ( file_exists( $fixture_file ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$content = file_get_contents( $fixture_file );
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			file_put_contents( $temp_path, $content );
			clearstatcache( true, $temp_path );
		}

		return $file;
	}

	/**
	 * Gets the path to the fixtures directory.
	 *
	 * @return string Absolute path to tests/fixtures/images.
	 */
	protected function get_fixtures_dir(): string {
		return dirname( __DIR__ ) . '/fixtures/images';
	}

	/**
	 * Reads a fixture file and returns a mock HTTP response array.
	 *
	 * @param string $filename  Fixture filename (e.g. 'test-1x1.jpg').
	 * @param string $mime_type MIME type for the Content-Type header.
	 * @return array HTTP response array compatible with pre_http_request.
	 */
	protected function get_fixture_response( string $filename, string $mime_type ): array {
		$filepath = $this->get_fixtures_dir() . '/' . $filename;

		if ( ! file_exists( $filepath ) ) {
			return array(
				'response' => array(
					'code'    => 500,
					'message' => 'Fixture Not Found',
				),
				'body'     => 'Test fixture file not found: ' . $filename,
				'headers'  => array(),
			);
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		$file_contents = file_get_contents( $filepath );

		if ( false === $file_contents ) {
			return array(
				'response' => array(
					'code'    => 500,
					'message' => 'File Read Error',
				),
				'body'     => 'Could not read fixture file: ' . $filename,
				'headers'  => array(),
			);
		}

		return array(
			'headers'       => array(
				'content-type'   => $mime_type,
				'content-length' => (string) strlen( $file_contents ),
			),
			'body'          => $file_contents,
			'response'      => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'       => array(),
			'filename'      => null,
			'http_response' => null,
		);
	}

	/**
	 * Gets the total count of attachments in the database.
	 *
	 * @return int Total attachment count.
	 */
	protected function get_attachment_count(): int {
		return count(
			get_posts(
				array(
					'post_type'      => 'attachment',
					'posts_per_page' => -1,
					'post_status'    => 'any',
				)
			)
		);
	}

	/**
	 * Asserts that no new attachments were created since a reference count.
	 *
	 * @param int    $expected_count Expected attachment count.
	 * @param string $message        Optional assertion message.
	 */
	protected function assert_no_new_attachments( int $expected_count, string $message = '' ): void {
		$actual_count = $this->get_attachment_count();
		$this->assertSame(
			$expected_count,
			$actual_count,
			'' !== $message ? $message : 'No new attachments should be created'
		);
	}
}
