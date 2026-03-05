<?php
/**
 * Plugin Name: Safe Publish VIP Authentication Handler
 * Plugin URI: https://github.com/Automattic/safe-publish
 * Description: VIP-compatible auth handler for Safe Publish using shared secret HMAC.
 * Version: 1.0.0
 * Author: WPVIP
 * Author URI: https://wpvip.com
 * Network: true
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * This mu-plugin handles authentication for Safe Publish
 * requests on WordPress VIP environments using shared secret HMAC authentication.
 *
 * The shared secret is read from the SAFE_PUBLISH_SHARED_SECRET environment variable
 * which should be configured in the VIP dashboard.
 *
 * @package Safe_Publish_Auth
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent multiple inclusions.
if ( defined( 'SAFE_PUBLISH_VIP_AUTH_LOADED' ) ) {
	return;
}
define( 'SAFE_PUBLISH_VIP_AUTH_LOADED', true );

require_once __DIR__ . '/safe-publish-auth/class-auth-logger.php';
require_once __DIR__ . '/safe-publish-auth/interface-authenticator.php';
require_once __DIR__ . '/safe-publish-auth/class-hmac-authenticator.php';
require_once __DIR__ . '/safe-publish-auth/class-permission-manager.php';

/**
 * Returns the singleton Auth_Logger instance.
 *
 * @return \Safe_Publish\Auth\Auth_Logger Logger instance.
 */
function safe_publish_vip_get_auth_logger(): \Safe_Publish\Auth\Auth_Logger {
	static $logger = null;

	if ( null === $logger ) {
		$logger = new \Safe_Publish\Auth\Auth_Logger();
	}

	return $logger;
}

/**
 * Returns the singleton HMAC_Authenticator instance.
 *
 * @return \Safe_Publish\Auth\HMAC_Authenticator Authenticator instance.
 */
function safe_publish_vip_get_hmac_authenticator(): \Safe_Publish\Auth\HMAC_Authenticator {
	static $authenticator = null;

	if ( null === $authenticator ) {
		$authenticator = new \Safe_Publish\Auth\HMAC_Authenticator( safe_publish_vip_get_auth_logger() );
	}

	return $authenticator;
}

/**
 * Returns the singleton Permission_Manager instance.
 *
 * @return \Safe_Publish\Auth\Permission_Manager Permission manager instance.
 */
function safe_publish_vip_get_permission_manager(): \Safe_Publish\Auth\Permission_Manager {
	static $manager = null;

	if ( null === $manager ) {
		$manager = new \Safe_Publish\Auth\Permission_Manager( safe_publish_vip_get_auth_logger() );
	}

	return $manager;
}

/**
 * Initializes Safe Publish authentication handler.
 */
add_action( 'rest_api_init', 'safe_publish_vip_init_auth_handler' );

/**
 * Adds admin dashboard widget for Safe Publish authentication status.
 */
add_action( 'wp_dashboard_setup', 'safe_publish_vip_add_dashboard_widget' );

/**
 * Adds Safe Publish info to mu-plugins list (for better visibility).
 */
add_filter( 'show_advanced_plugins', 'safe_publish_vip_enhance_mu_plugins_display', 10, 2 );

/**
 * Adds init hook to test logging immediately.
 */
add_action( 'init', 'safe_publish_vip_test_logging_on_init' );

if ( ! function_exists( 'safe_publish_vip_test_logging_on_init' ) ) {
	/**
	 * Tests logging on WordPress init to ensure logs appear.
	 *
	 * @see Auth_Logger::test_logging_on_init()
	 */
	function safe_publish_vip_test_logging_on_init(): void {
		safe_publish_vip_get_auth_logger()->test_logging_on_init();
	}
}

if ( ! function_exists( 'safe_publish_vip_init_auth_handler' ) ) {
	/**
	 * Initializes the authentication handler for REST API requests.
	 */
	function safe_publish_vip_init_auth_handler(): void {
		add_filter( 'rest_pre_dispatch', 'safe_publish_vip_authenticate_request', 10, 3 );

		// Add early permission override for Safe Publish requests.
		add_filter( 'rest_request_before_callbacks', 'safe_publish_vip_handle_permission_check', 10, 3 );
	}
}

if ( ! function_exists( 'safe_publish_vip_handle_permission_check' ) ) {
	/**
	 * Handles permission checks before REST callbacks are executed.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|null $response Response to replace.
	 * @param array                                           $handler  Route handler used for the request.
	 * @param WP_REST_Request                                 $request  Request used to generate the response.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|null Modified response.
	 * @see \Safe_Publish\Auth\Permission_Manager::handle_permission_check()
	 */
	function safe_publish_vip_handle_permission_check( $response, $handler, $request ): WP_REST_Response|WP_HTTP_Response|WP_Error|null {
		return safe_publish_vip_get_permission_manager()->handle_permission_check( $response, $handler, $request );
	}
}

if ( ! function_exists( 'safe_publish_vip_override_endpoint_permissions' ) ) {
	/**
	 * Overrides REST endpoint permissions for Safe Publish authenticated requests.
	 *
	 * @param array $endpoints Registered REST endpoints.
	 * @return array Modified endpoints.
	 * @see \Safe_Publish\Auth\Permission_Manager::override_endpoint_permissions()
	 */
	function safe_publish_vip_override_endpoint_permissions( $endpoints ): array {
		return safe_publish_vip_get_permission_manager()->override_endpoint_permissions( $endpoints );
	}
}

if ( ! function_exists( 'safe_publish_vip_allow_all_permissions' ) ) {
	/**
	 * Permission callback that allows all operations for Safe Publish authenticated requests.
	 *
	 * @param WP_REST_Request|null $request Optional. REST request object.
	 * @return bool True for Safe Publish authenticated requests, otherwise result of capability check.
	 * @see \Safe_Publish\Auth\Permission_Manager::allow_all_permissions()
	 */
	function safe_publish_vip_allow_all_permissions( $request = null ): bool {
		return safe_publish_vip_get_permission_manager()->allow_all_permissions( $request );
	}
}

if ( ! function_exists( 'safe_publish_vip_override_collection_params' ) ) {
	/**
	 * Overrides collection parameters to allow edit context for Safe Publish.
	 *
	 * @param array        $params    Collection parameters.
	 * @param WP_Post_Type $post_type Post type object.
	 * @return array Modified collection parameters.
	 * @see \Safe_Publish\Auth\Permission_Manager::override_collection_params()
	 */
	function safe_publish_vip_override_collection_params( $params, $post_type ): array {
		return safe_publish_vip_get_permission_manager()->override_collection_params( $params, $post_type );
	}
}

if ( ! function_exists( 'safe_publish_vip_ensure_edit_context_access' ) ) {
	/**
	 * Ensures edit context access for Safe Publish authenticated requests.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_Post          $post     Post object.
	 * @param WP_REST_Request  $request  Request object.
	 * @return WP_REST_Response Response object, unchanged.
	 * @see \Safe_Publish\Auth\Permission_Manager::ensure_edit_context_access()
	 */
	function safe_publish_vip_ensure_edit_context_access( $response, $post, $request ): WP_REST_Response {
		return safe_publish_vip_get_permission_manager()->ensure_edit_context_access( $response, $post, $request );
	}
}

if ( ! function_exists( 'safe_publish_vip_authenticate_request' ) ) {
	/**
	 * Authenticates Safe Publish REST API requests using HMAC-SHA256 shared secret.
	 *
	 * @param WP_REST_Response|WP_Error|null $result  Response to replace.
	 * @param WP_REST_Server                 $server  Server instance.
	 * @param WP_REST_Request                $request Request used to generate the response.
	 * @return WP_REST_Response|WP_Error|null Original result or WP_Error for authentication failures.
	 * @see \Safe_Publish\Auth\HMAC_Authenticator::authenticate_request()
	 */
	function safe_publish_vip_authenticate_request( $result, $server, $request ): WP_REST_Response|WP_Error|null {
		return safe_publish_vip_get_hmac_authenticator()->authenticate_request( $result, $server, $request );
	}
}

if ( ! function_exists( 'safe_publish_vip_authenticate_shared_secret' ) ) {
	/**
	 * Authenticates using shared secret HMAC.
	 *
	 * @param WP_REST_Request                $request REST request object.
	 * @param array                          $headers Request headers.
	 * @param WP_REST_Response|WP_Error|null $result  Optional. Original result to pass through on success.
	 * @return WP_REST_Response|WP_Error|null Original result on success, or WP_Error on failure.
	 * @see \Safe_Publish\Auth\HMAC_Authenticator::authenticate_request()
	 */
	function safe_publish_vip_authenticate_shared_secret( $request, $headers, $result = null ): WP_REST_Response|WP_Error|null {
		return safe_publish_vip_get_hmac_authenticator()->authenticate_request( $result, null, $request );
	}
}

if ( ! function_exists( 'safe_publish_vip_override_meta_capabilities' ) ) {
	/**
	 * Overrides meta capabilities for Safe Publish authenticated requests.
	 *
	 * Handles capability mapping that occurs before user_has_cap.
	 *
	 * @param array  $caps    Required capabilities.
	 * @param string $cap     Capability being checked.
	 * @param int    $user_id User ID.
	 * @param array  $args    Arguments passed to capability check.
	 * @return array Modified capabilities.
	 * @see \Safe_Publish\Auth\Permission_Manager::override_meta_capabilities()
	 */
	function safe_publish_vip_override_meta_capabilities( $caps, $cap, $user_id, $args ): array {
		return safe_publish_vip_get_permission_manager()->override_meta_capabilities( $caps, $cap, $user_id, $args );
	}
}

if ( ! function_exists( 'safe_publish_vip_override_context_permissions' ) ) {
	/**
	 * Overrides context permissions for REST API responses.
	 *
	 * This specifically handles the rest_forbidden_context error.
	 *
	 * @param WP_REST_Response|WP_Error $result  Response object.
	 * @param WP_REST_Server            $server  Server instance.
	 * @param WP_REST_Request           $request Request object.
	 * @return WP_REST_Response|WP_Error Modified or re-dispatched response.
	 * @see \Safe_Publish\Auth\Permission_Manager::override_context_permissions()
	 */
	function safe_publish_vip_override_context_permissions( $result, $server, $request ): WP_REST_Response|WP_Error {
		return safe_publish_vip_get_permission_manager()->override_context_permissions( $result, $server, $request );
	}
}

if ( ! function_exists( 'safe_publish_vip_setup_authenticated_context' ) ) {
	/**
	 * Sets up authenticated context for Safe Publish requests.
	 *
	 * Grants necessary permissions for REST API operations.
	 *
	 * @param WP_REST_Request $request Authenticated REST request.
	 * @see \Safe_Publish\Auth\Permission_Manager::setup_authenticated_context()
	 */
	function safe_publish_vip_setup_authenticated_context( $request ): void {
		safe_publish_vip_get_permission_manager()->setup_authenticated_context( $request );
	}
}

if ( ! function_exists( 'safe_publish_vip_grant_api_capabilities' ) ) {
	/**
	 * Grants API capabilities for Safe Publish authenticated requests.
	 *
	 * @param array   $allcaps All capabilities for the user.
	 * @param array   $caps    Required capabilities.
	 * @param array   $args    Arguments for capability check.
	 * @param WP_User $user    User object.
	 * @return array Modified capabilities.
	 * @see \Safe_Publish\Auth\Permission_Manager::grant_api_capabilities()
	 */
	function safe_publish_vip_grant_api_capabilities( $allcaps, $caps, $args, $user ): array {
		return safe_publish_vip_get_permission_manager()->grant_api_capabilities( $allcaps, $caps, $args, $user );
	}
}

/**
 * Bypasses additional permission checks for Safe Publish authenticated requests.
 *
 * @param WP_REST_Response|WP_Error|null $result  Response to replace the requested version with.
 * @param WP_REST_Server                 $server  Server instance.
 * @param WP_REST_Request                $request Request used to generate the response.
 * @return WP_REST_Response|WP_Error|null Original result, unchanged.
 * @see \Safe_Publish\Auth\Permission_Manager::bypass_permission_checks()
 */
function safe_publish_vip_bypass_permission_checks( $result, $server, $request ): WP_REST_Response|WP_Error|null {
	return safe_publish_vip_get_permission_manager()->bypass_permission_checks( $result, $server, $request );
}

/**
 * Prepares post data for edit context when Safe Publish is authenticated.
 *
 * @param WP_REST_Response $response Response object.
 * @param WP_Post          $post     Post object.
 * @param WP_REST_Request  $request  Request object.
 * @return WP_REST_Response Response object, unchanged.
 * @see \Safe_Publish\Auth\Permission_Manager::prepare_post_for_edit_context()
 */
function safe_publish_vip_prepare_post_for_edit_context( $response, $post, $request ): WP_REST_Response {
	return safe_publish_vip_get_permission_manager()->prepare_post_for_edit_context( $response, $post, $request );
}

/**
 * Ensures response success for valid Safe Publish operations.
 *
 * @param WP_REST_Response|WP_Error $response Response object.
 * @param WP_REST_Server            $server   Server instance.
 * @param WP_REST_Request           $request  Request used to generate the response.
 * @return WP_REST_Response|WP_Error Response, potentially modified.
 * @see \Safe_Publish\Auth\Permission_Manager::ensure_response_success()
 */
function safe_publish_vip_ensure_response_success( $response, $server, $request ): WP_REST_Response|WP_Error {
	return safe_publish_vip_get_permission_manager()->ensure_response_success( $response, $server, $request );
}

if ( ! function_exists( 'safe_publish_vip_get_shared_secret' ) ) {
	/**
	 * Gets shared secret from VIP environment (multiple fallback methods).
	 *
	 * @return string Shared secret, or empty string if not found.
	 */
	function safe_publish_vip_get_shared_secret(): string {
		// Method 1: VIP constant (preferred - set in vip-config.php).
		if ( defined( 'SAFE_PUBLISH_SHARED_SECRET' ) && ! empty( SAFE_PUBLISH_SHARED_SECRET ) ) {
			return SAFE_PUBLISH_SHARED_SECRET;
		}

		// Method 2: Direct environment variable access.
		$env_secret = getenv( 'SAFE_PUBLISH_SHARED_SECRET' );
		if ( ! empty( $env_secret ) ) {
			return $env_secret;
		}

		// Method 3: $_ENV superglobal (fallback for some hosting environments).
		if ( isset( $_ENV['SAFE_PUBLISH_SHARED_SECRET'] ) && ! empty( $_ENV['SAFE_PUBLISH_SHARED_SECRET'] ) ) {
			// Cryptographic secret not sanitized; used directly for HMAC authentication.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$env_secret = trim( $_ENV['SAFE_PUBLISH_SHARED_SECRET'] );
			// Validate that it contains only safe characters for a secret key.
			if ( ! empty( $env_secret ) && strlen( $env_secret ) >= 16 && preg_match( '/^[a-zA-Z0-9\-_+=\/]+$/', $env_secret ) ) {
				return $env_secret;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'safe_publish_vip_log_auth_event' ) ) {
	/**
	 * Logs authentication events for monitoring and debugging.
	 *
	 * @param string $event Event type (AUTH_SUCCESS, SIGNATURE_INVALID, etc.).
	 * @param array  $data  Optional. Additional event data. Default empty array.
	 * @see \SafePublish\Auth\Auth_Logger::log_event()
	 */
	function safe_publish_vip_log_auth_event( $event, $data = array() ): void {
		safe_publish_vip_get_auth_logger()->log_event( $event, $data );
	}
}

if ( ! function_exists( 'safe_publish_vip_process_deferred_logs' ) ) {
	/**
	 * Processes deferred logs that were queued during REST API requests.
	 *
	 * @see \SafePublish\Auth\Auth_Logger::process_deferred_logs()
	 */
	function safe_publish_vip_process_deferred_logs(): void {
		safe_publish_vip_get_auth_logger()->process_deferred_logs();
	}
}

if ( ! function_exists( 'safe_publish_vip_store_log_event' ) ) {
	/**
	 * Stores log events in database for dashboard viewing.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Event data to store.
	 */
	function safe_publish_vip_store_log_event( $event, $data ): void {
		// Get existing log events (keep last 100 events).
		$log_events = get_option( 'safe_publish_auth_log_events', array() );

		// Add new event.
		$log_events[] = array(
			'event' => $event,
			'data'  => $data,
			'id'    => uniqid(),
		);

		// Keep only last 100 events to prevent database bloat.
		if ( count( $log_events ) > 100 ) {
			$log_events = array_slice( $log_events, -100 );
		}

		// Update option.
		update_option( 'safe_publish_auth_log_events', $log_events, false );

		// Also update summary statistics.
		safe_publish_vip_update_auth_stats( $event );
	}
}

/**
 * Updates authentication statistics.
 *
 * @param string $event Event type to record.
 */
function safe_publish_vip_update_auth_stats( $event ): void {
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

	// Initialize today's stats if needed.
	if ( ! isset( $stats['daily_stats'][ $today ] ) ) {
		$stats['daily_stats'][ $today ] = array(
			'requests'  => 0,
			'successes' => 0,
			'failures'  => 0,
		);
	}

	// Update counters.
	++$stats['total_requests'];
	++$stats['daily_stats'][ $today ]['requests'];

	if ( strpos( $event, 'SUCCESS' ) !== false ) {
		++$stats['successful_auths'];
		$stats['last_success'] = current_time( 'mysql' );
		++$stats['daily_stats'][ $today ]['successes'];
	} elseif ( strpos( $event, 'INVALID' ) !== false || strpos( $event, 'EXPIRED' ) !== false ) {
		++$stats['failed_auths'];
		$stats['last_failure'] = current_time( 'mysql' );
		++$stats['daily_stats'][ $today ]['failures'];
	}

	// Keep only last 30 days of daily stats.
	$stats['daily_stats'] = array_slice( $stats['daily_stats'], -30, null, true );

	update_option( 'safe_publish_auth_stats', $stats, false );
}

/**
 * Adds admin notice about Safe Publish authentication status (VIP-safe).
 */
add_action( 'admin_notices', 'safe_publish_vip_auth_admin_notice' );

/**
 * Displays admin notice about Safe Publish authentication configuration status.
 */
function safe_publish_vip_auth_admin_notice(): void {
	// Only show to administrators.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only show on relevant admin pages.
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
		return;
	}

	$shared_secret = safe_publish_vip_get_shared_secret();
	$secret_length = strlen( $shared_secret );

	if ( empty( $shared_secret ) ) {
		wp_admin_notice(
			__( 'Safe Publish Authentication: Shared secret not configured. Set the <code>SAFE_PUBLISH_SHARED_SECRET</code> environment variable in VIP dashboard to enable Safe Publish authentication.', 'safe-publish' ),
			array(
				'type' => 'warning',
			),
		);
	} elseif ( $secret_length < 32 ) {
		wp_admin_notice(
			sprintf(
				/* translators: %d: Length of the shared secret in characters */
				__( 'Safe Publish Authentication: Shared secret is too short ( %d character secret). Use at least 32 characters for security.', 'safe-publish' ),
				absint( $secret_length )
			),
			array(
				'type' => 'warning',
			),
		);
	} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		wp_admin_notice(
			sprintf(
				/* translators: %d: Length of the shared secret in characters */
				__( 'Safe Publish Authentication: Configured successfully ✅ ( %d character secret).', 'safe-publish' ),
				absint( $secret_length )
			),
			array(
				'dismissible' => true,
				'type'        => 'warning',
			),
		);
	}
}

// Register test endpoint in debug mode only.
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	add_action( 'rest_api_init', 'safe_publish_vip_register_test_endpoint' );
}

// Always register monitoring endpoint.
add_action( 'rest_api_init', 'safe_publish_vip_register_monitoring_endpoints' );

/**
 * Registers test endpoint for Safe Publish authentication (debug mode only).
 */
function safe_publish_vip_register_test_endpoint(): void {
	register_rest_route(
		'safe-publish/v1',
		'/auth-test',
		array(
			'methods'             => 'GET',
			'callback'            => 'safe_publish_vip_auth_test_callback',
			'permission_callback' => '__return_true', // Public endpoint for testing.
		)
	);
}

/**
 * Registers monitoring endpoints for authentication status.
 */
function safe_publish_vip_register_monitoring_endpoints(): void {
	// Authentication status endpoint.
	register_rest_route(
		'safe-publish/v1',
		'/auth-status',
		array(
			'methods'             => 'GET',
			'callback'            => 'safe_publish_vip_auth_status_callback',
			'permission_callback' => 'safe_publish_vip_can_view_auth_status',
		)
	);

	// Authentication logs endpoint.
	register_rest_route(
		'safe-publish/v1',
		'/auth-logs',
		array(
			'methods'             => 'GET',
			'callback'            => 'safe_publish_vip_auth_logs_callback',
			'permission_callback' => 'safe_publish_vip_can_view_auth_status',
		)
	);

	// Clear logs endpoint (for maintenance).
	register_rest_route(
		'safe-publish/v1',
		'/auth-logs',
		array(
			'methods'             => 'DELETE',
			'callback'            => 'safe_publish_vip_clear_auth_logs_callback',
			'permission_callback' => 'safe_publish_vip_can_manage_auth',
		)
	);
}

/**
 * Permission callback for viewing auth status.
 *
 * @return bool True if user can view auth status, false otherwise.
 */
function safe_publish_vip_can_view_auth_status(): bool {
	// Allow if user can manage options, or if it's a VIP monitoring request.
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	// Allow VIP monitoring systems (check for specific user agents or IPs).
	// User agent not sanitized; only used for string comparison, not storage or output.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
	$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
	if ( strpos( $user_agent, 'WPVIP-Monitor' ) !== false ) {
		return true;
	}

	return false;
}

/**
 * Permission callback for managing auth.
 *
 * @return bool True if user can manage auth, false otherwise.
 */
function safe_publish_vip_can_manage_auth(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Authentication status callback for monitoring.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response Response containing auth status data.
 */
function safe_publish_vip_auth_status_callback( $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$shared_secret = safe_publish_vip_get_shared_secret();
	$stats         = get_option( 'safe_publish_auth_stats', array() );
	$recent_events = get_option( 'safe_publish_auth_log_events', array() );

	// Calculate health score.
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

	// Recent failures check.
	$recent_failures = array_filter(
		array_slice( $recent_events, -10 ),
		function ( $event ): bool {
			return strpos( $event['event'], 'INVALID' ) !== false || strpos( $event['event'], 'EXPIRED' ) !== false;
		}
	);

	if ( count( $recent_failures ) > 5 ) {
		$health_score -= 20;
		$issues[]      = 'Multiple recent authentication failures';
	}

	$status = 'healthy';
	if ( $health_score < 80 ) {
		$status = 'degraded';
	}
	if ( $health_score < 50 ) {
		$status = 'unhealthy';
	}

	return new WP_REST_Response(
		array(
			'status'              => $status,
			'health_score'        => max( 0, $health_score ),
			'issues'              => $issues,
			'timestamp'           => current_time( 'mysql' ),
			'configuration'       => array(
				'shared_secret_configured' => ! empty( $shared_secret ),
				'secret_length'            => strlen( $shared_secret ),
				'secret_source'            => safe_publish_vip_get_secret_source(),
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
 * @return WP_REST_Response Response containing auth logs data.
 */
function safe_publish_vip_auth_logs_callback( $request ): WP_REST_Response {
	$recent_events = get_option( 'safe_publish_auth_log_events', array() );
	$limit_value   = (int) $request->get_param( 'limit' );
	$limit         = min( $limit_value ? $limit_value : 50, 100 );
	$offset_value  = (int) $request->get_param( 'offset' );
	$offset        = $offset_value ? $offset_value : 0;

	// Filter by event type if specified.
	$event_type = $request->get_param( 'event_type' );
	if ( $event_type ) {
		$recent_events = array_filter(
			$recent_events,
			function ( $event ) use ( $event_type ): bool {
				return strpos( $event['event'], $event_type ) !== false;
			}
		);
	}

	// Apply pagination.
	$total  = count( $recent_events );
	$events = array_slice( array_reverse( $recent_events ), $offset, $limit );

	return new WP_REST_Response(
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
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response Response confirming logs were cleared.
 */
function safe_publish_vip_clear_auth_logs_callback( $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	delete_option( 'safe_publish_auth_log_events' );
	delete_option( 'safe_publish_auth_stats' );
	$user_id = get_current_user_id();

	safe_publish_vip_log_auth_event(
		'LOGS_CLEARED',
		array(
			'cleared_by' => $user_id ? $user_id : 'unknown',
			// Data only used for logging; not output to HTML.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
		)
	);

	return new WP_REST_Response(
		array(
			'message'   => 'Authentication logs and statistics cleared',
			'timestamp' => current_time( 'mysql' ),
		),
		200
	);
}

/**
 * Gets the source of the shared secret for debugging.
 *
 * @return string Source of the secret ('constant', 'environment', 'option', or 'none').
 */
function safe_publish_vip_get_secret_source(): string {
	if ( defined( 'SAFE_PUBLISH_SHARED_SECRET' ) && ! empty( SAFE_PUBLISH_SHARED_SECRET ) ) {
		return 'constant';
	}
	if ( ! empty( getenv( 'SAFE_PUBLISH_SHARED_SECRET' ) ) ) {
		return 'environment';
	}
	return 'none';
}

/**
 * Handles test endpoint callback for Safe Publish authentication.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response Response containing test results.
 */
function safe_publish_vip_auth_test_callback( $request ): WP_REST_Response {
	$headers                  = $request->get_headers();
	$has_safe_publish_headers = isset( $headers['x_safe_publish_timestamp'] ) && isset( $headers['x_safe_publish_signature'] );

	// Force generate test logs to verify VIP logging.
	safe_publish_vip_log_auth_event(
		'TEST_ENDPOINT_ACCESSED',
		array(
			'headers_present' => $has_safe_publish_headers,
			'user_agent'      => $request->get_header( 'user_agent' ),
			'test_type'       => 'manual_endpoint_test',
		)
	);

	return new WP_REST_Response(
		array(
			'message'                      => 'Safe Publish Authentication Test Endpoint',
			'timestamp'                    => current_time( 'mysql' ),
			'safe_publish_headers_present' => $has_safe_publish_headers,
			'shared_secret_configured'     => ! empty( safe_publish_vip_get_shared_secret() ),
			'secret_length'                => strlen( safe_publish_vip_get_shared_secret() ),
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
 * Adds Safe Publish authentication status to Site Health (WordPress 5.2+).
 */
add_filter( 'site_status_tests', 'safe_publish_vip_add_site_health_test' );

/**
 * Adds Safe Publish authentication test to Site Health.
 *
 * @param array $tests Existing Site Health tests.
 * @return array Modified tests array with Safe Publish auth test added.
 */
function safe_publish_vip_add_site_health_test( $tests ): array {
	$tests['direct']['safe_publish_auth'] = array(
		'label' => __( 'Safe Publish Authentication Configuration', 'safe-publish' ),
		'test'  => 'safe_publish_vip_site_health_test',
	);

	return $tests;
}

/**
 * Site Health test for Safe Publish authentication.
 *
 * @return array Site Health test result.
 */
function safe_publish_vip_site_health_test(): array {
	$shared_secret = safe_publish_vip_get_shared_secret();
	$secret_length = strlen( $shared_secret );

	if ( empty( $shared_secret ) ) {
		return array(
			'label'       => __( 'Safe Publish Authentication not configured', 'safe-publish' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Safe Publish', 'safe-publish' ),
				'color' => 'orange',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'The Safe Publish shared secret is not configured. If you plan to use Safe Publish, set the SAFE_PUBLISH_SHARED_SECRET environment variable.', 'safe-publish' )
			),
			'test'        => 'safe_publish_auth',
		);
	}

	if ( $secret_length < 32 ) {
		return array(
			'label'       => __( 'Safe Publish Authentication secret too short', 'safe-publish' ),
			'status'      => 'critical',
			'badge'       => array(
				'label' => __( 'Safe Publish', 'safe-publish' ),
				'color' => 'red',
			),
			'description' => sprintf(
				'<p>%s</p>',
				/* translators: %d: length of the shared secret in characters */
				sprintf( __( 'The Safe Publish shared secret is only %d characters long. For security, use at least 32 characters.', 'safe-publish' ), $secret_length )
			),
			'test'        => 'safe_publish_auth',
		);
	}

	return array(
		'label'       => __( 'Safe Publish Authentication configured correctly', 'safe-publish' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Safe Publish', 'safe-publish' ),
			'color' => 'green',
		),
		'description' => sprintf(
			'<p>%s</p>',
			/* translators: %d: length of the shared secret in characters */
			sprintf( __( 'Safe Publish authentication is properly configured with a %d-character shared secret.', 'safe-publish' ), $secret_length )
		),
		'test'        => 'safe_publish_auth',
	);
}

/**
 * Adds dashboard widget for Safe Publish authentication status.
 */
function safe_publish_vip_add_dashboard_widget(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'safe_publish_auth_status',
		'Safe Publish Authentication Status',
		'safe_publish_vip_dashboard_widget_content'
	);
}

/**
 * Dashboard widget content for Safe Publish authentication status.
 */
function safe_publish_vip_dashboard_widget_content(): void {
	$shared_secret = safe_publish_vip_get_shared_secret();
	$secret_length = strlen( $shared_secret );
	$stats         = get_option( 'safe_publish_auth_stats', array() );
	$recent_events = get_option( 'safe_publish_auth_log_events', array() );

	echo '<div class="safe-publish-dashboard-widget">';

	// Authentication Status.
	if ( empty( $shared_secret ) ) {
		echo '<p><span style="color: #d63638;">❌</span> <strong>' . esc_html__( 'Not Configured', 'safe-publish' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Set the <code>SAFE_PUBLISH_SHARED_SECRET</code> environment variable in VIP dashboard.', 'safe-publish' ) . '</p>';
		echo '<p><a href="https://dashboard.wpvip.com/" target="_blank">' . esc_html__( 'Open VIP Dashboard →', 'safe-publish' ) . '</a></p>';
	} elseif ( $secret_length < 32 ) {
		echo '<p><span style="color: #dba617;">⚠️</span> <strong>' . esc_html__( 'Secret Too Short', 'safe-publish' ) . '</strong></p>';
		echo '<p>' . sprintf(
			/* translators: %d is the current secret length in characters */
			esc_html__( 'Current length: %d characters. Recommend 32+ for security.', 'safe-publish' ),
			absint( $secret_length )
		) . '</p>';
	} else {
		echo '<p><span style="color: #00a32a;">✅</span> <strong>' . esc_html__( 'Properly Configured', 'safe-publish' ) . '</strong></p>';
		echo '<p><strong>✅ ' . esc_html__( 'Secret length:', 'safe-publish' ) . '</strong> ' . sprintf(
			/* translators: %d is the secret length in characters */
			esc_html__( '%d characters', 'safe-publish' ),
			absint( $secret_length )
		) . '</p>';
		echo '<p><strong>✅ ' . esc_html__( 'VIP 2FA Compliant:', 'safe-publish' ) . '</strong> ' . esc_html__( 'Uses capability-based authentication (no user creation)', 'safe-publish' ) . '</p>';
		echo '<p><strong>✅ ' . esc_html__( 'Editing Permissions:', 'safe-publish' ) . '</strong> ' . esc_html__( 'Enabled for Safe Publish authenticated requests', 'safe-publish' ) . '</p>';

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			echo '<p><a href="/wp-json/safe-publish/v1/auth-test" target="_blank">' . esc_html__( 'Test Authentication →', 'safe-publish' ) . '</a></p>';
		}
	}

	echo '<hr style="margin: 15px 0;">';

	// Authentication Statistics.
	if ( ! empty( $stats ) ) {
		echo '<h4 style="margin: 10px 0;">' . esc_html__( '📊 Authentication Statistics', 'safe-publish' ) . '</h4>';
		echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">';

		echo '<div>';
		echo '<strong>' . esc_html__( 'Total Requests:', 'safe-publish' ) . '</strong> ' . esc_html( $stats['total_requests'] ?? 0 );
		echo '<br><strong>' . esc_html__( 'Successful:', 'safe-publish' ) . '</strong> <span style="color: #00a32a;">' . esc_html( $stats['successful_auths'] ?? 0 ) . '</span>';
		echo '<br><strong>' . esc_html__( 'Failed:', 'safe-publish' ) . '</strong> <span style="color: #d63638;">' . esc_html( $stats['failed_auths'] ?? 0 ) . '</span>';
		echo '</div>';

		echo '<div>';
		if ( ! empty( $stats['last_success'] ) ) {
			echo '<strong>' . esc_html__( 'Last Success:', 'safe-publish' ) . '</strong><br><small>' . esc_html( $stats['last_success'] ) . '</small>';
		}
		if ( ! empty( $stats['last_failure'] ) ) {
			echo '<br><strong>' . esc_html__( 'Last Failure:', 'safe-publish' ) . '</strong><br><small style="color: #d63638;">' . esc_html( $stats['last_failure'] ) . '</small>';
		}
		echo '</div>';

		echo '</div>';

		// Success rate.
		$total = $stats['total_requests'] ?? 0;
		if ( $total > 0 ) {
			$success_rate = round( ( ( $stats['successful_auths'] ?? 0 ) / $total ) * 100, 1 );
			$color        = $success_rate >= 95 ? '#00a32a' : ( $success_rate >= 80 ? '#dba617' : '#d63638' );
			printf(
				'<p><strong>%s</strong> <span style="color: %s">%s%%</span></p>',
				esc_html__( 'Success Rate:', 'safe-publish' ),
				esc_attr( $color ),
				esc_html( number_format_i18n( $success_rate, 1 ) )
			);
		}
	}

	// Recent Events.
	if ( ! empty( $recent_events ) ) {
		echo '<hr style="margin: 15px 0;">';
		echo '<h4 style="margin: 10px 0;">' . esc_html__( '📋 Recent Authentication Events', 'safe-publish' ) . '</h4>';
		echo '<div style="max-height: 200px; overflow-y: auto; font-size: 12px;">';

		// Show last 10 events.
		$recent_events = array_slice( $recent_events, -10 );
		$recent_events = array_reverse( $recent_events );

		foreach ( $recent_events as $event ) {
			$event_type = $event['event'] ?? 'UNKNOWN';
			$timestamp  = $event['data']['timestamp'] ?? 'unknown';
			$ip         = $event['data']['ip'] ?? 'unknown';

			// Event icon and color.
			$icon  = '•';
			$color = '#666';
			if ( strpos( $event_type, 'SUCCESS' ) !== false ) {
				$icon  = '✅';
				$color = '#00a32a';
			} elseif ( strpos( $event_type, 'INVALID' ) !== false || strpos( $event_type, 'EXPIRED' ) !== false ) {
				$icon  = '❌';
				$color = '#d63638';
			} elseif ( strpos( $event_type, 'NO_SECRET' ) !== false ) {
				$icon  = '⚠️';
				$color = '#dba617';
			}

			echo '<div style="margin-bottom: 5px; padding: 5px; background: #f9f9f9; border-left: 3px solid ' . esc_attr( $color ) . ';">';
			echo '<span style="color: ' . esc_attr( $color ) . ';">' . esc_html( $icon ) . '</span> ';
			echo '<strong>' . esc_html( $event_type ) . '</strong> ';
			echo '<small style="color: #666;">(' . esc_html( $ip ) . ')</small>';
			echo '<br><small>' . esc_html( $timestamp ) . '</small>';

			// Show additional details for certain events.
			if ( isset( $event['data']['route'] ) ) {
				echo '<br><small><code>' . esc_html( $event['data']['route'] ) . '</code></small>';
			}
			if ( isset( $event['data']['method'] ) ) {
				echo ' <small><em>' . esc_html( $event['data']['method'] ) . '</em></small>';
			}

			echo '</div>';
		}

		echo '</div>';
	}

	echo '<hr style="margin: 15px 0;">';

	// Debug Information.
	echo '<details style="margin-top: 10px;">';
	echo '<summary style="cursor: pointer; font-weight: bold;">' . esc_html__( '🔧 Debug Information', 'safe-publish' ) . '</summary>';
	echo '<div style="margin-top: 10px; font-size: 12px;">';

	echo '<p><strong>' . esc_html__( 'Environment:', 'safe-publish' ) . '</strong> ' . ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ? esc_html__( 'VIP Production', 'safe-publish' ) : esc_html__( 'Development/Staging', 'safe-publish' ) ) . '</p>';
	echo '<p><strong>' . esc_html__( 'Debug Mode:', 'safe-publish' ) . '</strong> ' . ( defined( 'WP_DEBUG' ) && WP_DEBUG ? esc_html__( 'Enabled', 'safe-publish' ) : esc_html__( 'Disabled', 'safe-publish' ) ) . '</p>';
	echo '<p><strong>' . esc_html__( 'Secret Source:', 'safe-publish' ) . '</strong> ';

	if ( defined( 'SAFE_PUBLISH_SHARED_SECRET' ) && ! empty( SAFE_PUBLISH_SHARED_SECRET ) ) {
		echo esc_html__( 'Environment Variable (SAFE_PUBLISH_SHARED_SECRET)', 'safe-publish' );
	} elseif ( ! empty( getenv( 'SAFE_PUBLISH_SHARED_SECRET' ) ) ) {
		echo esc_html__( 'Environment Variable (getenv)', 'safe-publish' );
	} else {
		echo esc_html__( 'Not configured', 'safe-publish' );
	}
	echo '</p>';

	// Show log file locations.
	echo '<p><strong>' . esc_html__( 'Log Locations:', 'safe-publish' ) . '</strong></p>';
	echo '<ul style="margin-left: 20px; font-size: 11px;">';
	echo '<li>' . esc_html__( 'VIP Error Log:', 'safe-publish' ) . ' <code>/tmp/error_log</code></li>';
	echo '<li>' . esc_html__( 'WordPress Debug Log:', 'safe-publish' ) . ' <code>/wp-content/debug.log</code></li>';
	echo '<li>' . esc_html__( 'Database Events:', 'safe-publish' ) . ' <code>wp_options.safe_publish_auth_log_events</code></li>';
	echo '<li>' . esc_html__( 'New Relic:', 'safe-publish' ) . ' Custom Events → Safe_Publish_Auth_Event</li>';
	echo '</ul>';

	echo '</div>';
	echo '</details>';

	echo '<hr style="margin: 15px 0;">';
	echo '<p><small>' . esc_html__( 'MU-Plugin: Safe Publish VIP Authentication Handler with Enhanced Logging v1.1.0', 'safe-publish' ) . '</small></p>';
	echo '</div>';
}

/**
 * Enhances mu-plugins display to show Safe Publish plugin status.
 *
 * @param bool   $show_advanced_plugins Whether to show advanced plugins.
 * @param string $type                  Plugin type ('mustuse', 'dropins').
 * @return bool Show advanced plugins value, unchanged.
 */
function safe_publish_vip_enhance_mu_plugins_display( $show_advanced_plugins, $type ): bool {
	if ( 'mustuse' === $type && current_user_can( 'manage_options' ) ) {
		// Add custom CSS for better MU-plugin visibility.
		add_action( 'admin_footer', 'safe_publish_vip_add_mu_plugin_styles' );
	}

	return $show_advanced_plugins;
}

/**
 * Adds custom styles for MU-plugin display.
 */
function safe_publish_vip_add_mu_plugin_styles(): void {
	?>
	<style>
	.mu-plugin[data-plugin="safe-publish-auth.php"] {
		background-color: #f0f6fc;
		border-left: 4px solid #0073aa;
		padding: 10px;
	}
	.mu-plugin[data-plugin="safe-publish-auth.php"] .plugin-title strong {
		color: #0073aa;
	}
	.safe-publish-dashboard-widget {
		font-size: 13px;
	}
	.safe-publish-dashboard-widget code {
		background: #f1f1f1;
		padding: 2px 4px;
		border-radius: 3px;
	}
	</style>
	<?php
}

/**
 * Disables redirects for development hostnames.
 */
add_filter(
	'redirect_canonical',
	function ( $redirect_url, $requested_url ): string|false {
		// Get the hostname from the request.
		$requested_host = wp_parse_url( $requested_url, PHP_URL_HOST );

		// Allow both localhost and host.docker.internal for the same site.
		$allowed_hosts = array( 'localhost', 'host.docker.internal' );

		// If the requested host is in our allowed list and matches the site host pattern, don't redirect.
		if ( in_array( $requested_host, $allowed_hosts, true ) ) {
			// Check if it's the same port and path.
			$requested_port = wp_parse_url( $requested_url, PHP_URL_PORT );
			$site_port      = wp_parse_url( home_url(), PHP_URL_PORT );

			if ( $requested_port === $site_port ) {
				return false; // Disable redirect.
			}
		}

		return $redirect_url;
	},
	10,
	2
);

/**
 * Allows WordPress to accept requests from host.docker.internal.
 */
add_filter(
	'allowed_http_origins',
	function ( $origins ): array {
		$site_url  = home_url();
		$site_port = wp_parse_url( $site_url, PHP_URL_PORT );

		// Add host.docker.internal with the same port.
		if ( $site_port ) {
			$origins[] = 'http://host.docker.internal:' . $site_port;
		} else {
			$origins[] = 'http://host.docker.internal';
		}

		return $origins;
	}
);
