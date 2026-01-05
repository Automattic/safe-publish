<?php
/**
 * URL Validator Test file.
 *
 * @package CompliantContentPublisher
 */

declare(strict_types=1);

namespace CCP\Tests;

use PHPUnit\Framework\TestCase;
use CCP\Validators\URL_Validator;

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
		$this->assertEquals( $url, $sanitized );
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
	 * Verifies that VIP environments enforce HTTPS-only scheme.
	 */
	public function test_get_allowed_schemes_returns_https_for_vip(): void {
		// Mock VIP environment.
		if ( ! defined( 'WPCOM_IS_VIP_ENV' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			define( 'WPCOM_IS_VIP_ENV', true );
		}

		$schemes = URL_Validator::get_allowed_schemes();
		$this->assertEquals( array( 'https' ), $schemes );
	}

	/**
	 * Verifies that domain whitelisting returns true by default.
	 */
	public function test_is_domain_whitelisted_returns_true_by_default(): void {
		$url = 'https://example.com';
		$this->assertTrue( URL_Validator::is_domain_whitelisted( $url ) );
	}
}
