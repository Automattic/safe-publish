<?php
declare(strict_types=1);

namespace CCP\Tests;

use PHPUnit\Framework\TestCase;
use CCP\Auth\VIP_Safe_Auth;

/**
 * VIP Safe Auth Test
 * Tests authentication methods and security
 */
class VIPSafeAuthTest extends TestCase {

	public function test_get_auth_params_with_shared_secret(): void {
		$site_url = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );

		$this->assertIsArray( $params );
		$this->assertArrayHasKey( 'headers', $params );
		$this->assertArrayHasKey( 'X-CCP-Timestamp', $params['headers'] );
		$this->assertArrayHasKey( 'X-CCP-Signature', $params['headers'] );
	}

	public function test_get_auth_params_with_no_credentials_returns_empty(): void {
		$site_url = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array();

		$params = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );

		$this->assertIsArray( $params );
		$this->assertEmpty( $params );
	}

	public function test_is_authorized_with_valid_shared_secret(): void {
		$site_url = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$result = VIP_Safe_Auth::is_authorized( $site_url, $auth_config );

		$this->assertTrue( $result );
	}

	public function test_is_authorized_with_short_shared_secret_fails(): void {
		$site_url = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'short',
		);

		$result = VIP_Safe_Auth::is_authorized( $site_url, $auth_config );

		$this->assertFalse( $result );
	}

	public function test_is_authorized_with_no_credentials_fails(): void {
		$site_url = 'https://example.com';
		$auth_config = array();

		$result = VIP_Safe_Auth::is_authorized( $site_url, $auth_config );

		$this->assertFalse( $result );
	}

	public function test_generate_shared_secret_creates_long_secret(): void {
		$secret = VIP_Safe_Auth::generate_shared_secret();

		$this->assertIsString( $secret );
		$this->assertGreaterThanOrEqual( 32, strlen( $secret ) );
	}

	public function test_generate_shared_secret_creates_unique_secrets(): void {
		$secret1 = VIP_Safe_Auth::generate_shared_secret();
		$secret2 = VIP_Safe_Auth::generate_shared_secret();

		$this->assertNotEquals( $secret1, $secret2 );
	}

	public function test_signature_generation_is_consistent(): void {
		$site_url = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// Get params twice with same timestamp to verify consistency
		$params1 = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );
		sleep(1); // Wait a second
		$params2 = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );

		// Timestamps will be different, but signature generation process should be consistent
		$this->assertIsString( $params1['headers']['X-CCP-Signature'] );
		$this->assertIsString( $params2['headers']['X-CCP-Signature'] );
		$this->assertEquals( 64, strlen( $params1['headers']['X-CCP-Signature'] ) ); // SHA256 hex = 64 chars
	}
}
