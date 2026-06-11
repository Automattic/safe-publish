<?php
/**
 * Telemetry Service class.
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
 * Thin wrapper around the VIP mu-plugins telemetry library
 * (Automattic\VIP\Telemetry\Telemetry). Lazily constructs the underlying
 * client and guards class_exists so non-VIP/local installs silently no-op
 * instead of fataling.
 *
 * All Safe Publish telemetry call sites go through this service; the raw
 * VIP class is never referenced directly. Tests inject a
 * Telemetry_Event_Queue via the constructor to assert on recorded events
 * without needing a live send path.
 */
final class Telemetry_Service {

	/**
	 * Fully-qualified class name of the VIP mu-plugins telemetry client.
	 * Stored as a string so the wrapper can resolve it at runtime via
	 * class_exists without forcing the autoloader to fail on non-VIP
	 * installs.
	 */
	private const VIP_TELEMETRY_CLASS = '\\Automattic\\VIP\\Telemetry\\Telemetry';

	/**
	 * Event-name prefix applied by the underlying client.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Global properties attached to every event by the underlying client.
	 *
	 * @var array<string, mixed>
	 */
	private array $global_properties;

	/**
	 * Test-only queue. When set, replaces the real send path and tests
	 * assert on its contents.
	 *
	 * @var Telemetry_Event_Queue|null
	 */
	private ?Telemetry_Event_Queue $queue;

	/**
	 * Lazily-constructed VIP client instance. Typed as object so the wrapper
	 * compiles without the VIP class being present.
	 *
	 * @var object|null
	 */
	private ?object $client = null;

	/**
	 * Whether the lazy client init has already run. Used to distinguish
	 * "haven't tried yet" from "tried and the class is absent" so the
	 * class_exists check fires at most once per request.
	 *
	 * @var bool
	 */
	private bool $client_initialized = false;

	/**
	 * Constructs the service.
	 *
	 * @param string                     $prefix            Event-name prefix.
	 * @param array<string, mixed>       $global_properties Global properties.
	 * @param Telemetry_Event_Queue|null $queue       Optional test queue. When
	 *                                                set, replaces the real
	 *                                                send path.
	 */
	public function __construct(
		string $prefix,
		array $global_properties = array(),
		?Telemetry_Event_Queue $queue = null
	) {
		$this->prefix            = $prefix;
		$this->global_properties = $global_properties;
		$this->queue             = $queue;
	}

	/**
	 * Records a telemetry event.
	 *
	 * Silently no-ops when the VIP telemetry class isn't available (local
	 * dev, unit tests without a stub). When a Telemetry_Event_Queue is
	 * injected, the queue receives the event instead of the live client.
	 *
	 * @param string               $event      Event name without prefix.
	 * @param array<string, mixed> $properties Event properties.
	 */
	public function record_event( string $event, array $properties = array() ): void {
		if ( null !== $this->queue ) {
			$this->queue->record( $event, $properties );
			return;
		}

		$client = $this->client();
		if ( null === $client ) {
			return;
		}

		// The VIP client method is named record_event; the wrapper mirrors
		// the signature so the call site is identical to the library API.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$client->record_event( $event, $properties );
	}

	/**
	 * Lazily constructs the underlying VIP client.
	 *
	 * Returns null and caches that result when the VIP telemetry class
	 * isn't loaded — non-VIP installs (local dev, unit tests) hit this
	 * path and the wrapper becomes a no-op.
	 */
	private function client(): ?object {
		if ( $this->client_initialized ) {
			return $this->client;
		}

		$this->client_initialized = true;

		if ( ! class_exists( self::VIP_TELEMETRY_CLASS ) ) {
			return null;
		}

		$class        = self::VIP_TELEMETRY_CLASS;
		$this->client = new $class( $this->prefix, $this->global_properties );

		return $this->client;
	}
}
