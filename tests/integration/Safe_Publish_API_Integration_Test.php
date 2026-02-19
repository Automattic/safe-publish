<?php
/**
 * Integration Tests for Safe Publish API
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Diff_Renderer;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Safe Publish API Integration Test Class.
 *
 * Tests the Safe Publish API REST endpoints.
 *
 * @psalm-suppress InvalidArgument
 */
class Safe_Publish_API_Integration_Test extends Integration_Test_Case {

	/**
	 * REST server instance for dispatching requests.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Test post ID.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Admin user ID for tests requiring edit capabilities.
	 *
	 * @var int
	 */
	private int $admin_user_id;

	/**
	 * External post ID for test fixtures.
	 */
	private const EXTERNAL_POST_ID = 123;

	/**
	 * Non-existent external post ID for error tests.
	 */
	private const NON_EXISTENT_EXTERNAL_POST_ID = 555;

	/**
	 * Non-existent local post ID for error tests.
	 */
	private const NON_EXISTENT_POST_ID = 999999;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		// Create admin user for tests.
		$this->admin_user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		// Initialize REST server.
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Squiz.PHP.DisallowMultipleAssignments.Found
		$this->server = $wp_rest_server = new WP_REST_Server();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		// Create test post with external ID meta.
		$this->post_id = $this->factory()->post->create(
			array(
				'post_title'   => 'Original Title',
				'post_content' => 'Original content.',
				'post_excerpt' => 'Original excerpt.',
				'post_status'  => 'draft',
			)
		);

		update_post_meta( $this->post_id, 'safe_publish_external_post_id', self::EXTERNAL_POST_ID );
	}

	/**
	 * Verifies that the diff renderer generates diff structure successfully
	 * with external data.
	 *
	 * Uses mocked HTTP callable. Does not call the REST endpoint to limit test
	 * complexity.
	 */
	public function test_diff_renderer_generates_diff_structure_successfully(): void {
		// ARRANGE: Mock HTTP callable that returns WordPress REST API response.
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$mock_http_callable = function ( $_url, $_credentials ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'title'     => array( 'rendered' => 'Updated External Title' ),
						'content'   => array(
							'raw'      => '<p>Updated external content.</p>',
							'rendered' => '<p>Updated external content.</p>',
						),
						'excerpt'   => array( 'rendered' => 'Updated external excerpt.' ),
						'meta'      => array( 'custom_meta' => 'meta_value' ),
						'_embedded' => array(
							'wp:term' => array(
								array(
									array(
										'taxonomy' => 'category',
										'name'     => 'External Category',
										'slug'     => 'external-category',
									),
								),
							),
						),
					)
				),
			);
		};

		// Set required options.
		update_option( 'safe_publish_external_site_url', 'https://example.com' );

		// Create request.
		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', self::EXTERNAL_POST_ID );
		$request->set_param( 'postType', 'post' );
		$request->set_param( 'mode', 'split' );

		// ACT: Test Diff_Renderer directly with mock callable.
		$renderer = new Diff_Renderer();
		$result   = $renderer->render_diff( $request, $mock_http_callable, array() );

		// ASSERT: Verify response structure.
		$this->assertIsArray( $result, 'Should return array on success' );
		$this->assertArrayHasKey( 'contentDiffHtml', $result, 'Should have content diff' );
		$this->assertArrayHasKey( 'nonContentDiffs', $result, 'Should have non-content diffs' );
		$this->assertArrayHasKey( 'incoming', $result, 'Should have incoming data' );
		$this->assertArrayHasKey( 'current', $result, 'Should have current data' );
		$this->assertArrayHasKey( 'localPostId', $result, 'Should have local post ID' );
		$this->assertArrayHasKey( 'blockDiffs', $result, 'Should have block diffs' );
		$this->assertArrayHasKey( 'incomingRenderedHtml', $result, 'Should have incoming rendered HTML' );
		$this->assertArrayHasKey( 'currentRenderedHtml', $result, 'Should have current rendered HTML' );

		// ASSERT: Verify local post ID matches.
		$this->assertSame( $this->post_id, $result['localPostId'] );

		// ASSERT: Verify diff was generated.
		$this->assertIsString( $result['contentDiffHtml'] );
		$this->assertNotEmpty( $result['contentDiffHtml'], 'Content diff should not be empty' );

		// ASSERT: Verify incoming data extracted correctly from mock response.
		$this->assertSame( 'Updated External Title', $result['incoming']['title'] );
		$this->assertSame( 'Updated external excerpt.', $result['incoming']['excerpt'] );

		// ASSERT: Verify current data extracted from local post.
		$this->assertSame( 'Original Title', $result['current']['title'] );
		$this->assertSame( 'Original excerpt.', $result['current']['excerpt'] );

		// ASSERT: Verify non-content diffs structure exists.
		$this->assertIsArray( $result['nonContentDiffs'] );
	}

	/**
	 * Verifies that the diff-preview endpoint returns 404 when no local post
	 * matches the external ID, and the user has the edit_others_posts capability.
	 */
	public function test_diff_preview_endpoint_returns_404_for_nonexistent_external_id_with_edit_others_posts_capability(): void {
		// ARRANGE: Authenticate as user with edit_others_posts capability.
		wp_set_current_user( $this->admin_user_id );

		// Create request where no local post has this external ID.
		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', self::NON_EXISTENT_EXTERNAL_POST_ID );
		$request->set_param( 'content', wp_json_encode( array( 'title' => 'New Title' ) ) );
		$request->set_param( 'postType', 'post' );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: Users with edit_others_posts get semantically correct 404 for non-existent posts.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assert_404_response( $response, 'Should return 404 for non-existent post' );
	}

	/**
	 * Verifies that the diff-preview endpoint returns 403 when no local post
	 * matches the external ID, and the user lacks the edit_others_posts capability.
	 */
	public function test_diff_preview_endpoint_returns_403_for_nonexistent_external_id_without_edit_others_posts_capability(): void {
		// ARRANGE: Create user without edit_others_posts capability.
		$this->create_user_and_authenticate( 'author' );

		// Create request for non-existent post.
		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', self::NON_EXISTENT_EXTERNAL_POST_ID );
		$request->set_param( 'content', wp_json_encode( array( 'title' => 'New Title' ) ) );
		$request->set_param( 'postType', 'post' );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: Users without capability get 403 (not 404) for non-existent posts.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assert_403_response( $response, 'Should return 403 without edit_others_posts capability' );
	}

	/**
	 * Verifies that the update-post endpoint updates post content successfully.
	 */
	public function test_update_post_endpoint_updates_content_successfully(): void {
		// ARRANGE: Create request with updated content.
		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/update-post' );
		$request->set_param( 'postId', $this->post_id );
		$request->set_param( 'title', 'Updated Title' );
		$request->set_param( 'content', '<p>Updated content.</p>' );
		$request->set_param( 'excerpt', 'Updated excerpt.' );

		wp_set_current_user( $this->admin_user_id );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: Verify response.
		$this->assertInstanceOf( WP_REST_Response::class, $response, 'Should return WP_REST_Response' );
		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should have data array' );
		$this->assertArrayHasKey( 'success', $data, 'Response should have success key' );
		$this->assertTrue( $data['success'] );
		$this->assertSame( $this->post_id, $data['post_id'] );
		$this->assertSame( 200, $response->get_status() );

		// ASSERT: Verify post was updated.
		$updated_post = get_post( $this->post_id );
		$this->assertInstanceOf( \WP_Post::class, $updated_post, 'Post should exist after update' );
		$this->assertSame( 'Updated Title', $updated_post->post_title );
		$this->assertStringContainsString( 'Updated content', $updated_post->post_content );
		$this->assertSame( 'Updated excerpt.', $updated_post->post_excerpt );
	}

	/**
	 * Verifies that the update-post endpoint updates meta successfully.
	 */
	public function test_update_post_endpoint_updates_meta_successfully(): void {
		// ARRANGE: Create request with meta.
		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/update-post' );
		$request->set_param( 'postId', $this->post_id );
		$request->set_param( 'content', '<p>Content.</p>' );
		$request->set_param(
			'meta',
			array(
				'custom_field_1' => 'value1',
				'custom_field_2' => 'value2',
			)
		);

		wp_set_current_user( $this->admin_user_id );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: Verify response and meta were updated.
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'value1', get_post_meta( $this->post_id, 'custom_field_1', true ) );
		$this->assertSame( 'value2', get_post_meta( $this->post_id, 'custom_field_2', true ) );
	}

	/**
	 * Verifies that the update-post endpoint updates terms successfully.
	 */
	public function test_update_post_endpoint_updates_terms_successfully(): void {
		// ARRANGE: Create test category for this test.
		$category_id = $this->factory()->term->create(
			array(
				'name'     => 'Test Category',
				'taxonomy' => 'category',
			)
		);
		$category    = get_term( $category_id );
		$this->assertInstanceOf( \WP_Term::class, $category, 'Category should be a WP_Term object' );

		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/update-post' );
		$request->set_param( 'postId', $this->post_id );
		$request->set_param( 'content', '<p>Content.</p>' );
		$request->set_param(
			'terms',
			array(
				'category' => array( $category->term_id ),
			)
		);

		wp_set_current_user( $this->admin_user_id );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: Verify response and terms were updated.
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 200, $response->get_status() );

		// ASSERT: Verify term assignment.
		$post_categories = wp_get_post_categories( $this->post_id );
		$this->assertContains( $category->term_id, $post_categories, 'Post should have the test category assigned' );
	}

	/**
	 * Verifies that the update-post endpoint returns 403 for users without the
	 * edit_posts capability.
	 */
	public function test_update_post_endpoint_returns_403_without_edit_posts_capability(): void {
		// ARRANGE: Create user without edit_posts capability.
		$this->create_user_and_authenticate( 'subscriber' );

		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/update-post' );
		$request->set_param( 'postId', $this->post_id );
		$request->set_param( 'content', '<p>Content.</p>' );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: Verify permission callback denies access.
		$this->assert_403_response( $response, 'Should return 403 without edit_posts capability' );
	}

	/**
	 * Verifies that the update-post endpoint returns 403 for users with the
	 * edit_posts capability but without the edit_others_posts capability for
	 * posts they don't own.
	 */
	public function test_update_post_endpoint_returns_403_without_edit_others_posts_capability(): void {
		// ARRANGE: Create user without edit_others_posts capability, attempting
		// to edit another user's post.
		$this->create_user_and_authenticate( 'author' );

		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/update-post' );
		$request->set_param( 'postId', $this->post_id ); // This post is owned by a different user.
		$request->set_param( 'content', '<p>Content.</p>' );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: Verify permission callback denies access to post they don't own.
		$this->assert_403_response( $response, 'Should return 403 without edit_others_posts capability' );
	}

	/**
	 * Verifies that the update-post endpoint returns 404 for non-existent post
	 * IDs when the user has the edit_others_posts capability.
	 */
	public function test_update_post_endpoint_returns_404_for_nonexistent_post_id_with_edit_others_posts_capability(): void {
		// ARRANGE: Authenticate as user with edit_others_posts capability.
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/update-post' );
		$request->set_param( 'postId', self::NON_EXISTENT_POST_ID );
		$request->set_param( 'content', '<p>Content.</p>' );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: Users with edit_others_posts get 404 for non-existent posts.
		$this->assert_404_response( $response, 'Should return 404 for non-existent post' );
	}

	/**
	 * Verifies that the update-post endpoint returns 403 for non-existent post
	 * IDs when the user lacks the edit_others_posts capability.
	 */
	public function test_update_post_endpoint_returns_403_for_nonexistent_post_id_without_edit_others_posts_capability(): void {
		// ARRANGE: Create user without edit_others_posts capability.
		$this->create_user_and_authenticate( 'author' );

		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/update-post' );
		$request->set_param( 'postId', self::NON_EXISTENT_POST_ID );
		$request->set_param( 'content', '<p>Content.</p>' );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: Users without capability get 403 (not 404).
		$this->assert_403_response( $response, 'Should return 403 for non-existent post without edit_others_posts capability' );
	}

	/**
	 * Creates a user with a given role and authenticates as that user.
	 *
	 * @param string $role WordPress user role.
	 * @return int User ID of the created user.
	 */
	private function create_user_and_authenticate( string $role ): int {
		$user_id = $this->factory()->user->create( array( 'role' => $role ) );
		$this->assertIsInt( $user_id, 'User creation should succeed' );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Asserts that the response is a 403 Forbidden REST response.
	 *
	 * @param WP_REST_Response $response The response to check.
	 * @param string           $message Optional assertion message.
	 */
	private function assert_403_response( WP_REST_Response $response, string $message = '' ): void {
		$this->assertSame( 403, $response->get_status(), $message );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'rest_forbidden', $data['code'] );
	}

	/**
	 * Asserts that the response is a 404 Not Found REST response.
	 *
	 * @param WP_REST_Response $response The response to check.
	 * @param string           $message Optional assertion message.
	 */
	private function assert_404_response( WP_REST_Response $response, string $message = '' ): void {
		$this->assertSame( 404, $response->get_status(), $message );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data );
		$this->assertSame( 'rest_post_not_found', $data['code'] );
	}
}
