<?php
/**
 * Integration tests for settings page rendering
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Settings_Page;
use Safe_Publish\Utils\Options;
use WP_UnitTestCase;

/**
 * Settings Page Render Test Class.
 */
class Settings_Page_Render_Test extends WP_UnitTestCase {

	/**
	 * Markup identifying the Test Connection control.
	 */
	private const TEST_CONNECTION_CONTROL = 'id="safe-publish-test-connection"';

	/**
	 * Verifies that the Test Connection control is rendered only in the sync
	 * modes that register its AJAX handler.
	 */
	public function test_test_connection_control_tracks_import_modes(): void {
		// ACT: Render the page under every sync mode.
		$import        = $this->render_for_mode( Options::SYNC_MODE_IMPORT );
		$bidirectional = $this->render_for_mode( Options::SYNC_MODE_BIDIRECTIONAL );
		$export        = $this->render_for_mode( Options::SYNC_MODE_EXPORT );
		$unconfigured  = $this->render_for_mode( '' );

		// ASSERT: Only the modes registering the handler render the control.
		$this->assertStringContainsString( self::TEST_CONNECTION_CONTROL, $import );
		$this->assertStringContainsString( self::TEST_CONNECTION_CONTROL, $bidirectional );
		$this->assertStringNotContainsString( self::TEST_CONNECTION_CONTROL, $export );
		$this->assertStringNotContainsString( self::TEST_CONNECTION_CONTROL, $unconfigured );
	}

	/**
	 * Verifies that the Basic Auth fields stay in the markup outside the
	 * import modes, so the Sync Mode toggle can reveal them before saving.
	 */
	public function test_basic_auth_row_is_hidden_rather_than_omitted(): void {
		// ACT: Render with no sync mode saved, as on a fresh install.
		$html = $this->render_for_mode( '' );

		// ASSERT: The field is present and its row starts hidden.
		$this->assertStringContainsString(
			'id="safe_publish_basic_auth_username"',
			$html
		);
		$this->assertStringContainsString(
			'class="safe-publish-import-field-row hidden"',
			$html
		);
	}

	/**
	 * Renders the settings page under a sync mode.
	 *
	 * @param string $sync_mode Sync mode to render under.
	 * @return string Rendered markup.
	 */
	private function render_for_mode( string $sync_mode ): string {
		update_option( Options::OPTION_SYNC_MODE, $sync_mode );

		ob_start();
		( new Settings_Page() )->render();

		return (string) ob_get_clean();
	}
}
