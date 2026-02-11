<?php
/**
 * Integration tests for Plugin class
 *
 * These tests run in a real WordPress environment with a database.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Plugin;
use Safe_Publish\API\External_Posts_API;
use Safe_Publish\Admin\Admin_Handler;
use WP_UnitTestCase;

/**
 * Test Plugin class in WordPress environment.
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Set up test.
	 */
	#[\Override]
	public function set_up(): void {
		parent::set_up();
		$this->plugin = new Plugin();
		$this->plugin->init();
	}

	/**
	 * Test that plugin initializes successfully.
	 */
	public function test_plugin_initializes(): void {
		$this->assertInstanceOf( Plugin::class, $this->plugin );
	}

	/**
	 * Test that API is initialized.
	 */
	public function test_api_is_initialized(): void {
		$api = $this->plugin->get_api();

		$this->assertInstanceOf( External_Posts_API::class, $api );
		$this->assertNotNull( $api );
	}

	/**
	 * Test that admin handler is initialized.
	 */
	public function test_admin_handler_is_initialized(): void {
		$admin_handler = $this->plugin->get_admin_handler();

		$this->assertInstanceOf( Admin_Handler::class, $admin_handler );
		$this->assertNotNull( $admin_handler );
	}

	/**
	 * Test that Safe Publish API is initialized.
	 */
	public function test_safe_publish_api_is_initialized(): void {
		$safe_publish_api = $this->plugin->get_safe_publish_api();

		$this->assertNotNull( $safe_publish_api );
	}

	/**
	 * Test WordPress is loaded (integration test sanity check).
	 */
	public function test_wordpress_is_loaded(): void {
		// Verify WordPress functions are available.
		$this->assertTrue( function_exists( 'add_action' ) );
		$this->assertTrue( function_exists( 'register_post_type' ) );
		$this->assertTrue( defined( 'ABSPATH' ) );
	}

	/**
	 * Test database is available.
	 */
	public function test_database_is_available(): void {
		global $wpdb;

		$this->assertNotNull( $wpdb );
		$this->assertIsObject( $wpdb );

		// Test a simple query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( 'SELECT 1' );
		$this->assertEquals( '1', $result );
	}

	/**
	 * Test creating a WordPress post (demonstrates full integration).
	 */
	public function test_can_create_wordpress_post(): void {
		$post_id = $this->factory()->post->create(
			array(
				'post_title'   => 'Integration Test Post',
				'post_content' => 'This is a test post created by integration tests.',
				'post_status'  => 'publish',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		$post = get_post( $post_id );
		$this->assertInstanceOf( 'WP_Post', $post );
		$this->assertEquals( 'Integration Test Post', $post->post_title );
		$this->assertEquals( 'publish', $post->post_status );
	}
}
