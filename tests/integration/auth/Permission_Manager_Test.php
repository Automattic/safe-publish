<?php
/**
 * Integration tests for the Permission Manager.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use Safe_Publish\API\Dispatch_Logger;
use Safe_Publish\API\Export_Logger;
use Safe_Publish\API\Request_Actions;
use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\Permission_Manager;
use Safe_Publish\Utils\Audit_Log_Table;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Permission Manager Test Class.
 */
class Permission_Manager_Test extends WP_UnitTestCase {

	/**
	 * User-Agent string the destination sends; HTTP_Client::parse_destination_site_url
	 * extracts the destination URL from it into log payloads.
	 */
	private const TEST_USER_AGENT = 'Safe Publish/0.3.0; https://dest.example.com';

	/**
	 * Destination URL portion of TEST_USER_AGENT, as it appears in log rows.
	 */
	private const TEST_DESTINATION_URL = 'https://dest.example.com';

	/**
	 * Non-numeric string user id — the value class that crashed the callback
	 * pre-fix. A numeric string would coerce through core's weak-mode dispatch.
	 */
	private const STRING_USER_ID = '';

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
			new Export_Logger(),
			new Dispatch_Logger()
		);

		// Clear export and dispatch channels before each test.
		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'export' );
		Audit_Log_Table::clear( 'dispatch' );
	}

	/**
	 * Clears the action header between tests so leakage from one case can't
	 * affect another via the shared $_SERVER superglobal.
	 */
	#[\Override]
	protected function tearDown(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_X_SAFE_PUBLISH_ACTION'] );
		parent::tearDown();
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
	 * Verifies that a successful import call logs a CONTENT_EXPORTED event with
	 * the correct post ID, post type, and destination URL parsed from the
	 * User-Agent header.
	 */
	public function test_import_action_logs_content_exported(): void {
		// ARRANGE: Authenticated context with User-Agent, IMPORT action, and a
		// single-post response.
		$this->set_request_headers( self::TEST_USER_AGENT, Request_Actions::IMPORT );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/42' );
		$this->permission_manager->setup_authenticated_context( $request );

		$response = new WP_REST_Response(
			array(
				'id'    => 42,
				'title' => array( 'rendered' => 'A Post' ),
			),
			200
		);

		// ACT: Trigger the dispatch hook on the single-post response.
		$this->permission_manager->log_dispatch_event( $response, rest_get_server(), $request );

		// ASSERT: One CONTENT_EXPORTED row stored with the correct rest_base,
		// destination URL, post ID, and count.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'export',
				'event_type' => 'CONTENT_EXPORTED',
			)
		);
		$this->assertCount( 1, $events );

		$data = $events[0]['data'];
		$this->assertSame( 'posts', $data['rest_base'] );
		$this->assertSame( self::TEST_DESTINATION_URL, $data['destination_site_url'] );
		$this->assertSame( array( 42 ), $data['post_ids'] );
		$this->assertSame( 1, $data['post_count'] );
	}

	/**
	 * Verifies that a list action with a collection response writes no rows
	 * to the export channel — listings are not exports.
	 */
	public function test_list_action_does_not_log_to_export_channel(): void {
		// ARRANGE: Authenticated context with LIST_ITEMS action and a collection response.
		$this->set_request_headers( self::TEST_USER_AGENT, Request_Actions::LIST_ITEMS );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->permission_manager->setup_authenticated_context( $request );

		$response = new WP_REST_Response(
			array(
				array( 'id' => 10 ),
				array( 'id' => 20 ),
			),
			200
		);

		// ACT: Trigger the dispatch hook on the collection response.
		$this->permission_manager->log_dispatch_event( $response, rest_get_server(), $request );

		// ASSERT: No export-channel rows; listings live in the auth channel
		// via REQUEST_AUTHENTICATED, not in the export history.
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'export' ) ) );
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'dispatch' ) ) );
	}

	/**
	 * Verifies that an authenticated dispatch with no action header writes
	 * nothing to either channel. _embed subrequests inherit $_SERVER, so
	 * they're covered separately by the dispatch-depth flag test.
	 */
	public function test_missing_action_header_writes_no_entries(): void {
		// ARRANGE: Authenticated context with User-Agent but NO action header.
		$this->set_request_headers( self::TEST_USER_AGENT, null );
		$request = new WP_REST_Request( 'GET', '/wp/v2/users/7' );
		$this->permission_manager->setup_authenticated_context( $request );

		$response = new WP_REST_Response(
			array( 'id' => 7 ),
			200
		);

		// ACT: Trigger the dispatch hook on the subrequest-shaped response.
		$this->permission_manager->log_dispatch_event( $response, rest_get_server(), $request );

		// ASSERT: No rows in either channel.
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'export' ) ) );
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'dispatch' ) ) );
	}

	/**
	 * Verifies that an unrecognized action header writes nothing — the gate
	 * fails closed for unrecognized values.
	 */
	public function test_unrecognized_action_writes_no_entries(): void {
		// ARRANGE: Authenticated context with an action that's not in the vocab.
		$this->set_request_headers( self::TEST_USER_AGENT, 'totally-made-up' );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/42' );
		$this->permission_manager->setup_authenticated_context( $request );

		$response = new WP_REST_Response( array( 'id' => 42 ), 200 );

		// ACT: Trigger the dispatch hook.
		$this->permission_manager->log_dispatch_event( $response, rest_get_server(), $request );

		// ASSERT: Unrecognized actions fall through silently here; the auth
		// channel captures REQUEST_ACTION_UNRECOGNIZED separately via
		// HMAC_Authenticator.
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'export' ) ) );
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'dispatch' ) ) );
	}

	/**
	 * Verifies that log_dispatch_event does nothing for unauthenticated
	 * requests.
	 */
	public function test_log_dispatch_event_skips_unauthenticated_requests(): void {
		// ARRANGE: Action header set but no setup_authenticated_context call.
		$this->set_request_headers( self::TEST_USER_AGENT, Request_Actions::IMPORT );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/42' );
		$response = new WP_REST_Response( array( 'id' => 42 ), 200 );

		// ACT: Trigger the dispatch hook on an unauthenticated request.
		$this->permission_manager->log_dispatch_event( $response, rest_get_server(), $request );

		// ASSERT: Neither channel records the dispatch.
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'export' ) ) );
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'dispatch' ) ) );
	}

	/**
	 * Verifies that an import action with a non-200 response logs an
	 * EXPORT_RESPONSE_BAD_STATUS error event with the HTTP status code.
	 */
	public function test_import_action_logs_bad_status_to_export_channel(): void {
		// ARRANGE: Authenticated context with IMPORT action and a 403 response.
		$this->set_request_headers( self::TEST_USER_AGENT, Request_Actions::IMPORT );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/42' );
		$this->permission_manager->setup_authenticated_context( $request );

		$response = new WP_REST_Response( array(), 403 );

		// ACT: Trigger the dispatch hook on the failed response.
		$this->permission_manager->log_dispatch_event( $response, rest_get_server(), $request );

		// ASSERT: One EXPORT_RESPONSE_BAD_STATUS row with status and route.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'export',
				'event_type' => 'EXPORT_RESPONSE_BAD_STATUS',
				'level'      => 'error',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 403, $events[0]['data']['status'] );
		$this->assertSame( '/wp/v2/posts/42', $events[0]['data']['route'] );
	}

	/**
	 * Verifies that an import action returning WP_Error logs an
	 * EXPORT_REQUEST_ERROR event with the error code and message.
	 */
	public function test_import_action_logs_wp_error_to_export_channel(): void {
		// ARRANGE: Authenticated context with IMPORT action and a WP_Error response.
		$this->set_request_headers( self::TEST_USER_AGENT, Request_Actions::IMPORT );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/99' );
		$this->permission_manager->setup_authenticated_context( $request );

		$error = new WP_Error( 'rest_post_invalid_id', 'Invalid post ID.' );

		// ACT: Trigger the dispatch hook on the WP_Error response.
		$this->permission_manager->log_dispatch_event( $error, rest_get_server(), $request );

		// ASSERT: One EXPORT_REQUEST_ERROR row with the error code and message.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'export',
				'event_type' => 'EXPORT_REQUEST_ERROR',
				'level'      => 'error',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'rest_post_invalid_id', $events[0]['data']['error_code'] );
		$this->assertSame( 'Invalid post ID.', $events[0]['data']['error_message'] );
	}

	/**
	 * Verifies that a list action that returns a non-200 response routes to
	 * the dispatch channel — not the export channel — with the declared
	 * action recorded for forensics.
	 */
	public function test_list_action_logs_bad_status_to_dispatch_channel(): void {
		// ARRANGE: Authenticated LIST_ITEMS request with a 500 response.
		$this->set_request_headers( self::TEST_USER_AGENT, Request_Actions::LIST_ITEMS );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->permission_manager->setup_authenticated_context( $request );

		$response = new WP_REST_Response( array(), 500 );

		// ACT: Trigger the dispatch hook.
		$this->permission_manager->log_dispatch_event( $response, rest_get_server(), $request );

		// ASSERT: Bad-status row lives in the dispatch channel with the action
		// label preserved; nothing is written to the export channel.
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'export' ) ) );

		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'dispatch',
				'event_type' => 'DISPATCH_RESPONSE_BAD_STATUS',
				'level'      => 'error',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 500, $events[0]['data']['status'] );
		$this->assertSame( '/wp/v2/posts', $events[0]['data']['route'] );
		$this->assertSame( Request_Actions::LIST_ITEMS, $events[0]['data']['action'] );
		$this->assertSame( self::TEST_DESTINATION_URL, $events[0]['data']['destination_site_url'] );
	}

	/**
	 * Verifies that a preview action returning WP_Error routes to the
	 * dispatch channel with error code and message preserved.
	 */
	public function test_preview_action_logs_wp_error_to_dispatch_channel(): void {
		// ARRANGE: Authenticated PREVIEW request with a WP_Error response.
		$this->set_request_headers( self::TEST_USER_AGENT, Request_Actions::PREVIEW );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/77' );
		$this->permission_manager->setup_authenticated_context( $request );

		$error = new WP_Error( 'preview_failed', 'Preview rendering failed.' );

		// ACT: Trigger the dispatch hook on the WP_Error response.
		$this->permission_manager->log_dispatch_event( $error, rest_get_server(), $request );

		// ASSERT: One DISPATCH_REQUEST_ERROR row with the action recorded.
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'export' ) ) );

		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'dispatch',
				'event_type' => 'DISPATCH_REQUEST_ERROR',
				'level'      => 'error',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'preview_failed', $events[0]['data']['error_code'] );
		$this->assertSame( 'Preview rendering failed.', $events[0]['data']['error_message'] );
		$this->assertSame( Request_Actions::PREVIEW, $events[0]['data']['action'] );
	}

	/**
	 * Verifies that _embed subrequests fired after a real export produce
	 * no extra rows. Subrequests inherit $_SERVER (including the action
	 * header), so without dispatch_logged each would log its own
	 * CONTENT_EXPORTED — the original bug.
	 */
	public function test_embed_subrequests_do_not_produce_extra_export_rows(): void {
		// ARRANGE: Authenticated IMPORT request for a single post.
		$this->set_request_headers( self::TEST_USER_AGENT, Request_Actions::IMPORT );
		$main_request = new WP_REST_Request( 'GET', '/wp/v2/posts/42' );
		$this->permission_manager->setup_authenticated_context( $main_request );

		$main_response = new WP_REST_Response(
			array(
				'id'    => 42,
				'title' => array( 'rendered' => 'A Post' ),
			),
			200
		);

		// ACT: Main dispatch + synthetic subrequests for the embedded
		// resources, all sharing $_SERVER — mirrors WP core's embed_links().
		$this->permission_manager->log_dispatch_event(
			$main_response,
			rest_get_server(),
			$main_request
		);

		foreach (
			array(
				array( '/wp/v2/users/7', array( 'id' => 7 ) ),
				array( '/wp/v2/media/100', array( 'id' => 100 ) ),
				array( '/wp/v2/categories/5', array( 'id' => 5 ) ),
				array( '/wp/v2/tags/12', array( 'id' => 12 ) ),
			)
			as [ $sub_route, $sub_data ]
		) {
			$sub_request  = new WP_REST_Request( 'GET', $sub_route );
			$sub_response = new WP_REST_Response( $sub_data, 200 );
			$this->permission_manager->log_dispatch_event(
				$sub_response,
				rest_get_server(),
				$sub_request
			);
		}

		// ASSERT: Exactly one CONTENT_EXPORTED row for the main post, no rows
		// for the embedded subresources.
		$events = Audit_Log_Table::get_events( array( 'channel' => 'export' ) );
		$this->assertCount( 1, $events );
		$this->assertSame( 'CONTENT_EXPORTED', $events[0]['event'] );
		$this->assertSame( 'posts', $events[0]['data']['rest_base'] );
		$this->assertSame( array( 42 ), $events[0]['data']['post_ids'] );
	}

	/**
	 * Verifies that a successful list action writes no rows to the dispatch
	 * channel — only failures land there; success lives in the auth channel.
	 */
	public function test_successful_list_action_writes_no_dispatch_rows(): void {
		// ARRANGE: Authenticated LIST_ITEMS request with a 200 response.
		$this->set_request_headers( self::TEST_USER_AGENT, Request_Actions::LIST_ITEMS );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->permission_manager->setup_authenticated_context( $request );

		$response = new WP_REST_Response(
			array( array( 'id' => 1 ) ),
			200
		);

		// ACT: Trigger the dispatch hook on the successful response.
		$this->permission_manager->log_dispatch_event( $response, rest_get_server(), $request );

		// ASSERT: No rows in either channel; success is captured by the auth
		// channel's REQUEST_AUTHENTICATED event upstream.
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'export' ) ) );
		$this->assertCount( 0, Audit_Log_Table::get_events( array( 'channel' => 'dispatch' ) ) );
	}

	/**
	 * Verifies that tear_down_authenticated_context short-circuits while the
	 * rest_forbidden_context re-dispatch is in flight, so the outer dispatch
	 * keeps the filters it needs to log and clean up.
	 */
	public function test_teardown_skips_during_context_override_redispatch(): void {
		// ARRANGE: Authenticated context plus a subscriber session, then
		// simulate the mid-redispatch state override_context_permissions sets
		// before its inner $server->dispatch() call.
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/42' );
		$this->permission_manager->setup_authenticated_context( $request );
		$this->set_context_override( true );

		$response = new WP_REST_Response( array( 'id' => 42 ), 200 );

		// ACT: Fire rest_post_dispatch as the inner re-dispatch would.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );

		// ASSERT: Filters and state survive — the outer dispatch's
		// log_dispatch_event still needs them to record its audit row.
		$this->assertTrue( $this->permission_manager->is_authenticated() );
		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertNotFalse(
			has_filter(
				'rest_post_dispatch',
				array( $this->permission_manager, 'log_dispatch_event' )
			)
		);
		$this->assertNotFalse(
			has_filter(
				'rest_post_dispatch',
				array( $this->permission_manager, 'tear_down_authenticated_context' )
			)
		);
	}

	/**
	 * Verifies that two sequential authenticated dispatches do not share
	 * capability state — teardown returns the user to baseline before a
	 * second setup elevates again.
	 */
	public function test_sequential_authenticated_contexts_do_not_leak_capabilities(): void {
		// ARRANGE: A subscriber baseline with no edit capabilities.
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/42' );
		$response = new WP_REST_Response( array( 'id' => 42 ), 200 );

		// ACT 1: First authenticated dispatch — teardown fires at the end of
		// the rest_post_dispatch chain.
		$this->permission_manager->setup_authenticated_context( $request );
		$this->assertTrue( current_user_can( 'edit_posts' ) );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );

		// ASSERT: Between dispatches, the subscriber is back to baseline.
		$this->assertFalse( $this->permission_manager->is_authenticated() );
		$this->assertFalse( current_user_can( 'edit_posts' ) );

		// ACT 2: A second context on the same instance must elevate again
		// without residue from the prior teardown.
		$this->permission_manager->setup_authenticated_context( $request );

		// ASSERT: Caps fully restored — re-registration of the cleared
		// filters works the second time around.
		$this->assertTrue( $this->permission_manager->is_authenticated() );
		$this->assertTrue( current_user_can( 'edit_posts' ) );
	}

	/**
	 * Verifies that an edit capability maps to the always-granted 'exist'
	 * when the map_meta_cap user id arrives as a string.
	 */
	public function test_override_meta_capabilities_grants_edit_cap_for_string_user_id(): void {
		// ARRANGE: Authenticated context so the edit-capability branch runs.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/42' );
		$this->permission_manager->setup_authenticated_context( $request );

		// ACT: Map an edit capability with a string user id.
		$caps = $this->permission_manager->override_meta_capabilities(
			array( 'edit_post' ),
			'edit_post',
			self::STRING_USER_ID,
			array()
		);

		// ASSERT: Granted via 'exist'.
		$this->assertSame( array( 'exist' ), $caps );
	}

	/**
	 * Verifies that firing the map_meta_cap filter through WordPress'
	 * weak-mode hook dispatch survives a non-numeric string user id,
	 * reproducing the production path that crashed before the fix.
	 */
	public function test_map_meta_cap_filter_survives_string_user_id(): void {
		// ARRANGE: Authenticated context registers the map_meta_cap callback.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/42' );
		$this->permission_manager->setup_authenticated_context( $request );

		// ACT: Fire the filter as core's map_meta_cap() does.
		$caps = apply_filters(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'map_meta_cap',
			array( 'edit_post' ),
			'edit_post',
			self::STRING_USER_ID,
			array()
		);

		// ASSERT: Granted via 'exist'.
		$this->assertSame( array( 'exist' ), $caps );
	}

	/**
	 * Sets the private $context_override property via reflection, mirroring
	 * what override_context_permissions does before its inner $server->dispatch().
	 *
	 * @param bool $value New value for $context_override.
	 */
	private function set_context_override( bool $value ): void {
		$property = new \ReflectionProperty( $this->permission_manager, 'context_override' );
		$property->setValue( $this->permission_manager, $value );
	}

	/**
	 * Sets the User-Agent and (optionally) the X-Safe-Publish-Action server
	 * variables for a simulated REST request. Pass null for $action to omit
	 * the header.
	 *
	 * @param string      $user_agent User-Agent header value.
	 * @param string|null $action     Action header value, or null to omit.
	 */
	private function set_request_headers( string $user_agent, ?string $action ): void {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		$_SERVER['HTTP_USER_AGENT'] = $user_agent;
		if ( null === $action ) {
			unset( $_SERVER['HTTP_X_SAFE_PUBLISH_ACTION'] );
			return;
		}
		$_SERVER['HTTP_X_SAFE_PUBLISH_ACTION'] = $action;
	}
}
