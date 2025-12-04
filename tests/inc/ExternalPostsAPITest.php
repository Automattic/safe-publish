<?php
declare(strict_types=1);

namespace CCP\Tests;

use PHPUnit\Framework\TestCase;
use CCP\API\External_Posts_API;

/**
 * External Posts API Test.
 *
 * Tests the external API integration functionality.
 */
class ExternalPostsAPITest extends TestCase {

	private External_Posts_API $api;

	protected function setUp(): void {
		parent::setUp();
		$this->api = new External_Posts_API();
	}

	public function test_api_initializes(): void {
		$this->assertInstanceOf( External_Posts_API::class, $this->api );
	}

	public function test_fetch_posts_with_invalid_url_returns_error(): void {
		$result = $this->api->fetch_posts( 'invalid-url', 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_url', $result->get_error_code() );
	}

	public function test_fetch_posts_with_empty_url_returns_error(): void {
		$result = $this->api->fetch_posts( '', 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_url', $result->get_error_code() );
	}

	public function test_fetch_post_types_with_invalid_url_returns_error(): void {
		$result = $this->api->fetch_post_types( 'invalid-url' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_url', $result->get_error_code() );
	}

	public function test_fetch_post_types_with_empty_url_returns_error(): void {
		$result = $this->api->fetch_post_types( '' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_url', $result->get_error_code() );
	}

	public function test_test_connection_returns_array(): void {
		// This will fail to connect but should return proper array structure
		$result = $this->api->test_connection( 'https://example.com' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'response_time', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	public function test_get_attachment_id_from_url_returns_int(): void {
		$url = 'https://example.com/wp-content/uploads/2024/01/image.jpg';
		$result = $this->api->get_attachment_id_from_url( $url );

		$this->assertIsInt( $result );
	}

	public function test_add_webp_mime_type_adds_webp(): void {
		$mime_types = array(
			'jpg' => 'image/jpeg',
			'png' => 'image/png',
		);

		$result = $this->api->add_webp_mime_type( $mime_types );

		$this->assertArrayHasKey( 'webp', $result );
		$this->assertEquals( 'image/webp', $result['webp'] );
	}

	public function test_handle_webp_filetype_handles_webp(): void {
		$wp_check_filetype_and_ext = array(
			'ext' => false,
			'type' => false,
			'proper_filename' => false,
		);
		$file = '/tmp/test.webp';
		$filename = 'test.webp';

		$result = $this->api->handle_webp_filetype( $wp_check_filetype_and_ext, $file, $filename );

		$this->assertArrayHasKey( 'ext', $result );
		$this->assertArrayHasKey( 'type', $result );
		$this->assertEquals( 'webp', $result['ext'] );
		$this->assertEquals( 'image/webp', $result['type'] );
	}

	public function test_handle_webp_filetype_leaves_non_webp_unchanged(): void {
		$wp_check_filetype_and_ext = array(
			'ext' => 'jpg',
			'type' => 'image/jpeg',
			'proper_filename' => false,
		);
		$file = '/tmp/test.jpg';
		$filename = 'test.jpg';

		$result = $this->api->handle_webp_filetype( $wp_check_filetype_and_ext, $file, $filename );

		$this->assertEquals( $wp_check_filetype_and_ext, $result );
	}

	public function test_fetch_fresh_post_content_with_invalid_url_returns_false(): void {
		$result = $this->api->fetch_fresh_post_content( 123, 'invalid-url' );

		$this->assertFalse( $result );
	}

	public function test_import_featured_image_with_empty_id_returns_false(): void {
		$result = $this->api->import_featured_image( 0, 'https://example.com' );

		$this->assertFalse( $result );
	}

	public function test_import_featured_image_with_empty_site_url_returns_false(): void {
		$result = $this->api->import_featured_image( 123, '' );

		$this->assertFalse( $result );
	}
}
