<?php
/**
 * Integration tests for auth-channel server-log routing.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\VIP_Safe_Auth;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Log_Events;
use WP_UnitTestCase;

/**
 * Auth Server Log Test.
 *
 * Verifies the auth channel's split server-log routing: reclassified 4xx
 * rejections record an error-level audit row without a server-log line, while
 * the two config-missing faults (HTTP 500) still emit one. Each test drives a
 * real Auth_Logger through an anonymous subclass that captures server-log
 * writes.
 */
class Auth_Server_Log_Test extends WP_UnitTestCase {

	/**
	 * Set up the audit log table and clear the auth channel.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'auth' );
	}

	/**
	 * Verifies that a rejected HMAC signature records an error-level audit row
	 * without emitting a server-log line.
	 */
	public function test_signature_invalid_skips_server_log(): void {
		// ARRANGE: An auth logger that captures any server-log write.
		$logger = new class() extends Auth_Logger {
			/**
			 * Server-log writes captured in place of error_log() calls.
			 *
			 * @var array<int, array>
			 */
			public array $server_log_writes = array();

			/**
			 * Captures the write instead of touching the server log.
			 *
			 * @param string $event    Event type.
			 * @param array  $skeleton PII-free server-log projection.
			 */
			#[\Override]
			protected function write_server_log(
				string $event,
				array $skeleton
			): void {
				$this->server_log_writes[] = array(
					'event'    => $event,
					'skeleton' => $skeleton,
				);
			}
		};

		// ACT: Log a rejected signature.
		$logger->signature_invalid(
			'/wp/v2/posts',
			'GET',
			1700000000,
			'https://source.example',
			0
		);

		// ASSERT: Nothing reached the server log.
		$this->assertCount( 0, $logger->server_log_writes );

		// ASSERT: The audit row persists at level error with its payload.
		$events = $this->events_for( Log_Events::SIGNATURE_INVALID );
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
		$this->assertSame( 0, $events[0]['data']['received_sig_length'] );
	}

	/**
	 * Verifies that an authenticated request with an unrecognized action
	 * records an error-level audit row without emitting a server-log line.
	 */
	public function test_unrecognized_action_skips_server_log(): void {
		// ARRANGE: An auth logger that captures any server-log write.
		$logger = new class() extends Auth_Logger {
			/**
			 * Server-log writes captured in place of error_log() calls.
			 *
			 * @var array<int, array>
			 */
			public array $server_log_writes = array();

			/**
			 * Captures the write instead of touching the server log.
			 *
			 * @param string $event    Event type.
			 * @param array  $skeleton PII-free server-log projection.
			 */
			#[\Override]
			protected function write_server_log(
				string $event,
				array $skeleton
			): void {
				$this->server_log_writes[] = array(
					'event'    => $event,
					'skeleton' => $skeleton,
				);
			}
		};

		// ACT: Log an unrecognized post-auth action.
		$logger->request_action_unrecognized(
			'/wp/v2/posts',
			'GET',
			'bogus_action',
			'https://source.example'
		);

		// ASSERT: Nothing reached the server log.
		$this->assertCount( 0, $logger->server_log_writes );

		// ASSERT: The audit row persists at level error with its payload.
		$events = $this->events_for( Log_Events::REQUEST_ACTION_UNRECOGNIZED );
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
		$this->assertSame( 'bogus_action', $events[0]['data']['received_action'] );
	}

	/**
	 * Verifies that a missing shared secret still emits a server-log line
	 * alongside its error-level audit row.
	 */
	public function test_secret_not_configured_emits_server_log(): void {
		// ARRANGE: An auth logger that captures any server-log write.
		$logger = new class() extends Auth_Logger {
			/**
			 * Server-log writes captured in place of error_log() calls.
			 *
			 * @var array<int, array>
			 */
			public array $server_log_writes = array();

			/**
			 * Captures the write instead of touching the server log.
			 *
			 * @param string $event    Event type.
			 * @param array  $skeleton PII-free server-log projection.
			 */
			#[\Override]
			protected function write_server_log(
				string $event,
				array $skeleton
			): void {
				$this->server_log_writes[] = array(
					'event'    => $event,
					'skeleton' => $skeleton,
				);
			}
		};

		// ACT: Log a request rejected because no secret is configured.
		$logger->secret_not_configured( '/wp/v2/posts', 'GET' );

		// ASSERT: One server-log line was emitted for this event.
		$this->assertCount( 1, $logger->server_log_writes );
		$this->assertSame(
			Log_Events::SECRET_NOT_CONFIGURED,
			$logger->server_log_writes[0]['event']
		);

		// ASSERT: The audit row persists at level error.
		$events = $this->events_for( Log_Events::SECRET_NOT_CONFIGURED );
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
	}

	/**
	 * Verifies that a missing connected site URL still emits a server-log line
	 * alongside its error-level audit row.
	 */
	public function test_connected_url_not_configured_emits_server_log(): void {
		// ARRANGE: An auth logger that captures any server-log write.
		$logger = new class() extends Auth_Logger {
			/**
			 * Server-log writes captured in place of error_log() calls.
			 *
			 * @var array<int, array>
			 */
			public array $server_log_writes = array();

			/**
			 * Captures the write instead of touching the server log.
			 *
			 * @param string $event    Event type.
			 * @param array  $skeleton PII-free server-log projection.
			 */
			#[\Override]
			protected function write_server_log(
				string $event,
				array $skeleton
			): void {
				$this->server_log_writes[] = array(
					'event'    => $event,
					'skeleton' => $skeleton,
				);
			}
		};

		// ACT: Log a request rejected because no connected URL is configured.
		$logger->connected_url_not_configured( '/wp/v2/posts', 'GET' );

		// ASSERT: One server-log line was emitted for this event.
		$this->assertCount( 1, $logger->server_log_writes );
		$this->assertSame(
			Log_Events::CONNECTED_URL_NOT_CONFIGURED,
			$logger->server_log_writes[0]['event']
		);

		// ASSERT: The audit row persists at level error.
		$events = $this->events_for( Log_Events::CONNECTED_URL_NOT_CONFIGURED );
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
	}

	/**
	 * Verifies that a non-authorized connection probe records an error-level
	 * audit row without emitting a server-log line.
	 */
	public function test_connection_probe_failed_skips_server_log(): void {
		// ARRANGE: An auth logger that captures any server-log write.
		$logger = new class() extends Auth_Logger {
			/**
			 * Server-log writes captured in place of error_log() calls.
			 *
			 * @var array<int, array>
			 */
			public array $server_log_writes = array();

			/**
			 * Captures the write instead of touching the server log.
			 *
			 * @param string $event    Event type.
			 * @param array  $skeleton PII-free server-log projection.
			 */
			#[\Override]
			protected function write_server_log(
				string $event,
				array $skeleton
			): void {
				$this->server_log_writes[] = array(
					'event'    => $event,
					'skeleton' => $skeleton,
				);
			}
		};

		// ACT: Log a rejected connection probe.
		$logger->connection_probe_failed(
			VIP_Safe_Auth::STATUS_UNAUTHORIZED,
			401
		);

		// ASSERT: Nothing reached the server log.
		$this->assertCount( 0, $logger->server_log_writes );

		// ASSERT: The audit row persists at level error with its payload.
		$events = $this->events_for( Log_Events::CONNECTION_PROBE_FAILED );
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
		$this->assertSame(
			VIP_Safe_Auth::STATUS_UNAUTHORIZED,
			$events[0]['data']['probe_status']
		);
		$this->assertSame( 401, $events[0]['data']['code'] );
	}

	/**
	 * Returns the auth-channel audit rows for an event type.
	 *
	 * @param string $event_type Event type to query.
	 * @return array Matching audit rows.
	 */
	private function events_for( string $event_type ): array {
		return Audit_Log_Table::get_events(
			array(
				'channel'    => 'auth',
				'event_type' => $event_type,
			)
		);
	}
}
