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
 * Tests URL validation, sanitization, and site-URL normalization.
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
	 * Verifies that URLs whose scheme isn't http/https are rejected so
	 * the validator can't accidentally pass through ftp://, file://,
	 * gopher://, or similar schemes the plugin has no business fetching.
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
	 * HTTP:// and HTTPS:// are equivalent to the lowercase forms and
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

	/**
	 * Verifies that an explicit non-default port survives normalization so
	 * REST endpoints built on the result hit the right service.
	 */
	public function test_normalize_site_url_preserves_non_default_port(): void {
		// ARRANGE: A URL with an explicit non-default port and a subsite path.
		$url = 'http://example.com:8889/blog/some-post/';

		// ACT: Reduce to the site identity.
		$identity = URL_Validator::normalize_site_url( $url );

		// ASSERT: The port stays on the identity.
		$this->assertSame( 'http://example.com:8889', $identity );
	}

	/**
	 * Verifies that no spurious colon is appended when the URL has no
	 * explicit port.
	 */
	public function test_normalize_site_url_omits_port_when_url_has_none(): void {
		// ARRANGE: A URL on the default port.
		$url = 'https://example.com/2024/06/some-post/';

		// ACT: Reduce to the site identity.
		$identity = URL_Validator::normalize_site_url( $url );

		// ASSERT: Scheme and host only.
		$this->assertSame( 'https://example.com', $identity );
	}

	/**
	 * Verifies that path, query, and fragment are stripped so only the site
	 * identity remains.
	 */
	public function test_normalize_site_url_strips_path_query_and_fragment(): void {
		// ARRANGE: A URL with path, query, and fragment.
		$url = 'https://example.com:8443/path/to/post?foo=1#section';

		// ACT: Reduce to the site identity.
		$identity = URL_Validator::normalize_site_url( $url );

		// ASSERT: Only scheme + host + port remains.
		$this->assertSame( 'https://example.com:8443', $identity );
	}

	/**
	 * Verifies that empty input returns an empty string so callers can pass
	 * source links through unguarded.
	 */
	public function test_normalize_site_url_returns_empty_for_empty_input(): void {
		// ARRANGE: Empty input.
		$url = '';

		// ACT: Reduce to the site identity.
		$identity = URL_Validator::normalize_site_url( $url );

		// ASSERT: Empty string out.
		$this->assertSame( '', $identity );
	}

	/**
	 * Verifies that input with no parseable scheme and host returns an empty
	 * string.
	 */
	public function test_normalize_site_url_returns_empty_for_unparseable_input(): void {
		// ARRANGE: A bare path with no scheme or host.
		$url = '/blog/some-post';

		// ACT: Reduce to the site identity.
		$identity = URL_Validator::normalize_site_url( $url );

		// ASSERT: Empty string out.
		$this->assertSame( '', $identity );
	}

	/**
	 * Verifies that the subsite path is preserved on the identity so two
	 * subsites of one host scope to distinct values.
	 */
	public function test_normalize_site_url_with_path_keeps_subsite_path(): void {
		// ARRANGE: A connection URL pointing at a subdirectory subsite.
		$url = 'https://example.com/blog';

		// ACT: Reduce to the path-bearing identity.
		$identity = URL_Validator::normalize_site_url_with_path( $url );

		// ASSERT: Scheme, host, and path remain.
		$this->assertSame( 'https://example.com/blog', $identity );
	}

	/**
	 * Verifies that a trailing slash on the path is stripped so a connection
	 * stored with or without it yields the same identity.
	 */
	public function test_normalize_site_url_with_path_strips_trailing_slash(): void {
		// ARRANGE: A subsite connection URL with a trailing slash.
		$url = 'https://example.com/blog/';

		// ACT: Reduce to the path-bearing identity.
		$identity = URL_Validator::normalize_site_url_with_path( $url );

		// ASSERT: The trailing slash is gone.
		$this->assertSame( 'https://example.com/blog', $identity );
	}

	/**
	 * Verifies that an explicit non-default port survives alongside the path.
	 */
	public function test_normalize_site_url_with_path_preserves_port(): void {
		// ARRANGE: A subsite connection on a non-default port.
		$url = 'http://example.com:8889/blog/';

		// ACT: Reduce to the path-bearing identity.
		$identity = URL_Validator::normalize_site_url_with_path( $url );

		// ASSERT: Port and path both remain.
		$this->assertSame( 'http://example.com:8889/blog', $identity );
	}

	/**
	 * Verifies that query and fragment are dropped while the path is kept.
	 */
	public function test_normalize_site_url_with_path_drops_query_and_fragment(): void {
		// ARRANGE: A subsite URL carrying a query and fragment.
		$url = 'https://example.com/blog?foo=1#section';

		// ACT: Reduce to the path-bearing identity.
		$identity = URL_Validator::normalize_site_url_with_path( $url );

		// ASSERT: Only scheme, host, and path remain.
		$this->assertSame( 'https://example.com/blog', $identity );
	}

	/**
	 * Verifies that a path-less URL yields the host-only identity, so plain
	 * single-site connections stay unaffected by the change.
	 */
	public function test_normalize_site_url_with_path_returns_host_only_when_no_path(): void {
		// ARRANGE: A host-only connection, with and without a trailing slash.
		$bare    = 'https://example.com';
		$trailed = 'https://example.com/';

		// ACT: Reduce both to the path-bearing identity.
		$bare_identity    = URL_Validator::normalize_site_url_with_path( $bare );
		$trailed_identity = URL_Validator::normalize_site_url_with_path( $trailed );

		// ASSERT: Both collapse to the host-only identity.
		$this->assertSame( 'https://example.com', $bare_identity );
		$this->assertSame( 'https://example.com', $trailed_identity );
	}

	/**
	 * Verifies that empty input returns an empty string so callers can pass
	 * an unconfigured connection through unguarded.
	 */
	public function test_normalize_site_url_with_path_returns_empty_for_empty_input(): void {
		// ARRANGE: Empty input.
		$url = '';

		// ACT: Reduce to the path-bearing identity.
		$identity = URL_Validator::normalize_site_url_with_path( $url );

		// ASSERT: Empty string out.
		$this->assertSame( '', $identity );
	}

	/**
	 * Verifies that input with no parseable scheme and host returns an empty
	 * string.
	 */
	public function test_normalize_site_url_with_path_returns_empty_for_unparseable_input(): void {
		// ARRANGE: A bare path with no scheme or host.
		$url = '/blog/some-post';

		// ACT: Reduce to the path-bearing identity.
		$identity = URL_Validator::normalize_site_url_with_path( $url );

		// ASSERT: Empty string out.
		$this->assertSame( '', $identity );
	}
}
