<?php
/**
 * Integration tests for the Export History audit log AJAX endpoint.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Export_Logger;
use Safe_Publish\Tests\Integration\Ajax_Die_Continue_Trait;
use Safe_Publish\Utils\Audit_Log_Table;
use WP_Ajax_UnitTestCase;

/**
 * Export History Test Class.
 *
 * Cross-system contract: timestamps are stored as GMT and emitted as ISO
 * 8601 with an explicit Z marker so the React display can render them in
 * browser-local time.
 */
class Export_History_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Sets up the audit log table and an admin user for AJAX requests.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'export' );

		$admin_id = $this->factory()->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $admin_id );
	}

	/**
	 * Verifies that audit log timestamps are written as GMT regardless of
	 * the site's configured timezone, so the API contract stays
	 * browser-friendly.
	 */
	public function test_audit_log_timestamps_are_stored_in_gmt(): void {
		// ARRANGE: Configure the site to a non-UTC timezone.
		update_option( 'timezone_string', 'America/New_York' );
		$now = time();

		// ACT: Log an export event via Export_Logger.
		( new Export_Logger() )->content_exported( 'posts', 'https://destination.example/', array() );

		// ASSERT: The created_at_gmt column parses as GMT — i.e. it is NOT
		// shifted by the site's UTC offset.
		$events = Audit_Log_Table::get_events(
			array(
				'channel' => 'export',
				'limit'   => 1,
			)
		);
		$this->assertCount( 1, $events );

		$stored = strtotime( $events[0]['created_at_gmt'] . ' UTC' );
		$this->assertEqualsWithDelta( $now, $stored, 5 );

		// CLEANUP.
		delete_option( 'timezone_string' );
	}

	/**
	 * Verifies that the export events AJAX response emits dates as ISO 8601
	 * with an explicit Z marker so JS parses them in browser-local time.
	 */
	public function test_ajax_get_export_events_response_date_is_iso_utc(): void {
		// ARRANGE: Log one export event so the endpoint has a row to return.
		( new Export_Logger() )->content_exported( 'posts', 'https://destination.example/', array() );

		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
		);

		// ACT: Trigger the export events AJAX handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_export_events' );

		// ASSERT: Response contains a date matching the ISO 8601 UTC format.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $response['data'] );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$response['data'][0]['date']
		);
	}

	/**
	 * Verifies that the export events AJAX response surfaces the acting user's
	 * ID, snapshotted display name, and invocation context so the UI can render
	 * a User column for user-triggered exports.
	 */
	public function test_ajax_get_export_events_response_includes_actor_fields(): void {
		// ARRANGE: Act as a known admin user and log one export event.
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Export Triggerer',
			)
		);
		wp_set_current_user( $user_id );

		( new Export_Logger() )->content_exported(
			'posts',
			'https://destination.example/',
			array()
		);

		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
		);

		// ACT: Trigger the export events AJAX handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_export_events' );

		// ASSERT: Response carries the captured actor identity. The source
		// resolves to 'ajax' because the test runs under WP_Ajax_UnitTestCase.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $response['data'] );
		$this->assertSame( $user_id, $response['data'][0]['actor_user_id'] );
		$this->assertSame(
			'Export Triggerer',
			$response['data'][0]['actor_display_name']
		);
		$this->assertSame( 'ajax', $response['data'][0]['actor_source'] );
	}

	/**
	 * Verifies that the export events AJAX response surfaces actor_source for
	 * system-triggered events so the UI can render "System (<source>)" instead
	 * of a blank cell when no WordPress user is acting.
	 */
	public function test_ajax_get_export_events_response_surfaces_system_actor_source(): void {
		// ARRANGE: Insert an HMAC-triggered audit row directly. This bypasses
		// Logger detection (covered by Audit_Log_Actor_Attribution_Test) and
		// isolates the AJAX surfacing behavior.
		Audit_Log_Table::insert(
			'export',
			'info',
			'CONTENT_EXPORTED',
			gmdate( 'Y-m-d H:i:s' ),
			array(
				'actor_user_id'        => 0,
				'actor_display_name'   => '',
				'actor_source'         => 'hmac',
				'destination_site_url' => 'https://destination.example/',
				'post_ids'             => array(),
				'post_count'           => 0,
			)
		);

		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
		);

		// ACT: Trigger the export events AJAX handler.
		$this->dispatch_ajax_expecting_die( 'safe_publish_get_export_events' );

		// ASSERT: System-event fields are passed through verbatim.
		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $response['data'] );
		$this->assertSame( 0, $response['data'][0]['actor_user_id'] );
		$this->assertSame( '', $response['data'][0]['actor_display_name'] );
		$this->assertSame( 'hmac', $response['data'][0]['actor_source'] );
	}
}
