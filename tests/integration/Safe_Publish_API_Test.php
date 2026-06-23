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
use Safe_Publish\Utils\Options;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

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

		// Create test post tagged with the source post ID and site URL.
		$this->post_id = $this->factory()->post->create(
			array(
				'post_title'   => 'Original Title',
				'post_content' => 'Original content.',
				'post_excerpt' => 'Original excerpt.',
				'post_status'  => 'draft',
			)
		);

		update_post_meta( $this->post_id, 'safe_publish_source_post_id', self::SOURCE_POST_ID );
		update_post_meta( $this->post_id, 'safe_publish_source_site_url', 'https://example.com' );
	}

	/**
	 * Verifies that find_local_post scopes the Compare lookup by source site:
	 * a post imported from a different source with the same source ID and post
	 * type is not returned for the connected source.
	 */
	public function test_find_local_post_scopes_by_source_site_url(): void {
		// ARRANGE: Two posts share a source ID and post type but were imported
		// from different source sites.
		$source_a_post = $this->factory()->post->create(
			array( 'post_type' => 'post' )
		);
		update_post_meta(
			$source_a_post,
			Options::META_SOURCE_POST_ID,
			self::SOURCE_POST_ID
		);
		update_post_meta(
			$source_a_post,
			Options::META_SOURCE_SITE_URL,
			'https://source-a.example.com'
		);

		$source_b_post = $this->factory()->post->create(
			array( 'post_type' => 'post' )
		);
		update_post_meta(
			$source_b_post,
			Options::META_SOURCE_POST_ID,
			self::SOURCE_POST_ID
		);
		update_post_meta(
			$source_b_post,
			Options::META_SOURCE_SITE_URL,
			'https://source-b.example.com'
		);

		$renderer = new Diff_Renderer();

		// ACT + ASSERT: Scoping to source A resolves source A's post.
		$scoped = $renderer->find_local_post(
			self::SOURCE_POST_ID,
			'post',
			'https://source-a.example.com'
		);
		$this->assertInstanceOf( WP_Post::class, $scoped );
		$this->assertSame( $source_a_post, $scoped->ID );

		// ACT + ASSERT: Scoping to a source with no matching post errors rather
		// than falling back to a different source's post.
		$wrong_source = $renderer->find_local_post(
			self::SOURCE_POST_ID,
			'post',
			'https://source-c.example.com'
		);
		$this->assertInstanceOf( WP_Error::class, $wrong_source );

		// ACT + ASSERT: An empty URL opts out of scoping and still resolves a
		// post, preserving the unscoped lookup behavior.
		$unscoped = $renderer->find_local_post(
			self::SOURCE_POST_ID,
			'post',
			''
		);
		$this->assertInstanceOf( WP_Post::class, $unscoped );
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
		$this->assertArrayHasKey( 'current', $result, 'Should have current data' );
		$this->assertArrayHasKey( 'blockDiffs', $result, 'Should have block diffs' );
		$this->assertArrayHasKey( 'incomingRenderedHtml', $result, 'Should have incoming rendered HTML' );
		$this->assertArrayHasKey( 'currentRenderedHtml', $result, 'Should have current rendered HTML' );

		// ASSERT: Verify diff was generated.
		$this->assertIsString( $result['contentDiffHtml'] );
		$this->assertNotSame(
			'',
			$result['contentDiffHtml'],
			'Content diff should not be empty'
		);

		// ASSERT: Verify current data extracted from local post.
		$this->assertSame( 'Original Title', $result['current']['title'] );
		$this->assertSame( 'Original excerpt.', $result['current']['excerpt'] );

		// ASSERT: Verify non-content diff keys exist.
		$this->assertIsArray( $result['nonContentDiffs'] );
		$this->assertArrayHasKey( 'title', $result['nonContentDiffs'] );
		$this->assertArrayHasKey( 'excerpt', $result['nonContentDiffs'] );
		$this->assertArrayHasKey( 'taxonomies', $result['nonContentDiffs'] );
		$this->assertArrayHasKey( 'meta', $result['nonContentDiffs'] );
		$this->assertArrayHasKey( 'featuredMedia', $result['nonContentDiffs'] );

		// ASSERT: Source-side fields (title, excerpt, meta, embedded terms)
		// flowed through extract_incoming_data and reached the rendered diffs.
		// Distinctive substrings survive wp_text_diff's per-word HTML markup.
		$this->assertStringContainsString(
			'Updated External',
			$result['nonContentDiffs']['title'],
			'Source title should appear in the title diff.'
		);
		$this->assertStringContainsString(
			'Updated source',
			$result['nonContentDiffs']['excerpt'],
			'Source excerpt should appear in the excerpt diff.'
		);
		$this->assertStringContainsString(
			'custom_meta',
			$result['nonContentDiffs']['meta'],
			'Source meta keys should appear in the meta diff.'
		);
		$this->assertStringContainsString(
			'External Category',
			$result['nonContentDiffs']['taxonomies'],
			'Embedded source terms should appear in the taxonomies diff.'
		);
	}

	/**
	 * Verifies that current-side title extraction returns the raw post_title,
	 * not get_the_title()'s filtered form. Without this, wptexturize turns a
	 * stored `--` into `&#8211;`, producing a spurious diff against title.raw.
	 */
	public function test_diff_renderer_extracts_current_title_raw(): void {
		// ARRANGE: Destination stores literal `--`, the wptexturize trigger.
		$post_id = $this->factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Heading -- Subheading',
			)
		);
		update_post_meta(
			$post_id,
			'safe_publish_source_post_id',
			self::SOURCE_POST_ID + 1
		);
		update_post_meta(
			$post_id,
			'safe_publish_source_site_url',
			'https://example.com'
		);

		// Source returns the same raw value.
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$mock_http_callable = static function ( $_url, $_action, $_credentials ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'title'   => array( 'raw' => 'Heading -- Subheading' ),
						'content' => array( 'raw' => 'Body' ),
						'excerpt' => array( 'raw' => 'Excerpt' ),
					)
				),
			);
		};

		update_option(
			'safe_publish_connected_site_url',
			'https://example.com'
		);

		$request = new WP_REST_Request(
			'POST',
			'/safe-publish/v1/diff-preview'
		);
		$request->set_param( 'postId', self::SOURCE_POST_ID + 1 );
		$request->set_param( 'postType', 'post' );
		$request->set_param( 'mode', 'split' );

		// ACT: Render the diff.
		$renderer = new Diff_Renderer();
		$result   = $renderer->render_diff(
			$request,
			$mock_http_callable,
			array()
		);

		// ASSERT: current.title is the raw post_title, untouched by wptexturize.
		$this->assertIsArray( $result );
		$this->assertSame(
			'Heading -- Subheading',
			$result['current']['title']
		);

		// ASSERT: Matching raw titles return an empty title diff so the client
		// omits the section by default.
		$this->assertSame(
			'',
			$result['nonContentDiffs']['title']
		);
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
		update_post_meta(
			$local_movie_id,
			'safe_publish_source_site_url',
			'https://example.com'
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

		// ASSERT: The diff resolved against the rest_base endpoint, succeeded,
		// and surfaced the source-side title in the rendered diff.
		$this->assertIsArray(
			$result,
			'Diff should succeed for a custom CPT.'
		);
		$this->assertStringContainsString(
			'Source',
			$result['nonContentDiffs']['title'] ?? '',
			'Source title should appear in the title diff for a custom CPT.'
		);

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
	 * Verifies that diff-preview resolves a private local post. Regression:
	 * the source-ID lookup hard-coded draft/publish/pending and 404'd on any
	 * other status. Asserting private alone is sufficient now that the
	 * query uses 'any'.
	 */
	public function test_diff_preview_resolves_private_post(): void {
		// ARRANGE: Switch the fixture to private, stub the source fetch,
		// and authenticate as admin.
		wp_update_post(
			array(
				'ID'          => $this->post_id,
				'post_status' => 'private',
			)
		);
		$this->assertSame(
			'private',
			get_post_status( $this->post_id ),
			'Fixture should be private before exercising the endpoint'
		);
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

		try {
			// ACT: Dispatch through the REST server.
			$response = $this->server->dispatch( $request );

			// ASSERT: The status filter no longer hides private posts.
			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame(
				200,
				$response->get_status(),
				'Should return 200 for private post'
			);
		} finally {
			remove_filter( 'pre_http_request', $stub_source_fetch );
		}
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
	 * Verifies that block diffs skip empty freeform whitespace slots —
	 * parse_blocks emits them between real blocks and they otherwise show as
	 * empty cards in the modal.
	 */
	public function test_diff_renderer_skips_empty_freeform_blocks(): void {
		// ARRANGE: Local and source content share the same single paragraph
		// block surrounded by whitespace that parse_blocks treats as freeform
		// nodes with blockName === null and empty rendered HTML.
		$content_with_padding = "\n\n<!-- wp:paragraph -->\n<p>Same body.</p>\n<!-- /wp:paragraph -->\n\n";
		wp_update_post(
			array(
				'ID'           => $this->post_id,
				'post_content' => $content_with_padding,
			)
		);

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$mock_http_callable = static function (
			$_url,
			$_action,
			$_credentials
		) use ( $content_with_padding ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'title'   => array( 'raw' => 'Original Title' ),
						'content' => array( 'raw' => $content_with_padding ),
						'excerpt' => array( 'raw' => 'Original excerpt.' ),
					)
				),
			);
		};

		update_option(
			'safe_publish_connected_site_url',
			'https://example.com'
		);

		$request = new WP_REST_Request(
			'POST',
			'/safe-publish/v1/diff-preview'
		);
		$request->set_param( 'postId', self::SOURCE_POST_ID );
		$request->set_param( 'postType', 'post' );
		$request->set_param( 'mode', 'split' );

		// ACT: Render the diff.
		$renderer = new Diff_Renderer();
		$result   = $renderer->render_diff(
			$request,
			$mock_http_callable,
			array()
		);

		// ASSERT: Only the real paragraph block survives; the empty freeform
		// nodes on either side of it are filtered out entirely.
		$this->assertIsArray( $result );
		$this->assertCount(
			1,
			$result['blockDiffs'],
			'Empty freeform whitespace slots should be filtered out.'
		);
		$this->assertSame(
			'core/paragraph',
			$result['blockDiffs'][0]['current']['name'] ?? null
		);
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
