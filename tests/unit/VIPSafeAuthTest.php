<?php
/**
 * VIP Safe Auth Test file.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Request_Actions;
use Safe_Publish\Auth\VIP_Safe_Auth;
use WP_Error;

/**
 * VIP Safe Auth Test.
 *
 * Tests authentication methods and security.
 */
class VIPSafeAuthTest extends TestCase {

	/**
	 * Verifies that auth params include the source site URL header.
	 */
	public function test_get_auth_params_includes_site_url_header(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'GET'
		);

		$this->assertArrayHasKey( 'X-Safe-Publish-Site-URL', $params['headers'] );
		$this->assertSame(
			untrailingslashit( home_url() ),
			$params['headers']['X-Safe-Publish-Site-URL']
		);
	}

	/**
	 * Verifies that the source site URL is baked into the HMAC signature.
	 */
	public function test_site_url_is_included_in_signature(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params       = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'GET'
		);
		$source_url   = $params['headers']['X-Safe-Publish-Site-URL'];
		$timestamp    = $params['headers']['X-Safe-Publish-Timestamp'];
		$content_hash = $params['headers']['X-Safe-Publish-Content-Hash'];

		// Recompute what the signature should be with and without the source URL.
		$route       = '/wp/v2/posts';
		$without_url = hash_hmac(
			'sha256',
			'GET|' . $route . '|' . $timestamp . '|' . $content_hash
				. '||' . Request_Actions::IMPORT,
			$auth_config['shared_secret']
		);
		$with_url    = hash_hmac(
			'sha256',
			'GET|' . $route . '|' . $timestamp . '|' . $content_hash
				. '|' . $source_url . '|' . Request_Actions::IMPORT,
			$auth_config['shared_secret']
		);

		// The signature must match the one that includes the site URL.
		$this->assertSame( $with_url, $params['headers']['X-Safe-Publish-Signature'] );
		$this->assertNotSame( $without_url, $params['headers']['X-Safe-Publish-Signature'] );
	}

	/**
	 * Verifies that the declared request action is baked into the HMAC
	 * signature and emitted as a request header — tampering with the action
	 * label after signing must invalidate the signature.
	 */
	public function test_action_is_included_in_signature_and_header(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params       = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'GET'
		);
		$source_url   = $params['headers']['X-Safe-Publish-Site-URL'];
		$timestamp    = $params['headers']['X-Safe-Publish-Timestamp'];
		$content_hash = $params['headers']['X-Safe-Publish-Content-Hash'];

		// Recompute the signature with the wrong (tampered) action value.
		$route        = '/wp/v2/posts';
		$tampered_sig = hash_hmac(
			'sha256',
			'GET|' . $route . '|' . $timestamp . '|' . $content_hash
				. '|' . $source_url . '|' . Request_Actions::LIST_ITEMS,
			$auth_config['shared_secret']
		);
		$honest_sig   = hash_hmac(
			'sha256',
			'GET|' . $route . '|' . $timestamp . '|' . $content_hash
				. '|' . $source_url . '|' . Request_Actions::IMPORT,
			$auth_config['shared_secret']
		);

		$this->assertSame( $honest_sig, $params['headers']['X-Safe-Publish-Signature'] );
		$this->assertNotSame( $tampered_sig, $params['headers']['X-Safe-Publish-Signature'] );
		$this->assertSame(
			Request_Actions::IMPORT,
			$params['headers']['X-Safe-Publish-Action']
		);
	}

	/**
	 * Verifies that auth params include proper headers with shared secret.
	 */
	public function test_get_auth_params_with_shared_secret(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'GET'
		);

		$this->assertIsArray( $params );
		$this->assertArrayHasKey( 'headers', $params );
		$this->assertArrayHasKey( 'X-Safe-Publish-Timestamp', $params['headers'] );
		$this->assertArrayHasKey( 'X-Safe-Publish-Content-Hash', $params['headers'] );
		$this->assertArrayHasKey( 'X-Safe-Publish-Signature', $params['headers'] );
	}

	/**
	 * Verifies that Basic Auth headers are layered on top of Shared Secret headers.
	 */
	public function test_get_auth_params_layers_basic_auth_on_shared_secret(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
			'username'      => 'admin',
			'password'      => 'hunter2',
		);

		$params = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'GET'
		);

		$this->assertArrayHasKey( 'X-Safe-Publish-Signature', $params['headers'] );
		$this->assertArrayHasKey( 'Authorization', $params['headers'] );
		$this->assertStringStartsWith( 'Basic ', $params['headers']['Authorization'] );
		$this->assertSame( 'Basic ' . base64_encode( 'admin:hunter2' ), $params['headers']['Authorization'] );
	}

	/**
	 * Verifies that Basic Auth alone (no shared secret) returns empty params.
	 */
	public function test_get_auth_params_without_shared_secret_returns_empty(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'username' => 'admin',
			'password' => 'hunter2',
		);

		$params = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'GET'
		);

		$this->assertSame( array(), $params );
	}

	/**
	 * Verifies that auth params return empty array without credentials.
	 */
	public function test_get_auth_params_with_no_credentials_returns_empty(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array();

		$params = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'GET'
		);

		$this->assertSame( array(), $params );
	}

	/**
	 * Verifies that Basic Auth credentials alone (no shared secret) fail the
	 * credential format check.
	 */
	public function test_has_valid_credential_format_with_basic_auth_only_fails(): void {
		$auth_config = array(
			'username' => 'admin',
			'password' => 'hunter2',
		);

		$result = VIP_Safe_Auth::has_valid_credential_format( $auth_config );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that exactly 16-character shared secret passes the minimum
	 * length check.
	 */
	public function test_has_valid_credential_format_with_exactly_16_char_secret_passes(): void {
		$auth_config = array(
			'shared_secret' => '1234567890abcdef', // Exactly 16 chars.
		);

		$result = VIP_Safe_Auth::has_valid_credential_format( $auth_config );

		$this->assertTrue( $result );
	}

	/**
	 * Verifies that a 15-character shared secret fails the minimum length
	 * check.
	 */
	public function test_has_valid_credential_format_with_15_char_secret_fails(): void {
		$auth_config = array(
			'shared_secret' => '1234567890abcde', // Exactly 15 chars.
		);

		$result = VIP_Safe_Auth::has_valid_credential_format( $auth_config );

		$this->assertFalse( $result );
	}

	/**
	 * Verifies that partial Basic Auth config (username only) does not add an
	 * Authorization header.
	 */
	public function test_get_auth_params_shared_secret_with_username_only_has_no_authorization_header(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
			'username'      => 'admin',
			// No password.
		);

		$params = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'GET'
		);

		$this->assertArrayHasKey( 'X-Safe-Publish-Signature', $params['headers'] );
		$this->assertArrayNotHasKey( 'Authorization', $params['headers'] );
	}

	/**
	 * Verifies that content hash header is always included.
	 */
	public function test_get_auth_params_includes_content_hash(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);
		$body        = '{"content":"Hello world"}';

		$params = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'POST',
			$body
		);

		$this->assertArrayHasKey( 'X-Safe-Publish-Content-Hash', $params['headers'] );
		$this->assertSame( hash( 'sha256', $body ), $params['headers']['X-Safe-Publish-Content-Hash'] );
	}

	/**
	 * Verifies that empty body still produces a content hash.
	 */
	public function test_get_auth_params_includes_content_hash_for_empty_body(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'GET'
		);

		$this->assertArrayHasKey( 'X-Safe-Publish-Content-Hash', $params['headers'] );
		$this->assertSame( hash( 'sha256', '' ), $params['headers']['X-Safe-Publish-Content-Hash'] );
	}

	/**
	 * Verifies that different bodies produce different content hashes.
	 */
	public function test_different_bodies_produce_different_hashes(): void {
		$site_url    = 'https://example.com/wp-json/wp/v2/posts';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		$params_a = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'POST',
			'body A'
		);
		$params_b = VIP_Safe_Auth::get_auth_params(
			$site_url,
			Request_Actions::IMPORT,
			$auth_config,
			'POST',
			'body B'
		);

		$this->assertNotSame(
			$params_a['headers']['X-Safe-Publish-Content-Hash'],
			$params_b['headers']['X-Safe-Publish-Content-Hash']
		);
	}

	/**
	 * Resets the HTTP response stub between probe tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		reset_test_http_response();
		parent::tearDown();
	}

	/**
	 * Verifies that an empty source URL maps to the url_unset status.
	 */
	public function test_test_authorization_returns_url_unset_when_site_url_empty(): void {
		// ARRANGE: No site URL configured.
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe with an empty URL.
		$result = VIP_Safe_Auth::test_authorization( '', $auth_config );

		// ASSERT: Probe short-circuits with status only, before any HTTP call.
		$this->assertSame(
			array( 'status' => VIP_Safe_Auth::STATUS_URL_UNSET ),
			$result
		);
	}

	/**
	 * Verifies that an invalid credential format short-circuits the probe to
	 * unauthorized before issuing any HTTP request.
	 */
	public function test_test_authorization_returns_unauthorized_for_invalid_credentials(): void {
		// ARRANGE: Shared secret is below the 16-char minimum.
		$site_url    = 'https://example.com';
		$auth_config = array( 'shared_secret' => 'too-short' );

		// ACT: Probe with a short secret.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: Probe short-circuits with status only, before any HTTP call.
		$this->assertSame(
			array( 'status' => VIP_Safe_Auth::STATUS_UNAUTHORIZED ),
			$result
		);
	}

	/**
	 * Verifies that a 200 response from the source maps to authorized.
	 */
	public function test_test_authorization_returns_authorized_on_200(): void {
		// ARRANGE: Stub a successful HTTP response.
		set_test_http_response( array( 'response' => array( 'code' => 200 ) ) );
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: 200 maps to authorized with the response code preserved.
		$this->assertSame( VIP_Safe_Auth::STATUS_AUTHORIZED, $result['status'] );
		$this->assertSame( 200, $result['code'] );
	}

	/**
	 * Verifies that a 401 carrying a Safe Publish authenticator code maps to
	 * unauthorized with that error code preserved.
	 */
	public function test_test_authorization_returns_unauthorized_on_401(): void {
		// ARRANGE: Stub a 401 whose body is a genuine Safe Publish rejection.
		set_test_http_response(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"code":"safe_publish_auth_invalid","data":{"status":401}}',
			)
		);
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: A safe_publish_auth_* body classifies as unauthorized.
		$this->assertSame( VIP_Safe_Auth::STATUS_UNAUTHORIZED, $result['status'] );
		$this->assertSame( 401, $result['code'] );
		$this->assertSame( 'safe_publish_auth_invalid', $result['error_code'] );
	}

	/**
	 * Verifies that a 403 carrying a Safe Publish authenticator code maps to
	 * unauthorized with that error code preserved.
	 */
	public function test_test_authorization_returns_unauthorized_on_403(): void {
		// ARRANGE: Stub a 403 whose body is a genuine Safe Publish rejection.
		set_test_http_response(
			array(
				'response' => array( 'code' => 403 ),
				'body'     => '{"code":"safe_publish_auth_invalid","data":{"status":403}}',
			)
		);
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: A safe_publish_auth_* body classifies as unauthorized.
		$this->assertSame( VIP_Safe_Auth::STATUS_UNAUTHORIZED, $result['status'] );
		$this->assertSame( 403, $result['code'] );
		$this->assertSame( 'safe_publish_auth_invalid', $result['error_code'] );
	}

	/**
	 * Verifies that a 403 carrying a foreign body code is classified as
	 * blocked, with the foreign code surfaced as the bounded error code.
	 */
	public function test_test_authorization_returns_blocked_on_403_foreign_body(): void {
		// ARRANGE: Stub a 403 from an upstream gate with its own error code.
		set_test_http_response(
			array(
				'response' => array( 'code' => 403 ),
				'body'     => '{"code":"forbidden_access","message":"Blocked"}',
			)
		);
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: A non-Safe-Publish code reads as an upstream block.
		$this->assertSame( VIP_Safe_Auth::STATUS_BLOCKED, $result['status'] );
		$this->assertSame( 403, $result['code'] );
		$this->assertSame( 'forbidden_access', $result['error_code'] );
	}

	/**
	 * Verifies that a 403 with an empty body is classified as blocked.
	 */
	public function test_test_authorization_returns_blocked_on_403_empty_body(): void {
		// ARRANGE: Stub a 403 with no body, as a bare gate would return.
		set_test_http_response(
			array(
				'response' => array( 'code' => 403 ),
				'body'     => '',
			)
		);
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: No body means no Safe Publish code, so it reads as blocked.
		$this->assertSame( VIP_Safe_Auth::STATUS_BLOCKED, $result['status'] );
		$this->assertSame( '', $result['error_code'] );
	}

	/**
	 * Verifies that a 401 with a non-JSON body is classified as blocked.
	 */
	public function test_test_authorization_returns_blocked_on_401_non_json_body(): void {
		// ARRANGE: Stub a 401 whose body is an HTML block page, not JSON.
		set_test_http_response(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '<html><body>Access denied</body></html>',
			)
		);
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: An unparseable body yields no code, so it reads as blocked.
		$this->assertSame( VIP_Safe_Auth::STATUS_BLOCKED, $result['status'] );
		$this->assertSame( '', $result['error_code'] );
	}

	/**
	 * Verifies that a 401 expired-timestamp code maps to unauthorized with the
	 * expired error code preserved.
	 */
	public function test_test_authorization_surfaces_expired_error_code(): void {
		// ARRANGE: Stub a 401 whose body reports an expired request timestamp.
		set_test_http_response(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"code":"safe_publish_auth_expired"}',
			)
		);
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: The expired code rides along on an unauthorized verdict.
		$this->assertSame( VIP_Safe_Auth::STATUS_UNAUTHORIZED, $result['status'] );
		$this->assertSame( 'safe_publish_auth_expired', $result['error_code'] );
	}

	/**
	 * Verifies that a 403 connected-URL-mismatch code maps to unauthorized with
	 * the mismatch error code preserved.
	 */
	public function test_test_authorization_surfaces_site_url_mismatch_error_code(): void {
		// ARRANGE: Stub a 403 whose body reports a connected-URL mismatch.
		set_test_http_response(
			array(
				'response' => array( 'code' => 403 ),
				'body'     => '{"code":"safe_publish_auth_site_url_mismatch"}',
			)
		);
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: The mismatch code rides along on an unauthorized verdict.
		$this->assertSame( VIP_Safe_Auth::STATUS_UNAUTHORIZED, $result['status'] );
		$this->assertSame(
			'safe_publish_auth_site_url_mismatch',
			$result['error_code']
		);
	}

	/**
	 * Verifies that a 500 no-secret code stays unreachable but surfaces the
	 * misconfiguration error code for the message layer.
	 */
	public function test_test_authorization_surfaces_misconfigured_error_code_on_500(): void {
		// ARRANGE: Stub a 500 whose body reports a missing shared secret.
		set_test_http_response(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => '{"code":"safe_publish_auth_no_secret"}',
			)
		);
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: A reachable-but-misconfigured 500 keeps the unreachable
		// status while carrying the config error code.
		$this->assertSame( VIP_Safe_Auth::STATUS_UNREACHABLE, $result['status'] );
		$this->assertSame( 'safe_publish_auth_no_secret', $result['error_code'] );
	}

	/**
	 * Verifies that a junk, oversized body code is sanitized to the safe
	 * character set and truncated to 64 characters.
	 */
	public function test_test_authorization_sanitizes_and_truncates_error_code(): void {
		// ARRANGE: Stub a 403 whose code has disallowed characters and runs
		// past the 64-character cap.
		set_test_http_response(
			array(
				'response' => array( 'code' => 403 ),
				'body'     => '{"code":"' . str_repeat( 'a', 70 ) . ' <b>"}',
			)
		);
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: Disallowed characters are stripped and the result is capped
		// at 64 characters.
		$this->assertSame( str_repeat( 'a', 64 ), $result['error_code'] );
		$this->assertSame( VIP_Safe_Auth::STATUS_BLOCKED, $result['status'] );
	}

	/**
	 * Verifies that a WP_Error from the HTTP transport maps to unreachable.
	 */
	public function test_test_authorization_returns_unreachable_on_wp_error(): void {
		// ARRANGE: Stub the transport to return a WP_Error.
		set_test_http_response( new WP_Error( 'http_request_failed', 'Connection refused' ) );
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: Network failures map to unreachable with the error message.
		$this->assertSame( VIP_Safe_Auth::STATUS_UNREACHABLE, $result['status'] );
		$this->assertSame( 'Connection refused', $result['message'] );
	}

	/**
	 * Verifies that an unexpected response code (e.g. 500) maps to unreachable.
	 */
	public function test_test_authorization_returns_unreachable_on_unexpected_code(): void {
		// ARRANGE: Stub a 500 HTTP response.
		set_test_http_response( array( 'response' => array( 'code' => 500 ) ) );
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		$result = VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: Codes other than 200/401/403 are surfaced as unreachable.
		$this->assertSame( VIP_Safe_Auth::STATUS_UNREACHABLE, $result['status'] );
		$this->assertSame( 500, $result['code'] );
	}

	/**
	 * Verifies that the probe targets the wp/v2/posts edit-context endpoint
	 * and forwards signed headers.
	 */
	public function test_test_authorization_targets_posts_edit_endpoint_with_auth_headers(): void {
		// ARRANGE: Stub a successful response so the probe completes.
		set_test_http_response( array( 'response' => array( 'code' => 200 ) ) );
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe and capture the URL/args sent to the HTTP transport.
		VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

		// ASSERT: URL points at the edit-context posts endpoint with signed headers.
		$this->assertStringContainsString( '/wp-json/wp/v2/posts', $GLOBALS['_test_http_last_url'] );
		$this->assertStringContainsString( 'context=edit', $GLOBALS['_test_http_last_url'] );
		$this->assertStringContainsString( 'per_page=1', $GLOBALS['_test_http_last_url'] );
		$this->assertArrayHasKey( 'X-Safe-Publish-Signature', $GLOBALS['_test_http_last_args']['headers'] );
	}

	/**
	 * Verifies that the auth probe forwards the response-size cap to the
	 * transport.
	 */
	public function test_test_authorization_bounds_response_size(): void {
		// ARRANGE: Stub a successful response so the probe completes.
		set_test_http_response( array( 'response' => array( 'code' => 200 ) ) );
		$site_url    = 'https://example.com';
		$auth_config = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Probe the source.
		VIP_Safe_Auth::test_authorization( $site_url, $auth_config );

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
}
