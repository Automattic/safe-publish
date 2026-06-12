<?php
/**
 * Telemetry Service Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Utils\Telemetry_Event_Queue;
use Safe_Publish\Utils\Telemetry_Service;

/**
 * Telemetry Service Test.
 *
 * Verifies the wrapper around the VIP mu-plugins telemetry client. The
 * queue path is the primary contract because integration tests rely on
 * it; the no-client path is verified by absence of error (the VIP class
 * is not loaded in unit tests).
 */
class TelemetryServiceTest extends TestCase {

	/**
	 * Verifies that an injected queue captures the un-prefixed event name
	 * and properties exactly as passed.
	 */
	public function test_record_event_pushes_to_injected_queue(): void {
		// ARRANGE: a fresh queue and a service prefixed with safe_publish_.
		$queue   = new Telemetry_Event_Queue();
		$service = new Telemetry_Service(
			array( 'plugin_version' => '0.0.4' ),
			$queue
		);

		// ACT: record a single event with properties.
		$service->record_event(
			'bulk_import_completed',
			array(
				'batch_size' => 12,
				'successful' => 11,
				'failed'     => 1,
			)
		);

		// ASSERT: the queue captured the event name without the prefix and
		// the exact properties array.
		$events = $queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'bulk_import_completed', $events[0]['event'] );
		$this->assertSame(
			array(
				'batch_size' => 12,
				'successful' => 11,
				'failed'     => 1,
			),
			$events[0]['properties']
		);
	}

	/**
	 * Verifies that multiple events queue in insertion order.
	 */
	public function test_queue_preserves_insertion_order(): void {
		// ARRANGE: a service with a queue.
		$queue   = new Telemetry_Event_Queue();
		$service = new Telemetry_Service( array(), $queue );

		// ACT: record three events.
		$service->record_event( 'first' );
		$service->record_event( 'second' );
		$service->record_event( 'third' );

		// ASSERT: events appear in the order they were recorded.
		$events = $queue->events();
		$this->assertCount( 3, $events );
		$this->assertSame( 'first', $events[0]['event'] );
		$this->assertSame( 'second', $events[1]['event'] );
		$this->assertSame( 'third', $events[2]['event'] );
	}

	/**
	 * Verifies that the service silently no-ops when neither a queue is
	 * injected nor the VIP telemetry class is loaded. Local dev and unit
	 * tests hit this path.
	 */
	public function test_record_event_no_ops_without_queue_or_vip_class(): void {
		// ARRANGE: a service with no queue. The VIP class is not loaded in
		// unit tests, so the wrapper takes the no-op branch.
		$service = new Telemetry_Service();

		// ACT: record an event that has nowhere to go.
		$service->record_event( 'bulk_import_completed', array( 'batch_size' => 1 ) );

		// ASSERT: no exception was thrown.
		$this->assertTrue( true );
	}

	/**
	 * Verifies that clear() empties the queue without affecting the
	 * service's state.
	 */
	public function test_queue_clear_empties_recorded_events(): void {
		// ARRANGE: a queue with a recorded event.
		$queue   = new Telemetry_Event_Queue();
		$service = new Telemetry_Service( array(), $queue );
		$service->record_event( 'first' );

		// ACT: clear the queue and record another event.
		$queue->clear();
		$service->record_event( 'second' );

		// ASSERT: only the post-clear event remains.
		$events = $queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'second', $events[0]['event'] );
	}
}
