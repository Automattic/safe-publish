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
use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\HMAC_Authenticator;
use Safe_Publish\Auth\Permission_Manager;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Embed_Processor;
use Safe_Publish\Media\Media_Importer;
use WP_REST_Request;

/**
 * Full Workflow Integration Test Class.
 *
 * Tests the complete auth → import → history workflow end-to-end.
 */
class Full_Workflow_Integration_Test extends Integration_Test_Case {

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
			$this->import_history
		);
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
		$this->assertSame( 'Full Workflow Test Post', $logs[0]->post_title );
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
