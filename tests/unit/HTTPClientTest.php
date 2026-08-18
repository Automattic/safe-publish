<?php
/**
 * HTTP Client Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Request_Actions;
use WP_Error;

/**
 * HTTP Client Test.
 *
 * Tests HTTP client functionality and VIP compatibility.
 */
class HTTPClientTest extends TestCase {

	/**
	 * @var HTTP_Client HTTP client instance for testing.
	 */
	private HTTP_Client $http_client;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->http_client = new HTTP_Client();
	}

	/**
	 * Verifies that the HTTP client initializes correctly.
	 */
	public function test_http_client_initializes(): void {
		$this->assertInstanceOf( HTTP_Client::class, $this->http_client );
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
	 * Verifies that make_request forwards the response-size cap to the
	 * transport.
	 */
	public function test_make_request_bounds_response_size(): void {
		// ARRANGE: Stub a successful response so the request completes.
		set_test_http_response( array( 'response' => array( 'code' => 200 ) ) );

		// ACT: Issue a request through the shared client.
		$this->http_client->make_request(
			'https://example.com/wp-json/safe-publish/v1/catalog/posts',
			Request_Actions::LIST_ITEMS
		);

		// ASSERT: The transport received the response-size cap.
		$this->assertArrayHasKey(
			'limit_response_size',
			$GLOBALS['_test_http_last_args']
		);
		$this->assertSame(
			HTTP_Client::MAX_RESPONSE_BYTES,
			$GLOBALS['_test_http_last_args']['limit_response_size']
		);
	}

	/**
	 * Verifies that make_request prefixes the transport error reported by
	 * WordPress with the source-site sentence.
	 */
	public function test_make_request_prefixes_transport_error(): void {
		// ARRANGE: Stub a transport failure, so no HTTP response arrives.
		$detail = 'cURL error 7: Failed to connect to source host.';
		set_test_http_response(
			new WP_Error( 'http_request_failed', $detail )
		);

		// ACT: Issue a catalog request through the shared client.
		$result = $this->make_catalog_request();

		// ASSERT: The prefix and the transport reason read as one sentence
		// pair, separated by a single space.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'request_failed', $result->get_error_code() );
		$this->assertSame(
			'Failed to fetch data from source site. ' . $detail,
			$result->get_error_message()
		);
	}

	/**
	 * Verifies that make_request appends the source site's REST error message
	 * to the WP_Error on a non-200 response.
	 */
	public function test_make_request_appends_source_error_message(): void {
		// ARRANGE: Stub a 400 carrying a WordPress REST error body.
		$detail = 'Requested post type is not available through the catalog.';
		$body   = wp_json_encode(
			array(
				'code'    => 'safe_publish_catalog_invalid_post_type',
				'message' => $detail,
				'data'    => array( 'status' => 400 ),
			)
		);
		$this->stub_http_error( 400, $body );

		// ACT: Issue a catalog request through the shared client.
		$result = $this->make_catalog_request();

		// ASSERT: The HTTP code and the source message read as one sentence
		// pair, separated by a single space.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'Source site returned HTTP error 400. ' . $detail,
			$result->get_error_message()
		);
	}

	/**
	 * Verifies that make_request preserves the source error code and status in
	 * the WP_Error data while keeping the http_error code.
	 */
	public function test_make_request_preserves_source_error_data(): void {
		// ARRANGE: Stub a 400 carrying a WordPress REST error body.
		$body = wp_json_encode(
			array(
				'code'    => 'safe_publish_catalog_invalid_date',
				'message' => 'Invalid date parameters.',
				'data'    => array( 'status' => 400 ),
			)
		);
		$this->stub_http_error( 400, $body );

		// ACT: Issue a catalog request through the shared client.
		$result = $this->make_catalog_request();

		// ASSERT: The source code and status ride along under http_error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'http_error', $result->get_error_code() );
		$this->assertSame(
			array(
				'source_code'   => 'safe_publish_catalog_invalid_date',
				'source_status' => 400,
			),
			$result->get_error_data()
		);
	}

	/**
	 * Verifies that make_request bounds an oversized source error message and
	 * marks the truncation with an ellipsis.
	 */
	public function test_make_request_truncates_long_source_error_message(): void {
		// ARRANGE: Stub a 400 whose message far exceeds the display cap.
		$body = wp_json_encode( array( 'message' => str_repeat( 'a', 500 ) ) );
		$this->stub_http_error( 400, $body );

		// ACT: Issue a catalog request through the shared client.
		$result = $this->make_catalog_request();

		// ASSERT: The appended detail is capped at 300 chars plus an ellipsis.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringEndsWith(
			str_repeat( 'a', 300 ) . '…',
			$result->get_error_message()
		);
	}

	/**
	 * Verifies that make_request truncates a multibyte source message on a
	 * character boundary, keeping the surfaced message valid UTF-8.
	 */
	public function test_make_request_truncates_multibyte_message_safely(): void {
		// ARRANGE: Stub a 400 whose multibyte message exceeds the cap; the
		// ASCII prefix offsets the byte boundary so a naive byte cut would
		// split a character.
		$body = wp_json_encode(
			array( 'message' => 'x' . str_repeat( '中', 400 ) )
		);
		$this->stub_http_error( 400, $body );

		// ACT: Issue a catalog request through the shared client.
		$result = $this->make_catalog_request();

		// ASSERT: The message is truncated yet remains valid UTF-8.
		$this->assertInstanceOf( WP_Error::class, $result );
		$error_message = $result->get_error_message();
		$this->assertStringEndsWith( '…', $error_message );
		$this->assertSame( 1, preg_match( '//u', $error_message ) );
	}

	/**
	 * Verifies that make_request returns the generic HTTP-error message, with
	 * no error data, when the source body is not a JSON REST error.
	 *
	 * @dataProvider unparseable_error_body_provider
	 *
	 * @param string $body Response body that must not yield a source message.
	 */
	public function test_make_request_returns_generic_message_for_unparseable_body(
		string $body
	): void {
		// ARRANGE: Stub a 500 with a body that carries no REST error message.
		$this->stub_http_error( 500, $body );

		// ACT: Issue a catalog request through the shared client.
		$result = $this->make_catalog_request();

		// ASSERT: The generic message stands and no error data is attached.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'Source site returned HTTP error 500.',
			$result->get_error_message()
		);
		$this->assertNull( $result->get_error_data() );
	}

	/**
	 * Data provider for bodies that must degrade to the generic HTTP error.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unparseable_error_body_provider(): array {
		return array(
			'empty'       => array( '' ),
			'html'        => array( '<html>Bad Gateway</html>' ),
			'json scalar' => array( '"a string"' ),
			'json object' => array( '{"foo":"bar"}' ),
			'json array'  => array( '[1,2,3]' ),
		);
	}

	/**
	 * Verifies that get_user_agent returns a string.
	 */
	public function test_get_user_agent_returns_string(): void {
		$user_agent = $this->http_client->get_user_agent();

		$this->assertIsString( $user_agent );
		$this->assertStringContainsString( 'Safe Publish', $user_agent );
	}

	/**
	 * Verifies that parse_destination_site_url extracts the URL from a standard
	 * Safe Publish User-Agent string.
	 */
	public function test_parse_destination_site_url_extracts_url_from_user_agent(): void {
		$result = HTTP_Client::parse_destination_site_url(
			'Safe Publish/1.2.3; https://dest.example.com'
		);

		$this->assertSame( 'https://dest.example.com', $result );
	}

	/**
	 * Verifies that parse_destination_site_url returns an empty string for an
	 * absent User-Agent header.
	 */
	public function test_parse_destination_site_url_returns_empty_string_for_missing_header(): void {
		$this->assertSame( '', HTTP_Client::parse_destination_site_url( '' ) );
	}

	/**
	 * Verifies that parse_destination_site_url returns the raw value when the
	 * User-Agent does not match the expected format.
	 */
	public function test_parse_destination_site_url_returns_raw_value_for_unknown_format(): void {
		$result = HTTP_Client::parse_destination_site_url( 'curl/7.88.0' );

		$this->assertSame( 'curl/7.88.0', $result );
	}

	/**
	 * Verifies that should_verify_ssl returns a boolean.
	 */
	public function test_should_verify_ssl_returns_bool(): void {
		$url    = 'https://example.com';
		$result = $this->http_client->should_verify_ssl( $url );

		$this->assertIsBool( $result );
	}

	/**
	 * Verifies that should_verify_ssl returns false for localhost.
	 */
	public function test_should_verify_ssl_returns_false_for_localhost(): void {
		$url    = 'http://localhost';
		$result = $this->http_client->should_verify_ssl( $url );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that should_verify_ssl returns false for local domains.
	 */
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

	/**
	 * Verifies that cleanup_temp_file handles non-existent files gracefully.
	 */
	public function test_cleanup_temp_file_handles_non_existent_file(): void {
		// Should not throw exception for non-existent file.
		$this->http_client->cleanup_temp_file( '/tmp/non_existent_file.txt' );

		$this->assertTrue( true ); // If we reach here, no exception was thrown.
	}

	/**
	 * Verifies that cleanup_temp_file handles empty strings gracefully.
	 */
	public function test_cleanup_temp_file_handles_empty_string(): void {
		// Should not throw exception for empty string.
		$this->http_client->cleanup_temp_file( '' );

		$this->assertTrue( true );
	}

	/**
	 * Issues a catalog listing request through the shared client.
	 *
	 * @return array|WP_Error make_request result.
	 */
	private function make_catalog_request(): array|WP_Error {
		return $this->http_client->make_request(
			'https://example.com/wp-json/safe-publish/v1/catalog/posts',
			Request_Actions::LIST_ITEMS
		);
	}

	/**
	 * Stubs a non-200 HTTP response with the given status code and body.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Raw response body.
	 */
	private function stub_http_error( int $code, string $body ): void {
		set_test_http_response(
			array(
				'response' => array( 'code' => $code ),
				'body'     => $body,
			)
		);
	}
}
