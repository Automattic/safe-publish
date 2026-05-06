<?php
/**
 * External Posts API Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\API\External_Posts_API;
use Safe_Publish\Auth\VIP_Safe_Auth;

/**
 * External Posts API Test.
 *
 * Tests the external API integration functionality.
 */
class ExternalPostsAPITest extends TestCase {

	/**
	 * @var External_Posts_API External Posts API instance for testing.
	 */
	private External_Posts_API $api;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->api = new External_Posts_API();
	}

	/**
	 * Verifies that the External Posts API initializes correctly.
	 */
	public function test_api_initializes(): void {
		$this->assertInstanceOf( External_Posts_API::class, $this->api );
	}

	/**
	 * Verifies that fetch_posts returns an error for invalid URLs.
	 */
	public function test_fetch_posts_with_invalid_url_returns_error(): void {
		$result = $this->api->fetch_posts( 'invalid-url', 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Verifies that fetch_posts returns an error for empty URLs.
	 */
	public function test_fetch_posts_with_empty_url_returns_error(): void {
		$result = $this->api->fetch_posts( '', 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
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
			VIP_Safe_Auth::STATUS_UNREACHABLE,
			VIP_Safe_Auth::STATUS_URL_UNSET,
		);

		// ACT + ASSERT: Each status maps to a non-empty description.
		foreach ( $statuses as $status ) {
			$description = External_Posts_API::describe_auth_status( $status );
			$this->assertIsString( $description );
			$this->assertNotSame( '', $description );
		}
	}

	/**
	 * Verifies that fetch_fresh_post_content returns false for invalid URLs.
	 */
	public function test_fetch_fresh_post_content_with_invalid_url_returns_false(): void {
		$result = $this->api->fetch_fresh_post_content( 123, 'invalid-url' );

		$this->assertFalse( $result );
	}
}
