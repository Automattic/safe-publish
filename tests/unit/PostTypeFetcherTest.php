<?php
/**
 * Post Type Fetcher Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Post_Type_Fetcher;

/**
 * Post Type Fetcher Test.
 *
 * Tests URL validation and basic behavior of the Post_Type_Fetcher class.
 */
class PostTypeFetcherTest extends TestCase {

	/**
	 * Post Type Fetcher instance.
	 *
	 * @var Post_Type_Fetcher
	 */
	private Post_Type_Fetcher $fetcher;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->fetcher = new Post_Type_Fetcher( new HTTP_Client() );
	}

	/**
	 * Verifies that fetch_post_types returns an error for invalid URLs.
	 */
	public function test_fetch_post_types_with_invalid_url_returns_error(): void {
		$result = $this->fetcher->fetch_post_types( 'invalid-url' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Verifies that fetch_post_types returns an error for empty URLs.
	 */
	public function test_fetch_post_types_with_empty_url_returns_error(): void {
		$result = $this->fetcher->fetch_post_types( '' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}
}
