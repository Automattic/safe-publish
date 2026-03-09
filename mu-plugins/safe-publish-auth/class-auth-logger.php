<?php
/**
 * Authentication Logger class.
 *
 * @package Safe_Publish_Auth
 */

namespace Safe_Publish\Auth;

/**
 * Handles Safe Publish event logging.
 *
 * Failures are written to error_log. All events are stored in wp_options for
 * dashboard and REST API display, and fire a WordPress action hook.
 */
class Auth_Logger {

	/**
	 * Logs an informational event.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Optional. Additional event data. Default empty array.
	 */
	public function log_event( string $event, array $data = array() ): void {
		$this->write_log( $event, $data, false );
	}

	/**
	 * Logs a failure event.
	 *
	 * Writes to error_log in addition to the standard logging mechanisms.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Optional. Additional event data. Default empty array.
	 */
	public function log_error( string $event, array $data = array() ): void {
		$this->write_log( $event, $data, true );
	}

	/**
	 * Writes a log entry, optionally to error_log, and stores it in the database.
	 *
	 * @param string $event         Event type.
	 * @param array  $data          Event data to merge with standard fields.
	 * @param bool   $use_error_log Whether to write to error_log.
	 */
	private function write_log( string $event, array $data, bool $use_error_log ): void {
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$timestamp = function_exists( 'current_time' ) ? current_time( 'mysql' ) : date( 'Y-m-d H:i:s' );
		// Data only used for logging; escaped with esc_html() when output to HTML in dashboard widget.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$site_url = function_exists( 'get_site_url' ) ? get_site_url() : ( $_SERVER['HTTP_HOST'] ?? 'unknown' );

		$log_data = array_merge(
			array(
				'event'       => $event,
				'timestamp'   => $timestamp,
				'site_url'    => $site_url,
				// Data only used for logging; escaped with esc_html() when output to HTML in dashboard widget.
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__
				'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
				'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
			),
			$data
		);

		$log_message = '[Safe-Publish-Auth] ' . $event . ': ' . wp_json_encode( $log_data, JSON_UNESCAPED_SLASHES );

		if ( $use_error_log ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $log_message );
		}

		if ( function_exists( 'get_option' ) ) {
			$this->store_log_event( $event, $log_data );
		}

		if ( function_exists( 'do_action' ) ) {
			do_action( 'safe_publish_auth_event_logged', $event, $log_data );
		}
	}

	/**
	 * Stores a log event in the database for dashboard viewing.
	 *
	 * Keeps a rolling window of the last 100 events and updates auth statistics.
	 *
	 * @param string $event    Event type.
	 * @param array  $log_data Full event data to store.
	 */
	private function store_log_event( string $event, array $log_data ): void {
		$log_events = get_option( 'safe_publish_auth_log_events', array() );

		$log_events[] = array(
			'event' => $event,
			'data'  => $log_data,
			'id'    => uniqid(),
		);

		if ( count( $log_events ) > 100 ) {
			$log_events = array_slice( $log_events, -100 );
		}

		update_option( 'safe_publish_auth_log_events', $log_events, false );
		$this->update_auth_stats( $event );
	}

	/**
	 * Updates rolling authentication statistics in the database.
	 *
	 * Tracks totals and per-day breakdowns for the last 30 days.
	 *
	 * @param string $event Event type to record.
	 */
	private function update_auth_stats( string $event ): void {
		$stats = get_option(
			'safe_publish_auth_stats',
			array(
				'total_requests'   => 0,
				'successful_auths' => 0,
				'failed_auths'     => 0,
				'last_success'     => null,
				'last_failure'     => null,
				'daily_stats'      => array(),
			)
		);

		$today = current_time( 'Y-m-d' );

		if ( ! isset( $stats['daily_stats'][ $today ] ) ) {
			$stats['daily_stats'][ $today ] = array(
				'requests'  => 0,
				'successes' => 0,
				'failures'  => 0,
			);
		}

		++$stats['total_requests'];
		++$stats['daily_stats'][ $today ]['requests'];

		if ( strpos( $event, 'SUCCESS' ) !== false ) {
			++$stats['successful_auths'];
			$stats['last_success'] = current_time( 'mysql' );
			++$stats['daily_stats'][ $today ]['successes'];
		} elseif (
			strpos( $event, 'INVALID' ) !== false ||
			strpos( $event, 'EXPIRED' ) !== false
		) {
			++$stats['failed_auths'];
			$stats['last_failure'] = current_time( 'mysql' );
			++$stats['daily_stats'][ $today ]['failures'];
		}

		$stats['daily_stats'] = array_slice( $stats['daily_stats'], -30, null, true );
		update_option( 'safe_publish_auth_stats', $stats, false );
	}
}
