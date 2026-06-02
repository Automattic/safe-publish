<?php
/**
 * URL Validator Test file.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Validators\URL_Validator;

/**
 * URL Validator Test.
 *
 * Tests URL validation and sanitization.
 */
class URLValidatorTest extends TestCase {

	/**
	 * Verifies that valid HTTPS URLs pass validation.
	 */
	public function test_valid_https_url_passes_validation(): void {
		$url = 'https://example.com';
		$this->assertTrue( URL_Validator::is_valid_external_url( $url ) );
	}

	/**
	 * Verifies that valid HTTP URLs pass validation.
	 */
	public function test_valid_http_url_passes_validation(): void {
		$url = 'http://example.com';
		$this->assertTrue( URL_Validator::is_valid_external_url( $url ) );
	}

	/**
	 * Verifies that malformed URLs fail validation.
	 */
	public function test_invalid_url_fails_validation(): void {
		$url = 'not-a-url';
		$this->assertFalse( URL_Validator::is_valid_external_url( $url ) );
	}

	/**
	 * Verifies that empty URLs fail validation.
	 */
	public function test_empty_url_fails_validation(): void {
		$url = '';
		$this->assertFalse( URL_Validator::is_valid_external_url( $url ) );
	}

	/**
	 * Verifies that URLs with path components pass validation.
	 */
	public function test_url_with_path_passes_validation(): void {
		$url = 'https://example.com/path/to/resource';
		$this->assertTrue( URL_Validator::is_valid_external_url( $url ) );
	}

	/**
	 * Verifies that URLs with query parameters pass validation.
	 */
	public function test_url_with_query_string_passes_validation(): void {
		$url = 'https://example.com?param=value';
		$this->assertTrue( URL_Validator::is_valid_external_url( $url ) );
	}

	/**
	 * Verifies that URLs with port numbers pass validation.
	 */
	public function test_url_with_port_passes_validation(): void {
		$url = 'https://example.com:8080';
		$this->assertTrue( URL_Validator::is_valid_external_url( $url ) );
	}

	/**
	 * Verifies that valid URLs are properly sanitized.
	 */
	public function test_sanitize_external_url_returns_sanitized_url(): void {
		$url       = 'https://example.com';
		$sanitized = URL_Validator::sanitize_external_url( $url );
		$this->assertSame( $url, $sanitized );
	}

	/**
	 * Verifies that invalid URLs return false when sanitized.
	 */
	public function test_sanitize_external_url_returns_false_for_invalid_url(): void {
		$url       = 'not-a-url';
		$sanitized = URL_Validator::sanitize_external_url( $url );
		$this->assertFalse( $sanitized );
	}

	/**
	 * Verifies that URLs whose scheme isn't `http`/`https` are rejected so
	 * the validator can't accidentally pass through `ftp://`, `file://`,
	 * `gopher://`, or similar schemes the plugin has no business fetching.
	 *
	 * @dataProvider non_http_scheme_provider
	 *
	 * @param string $url URL expected to be rejected.
	 */
	public function test_non_http_scheme_fails_validation( string $url ): void {
		$this->assertFalse( URL_Validator::is_valid_external_url( $url ) );
	}

	/**
	 * Data provider for non-http(s) schemes that must be rejected.
	 *
	 * @return array<string, array{string}>
	 */
	public static function non_http_scheme_provider(): array {
		return array(
			'ftp'    => array( 'ftp://example.com' ),
			'file'   => array( 'file:///etc/passwd' ),
			'gopher' => array( 'gopher://example.com' ),
		);
	}

	/**
	 * Verifies that schemes are matched case-insensitively per RFC 3986 —
	 * `HTTP://` and `HTTPS://` are equivalent to the lowercase forms and
	 * must pass validation.
	 *
	 * @dataProvider uppercase_scheme_provider
	 *
	 * @param string $url URL expected to pass validation.
	 */
	public function test_uppercase_scheme_passes_validation( string $url ): void {
		$this->assertTrue( URL_Validator::is_valid_external_url( $url ) );
	}

	/**
	 * Data provider for uppercase http/https schemes.
	 *
	 * @return array<string, array{string}>
	 */
	public static function uppercase_scheme_provider(): array {
		return array(
			'HTTP'  => array( 'HTTP://example.com' ),
			'HTTPS' => array( 'HTTPS://example.com' ),
		);
	}

	/**
	 * Verifies that hosts that resolve into reserved address space — by
	 * literal loopback name or by IP literal in the loopback, RFC1918,
	 * link-local, or ULA ranges — are rejected so an admin-supplied URL
	 * cannot redirect plugin HTTP traffic at an internal address.
	 *
	 * @dataProvider private_host_provider
	 *
	 * @param string $url URL expected to be rejected.
	 */
	public function test_private_or_reserved_host_fails_validation( string $url ): void {
		$this->assertFalse( URL_Validator::is_valid_external_url( $url ) );
	}

	/**
	 * Data provider for hosts that must be rejected as private/reserved.
	 *
	 * @return array<string, array{string}>
	 */
	public static function private_host_provider(): array {
		return array(
			'localhost name'       => array( 'http://localhost' ),
			'localhost with port'  => array( 'http://localhost:8888' ),
			'ip6-localhost name'   => array( 'http://ip6-localhost' ),
			'ip6-loopback name'    => array( 'http://ip6-loopback' ),
			'IPv4 loopback'        => array( 'http://127.0.0.1' ),
			'IPv4 RFC1918 class A' => array( 'http://10.0.0.5' ),
			'IPv4 RFC1918 class B' => array( 'http://172.16.0.1' ),
			'IPv4 RFC1918 class C' => array( 'http://192.168.1.1' ),
			'IPv4 link-local'      => array( 'http://169.254.169.254' ),
			'IPv4 zero network'    => array( 'http://0.0.0.0' ),
			'IPv6 loopback'        => array( 'http://[::1]' ),
			'IPv6 ULA'             => array( 'http://[fd00::1]' ),
			'IPv6 link-local'      => array( 'http://[fe80::1]' ),
		);
	}
}
