<?php
/**
 * Integration tests for settings page asset enqueueing.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Settings_Page;
use Safe_Publish\Utils\Options;
use WP_UnitTestCase;

/**
 * Settings Page Assets Test Class.
 */
class Settings_Page_Assets_Test extends WP_UnitTestCase {

	/**
	 * Script handle registered by the settings page.
	 */
	private const HANDLE = 'safe-publish-settings-script';

	/**
	 * Enters an admin screen context and clears the handle.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		set_current_screen( 'safe-publish_page_safe-publish-settings' );
		$this->reset_handle();
	}

	/**
	 * Restores the default screen after each test.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->reset_handle();
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * Verifies that enqueue_assets registers the settings script from
	 * assets/js and marks it for the footer.
	 */
	public function test_enqueue_assets_registers_footer_script(): void {
		// ACT: Enqueue the settings page assets.
		( new Settings_Page() )->enqueue_assets();

		// ASSERT: The handle is enqueued from assets/js, in the footer.
		$this->assertTrue( wp_script_is( self::HANDLE, 'enqueued' ) );

		$scripts = wp_scripts();
		$this->assertStringContainsString(
			'assets/js/settings-page.js',
			(string) $scripts->registered[ self::HANDLE ]->src
		);
		$this->assertSame( SAFE_PUBLISH_VERSION, $scripts->registered[ self::HANDLE ]->ver );
		$this->assertSame( 1, $scripts->get_data( self::HANDLE, 'group' ) );
	}

	/**
	 * Verifies that enqueue_assets attaches the data global before the script,
	 * carrying the import modes, AJAX wiring, and translated strings.
	 */
	public function test_enqueue_assets_attaches_data_global(): void {
		// ACT: Enqueue the settings page assets.
		( new Settings_Page() )->enqueue_assets();

		// ASSERT: The inline "before" script decodes to the expected payload.
		$before = wp_scripts()->get_data( self::HANDLE, 'before' );
		$this->assertIsArray( $before );

		$inline = trim( implode( "\n", array_filter( $before ) ) );
		$this->assertStringStartsWith( 'window.safePublishSettingsData = ', $inline );

		$json = (string) preg_replace(
			'/^window\.safePublishSettingsData = (.*);$/s',
			'$1',
			$inline
		);
		$data = json_decode( $json, true );

		$this->assertIsArray( $data );
		$this->assertSame(
			array( Options::SYNC_MODE_IMPORT, Options::SYNC_MODE_BIDIRECTIONAL ),
			$data['importModes']
		);
		$this->assertSame( admin_url( 'admin-ajax.php' ), $data['ajaxUrl'] );
		$this->assertArrayHasKey( 'nonce', $data );
		$this->assertSame(
			array(
				'enterUrlFirst',
				'connectionFailed',
				'networkError',
				'statusUnavailable',
			),
			array_keys( $data['i18n'] )
		);
	}

	/**
	 * Dequeues and deregisters the settings handle to isolate each test.
	 */
	private function reset_handle(): void {
		wp_dequeue_script( self::HANDLE );
		wp_deregister_script( self::HANDLE );
	}
}
