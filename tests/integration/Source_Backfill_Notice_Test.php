<?php
/**
 * Integration tests for the source-site backfill admin notice.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Source_Backfill_Notice;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Source_Site_Url_Backfill;

/**
 * Exercises the dismissible notice shown when the backfill flags a destination
 * whose imports span more than one source.
 */
class Source_Backfill_Notice_Test extends Integration_Test_Case {

	/**
	 * A plugin screen the notice is allowed to render on.
	 */
	private const PLUGIN_SCREEN = 'toplevel_page_safe-publish';

	/**
	 * History repository used to seed multi-source history.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Sets up the repository and a non-AJAX admin screen context.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		// The backfill skips AJAX, and a prior AJAX test class may have defined
		// DOING_AJAX for the process; force a non-AJAX context.
		add_filter( 'wp_doing_ajax', '__return_false' );

		// render_notice() reads the current screen.
		/** @psalm-suppress MissingFile */
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		$this->repository = new History_Repository();
	}

	/**
	 * Tears down the screen context and filter.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'wp_doing_ajax', '__return_false' );
		unset( $GLOBALS['current_screen'] );
		parent::tearDown();
	}

	/**
	 * Verifies that the notice renders on a plugin screen once the backfill
	 * flags the destination for attention.
	 */
	public function test_renders_on_plugin_screen_when_attention_needed(): void {
		// ARRANGE: a flagged destination on a plugin screen.
		$this->flag_needs_attention();
		$this->set_screen( self::PLUGIN_SCREEN );

		// ACT: render.
		$output = $this->render();

		// ASSERT: the notice markup is emitted.
		$this->assertStringContainsString(
			'safe-publish-backfill-notice',
			$output
		);
	}

	/**
	 * Verifies that the notice is hidden once the current user dismisses it.
	 */
	public function test_hidden_after_user_dismissal(): void {
		// ARRANGE: a flagged destination the user has dismissed.
		$this->flag_needs_attention();
		$this->set_screen( self::PLUGIN_SCREEN );
		update_user_meta(
			get_current_user_id(),
			Source_Site_Url_Backfill::NOTICE_DISMISSED_META,
			'1'
		);

		// ACT & ASSERT: nothing renders.
		$this->assertSame( '', $this->render() );
	}

	/**
	 * Verifies that the notice does not render when no attention is needed.
	 */
	public function test_hidden_when_no_attention_needed(): void {
		// ARRANGE: a plugin screen but no flagged state.
		$this->set_screen( self::PLUGIN_SCREEN );

		// ACT & ASSERT: nothing renders.
		$this->assertFalse( Source_Site_Url_Backfill::needs_attention() );
		$this->assertSame( '', $this->render() );
	}

	/**
	 * Verifies that the notice does not render on unrelated admin screens.
	 */
	public function test_hidden_on_unrelated_screen(): void {
		// ARRANGE: a flagged destination on a non-plugin screen.
		$this->flag_needs_attention();
		$this->set_screen( 'dashboard' );

		// ACT & ASSERT: nothing renders.
		$this->assertSame( '', $this->render() );
	}

	/**
	 * Drives the backfill into its needs-attention state via multi-source
	 * history and a keyless post.
	 */
	private function flag_needs_attention(): void {
		$this->repository->create_session( 'https://source-a.example.com', 'single' );
		$this->repository->create_session( 'https://source-b.example.com', 'single' );

		$post_id = self::factory()->post->create();
		$this->assertIsInt( $post_id );
		update_post_meta( $post_id, Options::META_SOURCE_POST_ID, 10 );

		Source_Site_Url_Backfill::maybe_run();
		$this->assertTrue( Source_Site_Url_Backfill::needs_attention() );
	}

	/**
	 * Sets the current admin screen by ID.
	 *
	 * @param string $screen_id Screen ID to expose via get_current_screen().
	 */
	private function set_screen( string $screen_id ): void {
		set_current_screen( $screen_id );
	}

	/**
	 * Captures the notice's rendered output.
	 *
	 * @return string Rendered markup, or '' when nothing is emitted.
	 */
	private function render(): string {
		ob_start();
		( new Source_Backfill_Notice() )->render_notice();

		return trim( (string) ob_get_clean() );
	}
}
