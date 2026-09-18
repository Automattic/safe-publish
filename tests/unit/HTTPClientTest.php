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
	 * Media URL the download tests fetch.
	 */
	private const MEDIA_URL = 'https://src.example.com/uploads/a.jpg';

	/**
	 * Temporary file path the download stub reports on success.
	 */
	private const TEMP_FILE = '/tmp/safe-publish-download.tmp';

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
	 * Resets the HTTP response and download stubs between tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		reset_test_http_response();
		reset_test_downloads();
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
		$this->assertSame(
			array(
				HTTP_Client::ERROR_DATA_SOURCE_ERROR => array(
					'message'  => $detail,
					'template' =>
						'Failed to fetch data from source site. <reason />',
				),
			),
			$result->get_error_data()
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
				HTTP_Client::ERROR_DATA_SOURCE_ERROR => array(
					'message'  => 'Invalid date parameters.',
					'template' =>
						'Source site returned HTTP error 400. <reason />',
				),
				'source_code'                        =>
					'safe_publish_catalog_invalid_date',
				'source_status'                      => 400,
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

	/**
	 * Verifies that download_file tells the transport not to follow
	 * redirects, and leaves another request in flight untouched.
	 */
	public function test_download_file_pins_the_redirect_policy(): void {
		// ARRANGE: Stub a source that serves the file directly.
		set_test_download_responses( array( $this->ok_response() ) );

		// ACT: Download a media URL through the shared client.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: The file arrives, the transport was told not to redirect,
		// and an unrelated request still sees core's default.
		$calls = get_test_download_url_calls();
		$this->assertSame( self::TEMP_FILE, $result );
		$this->assertCount( 1, $calls );
		$this->assertSame( 0, $calls[0]['args']['redirection'] );
		$this->assertSame( 5, $calls[0]['unrelated_args']['redirection'] );
	}

	/**
	 * Verifies that download_file removes both of its filters, so no later
	 * request inherits them.
	 */
	public function test_download_file_removes_its_request_filters(): void {
		// ARRANGE: Record the registry, and stub a source that serves the
		// file directly.
		$before_request  = count( get_test_filters( 'http_request_args' ) );
		$before_response = count( get_test_filters( 'http_response' ) );
		set_test_download_responses( array( $this->ok_response() ) );

		// ACT: Download a media URL through the shared client.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: Both filters ran for the download, and the registry is back
		// to what it held before.
		$calls = get_test_download_url_calls();
		$this->assertSame( self::TEMP_FILE, $result );
		$this->assertSame( $before_request + 1, $calls[0]['request_filters'] );
		$this->assertSame(
			$before_response + 1,
			$calls[0]['response_filters']
		);
		$this->assertCount(
			$before_request,
			get_test_filters( 'http_request_args' )
		);
		$this->assertCount(
			$before_response,
			get_test_filters( 'http_response' )
		);
	}

	/**
	 * Verifies that download_file follows a redirect that upgrades the
	 * scheme on the host the media URL names.
	 */
	public function test_download_file_follows_same_host_redirect(): void {
		// ARRANGE: Stub a source that upgrades the scheme, then serves it.
		$http_url  = 'http://src.example.com/uploads/a.jpg';
		$https_url = 'https://src.example.com/uploads/a.jpg';
		set_test_download_responses(
			array(
				$this->response_with_location( 301, $https_url ),
				$this->ok_response(),
			)
		);

		// ACT: Download the http URL.
		$result = $this->http_client->download_file( $http_url );

		// ASSERT: The retry went to the https URL and returned the file.
		$this->assertSame(
			array( $http_url, $https_url ),
			$this->requested_urls()
		);
		$this->assertSame( self::TEMP_FILE, $result );
	}

	/**
	 * Verifies that download_file resolves a root-relative redirect against
	 * the media URL's own scheme and host, not against its path.
	 */
	public function test_download_file_follows_root_relative_redirect(): void {
		// ARRANGE: Stub a redirect carrying a path-only Location.
		set_test_download_responses(
			array(
				$this->response_with_location( 301, '/uploads/2024/a.jpg' ),
				$this->ok_response(),
			)
		);

		// ACT: Download the media URL.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: The retry went to the resolved same-host URL.
		$this->assertSame(
			array(
				self::MEDIA_URL,
				'https://src.example.com/uploads/2024/a.jpg',
			),
			$this->requested_urls()
		);
		$this->assertSame( self::TEMP_FILE, $result );
	}

	/**
	 * Verifies that download_file gives a scheme-relative redirect the media
	 * URL's own scheme, which keeps it on the same host.
	 */
	public function test_download_file_follows_scheme_relative_hop(): void {
		// ARRANGE: Stub a redirect carrying a scheme-relative Location.
		set_test_download_responses(
			array(
				$this->response_with_location(
					301,
					'//src.example.com/uploads/b.jpg'
				),
				$this->ok_response(),
			)
		);

		// ACT: Download the media URL.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: The retry went to the same host over https.
		$this->assertSame(
			array(
				self::MEDIA_URL,
				'https://src.example.com/uploads/b.jpg',
			),
			$this->requested_urls()
		);
		$this->assertSame( self::TEMP_FILE, $result );
	}

	/**
	 * Verifies that download_file compares the redirect host without regard
	 * to case, as host names are case insensitive.
	 */
	public function test_download_file_follows_uppercase_host_redirect(): void {
		// ARRANGE: Stub a redirect naming the same host in upper case.
		$shouting = 'https://SRC.EXAMPLE.COM/uploads/b.jpg';
		set_test_download_responses(
			array(
				$this->response_with_location( 301, $shouting ),
				$this->ok_response(),
			)
		);

		// ACT: Download the media URL.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: The redirect counted as the same host and was followed.
		$this->assertSame(
			array( self::MEDIA_URL, $shouting ),
			$this->requested_urls()
		);
		$this->assertSame( self::TEMP_FILE, $result );
	}

	/**
	 * Verifies that download_file fails a redirect that leaves the host the
	 * media URL names, without requesting the other host.
	 */
	public function test_download_file_refuses_off_host_redirect(): void {
		// ARRANGE: Stub a redirect pointing at a different host.
		set_test_download_responses(
			array(
				$this->response_with_location(
					301,
					'https://other.example.net/a.jpg'
				),
			)
		);

		// ACT: Download the media URL.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: The download reports the redirect, and only the media URL
		// itself was requested.
		$this->assertDownloadStopped(
			$result,
			HTTP_Client::ERROR_DOWNLOAD_REDIRECTED
		);
	}

	/**
	 * Verifies that download_file fails a scheme-relative redirect that
	 * names another host, rather than folding it into its own path.
	 */
	public function test_download_file_refuses_scheme_relative_host(): void {
		// ARRANGE: Stub a scheme-relative Location naming another host.
		set_test_download_responses(
			array(
				$this->response_with_location(
					301,
					'//other.example.net/a.jpg'
				),
			)
		);

		// ACT: Download the media URL.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: The download reports the redirect, and only the media URL
		// itself was requested.
		$this->assertDownloadStopped(
			$result,
			HTTP_Client::ERROR_DOWNLOAD_REDIRECTED
		);
	}

	/**
	 * Verifies that download_file fails a same-host redirect that downgrades
	 * an https media URL to plain http.
	 */
	public function test_download_file_refuses_scheme_downgrade(): void {
		// ARRANGE: Stub a redirect to the same host over http.
		set_test_download_responses(
			array(
				$this->response_with_location(
					301,
					'http://src.example.com/uploads/a.jpg'
				),
			)
		);

		// ACT: Download the https media URL.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: The download reports the redirect and stops at one request.
		$this->assertDownloadStopped(
			$result,
			HTTP_Client::ERROR_DOWNLOAD_REDIRECTED
		);
	}

	/**
	 * Verifies that download_file fails a redirect to another port on the
	 * host the media URL names.
	 */
	public function test_download_file_refuses_another_port(): void {
		// ARRANGE: Stub a redirect to the same host on another port.
		set_test_download_responses(
			array(
				$this->response_with_location(
					301,
					'https://src.example.com:8443/uploads/a.jpg'
				),
			)
		);

		// ACT: Download the media URL.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: The download reports the redirect and stops at one request.
		$this->assertDownloadStopped(
			$result,
			HTTP_Client::ERROR_DOWNLOAD_REDIRECTED
		);
	}

	/**
	 * Verifies that download_file stops once the same-host redirect budget
	 * is spent.
	 */
	public function test_download_file_stops_after_redirect_budget(): void {
		// ARRANGE: Stub a source that redirects on its own host forever.
		$responses = array();
		for ( $hop = 0; $hop < 6; $hop++ ) {
			$responses[] = $this->response_with_location(
				301,
				self::MEDIA_URL . '?hop=' . $hop
			);
		}
		set_test_download_responses( $responses );

		// ACT: Download the media URL.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: Four requests were made - the first plus three redirects -
		// and the download reports the redirect.
		$this->assertCount( 4, $this->requested_urls() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			HTTP_Client::ERROR_DOWNLOAD_REDIRECTED,
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that download_file ignores a redirect answered to a request
	 * other than its own.
	 */
	public function test_download_file_ignores_other_request_redirect(): void {
		// ARRANGE: Stub a missing media file, and an unrelated request that
		// is answered with a redirect while the download runs.
		set_test_unrelated_response(
			$this->response_with_location(
				301,
				'https://other.example.net/a.jpg'
			)
		);
		set_test_download_responses(
			array( array( 'response' => array( 'code' => 404 ) ) )
		);

		// ACT: Download the media URL.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: The policy ran for the media URL, the caller sees the
		// error core reported for it, and no retry was made.
		$this->assertDownloadStopped( $result, 'http_404' );
	}

	/**
	 * Verifies that download_file reports the error core raised when a
	 * redirect carries no Location to follow.
	 */
	public function test_download_file_needs_a_location_to_follow(): void {
		// ARRANGE: Stub a redirect with no Location header.
		set_test_download_responses(
			array( array( 'response' => array( 'code' => 301 ) ) )
		);

		// ACT: Download the media URL.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: The policy ran, the caller sees the error core reported
		// rather than a redirect error, and no retry was made.
		$this->assertDownloadStopped( $result, 'http_404' );
	}

	/**
	 * Verifies that download_file ignores a Location header on a response
	 * that is not a redirect.
	 */
	public function test_download_file_ignores_non_redirect_location(): void {
		// ARRANGE: Stub a missing file whose response still carries Location.
		set_test_download_responses(
			array(
				array(
					'response' => array( 'code' => 404 ),
					'headers'  => array(
						'location' => 'https://other.example.net/a.jpg',
					),
				),
			)
		);

		// ACT: Download the media URL.
		$result = $this->http_client->download_file( self::MEDIA_URL );

		// ASSERT: The policy ran, the caller sees the error core reported
		// rather than a redirect error, and no retry was made.
		$this->assertDownloadStopped( $result, 'http_404' );
	}

	/**
	 * Builds a stubbed 200 response.
	 *
	 * @return array Stubbed HTTP response.
	 */
	private function ok_response(): array {
		return array( 'response' => array( 'code' => 200 ) );
	}

	/**
	 * Builds a stubbed response carrying a Location header.
	 *
	 * @param int    $code     HTTP status code.
	 * @param string $location Location header value.
	 * @return array Stubbed HTTP response.
	 */
	private function response_with_location(
		int $code,
		string $location
	): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array( 'location' => $location ),
		);
	}

	/**
	 * Returns the URLs the download stub was asked to fetch, in order.
	 *
	 * @return array List of requested URLs.
	 */
	private function requested_urls(): array {
		return array_column( get_test_download_url_calls(), 'url' );
	}

	/**
	 * Asserts that a download stopped at the media URL with the given error,
	 * having applied the redirect policy to that one request.
	 *
	 * @param string|WP_Error $result     download_file() result.
	 * @param string          $error_code Expected WP_Error code.
	 */
	private function assertDownloadStopped(
		string|WP_Error $result,
		string $error_code
	): void {
		$calls = get_test_download_url_calls();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $error_code, $result->get_error_code() );
		$this->assertSame( array( self::MEDIA_URL ), $this->requested_urls() );
		$this->assertSame( 0, $calls[0]['args']['redirection'] );
	}
}
