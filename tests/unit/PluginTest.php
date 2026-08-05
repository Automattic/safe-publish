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
	 * Tears down test fixtures.
	 */
	#[\Override]
	protected function tearDown(): void {
		reset_test_options();
		parent::tearDown();
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
		set_test_option( 'safe_publish_sync_mode', 'import' );
		set_test_option( 'safe_publish_connected_site_url', 'https://example.com' );

		$this->plugin->init();

		$this->assertNotNull( $this->plugin->get_safe_publish_api() );
	}

	/**
	 * Verifies that get_safe_publish_api returns a Safe_Publish_API instance.
	 */
	public function test_get_safe_publish_api_returns_safe_publish_api(): void {
		set_test_option( 'safe_publish_sync_mode', 'import' );
		set_test_option( 'safe_publish_connected_site_url', 'https://example.com' );

		$this->plugin->init();

		$safe_publish_api = $this->plugin->get_safe_publish_api();
		$this->assertInstanceOf( \Safe_Publish\API\Safe_Publish_API::class, $safe_publish_api );
	}

	/**
	 * Verifies that the plugin's admin screens are appended to the Pendo
	 * allowed-screens list, preserving any screens registered by other sources.
	 */
	public function test_register_pendo_screens_appends_plugin_screens(): void {
		// ARRANGE: An existing allow-list from another telemetry consumer.
		$existing = array( 'plugins.php' );

		// ACT: Register the plugin's admin screens.
		$result = $this->plugin->register_pendo_screens( $existing );

		// ASSERT: The original screen is kept and every plugin screen is added.
		$this->assertSame(
			array(
				'plugins.php',
				'toplevel_page_safe-publish',
				'toplevel_page_safe-publish-settings',
				'safe-publish_page_safe-publish-settings',
				'safe-publish_page_safe-publish-exports',
				'safe-publish_page_safe-publish-audit-log',
			),
			$result
		);
	}
}
