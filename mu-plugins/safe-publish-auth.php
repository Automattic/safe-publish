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
	 */
	function safe_publish_vip_test_logging_on_init(): void {
		// Skip if this is a REST API request to avoid header issues.
		if ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) ) {
			return;
		}

		// Only run once per day to avoid spam.
		$last_test = get_option( 'safe_publish_auth_last_log_test', 0 );
		if ( time() - $last_test < 86400 ) { // 24 hours
			return;
		}

		update_option( 'safe_publish_auth_last_log_test', time(), false );

		// Force a test log entry.
		safe_publish_vip_log_auth_event(
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
	 * This intercepts the permission check that causes rest_forbidden_context errors.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|null $response Response to replace.
	 * @param array                                           $handler  Route handler used for the request.
	 * @param WP_REST_Request                                 $request  Request used to generate the response.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|null Modified response.
	 */
	function safe_publish_vip_handle_permission_check( $response, $handler, $request ): WP_REST_Response|WP_HTTP_Response|WP_Error|null {
		// Only apply to Safe Publish authenticated requests.
		if ( empty( $GLOBALS['safe_publish_authenticated'] ) ) {
			return $response;
		}

		// Check if this is a WordPress REST API route.
		$route = $request->get_route();
		if ( ! $route || strpos( $route, '/wp/v2/' ) !== 0 ) {
			return $response;
		}

		// For Safe Publish authenticated requests, temporarily override permission checks.
		add_filter(
			'user_has_cap',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			function ( $allcaps, $caps, $args, $user ): array {
				// Grant comprehensive permissions for Safe Publish operations.
				$safe_publish_caps = array(
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

				foreach ( $safe_publish_caps as $cap ) {
					$allcaps[ $cap ] = true;
				}

				return $allcaps;
			},
			5,
			4
		); // High priority to ensure it runs early.

		safe_publish_vip_log_auth_event(
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

if ( ! function_exists( 'safe_publish_vip_override_endpoint_permissions' ) ) {
	/**
	 * Overrides REST endpoint permissions for Safe Publish authenticated requests.
	 *
	 * @param array $endpoints Registered REST endpoints.
	 * @return array Modified endpoints.
	 */
	function safe_publish_vip_override_endpoint_permissions( $endpoints ): array {
		// Only apply to Safe Publish authenticated requests.
		if ( empty( $GLOBALS['safe_publish_authenticated'] ) ) {
			return $endpoints;
		}

		// Override permission callbacks for post-related endpoints.
		$post_routes = array( '/wp/v2/posts', '/wp/v2/pages' );

		foreach ( $post_routes as $route ) {
			if ( isset( $endpoints[ $route ] ) ) {
				foreach ( $endpoints[ $route ] as &$handler ) {
					// Override the permission callback for GET requests.
					if ( isset( $handler['methods'] ) && ( 'GET' === $handler['methods'] || false !== strpos( $handler['methods'], 'GET' ) ) ) {
						$handler['permission_callback'] = 'safe_publish_vip_allow_all_permissions';

						safe_publish_vip_log_auth_event(
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

if ( ! function_exists( 'safe_publish_vip_allow_all_permissions' ) ) {
	/**
	 * Permission callback that allows all operations for Safe Publish authenticated requests.
	 *
	 * @param WP_REST_Request|null $request Optional. REST request object.
	 * @return bool True for Safe Publish authenticated requests, otherwise result of capability check.
	 */
	function safe_publish_vip_allow_all_permissions( $request = null ): bool {
		// Only apply to Safe Publish authenticated requests.
		if ( empty( $GLOBALS['safe_publish_authenticated'] ) ) {
			return current_user_can( 'read' ); // Fallback to normal permission check.
		}

		safe_publish_vip_log_auth_event(
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

if ( ! function_exists( 'safe_publish_vip_override_collection_params' ) ) {
	/**
	 * Overrides collection parameters to allow edit context for Safe Publish.
	 *
	 * @param array        $params    Collection parameters.
	 * @param WP_Post_Type $post_type Post type object.
	 * @return array Modified collection parameters.
	 */
	function safe_publish_vip_override_collection_params( $params, $post_type ): array {
		// Only apply to Safe Publish authenticated requests.
		if ( empty( $GLOBALS['safe_publish_authenticated'] ) ) {
			return $params;
		}

		// Allow edit context without restrictions for Safe Publish.
		if ( isset( $params['context'] ) ) {
			$params['context']['default'] = 'edit';
			unset( $params['context']['required'] );

			safe_publish_vip_log_auth_event(
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

if ( ! function_exists( 'safe_publish_vip_ensure_edit_context_access' ) ) {
	/**
	 * Ensures edit context access for Safe Publish authenticated requests.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_Post          $post     Post object.
	 * @param WP_REST_Request  $request  Request object.
	 * @return WP_REST_Response Response object, unchanged.
	 */
	function safe_publish_vip_ensure_edit_context_access( $response, $post, $request ): WP_REST_Response {
		// Only apply to Safe Publish authenticated requests.
		if ( empty( $GLOBALS['safe_publish_authenticated'] ) ) {
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

		safe_publish_vip_log_auth_event(
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

if ( ! function_exists( 'safe_publish_vip_authenticate_request' ) ) {
	/**
	 * VIP-Compatible Shared Secret Authentication for Safe Publish.
	 *
	 * Authenticates Safe Publish requests using HMAC-SHA256 signatures and reads the shared
	 * secret from VIP environment variables.
	 *
	 * @param WP_REST_Response|WP_Error|null $result  Response to replace.
	 * @param WP_REST_Server                 $server  Server instance.
	 * @param WP_REST_Request                $request Request used to generate the response.
	 * @return WP_REST_Response|WP_Error|null Original result or WP_Error for authentication failures.
	 */
	function safe_publish_vip_authenticate_request( $result, $server, $request ): WP_REST_Response|WP_Error|null {
		// Only authenticate WordPress REST API endpoints.
		$route = $request->get_route();

		if ( ! $route || strpos( $route, '/wp/v2/' ) !== 0 ) {
			return $result;
		}

		$headers = $request->get_headers();

		// Check for Safe Publish authentication headers (shared secret only).
		if ( isset( $headers['x_safe_publish_timestamp'] ) && isset( $headers['x_safe_publish_signature'] ) ) {
			// Shared Secret Authentication.
			return safe_publish_vip_authenticate_shared_secret( $request, $headers, $result );
		}

		// No Safe Publish auth headers present, continue with normal WordPress authentication.
		return $result;
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
	 */
	function safe_publish_vip_authenticate_shared_secret( $request, $headers, $result = null ): WP_REST_Response|WP_Error|null {
		$route = $request->get_route();

		// Get shared secret from VIP environment.
		$shared_secret = safe_publish_vip_get_shared_secret();

		if ( empty( $shared_secret ) ) {
			safe_publish_vip_log_auth_event(
				'NO_SECRET_CONFIGURED',
				array(
					'route'  => $route,
					'method' => $request->get_method(),
				)
			);

			return new WP_Error(
				'safe_publish_auth_no_secret',
				'Safe Publish shared secret not configured in VIP environment',
				array( 'status' => 500 )
			);
		}

		$timestamp = $headers['x_safe_publish_timestamp'][0];
		$signature = $headers['x_safe_publish_signature'][0];
		$method    = $request->get_method();
		$uri       = $request->get_route();

		// Validate timestamp to prevent replay attacks.
		$current_time = time();
		$request_time = intval( $timestamp );
		$time_diff    = abs( $current_time - $request_time );

		// Allow 5-minute window for clock differences (configurable).
		$max_time_diff = apply_filters( 'safe_publish_auth_max_time_diff', 300 );

		if ( $time_diff > $max_time_diff ) {
			safe_publish_vip_log_auth_event(
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
				'safe_publish_auth_expired',
				sprintf( 'Request timestamp expired (difference: %d seconds)', $time_diff ),
				array( 'status' => 401 )
			);
		}

		// Create signature string: METHOD|URI|TIMESTAMP.
		$string_to_sign     = $method . '|' . $uri . '|' . $timestamp;
		$expected_signature = hash_hmac( 'sha256', $string_to_sign, $shared_secret );

		// Verify signature using constant-time comparison.
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			safe_publish_vip_log_auth_event(
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
				'safe_publish_auth_invalid',
				'Invalid Safe Publish authentication signature',
				array( 'status' => 401 )
			);
		}

		// Authentication successful.
		safe_publish_vip_log_auth_event(
			'AUTH_SUCCESS',
			array(
				'route'      => $route,
				'method'     => $method,
				'timestamp'  => $timestamp,
				'user_agent' => $request->get_header( 'user_agent' ),
			)
		);

		// Add custom header to indicate successful Safe Publish authentication (only if headers not sent).
		if ( ! headers_sent() ) {
			header( 'X-Safe-Publish-Auth: success' );
		}

		// Set up user context and permissions for Safe Publish authenticated requests.
		safe_publish_vip_setup_authenticated_context( $request );

		// Add immediate permission override for this specific request.
		add_filter( 'map_meta_cap', 'safe_publish_vip_override_meta_capabilities', 10, 4 );

		// Override REST permission checks specifically for context=edit.
		add_filter( 'rest_post_dispatch', 'safe_publish_vip_override_context_permissions', 5, 3 );

		// Continue with the authenticated request.
		return $result;
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
	 */
	function safe_publish_vip_override_meta_capabilities( $caps, $cap, $user_id, $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Only apply to Safe Publish authenticated requests.
		if ( empty( $GLOBALS['safe_publish_authenticated'] ) ) {
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
			safe_publish_vip_log_auth_event(
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
	 */
	function safe_publish_vip_override_context_permissions( $result, $server, $request ): WP_REST_Response|WP_Error {
		// Only apply to Safe Publish authenticated requests.
		if ( empty( $GLOBALS['safe_publish_authenticated'] ) ) {
			return $result;
		}

		// If we received a forbidden context error, override it.
		if ( is_wp_error( $result ) && $result->get_error_code() === 'rest_forbidden_context' ) {
			safe_publish_vip_log_auth_event(
				'CONTEXT_ERROR_OVERRIDDEN',
				array(
					'original_error' => $result->get_error_message(),
					'route'          => $request->get_route(),
					'method'         => $request->get_method(),
					'context'        => $request->get_param( 'context' ),
				)
			);

			// Re-dispatch the request with elevated permissions.
			$GLOBALS['safe_publish_context_override'] = true;

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

			unset( $GLOBALS['safe_publish_context_override'] );

			return $new_result;
		}

		return $result;
	}
}

if ( ! function_exists( 'safe_publish_vip_setup_authenticated_context' ) ) {
	/**
	 * Sets up authenticated context for Safe Publish requests.
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
	function safe_publish_vip_setup_authenticated_context( $request ): void {
		// Mark this request as Safe Publish authenticated for later reference.
		$GLOBALS['safe_publish_authenticated'] = true;

		// Always add the capability filter first as a safety net.
		add_filter( 'user_has_cap', 'safe_publish_vip_grant_api_capabilities', 10, 4 );

		// VIP-friendly approach: Use capability system without creating actual users.
		// This avoids 2FA requirements and is more secure.
		safe_publish_vip_log_auth_event(
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
		$GLOBALS['safe_publish_virtual_user'] = (object) array(
			'ID'           => 0,
			'user_login'   => 'safe-publish-system',
			'user_email'   => 'safe-publish-system@virtual',
			'display_name' => 'Safe Publish System (Virtual)',
		);

		// Add filter to bypass additional permission checks for Safe Publish requests.
		add_filter( 'rest_pre_dispatch', 'safe_publish_vip_bypass_permission_checks', 11, 3 );

		// Add direct permission callback overrides for post types.
		add_filter( 'rest_post_collection_params', 'safe_publish_vip_override_collection_params', 10, 2 );
		add_filter( 'rest_prepare_post', 'safe_publish_vip_ensure_edit_context_access', 10, 3 );
		add_filter( 'rest_prepare_page', 'safe_publish_vip_ensure_edit_context_access', 10, 3 );

		// Override permission checks at the endpoint level.
		add_filter( 'rest_endpoints', 'safe_publish_vip_override_endpoint_permissions' );
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
	 */
	function safe_publish_vip_grant_api_capabilities( $allcaps, $caps, $args, $user ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Only apply to Safe Publish authenticated requests.
		if ( empty( $GLOBALS['safe_publish_authenticated'] ) ) {
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
			$virtual_user = $GLOBALS['safe_publish_virtual_user'] ?? (object) array( 'ID' => 0 );
			safe_publish_vip_log_auth_event(
				'CAPABILITIES_GRANTED',
				array(
					'user_id'            => $virtual_user->ID,
					'user_type'          => 'virtual_safe_publish_user',
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
 * Bypasses additional permission checks for Safe Publish authenticated requests.
 *
 * @param WP_REST_Response|WP_Error|null $result  Response to replace the requested version with.
 * @param WP_REST_Server                 $server  Server instance.
 * @param WP_REST_Request                $request Request used to generate the response.
 * @return WP_REST_Response|WP_Error|null Original result, unchanged.
 */
function safe_publish_vip_bypass_permission_checks( $result, $server, $request ): WP_REST_Response|WP_Error|null { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	// Only apply to Safe Publish authenticated requests.
	if ( empty( $GLOBALS['safe_publish_authenticated'] ) ) {
		return $result;
	}

	// Add filter to allow edit context for Safe Publish authenticated requests.
	add_filter( 'rest_allow_anonymous_comments', '__return_true' );

	// Add specific permission callback override for posts and other post types.
	add_filter( 'rest_prepare_post', 'safe_publish_vip_prepare_post_for_edit_context', 10, 3 );
	add_filter( 'rest_prepare_page', 'safe_publish_vip_prepare_post_for_edit_context', 10, 3 );

	// Override specific permission checks for edit context.
	add_filter( 'rest_post_dispatch', 'safe_publish_vip_ensure_response_success', 10, 3 );

	return $result;
}

/**
 * Prepares post data for edit context when Safe Publish is authenticated.
 *
 * @param WP_REST_Response $response Response object.
 * @param WP_Post          $post     Post object.
 * @param WP_REST_Request  $request  Request object.
 * @return WP_REST_Response Response object, unchanged.
 */
function safe_publish_vip_prepare_post_for_edit_context( $response, $post, $request ): WP_REST_Response {
	// Only apply to Safe Publish authenticated requests.
	if ( empty( $GLOBALS['safe_publish_authenticated'] ) ) {
		return $response;
	}

	// If this is an edit context request, ensure we return the full data.
	if ( 'edit' === $request->get_param( 'context' ) ) {
		// Log that we're allowing edit context for Safe Publish.
		safe_publish_vip_log_auth_event(
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
 * Ensures response success for valid Safe Publish operations.
 *
 * @param WP_REST_Response|WP_Error $response Response object.
 * @param WP_REST_Server            $server   Server instance.
 * @param WP_REST_Request           $request  Request used to generate the response.
 * @return WP_REST_Response|WP_Error Response, potentially modified.
 */
function safe_publish_vip_ensure_response_success( $response, $server, $request ): WP_REST_Response|WP_Error {
	// Only apply to Safe Publish authenticated requests.
	if ( empty( $GLOBALS['safe_publish_authenticated'] ) ) {
		return $response;
	}

	// If we got a permission error for edit context, try to resolve it.
	if ( is_wp_error( $response ) ) {
		$error_code = $response->get_error_code();

		// Handle specific REST permission errors.
		if ( in_array( $error_code, array( 'rest_forbidden', 'rest_cannot_edit', 'rest_forbidden_context' ), true ) ) {
			safe_publish_vip_log_auth_event(
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
				// For Safe Publish authenticated requests, we should allow edit context.
				// This is a fallback - the proper fix should be in the capability system above.
				safe_publish_vip_log_auth_event(
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

		// Method 4: WordPress option (fallback for non-VIP or development).
		$option_secret = get_option( 'safe_publish_shared_secret', '' );
		if ( ! empty( $option_secret ) ) {
			return $option_secret;
		}

		return '';
	}
}

if ( ! function_exists( 'safe_publish_vip_log_auth_event' ) ) {
	/**
	 * Logs authentication events for monitoring and debugging.
	 *
	 * Enhanced for VIP dashboard visibility.
	 *
	 * @param string $event Event type (AUTH_SUCCESS, SIGNATURE_INVALID, etc.).
	 * @param array  $data  Optional. Additional event data. Default empty array.
	 */
	function safe_publish_vip_log_auth_event( $event, $data = array() ): void {
		// Skip logging during REST API requests to prevent header issues.
		if ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) && ! headers_sent() ) {
			// Queue the log for later processing.
			if ( ! isset( $GLOBALS['safe_publish_deferred_logs'] ) ) {
				$GLOBALS['safe_publish_deferred_logs'] = array();
			}
			$GLOBALS['safe_publish_deferred_logs'][] = array(
				'event' => $event,
				'data'  => $data,
			);

			// Register shutdown hook to process deferred logs.
			if ( ! has_action( 'shutdown', 'safe_publish_vip_process_deferred_logs' ) ) {
				add_action( 'shutdown', 'safe_publish_vip_process_deferred_logs' );
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
		$log_message = '[Safe-Publish-Auth-VIP] ' . $event . ': ' . wp_json_encode( $log_data, JSON_UNESCAPED_SLASHES );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $log_message );

		// Backup logging methods.

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

		// 3. Store recent events in database for dashboard viewing (only if WordPress is loaded).
		if ( function_exists( 'get_option' ) ) {
			safe_publish_vip_store_log_event( $event, $log_data );
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
}

if ( ! function_exists( 'safe_publish_vip_process_deferred_logs' ) ) {
	/**
	 * Processes deferred logs that were queued during REST API requests.
	 */
	function safe_publish_vip_process_deferred_logs(): void {
		if ( ! isset( $GLOBALS['safe_publish_deferred_logs'] ) || empty( $GLOBALS['safe_publish_deferred_logs'] ) ) {
			return;
		}

		foreach ( $GLOBALS['safe_publish_deferred_logs'] as $log_entry ) {
			safe_publish_vip_log_auth_event( $log_entry['event'], $log_entry['data'] );
		}

		// Clear the queue.
		unset( $GLOBALS['safe_publish_deferred_logs'] );
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

// Always register monitoring endpoint.
add_action( 'rest_api_init', 'safe_publish_vip_register_monitoring_endpoints' );

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
	return current_user_can( 'manage_options' );
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
	if ( ! empty( get_option( 'safe_publish_shared_secret' ) ) ) {
		return 'option';
	}
	return 'none';
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
	} elseif ( ! empty( get_option( 'safe_publish_shared_secret' ) ) ) {
		echo esc_html__( 'WordPress Option (safe_publish_shared_secret)', 'safe-publish' );
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
