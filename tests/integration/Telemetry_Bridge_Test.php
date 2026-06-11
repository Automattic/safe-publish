<?php
/**
 * Telemetry Bridge integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Utils\Telemetry_Bridge;
use Safe_Publish\Utils\Telemetry_Event_Queue;
use Safe_Publish\Utils\Telemetry_Events;
use Safe_Publish\Utils\Telemetry_Service;
use WP_UnitTestCase;

/**
 * Telemetry Bridge Test.
 *
 * Verifies that auth-channel failure events trigger an inbound_auth_failed
 * telemetry event with the mapped `reason` enum, and that success / unmapped
 * events are silently ignored.
 */
class Telemetry_Bridge_Test extends WP_UnitTestCase {

	/**
	 * Bridge under test. Registered against the global event hook for the
	 * duration of each test.
	 *
	 * @var Telemetry_Bridge
	 */
	private Telemetry_Bridge $bridge;

	/**
	 * Queue that captures every event the bridge forwards.
	 *
	 * @var Telemetry_Event_Queue
	 */
	private Telemetry_Event_Queue $queue;

	/**
	 * Auth_Logger instance used to fire the audit-log action that the
	 * bridge listens to.
	 *
	 * @var Auth_Logger
	 */
	private Auth_Logger $auth_logger;

	/**
	 * Registers a fresh bridge + queue before each test so events don't
	 * leak across tests.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->queue       = new Telemetry_Event_Queue();
		$telemetry         = new Telemetry_Service( array(), $this->queue );
		$this->bridge      = new Telemetry_Bridge( $telemetry );
		$this->auth_logger = new Auth_Logger();

		$this->bridge->register();
	}

	/**
	 * Removes the bridge hook so tests don't pollute each other.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_action(
			'safe_publish_event_logged',
			array( $this->bridge, 'on_event_logged' ),
			10
		);

		parent::tearDown();
	}

	/**
	 * Verifies the full mapping table: every covered auth failure event
	 * maps to the corresponding reason enum.
	 *
	 * @dataProvider provide_auth_failures
	 *
	 * @param callable(Auth_Logger): void $fire           Closure that fires
	 *                                                    the matching
	 *                                                    Auth_Logger helper.
	 * @param string                      $expected_reason Reason enum value
	 *                                                    expected in the
	 *                                                    queued event.
	 */
	public function test_auth_failure_mapping(
		callable $fire,
		string $expected_reason
	): void {
		// ARRANGE: bridge registered.

		// ACT: fire the auth-channel failure via the data-provided closure.
		$fire( $this->auth_logger );

		// ASSERT: queue captured the event with the expected reason.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame( Telemetry_Events::INBOUND_AUTH_FAILED, $events[0]['event'] );
		$this->assertSame( $expected_reason, $events[0]['properties']['reason'] );
	}

	/**
	 * Verifies that the success auth event is intentionally excluded —
	 * REQUEST_AUTHENTICATED is too high-volume to be useful as telemetry.
	 */
	public function test_request_authenticated_does_not_fire_telemetry(): void {
		// ARRANGE: bridge registered.

		// ACT: fire the success event that should be ignored.
		$this->auth_logger->request_authenticated(
			'/safe-publish/v1/test',
			'GET',
			1000,
			'https://source.example.com',
			'preview'
		);

		// ASSERT: no telemetry recorded.
		$this->assertSame( array(), $this->queue->events() );
	}

	/**
	 * Verifies that a non-auth channel passes through the bridge without
	 * triggering telemetry — the bridge only cares about auth events.
	 */
	public function test_non_auth_channel_does_not_fire_telemetry(): void {
		// ARRANGE: bridge registered.

		// ACT: fire the audit-log action directly on a non-auth channel.
		do_action( 'safe_publish_event_logged', 'settings', 'SYNC_MODE_CHANGED', array() );

		// ASSERT: no telemetry recorded.
		$this->assertSame( array(), $this->queue->events() );
	}

	/**
	 * Provides every covered auth failure and its mapped reason enum so
	 * the full table can be verified with one parameterized test.
	 *
	 * @return array<string, array{0: callable(Auth_Logger): void, 1: string}>
	 */
	public static function provide_auth_failures(): array {
		return array(
			'secret_not_configured'        => array(
				static function ( Auth_Logger $logger ): void {
					$logger->secret_not_configured( '/r', 'GET' );
				},
				'secret_not_configured',
			),
			'timestamp_expired'            => array(
				static function ( Auth_Logger $logger ): void {
					$logger->timestamp_expired( '/r', 'GET', 0, 0, 0, 0 );
				},
				'timestamp_expired',
			),
			'hash_missing'                 => array(
				static function ( Auth_Logger $logger ): void {
					$logger->content_hash_missing( '/r', 'GET' );
				},
				'hash_missing',
			),
			'hash_mismatch'                => array(
				static function ( Auth_Logger $logger ): void {
					$logger->content_hash_mismatch( '/r', 'GET' );
				},
				'hash_mismatch',
			),
			'connected_url_not_configured' => array(
				static function ( Auth_Logger $logger ): void {
					$logger->connected_url_not_configured( '/r', 'GET' );
				},
				'connected_url_not_configured',
			),
			'site_url_header_missing'      => array(
				static function ( Auth_Logger $logger ): void {
					$logger->site_url_header_missing( '/r', 'GET' );
				},
				'site_url_header_missing',
			),
			'url_mismatch'                 => array(
				static function ( Auth_Logger $logger ): void {
					$logger->site_url_mismatch( '/r', 'GET', 'a', 'b' );
				},
				'url_mismatch',
			),
			'signature_invalid'            => array(
				static function ( Auth_Logger $logger ): void {
					$logger->signature_invalid( '/r', 'GET', 0, 'a', 0 );
				},
				'signature_invalid',
			),
		);
	}
}
