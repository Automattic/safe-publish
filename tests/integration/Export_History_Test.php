<?php
/**
 * Integration tests for Export History (audit log) timezone handling.
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
}
