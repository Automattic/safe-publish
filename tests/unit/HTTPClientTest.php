<?php
/**
 * HTTP Client Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Request_Actions;

/**
 * HTTP Client Test.
 *
 * Tests HTTP client functionality and VIP compatibility.
 */
class HTTPClientTest extends TestCase {

	/**
	 * @var HTTP_Client HTTP client instance for testing.
	 */
	private HTTP_Client $http_client;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->http_client = new HTTP_Client();
	}

	/**
	 * Verifies that the HTTP client initializes correctly.
	 */
	public function test_http_client_initializes(): void {
		$this->assertInstanceOf( HTTP_Client::class, $this->http_client );
	}

	/**
	 * Resets the HTTP response stub between tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		reset_test_http_response();
		parent::tearDown();
	}

	/**
	 * Verifies that make_request forwards the response-size cap to the
	 * transport.
	 */
	public function test_make_request_bounds_response_size(): void {
		// ARRANGE: Stub a successful response so the request completes.
		set_test_http_response( array( 'response' => array( 'code' => 200 ) ) );

		// ACT: Issue a request through the shared client.
		$this->http_client->make_request(
			'https://example.com/wp-json/safe-publish/v1/catalog/posts',
			Request_Actions::LIST_ITEMS
		);

		// ASSERT: The transport received the response-size cap.
		$this->assertArrayHasKey(
			'limit_response_size',
			$GLOBALS['_test_http_last_args']
		);
		$this->assertSame(
			HTTP_Client::MAX_RESPONSE_BYTES,
			$GLOBALS['_test_http_last_args']['limit_response_size']
		);
	}

	/**
	 * Verifies that get_user_agent returns a string.
	 */
	public function test_get_user_agent_returns_string(): void {
		$user_agent = $this->http_client->get_user_agent();

		$this->assertIsString( $user_agent );
		$this->assertStringContainsString( 'Safe Publish', $user_agent );
	}

	/**
	 * Verifies that parse_destination_site_url extracts the URL from a standard
	 * Safe Publish User-Agent string.
	 */
	public function test_parse_destination_site_url_extracts_url_from_user_agent(): void {
		$result = HTTP_Client::parse_destination_site_url(
			'Safe Publish/1.2.3; https://dest.example.com'
		);

		$this->assertSame( 'https://dest.example.com', $result );
	}

	/**
	 * Verifies that parse_destination_site_url returns an empty string for an
	 * absent User-Agent header.
	 */
	public function test_parse_destination_site_url_returns_empty_string_for_missing_header(): void {
		$this->assertSame( '', HTTP_Client::parse_destination_site_url( '' ) );
	}

	/**
	 * Verifies that parse_destination_site_url returns the raw value when the
	 * User-Agent does not match the expected format.
	 */
	public function test_parse_destination_site_url_returns_raw_value_for_unknown_format(): void {
		$result = HTTP_Client::parse_destination_site_url( 'curl/7.88.0' );

		$this->assertSame( 'curl/7.88.0', $result );
	}

	/**
	 * Verifies that should_verify_ssl returns a boolean.
	 */
	public function test_should_verify_ssl_returns_bool(): void {
		$url    = 'https://example.com';
		$result = $this->http_client->should_verify_ssl( $url );

		$this->assertIsBool( $result );
	}

	/**
	 * Verifies that should_verify_ssl returns false for localhost.
	 */
	public function test_should_verify_ssl_returns_false_for_localhost(): void {
		$url    = 'http://localhost';
		$result = $this->http_client->should_verify_ssl( $url );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that should_verify_ssl returns false for local domains.
	 */
	public function test_should_verify_ssl_returns_false_for_local_domains(): void {
		$test_urls = array(
			'http://example.local',
			'http://example.test',
			'http://example.dev',
			'http://127.0.0.1',
		);

		foreach ( $test_urls as $url ) {
			$result = $this->http_client->should_verify_ssl( $url );
			$this->assertFalse( $result, "Failed for URL: $url" );
		}
	}

	/**
	 * Verifies that cleanup_temp_file handles non-existent files gracefully.
	 */
	public function test_cleanup_temp_file_handles_non_existent_file(): void {
		// Should not throw exception for non-existent file.
		$this->http_client->cleanup_temp_file( '/tmp/non_existent_file.txt' );

		$this->assertTrue( true ); // If we reach here, no exception was thrown.
	}

	/**
	 * Verifies that cleanup_temp_file handles empty strings gracefully.
	 */
	public function test_cleanup_temp_file_handles_empty_string(): void {
		// Should not throw exception for empty string.
		$this->http_client->cleanup_temp_file( '' );

		$this->assertTrue( true );
	}
}
