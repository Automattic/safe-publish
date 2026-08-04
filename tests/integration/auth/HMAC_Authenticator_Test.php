<?php
/**
 * Integration tests for the HMAC authenticator.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use Safe_Publish\API\Dispatch_Logger;
use Safe_Publish\API\Export_Logger;
use Safe_Publish\API\Request_Actions;
use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\HMAC_Authenticator;
use Safe_Publish\Auth\Permission_Manager;
use Safe_Publish\Utils\Audit_Log_Table;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * HMAC Authenticator Test Class.
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
			new Permission_Manager(
				new Auth_Logger(),
				new Export_Logger(),
				new Dispatch_Logger()
			),
			defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ? SAFE_PUBLISH_SHARED_SECRET : '',
			home_url()
		);

		// Clear any stored log events before each test.
		Audit_Log_Table::clear( 'auth' );
	}

	/**
	 * Verifies that a request with a valid HMAC signature passes authentication.
	 */
	public function test_valid_hmac_request_succeeds(): void {
		// ARRANGE: Valid signed GET request — POST on /wp/v2/* is short-circuited
		// before signature verification, so the read methods exercise the
		// full HMAC validation path.
		$request = $this->build_signed_request( 'GET', '/wp/v2/posts', '' );

		// ACT: Authenticate the request.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Authenticated; null returned and authenticator state set.
		$this->assertNull( $result );
		$this->assertTrue( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a request with an invalid HMAC signature fails with 401.
	 */
	public function test_invalid_signature_fails_with_401(): void {
		// ARRANGE: Valid timestamp, content hash, and site URL — but wrong signature.
		$timestamp     = time();
		$body          = 'some body content';
		$content_hash  = hash( 'sha256', $body );
		$this_site_url = home_url();

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_body( $body );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) $timestamp );
		$request->set_header( 'X-Safe-Publish-Content-Hash', $content_hash );
		$request->set_header( 'X-Safe-Publish-Site-URL', $this_site_url );
		$request->set_header( 'X-Safe-Publish-Signature', 'totally-invalid-signature' );

		// ACT: Attempt authentication with the tampered signature.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Returns 401 WP_Error; authentication state is unchanged.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_invalid', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertFalse( $this->authenticator->is_authenticated() );
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

		// ACT: Attempt authentication with the expired timestamp.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Returns 401 WP_Error; authentication state is unchanged.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_expired', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertFalse( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that requests without Safe Publish headers pass through to
	 * WordPress auth.
	 */
	public function test_missing_headers_passes_through_to_wp_auth(): void {
		// ARRANGE: Request with no Safe Publish headers.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );

		// ACT: Authenticate the request.
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

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_body( $body );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) $timestamp );

		// Build a valid signature (without content hash in the string).
		$string_to_sign = 'GET|/wp/v2/posts|' . $timestamp . '|' . hash( 'sha256', $body );
		$signature      = hash_hmac( 'sha256', $string_to_sign, self::FALLBACK_SECRET );
		$request->set_header( 'X-Safe-Publish-Signature', $signature );
		// Intentionally omit X-Safe-Publish-Content-Hash.

		// ACT: Attempt authentication without the content hash header.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Returns 401 WP_Error; authentication state is unchanged.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_content_hash_missing', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertFalse( $this->authenticator->is_authenticated() );
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
		$string_to_sign = 'GET|/wp/v2/posts|' . $timestamp . '|' . $wrong_hash;
		$signature      = hash_hmac( 'sha256', $string_to_sign, self::FALLBACK_SECRET );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_body( $body );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) $timestamp );
		$request->set_header( 'X-Safe-Publish-Content-Hash', $wrong_hash );
		$request->set_header( 'X-Safe-Publish-Signature', $signature );

		// ACT: Attempt authentication with the mismatched content hash.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Returns 401 WP_Error; authentication state is unchanged.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_content_hash_invalid', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertFalse( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that /safe-publish/v1/ source routes require HMAC authentication.
	 */
	public function test_safe_publish_route_authenticates(): void {
		// ARRANGE: Valid Safe Publish headers targeting a source route.
		$request = $this->build_signed_request( 'GET', '/safe-publish/v1/catalog/posts', '' );

		// ACT: Authenticate the request.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Authenticated — must prove knowledge of the shared secret.
		$this->assertNull( $result );
		$this->assertTrue( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a signed non-GET request on /wp/v2/* short-circuits
	 * before HMAC validation and falls through to WordPress' standard auth.
	 * The destination only ever issues reads against source `/wp/v2/*`, so
	 * write methods on that namespace should never enter the elevated
	 * context even when properly signed.
	 */
	public function test_post_on_wp_v2_routes_is_not_authenticated(): void {
		// ARRANGE: A signed POST on /wp/v2/* — the new method check returns
		// early before signature work.
		$request = $this->build_signed_request( 'POST', '/wp/v2/posts', 'body content' );

		// ACT: Authenticate the request.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Pass-through; no elevated context installed.
		$this->assertNull( $result );
		$this->assertFalse( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a signed POST on /safe-publish/v1/* still authenticates
	 * — the method check is scoped to /wp/v2/* so destination-side admin
	 * flows that POST to source routes continue to work.
	 */
	public function test_post_on_safe_publish_routes_still_authenticated(): void {
		// ARRANGE: A signed POST on /safe-publish/v1/*.
		$request = $this->build_signed_request( 'POST', '/safe-publish/v1/diff-preview', 'payload' );

		// ACT: Authenticate the request.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Authenticated; null returned and authenticator state set.
		$this->assertNull( $result );
		$this->assertTrue( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that requests to unrelated routes pass through even with Safe
	 * Publish headers.
	 */
	public function test_unrelated_route_passes_through(): void {
		// ARRANGE: Valid Safe Publish headers but targeting an unrelated route.
		$request = $this->build_signed_request( 'GET', '/woocommerce/v1/orders', '' );

		// ACT: Authenticate the request.
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

		// ACT: Attempt authentication with the future timestamp.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Returns 401 WP_Error; authentication state is unchanged.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_expired', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertFalse( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a valid request is rejected when no connected site URL is
	 * configured on the authenticator.
	 */
	public function test_missing_connected_site_url_fails_with_500(): void {
		// ARRANGE: Authenticator with no connected site URL.
		$authenticator = new HMAC_Authenticator(
			new Auth_Logger(),
			new Permission_Manager(
				new Auth_Logger(),
				new Export_Logger(),
				new Dispatch_Logger()
			),
			self::FALLBACK_SECRET,
			''
		);
		$request       = $this->build_signed_request( 'GET', '/wp/v2/posts', '' );

		// ACT: Attempt authentication with no connected site URL configured.
		$result = $authenticator->authenticate_request( null, null, $request );

		// ASSERT: Returns 500 WP_Error; authentication state is unchanged.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_no_connected_site_url', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertFalse( $authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a valid request is rejected when the site URL does not match
	 * the configured connected site URL.
	 */
	public function test_site_url_mismatch_fails_with_403(): void {
		// ARRANGE: Authenticator configured to only accept requests from a specific URL.
		$authenticator = new HMAC_Authenticator(
			new Auth_Logger(),
			new Permission_Manager(
				new Auth_Logger(),
				new Export_Logger(),
				new Dispatch_Logger()
			),
			self::FALLBACK_SECRET,
			'https://allowed-receiver.example.com'
		);
		$request       = $this->build_signed_request( 'GET', '/wp/v2/posts', '', 0, 'https://other-site.example.com' );

		// ACT: Attempt authentication from a different site URL.
		$result = $authenticator->authenticate_request( null, null, $request );

		// ASSERT: Returns 403 WP_Error; authentication state is unchanged.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_site_url_mismatch', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertFalse( $authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a valid request is rejected when the site URL header is
	 * missing.
	 */
	public function test_missing_site_url_header_fails(): void {
		// ARRANGE: Authenticator configured with a connected site URL.
		$authenticator = new HMAC_Authenticator(
			new Auth_Logger(),
			new Permission_Manager(
				new Auth_Logger(),
				new Export_Logger(),
				new Dispatch_Logger()
			),
			self::FALLBACK_SECRET,
			'https://allowed-receiver.example.com'
		);
		// Build a request without the site URL header.
		$request = $this->build_signed_request( 'GET', '/wp/v2/posts', '', 0, '' );

		// ACT: Attempt authentication without the site URL header.
		$result = $authenticator->authenticate_request( null, null, $request );

		// ASSERT: Returns 401 WP_Error; authentication state is unchanged.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_site_url_missing', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertFalse( $authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a valid request succeeds when the site URL matches the
	 * configured connected site URL.
	 */
	public function test_matching_site_url_succeeds(): void {
		// ARRANGE: Authenticator restricted to a URL; request signed from it.
		$allowed_url   = 'https://allowed-receiver.example.com';
		$authenticator = new HMAC_Authenticator(
			new Auth_Logger(),
			new Permission_Manager(
				new Auth_Logger(),
				new Export_Logger(),
				new Dispatch_Logger()
			),
			defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ? SAFE_PUBLISH_SHARED_SECRET : self::FALLBACK_SECRET,
			$allowed_url
		);
		$request       = $this->build_signed_request( 'GET', '/wp/v2/posts', '', 0, $allowed_url );

		// ACT: Authenticate the request.
		$result = $authenticator->authenticate_request( null, null, $request );

		// ASSERT: Authenticated; null returned and authenticator state set.
		$this->assertNull( $result );
		$this->assertTrue( $authenticator->is_authenticated() );
	}

	/**
	 * Verifies that a successful authentication event is stored in the log,
	 * including the declared action label.
	 */
	public function test_authentication_event_logged(): void {
		// ARRANGE: Valid GET request to /wp/v2/posts declaring IMPORT action.
		$request = $this->build_signed_request( 'GET', '/wp/v2/posts', '' );

		// ACT: Authenticate the request.
		$this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: REQUEST_AUTHENTICATED entry exists with correct route, method,
		// and declared action. Additional events (e.g. AUTHENTICATED_CONTEXT_INSTALLED)
		// may follow.
		$log_events  = Audit_Log_Table::get_events( array( 'channel' => 'auth' ) );
		$event_types = array_column( $log_events, 'event' );
		$this->assertContains( 'REQUEST_AUTHENTICATED', $event_types );
		$success_events = array_values(
			array_filter(
				$log_events,
				fn( array $e ) => 'REQUEST_AUTHENTICATED' === $e['event']
			)
		);
		$this->assertCount( 1, $success_events );
		$this->assertSame( 'GET', $success_events[0]['data']['method'] );
		$this->assertSame( '/wp/v2/posts', $success_events[0]['data']['route'] );
		$this->assertSame( home_url(), $success_events[0]['data']['request_site_url'] );
		$this->assertSame( Request_Actions::IMPORT, $success_events[0]['data']['action'] );
	}

	/**
	 * Verifies that an authenticated request with an unrecognized action
	 * header logs REQUEST_ACTION_UNRECOGNIZED. Missing-header behavior is
	 * the same — empty string also fails is_valid().
	 */
	public function test_unrecognized_action_logs_error_event(): void {
		// ARRANGE: Sign and dispatch a request whose action header is not in
		// the known Request_Actions vocabulary. The action must be included in
		// the signed string for HMAC verification to pass.
		$invalid_action = 'totally-made-up-action';
		$timestamp      = time();
		$body           = '';
		$site_url       = home_url();
		$secret         = defined( 'SAFE_PUBLISH_SHARED_SECRET' )
			? SAFE_PUBLISH_SHARED_SECRET
			: self::FALLBACK_SECRET;
		$content_hash   = hash( 'sha256', $body );
		$string_to_sign = 'GET|/wp/v2/posts'
			. '|' . $timestamp
			. '|' . $content_hash
			. '|' . $site_url
			. '|' . $invalid_action;
		$signature      = hash_hmac( 'sha256', $string_to_sign, $secret );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_body( $body );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) $timestamp );
		$request->set_header( 'X-Safe-Publish-Content-Hash', $content_hash );
		$request->set_header( 'X-Safe-Publish-Signature', $signature );
		$request->set_header( 'X-Safe-Publish-Site-URL', $site_url );
		$request->set_header( 'X-Safe-Publish-Action', $invalid_action );

		// ACT: Authenticate the request.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Auth still succeeds (signature valid), and an error event records
		// the unrecognized action value verbatim for forensics.
		$this->assertNull( $result );
		$this->assertTrue( $this->authenticator->is_authenticated() );

		$unrecognized_events = array_values(
			array_filter(
				Audit_Log_Table::get_events( array( 'channel' => 'auth' ) ),
				fn( array $e ) => 'REQUEST_ACTION_UNRECOGNIZED' === $e['event']
			)
		);
		$this->assertCount( 1, $unrecognized_events );
		$this->assertSame( 'error', $unrecognized_events[0]['level'] );
		$this->assertSame( $invalid_action, $unrecognized_events[0]['data']['received_action'] );
	}

	/**
	 * Verifies that tampering with the action header after signing
	 * invalidates the HMAC signature — the source must reject any attempt
	 * to swap the declared action.
	 */
	public function test_tampered_action_header_invalidates_signature(): void {
		// ARRANGE: Sign with IMPORT but submit the request as LIST_ITEMS, leaving
		// every other header intact.
		$request = $this->build_signed_request( 'GET', '/wp/v2/posts', '' );
		$request->set_header( 'X-Safe-Publish-Action', Request_Actions::LIST_ITEMS );

		// ACT: Authenticate the tampered request.
		$result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Signature verification fails — the action is part of the
		// signed payload and cannot be flipped post-signing.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'safe_publish_auth_invalid', $result->get_error_code() );
		$this->assertFalse( $this->authenticator->is_authenticated() );
	}

	/**
	 * Verifies that authenticating a wp/v2 read forces REST nocache headers,
	 * so a cache-fronting edge cannot store the edit-context response.
	 */
	public function test_authenticated_wp_v2_read_forces_nocache_headers(): void {
		// ARRANGE: A signed read; no logged-in user backs an HMAC request.
		$request = $this->build_signed_request( 'GET', '/wp/v2/posts/1', '' );

		// ACT: Authenticate the request.
		$this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: The nocache filter is registered despite no user; the headers
		// core then emits are the strong no-store, private form.
		$this->assertFalse( is_user_logged_in() );
		$this->assertNotFalse(
			has_filter(
				'rest_send_nocache_headers',
				array( $this->authenticator, 'force_rest_nocache_headers' )
			)
		);
		$cache_control = wp_get_nocache_headers()['Cache-Control'];
		$this->assertStringContainsString( 'no-store', $cache_control );
		$this->assertStringContainsString( 'private', $cache_control );
	}

	/**
	 * Verifies that authenticating a safe-publish/v1 catalog request forces
	 * REST nocache headers too — the common-path filter covers it, unlike the
	 * wp/v2-only authenticated context setup.
	 */
	public function test_authenticated_catalog_request_forces_nocache_headers(): void {
		// ARRANGE: A signed catalog request on /safe-publish/v1/.
		$request = $this->build_signed_request( 'GET', '/safe-publish/v1/catalog/posts', '' );

		// ACT: Authenticate the request.
		$this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: The common-path filter covers the catalog, not just wp/v2 reads.
		$this->assertNotFalse(
			has_filter(
				'rest_send_nocache_headers',
				array( $this->authenticator, 'force_rest_nocache_headers' )
			)
		);
	}

	/**
	 * Builds a properly signed WP_REST_Request.
	 *
	 * @param string      $method    HTTP method.
	 * @param string      $route     REST route path.
	 * @param string      $body      Request body.
	 * @param int         $timestamp Optional. Unix timestamp. Defaults to current time.
	 * @param string|null $site_url  Optional. URL to include in the X-Safe-Publish-Site-URL header.
	 *                               Null uses home_url(), '' omits the header.
	 * @param string      $action    Optional. Declared request action. Defaults to IMPORT.
	 * @return WP_REST_Request Signed request.
	 */
	private function build_signed_request(
		string $method,
		string $route,
		string $body,
		int $timestamp = 0,
		?string $site_url = null,
		string $action = Request_Actions::IMPORT
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
		$string_to_sign .= '|' . $action;
		$signature       = hash_hmac( 'sha256', $string_to_sign, $secret );

		$request = new WP_REST_Request( $method, $route );
		$request->set_body( $body );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) $timestamp );
		$request->set_header( 'X-Safe-Publish-Content-Hash', $content_hash );
		$request->set_header( 'X-Safe-Publish-Signature', $signature );
		$request->set_header( 'X-Safe-Publish-Action', $action );
		if ( ! empty( $site_url ) ) {
			$request->set_header( 'X-Safe-Publish-Site-URL', $site_url );
		}

		return $request;
	}
}
