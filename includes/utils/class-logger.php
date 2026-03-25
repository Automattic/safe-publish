<?php
/**
 * Logger class.
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Utils;

/**
 * Abstract base logger for Safe Publish events.
 *
 * Info events are stored in the database and fire a WordPress action hook.
 * Error events additionally write to the server error log.
 * Subclasses define the channel and may override store_log_event() to add
 * side effects while preserving base storage behavior.
 */
abstract class Logger {

	/**
	 * The logging channel identifier (e.g. 'auth', 'media').
	 *
	 * Drives the database option key, server log prefix, and hook channel
	 * argument.
	 *
	 * @var string
	 */
	protected string $channel;

	/**
	 * Logs an informational event to the database and fires a hook.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Optional. Additional event data. Default empty array.
	 */
	public function log_event( string $event, array $data = array() ): void {
		$this->write( $event, $data, false );
	}

	/**
	 * Logs a failure event to the server error log and the database, and fires
	 * a hook.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Optional. Additional event data. Default empty array.
	 */
	public function log_error( string $event, array $data = array() ): void {
		$this->write( $event, $data, true );
	}

	/**
	 * Writes a log entry to the configured targets.
	 *
	 * @param string $event         Event type.
	 * @param array  $data          Additional event data.
	 * @param bool   $use_error_log Whether to also write to the server error log.
	 */
	private function write( string $event, array $data, bool $use_error_log ): void {
		$log_data = $this->build_log_data( $event, $data );
		$level    = $use_error_log ? 'error' : 'info';

		if ( ! defined( 'WP_TESTS_DOMAIN' ) && $use_error_log ) {
			$prefix = '[Safe-Publish-' . ucfirst( $this->channel ) . '] ';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $prefix . $event . ': ' . wp_json_encode( $log_data, JSON_UNESCAPED_SLASHES ) );
		}

		global $wpdb;

		if ( isset( $wpdb ) ) {
			$this->store_log_event( $level, $event, $log_data );
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'safe_publish_event_logged', $this->channel, $event, $log_data );
		}
	}

	/**
	 * Builds the standard log data payload for an event.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Caller-supplied event data to merge.
	 * @return array Complete log data array.
	 */
	private function build_log_data( string $event, array $data ): array {
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$timestamp = function_exists( 'current_time' ) ? current_time( 'mysql' ) : date( 'Y-m-d H:i:s' );
		// Data only used for logging; escaped with esc_html() when output to HTML.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$site_url = function_exists( 'get_site_url' ) ? get_site_url() : ( $_SERVER['HTTP_HOST'] ?? 'unknown' );

		return array_merge(
			array(
				'event'       => $event,
				'timestamp'   => $timestamp,
				'site_url'    => $site_url,
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
				'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
			),
			$data
		);
	}

	/**
	 * Stores an event in the log table.
	 *
	 * Subclasses may override this method to add side effects while calling
	 * parent::store_log_event() to preserve the base storage behavior.
	 *
	 * @param string $level    Event level: 'info' or 'error'.
	 * @param string $event    Event type.
	 * @param array  $log_data Full event data.
	 */
	protected function store_log_event( string $level, string $event, array $log_data ): void {
		$created_at = $log_data['timestamp'];
		$data       = $log_data;

		// These are stored as dedicated columns.
		unset( $data['event'], $data['timestamp'] );

		Event_Table::insert( $this->channel, $level, $event, $created_at, $data );
	}
}
