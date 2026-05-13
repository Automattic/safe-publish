<?php
/**
 * Integration Tests for Admin Ajax Controller
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Import_Mode_Admin_Handler;

use Safe_Publish\Admin\Admin_Ajax_Controller;
use Safe_Publish\Auth\VIP_Safe_Auth;
use Safe_Publish\Tests\Integration\Ajax_Die_Continue_Trait;
use Safe_Publish\Tests\Integration\Mock_Post_API_Trait;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use Safe_Publish\Utils\Options;
use WP_Ajax_UnitTestCase;
use WP_Error;
use WPAjaxDieStopException;

/**
 * Admin Ajax Controller Integration Test Class.
 *
 * Tests the AJAX endpoints exposed by the admin controller.
 */
class Admin_Ajax_Controller_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;
	use Mock_Post_API_Trait;

	/**
	 * Fallback shared secret used when no environment constant is defined.
	 */
	private const FALLBACK_SECRET = 'integration-test-secret-key-32chars-ok';

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

		// Required by validate_auth_or_fail() in the gated AJAX endpoints.
		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		Imports_Table::create_table();
		Import_Items_Table::create_table();

		$this->admin_user_id = $this->factory()->user->create(
			array( 'role' => 'administrator' )
		);

		// Configure the connected site URL so fetch_fresh_post() can make requests.
		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );

		// Mock the single-post REST endpoint used by fetch_fresh_post().
		add_filter( 'pre_http_request', array( $this, 'mock_post_api' ), 1, 3 );
	}

	/**
	 * Tears down test fixtures.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_post_api' ), 1 );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		delete_site_transient( Admin_Ajax_Controller::AUTH_STATUS_TRANSIENT );
		parent::tearDown();
	}

	/**
	 * Intercepts HTTP requests to the single-post REST endpoint.
	 *
	 * Returns a minimal valid post response for fetch_fresh_post_content().
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value.
	 * @param array                $_args   HTTP request arguments (unused).
	 * @param string               $url     Request URL.
	 * @return false|array|WP_Error Mocked response, or the prior return value.
	 */
	public function mock_post_api(
		false|array|WP_Error $preempt,
		array $_args,
		string $url
	): false|array|WP_Error {
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

		// ACT: Trigger the bulk import handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_bulk_import' );

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

		// ACT: Trigger the bulk import handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_bulk_import' );

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
				'meta_key'       => 'safe_publish_source_post_id',
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
			get_post_meta( $imported_posts[0]->ID, Options::META_SOURCE_LINK, true ),
			'Source link meta should be stored'
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
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => '42',
			'title'          => 'Some Title',
		);

		// ACT: Trigger the create draft AJAX handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

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
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => '7001',
			'title'          => 'Single Draft Import',
			'content'        => '<p>Plain text, no external media.</p>',
			'source_link'    => 'https://source.example.com/single-draft',
			'post_type'      => 'post',
		);

		// ACT: Trigger the create draft AJAX handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

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
			get_post_meta( $post_id, Options::META_SOURCE_POST_ID, true ),
			'Source post ID meta should be stored'
		);
		$this->assertSame(
			'https://source.example.com/single-draft',
			get_post_meta( $post_id, Options::META_SOURCE_LINK, true ),
			'Source link meta should be stored'
		);
	}

	/**
	 * Verifies that the create draft endpoint returns a confirmation prompt
	 * when a post with the same source post ID already exists.
	 */
	public function test_ajax_create_draft_returns_existing_post_confirmation(): void {
		// ARRANGE: Pre-create a post with a known source post ID so the duplicate
		// is detected.
		$existing_post_id = wp_insert_post(
			array(
				'post_title'  => 'Already Imported',
				'post_status' => 'draft',
				'post_type'   => 'post',
			)
		);
		update_post_meta( $existing_post_id, 'safe_publish_source_post_id', '8001' );

		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => '8001',
			'title'          => 'Already Imported',
			'content'        => '<p>Same content.</p>',
			'source_link'    => 'https://source.example.com/already-imported',
			'post_type'      => 'post',
		);

		// ACT: Trigger handler without force_update — should not overwrite.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

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
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => '9999',
			'title'          => '',
			'source_link'    => 'https://source.example.com/no-title',
			'post_type'      => 'post',
		);

		// ACT: Trigger the create draft AJAX handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

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
		$this->dispatch_ajax_expecting_die( 'safe_publish_bulk_import' );

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
	 * Posts missing required fields (title or source post ID) should count as
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
		$this->dispatch_ajax_expecting_die( 'safe_publish_bulk_import' );

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
	 * If update_post_meta fails for META_IMPORT_DATE_GMT (e.g., a DB error), the
	 * import must report failure rather than silently leaving the tracking meta
	 * stale.
	 */
	public function test_ajax_update_draft_fails_when_tracking_meta_write_fails(): void {
		// ARRANGE: Pre-create a post with a known source post ID so the update
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
			Options::META_SOURCE_POST_ID,
			'8050'
		);

		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => '8050',
			'title'          => 'Existing Post',
			'content'        => '<p>Updated content.</p>',
			'source_link'    => 'https://source.example.com/existing-post',
			'post_type'      => 'post',
			'force_update'   => 'true',
		);

		// ARRANGE: Block update_post_meta for META_IMPORT_DATE_GMT to simulate a DB
		// failure.
		$block_meta = function (
			$check,
			$object_id,
			$meta_key,
			$meta_value,
			$prev_value
		) {
			unset( $object_id, $meta_value, $prev_value );
			if ( Options::META_IMPORT_DATE_GMT === $meta_key ) {
				return false;
			}

			return $check;
		};
		add_filter( 'update_post_metadata', $block_meta, 10, 5 );

		// ACT: Trigger the create draft handler; with force_update it takes the
		// update path.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

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
			get_post_meta( $existing_post_id, Options::META_IMPORT_DATE_GMT, true ),
			'META_IMPORT_DATE_GMT must be absent when the write was blocked after a delete.'
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
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => '7100',
			'title'          => 'Post With Source Fields',
			'content'        => '<p>Content.</p>',
			'source_link'    => 'https://source.example.com/source-slug',
			'post_type'      => 'post',
		);

		// ACT: Trigger the create draft handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

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
			Options::META_SOURCE_POST_ID,
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
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => '7101',
			'title'          => 'Existing Post',
			'content'        => '<p>Updated content.</p>',
			'source_link'    => 'https://source.example.com/new-slug',
			'post_type'      => 'post',
			'force_update'   => 'true',
		);

		// ACT: Trigger the create draft handler with force_update.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

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
	 * Verifies that post_password is preserved when creating a draft via the
	 * single-import path.
	 */
	public function test_ajax_create_draft_preserves_post_password(): void {
		// ARRANGE: Mock API returns a password-protected post.
		$this->mock_post_overrides = array(
			'password' => 's3cret',
		);

		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => '7200',
			'title'          => 'Password Protected Post',
			'content'        => '<p>Content.</p>',
			'source_link'    => 'https://source.example.com/password-post',
			'post_type'      => 'post',
		);

		// ACT: Trigger the create draft handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

		// ASSERT: Response is a success.
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue(
			$response['success'],
			'Create draft should return success'
		);

		$post = get_post( $response['data']['post_id'] );

		// ASSERT: Password must match the source value.
		$this->assertSame(
			's3cret',
			$post->post_password,
			'Post password must be preserved from the source post.'
		);
	}

	/**
	 * Verifies that post_password is updated when force-updating an existing
	 * draft via the single-import path.
	 */
	public function test_ajax_update_draft_updates_post_password(): void {
		// ARRANGE: Pre-create a post with a password.
		$existing_post_id = wp_insert_post(
			array(
				'post_title'    => 'Existing Post',
				'post_status'   => 'draft',
				'post_type'     => 'post',
				'post_password' => 'original',
			)
		);
		update_post_meta(
			$existing_post_id,
			Options::META_SOURCE_POST_ID,
			'7201'
		);

		// ARRANGE: Mock API returns an updated password.
		$this->mock_post_overrides = array(
			'password' => 'changed',
		);

		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => '7201',
			'title'          => 'Existing Post',
			'content'        => '<p>Updated content.</p>',
			'source_link'    => 'https://source.example.com/password-update',
			'post_type'      => 'post',
			'force_update'   => 'true',
		);

		// ACT: Trigger the create draft handler with force_update.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

		// ASSERT: Response is a success.
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue(
			$response['success'],
			'Force update should return success'
		);

		$post = get_post( $existing_post_id );

		// ASSERT: Password must reflect the updated source value.
		$this->assertSame(
			'changed',
			$post->post_password,
			'Post password must be updated on force update.'
		);
	}

	/**
	 * Verifies that the post password is never included in the AJAX response
	 * payload returned to the client.
	 */
	public function test_ajax_create_draft_response_excludes_password(): void {
		// ARRANGE: Mock API returns a password-protected post.
		$this->mock_post_overrides = array(
			'password' => 's3cret',
		);

		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce'          => wp_create_nonce(
				'safe_publish_ajax_nonce'
			),
			'source_post_id' => '7300',
			'title'          => 'Password Leak Check',
			'content'        => '<p>Content.</p>',
			'source_link'    => 'https://source.example.com/pw-leak',
			'post_type'      => 'post',
		);

		// ACT: Trigger the create draft handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

		// ASSERT: Response must not contain password.
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$this->assertArrayNotHasKey(
			'password',
			$response['data'],
			'Password must never be sent in the AJAX response.'
		);
	}

	/**
	 * Verifies that the auth-status endpoint rejects requests with an invalid
	 * nonce.
	 */
	public function test_ajax_auth_status_rejects_invalid_nonce(): void {
		// ARRANGE: Authenticate as admin with a bad nonce.
		wp_set_current_user( $this->admin_user_id );
		$_POST = array( 'nonce' => 'not-a-valid-nonce' );

		// ASSERT: Nonce failure calls wp_die( -1 ).
		$this->expectException( WPAjaxDieStopException::class );
		$this->expectExceptionMessage( '-1' );

		// ACT: Trigger the AJAX handler.
		$this->_handleAjax( 'safe_publish_auth_status' );
	}

	/**
	 * Verifies that the auth-status endpoint rejects users without the
	 * edit_posts capability.
	 */
	public function test_ajax_auth_status_rejects_request_without_edit_posts_capability(): void {
		// ARRANGE: Authenticate as a subscriber with a valid nonce.
		$subscriber_id = $this->factory()->user->create(
			array( 'role' => 'subscriber' )
		);
		wp_set_current_user( $subscriber_id );
		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
		);

		// ACT: Trigger the AJAX handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_auth_status' );

		// ASSERT: Subscriber receives a forbidden response.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Verifies that the auth-status endpoint returns the cached probe result
	 * without hitting the network.
	 */
	public function test_ajax_auth_status_returns_cached_probe_result(): void {
		// ARRANGE: Pre-populate the transient with an authorized probe result.
		set_site_transient(
			Admin_Ajax_Controller::AUTH_STATUS_TRANSIENT,
			array(
				'status' => VIP_Safe_Auth::STATUS_AUTHORIZED,
				'code'   => 200,
			),
			Admin_Ajax_Controller::AUTH_STATUS_TTL
		);

		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
		);

		// ACT: Trigger the AJAX handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_auth_status' );

		// ASSERT: Response carries the cached status payload.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertSame(
			VIP_Safe_Auth::STATUS_AUTHORIZED,
			$response['data']['status']
		);
		$this->assertSame( 200, $response['data']['code'] );
	}

	/**
	 * Verifies that the auth-status endpoint runs the probe and caches the
	 * result when the transient is empty.
	 */
	public function test_ajax_auth_status_runs_probe_and_caches_result(): void {
		// ARRANGE: Stub the probe HTTP request to return a 401 so we exercise
		// the unauthorized branch end-to-end without seeding the cache.
		$probe_filter = static function ( $preempt, array $_args, string $url ) {
			if ( false !== $preempt ) {
				return $preempt;
			}
			if ( 1 === preg_match( '#/wp-json/wp/v2/posts\?#', $url ) ) {
				return array(
					'response' => array( 'code' => 401 ),
					'body'     => '',
					'headers'  => array(),
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $probe_filter, 1, 3 );

		delete_site_transient(
			Admin_Ajax_Controller::AUTH_STATUS_TRANSIENT
		);

		wp_set_current_user( $this->admin_user_id );
		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
		);

		// ACT: Trigger the AJAX handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_auth_status' );

		remove_filter( 'pre_http_request', $probe_filter, 1 );

		// ASSERT: Response reflects the probe verdict and the transient was
		// populated with the same payload.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertSame(
			VIP_Safe_Auth::STATUS_UNAUTHORIZED,
			$response['data']['status']
		);

		$cached = get_site_transient(
			Admin_Ajax_Controller::AUTH_STATUS_TRANSIENT
		);
		$this->assertIsArray( $cached );
		$this->assertSame(
			VIP_Safe_Auth::STATUS_UNAUTHORIZED,
			$cached['status']
		);
	}

	/**
	 * Provides option/hook combinations that should bust the auth-status cache.
	 *
	 * Covers each auth-related option for both `add_option_*` (first save) and
	 * `update_option_*` (subsequent saves) so a regression in either hook or any
	 * option entry surfaces.
	 *
	 * @return array<string, array{string, string, mixed}>
	 */
	public function auth_option_provider(): array {
		return array(
			'add connected site URL'     => array(
				'add',
				Options::OPTION_CONNECTED_SITE_URL,
				'https://example.com',
			),
			'update connected site URL'  => array(
				'update',
				Options::OPTION_CONNECTED_SITE_URL,
				'https://different.example.com',
			),
			'add basic auth username'    => array(
				'add',
				Options::OPTION_USERNAME,
				'new-user',
			),
			'update basic auth username' => array(
				'update',
				Options::OPTION_USERNAME,
				'updated-user',
			),
			'add basic auth password'    => array(
				'add',
				Options::OPTION_PASSWORD,
				'new-password',
			),
			'update basic auth password' => array(
				'update',
				Options::OPTION_PASSWORD,
				'updated-password',
			),
		);
	}

	/**
	 * Verifies that adding or updating any auth-related option busts the
	 * cached auth-status transient.
	 *
	 * @dataProvider auth_option_provider
	 *
	 * @param string $hook   Either 'add' or 'update'.
	 * @param string $option Option key being changed.
	 * @param mixed  $value  Value to write.
	 */
	public function test_changing_auth_option_busts_auth_status_cache(
		string $hook,
		string $option,
		$value
	): void {
		// ARRANGE: For 'add', ensure the option does not exist so add_option
		// fires; for 'update', seed an initial value so update_option fires.
		if ( 'add' === $hook ) {
			delete_option( $option );
		} else {
			update_option( $option, 'initial-value' );
		}

		// ARRANGE: Seed the transient with a stale probe result.
		set_site_transient(
			Admin_Ajax_Controller::AUTH_STATUS_TRANSIENT,
			array( 'status' => VIP_Safe_Auth::STATUS_AUTHORIZED ),
			Admin_Ajax_Controller::AUTH_STATUS_TTL
		);

		// ACT: Write the option, which should fire the matching hook.
		update_option( $option, $value );

		// ASSERT: Transient was deleted by the invalidation hook.
		$this->assertFalse(
			get_site_transient(
				Admin_Ajax_Controller::AUTH_STATUS_TRANSIENT
			)
		);

		delete_option( $option );
	}

	/**
	 * Verifies that a single-import author-resolution failure produces an
	 * `import_items` row with status 'error' and a descriptive error_message.
	 */
	public function test_ajax_create_draft_logs_author_resolution_failure(): void {
		// ARRANGE: Source response advertises an author email that does not
		// exist on the destination — strict resolution must abort.
		wp_set_current_user( $this->admin_user_id );
		$this->mock_post_overrides = array(
			'safe_publish_author' => array(
				'email'        => 'ghost@source.example',
				'login'        => 'ghost',
				'display_name' => 'Ghost Author',
			),
		);
		$_POST                     = array(
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => '6010',
			'title'          => 'Author Resolution Logging',
			'source_link'    => 'https://source.example.com/author-logging',
			'post_type'      => 'post',
		);

		// ACT: Trigger the create draft AJAX handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

		// ASSERT: Response is a JSON error naming the unmatched source author.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Ghost Author', $response['data'] );

		// ASSERT: A single error item row was written for this failure.
		global $wpdb;
		$items_table = Import_Items_Table::table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, error_message FROM `{$items_table}` WHERE source_post_id = %d",
				6010
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
		$this->assertStringContainsString( 'Ghost Author', (string) $rows[0]['error_message'] );

		// ASSERT: The session was finalized — no open session remains.
		$this->assertSame( 0, $this->count_open_sessions() );
	}

	/**
	 * Returns the number of import sessions currently in the 'in_progress' state.
	 *
	 * @return int
	 */
	private function count_open_sessions(): int {
		global $wpdb;

		$table = Imports_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE status = %s",
				'in_progress'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
