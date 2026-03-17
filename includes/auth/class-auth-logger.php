<?php
/**
 * Authentication Logger class.
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Auth;

use Safe_Publish\Utils\Logger;

/**
 * Logger for Safe Publish authentication events.
 *
 * Extends the base Logger with auth-specific statistics tracking.
 */
class Auth_Logger extends Logger {

	/**
	 * Constructs the Auth_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'auth';
	}

	/**
	 * Stores an auth event in the log table and updates rolling auth statistics.
	 *
	 * @param string $level    Event level: 'info' or 'error'.
	 * @param string $event    Event type.
	 * @param array  $log_data Full event data.
	 */
	#[\Override]
	protected function store_log_event(
		string $level,
		string $event,
		array $log_data
	): void {
		parent::store_log_event( $level, $event, $log_data );
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
