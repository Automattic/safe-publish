<?php
/**
 * Test-only logger subclass that exposes the base log methods.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Logger;

/**
 * Lets integration tests fire events at any level on a chosen channel and
 * capture the server-log skeleton in place of a real error_log() write, so
 * they can exercise the base Logger's routing, projection, and
 * actor-attribution without coupling to a specific event helper.
 */
final class Test_Logger extends Logger {

	/**
	 * Server-log skeletons captured in place of real error_log() writes.
	 *
	 * @var array<int, array{event: string, skeleton: array}>
	 */
	public array $server_log_writes = array();

	/**
	 * Constructs the Test_Logger instance.
	 *
	 * @param string $channel Channel identifier (e.g. 'auth', 'media').
	 */
	public function __construct( string $channel ) {
		$this->channel = $channel;
	}

	/**
	 * Fires an info-level event with the given name and data.
	 *
	 * @param string $event Event name (typically a TEST_* sentinel).
	 * @param array  $data  Optional. Event data. Default empty array.
	 */
	public function fire_event( string $event, array $data = array() ): void {
		$this->log_event( $event, $data );
	}

	/**
	 * Fires a warning-level event with the given name and data.
	 *
	 * @param string $event Event name (typically a TEST_* sentinel).
	 * @param array  $data  Optional. Event data. Default empty array.
	 */
	public function fire_warning( string $event, array $data = array() ): void {
		$this->log_warning( $event, $data );
	}

	/**
	 * Fires a domain-failure event with the given name and data.
	 *
	 * @param string $event Event name (typically a TEST_* sentinel).
	 * @param array  $data  Optional. Event data. Default empty array.
	 */
	public function fire_failure( string $event, array $data = array() ): void {
		$this->log_failure( $event, $data );
	}

	/**
	 * Fires an error-level event with the given name and data.
	 *
	 * @param string $event Event name (typically a TEST_* sentinel).
	 * @param array  $data  Optional. Event data. Default empty array.
	 */
	public function fire_error( string $event, array $data = array() ): void {
		$this->log_error( $event, $data );
	}

	/**
	 * Captures the server-log skeleton instead of writing to error_log(), so
	 * tests can assert what would reach the server log.
	 *
	 * @param string $event    Event type.
	 * @param array  $skeleton PII-free projection built for the server log.
	 */
	#[\Override]
	protected function write_server_log( string $event, array $skeleton ): void {
		$this->server_log_writes[] = array(
			'event'    => $event,
			'skeleton' => $skeleton,
		);
	}
}
