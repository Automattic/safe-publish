<?php
/**
 * Integration tests for the Logger server-log axis and PII-free skeleton.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Audit_Log_Table;

/**
 * Logger Server Log Test.
 */
class Logger_Server_Log_Test extends Integration_Test_Case {

	private const CHANNEL = 'auth';

	/**
	 * Set up the audit log table and clear the channel under test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( self::CHANNEL );
	}

	/**
	 * Verifies that a log_error event emits a PII-free server-log skeleton
	 * while the audit row keeps the full payload at level error.
	 */
	public function test_log_error_emits_skeleton_and_full_audit_row(): void {
		// ARRANGE: Act as a named user so a display name is captured.
		$user_id = self::factory()->user->create(
			array( 'display_name' => 'Sensitive Name' )
		);
		wp_set_current_user( $user_id );
		$logger = new Test_Logger( self::CHANNEL );

		// ACT: Log a fault carrying allowlisted codes plus PII and free text.
		$logger->fire_error(
			'TEST_ERROR',
			array(
				'session_id'      => 'sess-123',
				'source_post_id'  => 42,
				'error_code'      => 'SECRET_NOT_CONFIGURED',
				'status'          => 500,
				'message'         => 'user@example.com not found',
				'source_site_url' => 'https://source.example',
			)
		);

		// ASSERT: Exactly one server-log line was emitted.
		$this->assertCount( 1, $logger->server_log_writes );
		$skeleton = $logger->server_log_writes[0]['skeleton'];

		// ASSERT: Skeleton carries the allowlisted forensic and context fields.
		$this->assertSame( 'TEST_ERROR', $skeleton['event'] );
		$this->assertSame( self::CHANNEL, $skeleton['channel'] );
		$this->assertSame( 'error', $skeleton['level'] );
		$this->assertSame( $user_id, $skeleton['actor_user_id'] );
		$this->assertSame( 'sess-123', $skeleton['session_id'] );
		$this->assertSame( 42, $skeleton['source_post_id'] );
		$this->assertSame( 'SECRET_NOT_CONFIGURED', $skeleton['error_code'] );
		$this->assertSame( 500, $skeleton['status'] );
		$this->assertArrayHasKey( 'timestamp', $skeleton );
		$this->assertArrayHasKey( 'actor_source', $skeleton );

		// ASSERT: No PII or free-text field leaks into the skeleton.
		$this->assertArrayNotHasKey( 'message', $skeleton );
		$this->assertArrayNotHasKey( 'source_site_url', $skeleton );
		$this->assertArrayNotHasKey( 'actor_display_name', $skeleton );
		$this->assertArrayNotHasKey( 'site_url', $skeleton );
		$this->assertArrayNotHasKey( 'request_uri', $skeleton );
		$this->assertArrayNotHasKey( 'user_agent', $skeleton );

		// ASSERT: The audit row stores the full payload at level error.
		$events = $this->events_for( 'TEST_ERROR' );
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
		$data = $events[0]['data'];
		$this->assertSame( 'Sensitive Name', $data['actor_display_name'] );
		$this->assertSame( 'user@example.com not found', $data['message'] );
		$this->assertSame( 'https://source.example', $data['source_site_url'] );
	}

	/**
	 * Verifies that a log_failure event stores an error-level audit row and
	 * writes nothing to the server log.
	 */
	public function test_log_failure_records_error_row_and_skips_server_log(): void {
		// ARRANGE: A logger on the channel under test.
		$logger = new Test_Logger( self::CHANNEL );

		// ACT: Log an expected domain failure.
		$logger->fire_failure(
			'TEST_FAILURE',
			array( 'action' => 'request_rejected' )
		);

		// ASSERT: Nothing reached the server log.
		$this->assertCount( 0, $logger->server_log_writes );

		// ASSERT: The audit row persists at level error with its payload.
		$events = $this->events_for( 'TEST_FAILURE' );
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
		$this->assertSame( 'request_rejected', $events[0]['data']['action'] );
	}

	/**
	 * Verifies that log_warning and log_event never write to the server log.
	 */
	public function test_warning_and_info_skip_server_log(): void {
		// ARRANGE: A logger on the channel under test.
		$logger = new Test_Logger( self::CHANNEL );

		// ACT: Fire a warning and an info event.
		$logger->fire_warning( 'TEST_WARNING' );
		$logger->fire_event( 'TEST_INFO' );

		// ASSERT: Neither wrote to the server log.
		$this->assertCount( 0, $logger->server_log_writes );

		// ASSERT: Both rows persist at their respective levels.
		$warning = $this->events_for( 'TEST_WARNING' );
		$info    = $this->events_for( 'TEST_INFO' );
		$this->assertCount( 1, $warning );
		$this->assertSame( 'warning', $warning[0]['level'] );
		$this->assertCount( 1, $info );
		$this->assertSame( 'info', $info[0]['level'] );
	}

	/**
	 * Verifies that a non-scalar value under an allowlisted key is dropped
	 * from the server-log skeleton.
	 */
	public function test_skeleton_omits_non_scalar_allowlisted_value(): void {
		// ARRANGE: A logger on the channel under test.
		$logger = new Test_Logger( self::CHANNEL );

		// ACT: Log an error whose allowlisted 'status' key holds an array.
		$logger->fire_error(
			'TEST_NON_SCALAR',
			array( 'status' => array( 'nested' => 'secret' ) )
		);

		// ASSERT: The non-scalar value never reaches the skeleton.
		$this->assertCount( 1, $logger->server_log_writes );
		$this->assertArrayNotHasKey(
			'status',
			$logger->server_log_writes[0]['skeleton']
		);
	}

	/**
	 * Verifies that caller data cannot spoof the skeleton's channel, level, or
	 * actor_source.
	 */
	public function test_skeleton_forensic_fields_resist_spoofing(): void {
		// ARRANGE: A logger on the channel under test.
		$logger = new Test_Logger( self::CHANNEL );

		// ACT: Fire an error whose data tries to spoof the trusted fields.
		$logger->fire_error(
			'TEST_SPOOF',
			array(
				'channel'      => 'spoofed',
				'level'        => 'info',
				'actor_source' => 'spoofed',
			)
		);

		// ASSERT: The skeleton keeps the trusted values, not the caller's.
		$skeleton = $logger->server_log_writes[0]['skeleton'];
		$this->assertSame( self::CHANNEL, $skeleton['channel'] );
		$this->assertSame( 'error', $skeleton['level'] );
		$this->assertNotSame( 'spoofed', $skeleton['actor_source'] );
	}

	/**
	 * Returns the audit rows for an event type on the channel under test.
	 *
	 * @param string $event_type Event type to query.
	 * @return array Matching audit rows.
	 */
	private function events_for( string $event_type ): array {
		return Audit_Log_Table::get_events(
			array(
				'channel'    => self::CHANNEL,
				'event_type' => $event_type,
			)
		);
	}
}
