<?php
/**
 * Stub for the VIP mu-plugins telemetry client used in integration tests.
 *
 * The plugin's Telemetry_Service guards the real send path with
 * class_exists(\Automattic\VIP\Telemetry\Telemetry::class) so non-VIP
 * installs no-op cleanly. Integration tests need the class to exist so
 * they can exercise the wrapper end-to-end; this minimal stub provides
 * a constructor and record_event signature that match the library API.
 *
 * The stub is intentionally inert — integration tests assert against an
 * injected Telemetry_Event_Queue, not against the stub itself. The stub's
 * only job is to make class_exists return true.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

// The whole point of this stub is to register a class under the VIP
// mu-plugins namespace so Telemetry_Service's class_exists guard fires;
// the prefix-all-globals rule does not apply.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Automattic\VIP\Telemetry;

if ( ! class_exists( Telemetry::class ) ) {
	/**
	 * Inert stand-in for the VIP mu-plugins telemetry client.
	 */
	class Telemetry {

		/**
		 * Constructs the stub.
		 *
		 * @param string               $prefix            Event prefix (ignored).
		 * @param array<string, mixed> $global_properties Global props (ignored).
		 */
		public function __construct( string $prefix, array $global_properties = array() ) {
		}

		/**
		 * Records an event. Inert.
		 *
		 * @param string               $event      Event name (ignored).
		 * @param array<string, mixed> $properties Event properties (ignored).
		 */
		public function record_event( string $event, array $properties = array() ): void {
		}
	}
}
