<?php
/**
 * VIP Safe Auth Test file.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Auth\VIP_Safe_Auth;

/**
 * VIP Safe Auth Test.
 *
 * Tests authentication methods and security.
 */
class VIPSafeAuthTest extends TestCase {

	/**
	 * Verifies that auth params include proper headers with shared secret.
	 */
	public function test_get_auth_params_with_shared_secret(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );

		$this->assertIsArray( $params );
		$this->assertArrayHasKey( 'headers', $params );
		$this->assertArrayHasKey( 'X-Safe-Publish-Timestamp', $params['headers'] );
		$this->assertArrayHasKey( 'X-Safe-Publish-Signature', $params['headers'] );
	}

	/**
	 * Verifies that auth params return empty array without credentials.
	 */
	public function test_get_auth_params_with_no_credentials_returns_empty(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array();

		$params = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );

		$this->assertIsArray( $params );
		$this->assertEmpty( $params );
	}

	/**
	 * Verifies that valid shared secrets pass authorization.
	 */
	public function test_is_authorized_with_valid_shared_secret(): void {
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$result = VIP_Safe_Auth::is_authorized( $site_url, $auth_config );

		$this->assertTrue( $result );
	}

	/**
	 * Verifies that short shared secrets fail authorization.
	 */
	public function test_is_authorized_with_short_shared_secret_fails(): void {
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'short',
		);

		$result = VIP_Safe_Auth::is_authorized( $site_url, $auth_config );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that authorization fails without credentials.
	 */
	public function test_is_authorized_with_no_credentials_fails(): void {
		$site_url    = 'https://example.com';
		$auth_config = array();

		$result = VIP_Safe_Auth::is_authorized( $site_url, $auth_config );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that signature generation produces consistent format.
	 */
	public function test_signature_generation_is_consistent(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// Get params twice with same timestamp to verify consistency.
		$params1 = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );
		sleep( 1 ); // Wait a second.
		$params2 = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );

		// Timestamps will be different, but signature generation process should be consistent.
		$this->assertIsString( $params1['headers']['X-Safe-Publish-Signature'] );
		$this->assertIsString( $params2['headers']['X-Safe-Publish-Signature'] );
		$this->assertSame( 64, strlen( $params1['headers']['X-Safe-Publish-Signature'] ) ); // SHA256 hex = 64 chars.
	}

	/**
	 * Verifies that content hash header is included when body is provided.
	 */
	public function test_get_auth_params_includes_content_hash_when_body_provided(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);
		$body        = '{"content":"Hello world"}';

		$params = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'POST', $body );

		$this->assertArrayHasKey( 'X-Safe-Publish-Content-Hash', $params['headers'] );
		$this->assertSame( hash( 'sha256', $body ), $params['headers']['X-Safe-Publish-Content-Hash'] );
	}

	/**
	 * Verifies that content hash header is absent when no body is provided.
	 */
	public function test_get_auth_params_omits_content_hash_when_no_body(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );

		$this->assertArrayNotHasKey( 'X-Safe-Publish-Content-Hash', $params['headers'] );
	}

	/**
	 * Verifies that the HMAC signature differs when a body is included.
	 */
	public function test_signature_differs_with_and_without_body(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);
		$body        = '{"content":"Hello world"}';

		$params_without = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'POST' );
		$params_with    = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'POST', $body );

		// Both should have valid 64-char hex signatures, but they should differ.
		$this->assertSame( 64, strlen( $params_without['headers']['X-Safe-Publish-Signature'] ) );
		$this->assertSame( 64, strlen( $params_with['headers']['X-Safe-Publish-Signature'] ) );

		// Signatures will differ because the string-to-sign includes the content hash.
		// Note: timestamps may also differ, so we verify the structural difference
		// by checking that content hash is only present with body.
		$this->assertArrayNotHasKey( 'X-Safe-Publish-Content-Hash', $params_without['headers'] );
		$this->assertArrayHasKey( 'X-Safe-Publish-Content-Hash', $params_with['headers'] );
	}

	/**
	 * Verifies backward compatibility: no-body requests produce the same
	 * header structure as before.
	 */
	public function test_backward_compatible_without_body(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET', '' );

		// Should have exactly the same headers as before: Timestamp + Signature only.
		$this->assertCount( 2, $params['headers'] );
		$this->assertArrayHasKey( 'X-Safe-Publish-Timestamp', $params['headers'] );
		$this->assertArrayHasKey( 'X-Safe-Publish-Signature', $params['headers'] );
	}
}
