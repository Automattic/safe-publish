<?php
/**
 * Plugin Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Plugin;

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
	#[\Override]
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
		$this->assertNotNull( $this->plugin->get_safe_publish_api() );
	}

	/**
	 * Verifies that get_api returns an External_Posts_API instance.
	 */
	public function test_get_api_returns_external_posts_api(): void {
		$this->plugin->init();

		$api = $this->plugin->get_api();
		$this->assertInstanceOf( \Safe_Publish\API\External_Posts_API::class, $api );
	}

	/**
	 * Verifies that get_admin_handler returns an Admin_Handler instance.
	 */
	public function test_get_admin_handler_returns_admin_handler(): void {
		$this->plugin->init();

		$admin_handler = $this->plugin->get_admin_handler();
		$this->assertInstanceOf( \Safe_Publish\Admin\Admin_Handler::class, $admin_handler );
	}

	/**
	 * Verifies that get_safe_publish_api returns a Safe_Publish_API instance.
	 */
	public function test_get_safe_publish_api_returns_safe_publish_api(): void {
		$this->plugin->init();

		$safe_publish_api = $this->plugin->get_safe_publish_api();
		$this->assertInstanceOf( \Safe_Publish\API\Safe_Publish_API::class, $safe_publish_api );
	}

	/**
	 * Verifies that get_admin_handler returns null before initialization.
	 */
	public function test_get_admin_handler_before_init_returns_null(): void {
		$admin_handler = $this->plugin->get_admin_handler();
		$this->assertNull( $admin_handler );
	}
}
