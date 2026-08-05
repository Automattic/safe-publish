<?php
/**
 * Settings Page Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Admin\Settings_Page;
use Safe_Publish\Utils\Options;

/**
 * Settings Page Test.
 *
 * Tests settings page rendering behavior.
 */
class SettingsPageTest extends TestCase {

	/**
	 * Resets test option overrides after each test.
	 */
	#[\Override]
	protected function tearDown(): void {
		parent::tearDown();
		reset_test_options();
	}

	/**
	 * Verifies that externally configured passwords are not rendered into HTML.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_hides_externally_configured_password(): void {
		// ARRANGE: Define the password constant and import-mode settings.
		define( 'SAFE_PUBLISH_BASIC_AUTH_PASSWORD', 'constant-password' );
		set_test_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );
		set_test_option( Options::OPTION_SYNC_MODE, Options::SYNC_MODE_IMPORT );
		set_test_option( Options::OPTION_BASIC_AUTH_USERNAME, 'editor' );

		// ACT: Render the settings page markup.
		ob_start();
		( new Settings_Page() )->render();
		$output = (string) ob_get_clean();

		// ASSERT: The constant value is replaced with an external placeholder.
		$this->assertStringContainsString( 'Configured externally', $output );
		$this->assertStringContainsString( 'data-configured-externally="1"', $output );
		$this->assertStringNotContainsString( 'constant-password', $output );
		$this->assertStringNotContainsString(
			'name="safe_publish_basic_auth_password"',
			$output
		);
	}

	/**
	 * Verifies that stored user-entered passwords still render into the form.
	 */
	public function test_render_keeps_user_entered_password_editable(): void {
		// ARRANGE: Store import-mode settings and a saved Basic Auth password.
		set_test_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );
		set_test_option( Options::OPTION_SYNC_MODE, Options::SYNC_MODE_IMPORT );
		set_test_option( Options::OPTION_BASIC_AUTH_PASSWORD, 'user-password' );

		// ACT: Render the settings page markup.
		ob_start();
		( new Settings_Page() )->render();
		$output = (string) ob_get_clean();

		// ASSERT: The stored password remains editable in the settings form.
		$this->assertStringContainsString(
			'name="safe_publish_basic_auth_password"',
			$output
		);
		$this->assertStringContainsString( 'value="user-password"', $output );
		$this->assertStringNotContainsString( 'Configured externally', $output );
	}

	/**
	 * Verifies that the settings page markup no longer carries an inline
	 * script; the behavior now ships as an enqueued file.
	 */
	public function test_render_emits_no_inline_script(): void {
		// ARRANGE: Enable import mode so the full settings form renders.
		set_test_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );
		set_test_option( Options::OPTION_SYNC_MODE, Options::SYNC_MODE_IMPORT );

		// ACT: Render the settings page markup.
		ob_start();
		( new Settings_Page() )->render();
		$output = (string) ob_get_clean();

		// ASSERT: Neither a script tag nor the data global is printed.
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringNotContainsString( 'safePublishSettingsData', $output );
	}
}
