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
use Safe_Publish\Content\Embed_Processor;
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
			new Content_Media_Processor( $media_importer, new Embed_Processor() )
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
		$this->assertFalse( $result['existing'], 'Post should be newly created, not an update.' );

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

		// ASSERT: Term is assigned to the created post.
		$this->assertTrue( $result['success'] );

		$assigned = wp_get_post_terms( $result['post_id'], 'category', array( 'fields' => 'names' ) );
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

		// ASSERT: Term is replaced, not appended.
		$this->assertTrue( $second['success'] );
		$this->assertTrue( $second['existing'] );

		$assigned = wp_get_post_terms( $second['post_id'], 'category', array( 'fields' => 'names' ) );
		$this->assertContains( 'Replacement Term', $assigned );
		$this->assertNotContains( 'Original Term', $assigned );
	}

	/**
	 * Verifies that bulk import sanitizes post content with wp_kses_post before
	 * writing it to the database.
	 */
	public function test_bulk_import_sanitizes_post_content(): void {
		// ARRANGE: Content with a disallowed script tag that wp_kses_post must strip.
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'             => 8001,
			'title'          => 'Sanitization Test Post',
			'content'        => '<p>Safe content.</p><script>alert("xss")</script>',
			'link'           => 'https://source.example.com/sanitization-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'content' => '<p>Safe content.</p><script>alert("xss")</script>',
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Post was created and unsafe markup was stripped.
		$this->assertTrue( $result['success'] );

		$post = get_post( $result['post_id'] );
		$this->assertStringContainsString( '<p>Safe content.</p>', $post->post_content );
		$this->assertStringNotContainsString( '<script>', $post->post_content );
	}

	/**
	 * Verifies that bulk import strips disallowed HTML from excerpts while
	 * preserving allowed inline tags.
	 */
	public function test_bulk_import_sanitizes_excerpt(): void {
		// ARRANGE: Excerpt with a disallowed script tag and an allowed inline tag.
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'             => 8002,
			'title'          => 'Excerpt Sanitization Test',
			'content'        => '<p>Content.</p>',
			'link'           => 'https://source.example.com/excerpt-sanitization-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '<em>Safe excerpt.</em><script>alert("xss")</script>',
			'meta'           => array(),
			'terms'          => array(),
		);

		$this->mock_post_overrides = array(
			'excerpt' => '<em>Safe excerpt.</em><script>alert("xss")</script>',
		);

		// ACT: Import via the bulk path.
		$result = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Allowed HTML is preserved; disallowed markup is stripped.
		$this->assertTrue( $result['success'] );

		$post = get_post( $result['post_id'] );
		$this->assertStringContainsString( '<em>Safe excerpt.</em>', $post->post_excerpt );
		$this->assertStringNotContainsString( '<script>', $post->post_excerpt );
	}

	/**
	 * Verifies that bulk re-import sanitizes post content when updating an
	 * existing post.
	 */
	public function test_bulk_reimport_sanitizes_post_content(): void {
		// ARRANGE: Import a post, then re-import with unsafe content.
		$session_id = $this->import_history->create_session( 'https://source.example.com', 'bulk' );

		$post_data = array(
			'id'             => 8003,
			'title'          => 'Content Sanitization On Update Test',
			'content'        => '<p>Original content.</p>',
			'link'           => 'https://source.example.com/content-sanitization-update-test',
			'featured_media' => 0,
			'post_type'      => 'posts',
			'excerpt'        => '',
			'meta'           => array(),
			'terms'          => array(),
		);

		$first = $this->import_service->import_post( $post_data, $session_id );
		$this->assertTrue( $first['success'] );

		// ACT: Re-import with a script tag injected into the content.
		$post_data['content'] = '<p>Updated content.</p><script>alert("xss")</script>';

		$this->mock_post_overrides = array(
			'content' => '<p>Updated content.</p><script>alert("xss")</script>',
		);

		$second = $this->import_service->import_post( $post_data, $session_id );

		// ASSERT: Update succeeded and the script tag was stripped.
		$this->assertTrue( $second['success'] );
		$this->assertTrue( $second['existing'] );

		$post = get_post( $second['post_id'] );
		$this->assertStringContainsString( '<p>Updated content.</p>', $post->post_content );
		$this->assertStringNotContainsString( '<script>', $post->post_content );
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
