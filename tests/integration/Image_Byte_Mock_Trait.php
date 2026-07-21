<?php
/**
 * HTTP image-byte mocking for integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use WP_Error;

/**
 * Serves fixture image bytes in place of live downloads.
 *
 * Intercepts wp_remote_get() for image URLs and feeds real fixture files from
 * tests/fixtures/images/ through the sideload pipeline. Carries no TestCase
 * dependency, so non-test helpers (e.g. fixture builders) can reuse it.
 */
trait Image_Byte_Mock_Trait {

	/**
	 * MIME types served by mock_image_byte_response() keyed by file extension.
	 *
	 * @var array<string, array{file: string, mime: string}>
	 */
	private const IMAGE_FIXTURE_MAP = array(
		'jpg'  => array(
			'file' => 'test-1x1.jpg',
			'mime' => 'image/jpeg',
		),
		'jpeg' => array(
			'file' => 'test-1x1.jpg',
			'mime' => 'image/jpeg',
		),
		'png'  => array(
			'file' => 'test-1x1.png',
			'mime' => 'image/png',
		),
		'gif'  => array(
			'file' => 'test-1x1.gif',
			'mime' => 'image/gif',
		),
		'webp' => array(
			'file' => 'test-1x1.webp',
			'mime' => 'image/webp',
		),
	);

	/**
	 * Registers the pre_http_request filter that serves fixture bytes for
	 * image URLs and the wp_handle_sideload_prefilter that populates the empty
	 * temp files download_url() leaves behind.
	 *
	 * Tests that also mock REST endpoints (post or media) should register those
	 * mocks separately; this helper only wires the image-byte pipeline.
	 */
	protected function add_image_byte_response_mock(): void {
		add_filter(
			'pre_http_request',
			array( $this, 'mock_image_byte_response' ),
			1,
			3
		);
		add_filter(
			'wp_handle_sideload_prefilter',
			array( $this, 'fix_empty_temp_files' ),
			10,
			1
		);
	}

	/**
	 * Removes the filters registered by add_image_byte_response_mock().
	 */
	protected function remove_image_byte_response_mock(): void {
		remove_filter(
			'pre_http_request',
			array( $this, 'mock_image_byte_response' ),
			1
		);
		remove_filter(
			'wp_handle_sideload_prefilter',
			array( $this, 'fix_empty_temp_files' ),
			10
		);
	}

	/**
	 * Serves fixture bytes for any URL whose path has an image extension this
	 * trait knows about. URLs without a recognized image extension fall
	 * through so other pre_http_request filters can handle them.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $args    HTTP arguments.
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error
	 */
	public function mock_image_byte_response(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		if ( false !== $preempt ) {
			return $preempt;
		}

		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( ! isset( self::IMAGE_FIXTURE_MAP[ $extension ] ) ) {
			return $preempt;
		}

		$fixture  = self::IMAGE_FIXTURE_MAP[ $extension ];
		$response = $this->get_fixture_response( $fixture['file'], $fixture['mime'] );
		$this->populate_download_temp( $args, (string) $response['body'] );

		return $response;
	}

	/**
	 * Writes a mocked response body to the temp file download_url() streams
	 * into, so byte-level type detection sees real content at download time.
	 *
	 * When pre_http_request short-circuits, download_url() never writes the body
	 * to the target path it passes as the 'filename' request arg.
	 *
	 * @param array  $args HTTP request arguments passed to pre_http_request.
	 * @param string $body Response body to write.
	 */
	protected function populate_download_temp( array $args, string $body ): void {
		$filename = $args['filename'] ?? '';

		if ( ! is_string( $filename ) || '' === $filename ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		file_put_contents( $filename, $body );
		clearstatcache( true, $filename );
	}

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
}
