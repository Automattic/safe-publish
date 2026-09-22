<?php
/**
 * Post Type Fetcher Test
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Post_Type_Fetcher;
use WP_Error;

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
	 * Resets the HTTP response stub between tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		reset_test_http_response();
		parent::tearDown();
	}

	/**
	 * Verifies that fetch_post_types returns an error for invalid URLs.
	 */
	public function test_fetch_post_types_with_invalid_url_returns_error(): void {
		$result = $this->fetcher->fetch_post_types( 'invalid-url' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Verifies that fetch_post_types returns an error for empty URLs.
	 */
	public function test_fetch_post_types_with_empty_url_returns_error(): void {
		$result = $this->fetcher->fetch_post_types( '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Verifies that an empty catalog carries the response snippet as
	 * structured source detail the client can isolate.
	 */
	public function test_empty_catalog_carries_structured_source_detail(): void {
		// ARRANGE: The source answers with an empty catalog.
		set_test_http_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '[]',
			)
		);

		// ACT: Fetch the post types.
		$result = $this->fetcher->fetch_post_types( 'https://example.com' );

		// ASSERT: Both halves of the sentence survive, and they compose it.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_post_types', $result->get_error_code() );
		$source_error = $result->get_error_data()['source_error'] ?? null;
		$this->assertSame(
			array(
				'message'  => '[]',
				'template' => 'No post types found. Response: <reason />',
			),
			$source_error
		);
		$this->assertSame(
			'No post types found. Response: []',
			$result->get_error_message()
		);
	}

	/**
	 * Verifies that an oversized response snippet is cut on a character
	 * boundary, never mid-sequence.
	 */
	public function test_long_response_snippet_stays_valid_utf8(): void {
		// ARRANGE: A non-JSON body whose multibyte run outlives the cap, with
		// a leading ASCII char so a byte-wise cut would split a sequence.
		set_test_http_response(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'x' . str_repeat( 'é', 400 ),
			)
		);

		// ACT: Fetch the post types.
		$result = $this->fetcher->fetch_post_types( 'https://example.com' );

		// ASSERT: The surfaced snippet is truncated and still valid UTF-8.
		$this->assertInstanceOf( WP_Error::class, $result );
		$snippet = $result->get_error_data()['source_error']['message'] ?? '';
		$this->assertStringEndsWith( '…', $snippet );
		$this->assertSame(
			1,
			preg_match( '//u', $snippet ),
			'Snippet must not end mid-sequence.'
		);
	}
}
