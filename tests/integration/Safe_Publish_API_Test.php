<?php
/**
 * Integration Tests for Safe Publish API
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Diff_Renderer;
use Safe_Publish\API\Source_Post_Type_Resolver;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Term;

/**
 * Safe Publish API Test Class.
 *
 * @psalm-suppress InvalidArgument
 */
class Safe_Publish_API_Test extends Integration_Test_Case {

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
	 * Source post ID for test fixtures.
	 */
	private const SOURCE_POST_ID = 123;

	/**
	 * Non-existent source post ID for error tests.
	 */
	private const NON_EXISTENT_SOURCE_POST_ID = 555;

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

		// The resolver memoizes the source post-types map per request; clear it
		// so each test observes its own mocked source response.
		Source_Post_Type_Resolver::reset_cache();

		// Create admin user for tests.
		$this->admin_user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );

		// Initialize REST server.
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, Squiz.PHP.DisallowMultipleAssignments.Found
		$this->server = $wp_rest_server = new WP_REST_Server();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'rest_api_init' );

		// Create test post with source post ID meta.
		$this->post_id = $this->factory()->post->create(
			array(
				'post_title'   => 'Original Title',
				'post_content' => 'Original content.',
				'post_excerpt' => 'Original excerpt.',
				'post_status'  => 'draft',
			)
		);

		update_post_meta( $this->post_id, 'safe_publish_source_post_id', self::SOURCE_POST_ID );
	}

	/**
	 * Verifies that the diff renderer generates diff structure successfully
	 * with source data, including correct extraction of meta, terms, and
	 * non-content diff keys.
	 *
	 * Uses mocked HTTP callable. Does not call the REST endpoint to limit test
	 * complexity.
	 */
	public function test_diff_renderer_generates_diff_structure_successfully(): void {
		// ARRANGE: Mock HTTP callable that returns WordPress REST API response.
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$mock_http_callable = function ( $_url, $_action, $_credentials ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'title'     => array( 'raw' => 'Updated External Title' ),
						'content'   => array( 'raw' => '<p>Updated source content.</p>' ),
						'excerpt'   => array( 'raw' => 'Updated source excerpt.' ),
						'meta'      => array( 'custom_meta' => 'meta_value' ),
						'_embedded' => array(
							'wp:term' => array(
								array(
									array(
										'taxonomy' => 'category',
										'name'     => 'External Category',
										'slug'     => 'source-category',
									),
								),
							),
						),
					)
				),
			);
		};

		// Set required options.
		update_option( 'safe_publish_connected_site_url', 'https://example.com' );

		// Create request.
		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', self::SOURCE_POST_ID );
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
		$this->assertNotSame(
			'',
			$result['contentDiffHtml'],
			'Content diff should not be empty'
		);

		// ASSERT: Verify incoming data extracted correctly from mock response.
		$this->assertSame( 'Updated External Title', $result['incoming']['title'] );
		$this->assertSame( 'Updated source excerpt.', $result['incoming']['excerpt'] );

		// ASSERT: Verify current data extracted from local post.
		$this->assertSame( 'Original Title', $result['current']['title'] );
		$this->assertSame( 'Original excerpt.', $result['current']['excerpt'] );

		// ASSERT: Verify incoming meta and terms extracted from mock response.
		$this->assertSame( array( 'custom_meta' => 'meta_value' ), $result['incoming']['meta'] );
		$this->assertSame( array( 'category' => array( 'External Category' ) ), $result['incoming']['terms'] );

		// ASSERT: Verify non-content diff keys exist.
		$this->assertIsArray( $result['nonContentDiffs'] );
		$this->assertArrayHasKey( 'title', $result['nonContentDiffs'] );
		$this->assertArrayHasKey( 'excerpt', $result['nonContentDiffs'] );
		$this->assertArrayHasKey( 'taxonomies', $result['nonContentDiffs'] );
		$this->assertArrayHasKey( 'meta', $result['nonContentDiffs'] );
		$this->assertArrayHasKey( 'featuredMedia', $result['nonContentDiffs'] );
	}

	/**
	 * Verifies that the diff renderer addresses a custom post type by its
	 * source rest_base rather than its slug.
	 */
	public function test_diff_renderer_resolves_custom_cpt_via_rest_base(): void {
		// ARRANGE: Register a CPT whose rest_base differs from its slug and
		// create a local post of that type mapped to the source post ID.
		register_post_type(
			'sp_movie',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'sp_movies',
			)
		);

		$local_movie_id = $this->factory()->post->create(
			array(
				'post_type'    => 'sp_movie',
				'post_title'   => 'Local Movie',
				'post_content' => 'Local movie content.',
				'post_excerpt' => 'Local excerpt.',
				'post_status'  => 'draft',
			)
		);
		update_post_meta(
			$local_movie_id,
			'safe_publish_source_post_id',
			self::SOURCE_POST_ID
		);

		update_option(
			'safe_publish_connected_site_url',
			'https://example.com'
		);

		// Record requested URLs to prove the source post is addressed by
		// rest_base (sp_movies), not the slug (sp_movie).
		$requested_urls = array();
		$make_request   = function ( $url ) use ( &$requested_urls ): array {
			$requested_urls[] = $url;

			if ( false !== strpos(
				$url,
				'/safe-publish/v1/catalog/post-types'
			) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode(
						array(
							array(
								'slug'      => 'sp_movie',
								'name'      => 'Movies',
								'label'     => 'Movies',
								'rest_base' => 'sp_movies',
							),
						)
					),
				);
			}

			if ( false !== strpos( $url, '/wp/v2/sp_movies/' ) ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode(
						array(
							'title'   => array( 'raw' => 'Source Movie' ),
							'content' => array(
								'raw' => '<p>Source movie content.</p>',
							),
							'excerpt' => array( 'raw' => 'Source excerpt.' ),
						)
					),
				);
			}

			// Any other endpoint (e.g. the slug-based /wp/v2/sp_movie/) is
			// wrong.
			return array(
				'response' => array( 'code' => 404 ),
				'body'     => (string) wp_json_encode(
					array( 'code' => 'rest_no_route' )
				),
			);
		};

		$request = new WP_REST_Request(
			'POST',
			'/safe-publish/v1/diff-preview'
		);
		$request->set_param( 'postId', self::SOURCE_POST_ID );
		$request->set_param( 'postType', 'sp_movie' );
		$request->set_param( 'mode', 'split' );

		// ACT: Render the diff with the recording callable.
		$renderer = new Diff_Renderer();
		$result   = $renderer->render_diff( $request, $make_request, array() );

		// ASSERT: The diff resolved against the rest_base endpoint and
		// succeeded.
		$this->assertIsArray(
			$result,
			'Diff should succeed for a custom CPT.'
		);
		$this->assertSame( 'Source Movie', $result['incoming']['title'] );

		// ASSERT: The source post was fetched via rest_base, never via the
		// slug.
		$hit_rest_base = false;
		$hit_slug      = false;
		foreach ( $requested_urls as $url ) {
			if ( false !== strpos(
				$url,
				'/wp/v2/sp_movies/' . self::SOURCE_POST_ID
			) ) {
				$hit_rest_base = true;
			}
			if ( false !== strpos(
				$url,
				'/wp/v2/sp_movie/' . self::SOURCE_POST_ID
			) ) {
				$hit_slug = true;
			}
		}

		$this->assertTrue(
			$hit_rest_base,
			'Source post must be fetched via rest_base (sp_movies).'
		);
		$this->assertFalse(
			$hit_slug,
			'Source post must not be fetched via the slug (sp_movie).'
		);

		unregister_post_type( 'sp_movie' );
	}

	/**
	 * Verifies that the diff-preview endpoint returns 404 when no local post
	 * matches the source post ID, and the user has the edit_others_posts capability.
	 */
	public function test_diff_preview_endpoint_returns_404_for_nonexistent_source_post_id_with_edit_others_posts_capability(): void {
		// ARRANGE: Authenticate as user with edit_others_posts capability.
		wp_set_current_user( $this->admin_user_id );

		// Create request where no local post has this source post ID.
		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', self::NON_EXISTENT_SOURCE_POST_ID );
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
	 * matches the source post ID, and the user lacks the edit_others_posts capability.
	 */
	public function test_diff_preview_endpoint_returns_403_for_nonexistent_source_post_id_without_edit_others_posts_capability(): void {
		// ARRANGE: Create user without edit_others_posts capability.
		$this->create_user_and_authenticate( 'author' );

		// Create request for non-existent post.
		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', self::NON_EXISTENT_SOURCE_POST_ID );
		$request->set_param( 'content', wp_json_encode( array( 'title' => 'New Title' ) ) );
		$request->set_param( 'postType', 'post' );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: Users without capability get 403 (not 404) for non-existent posts.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assert_403_response( $response, 'Should return 403 without edit_others_posts capability' );
	}

	/**
	 * Verifies that diff-preview permission resolves a source post ID to a
	 * local post before checking capabilities.
	 *
	 * Regression: the callback used to treat the source ID as a local ID,
	 * 404'ing every authorized request. Dispatches through the REST server
	 * so route-wiring regressions also surface.
	 */
	public function test_diff_preview_permission_resolves_source_id_to_local_post(): void {
		// ARRANGE: Authenticate, configure source URL, and stub the source fetch
		// so render_diff can complete after the permission callback grants access.
		wp_set_current_user( $this->admin_user_id );
		update_option( 'safe_publish_connected_site_url', 'https://example.com' );

		$stub_source_fetch = static fn() => array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'title'   => array( 'raw' => 'Source Title' ),
					'content' => array( 'raw' => '<p>Source content.</p>' ),
					'excerpt' => array( 'raw' => 'Source excerpt.' ),
				)
			),
		);
		add_filter( 'pre_http_request', $stub_source_fetch );

		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', self::SOURCE_POST_ID );
		$request->set_param( 'postType', 'post' );
		$request->set_param( 'content', wp_json_encode( array( 'title' => 'New Title' ) ) );

		try {
			// ACT: Dispatch through the REST server so route wiring is exercised.
			$response = $this->server->dispatch( $request );

			// ASSERT: Permission resolved the source ID to a local post and the
			// handler completed successfully.
			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 200, $response->get_status(), 'Should return 200 when source ID maps to an editable local post' );
		} finally {
			remove_filter( 'pre_http_request', $stub_source_fetch );
		}
	}

	/**
	 * Verifies that diff-preview returns 403 (not 404) when the source ID
	 * resolves to a local post the user cannot edit, to avoid leaking the
	 * post's existence to non-editors.
	 */
	public function test_diff_preview_permission_returns_403_for_resolved_post_user_cannot_edit(): void {
		// ARRANGE: Author user lacks edit_others_posts; the mapped post is
		// owned by a different user (see setUp), so edit_post is denied.
		$this->create_user_and_authenticate( 'author' );

		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', self::SOURCE_POST_ID );
		$request->set_param( 'content', wp_json_encode( array( 'title' => 'New Title' ) ) );
		$request->set_param( 'postType', 'post' );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: 403, never 404 — resolved post exists but user cannot edit it.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assert_403_response( $response, 'Should return 403 when resolved post is not editable' );
	}

	/**
	 * Verifies that diff-preview returns 400 when postId is not a positive
	 * integer.
	 *
	 * REST argument validation accepts integer 0 and negative integers, so the
	 * permission callback is the gate that rejects them.
	 */
	public function test_diff_preview_permission_returns_400_for_non_positive_post_id(): void {
		// ARRANGE: Authenticate so the request reaches the permission callback.
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', 0 );
		$request->set_param( 'content', wp_json_encode( array( 'title' => 'New Title' ) ) );
		$request->set_param( 'postType', 'post' );

		// ACT: Dispatch through REST server.
		$response = $this->server->dispatch( $request );

		// ASSERT: 400 rest_invalid_param from the permission callback.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 400, $response->get_status(), 'Should return 400 for non-positive post ID' );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] ?? null );
	}

	/**
	 * Verifies that the update-post endpoint updates post content successfully,
	 * storing the exact title, content, and excerpt in the database.
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
		$this->assertInstanceOf( WP_Post::class, $updated_post, 'Post should exist after update' );
		$this->assertSame( 'Updated Title', $updated_post->post_title );
		$this->assertSame( '<p>Updated content.</p>', $updated_post->post_content );
		$this->assertSame( 'Updated excerpt.', $updated_post->post_excerpt );
	}

	/**
	 * Verifies that the update-post endpoint preserves HTML in excerpts.
	 *
	 * Excerpts can contain inline HTML (em, strong, links, etc.). The endpoint
	 * must use wp_kses_post (not sanitize_text_field) so that allowed HTML is
	 * retained.
	 */
	public function test_update_post_endpoint_preserves_excerpt_html(): void {
		// ARRANGE: Create request with HTML excerpt.
		$request = new WP_REST_Request(
			'POST',
			'/safe-publish/v1/update-post'
		);
		$request->set_param( 'postId', $this->post_id );
		$request->set_param( 'content', '<p>Content.</p>' );
		$request->set_param(
			'excerpt',
			'Excerpt with <em>emphasis</em> and <strong>bold</strong>.'
		);

		wp_set_current_user( $this->admin_user_id );

		// ACT: Dispatch the request.
		$response = $this->server->dispatch( $request );

		// ASSERT: Request succeeds.
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		// ASSERT: HTML tags are preserved in the stored excerpt.
		$updated_post = get_post( $this->post_id );
		$this->assertSame(
			'Excerpt with <em>emphasis</em> and <strong>bold</strong>.',
			$updated_post->post_excerpt,
			'Allowed HTML must be preserved in excerpts.'
		);
	}

	/**
	 * Verifies that the update-post endpoint updates meta successfully and
	 * returns the correct post ID in the response.
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
		$this->assertSame( $this->post_id, $data['post_id'] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'value1', get_post_meta( $this->post_id, 'custom_field_1', true ) );
		$this->assertSame( 'value2', get_post_meta( $this->post_id, 'custom_field_2', true ) );
	}

	/**
	 * Verifies that the update-post endpoint updates terms successfully and
	 * returns the correct post ID in the response.
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
		$this->assertInstanceOf( WP_Term::class, $category, 'Category should be a WP_Term object' );

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
		$this->assertSame( $this->post_id, $data['post_id'] );
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
