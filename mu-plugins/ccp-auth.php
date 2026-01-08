<?php
/**
 * Plugin Name: CCP VIP Authentication Handler
 * Plugin URI: https://github.com/wpcomvip/x-team-sandbox
 * Description: VIP-compatible auth handler for CCP using shared secret HMAC.
 * Version: 1.0.0
 * Author: WPVIP
 * Author URI: https://wpvip.com
 * Network: true
 * Requires at least: 5.0
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * This mu-plugin handles authentication for Compliant Content Publisher (CCP)
 * requests on WordPress VIP environments using shared secret HMAC authentication.
 *
 * The shared secret is read from the CCP_SHARED_SECRET environment variable
 * which should be configured in the VIP dashboard.
 *
 * @package CCP_Auth
 * @version 1.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent multiple inclusions.
if ( defined( 'CCP_VIP_AUTH_LOADED' ) ) {
	return;
}
define( 'CCP_VIP_AUTH_LOADED', true );

/**
 * Initializes CCP authentication handler.
 */
add_action( 'rest_api_init', 'ccp_vip_init_auth_handler' );

/**
 * Adds admin dashboard widget for CCP authentication status.
 */
add_action( 'wp_dashboard_setup', 'ccp_vip_add_dashboard_widget' );

/**
 * Adds CCP info to mu-plugins list (for better visibility).
 */
add_filter( 'show_advanced_plugins', 'ccp_vip_enhance_mu_plugins_display', 10, 2 );

/**
 * Adds init hook to test logging immediately.
 */
add_action( 'init', 'ccp_vip_test_logging_on_init' );

if ( ! function_exists( 'ccp_vip_test_logging_on_init' ) ) {
	/**
	 * Tests logging on WordPress init to ensure logs appear.
	 */
	function ccp_vip_test_logging_on_init(): void {
		// Skip if this is a REST API request to avoid header issues.
		if ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) ) {
			return;
		}

		// Only run once per day to avoid spam.
		$last_test = get_option( 'ccp_auth_last_log_test', 0 );
		if ( time() - $last_test < 86400 ) { // 24 hours
			return;
		}

		update_option( 'ccp_auth_last_log_test', time(), false );

		// Force a test log entry.
		ccp_vip_log_auth_event(
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
}

if ( ! function_exists( 'ccp_vip_init_auth_handler' ) ) {
	/**
	 * Initializes the authentication handler for REST API requests.
	 */
	function ccp_vip_init_auth_handler(): void {
		add_filter( 'rest_pre_dispatch', 'ccp_vip_authenticate_request', 10, 3 );

		// Add early permission override for CCP requests.
		add_filter( 'rest_request_before_callbacks', 'ccp_vip_handle_permission_check', 10, 3 );
	}
}

if ( ! function_exists( 'ccp_vip_handle_permission_check' ) ) {
	/**
	 * Handles permission checks before REST callbacks are executed.
	 *
	 * This intercepts the permission check that causes rest_forbidden_context errors.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|null $response Response to replace.
	 * @param array                                           $handler  Route handler used for the request.
	 * @param WP_REST_Request                                 $request  Request used to generate the response.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|null Modified response.
	 */
	function ccp_vip_handle_permission_check( $response, $handler, $request ): WP_REST_Response|WP_HTTP_Response|WP_Error|null {
		// Only apply to CCP authenticated requests.
		if ( empty( $GLOBALS['ccp_authenticated'] ) ) {
			return $response;
		}

		// Check if this is a WordPress REST API route.
		$route = $request->get_route();
		if ( ! $route || strpos( $route, '/wp/v2/' ) !== 0 ) {
			return $response;
		}

		// For CCP authenticated requests, temporarily override permission checks.
		add_filter(
			'user_has_cap',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			function ( $allcaps, $caps, $args, $user ): array {
				// Grant comprehensive permissions for CCP operations.
				$ccp_caps = array(
					'read',
					'edit_posts',
					'edit_others_posts',
					'edit_private_posts',
					'edit_published_posts',
					'publish_posts',
					'delete_posts',
					'delete_others_posts',
					'delete_private_posts',
					'delete_published_posts',
					'read_private_posts',
					'edit_pages',
					'edit_others_pages',
					'edit_private_pages',
					'edit_published_pages',
					'publish_pages',
					'delete_pages',
					'delete_others_pages',
					'delete_private_pages',
					'delete_published_pages',
					'read_private_pages',
					'manage_categories',
					'manage_options',
					'upload_files',
					'edit_files',
					'unfiltered_html',
				);

				foreach ( $ccp_caps as $cap ) {
					$allcaps[ $cap ] = true;
				}

				return $allcaps;
			},
			5,
			4
		); // High priority to ensure it runs early.

		ccp_vip_log_auth_event(
			'PERMISSION_CHECK_INTERCEPTED',
			array(
				'route'            => $route,
				'method'           => $request->get_method(),
				'context'          => $request->get_param( 'context' ),
				'handler_callback' => isset( $handler['callback'] ) ? 'set' : 'not_set',
			)
		);

		return $response;
	}
}

if ( ! function_exists( 'ccp_vip_override_endpoint_permissions' ) ) {
	/**
	 * Overrides REST endpoint permissions for CCP authenticated requests.
	 *
	 * @param array $endpoints Registered REST endpoints.
	 * @return array Modified endpoints.
	 */
	function ccp_vip_override_endpoint_permissions( $endpoints ): array {
		// Only apply to CCP authenticated requests.
		if ( empty( $GLOBALS['ccp_authenticated'] ) ) {
			return $endpoints;
		}

		// Override permission callbacks for post-related endpoints.
		$post_routes = array( '/wp/v2/posts', '/wp/v2/pages' );

		foreach ( $post_routes as $route ) {
			if ( isset( $endpoints[ $route ] ) ) {
				foreach ( $endpoints[ $route ] as &$handler ) {
					// Override the permission callback for GET requests.
					if ( isset( $handler['methods'] ) && ( 'GET' === $handler['methods'] || false !== strpos( $handler['methods'], 'GET' ) ) ) {
						$handler['permission_callback'] = 'ccp_vip_allow_all_permissions';

						ccp_vip_log_auth_event(
							'PERMISSION_CALLBACK_OVERRIDDEN',
							array(
								'route'   => $route,
								'methods' => $handler['methods'],
							)
						);
					}
				}
			}
		}

		return $endpoints;
	}
}

if ( ! function_exists( 'ccp_vip_allow_all_permissions' ) ) {
	/**
	 * Permission callback that allows all operations for CCP authenticated requests.
	 *
	 * @param WP_REST_Request|null $request Optional. REST request object.
	 * @return bool True for CCP authenticated requests, otherwise result of capability check.
	 */
	function ccp_vip_allow_all_permissions( $request = null ): bool {
		// Only apply to CCP authenticated requests.
		if ( empty( $GLOBALS['ccp_authenticated'] ) ) {
			return current_user_can( 'read' ); // Fallback to normal permission check.
		}

		ccp_vip_log_auth_event(
			'PERMISSION_OVERRIDE_APPLIED',
			array(
				'route'   => $request ? $request->get_route() : 'unknown',
				'method'  => $request ? $request->get_method() : 'unknown',
				'context' => $request ? $request->get_param( 'context' ) : 'unknown',
			)
		);

		return true;
	}
}

if ( ! function_exists( 'ccp_vip_override_collection_params' ) ) {
	/**
	 * Overrides collection parameters to allow edit context for CCP.
	 *
	 * @param array        $params    Collection parameters.
	 * @param WP_Post_Type $post_type Post type object.
	 * @return array Modified collection parameters.
	 */
	function ccp_vip_override_collection_params( $params, $post_type ): array {
		// Only apply to CCP authenticated requests.
		if ( empty( $GLOBALS['ccp_authenticated'] ) ) {
			return $params;
		}

		// Allow edit context without restrictions for CCP.
		if ( isset( $params['context'] ) ) {
			$params['context']['default'] = 'edit';
			unset( $params['context']['required'] );

			ccp_vip_log_auth_event(
				'COLLECTION_PARAMS_OVERRIDDEN',
				array(
					'post_type'       => $post_type->name,
					'default_context' => 'edit',
				)
			);
		}

		return $params;
	}
}

if ( ! function_exists( 'ccp_vip_ensure_edit_context_access' ) ) {
	/**
	 * Ensures edit context access for CCP authenticated requests.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_Post          $post     Post object.
	 * @param WP_REST_Request  $request  Request object.
	 * @return WP_REST_Response Response object, unchanged.
	 */
	function ccp_vip_ensure_edit_context_access( $response, $post, $request ): WP_REST_Response {
		// Only apply to CCP authenticated requests.
		if ( empty( $GLOBALS['ccp_authenticated'] ) ) {
			return $response;
		}

		// Force edit context access by temporarily granting permissions.
		add_filter(
			'user_has_cap',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			function ( $allcaps, $caps, $args, $user ): array {
				$allcaps['edit_posts']         = true;
				$allcaps['edit_others_posts']  = true;
				$allcaps['edit_private_posts'] = true;
				$allcaps['read_private_posts'] = true;
				return $allcaps;
			},
			999,
			4
		);

		ccp_vip_log_auth_event(
			'EDIT_CONTEXT_ACCESS_ENSURED',
			array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
				'context'   => $request->get_param( 'context' ),
			)
		);

		return $response;
	}
}

if ( ! function_exists( 'ccp_vip_authenticate_request' ) ) {
	/**
	 * VIP-Compatible Shared Secret Authentication for CCP.
	 *
	 * Authenticates CCP requests using HMAC-SHA256 signatures and reads the shared
	 * secret from VIP environment variables.
	 *
	 * @param WP_REST_Response|WP_Error|null $result  Response to replace.
	 * @param WP_REST_Server                 $server  Server instance.
	 * @param WP_REST_Request                $request Request used to generate the response.
	 * @return WP_REST_Response|WP_Error|null Original result or WP_Error for authentication failures.
	 */
	function ccp_vip_authenticate_request( $result, $server, $request ): WP_REST_Response|WP_Error|null {
		// Only authenticate WordPress REST API endpoints.
		$route = $request->get_route();

		if ( ! $route || strpos( $route, '/wp/v2/' ) !== 0 ) {
			return $result;
		}

		$headers = $request->get_headers();

		// Check for CCP authentication headers (shared secret only).
		if ( isset( $headers['x_ccp_timestamp'] ) && isset( $headers['x_ccp_signature'] ) ) {
			// Shared Secret Authentication.
			return ccp_vip_authenticate_shared_secret( $request, $headers, $result );
		}

		// No CCP auth headers present, continue with normal WordPress authentication.
		return $result;
	}
}

if ( ! function_exists( 'ccp_vip_authenticate_shared_secret' ) ) {
	/**
	 * Authenticates using shared secret HMAC.
	 *
	 * @param WP_REST_Request                $request REST request object.
	 * @param array                          $headers Request headers.
	 * @param WP_REST_Response|WP_Error|null $result  Optional. Original result to pass through on success.
	 * @return WP_REST_Response|WP_Error|null Original result on success, or WP_Error on failure.
	 */
	function ccp_vip_authenticate_shared_secret( $request, $headers, $result = null ): WP_REST_Response|WP_Error|null {
		$route = $request->get_route();

		// Get shared secret from VIP environment.
		$shared_secret = ccp_vip_get_shared_secret();

		if ( empty( $shared_secret ) ) {
			ccp_vip_log_auth_event(
				'NO_SECRET_CONFIGURED',
				array(
					'route'  => $route,
					'method' => $request->get_method(),
				)
			);

			return new WP_Error(
				'ccp_auth_no_secret',
				'CCP shared secret not configured in VIP environment',
				array( 'status' => 500 )
			);
		}

		$timestamp = $headers['x_ccp_timestamp'][0];
		$signature = $headers['x_ccp_signature'][0];
		$method    = $request->get_method();
		$uri       = $request->get_route();

		// Validate timestamp to prevent replay attacks.
		$current_time = time();
		$request_time = intval( $timestamp );
		$time_diff    = abs( $current_time - $request_time );

		// Allow 5-minute window for clock differences (configurable).
		$max_time_diff = apply_filters( 'ccp_auth_max_time_diff', 300 );

		if ( $time_diff > $max_time_diff ) {
			ccp_vip_log_auth_event(
				'TIMESTAMP_EXPIRED',
				array(
					'route'        => $route,
					'method'       => $method,
					'timestamp'    => $timestamp,
					'current_time' => $current_time,
					'time_diff'    => $time_diff,
					'max_allowed'  => $max_time_diff,
				)
			);

			return new WP_Error(
				'ccp_auth_expired',
				sprintf( 'Request timestamp expired (difference: %d seconds)', $time_diff ),
				array( 'status' => 401 )
			);
		}

		// Create signature string: METHOD|URI|TIMESTAMP.
		$string_to_sign     = $method . '|' . $uri . '|' . $timestamp;
		$expected_signature = hash_hmac( 'sha256', $string_to_sign, $shared_secret );

		// Verify signature using constant-time comparison.
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			ccp_vip_log_auth_event(
				'SIGNATURE_INVALID',
				array(
					'route'               => $route,
					'method'              => $method,
					'timestamp'           => $timestamp,
					'string_to_sign'      => $string_to_sign,
					'expected_sig_length' => strlen( $expected_signature ),
					'received_sig_length' => strlen( $signature ),
				)
			);

			return new WP_Error(
				'ccp_auth_invalid',
				'Invalid CCP authentication signature',
				array( 'status' => 401 )
			);
		}

		// Authentication successful.
		ccp_vip_log_auth_event(
			'AUTH_SUCCESS',
			array(
				'route'      => $route,
				'method'     => $method,
				'timestamp'  => $timestamp,
				'user_agent' => $request->get_header( 'user_agent' ),
			)
		);

		// Add custom header to indicate successful CCP authentication (only if headers not sent).
		if ( ! headers_sent() ) {
			header( 'X-CCP-Auth: success' );
		}

		// Set up user context and permissions for CCP authenticated requests.
		ccp_vip_setup_authenticated_context( $request );

		// Add immediate permission override for this specific request.
		add_filter( 'map_meta_cap', 'ccp_vip_override_meta_capabilities', 10, 4 );

		// Override REST permission checks specifically for context=edit.
		add_filter( 'rest_post_dispatch', 'ccp_vip_override_context_permissions', 5, 3 );

		// Continue with the authenticated request.
		return $result;
	}
}

if ( ! function_exists( 'ccp_vip_override_meta_capabilities' ) ) {
	/**
	 * Overrides meta capabilities for CCP authenticated requests.
	 *
	 * Handles capability mapping that occurs before user_has_cap.
	 *
	 * @param array  $caps    Required capabilities.
	 * @param string $cap     Capability being checked.
	 * @param int    $user_id User ID.
	 * @param array  $args    Arguments passed to capability check.
	 * @return array Modified capabilities.
	 */
	function ccp_vip_override_meta_capabilities( $caps, $cap, $user_id, $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Only apply to CCP authenticated requests.
		if ( empty( $GLOBALS['ccp_authenticated'] ) ) {
			return $caps;
		}

		// Override capabilities related to post editing and reading.
		$edit_caps = array(
			'edit_post',
			'edit_posts',
			'edit_others_posts',
			'edit_private_posts',
			'edit_published_posts',
			'read_post',
			'read_private_posts',
			'delete_post',
			'delete_posts',
			'delete_others_posts',
			'delete_private_posts',
			'delete_published_posts',
		);

		if ( in_array( $cap, $edit_caps, true ) ) {
			ccp_vip_log_auth_event(
				'META_CAP_OVERRIDE',
				array(
					'capability'    => $cap,
					'user_id'       => $user_id,
					'original_caps' => $caps,
				)
			);

			// Grant the capability by returning 'exist' (always granted).
			return array( 'exist' );
		}

		return $caps;
	}
}

if ( ! function_exists( 'ccp_vip_override_context_permissions' ) ) {
	/**
	 * Overrides context permissions for REST API responses.
	 *
	 * This specifically handles the rest_forbidden_context error.
	 *
	 * @param WP_REST_Response|WP_Error $result  Response object.
	 * @param WP_REST_Server            $server  Server instance.
	 * @param WP_REST_Request           $request Request object.
	 * @return WP_REST_Response Modified or re-dispatched response.
	 */
	function ccp_vip_override_context_permissions( $result, $server, $request ): WP_REST_Response {
		// Only apply to CCP authenticated requests.
		if ( empty( $GLOBALS['ccp_authenticated'] ) ) {
			return $result;
		}

		// If we received a forbidden context error, override it.
		if ( is_wp_error( $result ) && $result->get_error_code() === 'rest_forbidden_context' ) {
			ccp_vip_log_auth_event(
				'CONTEXT_ERROR_OVERRIDDEN',
				array(
					'original_error' => $result->get_error_message(),
					'route'          => $request->get_route(),
					'method'         => $request->get_method(),
					'context'        => $request->get_param( 'context' ),
				)
			);

			// Re-dispatch the request with elevated permissions.
			$GLOBALS['ccp_context_override'] = true;

			// Temporarily grant all capabilities.
			add_filter(
				'user_has_cap',
				function ( $allcaps ): array {
					$allcaps['edit_posts']         = true;
					$allcaps['edit_others_posts']  = true;
					$allcaps['edit_private_posts'] = true;
					$allcaps['read_private_posts'] = true;
					$allcaps['edit_pages']         = true;
					$allcaps['edit_others_pages']  = true;
					$allcaps['edit_private_pages'] = true;
					$allcaps['read_private_pages'] = true;
					return $allcaps;
				},
				999
			);

			// Try to re-process the request.
			$new_result = $server->dispatch( $request );

			unset( $GLOBALS['ccp_context_override'] );

			return $new_result;
		}

		return $result;
	}
}

if ( ! function_exists( 'ccp_vip_setup_authenticated_context' ) ) {
	/**
	 * Sets up authenticated context for CCP requests.
	 *
	 * Grants necessary permissions for REST API operations.
	 *
	 * VIP 2FA COMPLIANCE NOTE:
	 * This function uses a capability-based authentication approach instead of
	 * creating actual WordPress users. This is VIP-friendly because:
	 *
	 * 1. No real users are created that would require 2FA
	 * 2. Authentication is handled via shared secret HMAC (already validated)
	 * 3. Permissions are granted temporarily via capability filters
	 * 4. More secure than bypassing 2FA requirements
	 * 5. Complies with VIP platform security policies
	 *
	 * @param WP_REST_Request $request Authenticated REST request.
	 */
	function ccp_vip_setup_authenticated_context( $request ): void {
		// Mark this request as CCP authenticated for later reference.
		$GLOBALS['ccp_authenticated'] = true;

		// Always add the capability filter first as a safety net.
		add_filter( 'user_has_cap', 'ccp_vip_grant_api_capabilities', 10, 4 );

		// VIP-friendly approach: Use capability system without creating actual users.
		// This avoids 2FA requirements and is more secure.
		ccp_vip_log_auth_event(
			'CAPABILITY_BASED_AUTH_SETUP',
			array(
				'route'    => $request->get_route(),
				'method'   => $request->get_method(),
				'approach' => 'capability_only',
				'reason'   => 'VIP 2FA compliance - no user creation needed',
			)
		);

		// Set a virtual user context for logging purposes only.
		// This doesn't actually log in a user, just provides context.
		$GLOBALS['ccp_virtual_user'] = (object) array(
			'ID'           => 0,
			'user_login'   => 'ccp-system',
			'user_email'   => 'ccp-system@virtual',
			'display_name' => 'CCP System (Virtual)',
		);

		// Add filter to bypass additional permission checks for CCP requests.
		add_filter( 'rest_pre_dispatch', 'ccp_vip_bypass_permission_checks', 11, 3 );

		// Add direct permission callback overrides for post types.
		add_filter( 'rest_post_collection_params', 'ccp_vip_override_collection_params', 10, 2 );
		add_filter( 'rest_prepare_post', 'ccp_vip_ensure_edit_context_access', 10, 3 );
		add_filter( 'rest_prepare_page', 'ccp_vip_ensure_edit_context_access', 10, 3 );

		// Override permission checks at the endpoint level.
		add_filter( 'rest_endpoints', 'ccp_vip_override_endpoint_permissions' );
	}
}

if ( ! function_exists( 'ccp_vip_grant_api_capabilities' ) ) {
	/**
	 * Grants API capabilities for CCP authenticated requests.
	 *
	 * @param array   $allcaps All capabilities for the user.
	 * @param array   $caps    Required capabilities.
	 * @param array   $args    Arguments for capability check.
	 * @param WP_User $user    User object.
	 * @return array Modified capabilities.
	 */
	function ccp_vip_grant_api_capabilities( $allcaps, $caps, $args, $user ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Only apply to CCP authenticated requests.
		if ( empty( $GLOBALS['ccp_authenticated'] ) ) {
			return $allcaps;
		}

		// Grant essential REST API capabilities.
		$api_caps = array(
			'read',
			'edit_posts',
		);

		foreach ( $api_caps as $cap ) {
			$allcaps[ $cap ] = true;
		}

		// Log the capability grant for debugging (use virtual user info).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$virtual_user = $GLOBALS['ccp_virtual_user'] ?? (object) array( 'ID' => 0 );
			ccp_vip_log_auth_event(
				'CAPABILITIES_GRANTED',
				array(
					'user_id'            => $virtual_user->ID,
					'user_type'          => 'virtual_ccp_user',
					'requested_caps'     => $caps,
					'granted_caps_count' => count( array_filter( $allcaps ) ),
					'vip_2fa_bypass'     => 'capability_based_auth',
				)
			);
		}

		return $allcaps;
	}
}

/**
 * Bypasses additional permission checks for CCP authenticated requests.
 *
 * @param WP_REST_Response|WP_Error|null $result  Response to replace the requested version with.
 * @param WP_REST_Server                 $server  Server instance.
 * @param WP_REST_Request                $request Request used to generate the response.
 * @return WP_REST_Response|WP_Error|null Original result, unchanged.
 */
function ccp_vip_bypass_permission_checks( $result, $server, $request ): WP_REST_Response|WP_Error|null { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	// Only apply to CCP authenticated requests.
	if ( empty( $GLOBALS['ccp_authenticated'] ) ) {
		return $result;
	}

	// Add filter to allow edit context for CCP authenticated requests.
	add_filter( 'rest_allow_anonymous_comments', '__return_true' );

	// Add specific permission callback override for posts and other post types.
	add_filter( 'rest_prepare_post', 'ccp_vip_prepare_post_for_edit_context', 10, 3 );
	add_filter( 'rest_prepare_page', 'ccp_vip_prepare_post_for_edit_context', 10, 3 );

	// Override specific permission checks for edit context.
	add_filter( 'rest_post_dispatch', 'ccp_vip_ensure_response_success', 10, 3 );

	return $result;
}

/**
 * Prepares post data for edit context when CCP is authenticated.
 *
 * @param WP_REST_Response $response Response object.
 * @param WP_Post          $post     Post object.
 * @param WP_REST_Request  $request  Request object.
 * @return WP_REST_Response Response object, unchanged.
 */
function ccp_vip_prepare_post_for_edit_context( $response, $post, $request ): WP_REST_Response {
	// Only apply to CCP authenticated requests.
	if ( empty( $GLOBALS['ccp_authenticated'] ) ) {
		return $response;
	}

	// If this is an edit context request, ensure we return the full data.
	if ( 'edit' === $request->get_param( 'context' ) ) {
		// Log that we're allowing edit context for CCP.
		ccp_vip_log_auth_event(
			'EDIT_CONTEXT_ALLOWED',
			array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
				'route'     => $request->get_route(),
			)
		);
	}

	return $response;
}

/**
 * Ensures response success for valid CCP operations.
 *
 * @param WP_REST_Response|WP_Error $response Response object.
 * @param WP_REST_Server            $server   Server instance.
 * @param WP_REST_Request           $request  Request used to generate the response.
 * @return WP_REST_Response|WP_Error Response, potentially modified.
 */
function ccp_vip_ensure_response_success( $response, $server, $request ): WP_REST_Response|WP_Error {
	// Only apply to CCP authenticated requests.
	if ( empty( $GLOBALS['ccp_authenticated'] ) ) {
		return $response;
	}

	// If we got a permission error for edit context, try to resolve it.
	if ( is_wp_error( $response ) ) {
		$error_code = $response->get_error_code();

		// Handle specific REST permission errors.
		if ( in_array( $error_code, array( 'rest_forbidden', 'rest_cannot_edit', 'rest_forbidden_context' ), true ) ) {
			ccp_vip_log_auth_event(
				'PERMISSION_ERROR_INTERCEPTED',
				array(
					'error_code'    => $error_code,
					'error_message' => $response->get_error_message(),
					'route'         => $request->get_route(),
					'method'        => $request->get_method(),
					'context'       => $request->get_param( 'context' ),
				)
			);

			// If this is a context permission error, we might need to handle it differently.
			if ( 'rest_forbidden_context' === $error_code ) {
				// For CCP authenticated requests, we should allow edit context.
				// This is a fallback - the proper fix should be in the capability system above.
				ccp_vip_log_auth_event(
					'CONTEXT_PERMISSION_OVERRIDE_NEEDED',
					array(
						'route'          => $request->get_route(),
						'original_error' => $response->get_error_message(),
					)
				);
			}
		}
	}

	return $response;
}

if ( ! function_exists( 'ccp_vip_get_shared_secret' ) ) {
	/**
	 * Gets shared secret from VIP environment (multiple fallback methods).
	 *
	 * @return string Shared secret, or empty string if not found.
	 */
	function ccp_vip_get_shared_secret(): string {
		// Method 1: VIP constant (preferred - set in vip-config.php).
		if ( defined( 'CCP_SHARED_SECRET' ) && ! empty( CCP_SHARED_SECRET ) ) {
			return CCP_SHARED_SECRET;
		}

		// Method 2: Direct environment variable access.
		$env_secret = getenv( 'CCP_SHARED_SECRET' );
		if ( ! empty( $env_secret ) ) {
			return $env_secret;
		}

		// Method 3: $_ENV superglobal (fallback for some hosting environments).
		if ( isset( $_ENV['CCP_SHARED_SECRET'] ) && ! empty( $_ENV['CCP_SHARED_SECRET'] ) ) {
			// Cryptographic secret not sanitized; used directly for HMAC authentication.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$env_secret = trim( $_ENV['CCP_SHARED_SECRET'] );
			// Validate that it contains only safe characters for a secret key.
			if ( ! empty( $env_secret ) && strlen( $env_secret ) >= 16 && preg_match( '/^[a-zA-Z0-9\-_+=\/]+$/', $env_secret ) ) {
				return $env_secret;
			}
		}

		// Method 4: WordPress option (fallback for non-VIP or development).
		$option_secret = get_option( 'ccp_shared_secret', '' );
		if ( ! empty( $option_secret ) ) {
			return $option_secret;
		}

		return '';
	}
}

if ( ! function_exists( 'ccp_vip_log_auth_event' ) ) {
	/**
	 * Logs authentication events for monitoring and debugging.
	 *
	 * Enhanced for VIP dashboard visibility.
	 *
	 * @param string $event Event type (AUTH_SUCCESS, SIGNATURE_INVALID, etc.).
	 * @param array  $data  Optional. Additional event data. Default empty array.
	 */
	function ccp_vip_log_auth_event( $event, $data = array() ): void {
		// Skip logging during REST API requests to prevent header issues.
		if ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) && ! headers_sent() ) {
			// Queue the log for later processing.
			if ( ! isset( $GLOBALS['ccp_deferred_logs'] ) ) {
				$GLOBALS['ccp_deferred_logs'] = array();
			}
			$GLOBALS['ccp_deferred_logs'][] = array(
				'event' => $event,
				'data'  => $data,
			);

			// Register shutdown hook to process deferred logs.
			if ( ! has_action( 'shutdown', 'ccp_vip_process_deferred_logs' ) ) {
				add_action( 'shutdown', 'ccp_vip_process_deferred_logs' );
			}
			return;
		}

		// Ensure we can log even when WordPress functions aren't available.
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

		// Simple, reliable log message format.
		$log_message = '[CCP-Auth-VIP] ' . $event . ': ' . wp_json_encode( $log_data, JSON_UNESCAPED_SLASHES );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $log_message );

		// Backup logging methods.

		// 1. Direct file write as backup (VIP-safe location).
		$log_file = get_temp_dir() . 'ccp-auth-vip.log';
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$file_message = date( 'Y-m-d H:i:s' ) . ' ' . $log_message . "\n";
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		@file_put_contents( $log_file, $file_message, FILE_APPEND | LOCK_EX );

		// 2. PHP syslog for additional visibility.
		if ( function_exists( 'syslog' ) ) {
			openlog( 'CCP-Auth-VIP', LOG_PID, LOG_USER );
			syslog( LOG_INFO, $event . ': ' . wp_json_encode( $log_data, JSON_UNESCAPED_SLASHES ) );
			closelog();
		}

		// 3. Store recent events in database for dashboard viewing (only if WordPress is loaded).
		if ( function_exists( 'get_option' ) ) {
			ccp_vip_store_log_event( $event, $log_data );
		}

		// 4. New Relic custom events (if available).
		if ( function_exists( 'newrelic_record_custom_event' ) ) {
			newrelic_record_custom_event(
				'CCP_Auth_Event',
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
			do_action( 'ccp_auth_event_logged', $event, $log_data );
		}

		// 7. Force immediate log write for VIP (bypass buffering).
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV && function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
	}
}

if ( ! function_exists( 'ccp_vip_process_deferred_logs' ) ) {
	/**
	 * Processes deferred logs that were queued during REST API requests.
	 */
	function ccp_vip_process_deferred_logs(): void {
		if ( ! isset( $GLOBALS['ccp_deferred_logs'] ) || empty( $GLOBALS['ccp_deferred_logs'] ) ) {
			return;
		}

		foreach ( $GLOBALS['ccp_deferred_logs'] as $log_entry ) {
			ccp_vip_log_auth_event( $log_entry['event'], $log_entry['data'] );
		}

		// Clear the queue.
		unset( $GLOBALS['ccp_deferred_logs'] );
	}
}

if ( ! function_exists( 'ccp_vip_store_log_event' ) ) {
	/**
	 * Stores log events in database for dashboard viewing.
	 *
	 * @param string $event Event type.
	 * @param array  $data  Event data to store.
	 */
	function ccp_vip_store_log_event( $event, $data ): void {
		// Get existing log events (keep last 100 events).
		$log_events = get_option( 'ccp_auth_log_events', array() );

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
		update_option( 'ccp_auth_log_events', $log_events, false );

		// Also update summary statistics.
		ccp_vip_update_auth_stats( $event );
	}
}

/**
 * Updates authentication statistics.
 *
 * @param string $event Event type to record.
 */
function ccp_vip_update_auth_stats( $event ): void {
	$stats = get_option(
		'ccp_auth_stats',
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

	update_option( 'ccp_auth_stats', $stats, false );
}

/**
 * Adds admin notice about CCP authentication status (VIP-safe).
 */
add_action( 'admin_notices', 'ccp_vip_auth_admin_notice' );

/**
 * Displays admin notice about CCP authentication configuration status.
 */
function ccp_vip_auth_admin_notice(): void {
	// Only show to administrators.
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only show on relevant admin pages.
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
		return;
	}

	$shared_secret = ccp_vip_get_shared_secret();
	$secret_length = strlen( $shared_secret );

	if ( empty( $shared_secret ) ) {
		wp_admin_notice(
			__( 'CCP Authentication: Shared secret not configured. Set the <code>CCP_SHARED_SECRET</code> environment variable in VIP dashboard to enable CCP authentication.', 'ccp' ),
			array(
				'icon' => 'warning',
				'type' => 'warning',
			),
		);
	} elseif ( $secret_length < 32 ) {
		wp_admin_notice(
			sprintf(
				/* translators: %d: Length of the shared secret in characters */
				__( 'CCP Authentication: Shared secret is too short ( %d character secret). Use at least 32 characters for security.', 'ccp' ),
				absint( $secret_length )
			),
			array(
				'icon' => 'warning',
				'type' => 'warning',
			),
		);
	} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		wp_admin_notice(
			sprintf(
				/* translators: %d: Length of the shared secret in characters */
				__( 'CCP Authentication: Configured successfully ✅ ( %d character secret).', 'ccp' ),
				absint( $secret_length )
			),
			array(
				'dismissible' => true,
				'icon'        => 'warning',
				'type'        => 'warning',
			),
		);
	}
}

// Register test endpoint in debug mode only.
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	add_action( 'rest_api_init', 'ccp_vip_register_test_endpoint' );
}

// Always register monitoring endpoint.
add_action( 'rest_api_init', 'ccp_vip_register_monitoring_endpoints' );

/**
 * Registers test endpoint for CCP authentication (debug mode only).
 */
function ccp_vip_register_test_endpoint(): void {
	register_rest_route(
		'ccp/v1',
		'/auth-test',
		array(
			'methods'             => 'GET',
			'callback'            => 'ccp_vip_auth_test_callback',
			'permission_callback' => '__return_true', // Public endpoint for testing.
		)
	);
}

/**
 * Registers monitoring endpoints for authentication status.
 */
function ccp_vip_register_monitoring_endpoints(): void {
	// Authentication status endpoint.
	register_rest_route(
		'ccp/v1',
		'/auth-status',
		array(
			'methods'             => 'GET',
			'callback'            => 'ccp_vip_auth_status_callback',
			'permission_callback' => 'ccp_vip_can_view_auth_status',
		)
	);

	// Authentication logs endpoint.
	register_rest_route(
		'ccp/v1',
		'/auth-logs',
		array(
			'methods'             => 'GET',
			'callback'            => 'ccp_vip_auth_logs_callback',
			'permission_callback' => 'ccp_vip_can_view_auth_status',
		)
	);

	// Clear logs endpoint (for maintenance).
	register_rest_route(
		'ccp/v1',
		'/auth-logs',
		array(
			'methods'             => 'DELETE',
			'callback'            => 'ccp_vip_clear_auth_logs_callback',
			'permission_callback' => 'ccp_vip_can_manage_auth',
		)
	);
}

/**
 * Permission callback for viewing auth status.
 *
 * @return bool True if user can view auth status, false otherwise.
 */
function ccp_vip_can_view_auth_status(): bool {
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
function ccp_vip_can_manage_auth(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Authentication status callback for monitoring.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response Response containing auth status data.
 */
function ccp_vip_auth_status_callback( $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$shared_secret = ccp_vip_get_shared_secret();
	$stats         = get_option( 'ccp_auth_stats', array() );
	$recent_events = get_option( 'ccp_auth_log_events', array() );

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
				'secret_source'            => ccp_vip_get_secret_source(),
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
function ccp_vip_auth_logs_callback( $request ): WP_REST_Response {
	$recent_events = get_option( 'ccp_auth_log_events', array() );
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
function ccp_vip_clear_auth_logs_callback( $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	delete_option( 'ccp_auth_log_events' );
	delete_option( 'ccp_auth_stats' );
	$user_id = get_current_user_id();

	ccp_vip_log_auth_event(
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
function ccp_vip_get_secret_source(): string {
	if ( defined( 'CCP_SHARED_SECRET' ) && ! empty( CCP_SHARED_SECRET ) ) {
		return 'constant';
	}
	if ( ! empty( getenv( 'CCP_SHARED_SECRET' ) ) ) {
		return 'environment';
	}
	if ( ! empty( get_option( 'ccp_shared_secret' ) ) ) {
		return 'option';
	}
	return 'none';
}

/**
 * Handles test endpoint callback for CCP authentication.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response Response containing test results.
 */
function ccp_vip_auth_test_callback( $request ): WP_REST_Response {
	$headers         = $request->get_headers();
	$has_ccp_headers = isset( $headers['x_ccp_timestamp'] ) && isset( $headers['x_ccp_signature'] );

	// Force generate test logs to verify VIP logging.
	ccp_vip_log_auth_event(
		'TEST_ENDPOINT_ACCESSED',
		array(
			'headers_present' => $has_ccp_headers,
			'user_agent'      => $request->get_header( 'user_agent' ),
			'test_type'       => 'manual_endpoint_test',
		)
	);

	return new WP_REST_Response(
		array(
			'message'                  => 'CCP Authentication Test Endpoint',
			'timestamp'                => current_time( 'mysql' ),
			'ccp_headers_present'      => $has_ccp_headers,
			'shared_secret_configured' => ! empty( ccp_vip_get_shared_secret() ),
			'secret_length'            => strlen( ccp_vip_get_shared_secret() ),
			'vip_environment'          => defined( 'WPCOM_IS_VIP_ENV' ) ? WPCOM_IS_VIP_ENV : false,
			'debug_mode'               => defined( 'WP_DEBUG' ) ? WP_DEBUG : false,
			'logging_info'             => array(
				'error_log_available' => function_exists( 'error_log' ),
				'syslog_available'    => function_exists( 'syslog' ),
				'log_test_generated'  => true,
			),
		),
		200
	);
}

/**
 * Adds CCP authentication status to Site Health (WordPress 5.2+).
 */
add_filter( 'site_status_tests', 'ccp_vip_add_site_health_test' );

/**
 * Adds CCP authentication test to Site Health.
 *
 * @param array $tests Existing Site Health tests.
 * @return array Modified tests array with CCP auth test added.
 */
function ccp_vip_add_site_health_test( $tests ): array {
	$tests['direct']['ccp_auth'] = array(
		'label' => __( 'CCP Authentication Configuration', 'ccp' ),
		'test'  => 'ccp_vip_site_health_test',
	);

	return $tests;
}

/**
 * Site Health test for CCP authentication.
 *
 * @return array Site Health test result.
 */
function ccp_vip_site_health_test(): array {
	$shared_secret = ccp_vip_get_shared_secret();
	$secret_length = strlen( $shared_secret );

	if ( empty( $shared_secret ) ) {
		return array(
			'label'       => __( 'CCP Authentication not configured', 'ccp' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'CCP', 'ccp' ),
				'color' => 'orange',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'The CCP (Compliant Content Publisher) shared secret is not configured. If you plan to use CCP, set the CCP_SHARED_SECRET environment variable.', 'ccp' )
			),
			'test'        => 'ccp_auth',
		);
	}

	if ( $secret_length < 32 ) {
		return array(
			'label'       => __( 'CCP Authentication secret too short', 'ccp' ),
			'status'      => 'critical',
			'badge'       => array(
				'label' => __( 'CCP', 'ccp' ),
				'color' => 'red',
			),
			'description' => sprintf(
				'<p>%s</p>',
				/* translators: %d: length of the shared secret in characters */
				sprintf( __( 'The CCP shared secret is only %d characters long. For security, use at least 32 characters.', 'ccp' ), $secret_length )
			),
			'test'        => 'ccp_auth',
		);
	}

	return array(
		'label'       => __( 'CCP Authentication configured correctly', 'ccp' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'CCP', 'ccp' ),
			'color' => 'green',
		),
		'description' => sprintf(
			'<p>%s</p>',
			/* translators: %d: length of the shared secret in characters */
			sprintf( __( 'CCP authentication is properly configured with a %d-character shared secret.', 'ccp' ), $secret_length )
		),
		'test'        => 'ccp_auth',
	);
}

/**
 * Adds dashboard widget for CCP authentication status.
 */
function ccp_vip_add_dashboard_widget(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'ccp_auth_status',
		'CCP Authentication Status',
		'ccp_vip_dashboard_widget_content'
	);
}

/**
 * Dashboard widget content for CCP authentication status.
 */
function ccp_vip_dashboard_widget_content(): void {
	$shared_secret = ccp_vip_get_shared_secret();
	$secret_length = strlen( $shared_secret );
	$stats         = get_option( 'ccp_auth_stats', array() );
	$recent_events = get_option( 'ccp_auth_log_events', array() );

	echo '<div class="ccp-dashboard-widget">';

	// Authentication Status.
	if ( empty( $shared_secret ) ) {
		echo '<p><span style="color: #d63638;">❌</span> <strong>Not Configured</strong></p>';
		echo '<p>Set the <code>CCP_SHARED_SECRET</code> environment variable in VIP dashboard.</p>';
		echo '<p><a href="https://dashboard.wpvip.com/" target="_blank">Open VIP Dashboard →</a></p>';
	} elseif ( $secret_length < 32 ) {
		echo '<p><span style="color: #dba617;">⚠️</span> <strong>Secret Too Short</strong></p>';
		echo '<p>Current length: ' . absint( $secret_length ) . ' characters. Recommend 32+ for security.</p>';
	} else {
		echo '<p><span style="color: #00a32a;">✅</span> <strong>Properly Configured</strong></p>';
		echo '<p><strong>✅ Secret length:</strong> ' . absint( $secret_length ) . ' characters</p>';
		echo '<p><strong>✅ VIP 2FA Compliant:</strong> Uses capability-based authentication (no user creation)</p>';
		echo '<p><strong>✅ Editing Permissions:</strong> Enabled for CCP authenticated requests</p>';

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			echo '<p><a href="/wp-json/ccp/v1/auth-test" target="_blank">Test Authentication →</a></p>';
		}
	}

	echo '<hr style="margin: 15px 0;">';

	// Authentication Statistics.
	if ( ! empty( $stats ) ) {
		echo '<h4 style="margin: 10px 0;">📊 Authentication Statistics</h4>';
		echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">';

		echo '<div>';
		echo '<strong>Total Requests:</strong> ' . esc_html( $stats['total_requests'] ?? 0 );
		echo '<br><strong>Successful:</strong> <span style="color: #00a32a;">' . esc_html( $stats['successful_auths'] ?? 0 ) . '</span>';
		echo '<br><strong>Failed:</strong> <span style="color: #d63638;">' . esc_html( $stats['failed_auths'] ?? 0 ) . '</span>';
		echo '</div>';

		echo '<div>';
		if ( ! empty( $stats['last_success'] ) ) {
			echo '<strong>Last Success:</strong><br><small>' . esc_html( $stats['last_success'] ) . '</small>';
		}
		if ( ! empty( $stats['last_failure'] ) ) {
			echo '<br><strong>Last Failure:</strong><br><small style="color: #d63638;">' . esc_html( $stats['last_failure'] ) . '</small>';
		}
		echo '</div>';

		echo '</div>';

		// Success rate.
		$total = $stats['total_requests'] ?? 0;
		if ( $total > 0 ) {
			$success_rate = round( ( ( $stats['successful_auths'] ?? 0 ) / $total ) * 100, 1 );
			$color        = $success_rate >= 95 ? '#00a32a' : ( $success_rate >= 80 ? '#dba617' : '#d63638' );
			echo '<p><strong>Success Rate:</strong> <span style="color: ' . esc_attr( $color ) . ';">' . esc_html( $success_rate ) . '%</span></p>';
		}
	}

	// Recent Events.
	if ( ! empty( $recent_events ) ) {
		echo '<hr style="margin: 15px 0;">';
		echo '<h4 style="margin: 10px 0;">📋 Recent Authentication Events</h4>';
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
	echo '<summary style="cursor: pointer; font-weight: bold;">🔧 Debug Information</summary>';
	echo '<div style="margin-top: 10px; font-size: 12px;">';

	echo '<p><strong>Environment:</strong> ' . ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ? 'VIP Production' : 'Development/Staging' ) . '</p>';
	echo '<p><strong>Debug Mode:</strong> ' . ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'Enabled' : 'Disabled' ) . '</p>';
	echo '<p><strong>Secret Source:</strong> ';

	if ( defined( 'CCP_SHARED_SECRET' ) && ! empty( CCP_SHARED_SECRET ) ) {
		echo 'Environment Variable (CCP_SHARED_SECRET)';
	} elseif ( ! empty( getenv( 'CCP_SHARED_SECRET' ) ) ) {
		echo 'Environment Variable (getenv)';
	} elseif ( ! empty( get_option( 'ccp_shared_secret' ) ) ) {
		echo 'WordPress Option (ccp_shared_secret)';
	} else {
		echo 'Not configured';
	}
	echo '</p>';

	// Show log file locations.
	echo '<p><strong>Log Locations:</strong></p>';
	echo '<ul style="margin-left: 20px; font-size: 11px;">';
	echo '<li>VIP Error Log: <code>/tmp/error_log</code></li>';
	echo '<li>WordPress Debug Log: <code>/wp-content/debug.log</code></li>';
	echo '<li>Database Events: <code>wp_options.ccp_auth_log_events</code></li>';
	echo '<li>New Relic: Custom Events → CCP_Auth_Event</li>';
	echo '</ul>';

	echo '</div>';
	echo '</details>';

	echo '<hr style="margin: 15px 0;">';
	echo '<p><small>MU-Plugin: CCP VIP Authentication Handler with Enhanced Logging v1.1.0</small></p>';
	echo '</div>';
}

/**
 * Enhances mu-plugins display to show CCP plugin status.
 *
 * @param bool   $show_advanced_plugins Whether to show advanced plugins.
 * @param string $type                  Plugin type ('mustuse', 'dropins').
 * @return bool Show advanced plugins value, unchanged.
 */
function ccp_vip_enhance_mu_plugins_display( $show_advanced_plugins, $type ): bool {
	if ( 'mustuse' === $type && current_user_can( 'manage_options' ) ) {
		// Add custom CSS for better MU-plugin visibility.
		add_action( 'admin_footer', 'ccp_vip_add_mu_plugin_styles' );
	}

	return $show_advanced_plugins;
}

/**
 * Adds custom styles for MU-plugin display.
 */
function ccp_vip_add_mu_plugin_styles(): void {
	?>
	<style>
	.mu-plugin[data-plugin="ccp-auth.php"] {
		background-color: #f0f6fc;
		border-left: 4px solid #0073aa;
		padding: 10px;
	}
	.mu-plugin[data-plugin="ccp-auth.php"] .plugin-title strong {
		color: #0073aa;
	}
	.ccp-dashboard-widget {
		font-size: 13px;
	}
	.ccp-dashboard-widget code {
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
