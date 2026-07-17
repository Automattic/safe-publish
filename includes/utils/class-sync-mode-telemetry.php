<?php
/**
 * Sync Mode Telemetry class.
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
 * Emits a telemetry event when the operator sets or changes the sync mode.
 *
 * The sync_mode global property already rides on every event, but that only
 * captures the mode a given event fired under. This bridge captures the
 * configuration moment itself, turning the onboarding funnel (installed ->
 * configured) and later mode switches into a measurable signal.
 *
 * Self-registers its own option hooks, mirroring Settings_Logger: settings
 * flow through the Settings API's options.php form and no domain class owns
 * the save path to call this from. WP fires add_option_<name> only on first
 * creation and update_option_<name> only when the value actually changes, so
 * no-op saves produce no event.
 */
final class Sync_Mode_Telemetry {

	/**
	 * Telemetry service used to emit the configuration event.
	 *
	 * @var Telemetry_Service
	 */
	private Telemetry_Service $telemetry;

	/**
	 * Constructs the Sync_Mode_Telemetry instance.
	 *
	 * @param Telemetry_Service $telemetry Telemetry service.
	 */
	public function __construct( Telemetry_Service $telemetry ) {
		$this->telemetry = $telemetry;
	}

	/**
	 * Registers the sync-mode option-change hooks.
	 */
	public function register_handlers(): void {
		add_action(
			'add_option_' . Options::OPTION_SYNC_MODE,
			array( $this, 'on_sync_mode_added' ),
			10,
			2
		);
		add_action(
			'update_option_' . Options::OPTION_SYNC_MODE,
			array( $this, 'on_sync_mode_updated' ),
			10,
			2
		);
	}

	/**
	 * Emits the event for the first-ever sync-mode save.
	 *
	 * @param string $_option Option name (unused, fixed by hook binding).
	 * @param mixed  $value   New sync-mode value.
	 */
	public function on_sync_mode_added( string $_option, mixed $value ): void {
		$this->record( '', (string) $value, true );
	}

	/**
	 * Emits the event for a later sync-mode change.
	 *
	 * @param mixed $old_value Previous sync-mode value.
	 * @param mixed $new_value New sync-mode value.
	 */
	public function on_sync_mode_updated( mixed $old_value, mixed $new_value ): void {
		$this->record( (string) $old_value, (string) $new_value, false );
	}

	/**
	 * Records the sync_mode_configured event with bounded modes.
	 *
	 * @param string $previous_mode          Previous mode ('' when first set).
	 * @param string $new_mode               New mode.
	 * @param bool   $is_first_configuration Whether this is the first-ever save.
	 */
	private function record(
		string $previous_mode,
		string $new_mode,
		bool $is_first_configuration
	): void {
		$this->telemetry->record_event(
			Telemetry_Events::SYNC_MODE_CONFIGURED,
			array(
				'previous_mode'          => Telemetry_Events::normalize_sync_mode(
					$previous_mode
				),
				'new_mode'               => Telemetry_Events::normalize_sync_mode(
					$new_mode
				),
				'is_first_configuration' => $is_first_configuration,
			)
		);
	}
}
