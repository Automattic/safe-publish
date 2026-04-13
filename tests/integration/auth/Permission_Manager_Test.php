<?php
/**
 * Integration tests for the Permission Manager.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\Permission_Manager;
use Safe_Publish\API\Export_Logger;
use Safe_Publish\Utils\Event_Table;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Permission Manager Test.
 *
 * Tests permission assignment for authenticated vs unauthenticated requests.
 */
class Permission_Manager_Test extends WP_UnitTestCase {

	/**
	 * Permission manager instance.
	 *
	 * @var Permission_Manager
	 */
	private Permission_Manager $permission_manager;

	/**
	 * Sets up each test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->permission_manager = new Permission_Manager(
			new Auth_Logger(),
			new Export_Logger()
		);

		// Clear any stored export events before each test.
		Event_Table::create_table();
		Event_Table::clear( 'export' );
	}

	/**
	 * Verifies that an authenticated request gets the correct capabilities
	 * assigned.
	 */
	public function test_authenticated_request_gets_correct_capabilities(): void {
		// ARRANGE: Create a subscriber (no edit capabilities by default).
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );

		// ACT: Set up authenticated context.
		$this->permission_manager->setup_authenticated_context( $request );

		// ASSERT: Capability filter grants edit_posts.
		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertTrue( $this->permission_manager->is_authenticated() );
	}

	/**
	 * Verifies that an unauthenticated request has no special capabilities
	 * added.
	 */
	public function test_unauthenticated_request_has_no_special_capabilities(): void {
		// ARRANGE: Create a subscriber (no edit capabilities by default).
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// ACT: No authentication context set up.

		// ASSERT: No elevated capabilities granted.
		$this->assertFalse( current_user_can( 'edit_posts' ) );
		$this->assertFalse( $this->permission_manager->is_authenticated() );
	}

	/**
	 * Verifies that a successful authenticated collection request logs a
	 * CONTENT_EXPORTED event with the correct post IDs, post type, and
	 * destination URL parsed from the User-Agent header.
	 */
	public function test_log_export_event_logs_collection_export(): void {
		// ARRANGE: Set up an authenticated context with a User-Agent header and
		// a two-post collection response.
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		$_SERVER['HTTP_USER_AGENT'] = 'Safe Publish/1.0.0; https://dest.example.com';
		$request                    = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->permission_manager->setup_authenticated_context( $request );

		$response = new WP_REST_Response(
			array(
				array(
					'id'    => 10,
					'title' => array( 'rendered' => 'Post A' ),
				),
				array(
					'id'    => 20,
					'title' => array( 'rendered' => 'Post B' ),
				),
			),
			200
		);

		// ACT: Trigger log_export_event on the collection response.
		$this->permission_manager->log_export_event( $response, rest_get_server(), $request );

		// ASSERT: One CONTENT_EXPORTED event is stored with the correct rest_base,
		// destination URL, post IDs, and count.
		$events = Event_Table::get_events(
			array(
				'channel'    => 'export',
				'event_type' => 'CONTENT_EXPORTED',
			)
		);
		$this->assertCount( 1, $events );

		$data = $events[0]['data'];
		$this->assertSame( 'posts', $data['rest_base'] );
		$this->assertSame( 'https://dest.example.com', $data['destination_url'] );
		$this->assertSame( array( 10, 20 ), $data['post_ids'] );
		$this->assertSame( 2, $data['post_count'] );

		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/**
	 * Verifies that a successful authenticated single-post request logs a
	 * CONTENT_EXPORTED event with the correct post ID.
	 */
	public function test_log_export_event_logs_single_post_export(): void {
		// ARRANGE: Set up an authenticated context with a single-post response.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/42' );
		$this->permission_manager->setup_authenticated_context( $request );

		$response = new WP_REST_Response(
			array(
				'id'    => 42,
				'title' => array( 'rendered' => 'A Post' ),
			),
			200
		);

		// ACT: Trigger log_export_event on the single-post response.
		$this->permission_manager->log_export_event( $response, rest_get_server(), $request );

		// ASSERT: One CONTENT_EXPORTED event is stored with the correct rest_base,
		// post ID, and count.
		$events = Event_Table::get_events(
			array(
				'channel'    => 'export',
				'event_type' => 'CONTENT_EXPORTED',
			)
		);
		$this->assertCount( 1, $events );

		$data = $events[0]['data'];
		$this->assertSame( 'posts', $data['rest_base'] );
		$this->assertSame( array( 42 ), $data['post_ids'] );
		$this->assertSame( 1, $data['post_count'] );
	}

	/**
	 * Verifies that log_export_event does nothing for unauthenticated requests.
	 */
	public function test_log_export_event_skips_unauthenticated_requests(): void {
		// ARRANGE: Prepare a request and response without calling setup_authenticated_context.
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$response = new WP_REST_Response( array( array( 'id' => 5 ) ), 200 );

		// ACT: No setup_authenticated_context call.
		$this->permission_manager->log_export_event( $response, rest_get_server(), $request );

		// ASSERT: No export events are stored.
		$events = Event_Table::get_events( array( 'channel' => 'export' ) );
		$this->assertCount( 0, $events );
	}

	/**
	 * Verifies that an authenticated request with a non-2xx response logs an
	 * EXPORT_FAILED error event with the HTTP status code.
	 */
	public function test_log_export_event_logs_failed_http_response(): void {
		// ARRANGE: Set up an authenticated context with a 403 response.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->permission_manager->setup_authenticated_context( $request );

		$response = new WP_REST_Response( array(), 403 );

		// ACT: Trigger log_export_event on the failed response.
		$this->permission_manager->log_export_event( $response, rest_get_server(), $request );

		// ASSERT: One EXPORT_FAILED error event is stored with the HTTP status and route.
		$events = Event_Table::get_events(
			array(
				'channel'    => 'export',
				'event_type' => 'EXPORT_FAILED',
				'level'      => 'error',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 403, $events[0]['data']['status'] );
		$this->assertSame( '/wp/v2/posts', $events[0]['data']['route'] );
	}

	/**
	 * Verifies that an authenticated request that results in a WP_Error logs an
	 * EXPORT_FAILED error event with the error code and message.
	 */
	public function test_log_export_event_logs_wp_error_response(): void {
		// ARRANGE: Set up an authenticated context with a WP_Error for an invalid post ID.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/99' );
		$this->permission_manager->setup_authenticated_context( $request );

		$error = new WP_Error( 'rest_post_invalid_id', 'Invalid post ID.' );

		// ACT: Trigger log_export_event on the WP_Error response.
		$this->permission_manager->log_export_event( $error, rest_get_server(), $request );

		// ASSERT: One EXPORT_FAILED error event is stored with the error code and message.
		$events = Event_Table::get_events(
			array(
				'channel'    => 'export',
				'event_type' => 'EXPORT_FAILED',
				'level'      => 'error',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'rest_post_invalid_id', $events[0]['data']['error_code'] );
		$this->assertSame( 'Invalid post ID.', $events[0]['data']['error_message'] );
	}
}
