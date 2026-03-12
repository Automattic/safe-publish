<?php
/**
 * Integration tests for "send" sync direction behavior
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Sync_Send;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Verifies that in "send" sync direction only send functionality is active.
 *
 * These tests are run exclusively via phpunit-integration-sync-send.xml, which
 * boots the plugin with WP_TEST_SYNC_DIRECTION=send.
 */
class Sync_Send_Integration_Test extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private int $admin_user_id;

	/**
	 * Sets up each test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->admin_user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Squiz.PHP.DisallowMultipleAssignments.Found
		$this->server = $wp_rest_server = new WP_REST_Server();
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
	 * Verifies that REST routes for receiving are not registered in "send" sync
	 * direction.
	 */
	public function test_receive_rest_routes_are_not_registered(): void {
		$diff_response = $this->server->dispatch(
			new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' )
		);
		$this->assertSame( 404, $diff_response->get_status() );

		$update_response = $this->server->dispatch(
			new WP_REST_Request( 'POST', '/safe-publish/v1/update-post' )
		);
		$this->assertSame( 404, $update_response->get_status() );
	}

	/**
	 * Verifies that AJAX handlers for receiving are not registered in "send"
	 * sync direction.
	 */
	public function test_receive_ajax_handlers_are_not_registered(): void {
		$this->assertFalse( (bool) has_action( 'wp_ajax_safe_publish_fetch_posts' ) );
		$this->assertFalse( (bool) has_action( 'wp_ajax_safe_publish_fetch_post_types' ) );
		$this->assertFalse( (bool) has_action( 'wp_ajax_safe_publish_test_connection' ) );
		$this->assertFalse( (bool) has_action( 'wp_ajax_safe_publish_create_draft' ) );
		$this->assertFalse( (bool) has_action( 'wp_ajax_safe_publish_bulk_import' ) );
		$this->assertFalse( (bool) has_action( 'wp_ajax_safe_publish_debug_auth' ) );
	}

	/**
	 * Verifies that auth monitoring endpoints are registered in "send" sync
	 * direction.
	 *
	 * A non-404 response confirms the routes exist.
	 */
	public function test_auth_monitoring_endpoints_are_registered(): void {
		$status_response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/safe-publish/v1/auth-status' )
		);
		$this->assertNotSame( 404, $status_response->get_status() );

		$logs_response = $this->server->dispatch(
			new WP_REST_Request( 'GET', '/safe-publish/v1/auth-logs' )
		);
		$this->assertNotSame( 404, $logs_response->get_status() );
	}
}
