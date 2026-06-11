<?php
/**
 * Telemetry Bridge class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Utils;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges the existing audit-log action (`safe_publish_event_logged`) to
 * telemetry. Maps selected channel + event combinations to telemetry events
 * so that actions already audit-logged don't need a second emit at the
 * call site.
 *
 * Identity caveat: source-side auth failures may fire before any user is
 * authenticated, so the VIP telemetry library's visitor resolution falls
 * back to whatever it records for unauthenticated REST contexts.
 */
final class Telemetry_Bridge {

	/**
	 * Telemetry service to forward bridged events to.
	 *
	 * @var Telemetry_Service
	 */
	private Telemetry_Service $telemetry;

	/**
	 * Constructs the bridge.
	 *
	 * @param Telemetry_Service $telemetry Service to forward events to.
	 */
	public function __construct( Telemetry_Service $telemetry ) {
		$this->telemetry = $telemetry;
	}

	/**
	 * Subscribes the bridge to the audit-log action so future log writes
	 * are inspected and selectively mapped to telemetry.
	 */
	public function register(): void {
		add_action(
			'safe_publish_event_logged',
			array( $this, 'on_event_logged' ),
			10,
			3
		);
	}

	/**
	 * Inspects an audit-log entry and emits telemetry when the channel +
	 * event combination is mapped. Non-mapped events return without side
	 * effects.
	 *
	 * @param string               $channel Channel identifier (auth, import, etc.).
	 * @param string               $event   Event code from Log_Events.
	 * @param array<string, mixed> $_data   Event payload (unused at this level).
	 */
	public function on_event_logged( // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		string $channel,
		string $event,
		array $_data
	): void {
		if ( 'auth' === $channel ) {
			$this->emit_inbound_auth_failed( $event );
		}
	}

	/**
	 * Maps an auth-channel event code to the `inbound_auth_failed` reason
	 * enum and emits the telemetry event. Success and unrecognized-action
	 * events are intentionally not mapped.
	 *
	 * @param string $event Auth-channel event code from Log_Events.
	 */
	private function emit_inbound_auth_failed( string $event ): void {
		$reason = Telemetry_Events::AUTH_REASON_MAP[ $event ] ?? null;
		if ( null === $reason ) {
			return;
		}

		$this->telemetry->record_event(
			Telemetry_Events::INBOUND_AUTH_FAILED,
			array( 'reason' => $reason )
		);
	}
}
