<?php
/**
 * Source Posts API Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Auth\VIP_Safe_Auth;
use Safe_Publish\Utils\Options;
use WP_Error;

/**
 * Source Posts API Test.
 *
 * Tests the source API integration functionality.
 */
class SourcePostsAPITest extends TestCase {

	/**
	 * @var Source_Posts_API Source Posts API instance for testing.
	 */
	private Source_Posts_API $api;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->api = new Source_Posts_API();
	}

	/**
	 * Verifies that the Source Posts API initializes correctly.
	 */
	public function test_api_initializes(): void {
		$this->assertInstanceOf( Source_Posts_API::class, $this->api );
	}

	/**
	 * Verifies that fetch_posts returns an error for invalid URLs.
	 */
	public function test_fetch_posts_with_invalid_url_returns_error(): void {
		$result = $this->api->fetch_posts( 'invalid-url' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Verifies that fetch_posts returns an error for empty URLs.
	 */
	public function test_fetch_posts_with_empty_url_returns_error(): void {
		$result = $this->api->fetch_posts( '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Verifies that test_connection returns the expected array shape with the
	 * new status field.
	 */
	public function test_test_connection_returns_array(): void {
		// ARRANGE: No credentials so the probe short-circuits to unauthorized.
		// ACT: Run the connection test.
		$result = $this->api->test_connection( 'https://example.com', array() );

		// ASSERT: Response carries success, status, response_time, and message.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'response_time', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Verifies that test_connection reports failure when the probe is
	 * unauthorized and exposes the unauthorized status.
	 */
	public function test_test_connection_reports_unauthorized_when_no_credentials(): void {
		// ARRANGE: No credentials, so the format check rejects the probe.
		// ACT: Run the connection test against a configured URL.
		$result = $this->api->test_connection( 'https://example.com', array() );

		// ASSERT: success is false and status mirrors the probe verdict.
		$this->assertFalse( $result['success'] );
		$this->assertSame( VIP_Safe_Auth::STATUS_UNAUTHORIZED, $result['status'] );
	}

	/**
	 * Verifies that test_connection reports url_unset when no URL is supplied.
	 */
	public function test_test_connection_reports_url_unset_for_empty_url(): void {
		// ARRANGE: Empty URL exercises the url_unset short-circuit.
		// ACT: Run the connection test.
		$result = $this->api->test_connection( '', array() );

		// ASSERT: success is false and status is url_unset.
		$this->assertFalse( $result['success'] );
		$this->assertSame( VIP_Safe_Auth::STATUS_URL_UNSET, $result['status'] );
	}

	/**
	 * Verifies that test_connection reports success when the probe authorizes.
	 */
	public function test_test_connection_reports_success_when_probe_authorized(): void {
		// ARRANGE: Stub a successful probe HTTP response.
		set_test_http_response( array( 'response' => array( 'code' => 200 ) ) );
		$auth_credentials = array(
			'shared_secret' => 'test_secret_key_that_is_long_enough_for_validation',
		);

		// ACT: Run the connection test.
		$result = $this->api->test_connection( 'https://example.com', $auth_credentials );

		// ASSERT: success is true and status is authorized.
		$this->assertTrue( $result['success'] );
		$this->assertSame( VIP_Safe_Auth::STATUS_AUTHORIZED, $result['status'] );

		reset_test_http_response();
	}

	/**
	 * Verifies that describe_auth_status returns a non-empty translated
	 * message for every known status value.
	 */
	public function test_describe_auth_status_covers_all_known_statuses(): void {
		// ARRANGE: Enumerate every probe status defined on VIP_Safe_Auth.
		$statuses = array(
			VIP_Safe_Auth::STATUS_AUTHORIZED,
			VIP_Safe_Auth::STATUS_UNAUTHORIZED,
			VIP_Safe_Auth::STATUS_BLOCKED,
			VIP_Safe_Auth::STATUS_UNREACHABLE,
			VIP_Safe_Auth::STATUS_URL_UNSET,
		);

		// ACT + ASSERT: Each status maps to a non-empty description.
		foreach ( $statuses as $status ) {
			$description = Source_Posts_API::describe_auth_status( $status );
			$this->assertIsString( $description );
			$this->assertNotSame( '', $description );
		}
	}

	/**
	 * Verifies that describe_auth_status returns the upstream-block message for
	 * the blocked status.
	 */
	public function test_describe_auth_status_blocked_explains_upstream_block(): void {
		// ARRANGE + ACT: Describe a blocked probe.
		$message = Source_Posts_API::describe_auth_status(
			VIP_Safe_Auth::STATUS_BLOCKED
		);

		// ASSERT: The message names the upstream restriction and the fix.
		$this->assertStringContainsString( 'blocked the request', $message );
		$this->assertStringContainsString( 'safe-publish/v1', $message );
	}

	/**
	 * Verifies that an unauthorized probe with a connected-URL-mismatch code
	 * yields the URL-mismatch guidance, distinct from the generic secret
	 * message.
	 */
	public function test_describe_auth_status_unauthorized_url_mismatch(): void {
		// ARRANGE + ACT: Describe the mismatch variant and the generic one.
		$message = Source_Posts_API::describe_auth_status(
			VIP_Safe_Auth::STATUS_UNAUTHORIZED,
			'safe_publish_auth_site_url_mismatch'
		);
		$generic = Source_Posts_API::describe_auth_status(
			VIP_Safe_Auth::STATUS_UNAUTHORIZED,
			''
		);

		// ASSERT: The message addresses the connected-site URL and differs from
		// the generic secret message.
		$this->assertStringContainsString( 'connected-site URL', $message );
		$this->assertNotSame( $generic, $message );
	}

	/**
	 * Verifies that an unauthorized probe with an expired code yields the
	 * clock-skew guidance, distinct from the generic secret message.
	 */
	public function test_describe_auth_status_unauthorized_expired(): void {
		// ARRANGE + ACT: Describe the expired variant and the generic one.
		$message = Source_Posts_API::describe_auth_status(
			VIP_Safe_Auth::STATUS_UNAUTHORIZED,
			'safe_publish_auth_expired'
		);
		$generic = Source_Posts_API::describe_auth_status(
			VIP_Safe_Auth::STATUS_UNAUTHORIZED,
			''
		);

		// ASSERT: The message addresses clock skew and differs from the generic
		// secret message.
		$this->assertStringContainsString( 'clock', $message );
		$this->assertNotSame( $generic, $message );
	}

	/**
	 * Verifies that an unauthorized probe with the invalid code or no code both
	 * fall back to the shared-secret message.
	 */
	public function test_describe_auth_status_unauthorized_invalid_falls_back(): void {
		// ARRANGE + ACT: Describe the invalid-signature and no-code variants.
		$invalid = Source_Posts_API::describe_auth_status(
			VIP_Safe_Auth::STATUS_UNAUTHORIZED,
			'safe_publish_auth_invalid'
		);
		$empty   = Source_Posts_API::describe_auth_status(
			VIP_Safe_Auth::STATUS_UNAUTHORIZED,
			''
		);

		// ASSERT: Both yield the same shared-secret message.
		$this->assertStringContainsString(
			'SAFE_PUBLISH_SHARED_SECRET',
			$invalid
		);
		$this->assertSame( $empty, $invalid );
	}

	/**
	 * Verifies that an unreachable probe carrying a Safe Publish config code
	 * yields the not-fully-configured message, distinct from the generic
	 * unreachable message.
	 */
	public function test_describe_auth_status_unreachable_misconfigured(): void {
		// ARRANGE + ACT: Describe a 500-config unreachable and a generic one.
		$configured = Source_Posts_API::describe_auth_status(
			VIP_Safe_Auth::STATUS_UNREACHABLE,
			'safe_publish_auth_no_secret'
		);
		$generic    = Source_Posts_API::describe_auth_status(
			VIP_Safe_Auth::STATUS_UNREACHABLE,
			''
		);

		// ASSERT: The config variant names the misconfiguration and differs
		// from the generic unreachable message.
		$this->assertStringContainsString( 'not fully configured', $configured );
		$this->assertNotSame( $generic, $configured );
	}

	/**
	 * Verifies that fetch_fresh_post_content returns the invalid-URL WP_Error
	 * for an unusable source site URL, so the caller can surface the reason.
	 */
	public function test_fetch_fresh_post_content_with_invalid_url_returns_error(): void {
		// ARRANGE: An invalid source site URL the URL_Validator rejects.
		// ACT: Fetch fresh content for that URL.
		$result = $this->api->fetch_fresh_post_content( 123, 'invalid-url' );

		// ASSERT: Returns the distinct invalid-URL WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_invalid_url',
			$result->get_error_code()
		);
	}

	/**
	 * Verifies that fetch_fresh_post returns the "no connected
	 * site URL" WP_Error when the option is unset, so callers abort instead
	 * of issuing a request against an empty host.
	 */
	public function test_fetch_fresh_post_returns_error_when_url_unset(): void {
		// ARRANGE: Connected site URL option is empty.
		set_test_option( Options::OPTION_CONNECTED_SITE_URL, '' );

		// ACT: Invoke the wrapper.
		$result = $this->api->fetch_fresh_post( 123, 'posts' );

		// ASSERT: Returns the explicit "no connected site URL" WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_fetch_no_connected_site_url',
			$result->get_error_code()
		);

		reset_test_options();
	}

	/**
	 * Verifies that fetch_fresh_post propagates the specific WP_Error the
	 * underlying fetch_fresh_post_content returns, so the import abort surfaces
	 * the reason instead of a generic message.
	 */
	public function test_fetch_fresh_post_propagates_underlying_error(): void {
		// ARRANGE: Configure a URL the URL_Validator rejects so the underlying
		// fetch_fresh_post_content returns the invalid-URL WP_Error.
		set_test_option( Options::OPTION_CONNECTED_SITE_URL, 'not-a-real-url' );

		// ACT: Invoke the wrapper.
		$result = $this->api->fetch_fresh_post( 123, 'posts' );

		// ASSERT: The specific invalid-URL code propagates through.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'fresh_content_invalid_url',
			$result->get_error_code()
		);

		reset_test_options();
	}
}
