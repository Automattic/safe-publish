<?php
/**
 * Full workflow integration test: auth → import → history
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\History_Renderer;
use Safe_Publish\Admin\Import_History;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\Admin\Session_Formatter;
use Safe_Publish\Admin\Session_Rollback_Service;
use Safe_Publish\API\Export_Logger;
use Safe_Publish\API\External_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\HMAC_Authenticator;
use Safe_Publish\Auth\Permission_Manager;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;
use Safe_Publish\Tests\Integration\Mock_Post_API_Trait;
use WP_REST_Request;

/**
 * Full Workflow Integration Test Class.
 *
 * Tests the complete auth → import → history workflow end-to-end.
 */
class Full_Workflow_Integration_Test extends Integration_Test_Case {

	use Mock_Post_API_Trait;

	/**
	 * Shared secret used when the constant is not already defined.
	 */
	private const FALLBACK_SECRET = 'full-workflow-test-shared-secret-32c';

	/**
	 * HMAC authenticator instance.
	 *
	 * @var HMAC_Authenticator
	 */
	private HMAC_Authenticator $authenticator;

	/**
	 * Post import service instance.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $import_service;

	/**
	 * Import history coordinator instance.
	 *
	 * @var Import_History
	 */
	private Import_History $import_history;

	/**
	 * History repository instance for direct assertions.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Sets up test dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		$logger             = new Auth_Logger();
		$permission_manager = new Permission_Manager( $logger, new Export_Logger() );

		$this->authenticator = new HMAC_Authenticator(
			$logger,
			$permission_manager,
			defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ? SAFE_PUBLISH_SHARED_SECRET : self::FALLBACK_SECRET,
			home_url()
		);

		$this->repository     = new History_Repository();
		$this->import_history = new Import_History(
			$this->repository,
			new History_Renderer(),
			new Session_Formatter(),
			new Session_Rollback_Service( $this->repository )
		);

		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer )
		);

		$this->import_service = new Post_Import_Service(
			new External_Posts_API( new HTTP_Client() ),
			$media_importer,
			$content_processor,
			$this->import_history,
			new Meta_Terms_Manager()
		);

		// Configure the connected site URL so fetch_fresh_content() can make requests.
		update_option( Options::OPTION_CONNECTED_SITE_URL, 'https://source.example.com' );

		// Mock the single-post REST endpoint used by fetch_fresh_content().
		add_filter( 'pre_http_request', array( $this, 'mock_post_api' ), 1, 3 );
	}

	/**
	 * Tears down test dependencies.
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
	 * Returns a post response built from defaults merged with $this->mock_post_overrides.
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
	 * Verifies that the complete auth → import → history workflow succeeds.
	 */
	public function test_complete_auth_import_history_workflow_succeeds(): void {
		// STEP 1 — AUTH: Authenticate a valid inbound REST request.
		$request     = $this->build_signed_request( 'POST', '/wp/v2/posts', 'request body' );
		$auth_result = $this->authenticator->authenticate_request( null, null, $request );

		$this->assertNull( $auth_result, 'Valid HMAC request should pass authentication.' );
		$this->assertTrue( $this->authenticator->is_authenticated() );

		// STEP 2 — IMPORT: Open a session and import a post.
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );
		$this->assertIsInt( $session_id );

		$post_data = array(
			'id'             => 4001,
			'title'          => 'Full Workflow Test Post',
			'content'        => '<p>Simple imported post content, no external media.</p>',
			'link'           => 'https://source.example.com/full-workflow-test-post',
			'featured_media' => 0,
			'post_type'      => 'posts',
		);

		$result = $this->import_service->import_post( $post_data, $session_id );

		$this->assertTrue( $result['success'], 'Post import should succeed.' );
		$this->assertIsInt( $result['post_id'] );
		$this->assertGreaterThan( 0, $result['post_id'], 'Import should return a valid post ID.' );
		$this->assertFalse( $result['existing'], 'Post should be newly created, not an update.' );

		// ASSERT: Verify the created post has the expected field values.
		$imported_post = get_post( $result['post_id'] );
		$this->assertSame( 'Test Post', $imported_post->post_title, 'Imported post title should match fresh-content response.' );
		$this->assertSame( 'draft', $imported_post->post_status, 'Newly imported post should be created as draft.' );
		$this->assertSame( '4001', get_post_meta( $result['post_id'], Options::META_EXTERNAL_POST_ID, true ), 'Imported post should store the external post ID.' );
		$this->assertSame( Options::META_IMPORTED_FROM_VALUE, get_post_meta( $result['post_id'], Options::META_IMPORTED_FROM, true ), 'Imported post should be tagged with the plugin identifier.' );

		// STEP 3 — HISTORY: Update stats, complete the session, then assert.
		$this->import_history->update_session_stats( $session_id, 'success' );
		$this->import_history->complete_session( $session_id );

		$session    = $this->repository->get_session( $session_id );
		$total      = (int) get_post_meta( $session->ID, 'total_items', true );
		$successful = (int) get_post_meta( $session->ID, 'successful', true );
		$failed     = (int) get_post_meta( $session->ID, 'failed', true );
		$status     = get_post_meta( $session->ID, 'status', true );

		$this->assertSame( 1, $total, 'Session should record 1 total item.' );
		$this->assertSame( 1, $successful, 'Session should record 1 successful import.' );
		$this->assertSame( 0, $failed, 'Session should record 0 failed imports.' );
		$this->assertSame( 'completed', $status, 'Session should be marked completed.' );

		// STEP 4 — LOG ENTRY: Verify the import service wrote a log entry for this session.
		$logs = $this->repository->get_session_logs( $session_id );

		$this->assertCount( 1, $logs, 'One log entry should have been created for the session.' );
		$this->assertSame( 'Test Post', $logs[0]->post_title );
		$this->assertSame( 'success', get_post_meta( $logs[0]->ID, 'status', true ) );
		$this->assertSame( $result['post_id'], (int) get_post_meta( $logs[0]->ID, 'post_id', true ) );
	}

	/**
	 * Verifies that importing the same external post a second time updates the
	 * existing post rather than creating a duplicate.
	 */
	public function test_reimporting_same_post_updates_existing_post(): void {
		// ARRANGE: Import a post for the first time.
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );
		$this->assertIsInt( $session_id );

		$post_data = array(
			'id'             => 5001,
			'title'          => 'Update Path Test Post',
			'content'        => '<p>Original content.</p>',
			'link'           => 'https://source.example.com/update-path-test-post',
			'featured_media' => 0,
			'post_type'      => 'posts',
		);

		$first_result = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first_result['success'], 'First import should succeed.' );
		$this->assertFalse( $first_result['existing'], 'First import should create a new post.' );

		// ACT: Re-import the same external post ID.
		$second_result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Second import takes the update path.
		$this->assertTrue( $second_result['success'], 'Second import should succeed.' );
		$this->assertTrue( $second_result['existing'], 'Second import should detect the existing post.' );
		$this->assertSame(
			$first_result['post_id'],
			$second_result['post_id'],
			'Both imports should resolve to the same WordPress post ID.'
		);

		// ASSERT: Post fields reflect the freshly fetched content.
		$updated_post = get_post( $second_result['post_id'] );
		$this->assertSame( 'Test Post', $updated_post->post_title, 'Re-imported post should have the title from the fresh-content response.' );

		// ASSERT: Two separate log entries were written (one per import call).
		$logs = $this->repository->get_session_logs( $session_id );
		$this->assertCount( 2, $logs, 'Each import call should produce its own log entry.' );

		$statuses = array_map(
			fn( $log ) => get_post_meta( $log->ID, 'status', true ),
			$logs
		);
		$this->assertContains( 'success', $statuses, 'First import log should have status "success".' );
		$this->assertContains( 'updated', $statuses, 'Second import log should have status "updated".' );
	}

	/**
	 * Verifies that bulk re-import does not reset post_status on an already-published post.
	 */
	public function test_bulk_reimport_preserves_published_post_status(): void {
		// ARRANGE: Import a post, then publish it to simulate a live post.
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'             => 7001,
			'title'          => 'Published Post',
			'content'        => '<p>Original content.</p>',
			'link'           => 'https://source.example.com/published-post',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		wp_update_post(
			array(
				'ID'          => $first['post_id'],
				'post_status' => 'publish',
			)
		);

		// ACT: Re-import the same post with updated content.
		$post_data['title'] = 'Published Post (updated)';

		$second = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Post status is preserved.
		$this->assertTrue( $second['success'] );
		$this->assertTrue( $second['existing'] );

		$updated_post = get_post( $second['post_id'] );
		$this->assertSame( 'publish', $updated_post->post_status, 'Bulk re-import must not demote a published post to draft.' );
	}

	/**
	 * Verifies that bulk import writes excerpt and meta to the created post.
	 */
	public function test_bulk_import_writes_excerpt_and_meta(): void {
		// ARRANGE: Prepare post data with an excerpt and a custom meta field.
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'             => 6001,
			'title'          => 'Post With Excerpt And Meta',
			'content'        => '<p>Content.</p>',
			'link'           => 'https://source.example.com/excerpt-meta-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => 'A short summary.',
			'meta'           => array( 'custom_key' => 'custom_value' ),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'excerpt' => 'A short summary.',
			'meta'    => array( 'custom_key' => 'custom_value' ),
		);

		// ACT: Import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Excerpt and meta are written to the created post.
		$this->assertTrue( $result['success'] );

		$post = get_post( $result['post_id'] );
		$this->assertSame( 'A short summary.', $post->post_excerpt );
		$this->assertSame( 'custom_value', get_post_meta( $result['post_id'], 'custom_key', true ) );
	}

	/**
	 * Verifies that bulk import writes taxonomy terms to the created post.
	 */
	public function test_bulk_import_writes_terms(): void {
		// ARRANGE: Prepare post data with a category term.
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

		$term = wp_insert_term( 'Integration Test Category', 'category' );
		$this->assertIsArray( $term );

		$post_data = array(
			'id'             => 6002,
			'title'          => 'Post With Terms',
			'content'        => '<p>Content.</p>',
			'link'           => 'https://source.example.com/terms-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array( 'category' => array( 'Integration Test Category' ) ),
		);

		$this->mock_post_overrides = array(
			'terms' => array( 'category' => array( 'Integration Test Category' ) ),
		);

		// ACT: Import the post.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Exactly one term is assigned to the created post.
		$this->assertTrue( $result['success'] );

		$assigned = wp_get_post_terms( $result['post_id'], 'category', array( 'fields' => 'names' ) );
		$this->assertCount( 1, $assigned, 'Exactly one category term should be assigned.' );
		$this->assertContains( 'Integration Test Category', $assigned );
	}

	/**
	 * Verifies that bulk re-import updates excerpt and meta on an existing post.
	 */
	public function test_bulk_reimport_updates_excerpt_and_meta(): void {
		// ARRANGE: Import a post with initial excerpt and meta.
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'             => 6003,
			'title'          => 'Post To Update',
			'content'        => '<p>Original.</p>',
			'link'           => 'https://source.example.com/update-excerpt-meta',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => 'Original excerpt.',
			'meta'           => array( 'my_key' => 'original_value' ),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'excerpt' => 'Original excerpt.',
			'meta'    => array( 'my_key' => 'original_value' ),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		// ACT: Update excerpt and meta, then re-import.
		$post_data['excerpt']        = 'Updated excerpt.';
		$post_data['meta']['my_key'] = 'updated_value';

		$this->mock_post_overrides = array(
			'excerpt' => 'Updated excerpt.',
			'meta'    => array( 'my_key' => 'updated_value' ),
		);

		$second = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Updated values are persisted on the existing post.
		$this->assertTrue( $second['success'] );
		$this->assertTrue( $second['existing'] );

		$post = get_post( $second['post_id'] );
		$this->assertSame( 'Updated excerpt.', $post->post_excerpt );
		$this->assertSame( 'updated_value', get_post_meta( $second['post_id'], 'my_key', true ) );
	}

	/**
	 * Verifies that bulk re-import replaces taxonomy terms on an existing post.
	 */
	public function test_bulk_reimport_updates_terms(): void {
		// ARRANGE: Import a post with one term.
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

		wp_insert_term( 'Original Term', 'category' );
		wp_insert_term( 'Replacement Term', 'category' );

		$post_data = array(
			'id'             => 6004,
			'title'          => 'Post With Terms To Update',
			'content'        => '<p>Content.</p>',
			'link'           => 'https://source.example.com/terms-update-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array( 'category' => array( 'Original Term' ) ),
		);

		$this->mock_post_overrides = array(
			'terms' => array( 'category' => array( 'Original Term' ) ),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		// ACT: Re-import with a different term.
		$post_data['terms'] = array( 'category' => array( 'Replacement Term' ) );

		$this->mock_post_overrides = array(
			'terms' => array( 'category' => array( 'Replacement Term' ) ),
		);

		$second = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Term is replaced, not appended; exactly one term should remain.
		$this->assertTrue( $second['success'] );
		$this->assertTrue( $second['existing'] );

		$assigned = wp_get_post_terms( $second['post_id'], 'category', array( 'fields' => 'names' ) );
		$this->assertCount( 1, $assigned, 'Exactly one category term should remain after replacement.' );
		$this->assertContains( 'Replacement Term', $assigned );
		$this->assertNotContains( 'Original Term', $assigned );
	}

	/**
	 * Verifies that bulk import preserves content with script tags when kses is
	 * disabled (default).
	 */
	public function test_bulk_import_preserves_content_by_default(): void {
		// ARRANGE: Content with a script tag that kses would strip.
		$session_id = $this->import_history->create_session(
			'https://source.example.com',
			'bulk'
		);

		$content = '<p>Safe content.</p>'
			. '<script>alert("xss")</script>';

		$post_data = array(
			'id'             => 8001,
			'title'          => 'Sanitization Test Post',
			'content'        => $content,
			'link'           => 'https://source.example.com/sanitization-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => $content,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Import succeeded with content preserved.
		$this->assertTrue( $result['success'] );

		$post = get_post( $result['post_id'] );
		$this->assertStringContainsString(
			'<script>',
			$post->post_content
		);
	}

	/**
	 * Verifies that bulk import preserves excerpts with script tags when kses
	 * is disabled (default).
	 */
	public function test_bulk_import_preserves_excerpt_by_default(): void {
		// ARRANGE: Excerpt with a script tag that kses would strip.
		$session_id = $this->import_history->create_session(
			'https://source.example.com',
			'bulk'
		);

		$excerpt = '<em>Summary.</em><script>xss</script>';

		$post_data = array(
			'id'             => 8002,
			'title'          => 'Excerpt Sanitization Test',
			'content'        => '<p>Clean content.</p>',
			'link'           => 'https://source.example.com/excerpt-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => $excerpt,
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => '<p>Clean content.</p>',
			'excerpt' => $excerpt,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		// ASSERT: Import succeeded with excerpt preserved.
		$this->assertTrue( $result['success'] );

		$post = get_post( $result['post_id'] );
		$this->assertStringContainsString(
			'<script>',
			$post->post_excerpt
		);
	}

	/**
	 * Provides stripping scenarios with content and expected error
	 * substrings.
	 *
	 * @return array[] Test cases keyed by label.
	 */
	public function provide_stripping_scenarios(): array {
		return array(
			'stripped tag'               => array(
				'<p>Text.</p><script>alert("xss")</script>',
				array( '<script>' ),
			),
			'stripped tag with attrs'    => array(
				'<!-- wp:html -->'
					. '<iframe src="https://youtube.com/embed/abc"'
					. ' width="560" height="315"></iframe>'
					. '<!-- /wp:html -->',
				array( '<iframe', 'src=' ),
			),
			'stripped attr on kept tag'  => array(
				'<p><img src="http://localhost/img.jpg"'
					. ' alt="Photo" decoding="async"/></p>',
				array( '<img', 'decoding=' ),
			),
			'multiple stripped elements' => array(
				'<!-- wp:html -->'
					. '<svg viewBox="0 0 100 100">'
					. '<circle cx="50" cy="50" r="40"/>'
					. '</svg>'
					. '<!-- /wp:html -->',
				array( '<svg', '<circle' ),
			),
		);
	}

	/**
	 * Verifies that the sanitization error message describes the specific HTML
	 * that was stripped for different stripping types when kses is enabled via
	 * filter.
	 *
	 * @dataProvider provide_stripping_scenarios
	 *
	 * @param string   $content           Content with strippable HTML.
	 * @param string[] $expected_in_error Substrings that must appear
	 *                                    in the error message.
	 */
	public function test_sanitization_error_describes_stripped_html(
		string $content,
		array $expected_in_error
	): void {
		// ARRANGE: Enable kses via filter, then import content that kses would
		// modify.
		add_filter( 'safe_publish_import_kses', '__return_true' );

		$session_id = $this->import_history->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'             => 8020,
			'title'          => 'Stripping Scenario Test',
			'content'        => $content,
			'link'           => 'https://source.example.com/strip-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => $content,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		remove_filter( 'safe_publish_import_kses', '__return_true' );

		// ASSERT: Import failed with a descriptive error.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'modified by sanitization',
			$result['error']
		);

		foreach ( $expected_in_error as $expected ) {
			$this->assertStringContainsString(
				$expected,
				$result['error'],
				"Error should mention: $expected"
			);
		}
	}

	/**
	 * Verifies that bulk import succeeds when kses-enabled sanitization only
	 * makes cosmetic whitespace changes (no false positives).
	 */
	public function test_bulk_import_succeeds_with_cosmetic_whitespace_changes(): void {
		// ARRANGE: Enable kses, then import content with inline styles that
		// kses normalizes (e.g. removes space after semicolons) but does not
		// meaningfully modify.
		add_filter( 'safe_publish_import_kses', '__return_true' );

		$session_id = $this->import_history->create_session(
			'https://source.example.com',
			'bulk'
		);

		$content = '<!-- wp:button -->'
			. '<div class="wp-block-button">'
			. '<a class="wp-block-button__link"'
			. ' style="background-color: #ff0000; color: #ffffff">'
			. 'Click Me</a></div>'
			. '<!-- /wp:button -->';

		$post_data = array(
			'id'             => 8010,
			'title'          => 'Cosmetic Whitespace Test',
			'content'        => $content,
			'link'           => 'https://source.example.com/style-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => $content,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		remove_filter( 'safe_publish_import_kses', '__return_true' );

		// ASSERT: Import succeeded despite cosmetic changes.
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Verifies that bulk import strips script tags from excerpts when kses is
	 * enabled via the safe_publish_import_kses filter.
	 */
	public function test_bulk_import_sanitizes_excerpt(): void {
		// ARRANGE: Enable kses, then import an excerpt with a script tag.
		add_filter( 'safe_publish_import_kses', '__return_true' );

		$session_id = $this->import_history->create_session(
			'https://source.example.com',
			'bulk'
		);

		$excerpt = '<em>Summary.</em><script>xss</script>';

		$post_data = array(
			'id'             => 8031,
			'title'          => 'Kses Filter Excerpt Test',
			'content'        => '<p>Clean content.</p>',
			'link'           => 'https://source.example.com/kses-excerpt',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => $excerpt,
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => '<p>Clean content.</p>',
			'excerpt' => $excerpt,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		remove_filter( 'safe_publish_import_kses', '__return_true' );

		// ASSERT: Import failed due to excerpt sanitization.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'excerpt',
			$result['error']
		);
		$this->assertStringContainsString(
			'<script>',
			$result['error']
		);
	}

	/**
	 * Verifies that reimporting a post with kses enabled fails when the updated
	 * content contains tags that kses would strip.
	 */
	public function test_bulk_reimport_sanitizes_post_content(): void {
		// ARRANGE: First import clean content, then reimport with a script tag
		// while kses is enabled.
		$session_id = $this->import_history->create_session(
			'https://source.example.com',
			'bulk'
		);

		$post_data = array(
			'id'             => 8032,
			'title'          => 'Reimport Sanitization Test',
			'content'        => '<p>Clean content.</p>',
			'link'           => 'https://source.example.com/reimport-kses',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => '<p>Clean content.</p>',
		);

		$first_result = $this->import_service->import_post(
			$post_data,
			$session_id
		);
		$this->assertTrue( $first_result['success'] );

		// ACT: Reimport with kses enabled and a script tag.
		add_filter( 'safe_publish_import_kses', '__return_true' );

		$dirty_content = '<p>Updated.</p>'
			. '<script>alert("xss")</script>';

		$this->mock_post_overrides = array(
			'content' => $dirty_content,
		);

		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		remove_filter( 'safe_publish_import_kses', '__return_true' );

		// ASSERT: Reimport failed due to sanitization.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'modified by sanitization',
			$result['error']
		);
	}

	/**
	 * Verifies that the safe_publish_kses_allowed_html filter lets developers
	 * customize which tags are allowed when kses is enabled.
	 */
	public function test_bulk_import_uses_custom_allowed_tags(): void {
		// ARRANGE: Enable kses and add <iframe> to allowed tags.
		add_filter( 'safe_publish_import_kses', '__return_true' );

		$allow_iframes = static function ( array $allowed ): array {
			$allowed['iframe'] = array(
				'src'    => true,
				'width'  => true,
				'height' => true,
			);
			return $allowed;
		};
		add_filter(
			'safe_publish_kses_allowed_html',
			$allow_iframes
		);

		$session_id = $this->import_history->create_session(
			'https://source.example.com',
			'bulk'
		);

		$content = '<p>Watch this:</p>'
			. '<iframe src="https://youtube.com/embed/abc"'
			. ' width="560" height="315"></iframe>';

		$post_data = array(
			'id'             => 8033,
			'title'          => 'Custom Allowed Tags Test',
			'content'        => $content,
			'link'           => 'https://source.example.com/custom-tags',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => $content,
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post(
			$post_data,
			$session_id
		);

		remove_filter( 'safe_publish_import_kses', '__return_true' );
		remove_filter(
			'safe_publish_kses_allowed_html',
			$allow_iframes
		);

		// ASSERT: Import succeeded — iframe is in the custom allowlist.
		$this->assertTrue( $result['success'] );

		$post = get_post( $result['post_id'] );
		$this->assertStringContainsString(
			'<iframe',
			$post->post_content
		);
	}

	/**
	 * Verifies that an invalid HMAC signature is rejected and no import runs.
	 */
	public function test_invalid_auth_is_rejected_before_import(): void {
		// ARRANGE: A request with a tampered signature.
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body( 'some body' );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) time() );
		$request->set_header( 'X-Safe-Publish-Content-Hash', hash( 'sha256', 'some body' ) );
		$request->set_header( 'X-Safe-Publish-Site-URL', home_url() );
		$request->set_header( 'X-Safe-Publish-Signature', 'tampered-invalid-signature' );

		// ACT: Attempt authentication.
		$auth_result = $this->authenticator->authenticate_request( null, null, $request );

		// ASSERT: Auth failed; no import should have proceeded.
		$this->assertInstanceOf( \WP_Error::class, $auth_result );
		$this->assertSame( 'safe_publish_auth_invalid', $auth_result->get_error_code() );
		$this->assertFalse( $this->authenticator->is_authenticated() );
	}

	/**
	 * Builds a properly signed WP_REST_Request.
	 *
	 * @param string $method    HTTP method.
	 * @param string $route     REST route path.
	 * @param string $body      Request body.
	 * @param int    $timestamp Optional. Unix timestamp. Defaults to current time.
	 * @return WP_REST_Request Signed request.
	 */
	private function build_signed_request(
		string $method,
		string $route,
		string $body,
		int $timestamp = 0
	): WP_REST_Request {
		if ( 0 === $timestamp ) {
			$timestamp = time();
		}

		$secret         = defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ? SAFE_PUBLISH_SHARED_SECRET : self::FALLBACK_SECRET;
		$site_url       = home_url();
		$content_hash   = hash( 'sha256', $body );
		$string_to_sign = $method . '|' . $route . '|' . $timestamp . '|' . $content_hash . '|' . $site_url;
		$signature      = hash_hmac( 'sha256', $string_to_sign, $secret );

		$request = new WP_REST_Request( $method, $route );
		$request->set_body( $body );
		$request->set_header( 'X-Safe-Publish-Timestamp', (string) $timestamp );
		$request->set_header( 'X-Safe-Publish-Content-Hash', $content_hash );
		$request->set_header( 'X-Safe-Publish-Site-URL', $site_url );
		$request->set_header( 'X-Safe-Publish-Signature', $signature );

		return $request;
	}
}
