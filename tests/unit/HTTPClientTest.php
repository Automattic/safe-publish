<?php
/**
 * HTTP Client Test.
 *
 * @package Compliant_Content_Publisher
 */

declare(strict_types=1);

namespace CCP\Tests;

use PHPUnit\Framework\TestCase;
use CCP\API\HTTP_Client;

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
	 * Verifies that get_user_agent returns a string.
	 */
	public function test_get_user_agent_returns_string(): void {
		$user_agent = $this->http_client->get_user_agent();

		$this->assertIsString( $user_agent );
		$this->assertStringContainsString( 'Compliant Content Publisher', $user_agent );
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
	 * Verifies that is_development_environment returns a boolean.
	 */
	public function test_is_development_environment_returns_bool(): void {
		$result = $this->http_client->is_development_environment();

		$this->assertIsBool( $result );
	}

	/**
	 * Verifies that get_fallback_auth_credentials returns an array.
	 */
	public function test_get_fallback_auth_credentials_returns_array(): void {
		$credentials = $this->http_client->get_fallback_auth_credentials();

		$this->assertIsArray( $credentials );
	}

	/**
	 * Verifies that get_fallback_auth_credentials returns provided credentials.
	 */
	public function test_get_fallback_auth_credentials_returns_provided_credentials(): void {
		$provided = array(
			'shared_secret' => 'test_secret',
		);

		$credentials = $this->http_client->get_fallback_auth_credentials( $provided );

		$this->assertEquals( $provided, $credentials );
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
