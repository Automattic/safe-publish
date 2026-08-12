<?php
/**
 * Integration tests for the post-import admin notice.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Post_Import_Notice;
use WP_UnitTestCase;

/**
 * Post Import Notice Test Class.
 *
 * Cases render on the Settings screen unless one sets another screen.
 */
class Post_Import_Notice_Test extends WP_UnitTestCase {

	/**
	 * Session id recorded with every batch under test.
	 */
	private const SESSION_ID = 42;

	/**
	 * Class identifying the notice wrapper.
	 */
	private const NOTICE_CLASS = 'safe-publish-post-import-notice';

	/**
	 * Signs in an administrator and enters a plugin screen.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'administrator' ) )
		);
		set_current_screen( 'safe-publish_page_safe-publish-settings' );
	}

	/**
	 * Restores the default screen after each test.
	 */
	#[\Override]
	protected function tearDown(): void {
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * Verifies that a batch where nothing succeeded renders at error severity
	 * and links to the Needs attention inbox.
	 */
	public function test_failures_only_batch_renders_error_severity(): void {
		// ARRANGE: Record a batch in which both posts failed.
		Post_Import_Notice::record( self::SESSION_ID, 2, 0, 2 );

		// ACT: Render the notice.
		$output = $this->capture_notice();

		// ASSERT: Error severity, routed to the inbox.
		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringNotContainsString( 'notice-warning', $output );
		$this->assertStringNotContainsString( 'notice-info', $output );
		$this->assertStringContainsString( 'tab=needs-attention', $output );
		$this->assertStringContainsString( 'View failures', $output );
	}

	/**
	 * Verifies that a batch mixing successes and failures renders at warning
	 * severity and links to the imported posts.
	 */
	public function test_mixed_batch_renders_warning_severity(): void {
		// ARRANGE: Record a batch with one success and one failure.
		Post_Import_Notice::record( self::SESSION_ID, 2, 1, 1 );

		// ACT: Render the notice.
		$output = $this->capture_notice();

		// ASSERT: Warning severity, routed to the imports.
		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringNotContainsString( 'notice-error', $output );
		$this->assertStringNotContainsString( 'notice-info', $output );
		$this->assertStringContainsString( 'View imports', $output );
	}

	/**
	 * Verifies that a batch without failures keeps the informational severity.
	 */
	public function test_clean_batch_renders_info_severity(): void {
		// ARRANGE: Record a batch in which every post succeeded.
		Post_Import_Notice::record( self::SESSION_ID, 2, 2, 0 );

		// ACT: Render the notice.
		$output = $this->capture_notice();

		// ASSERT: Informational severity, with neither failure severity.
		$this->assertStringContainsString( 'notice-info', $output );
		$this->assertStringNotContainsString( 'notice-error', $output );
		$this->assertStringNotContainsString( 'notice-warning', $output );
	}

	/**
	 * Verifies that the Manage screen renders the notice on every load, since
	 * that is the screen operators import from and return to.
	 */
	public function test_manage_screen_renders_on_every_load(): void {
		// ARRANGE: Record a batch, then enter the Manage screen.
		Post_Import_Notice::record( self::SESSION_ID, 2, 2, 0 );
		set_current_screen( 'toplevel_page_safe-publish' );

		// ACT: Load the screen twice.
		$first  = $this->capture_notice();
		$second = $this->capture_notice();

		// ASSERT: Both loads render it; rendering doesn't consume the batch.
		$this->assertStringContainsString( self::NOTICE_CLASS, $first );
		$this->assertStringContainsString( self::NOTICE_CLASS, $second );
	}

	/**
	 * Verifies that no notice renders when no batch was recorded.
	 */
	public function test_no_recorded_batch_renders_nothing(): void {
		// ACT: Render without recording a batch.
		$output = $this->capture_notice();

		// ASSERT: Nothing is emitted.
		$this->assertSame( '', $output );
	}

	/**
	 * Verifies that a screen outside the plugin renders nothing, keeping the
	 * inline dismiss script off unrelated admin pages.
	 */
	public function test_screen_outside_the_plugin_renders_nothing(): void {
		// ARRANGE: Record a batch, then move to an unrelated admin screen.
		Post_Import_Notice::record( self::SESSION_ID, 2, 2, 0 );
		set_current_screen( 'dashboard' );

		// ACT: Render on that screen.
		$output = $this->capture_notice();

		// ASSERT: Nothing is emitted.
		$this->assertSame( '', $output );
	}

	/**
	 * Captures the notice markup for the current screen and user.
	 *
	 * @return string Rendered markup, empty when nothing renders.
	 */
	private function capture_notice(): string {
		ob_start();
		( new Post_Import_Notice() )->render_notice();

		return (string) ob_get_clean();
	}
}
