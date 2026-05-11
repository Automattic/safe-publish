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
 * Lets integration tests fire arbitrary event names on a chosen channel so
 * they can exercise the base Logger's actor-attribution and reserved-key
 * behavior without coupling to a specific event helper.
 */
final class Test_Logger extends Logger {

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
}
