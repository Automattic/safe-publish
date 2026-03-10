<?php
/**
 * Auth Manager class.
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Auth;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Coordinates authentication system initialization.
 *
 * Wires up all auth components (logger, authenticator, permission manager,
 * dashboard widget) and registers WordPress hooks on their behalf.
 */
class Auth_Manager {

	/**
	 * HMAC authenticator instance.
	 *
	 * @var HMAC_Authenticator
	 */
	private HMAC_Authenticator $authenticator;

	/**
	 * Permission manager instance.
	 *
	 * @var Permission_Manager
	 */
	private Permission_Manager $permission_manager;

	/**
	 * Logger instance.
	 *
	 * @var Auth_Logger
	 */
	private Auth_Logger $logger;

	/**
	 * Dashboard widget instance.
	 *
	 * Instantiated here to register its WordPress hooks (wp_dashboard_setup,
	 * admin_notices, etc.) alongside the other auth components.
	 *
	 * @psalm-suppress UnusedProperty
	 * @var Dashboard_Widget
	 */
	private Dashboard_Widget $dashboard_widget;

	/**
	 * Constructor.
	 *
	 * Creates all auth components. Dashboard_Widget registers its own admin
	 * hooks in its constructor (wp_dashboard_setup, admin_notices, etc.).
	 */
	public function __construct() {
		$this->logger             = new Auth_Logger();
		$this->permission_manager = new Permission_Manager( $this->logger );
		$this->authenticator      = new HMAC_Authenticator( $this->logger, $this->permission_manager );
		$this->dashboard_widget   = new Dashboard_Widget( $this->get_shared_secret() );
	}

	/**
	 * Initializes authentication system hooks.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'init_auth_handler' ) );
		add_action( 'rest_api_init', array( $this, 'register_monitoring_endpoints' ) );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			add_action( 'rest_api_init', array( $this, 'register_test_endpoint' ) );
		}

		// Dev: once-per-day log probe (skips REST requests).
		add_action( 'init', array( $this->logger, 'test_logging_on_init' ) );
	}

	/**
	 * Initializes authentication and permission filters for REST API.
	 */
	public function init_auth_handler(): void {
		add_filter( 'rest_pre_dispatch', array( $this->authenticator, 'authenticate_request' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( $this->permission_manager, 'handle_permission_check' ), 10, 3 );
	}

	/**

	 * Registers monitoring REST endpoints for authentication status.
	 */
	public function register_monitoring_endpoints(): void {
		register_rest_route(
			'safe-publish/v1',
			'/auth-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'auth_status_callback' ),
				'permission_callback' => array( $this, 'can_view_auth_status' ),
			)
		);

		register_rest_route(
			'safe-publish/v1',
			'/auth-logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'auth_logs_callback' ),
				'permission_callback' => array( $this, 'can_view_auth_status' ),
			)
		);

		register_rest_route(
			'safe-publish/v1',
			'/auth-logs',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'clear_auth_logs_callback' ),
				'permission_callback' => array( $this, 'can_manage_auth' ),
			)
		);
	}

	/**
	 * Registers test endpoint for Safe Publish authentication (debug mode only).
	 */
	public function register_test_endpoint(): void {
		register_rest_route(
			'safe-publish/v1',
			'/auth-test',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'auth_test_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Authentication status callback for the monitoring endpoint.
	 *
	 * @param WP_REST_Request $_request REST request object.
	 * @return WP_REST_Response Response containing auth status data.
	 */
	public function auth_status_callback( WP_REST_Request $_request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$shared_secret = $this->get_shared_secret();
		$stats         = get_option( 'safe_publish_auth_stats', array() );
		$recent_events = get_option( 'safe_publish_auth_log_events', array() );

		[ $status, $health_score, $issues ] = $this->calculate_health( $shared_secret, $stats, $recent_events );

		return new \WP_REST_Response(
			array(
				'status'              => $status,
				'health_score'        => $health_score,
				'issues'              => $issues,
				'timestamp'           => current_time( 'mysql' ),
				'configuration'       => array(
					'shared_secret_configured' => ! empty( $shared_secret ),
					'secret_length'            => strlen( $shared_secret ),
					'secret_source'            => $this->get_secret_source(),
					'vip_environment'          => defined( 'WPCOM_IS_VIP_ENV' ) ? WPCOM_IS_VIP_ENV : false,
					'debug_mode'               => defined( 'WP_DEBUG' ) ? WP_DEBUG : false,
				),
				'statistics'          => array_merge(
					array(
						'total_requests'   => 0,
						'successful_auths' => 0,
						'failed_auths'     => 0,
						'success_rate'     => 0,
						'last_success'     => null,
						'last_failure'     => null,
					),
					$stats
				),
				'recent_events_count' => count( $recent_events ),
			),
			200
		);
	}

	/**
	 * Authentication logs callback.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response Response containing paginated auth log data.
	 */
	public function auth_logs_callback( WP_REST_Request $request ): WP_REST_Response {
		$recent_events = get_option( 'safe_publish_auth_log_events', array() );
		$limit_value   = (int) $request->get_param( 'limit' );
		$limit         = min( $limit_value ? $limit_value : 50, 100 );
		$offset_value  = (int) $request->get_param( 'offset' );
		$offset        = $offset_value ? $offset_value : 0;

		$event_type = $request->get_param( 'event_type' );
		if ( $event_type ) {
			$recent_events = array_filter(
				$recent_events,
				function ( $event ) use ( $event_type ): bool {
					return strpos( $event['event'], $event_type ) !== false;
				}
			);
		}

		$total  = count( $recent_events );
		$events = array_slice( array_reverse( $recent_events ), $offset, $limit );

		return new \WP_REST_Response(
			array(
				'events'     => $events,
				'pagination' => array(
					'total'    => $total,
					'limit'    => $limit,
					'offset'   => $offset,
					'has_more' => ( $offset + $limit ) < $total,
				),
				'timestamp'  => current_time( 'mysql' ),
			),
			200
		);
	}

	/**
	 * Clears authentication logs callback.
	 *
	 * @param WP_REST_Request $_request REST request object.
	 * @return WP_REST_Response Response confirming logs were cleared.
	 */
	public function clear_auth_logs_callback( WP_REST_Request $_request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		delete_option( 'safe_publish_auth_log_events' );
		delete_option( 'safe_publish_auth_stats' );

		$user_id = get_current_user_id();

		$this->logger->log_event(
			'LOGS_CLEARED',
			array(
				'cleared_by' => $user_id ? $user_id : 'unknown',
				// Data only used for logging; not output to HTML.
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
				'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
			)
		);

		return new \WP_REST_Response(
			array(
				'message'   => 'Authentication logs and statistics cleared',
				'timestamp' => current_time( 'mysql' ),
			),
			200
		);
	}

	/**
	 * Test endpoint callback for Safe Publish authentication.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response containing test results.
	 */
	public function auth_test_callback( WP_REST_Request $request ): WP_REST_Response {
		$headers                  = $request->get_headers();
		$has_safe_publish_headers = isset( $headers['x_safe_publish_timestamp'] )
			&& isset( $headers['x_safe_publish_signature'] );
		$shared_secret            = $this->get_shared_secret();

		$this->logger->log_event(
			'TEST_ENDPOINT_ACCESSED',
			array(
				'headers_present' => $has_safe_publish_headers,
				'user_agent'      => $request->get_header( 'user_agent' ),
				'test_type'       => 'manual_endpoint_test',
			)
		);

		return new \WP_REST_Response(
			array(
				'message'                      => 'Safe Publish Authentication Test Endpoint',
				'timestamp'                    => current_time( 'mysql' ),
				'safe_publish_headers_present' => $has_safe_publish_headers,
				'shared_secret_configured'     => ! empty( $shared_secret ),
				'secret_length'                => strlen( $shared_secret ),
				'vip_environment'              => defined( 'WPCOM_IS_VIP_ENV' ) ? WPCOM_IS_VIP_ENV : false,
				'debug_mode'                   => defined( 'WP_DEBUG' ) ? WP_DEBUG : false,
				'logging_info'                 => array(
					'error_log_available' => function_exists( 'error_log' ),
					'syslog_available'    => function_exists( 'syslog' ),
					'log_test_generated'  => true,
				),
			),
			200
		);
	}

	/**
	 * Permission callback for viewing authentication status endpoints.
	 *
	 * @return bool True if user can view auth status, false otherwise.
	 */
	public function can_view_auth_status(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Allow requests authenticated via HMAC shared secret.
		return $this->authenticator->is_authenticated();
	}

	/**
	 * Permission callback for managing authentication configuration.
	 *
	 * @return bool True if user can manage auth, false otherwise.
	 */
	public function can_manage_auth(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Calculates health status from current configuration and recent statistics.
	 *
	 * @param string $shared_secret  The configured shared secret.
	 * @param array  $stats          Authentication statistics from wp_options.
	 * @param array  $recent_events  Recent authentication event log.
	 * @return array{0: string, 1: int, 2: array} Tuple of [status, health_score, issues].
	 */
	private function calculate_health( string $shared_secret, array $stats, array $recent_events ): array {
		$health_score = 100;
		$issues       = array();

		if ( empty( $shared_secret ) ) {
			$health_score -= 50;
			$issues[]      = 'No shared secret configured';
		} elseif ( strlen( $shared_secret ) < 32 ) {
			$health_score -= 20;
			$issues[]      = 'Shared secret too short (< 32 characters)';
		}

		$total_requests = $stats['total_requests'] ?? 0;
		if ( $total_requests > 0 ) {
			$success_rate = ( ( $stats['successful_auths'] ?? 0 ) / $total_requests ) * 100;
			if ( $success_rate < 95 ) {
				$health_score -= 30;
				$issues[]      = sprintf( 'Low success rate: %.1f%%', $success_rate );
			}
		}

		$recent_failures = array_filter(
			array_slice( $recent_events, -10 ),
			function ( $event ): bool {
				return strpos( $event['event'], 'INVALID' ) !== false
					|| strpos( $event['event'], 'EXPIRED' ) !== false;
			}
		);

		if ( count( $recent_failures ) > 5 ) {
			$health_score -= 20;
			$issues[]      = 'Multiple recent authentication failures';
		}

		$health_score = max( 0, $health_score );
		$status       = 'healthy';

		if ( $health_score < 80 ) {
			$status = 'degraded';
		}
		if ( $health_score < 50 ) {
			$status = 'unhealthy';
		}

		return array( $status, $health_score, $issues );
	}

	/**
	 * Gets the shared secret from a constant or environment variable.
	 *
	 * Does NOT read from wp_options — secret must come from the server environment.
	 *
	 * @return string Shared secret, or empty string if not configured.
	 */
	private function get_shared_secret(): string {
		if ( defined( 'SAFE_PUBLISH_SHARED_SECRET' ) && ! empty( SAFE_PUBLISH_SHARED_SECRET ) ) {
			return SAFE_PUBLISH_SHARED_SECRET;
		}

		$env_secret = getenv( 'SAFE_PUBLISH_SHARED_SECRET' );
		if ( ! empty( $env_secret ) ) {
			return $env_secret;
		}

		if ( isset( $_ENV['SAFE_PUBLISH_SHARED_SECRET'] ) && ! empty( $_ENV['SAFE_PUBLISH_SHARED_SECRET'] ) ) {
			// Cryptographic secret not sanitized; used directly for HMAC authentication.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$env_secret = trim( $_ENV['SAFE_PUBLISH_SHARED_SECRET'] );
			if ( strlen( $env_secret ) >= 16 && preg_match( '/^[a-zA-Z0-9\-_+=\/]+$/', $env_secret ) ) {
				return $env_secret;
			}
		}

		return '';
	}

	/**
	 * Gets the source of the shared secret for debugging purposes.
	 *
	 * @return string Source of the secret: 'constant', 'environment', or 'none'.
	 */
	private function get_secret_source(): string {
		if ( defined( 'SAFE_PUBLISH_SHARED_SECRET' ) && ! empty( SAFE_PUBLISH_SHARED_SECRET ) ) {
			return 'constant';
		}

		if ( ! empty( getenv( 'SAFE_PUBLISH_SHARED_SECRET' ) ) ) {
			return 'environment';
		}

		return 'none';
	}
}
