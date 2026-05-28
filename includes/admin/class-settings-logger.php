<?php
/**
 * Settings Logger class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Logger;
use Safe_Publish\Utils\Options;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger for security-relevant settings changes.
 *
 * Records when an operator changes a setting that affects who this site
 * connects to. URLs and sync mode are logged with their previous and new
 * values.
 *
 * Unlike other channel loggers, this one registers its own WP option hooks
 * because settings flow through the Settings API's options.php form and no
 * domain class owns the save path to call the logger from. Runs in every
 * sync mode so config drift is auditable.
 *
 * WP only fires update_option_<name> when the value actually differs from
 * the previous value, and only fires add_option_<name> when the option is
 * first created, so no-op writes produce no audit noise.
 */
class Settings_Logger extends Logger {

	/**
	 * Constructs the Settings_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'settings';
	}

	/**
	 * Registers the option-change hooks for every audited option.
	 */
	public function register_handlers(): void {
		add_action(
			'add_option_' . Options::OPTION_CONNECTED_SITE_URL,
			array( $this, 'on_connected_site_url_added' ),
			10,
			2
		);
		add_action(
			'update_option_' . Options::OPTION_CONNECTED_SITE_URL,
			array( $this, 'on_connected_site_url_updated' ),
			10,
			2
		);

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
	 * Logs a change to the connected site URL.
	 *
	 * @param string $previous_value Previous URL ('' when first set).
	 * @param string $new_value      New URL.
	 */
	public function connected_site_url_changed(
		string $previous_value,
		string $new_value
	): void {
		$this->log_event(
			Log_Events::CONNECTED_SITE_URL_CHANGED,
			array(
				'previous_value' => $previous_value,
				'new_value'      => $new_value,
			)
		);
	}

	/**
	 * Logs a change to the sync mode.
	 *
	 * @param string $previous_value Previous mode ('' when first set).
	 * @param string $new_value      New mode.
	 */
	public function sync_mode_changed(
		string $previous_value,
		string $new_value
	): void {
		$this->log_event(
			Log_Events::SYNC_MODE_CHANGED,
			array(
				'previous_value' => $previous_value,
				'new_value'      => $new_value,
			)
		);
	}

	/**
	 * Handles the first-set of the connected site URL.
	 *
	 * @param string $_option Option name (unused, fixed by hook binding).
	 * @param mixed  $value   New value.
	 */
	public function on_connected_site_url_added(
		string $_option,
		mixed $value
	): void {
		$this->connected_site_url_changed( '', (string) $value );
	}

	/**
	 * Handles an update to the connected site URL.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $new_value New value.
	 */
	public function on_connected_site_url_updated(
		mixed $old_value,
		mixed $new_value
	): void {
		$this->connected_site_url_changed(
			(string) $old_value,
			(string) $new_value
		);
	}

	/**
	 * Handles the first-set of the sync mode.
	 *
	 * @param string $_option Option name (unused, fixed by hook binding).
	 * @param mixed  $value   New value.
	 */
	public function on_sync_mode_added( string $_option, mixed $value ): void {
		$this->sync_mode_changed( '', (string) $value );
	}

	/**
	 * Handles an update to the sync mode.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $new_value New value.
	 */
	public function on_sync_mode_updated(
		mixed $old_value,
		mixed $new_value
	): void {
		$this->sync_mode_changed(
			(string) $old_value,
			(string) $new_value
		);
	}
}
