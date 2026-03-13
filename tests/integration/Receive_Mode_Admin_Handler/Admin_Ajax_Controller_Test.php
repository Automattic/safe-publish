<?php
/**
 * Integration Tests for Admin Ajax Controller
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Receive_Mode_Admin_Handler;

use WPAjaxDieContinueException;
use WPAjaxDieStopException;

/**
 * Admin Ajax Controller Integration Test Class.
 *
 * Tests the AJAX endpoints exposed by the admin controller.
 */
class Admin_Ajax_Controller_Test extends \WP_Ajax_UnitTestCase {

	/**
	 * Admin user ID for privileged test requests.
	 *
	 * @var int
	 */
	private int $admin_user_id;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->admin_user_id = $this->factory()->user->create(
			array( 'role' => 'administrator' )
		);
	}

	/**
	 * Verifies that the create draft endpoint rejects requests with an invalid
	 * nonce.
	 */
	public function test_ajax_create_draft_rejects_request_with_invalid_nonce(): void {
		// ARRANGE: Authenticate as admin, but supply a bad nonce.
		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce' => 'not-a-valid-nonce',
		);

		// ASSERT: Nonce failure calls wp_die( -1 ).
		$this->expectException( WPAjaxDieStopException::class );
		$this->expectExceptionMessage( '-1' );

		// ACT: Trigger the AJAX handler registered by the plugin.
		$this->_handleAjax( 'safe_publish_create_draft' );
	}

	/**
	 * Verifies that the bulk import endpoint rejects unauthenticated requests.
	 */
	public function test_ajax_bulk_import_rejects_request_with_invalid_nonce(): void {
		// ARRANGE: Authenticate as admin, but supply a bad nonce.
		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce' => 'not-a-valid-nonce',
		);

		// ASSERT: Nonce failure calls wp_die( -1 ).
		$this->expectException( WPAjaxDieStopException::class );
		$this->expectExceptionMessage( '-1' );

		// ACT: Trigger the AJAX handler registered by the plugin.
		$this->_handleAjax( 'safe_publish_bulk_import' );
	}

	/**
	 * Verifies that the bulk import endpoint rejects users without edit_posts
	 * capability.
	 */
	public function test_ajax_bulk_import_rejects_request_without_edit_posts_capability(): void {
		// ARRANGE: Authenticate as a subscriber who cannot edit posts.
		$subscriber_id = $this->factory()->user->create(
			array( 'role' => 'subscriber' )
		);
		wp_set_current_user( $subscriber_id );
		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
		);

		// ACT: wp_send_json_error() terminates via wp_die(), which throws
		// WPAjaxDieContinueException.
		try {
			$this->_handleAjax( 'safe_publish_bulk_import' );
			$this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// ASSERT: Response is a JSON failure with a forbidden error message.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Response should be a JSON object' );
		$this->assertFalse( $response['success'], 'Subscriber should be denied' );
		$this->assertStringContainsString(
			'Forbidden',
			$response['data'],
			'Error message should indicate the request is forbidden'
		);
	}

	/**
	 * Verifies that the bulk import endpoint imports a post and returns a JSON
	 * success response.
	 */
	public function test_ajax_bulk_import_imports_post_and_returns_success(): void {
		// ARRANGE: Authenticate as admin with valid nonce and text-only post
		// data.
		wp_set_current_user( $this->admin_user_id );

		$posts_data = array(
			array(
				'id'        => 9001,
				'title'     => 'Test Import Post',
				'content'   => '<p>Simple text content, no external media.</p>',
				'link'      => 'https://source.example.com/test-import-post',
				'post_type' => 'posts',
			),
		);

		$_POST = array(
			'nonce'      => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'posts_data' => wp_json_encode( $posts_data ),
		);

		// ACT: Trigger AJAX handler; wp_send_json_success outputs JSON then
		// calls wp_die(), which throws WPAjaxDieContinueException after
		// buffering output.
		try {
			$this->_handleAjax( 'safe_publish_bulk_import' );
			$this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// ASSERT: Response is a valid JSON success with correct counts.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Response should be a JSON object' );
		$this->assertTrue( $response['success'], 'Bulk import should return success' );
		$this->assertSame( 1, $response['data']['total'], 'Should have processed 1 post' );
		$this->assertSame(
			1,
			$response['data']['successful'],
			'Should have 1 successful import'
		);
		$this->assertSame( 0, $response['data']['failed'], 'Should have 0 failed imports' );

		// ASSERT: Verify the post was actually created in the database.
		$imported_posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'posts_per_page' => 1,
				'meta_key'       => 'safe_publish_external_post_id',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'     => '9001',
			)
		);

		$this->assertCount( 1, $imported_posts, 'A draft post should exist in the database' );
		$this->assertSame(
			'Test Import Post',
			$imported_posts[0]->post_title,
			'Imported post should have the correct title'
		);
	}

	/**
	 * Verifies that the create draft endpoint rejects users without edit_posts
	 * capability.
	 */
	public function test_ajax_create_draft_rejects_request_without_edit_posts_capability(): void {
		// ARRANGE: Authenticate as subscriber who cannot edit posts.
		$subscriber_id = $this->factory()->user->create(
			array( 'role' => 'subscriber' )
		);
		wp_set_current_user( $subscriber_id );
		$_POST = array(
			'nonce'            => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'external_post_id' => '42',
			'title'            => 'Some Title',
		);

		// ACT: wp_send_json_error() terminates via wp_die(), which throws
		// WPAjaxDieContinueException.
		try {
			$this->_handleAjax( 'safe_publish_create_draft' );
			$this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// ASSERT: Response is a JSON failure with a permission error message.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Response should be a JSON object' );
		$this->assertFalse( $response['success'], 'Subscriber should be denied' );
		$this->assertStringContainsString(
			'permission',
			$response['data']['message'],
			'Error message should mention permissions'
		);
	}

	/**
	 * Verifies that the create draft endpoint creates a new post and returns a
	 * JSON success response.
	 */
	public function test_ajax_create_draft_imports_post_and_returns_success(): void {
		// ARRANGE: Authenticate as admin with valid nonce and minimal post data.
		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce'            => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'external_post_id' => '7001',
			'title'            => 'Single Draft Import',
			'content'          => '<p>Plain text, no external media.</p>',
			'external_link'    => 'https://source.example.com/single-draft',
			'post_type'        => 'post',
		);

		// ACT: Trigger the create draft AJAX handler.
		try {
			$this->_handleAjax( 'safe_publish_create_draft' );
			$this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// ASSERT: Response is a JSON success for a newly created post.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Response should be a JSON object' );
		$this->assertTrue( $response['success'], 'Create draft should return success' );
		$this->assertArrayHasKey( 'post_id', $response['data'], 'Response should include post_id' );
		$this->assertNotEmpty( $response['data']['edit_url'], 'Response should include edit_url' );
		$this->assertFalse( $response['data']['existing'], 'Should be flagged as a new post, not existing' );
	}

	/**
	 * Verifies that the create draft endpoint returns a confirmation prompt
	 * when a post with the same external ID already exists.
	 */
	public function test_ajax_create_draft_returns_existing_post_confirmation(): void {
		// ARRANGE: Pre-create a post with a known external ID so the duplicate
		// is detected.
		$existing_post_id = wp_insert_post(
			array(
				'post_title'  => 'Already Imported',
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);
		update_post_meta( $existing_post_id, 'safe_publish_external_post_id', '8001' );

		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce'            => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'external_post_id' => '8001',
			'title'            => 'Already Imported',
			'content'          => '<p>Same content.</p>',
			'external_link'    => 'https://source.example.com/already-imported',
			'post_type'        => 'post',
		);

		// ACT: Trigger handler without force_update — should not overwrite.
		try {
			$this->_handleAjax( 'safe_publish_create_draft' );
			$this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// ASSERT: A confirmation prompt is returned, not a new post.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Response should be a JSON object' );
		$this->assertTrue( $response['success'], 'Should succeed with a confirmation prompt' );
		$this->assertTrue( $response['data']['existing'], 'Should flag the post as already existing' );
		$this->assertSame(
			'update_existing',
			$response['data']['confirm_action'],
			'Should ask to confirm update of existing post'
		);
	}

	/**
	 * Verifies that the bulk import endpoint rejects a batch with more than 50
	 * posts.
	 */
	public function test_ajax_bulk_import_rejects_request_exceeding_post_limit(): void {
		// ARRANGE: Build 51 minimal post entries to exceed the limit.
		wp_set_current_user( $this->admin_user_id );
		$posts_data = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$posts_data[] = array(
				'id'        => $i,
				'title'     => 'Post ' . $i,
				'content'   => '<p>Content.</p>',
				'link'      => 'https://source.example.com/post-' . $i,
				'post_type' => 'posts',
			);
		}
		$_POST = array(
			'nonce'      => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'posts_data' => wp_json_encode( $posts_data ),
		);

		// ACT: Trigger the bulk import handler with an oversized batch.
		try {
			$this->_handleAjax( 'safe_publish_bulk_import' );
			$this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// ASSERT: Response is a JSON failure indicating the limit was exceeded.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Response should be a JSON object' );
		$this->assertFalse( $response['success'], 'Should reject oversized batches' );
		$this->assertStringContainsString( '50', $response['data'], 'Error should mention the 50-post limit' );
	}

	/**
	 * Verifies that the bulk import endpoint correctly reports failures for
	 * invalid post data.
	 *
	 * Posts missing required fields (title or external ID) should count as
	 * failed without aborting the rest of the batch.
	 */
	public function test_ajax_bulk_import_reports_partial_failure(): void {
		// ARRANGE: Mix one valid post with one that is missing its title.
		wp_set_current_user( $this->admin_user_id );
		$posts_data = array(
			array(
				'id'        => 5001,
				'title'     => 'Valid Post',
				'content'   => '<p>Content.</p>',
				'link'      => 'https://source.example.com/valid-post',
				'post_type' => 'posts',
			),
			array(
				'id'        => 5002,
				'title'     => '', // Empty title — will fail validation.
				'content'   => '<p>Content.</p>',
				'link'      => 'https://source.example.com/invalid-post',
				'post_type' => 'posts',
			),
		);
		$_POST      = array(
			'nonce'      => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'posts_data' => wp_json_encode( $posts_data ),
		);

		// ACT: Trigger the bulk import handler.
		try {
			$this->_handleAjax( 'safe_publish_bulk_import' );
			$this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// ASSERT: One post succeeded and one failed.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Response should be a JSON object' );
		$this->assertTrue( $response['success'], 'Bulk import should always return success response' );
		$this->assertSame( 2, $response['data']['total'], 'Should have processed 2 posts' );
		$this->assertSame( 1, $response['data']['successful'], 'Should have 1 successful import' );
		$this->assertSame( 1, $response['data']['failed'], 'Should have 1 failed import' );
	}
}
