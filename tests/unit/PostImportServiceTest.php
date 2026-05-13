<?php
/**
 * Post Import Service Test
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Safe_Publish\Admin\Post_Import_Service;

/**
 * Post Import Service Test.
 *
 * Exercises pure URL-parsing helpers on Post_Import_Service that don't
 * depend on the service's collaborators. The method under test is
 * private; the service is built without invoking its constructor so the
 * full dependency graph doesn't need to be mocked.
 */
class PostImportServiceTest extends TestCase {

	/**
	 * Invokes the private extract_site_url method.
	 *
	 * @param string $url Full URL to extract the base from.
	 * @return string Site base URL as returned by the method.
	 */
	private function extract_site_url( string $url ): string {
		$reflection = new ReflectionClass( Post_Import_Service::class );
		$method     = $reflection->getMethod( 'extract_site_url' );
		$instance   = $reflection->newInstanceWithoutConstructor();

		return (string) $method->invoke( $instance, $url );
	}

	/**
	 * Verifies that an explicit non-default port survives the extraction so
	 * REST endpoints built on top of the returned base hit the right
	 * service.
	 */
	public function test_extract_site_url_preserves_non_default_port(): void {
		// ARRANGE: a URL with an explicit non-default port.
		$url = 'http://host.docker.internal:8889/blog/some-post/';

		// ACT: derive the site base.
		$base = $this->extract_site_url( $url );

		// ASSERT: the port stays on the site base.
		$this->assertSame( 'http://host.docker.internal:8889', $base );
	}

	/**
	 * Verifies that no spurious colon is appended when the source URL has
	 * no explicit port.
	 */
	public function test_extract_site_url_omits_port_when_url_has_none(): void {
		// ARRANGE: a URL on the default port.
		$url = 'https://example.com/2024/06/some-post/';

		// ACT: derive the site base.
		$base = $this->extract_site_url( $url );

		// ASSERT: scheme and host only.
		$this->assertSame( 'https://example.com', $base );
	}

	/**
	 * Verifies that path, query, and fragment are stripped so only the
	 * site root is returned.
	 */
	public function test_extract_site_url_strips_path_query_and_fragment(): void {
		// ARRANGE: a URL with path, query, and fragment.
		$url = 'https://example.com:8443/path/to/post?foo=1#section';

		// ACT: derive the site base.
		$base = $this->extract_site_url( $url );

		// ASSERT: only scheme + host + port remains.
		$this->assertSame( 'https://example.com:8443', $base );
	}
}
