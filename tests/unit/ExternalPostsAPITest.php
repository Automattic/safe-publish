<?php
/**
 * External Posts API Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\External_Posts_API;

/**
 * External Posts API Test.
 *
 * Tests the external API integration functionality.
 */
class ExternalPostsAPITest extends TestCase {

	/**
	 * @var External_Posts_API External Posts API instance for testing.
	 */
	private External_Posts_API $api;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->api = new External_Posts_API();
	}

	/**
	 * Verifies that the External Posts API initializes correctly.
	 */
	public function test_api_initializes(): void {
		$this->assertInstanceOf( External_Posts_API::class, $this->api );
	}

	/**
	 * Verifies that fetch_posts returns an error for invalid URLs.
	 */
	public function test_fetch_posts_with_invalid_url_returns_error(): void {
		$result = $this->api->fetch_posts( 'invalid-url', 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Verifies that fetch_posts returns an error for empty URLs.
	 */
	public function test_fetch_posts_with_empty_url_returns_error(): void {
		$result = $this->api->fetch_posts( '', 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Verifies that test_connection returns an array structure.
	 */
	public function test_test_connection_returns_array(): void {
		// This will fail to connect but should return proper array structure.
		$result = $this->api->test_connection( 'https://example.com', array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'response_time', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Verifies that fetch_fresh_post_content returns false for invalid URLs.
	 */
	public function test_fetch_fresh_post_content_with_invalid_url_returns_false(): void {
		$result = $this->api->fetch_fresh_post_content( 123, 'invalid-url' );

		$this->assertFalse( $result );
	}
}
