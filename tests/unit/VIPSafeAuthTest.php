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
		$this->assertArrayHasKey( 'X-Safe-Publish-Content-Hash', $params['headers'] );
		$this->assertArrayHasKey( 'X-Safe-Publish-Signature', $params['headers'] );
	}

	/**
	 * Verifies that Basic Auth headers are layered on top of Shared Secret headers.
	 */
	public function test_get_auth_params_layers_basic_auth_on_shared_secret(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
			'username'      => 'admin',
			'password'      => 'hunter2',
		);

		$params = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );

		$this->assertArrayHasKey( 'X-Safe-Publish-Signature', $params['headers'] );
		$this->assertArrayHasKey( 'Authorization', $params['headers'] );
		$this->assertStringStartsWith( 'Basic ', $params['headers']['Authorization'] );
		$this->assertSame( 'Basic ' . base64_encode( 'admin:hunter2' ), $params['headers']['Authorization'] );
	}

	/**
	 * Verifies that Basic Auth alone (no shared secret) returns empty params.
	 */
	public function test_get_auth_params_without_shared_secret_returns_empty(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'username' => 'admin',
			'password' => 'hunter2',
		);

		$params = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );

		$this->assertIsArray( $params );
		$this->assertEmpty( $params );
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
	 * Verifies that Basic Auth credentials alone (no shared secret) fail
	 * authorization.
	 */
	public function test_is_authorized_with_basic_auth_only_fails(): void {
		$site_url    = 'https://example.com';
		$auth_config = array(
			'username' => 'admin',
			'password' => 'hunter2',
		);

		$result = VIP_Safe_Auth::is_authorized( $site_url, $auth_config );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that exactly 16-character shared secret passes the minimum
	 * length check.
	 */
	public function test_is_authorized_with_exactly_16_char_secret_passes(): void {
		$auth_config = array(
			'shared_secret' => '1234567890abcdef', // Exactly 16 chars.
		);

		$result = VIP_Safe_Auth::is_authorized( '', $auth_config );

		$this->assertTrue( $result );
	}

	/**
	 * Verifies that a 15-character shared secret fails the minimum length
	 * check.
	 */
	public function test_is_authorized_with_15_char_secret_fails(): void {
		$auth_config = array(
			'shared_secret' => '1234567890abcde', // Exactly 15 chars.
		);

		$result = VIP_Safe_Auth::is_authorized( '', $auth_config );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that partial Basic Auth config (username only) does not add an
	 * Authorization header.
	 */
	public function test_get_auth_params_shared_secret_with_username_only_has_no_authorization_header(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
			'username'      => 'admin',
			// No password.
		);

		$params = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );

		$this->assertArrayHasKey( 'X-Safe-Publish-Signature', $params['headers'] );
		$this->assertArrayNotHasKey( 'Authorization', $params['headers'] );
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
	 * Verifies that authorization fails without credentials.
	 */
	public function test_is_authorized_with_no_credentials_fails(): void {
		$site_url    = 'https://example.com';
		$auth_config = array();

		$result = VIP_Safe_Auth::is_authorized( $site_url, $auth_config );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that content hash header is always included.
	 */
	public function test_get_auth_params_includes_content_hash(): void {
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
	 * Verifies that empty body still produces a content hash.
	 */
	public function test_get_auth_params_includes_content_hash_for_empty_body(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'GET' );

		$this->assertArrayHasKey( 'X-Safe-Publish-Content-Hash', $params['headers'] );
		$this->assertSame( hash( 'sha256', '' ), $params['headers']['X-Safe-Publish-Content-Hash'] );
	}

	/**
	 * Verifies that different bodies produce different content hashes.
	 */
	public function test_different_bodies_produce_different_hashes(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params_a = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'POST', 'body A' );
		$params_b = VIP_Safe_Auth::get_auth_params( $site_url, $auth_config, 'POST', 'body B' );

		$this->assertNotSame(
			$params_a['headers']['X-Safe-Publish-Content-Hash'],
			$params_b['headers']['X-Safe-Publish-Content-Hash']
		);
	}
}
