<?php
/**
 * Integration tests for pre-authentication audit rows written during dispatch
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Sync_Export;

use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Log_Events;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Pre Auth Dispatch Log Test Class.
 *
 * These tests run exclusively via phpunit-integration-sync-export.xml, the one
 * suite where Plugin::init() registers the authenticator on rest_pre_dispatch.
 * They drive that production wiring with anonymous requests.
 */
class Pre_Auth_Dispatch_Log_Test extends WP_UnitTestCase {

	/**
	 * Sets up each test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( 0 );

		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'auth' );

		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wp_rest_server = new WP_REST_Server();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );
	}

	/**
	 * Tears down each test.
	 */
	#[\Override]
	protected function tearDown(): void {
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * Verifies that repeated anonymous requests carrying the authentication
	 * headers add one audit row rather than one row per request.
	 */
	public function test_repeated_anonymous_requests_add_one_row(): void {
		// ARRANGE: Ten identical anonymous requests carrying the headers.
		$responses = array();

		// ACT: Dispatch them through rest_pre_dispatch.
		for ( $i = 0; $i < 10; $i++ ) {
			$responses[] = rest_do_request( $this->probe_request() );
		}

		// ASSERT: The caller stayed anonymous and every request was rejected.
		$this->assertSame( 0, get_current_user_id() );
		foreach ( $responses as $response ) {
			$this->assertSame( 401, $response->get_status() );
		}

		// ASSERT: One row covers the window.
		$events = $this->auth_events();
		$this->assertCount( 1, $events );
		$this->assertSame( Log_Events::TIMESTAMP_EXPIRED, $events[0]['event'] );
	}

	/**
	 * Verifies that an anonymous request without the authentication headers
	 * is served normally and adds no audit row.
	 */
	public function test_request_without_headers_adds_no_row(): void {
		// ARRANGE: An anonymous request carrying no plugin headers.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );

		// ACT: Dispatch it.
		$response = rest_do_request( $request );

		// ASSERT: It is served and nothing is recorded.
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $this->auth_events() );
	}

	/**
	 * Builds an anonymous request carrying a stale timestamp.
	 *
	 * @return WP_REST_Request Request rejected before authentication.
	 */
	private function probe_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_header( 'X-Safe-Publish-Timestamp', '1' );
		$request->set_header( 'X-Safe-Publish-Signature', 'invalid' );

		return $request;
	}

	/**
	 * Reads every row recorded on the auth channel.
	 *
	 * @return array Audit rows.
	 */
	private function auth_events(): array {
		return Audit_Log_Table::get_events( array( 'channel' => 'auth' ) );
	}
}
