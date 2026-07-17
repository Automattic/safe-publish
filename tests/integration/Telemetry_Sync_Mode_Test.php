<?php
/**
 * Telemetry sync-mode integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Sync_Mode_Telemetry;
use Safe_Publish\Utils\Telemetry_Event_Queue;
use Safe_Publish\Utils\Telemetry_Events;
use Safe_Publish\Utils\Telemetry_Service;
use WP_UnitTestCase;

/**
 * Telemetry Sync Mode Test.
 *
 * Verifies that saving or changing the sync mode emits sync_mode_configured
 * with bounded previous/new modes and the first-configuration flag, and that
 * no-op saves stay silent.
 */
class Telemetry_Sync_Mode_Test extends WP_UnitTestCase {

	/**
	 * Queue that captures every telemetry event emitted by the bridge.
	 *
	 * @var Telemetry_Event_Queue
	 */
	private Telemetry_Event_Queue $queue;

	/**
	 * Registers a queue-backed sync-mode telemetry bridge against a clean
	 * sync-mode option.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		delete_option( Options::OPTION_SYNC_MODE );

		// Deterministic isolation: strip any handlers the full-plugin bootstrap
		// registered on these hooks so the queue-backed bridge is the only one.
		remove_all_actions( 'add_option_' . Options::OPTION_SYNC_MODE );
		remove_all_actions( 'update_option_' . Options::OPTION_SYNC_MODE );

		$this->queue = new Telemetry_Event_Queue();
		$telemetry   = new Telemetry_Service( array(), $this->queue );

		( new Sync_Mode_Telemetry( $telemetry ) )->register_handlers();
	}

	/**
	 * Removes the sync-mode option so it can't leak into other tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		delete_option( Options::OPTION_SYNC_MODE );
		parent::tearDown();
	}

	/**
	 * Verifies that the first-ever sync-mode save reports the unconfigured
	 * previous mode and flags the first configuration.
	 */
	public function test_first_configuration_reports_unconfigured_previous(): void {
		// ARRANGE: a fresh install with no sync-mode row (setUp deleted it).

		// ACT: the operator picks import mode for the first time.
		add_option( Options::OPTION_SYNC_MODE, 'import' );

		// ASSERT: one event, previous mode is the bounded unconfigured fallback,
		// new mode is import, and the first-configuration flag is set.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			Telemetry_Events::SYNC_MODE_CONFIGURED,
			$events[0]['event']
		);
		$this->assertSame(
			Telemetry_Events::SYNC_MODE_UNCONFIGURED,
			$events[0]['properties']['previous_mode']
		);
		$this->assertSame( 'import', $events[0]['properties']['new_mode'] );
		$this->assertTrue( $events[0]['properties']['is_first_configuration'] );
	}

	/**
	 * Verifies that a later mode switch reports both the previous and new
	 * modes and clears the first-configuration flag.
	 */
	public function test_mode_switch_reports_previous_and_new(): void {
		// ARRANGE: an already-configured import site.
		add_option( Options::OPTION_SYNC_MODE, 'import' );
		$this->queue->clear();

		// ACT: the operator switches to bidirectional.
		update_option( Options::OPTION_SYNC_MODE, 'bidirectional' );

		// ASSERT: one event carrying the transition, not a first configuration.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'import', $events[0]['properties']['previous_mode'] );
		$this->assertSame(
			'bidirectional',
			$events[0]['properties']['new_mode']
		);
		$this->assertFalse( $events[0]['properties']['is_first_configuration'] );
	}

	/**
	 * Verifies that re-saving the same mode emits nothing, since WordPress
	 * fires no update_option hook when the value is unchanged.
	 */
	public function test_noop_save_emits_nothing(): void {
		// ARRANGE: an already-configured import site.
		add_option( Options::OPTION_SYNC_MODE, 'import' );
		$this->queue->clear();

		// ACT: save the identical mode again.
		update_option( Options::OPTION_SYNC_MODE, 'import' );

		// ASSERT: no event, so the funnel isn't polluted by no-op saves.
		$this->assertCount( 0, $this->queue->events() );
	}
}
