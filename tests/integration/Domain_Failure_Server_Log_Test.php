<?php
/**
 * Integration tests for domain-failure server-log suppression.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Content_Logger;
use Safe_Publish\API\Dispatch_Logger;
use Safe_Publish\API\Export_Logger;
use Safe_Publish\Media\Media_Logger;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Log_Events;

/**
 * Domain Failure Server Log Test.
 *
 * Each test drives a real channel logger through an anonymous subclass that
 * captures server-log writes, asserting the reclassified helper records an
 * error-level audit row while writing nothing to the server log.
 */
class Domain_Failure_Server_Log_Test extends Integration_Test_Case {

	private const CHANNELS = array( 'media', 'content', 'dispatch', 'export' );

	/**
	 * Set up the audit log table and clear the channels under test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		foreach ( self::CHANNELS as $channel ) {
			Audit_Log_Table::clear( $channel );
		}
	}

	/**
	 * Verifies that a media domain failure records an error-level audit row
	 * without emitting a server-log line.
	 */
	public function test_media_failure_skips_server_log(): void {
		// ARRANGE: A media logger that captures any server-log write.
		$logger = new class() extends Media_Logger {
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

		// ACT: Log a media download failure.
		$logger->media_download_failed(
			'https://source.example/uploads/photo.jpg',
			'https://source.example',
			'Connection timed out'
		);

		// ASSERT: Nothing reached the server log.
		$this->assertCount( 0, $logger->server_log_writes );

		// ASSERT: The audit row persists at level error with its payload.
		$events = $this->events_for( 'media', Log_Events::MEDIA_DOWNLOAD_FAILED );
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
		$this->assertSame( 'Connection timed out', $events[0]['data']['error'] );
	}

	/**
	 * Verifies that a content domain failure records an error-level audit row
	 * without emitting a server-log line.
	 */
	public function test_content_failure_skips_server_log(): void {
		// ARRANGE: A content logger that captures any server-log write.
		$logger = new class() extends Content_Logger {
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

		// ACT: Log a content fetch failure.
		$logger->content_fetch_failed( 42, 'https://source.example', 'HTTP 500' );

		// ASSERT: Nothing reached the server log.
		$this->assertCount( 0, $logger->server_log_writes );

		// ASSERT: The audit row persists at level error with its payload.
		$events = $this->events_for( 'content', Log_Events::CONTENT_FETCH_FAILED );
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
		$this->assertSame( 42, $events[0]['data']['source_post_id'] );
	}

	/**
	 * Verifies that a dispatch domain failure records an error-level audit row
	 * without emitting a server-log line.
	 */
	public function test_dispatch_failure_skips_server_log(): void {
		// ARRANGE: A dispatch logger that captures any server-log write.
		$logger = new class() extends Dispatch_Logger {
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

		// ACT: Log a non-export dispatch that returned a WP_Error.
		$logger->dispatch_request_error(
			'/wp/v2/posts',
			'list',
			'https://destination.example',
			'rest_no_route',
			'No route was found matching the URL and request method.'
		);

		// ASSERT: Nothing reached the server log.
		$this->assertCount( 0, $logger->server_log_writes );

		// ASSERT: The audit row persists at level error with its payload.
		$events = $this->events_for( 'dispatch', Log_Events::DISPATCH_REQUEST_ERROR );
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
		$this->assertSame( 'rest_no_route', $events[0]['data']['error_code'] );
	}

	/**
	 * Verifies that an export domain failure records an error-level audit row
	 * without emitting a server-log line.
	 */
	public function test_export_failure_skips_server_log(): void {
		// ARRANGE: An export logger that captures any server-log write.
		$logger = new class() extends Export_Logger {
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

		// ACT: Log an export request that returned a WP_Error.
		$logger->export_request_error(
			'/wp/v2/posts/99',
			'https://destination.example',
			'rest_post_invalid_id',
			'Invalid post ID.'
		);

		// ASSERT: Nothing reached the server log.
		$this->assertCount( 0, $logger->server_log_writes );

		// ASSERT: The audit row persists at level error with its payload.
		$events = $this->events_for( 'export', Log_Events::EXPORT_REQUEST_ERROR );
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
		$this->assertSame( 'rest_post_invalid_id', $events[0]['data']['error_code'] );
	}

	/**
	 * Returns the audit rows for an event type on a channel.
	 *
	 * @param string $channel    Channel to query.
	 * @param string $event_type Event type to query.
	 * @return array Matching audit rows.
	 */
	private function events_for( string $channel, string $event_type ): array {
		return Audit_Log_Table::get_events(
			array(
				'channel'    => $channel,
				'event_type' => $event_type,
			)
		);
	}
}
