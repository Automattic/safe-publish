<?php
/**
 * Integration Tests for Admin Ajax Controller
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Import_Mode_Admin_Handler;

use Safe_Publish\Tests\Integration\Mock_Post_API_Trait;
use Safe_Publish\Utils\Options;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

/**
 * Admin Ajax Controller Integration Test Class.
 *
 * Tests the AJAX endpoints exposed by the admin controller.
 */
class Admin_Ajax_Controller_Test extends \WP_Ajax_UnitTestCase {

	use Mock_Post_API_Trait;

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

		// Configure the connected site URL so fetch_fresh_content() can make requests.
		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );

		// Mock the single-post REST endpoint used by fetch_fresh_content().
		add_filter( 'pre_http_request', array( $this, 'mock_post_api' ), 1, 3 );
	}

	/**
	 * Tears down test fixtures.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_post_api' ), 1 );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Intercepts HTTP requests to the single-post REST endpoint.
	 *
	 * Returns a minimal valid post response for fetch_fresh_post_content().
	 *
	 * @param false|array|\WP_Error $preempt Preemptive return value.
	 * @param array                 $_args   HTTP request arguments (unused).
	 * @param string                $url     Request URL.
	 * @return false|array|\WP_Error Mocked response, or the prior return value.
	 */
	public function mock_post_api( $preempt, array $_args, string $url ) {
		if ( false !== $preempt || ! preg_match( '#/wp-json/wp/v2/posts/\d+#', $url ) ) {
			return $preempt;
		}

		return $this->build_mock_post_response();
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
			'Test Post',
			$imported_posts[0]->post_title,
			'Imported post title should come from the fresh fetch'
		);
		$this->assertSame(
			'https://source.example.com/test-import-post',
			get_post_meta( $imported_posts[0]->ID, Options::META_EXTERNAL_LINK, true ),
			'External link meta should be stored'
		);
		$this->assertSame(
			Options::META_IMPORTED_FROM_VALUE,
			get_post_meta( $imported_posts[0]->ID, Options::META_IMPORTED_FROM, true ),
			'Imported-from meta should be stored'
		);
		$this->assertTrue( $response['data']['results'][0]['success'], 'Result entry should indicate success' );
		$this->assertFalse( $response['data']['results'][0]['existing'], 'Result entry should indicate a new post' );
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
		$post_id = $response['data']['post_id'];
		$this->assertIsInt( $post_id, 'post_id should be an integer' );
		$this->assertGreaterThan( 0, $post_id, 'post_id should be a positive integer' );
		$this->assertSame(
			admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			$response['data']['edit_url'],
			'edit_url should point to the newly created post'
		);
		$this->assertFalse( $response['data']['existing'], 'Should be flagged as a new post, not existing' );

		// ASSERT: Post was created in the database with correct title, status, and meta.
		$post = get_post( $post_id );
		$this->assertNotNull( $post, 'The created post should exist in the database' );
		$this->assertSame( 'Test Post', $post->post_title, 'Title should come from the fresh fetch' );
		$this->assertSame( 'draft', $post->post_status, 'Post should be saved as a draft' );
		$this->assertSame(
			'7001',
			get_post_meta( $post_id, Options::META_EXTERNAL_POST_ID, true ),
			'External post ID meta should be stored'
		);
		$this->assertSame(
			'https://source.example.com/single-draft',
			get_post_meta( $post_id, Options::META_EXTERNAL_LINK, true ),
			'External link meta should be stored'
		);
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
		$this->assertSame( $existing_post_id, $response['data']['post_id'], 'Should reference the existing post ID' );
		$this->assertSame( 'Already Imported', $response['data']['post_title'], 'Should include the existing post title' );

		// ASSERT: No import session was opened — the request was deferred, not
		// completed, so no tracking row should exist.
		$this->assertSame(
			0,
			$this->count_open_sessions(),
			'No open session should remain after the confirmation prompt'
		);
	}

	/**
	 * Verifies that the create draft endpoint returns an error when the title is
	 * missing, and does not leave an open import session in the database.
	 */
	public function test_ajax_create_draft_rejects_empty_title_without_leaking_session(): void {
		// ARRANGE: Authenticate as admin but omit the title.
		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce'            => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'external_post_id' => '9999',
			'title'            => '',
			'external_link'    => 'https://source.example.com/no-title',
			'post_type'        => 'post',
		);

		// ACT: Trigger the create draft AJAX handler.
		try {
			$this->_handleAjax( 'safe_publish_create_draft' );
			$this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// ASSERT: Response is a JSON failure.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Response should be a JSON object' );
		$this->assertFalse( $response['success'], 'Should return an error for a missing title' );
		$this->assertStringContainsString(
			'title',
			strtolower( $response['data'] ),
			'Error message should mention the title field'
		);

		// ASSERT: No import session was opened — validation failed before any
		// tracking row should have been created.
		$this->assertSame(
			0,
			$this->count_open_sessions(),
			'No open session should remain after a validation failure'
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

		// ASSERT: One post succeeded, one failed; results array reports per-item outcome.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Response should be a JSON object' );
		$this->assertTrue( $response['success'], 'Bulk import should always return success response' );
		$this->assertSame( 2, $response['data']['total'], 'Should have processed 2 posts' );
		$this->assertSame( 1, $response['data']['successful'], 'Should have 1 successful import' );
		$this->assertSame( 1, $response['data']['failed'], 'Should have 1 failed import' );
		$this->assertTrue( $response['data']['results'][0]['success'], 'Valid post (ID 5001) should have succeeded' );
		$this->assertFalse( $response['data']['results'][1]['success'], 'Post with empty title (ID 5002) should have failed' );
	}

	/**
	 * Verifies that the single-import update path returns an error when the
	 * tracking meta write fails.
	 *
	 * If update_post_meta fails for META_IMPORT_DATE (e.g., a DB error), the
	 * import must report failure rather than silently leaving the tracking meta
	 * stale.
	 */
	public function test_ajax_update_draft_fails_when_tracking_meta_write_fails(): void {
		// ARRANGE: Pre-create a post with a known external ID so the update
		// path is taken.
		$existing_post_id = wp_insert_post(
			array(
				'post_title'  => 'Existing Post',
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);
		update_post_meta(
			$existing_post_id,
			Options::META_EXTERNAL_POST_ID,
			'8050'
		);

		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce'            => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'external_post_id' => '8050',
			'title'            => 'Existing Post',
			'content'          => '<p>Updated content.</p>',
			'external_link'    => 'https://source.example.com/existing-post',
			'post_type'        => 'post',
			'force_update'     => 'true',
		);

		// ARRANGE: Block update_post_meta for META_IMPORT_DATE to simulate a DB
		// failure.
		$block_meta = function (
			$check,
			$object_id,
			$meta_key,
			$meta_value,
			$prev_value
		) {
			unset( $object_id, $meta_value, $prev_value );
			if ( Options::META_IMPORT_DATE === $meta_key ) {
				return false;
			}

			return $check;
		};
		add_filter( 'update_post_metadata', $block_meta, 10, 5 );

		// ACT: Trigger the create draft handler; with force_update it takes the
		// update path.
		try {
			$this->_handleAjax( 'safe_publish_create_draft' );
			$this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		remove_filter( 'update_post_metadata', $block_meta, 10 );

		// ASSERT: Response is a JSON failure with a descriptive error.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'Response should be a JSON object' );
		$this->assertFalse(
			$response['success'],
			'Update should fail when tracking meta cannot be written.'
		);
		$this->assertStringContainsString(
			'tracking metadata',
			$response['data']
		);

		// ASSERT: The import date meta must be absent: the delete succeeded but
		// the subsequent write was blocked, so no value was committed.
		$this->assertSame(
			'',
			get_post_meta( $existing_post_id, Options::META_IMPORT_DATE, true ),
			'META_IMPORT_DATE must be absent when the write was blocked after a delete.'
		);
	}

	/**
	 * Verifies that slug, comment_status, ping_status, and menu_order are
	 * preserved when creating a draft via the single-import path.
	 */
	public function test_ajax_create_draft_preserves_slug_and_post_fields(): void {
		// ARRANGE: Mock API returns specific field values.
		$this->mock_post_overrides = array(
			'slug'           => 'source-slug',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'menu_order'     => 7,
		);

		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce'            => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'external_post_id' => '7100',
			'title'            => 'Post With Source Fields',
			'content'          => '<p>Content.</p>',
			'external_link'    => 'https://source.example.com/source-slug',
			'post_type'        => 'post',
		);

		// ACT: Trigger the create draft handler.
		try {
			$this->_handleAjax( 'safe_publish_create_draft' );
			$this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// ASSERT: Response is a success.
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue(
			$response['success'],
			'Create draft should return success'
		);

		$post = get_post( $response['data']['post_id'] );

		// ASSERT: Fields must match the source values.
		$this->assertSame(
			'source-slug',
			$post->post_name,
			'Slug must be preserved from the source post.'
		);
		$this->assertSame(
			'closed',
			$post->comment_status,
			'Comment status must be preserved from the source post.'
		);
		$this->assertSame(
			'closed',
			$post->ping_status,
			'Ping status must be preserved from the source post.'
		);
		$this->assertSame(
			7,
			$post->menu_order,
			'Menu order must be preserved from the source post.'
		);
	}

	/**
	 * Verifies that slug, comment_status, ping_status, and menu_order are
	 * updated when force-updating an existing draft via the single-import path.
	 */
	public function test_ajax_update_draft_updates_slug_and_post_fields(): void {
		// ARRANGE: Pre-create a post with default field values.
		$existing_post_id = wp_insert_post(
			array(
				'post_title'     => 'Existing Post',
				'post_status'    => 'draft',
				'post_type'      => 'post',
				'post_name'      => 'old-slug',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'menu_order'     => 0,
			)
		);
		update_post_meta(
			$existing_post_id,
			Options::META_EXTERNAL_POST_ID,
			'7101'
		);

		// ARRANGE: Mock API returns updated field values.
		$this->mock_post_overrides = array(
			'slug'           => 'new-slug',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
			'menu_order'     => 4,
		);

		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce'            => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'external_post_id' => '7101',
			'title'            => 'Existing Post',
			'content'          => '<p>Updated content.</p>',
			'external_link'    => 'https://source.example.com/new-slug',
			'post_type'        => 'post',
			'force_update'     => 'true',
		);

		// ACT: Trigger the create draft handler with force_update.
		try {
			$this->_handleAjax( 'safe_publish_create_draft' );
			$this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		// ASSERT: Response is a success.
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue(
			$response['success'],
			'Force update should return success'
		);

		$post = get_post( $existing_post_id );

		// ASSERT: Fields must reflect the updated source values.
		$this->assertSame(
			'new-slug',
			$post->post_name,
			'Slug must be updated on force update.'
		);
		$this->assertSame(
			'closed',
			$post->comment_status,
			'Comment status must be updated on force update.'
		);
		$this->assertSame(
			'closed',
			$post->ping_status,
			'Ping status must be updated on force update.'
		);
		$this->assertSame(
			4,
			$post->menu_order,
			'Menu order must be updated on force update.'
		);
	}

	/**
	 * Returns the number of import sessions currently in the 'in_progress' state.
	 *
	 * @return int
	 */
	private function count_open_sessions(): int {
		$sessions = get_posts(
			array(
				'post_type'      => 'sp_import_session',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => 'status',
						'value' => 'in_progress',
					),
				),
			)
		);

		return count( $sessions );
	}
}
