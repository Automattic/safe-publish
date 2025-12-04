<?php
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

	private HTTP_Client $http_client;

	protected function setUp(): void {
		parent::setUp();
		$this->http_client = new HTTP_Client();
	}

	public function test_http_client_initializes(): void {
		$this->assertInstanceOf( HTTP_Client::class, $this->http_client );
	}

	public function test_get_user_agent_returns_string(): void {
		$user_agent = $this->http_client->get_user_agent();

		$this->assertIsString( $user_agent );
		$this->assertStringContainsString( 'Compliant Content Publisher', $user_agent );
	}

	public function test_should_verify_ssl_returns_bool(): void {
		$url = 'https://example.com';
		$result = $this->http_client->should_verify_ssl( $url );

		$this->assertIsBool( $result );
	}

	public function test_should_verify_ssl_returns_false_for_localhost(): void {
		$url = 'http://localhost';
		$result = $this->http_client->should_verify_ssl( $url );

		$this->assertFalse( $result );
	}

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

	public function test_is_development_environment_returns_bool(): void {
		$result = $this->http_client->is_development_environment();

		$this->assertIsBool( $result );
	}

	public function test_get_fallback_auth_credentials_returns_array(): void {
		$credentials = $this->http_client->get_fallback_auth_credentials();

		$this->assertIsArray( $credentials );
	}

	public function test_get_fallback_auth_credentials_returns_provided_credentials(): void {
		$provided = array(
			'shared_secret' => 'test_secret',
		);

		$credentials = $this->http_client->get_fallback_auth_credentials( $provided );

		$this->assertEquals( $provided, $credentials );
	}

	public function test_cleanup_temp_file_handles_non_existent_file(): void {
		// Should not throw exception for non-existent file
		$this->http_client->cleanup_temp_file( '/tmp/non_existent_file.txt' );

		$this->assertTrue( true ); // If we reach here, no exception was thrown
	}

	public function test_cleanup_temp_file_handles_empty_string(): void {
		// Should not throw exception for empty string
		$this->http_client->cleanup_temp_file( '' );

		$this->assertTrue( true );
	}
}
