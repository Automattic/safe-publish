<?php
/**
 * Integration tests for settings-only admin asset wiring
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Sync_Export;

use Safe_Publish\Admin\Settings_Page;
use WP_UnitTestCase;

/**
 * Settings Only Assets Test Class.
 *
 * These tests run exclusively via phpunit-integration-sync-export.xml, which
 * boots the plugin with WP_TEST_SYNC_MODE=export and so takes the
 * settings-only admin path, where Admin_Menu_Manager never runs.
 */
class Settings_Only_Assets_Test extends WP_UnitTestCase {

	/**
	 * Script handle registered by the settings page.
	 */
	private const HANDLE = 'safe-publish-settings-script';

	/**
	 * Enters the top-level settings screen and clears the handle.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		set_current_screen( 'toplevel_page_' . Settings_Page::PAGE_SLUG );
		$this->reset_handle();
	}

	/**
	 * Restores the default screen and the admin menu globals after each test.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->reset_handle();
		set_current_screen( 'front' );

		// PHPUnit does not back up globals, so clear what the menu test built.
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		global $menu, $submenu;
		$menu    = array();
		$submenu = array();
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

		parent::tearDown();
	}

	/**
	 * Verifies that the settings-only admin path enqueues the settings page
	 * assets, which no other code path registers in this mode.
	 */
	public function test_settings_only_admin_enqueues_assets(): void {
		// ACT: Fire the admin enqueue hook for the top-level settings screen.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'admin_enqueue_scripts', 'toplevel_page_' . Settings_Page::PAGE_SLUG );

		// ASSERT: The settings script is enqueued.
		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );
	}

	/**
	 * Verifies that the settings-only menu labels its own submenu entry
	 * instead of letting WordPress auto-generate a copy of the parent title.
	 */
	public function test_settings_only_menu_labels_its_own_submenu(): void {
		// ARRANGE: Act as a user who can manage the plugin.
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'administrator' ) )
		);

		// ACT: Build the admin menu.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'admin_menu' );

		// ASSERT: Settings leads, followed by Audit Log, with no duplicate.
		global $submenu;
		$titles = wp_list_pluck( $submenu[ Settings_Page::PAGE_SLUG ], 0 );

		$this->assertSame( array( 'Settings', 'Audit Log' ), $titles );
	}

	/**
	 * Dequeues and deregisters the settings handle to isolate each test.
	 */
	private function reset_handle(): void {
		wp_dequeue_script( self::HANDLE );
		wp_deregister_script( self::HANDLE );
	}
}
