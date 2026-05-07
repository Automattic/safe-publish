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
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\Integration_Test_Case;
use Safe_Publish\Tests\Integration\Mock_Media_HTTP_Trait;
use Safe_Publish\Tests\Integration\Mock_Post_API_Trait;
use Safe_Publish\Utils\Options;
use WP_Error;

/**
 * Base class for External Posts API Integration Tests.
 *
 * Provides shared setup, mocking, and helper methods for all External Posts API
 * test classes.
 */
abstract class External_Posts_API_Test_Base extends Integration_Test_Case {

	use Mock_Media_HTTP_Trait;
	use Mock_Post_API_Trait;

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
	protected Content_Media_Processor $content_media_processor;

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
		$this->media_importer          = new Media_Importer( new HTTP_Client() );
		$this->content_media_processor = new Content_Media_Processor(
			$this->media_importer
		);

		// Configure the connected site URL so fetch_fresh_content() can make requests.
		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );

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
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
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
	 * @return false|array|WP_Error Mock response or error.
	 */
	public function mock_http_request(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
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

		// Handle single-post REST endpoint used by fetch_fresh_content().
		if ( preg_match( '#/wp-json/wp/v2/posts/\d+#', $url ) ) {
			return $this->build_mock_post_response();
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
}
