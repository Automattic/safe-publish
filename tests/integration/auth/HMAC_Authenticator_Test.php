<?php
/**
 * Integration tests for the HMAC authenticator.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use Safe_Publish\API\Export_Logger;
use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\HMAC_Authenticator;
use Safe_Publish\Auth\Permission_Manager;
use Safe_Publish\Utils\Event_Table;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * HMAC Authenticator Test.
 *
 * Tests the complete HMAC authentication workflow end-to-end.
 */
class HMAC_Authenticator_Test extends WP_UnitTestCase {

	/**
	 * Fallback shared secret used when no environment constant is defined.
	 */
	private const FALLBACK_SECRET = 'integration-test-secret-key-32chars-ok';

	/**
	 * HMAC authenticator instance.
	 *
	 * @var HMAC_Authenticator
	 */
	private HMAC_Authenticator $authenticator;

	/**
	 * Sets up each test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		$this->authenticator = new HMAC_Authenticator(
			new Auth_Logger(),
			new Permission_Manager( new Auth_Logger(), new Export_Logger() ),
			defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ? SAFE_PUBLISH_SHARED_SECRET : ''
		);

		// Clear any stored log events before each test.
		Event_Table::clear( 'auth' );
	}

	/**
	 * Verifies that a request with a valid HMAC signature passes authentication.
	 */
	public function test_valid_hmac_request_succeeds(): void {
		// ARRANGE.
		$request = $this->build_signed_request( 'POST', '/wp/v2/posts', 'body content' );

		// ACT.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT.
		$this->assertNull( $result );
		$this->assertTrue( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a request with an invalid HMAC signature fails with 401.
	 */
	public function test_invalid_signature_fails_with_401(): void {
		// ARRANGE: Valid timestamp, content hash, and site URL — but wrong signature.
		$timestamp    = time();
		$body         = 'some body content';
		$content_hash = hash( 'sha256', $body );
		$site_url     = home_url();

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body( $body );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) $timestamp );
		$request->set_header( 'X-Safe-Publish-Content-Hash', $content_hash );
		$request->set_header( 'X-Safe-Publish-Site-URL', $site_url );
		$request->set_header( 'X-Safe-Publish-Signature', 'totally-invalid-signature' );

		// ACT.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_invalid', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Verifies that a request with an expired timestamp fails with 401.
	 */
	public function test_expired_timestamp_fails(): void {
		// ARRANGE: Timestamp more than 5 minutes in the past.
		$expired_timestamp = time() - 400;
		$request           = $this->build_signed_request(
			'GET',
			'/wp/v2/posts',
			'',
			$expired_timestamp
		);

		// ACT.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_expired', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Verifies that requests without Safe Publish headers pass through to
	 * WordPress auth.
	 */
	public function test_missing_headers_passes_through_to_wp_auth(): void {
		// ARRANGE: Request with no Safe Publish headers.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );

		// ACT.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Pass-through (null returned, not a WP_Error).
		$this->assertNull( $result );
		$this->assertFalse( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a request missing the Content-Hash header fails with 401.
	 */
	public function test_missing_content_hash_header_fails(): void {
		// ARRANGE: Valid timestamp and signature but no content hash.
		$timestamp = time();
		$body      = 'some body';

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body( $body );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) $timestamp );

		// Build a valid signature (without content hash in the string).
		$string_to_sign = 'POST|/wp/v2/posts|' . $timestamp . '|' . hash( 'sha256', $body );
		$signature      = hash_hmac( 'sha256', $string_to_sign, self::FALLBACK_SECRET );
		$request->set_header( 'X-Safe-Publish-Signature', $signature );
		// Intentionally omit X-Safe-Publish-Content-Hash.

		// ACT.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_content_hash_missing', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Verifies that a request with a mismatched Content-Hash header fails with
	 * 401.
	 */
	public function test_mismatched_content_hash_fails(): void {
		// ARRANGE: Content hash header doesn't match actual body.
		$timestamp      = time();
		$body           = 'actual body content';
		$wrong_hash     = hash( 'sha256', 'different content' );
		$string_to_sign = 'POST|/wp/v2/posts|' . $timestamp . '|' . $wrong_hash;
		$signature      = hash_hmac( 'sha256', $string_to_sign, self::FALLBACK_SECRET );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body( $body );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) $timestamp );
		$request->set_header( 'X-Safe-Publish-Content-Hash', $wrong_hash );
		$request->set_header( 'X-Safe-Publish-Signature', $signature );

		// ACT.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_content_hash_invalid', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Verifies that /safe-publish/v1/ monitoring routes require HMAC
	 * authentication.
	 */
	public function test_safe_publish_monitoring_route_authenticates(): void {
		// ARRANGE: Valid Safe Publish headers targeting a monitoring route.
		$request = $this->build_signed_request( 'GET', '/safe-publish/v1/auth-status', '' );

		// ACT.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Authenticated — must prove knowledge of the shared secret.
		$this->assertNull( $result );
		$this->assertTrue( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that the debug test endpoint passes through even with invalid
	 * HMAC headers, so its diagnostic response is always reachable.
	 */
	public function test_auth_test_endpoint_passes_through_with_invalid_headers(): void {
		// ARRANGE: Malformed Safe Publish headers targeting the diagnostic endpoint.
		$timestamp = time();
		$request   = new \WP_REST_Request( 'GET', '/safe-publish/v1/auth-test' );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) $timestamp );
		$request->set_header( 'X-Safe-Publish-Content-Hash', hash( 'sha256', '' ) );
		$request->set_header( 'X-Safe-Publish-Signature', 'totally-wrong-signature' );

		// ACT.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Pass-through — auth-test is excluded from the route guard so the
		// diagnostic callback can always run and return useful debug information.
		$this->assertNull( $result );
		$this->assertFalse( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that requests to unrelated routes pass through even with Safe
	 * Publish headers.
	 */
	public function test_unrelated_route_passes_through(): void {
		// ARRANGE: Valid Safe Publish headers but targeting an unrelated route.
		$request = $this->build_signed_request( 'GET', '/woocommerce/v1/orders', '' );

		// ACT.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Pass-through — route guard returns early.
		$this->assertNull( $result );
		$this->assertFalse( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a request with a future timestamp fails with 401.
	 */
	public function test_future_timestamp_fails(): void {
		// ARRANGE: Timestamp more than 5 minutes in the future.
		$future_timestamp = time() + 400;
		$request          = $this->build_signed_request( 'GET', '/wp/v2/posts', '', $future_timestamp );

		// ACT.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_expired', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Verifies that a valid request is rejected when no connected site URL is
	 * configured on the authenticator.
	 */
	public function test_missing_connected_site_url_fails_with_500(): void {
		// ARRANGE: Authenticator with no connected site URL.
		$authenticator = new HMAC_Authenticator(
			new Auth_Logger(),
			new Permission_Manager( new Auth_Logger(), new Export_Logger() ),
			self::FALLBACK_SECRET,
			''
		);
		$request       = $this->build_signed_request( 'GET', '/wp/v2/posts', '' );

		// ACT.
		$result = $authenticator->authenticate_request( null, null, $request );

		// ASSERT.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_no_connected_url', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	/**
	 * Verifies that a valid request is rejected when the site URL does not match
	 * the configured connected site URL.
	 */
	public function test_site_url_mismatch_fails_with_403(): void {
		// ARRANGE: Authenticator configured to only accept requests from a specific URL.
		$authenticator = new HMAC_Authenticator(
			new Auth_Logger(),
			new Permission_Manager( new Auth_Logger(), new Export_Logger() ),
			self::FALLBACK_SECRET,
			'https://allowed-receiver.example.com'
		);
		$request       = $this->build_signed_request( 'GET', '/wp/v2/posts', '', 0, 'https://other-site.example.com' );

		// ACT.
		$result = $authenticator->authenticate_request( null, null, $request );

		// ASSERT.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_site_url_mismatch', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Verifies that a valid request is rejected when the site URL header is
	 * missing.
	 */
	public function test_missing_site_url_header_fails(): void {
		// ARRANGE: Authenticator configured with a connected site URL.
		$authenticator = new HMAC_Authenticator(
			new Auth_Logger(),
			new Permission_Manager( new Auth_Logger(), new Export_Logger() ),
			self::FALLBACK_SECRET,
			'https://allowed-receiver.example.com'
		);
		// Build a request without the site URL header.
		$request = $this->build_signed_request( 'GET', '/wp/v2/posts', '', 0, '' );

		// ACT.
		$result = $authenticator->authenticate_request( null, null, $request );

		// ASSERT.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_site_url_missing', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Verifies that a valid request succeeds when the site URL matches the
	 * configured connected site URL.
	 */
	public function test_matching_site_url_succeeds(): void {
		// ARRANGE.
		$allowed_url   = 'https://allowed-receiver.example.com';
		$authenticator = new HMAC_Authenticator(
			new Auth_Logger(),
			new Permission_Manager( new Auth_Logger(), new Export_Logger() ),
			defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ? SAFE_PUBLISH_SHARED_SECRET : self::FALLBACK_SECRET,
			$allowed_url
		);
		$request       = $this->build_signed_request( 'GET', '/wp/v2/posts', '', 0, $allowed_url );

		// ACT.
		$result = $authenticator->authenticate_request( null, null, $request );

		// ASSERT.
		$this->assertNull( $result );
		$this->assertTrue( $authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a successful authentication event is stored in the log.
	 */
	public function test_authentication_event_logged(): void {
		// ARRANGE.
		$request = $this->build_signed_request( 'GET', '/wp/v2/posts', '' );

		// ACT.
		$this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: AUTH_SUCCESS entry exists in the log.
		// Note: additional events (e.g. CAPABILITY_BASED_AUTH_SETUP) may follow.
		$log_events  = Event_Table::get_events( array( 'channel' => 'auth' ) );
		$event_types = array_column( $log_events, 'event' );
		$this->assertContains( 'AUTH_SUCCESS', $event_types );
	}

	/**
	 * Builds a properly signed WP_REST_Request.
	 *
	 * @param string      $method    HTTP method.
	 * @param string      $route     REST route path.
	 * @param string      $body      Request body.
	 * @param int         $timestamp Optional. Unix timestamp. Defaults to current time.
	 * @param string|null $site_url  Optional. Source site URL to include in the request. Null uses home_url(), '' omits the header.
	 * @return WP_REST_Request Signed request.
	 */
	private function build_signed_request(
		string $method,
		string $route,
		string $body,
		int $timestamp = 0,
		?string $site_url = null
	): WP_REST_Request {
		if ( 0 === $timestamp ) {
			$timestamp = time();
		}

		if ( null === $site_url ) {
			$site_url = home_url();
		}

		$secret         = defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ? SAFE_PUBLISH_SHARED_SECRET : self::FALLBACK_SECRET;
		$content_hash   = hash( 'sha256', $body );
		$string_to_sign = $method . '|' . $route . '|' . $timestamp . '|' . $content_hash;
		if ( ! empty( $site_url ) ) {
			$string_to_sign .= '|' . $site_url;
		}
		$signature = hash_hmac( 'sha256', $string_to_sign, $secret );

		$request = new WP_REST_Request( $method, $route );
		$request->set_body( $body );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) $timestamp );
		$request->set_header( 'X-Safe-Publish-Content-Hash', $content_hash );
		$request->set_header( 'X-Safe-Publish-Signature', $signature );
		if ( ! empty( $site_url ) ) {
			$request->set_header( 'X-Safe-Publish-Site-URL', $site_url );
		}

		return $request;
	}
}
