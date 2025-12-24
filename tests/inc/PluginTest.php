<?php
/**
 * Plugin Test.
 *
 * @package Compliant_Content_Publisher
 */

declare(strict_types=1);

namespace CCP\Tests;

use PHPUnit\Framework\TestCase;
use CCP\Plugin;

/**
 * Plugin Test.
 *
 * Tests the main plugin class initialization and core functionality.
 */
class PluginTest extends TestCase {

	/**
	 * @var Plugin Plugin instance for testing.
	 */
	private Plugin $plugin;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->plugin = new Plugin();
	}

	/**
	 * Verifies that the plugin initializes correctly.
	 */
	public function test_plugin_initializes(): void {
		$this->assertInstanceOf( Plugin::class, $this->plugin );
	}

	/**
	 * Verifies that init creates required instances.
	 */
	public function test_init_creates_required_instances(): void {
		$this->plugin->init();

		$this->assertNotNull( $this->plugin->get_api() );
		$this->assertNotNull( $this->plugin->get_admin_handler() );
		$this->assertNotNull( $this->plugin->get_ccp_api() );
	}

	/**
	 * Verifies that get_api returns an External_Posts_API instance.
	 */
	public function test_get_api_returns_external_posts_api(): void {
		$this->plugin->init();

		$api = $this->plugin->get_api();
		$this->assertInstanceOf( \CCP\API\External_Posts_API::class, $api );
	}

	/**
	 * Verifies that get_admin_handler returns an Admin_Handler instance.
	 */
	public function test_get_admin_handler_returns_admin_handler(): void {
		$this->plugin->init();

		$admin_handler = $this->plugin->get_admin_handler();
		$this->assertInstanceOf( \CCP\Admin\Admin_Handler::class, $admin_handler );
	}

	/**
	 * Verifies that get_ccp_api returns a CCP_API instance.
	 */
	public function test_get_ccp_api_returns_ccp_api(): void {
		$this->plugin->init();

		$ccp_api = $this->plugin->get_ccp_api();
		$this->assertInstanceOf( \CCP\API\CCP_API::class, $ccp_api );
	}

	/**
	 * Verifies that get_admin_handler returns null before initialization.
	 */
	public function test_get_admin_handler_before_init_returns_null(): void {
		$admin_handler = $this->plugin->get_admin_handler();
		$this->assertNull( $admin_handler );
	}
}
