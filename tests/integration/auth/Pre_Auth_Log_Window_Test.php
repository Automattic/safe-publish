<?php
/**
 * Integration tests for the pre-authentication log window
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Log_Events;
use WP_UnitTestCase;

/**
 * Pre Auth Log Window Test.
 *
 * Verifies that failures raised before a request authenticates record one row
 * per failure type per window, keyed on the failure type alone so a caller
 * cannot open a fresh window by varying the request.
 */
class Pre_Auth_Log_Window_Test extends WP_UnitTestCase {

	/**
	 * Transient key prefix guarding the pre-authentication log window.
	 */
	private const KEY_PREFIX = 'safe_publish_auth_fail_';

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
	 * Verifies that repeating one failure type records a single row for the
	 * window.
	 */
	public function test_repeated_failure_records_one_row(): void {
		// ARRANGE: A logger writing to the auth channel.
		$logger = new Auth_Logger();

		// ACT: Reject ten identical requests.
		for ( $i = 0; $i < 10; $i++ ) {
			$logger->timestamp_expired( '/wp/v2/posts', 'GET', 1, 2, 1, 300 );
		}

		// ASSERT: One row covers the window.
		$this->assertCount(
			1,
			$this->events_for( Log_Events::TIMESTAMP_EXPIRED )
		);
	}

	/**
	 * Verifies that varying the route and method does not open a fresh window
	 * for the same failure type.
	 */
	public function test_varying_request_does_not_open_a_window(): void {
		// ARRANGE: A logger writing to the auth channel.
		$logger = new Auth_Logger();

		// ACT: Reject three requests differing only in route and method.
		$logger->site_url_header_missing( '/wp/v2/posts', 'GET' );
		$logger->site_url_header_missing( '/wp/v2/pages', 'HEAD' );
		$logger->site_url_header_missing( '/safe-publish/v1/catalog', 'POST' );

		// ASSERT: The window still holds one row.
		$this->assertCount(
			1,
			$this->events_for( Log_Events::SITE_URL_HEADER_MISSING )
		);
	}

	/**
	 * Verifies that each failure type keeps its own window.
	 */
	public function test_distinct_failure_types_each_record_a_row(): void {
		// ARRANGE: A logger writing to the auth channel.
		$logger = new Auth_Logger();

		// ACT: Reject two requests that fail different checks.
		$logger->timestamp_expired( '/wp/v2/posts', 'GET', 1, 2, 1, 300 );
		$logger->content_hash_missing( '/wp/v2/posts', 'GET' );

		// ASSERT: Both failures stay in the trail.
		$this->assertCount(
			1,
			$this->events_for( Log_Events::TIMESTAMP_EXPIRED )
		);
		$this->assertCount(
			1,
			$this->events_for( Log_Events::CONTENT_HASH_MISSING )
		);
	}

	/**
	 * Verifies that a repeated configuration fault reaches the server log once
	 * per window.
	 */
	public function test_repeated_config_fault_writes_one_server_log(): void {
		// ARRANGE: A logger capturing server-log writes.
		$logger = new class() extends Auth_Logger {
			/**
			 * Server-log writes captured in place of error_log() calls.
			 *
			 * @var array<int, string>
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
				$this->server_log_writes[] = $event;
			}
		};

		// ACT: Reject five requests at the missing-secret check.
		for ( $i = 0; $i < 5; $i++ ) {
			$logger->secret_not_configured( '/wp/v2/posts', 'GET' );
		}

		// ASSERT: One server-log line and one audit row cover the window.
		$this->assertSame(
			array( Log_Events::SECRET_NOT_CONFIGURED ),
			$logger->server_log_writes
		);
		$this->assertCount(
			1,
			$this->events_for( Log_Events::SECRET_NOT_CONFIGURED )
		);
	}

	/**
	 * Verifies that oversized caller-set payload strings are stored capped.
	 */
	public function test_oversized_payload_strings_are_capped(): void {
		// ARRANGE: A route and an origin far past the payload limit.
		$logger = new Auth_Logger();
		$route  = '/wp/v2/' . str_repeat( 'a', 4096 );
		$origin = 'https://' . str_repeat( 'b', 4096 );

		// ACT: Reject one request carrying both.
		$logger->site_url_mismatch(
			$route,
			'GET',
			$origin,
			'https://destination.example'
		);

		// ASSERT: Each keeps its leading 256 bytes and no more.
		$data = $this->events_for( Log_Events::SITE_URL_MISMATCH )[0]['data'];
		$this->assertSame(
			'/wp/v2/' . str_repeat( 'a', 249 ),
			$data['route']
		);
		$this->assertSame(
			'https://' . str_repeat( 'b', 248 ),
			$data['request_site_url']
		);
	}

	/**
	 * Verifies that a window carries an expiry instead of suppressing the
	 * failure type for good.
	 */
	public function test_window_carries_its_configured_expiry(): void {
		// ARRANGE: A logger writing to the auth channel.
		$logger = new Auth_Logger();
		$before = time();

		// ACT: Reject one request.
		$logger->timestamp_expired( '/wp/v2/posts', 'GET', 1, 2, 1, 300 );

		// ASSERT: The window expires five minutes out.
		$key     = self::KEY_PREFIX . Log_Events::TIMESTAMP_EXPIRED;
		$expires = (int) get_option( '_transient_timeout_' . $key );
		$this->assertGreaterThanOrEqual( $before + 300, $expires );
		$this->assertLessThanOrEqual( time() + 300, $expires );
	}

	/**
	 * Reads the auth-channel rows recorded for one event type.
	 *
	 * @param string $event_type Event type to match.
	 * @return array Audit rows.
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
