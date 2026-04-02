<?php
/**
 * Base class for External Posts API tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\External_Posts_API;

use Safe_Publish\API\HTTP_Client;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Embed_Processor;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\Integration_Test_Case;
use WP_Error;

/**
 * Base class for External Posts API Integration Tests.
 *
 * Provides shared setup, mocking, and helper methods for all External Posts API
 * test classes.
 */
abstract class External_Posts_API_Test_Base extends Integration_Test_Case {

	/**
	 * Metadata key for original URL.
	 */
	protected const META_ORIGINAL_URL = 'safe_publish_original_url';

	/**
	 * Metadata key for source site.
	 */
	protected const META_IMPORTED_FROM = 'safe_publish_imported_from';

	/**
	 * Content Media Processor instance.
	 *
	 * @var Content_Media_Processor
	 */
	protected Content_Media_Processor $content_processor;

	/**
	 * Media Importer instance.
	 *
	 * @var Media_Importer
	 */
	protected Media_Importer $media_importer;

	/**
	 * Sets up test environment.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		// Create service instances with real dependencies.
		$this->media_importer    = new Media_Importer( new HTTP_Client() );
		$this->content_processor = new Content_Media_Processor( $this->media_importer, new Embed_Processor() );

		// Mock HTTP requests to return test image data.
		add_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10, 3 );

		// Fix empty temp files created by download_url() before wp_handle_sideload() processes them.
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
	 * Fixes empty temp files by populating them with fixture content.
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
		$fixtures_dir = $this->get_fixtures_dir();
		$fixture_file = $fixtures_dir . '/' . $fixture_map[ $extension ];

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
	 * Mocks HTTP requests for media downloads.
	 *
	 * Serves real image files from tests/fixtures/images/ directory, allowing
	 * media_handle_sideload() to validate and process actual image data.
	 *
	 * @param false|array|WP_Error $preempt A preemptive return value.
	 * @param array                $args    HTTP request arguments.
	 * @param string               $url     The request URL.
	 * @return array|WP_Error Mock response or error.
	 */
	public function mock_http_request( $preempt, array $args, string $url ) {
		// explicitly unset $args, to resolve Psalm's PossiblyUnusedParam error
		// during the pre-commit check. A @psalm-suppress annotation doesn't
		// solve this.
		unset( $args );

		// Respect responses already set by higher-priority filters.
		if ( false !== $preempt ) {
			return $preempt;
		}

		// Only mock example.com URLs.
		if ( ! str_contains( $url, 'example.com' ) ) {
			return $preempt;
		}

		// Return 404 for URLs explicitly marked as nonexistent.
		if ( str_contains( $url, 'nonexistent' ) || str_contains( $url, '404' ) ) {
			return array(
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
				'body'     => 'Not Found',
				'headers'  => array(),
			);
		}

		// Return 500 for server error test URLs.
		if ( str_contains( $url, 'server-error' ) ) {
			return array(
				'response' => array(
					'code'    => 500,
					'message' => 'Internal Server Error',
				),
				'body'     => 'Internal Server Error',
				'headers'  => array(),
			);
		}

		// Determine content type from extension.
		$extension = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
		$extension = strtolower( $extension );

		// Return appropriate mock response based on file type.
		switch ( $extension ) {
			case 'jpg':
			case 'jpeg':
				return $this->get_fixture_response( 'test-1x1.jpg', 'image/jpeg' );
			case 'png':
				return $this->get_fixture_response( 'test-1x1.png', 'image/png' );
			case 'gif':
				return $this->get_fixture_response( 'test-1x1.gif', 'image/gif' );
			case 'webp':
				return $this->get_fixture_response( 'test-1x1.webp', 'image/webp' );
			case 'mp4':
				// For video/audio, still use mock data as we don't have fixtures.
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => 'MOCK_VIDEO_DATA',
					'headers'  => array( 'content-type' => 'video/mp4' ),
				);
			case 'mp3':
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => 'MOCK_AUDIO_DATA',
					'headers'  => array( 'content-type' => 'audio/mpeg' ),
				);
			default:
				// For unknown types, return success with minimal data.
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => 'MOCK_DATA',
					'headers'  => array(),
				);
		}
	}

	/**
	 * Gets the path to the fixtures directory.
	 *
	 * @return string Absolute path to fixtures/images directory.
	 */
	protected function get_fixtures_dir(): string {
		return dirname( __DIR__, 2 ) . '/fixtures/images';
	}

	/**
	 * Reads a fixture file and returns an HTTP response.
	 *
	 * @param string $filename   Fixture filename (e.g., 'test-1x1.jpg').
	 * @param string $mime_type  MIME type for the response.
	 * @return array HTTP response array.
	 */
	protected function get_fixture_response( string $filename, string $mime_type ): array {
		$filepath = $this->get_fixtures_dir() . '/' . $filename;

		// If fixture file doesn't exist, return error.
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

		// Read the actual file contents.
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

		$size = strlen( $file_contents );

		// Return successful response with real file data.
		// Format must match wp_remote_get() expectations.
		return array(
			'headers'       => array(
				'content-type'   => $mime_type,
				'content-length' => (string) $size,
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
	 * Gets total count of attachments in the database.
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
	 * Asserts that no new attachments were created.
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
