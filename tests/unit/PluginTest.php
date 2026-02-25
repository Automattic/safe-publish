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

		$this->assertNotNull( $this->plugin->get_safe_publish_api() );
	}

	/**
	 * Verifies that get_safe_publish_api returns a Safe_Publish_API instance.
	 */
	public function test_get_safe_publish_api_returns_safe_publish_api(): void {
		$this->plugin->init();

		$safe_publish_api = $this->plugin->get_safe_publish_api();
		$this->assertInstanceOf( \Safe_Publish\API\Safe_Publish_API::class, $safe_publish_api );
	}
}
