<?php
/**
 * Integration tests for the post-import notice dismiss endpoint.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Post_Import_Notice;
use WP_Ajax_UnitTestCase;

/**
 * Post Import Notice Ajax Test Class.
 *
 * Cases assert the batch exists before dispatching, so a wrong transient key
 * cannot make a cleared-batch assertion pass against a missing transient.
 */
class Post_Import_Notice_Ajax_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Dismiss action the notice registers.
	 */
	private const ACTION = 'safe_publish_dismiss_import_notice';

	/**
	 * Per-user transient prefix the notice records batches under.
	 */
	private const TRANSIENT_PREFIX = 'safe_publish_post_import_notice_';

	/**
	 * Registers the notice so its AJAX action is dispatchable.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		( new Post_Import_Notice() )->init();
	}

	/**
	 * Removes the management capability filter between tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_edit_posts_capability' )
		);
		parent::tearDown();
	}

	/**
	 * Verifies that dismissing clears the current user's recorded batch.
	 */
	public function test_dismiss_clears_the_recorded_batch(): void {
		// ARRANGE: An administrator holding a recorded batch.
		$this->sign_in( 'administrator' );
		Post_Import_Notice::record( 42, 2, 2, 0 );
		$this->assertIsArray( $this->recorded_batch() );

		// ACT: Dismiss over AJAX.
		$response = $this->dismiss();

		// ASSERT: Success, and the batch is gone.
		$this->assertTrue( $response['success'] );
		$this->assertFalse( $this->recorded_batch() );
	}

	/**
	 * Verifies that a user without the manage capability cannot clear a batch,
	 * so the endpoint is not a way to silence another role's notice.
	 */
	public function test_dismiss_without_the_capability_keeps_the_batch(): void {
		// ARRANGE: A subscriber holding a recorded batch of their own.
		$this->sign_in( 'subscriber' );
		Post_Import_Notice::record( 42, 2, 2, 0 );
		$this->assertIsArray( $this->recorded_batch() );

		// ACT: Dismiss over AJAX.
		$response = $this->dismiss();

		// ASSERT: Forbidden, and the batch survives.
		$this->assertFalse( $response['success'] );
		$this->assertIsArray( $this->recorded_batch() );
	}

	/**
	 * Verifies that filtering the management capability changes the AJAX guard
	 * without granting the default capability.
	 */
	public function test_filtered_capability_changes_ajax_guard(): void {
		// ARRANGE: A subscriber with edit_posts and a recorded batch.
		$this->sign_in( 'subscriber' );
		wp_get_current_user()->add_cap( 'edit_posts' );
		Post_Import_Notice::record( 42, 2, 2, 0 );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		// ACT: Attempt dismissal before and after filtering the capability.
		$denied_response    = $this->dismiss();
		$batch_after_denial = $this->recorded_batch();
		add_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_edit_posts_capability' )
		);
		$allowed_response = $this->dismiss();

		// ASSERT: Only the filtered capability allows and clears the batch.
		$this->assertFalse( $denied_response['success'] );
		$this->assertIsArray( $batch_after_denial );
		$this->assertTrue( $allowed_response['success'] );
		$this->assertFalse( $this->recorded_batch() );
	}

	/**
	 * Returns the alternate management capability used by focused tests.
	 *
	 * @return string Alternate management capability.
	 */
	public static function use_edit_posts_capability(): string {
		return 'edit_posts';
	}

	/**
	 * Signs in a new user with the given role.
	 *
	 * @param string $role Role to create the user with.
	 */
	private function sign_in( string $role ): void {
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => $role ) )
		);
	}

	/**
	 * Reads the batch recorded for the signed-in user.
	 *
	 * @return array|false Recorded batch, false when none is stored.
	 */
	private function recorded_batch(): array|false {
		return get_transient( self::TRANSIENT_PREFIX . get_current_user_id() );
	}

	/**
	 * Dispatches the dismiss endpoint and returns the decoded JSON response.
	 *
	 * @return array Decoded response.
	 */
	private function dismiss(): array {
		$_POST                = array( 'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ) );
		$this->_last_response = '';

		$this->dispatch_ajax_expecting_die( self::ACTION );

		return json_decode( $this->_last_response, true );
	}
}
