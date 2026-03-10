<?php
/**
 * Authentication Logger class.
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Auth;

/**
 * Handles authentication event logging.
 *
 * Logs events to multiple backends: error_log, syslog, file, database (wp_options),
 * New Relic, WP debug log, and WordPress action hooks.
 */
class Auth_Logger {
	/**
	 * Deferred logs waiting to be written.
	 *
	 * @var array
	 */
	private array $deferred_logs = array();

	/**
	 * Logs an authentication event.
	 *
	 * If called during a REST API request before headers are sent,
	 * the log is deferred until shutdown.
	 *
	 * @param string $event Event type (AUTH_SUCCESS, SIGNATURE_INVALID, etc.).
	 * @param array  $data  Optional. Additional event data. Default empty array.
	 */
	public function log_event( string $event, array $data = array() ): void {
		if ( $this->should_defer_logging() ) {
			$this->defer_log( $event, $data );
			return;
		}

		$this->write_log( $event, $data );
	}

	/**
	 * Processes all deferred logs queued during REST API requests.
	 */
	public function process_deferred_logs(): void {
		foreach ( $this->deferred_logs as $log ) {
			$this->write_log( $log['event'], $log['data'] );
		}

		$this->deferred_logs = array();
	}

	/**
	 * Tests logging on WordPress init to ensure logs appear.
	 *
	 * Runs at most once per day to avoid log spam.
	 * Skipped during REST API requests to avoid header issues.
	 */
	public function test_logging_on_init(): void {
		if ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) ) {
			return;
		}

		if ( defined( 'WP_TESTS_DOMAIN' ) ) {
			return;
		}

		$last_test = get_option( 'safe_publish_auth_last_log_test', 0 );
		if ( time() - $last_test < 86400 ) { // 24 hours.
			return;
		}

		update_option( 'safe_publish_auth_last_log_test', time(), false );

		$this->log_event(
			'INIT_LOG_TEST',
			array(
				'purpose'           => 'Testing VIP logging visibility',
				'wp_loaded'         => did_action( 'wp_loaded' ),
				'plugins_loaded'    => did_action( 'plugins_loaded' ),
				'mu_plugins_loaded' => did_action( 'muplugins_loaded' ),
				'php_version'       => PHP_VERSION,
				'wp_version'        => get_bloginfo( 'version' ),
			)
		);
	}

	/**
	 * Checks if logging should be deferred due to an active REST request.
	 *
	 * @return bool True if logging should be deferred, false otherwise.
	 */
	private function should_defer_logging(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST && ! headers_sent();
	}

	/**
	 * Defers a log entry for later processing.
	 *
	 * Registers the shutdown callback on first deferral.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Event data.
	 */
	private function defer_log( string $event, array $data ): void {
		$this->deferred_logs[] = array(
			'event' => $event,
			'data'  => $data,
		);

		if ( ! has_action( 'shutdown', array( $this, 'process_deferred_logs' ) ) ) {
			add_action( 'shutdown', array( $this, 'process_deferred_logs' ) );
		}
	}

	/**
	 * Writes a log entry to all configured backends.
	 *
	 * Backends: error_log, file (get_temp_dir), syslog, database (wp_options),
	 * New Relic, WP_DEBUG_LOG, WordPress action hook, and fastcgi_finish_request.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Event data to merge with standard fields.
	 */
	private function write_log( string $event, array $data ): void {
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

		$log_message = '[Safe-Publish-Auth-VIP] ' . $event . ': ' . wp_json_encode( $log_data, JSON_UNESCAPED_SLASHES );

		$is_test_env = defined( 'WP_TESTS_DOMAIN' );

		if ( ! $is_test_env ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $log_message );

			// 1. Direct file write as backup (VIP-safe location).
			$log_file = get_temp_dir() . 'safe-publish-auth-vip.log';
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			$file_message = date( 'Y-m-d H:i:s' ) . ' ' . $log_message . "\n";
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
			@file_put_contents( $log_file, $file_message, FILE_APPEND | LOCK_EX );

			// 2. PHP syslog for additional visibility.
			if ( function_exists( 'syslog' ) ) {
				openlog( 'Safe-Publish-Auth-VIP', LOG_PID, LOG_USER );
				syslog( LOG_INFO, $event . ': ' . wp_json_encode( $log_data, JSON_UNESCAPED_SLASHES ) );
				closelog();
			}
		}

		// 3. Store recent events in database for dashboard viewing (only if WordPress is loaded).
		if ( function_exists( 'get_option' ) ) {
			$this->store_log_event( $event, $log_data );
		}

		// 4. New Relic custom events (if available).
		if ( function_exists( 'newrelic_record_custom_event' ) ) {
			newrelic_record_custom_event(
				'Safe_Publish_Auth_Event',
				array(
					'event_type' => $event,
					'site_url'   => $site_url,
					'ip'         => $log_data['ip'],
					'success'    => strpos( $event, 'SUCCESS' ) !== false,
				)
			);
		}

		// 5. WordPress debug log (if WP_DEBUG_LOG is enabled).
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && function_exists( 'wp_debug_log' ) ) {
			wp_debug_log( $log_message );
		}

		// 6. Trigger WordPress action for other monitoring plugins (only if WordPress is loaded).
		if ( function_exists( 'do_action' ) ) {
			do_action( 'safe_publish_auth_event_logged', $event, $log_data );
		}

		// 7. Force immediate log write for VIP (bypass buffering).
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV && function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
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
