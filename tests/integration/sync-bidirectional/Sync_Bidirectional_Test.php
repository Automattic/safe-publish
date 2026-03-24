<?php
/**
 * Integration tests for "bidirectional" sync mode behavior
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Sync_Bidirectional;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Verifies that in "bidirectional" sync mode all send and receive functionality
 * is active simultaneously.
 *
 * These tests are run exclusively via phpunit-integration-sync-bidirectional.xml,
 * which boots the plugin with WP_TEST_SYNC_MODE=bidirectional.
 */
class Sync_Bidirectional_Integration_Test extends WP_UnitTestCase {

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
	 * Verifies that REST routes for importing are registered in "bidirectional"
	 * sync mode.
	 *
	 * A non-404 response confirms the routes exist.
	 */
	public function test_import_rest_routes_are_registered(): void {
		$diff_response = $this->server->dispatch(
			new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' )
		);
		$this->assertNotSame( 404, $diff_response->get_status() );

		$update_response = $this->server->dispatch(
			new WP_REST_Request( 'POST', '/safe-publish/v1/update-post' )
		);
		$this->assertNotSame( 404, $update_response->get_status() );
	}

	/**
	 * Verifies that AJAX handlers for importing are registered in "bidirectional"
	 * sync mode.
	 */
	public function test_import_ajax_handlers_are_registered(): void {
		$this->assertNotFalse( has_action( 'wp_ajax_safe_publish_fetch_posts' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_safe_publish_fetch_post_types' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_safe_publish_test_connection' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_safe_publish_create_draft' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_safe_publish_bulk_import' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_safe_publish_debug_auth' ) );
	}

	/**
	 * Verifies that auth monitoring endpoints are registered in "bidirectional"
	 * sync mode.
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
