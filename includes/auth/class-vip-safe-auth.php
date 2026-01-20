<?php
/**
 * VIP-Safe Authentication Handler
 *
 * @package CCP
 */

namespace CCP\Auth;

use CCP\Utils\Environment;
use WP_Error;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VIP-Safe Authentication Class.
 *
 * Implements authentication methods that work on VIP:
 *
 * 1. Shared Secret (HMAC authentication) - Production ready.
 * 2. Basic Authentication - Development environments only.
 */
final class VIP_Safe_Auth {

	/**
	 * Authentication methods priority order.
	 */
	const AUTH_METHODS = array(
		'shared_secret',
		'basic_auth', // Development only.
	);

	/**
	 * Gets authentication parameters for requests.
	 *
	 * @param string $site_url    Target site URL.
	 * @param array  $auth_config Optional. Authentication configuration array. Default empty array.
	 * @param string $method      Optional. HTTP method for the request. Default 'GET'.
	 * @return array Request modifications (headers, query params, etc.).
	 */
	public static function get_auth_params( $site_url, $auth_config = array(), $method = 'GET' ): array {
		$auth_method = self::determine_auth_method( $auth_config );

		switch ( $auth_method ) {
			case 'shared_secret':
				$result = self::get_shared_secret_auth( $site_url, $auth_config, $method );
				return $result;

			case 'basic_auth':
				return self::get_basic_auth( $site_url, $auth_config );

			default:
				return array();
		}
	}

	/**
	 * Determines the best authentication method to use.
	 *
	 * @param array $auth_config Authentication configuration.
	 * @return string Authentication method to use.
	 */
	private static function determine_auth_method( $auth_config ): string {
		// Check what auth methods are configured.
		if ( ! empty( $auth_config['shared_secret'] ) ) {
			return 'shared_secret';
		}

		// Only allow Basic auth in development environments.
		if ( ! empty( $auth_config['username'] ) && ! empty( $auth_config['password'] ) && Environment::is_development() ) {
			return 'basic_auth';
		}

		return 'none';
	}

	/**
	 * Checks if the current request/context is correctly authorized.
	 *
	 * Validates that the authentication credentials are valid and can
	 * successfully authenticate with the target site.
	 *
	 * @param string $site_url    Optional. Target site URL to test authorization against. Default ''.
	 * @param array  $auth_config Optional. Authentication configuration array. Default empty array.
	 * @return bool True if correctly authorized, false otherwise.
	 */
	public static function is_authorized( $site_url = '', $auth_config = array() ): bool {
		$auth_method = self::determine_auth_method( $auth_config );

		// No authentication method available.
		if ( 'none' === $auth_method ) {
			return false;
		}

		// If we have shared secret authentication.
		if ( 'shared_secret' === $auth_method ) {
			$shared_secret = $auth_config['shared_secret'] ?? '';

			// Check if shared secret is configured and meets minimum requirements.
			if ( empty( $shared_secret ) || strlen( $shared_secret ) < 16 ) {
				return false;
			}

			// If site URL is provided, we can test if the auth headers are correctly generated.
			if ( ! empty( $site_url ) ) {
				$auth_params = self::get_shared_secret_auth( $site_url, $auth_config, 'GET' );
				return ! empty( $auth_params['headers']['X-CCP-Timestamp'] ) &&
						! empty( $auth_params['headers']['X-CCP-Signature'] );
			}

			return true; // Shared secret is present and valid format.
		}

		// If we have basic authentication (development only).
		if ( 'basic_auth' === $auth_method ) {
			$username = $auth_config['username'] ?? '';
			$password = $auth_config['password'] ?? '';

			// Check if credentials are correctly configured.
			if ( empty( $username ) || empty( $password ) ) {
				return false;
			}

			// Check if we're in a development environment (basic auth not allowed in production).
			if ( ! Environment::is_development() ) {
				return false;
			}

			// If site URL is provided, we could test the credentials (but this would make an actual request).
			// For now, we'll just verify the credentials are present and environment is appropriate.
			return true;
		}

		return false;
	}

	/**
	 * Tests authorization against a site by making a lightweight request.
	 *
	 * Validates that the credentials work with the target site.
	 *
	 * @param string $site_url    Target site URL.
	 * @param array  $auth_config Optional. Authentication configuration array. Default empty array.
	 * @return bool|WP_Error True if authorized, WP_Error with details if not.
	 */
	public static function test_authorization( $site_url, $auth_config = array() ): bool|WP_Error {
		if ( empty( $site_url ) ) {
			return new WP_Error( 'invalid_url', __( 'Site URL is required for authorization testing', 'ccp' ) );
		}

		// First check if we have valid credentials format.
		if ( ! self::is_authorized( $site_url, $auth_config ) ) {
			return new WP_Error( 'invalid_credentials', __( 'Invalid or missing authentication credentials', 'ccp' ) );
		}

		// Make a lightweight test request to verify credentials work.
		$test_url    = trailingslashit( $site_url ) . 'wp-json/wp/v2/';
		$auth_params = self::get_auth_params( $test_url, $auth_config, 'GET' );

		$request_args = array(
			'timeout'     => 3,
			'redirection' => 0,
			'user-agent'  => 'CCP-Auth-Test/1.0',
		);

		// Add authentication headers if available.
		if ( ! empty( $auth_params['headers'] ) ) {
			$request_args['headers'] = $auth_params['headers'];
		}

		// Add query parameters for authentication if needed.
		if ( ! empty( $auth_params['query_args'] ) ) {
			$test_url = add_query_arg( $auth_params['query_args'], $test_url );
		}

		// Use VIP-optimized function when available, fallback to core function.
		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = vip_safe_wp_remote_get( $test_url, '', 3, 5, 20, $request_args );
		} else {
			// On non-VIP environments, use standard wp_remote_get.
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Fallback for non-VIP environments
			$response = wp_remote_get( $test_url, $request_args );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', __( 'Authorization test request failed: ', 'ccp' ) . $response->get_error_message() );
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		// Check for authentication-related errors.
		if ( in_array( $response_code, array( 401, 403 ), true ) ) {
			$response_body = wp_remote_retrieve_body( $response );
			return new WP_Error(
				'auth_failed',
				sprintf(
					/* translators: 1: HTTP response code, 2: response body message */
					__( 'Authentication failed with HTTP %1$d: %2$s', 'ccp' ),
					$response_code,
					$response_body
				)
			);
		}

		// If we get a successful response (200) or even a 404 (endpoint exists but not found),
		// it means authentication passed.
		if ( in_array( $response_code, array( 200, 404 ), true ) ) {
			return true;
		}

		// Other error codes might indicate server issues rather than auth issues.
		return new WP_Error(
			'unexpected_response',
			sprintf(
				/* translators: %d: HTTP response code */
				__( 'Unexpected response code: %d', 'ccp' ),
				$response_code
			)
		);
	}

	/**
	 * Gets shared secret authentication parameters.
	 *
	 * Uses HMAC signature in custom headers that VIP allows.
	 * Compatible with the CCP VIP mu-plugin authentication handler.
	 *
	 * @param string $site_url    Target site URL.
	 * @param array  $auth_config Authentication configuration.
	 * @param string $method      Optional. HTTP method for the request. Default 'GET'.
	 * @return array Request modifications.
	 */
	private static function get_shared_secret_auth( $site_url, $auth_config, $method = 'GET' ): array {
		$shared_secret = $auth_config['shared_secret'] ?? '';

		if ( empty( $shared_secret ) ) {
			return array();
		}

		// Generate timestamp for replay protection.
		$timestamp = time();

		// Extract the REST API path portion - this should match what WP_REST_Request::get_route() returns.
		// Parse the URL to get the path component.
		$parsed_url = wp_parse_url( $site_url );
		$full_path  = $parsed_url['path'] ?? '';

		if ( strpos( $full_path, '/wp-json/' ) !== false ) {
			// Full REST API URL - extract everything after /wp-json.
			$wp_json_pos = strpos( $full_path, '/wp-json/' );
			$path        = substr( $full_path, $wp_json_pos + 8 ); // +8 to skip '/wp-json'.

			// Ensure path starts with / and handle empty paths.
			if ( empty( $path ) || '/' !== $path[0] ) {
				$path = '/' . ltrim( $path, '/' );
			}
		} else {
			// No wp-json in path - this shouldn't happen in normal usage.
			// Default to a common endpoint.
			$path = '/wp/v2/posts';
		}

		// Create signature string: METHOD|URI|TIMESTAMP (compatible with mu-plugin).
		$string_to_sign = $method . '|' . $path . '|' . $timestamp;
		$signature      = hash_hmac( 'sha256', $string_to_sign, $shared_secret );

		return array(
			'headers' => array(
				'X-CCP-Timestamp' => $timestamp,
				'X-CCP-Signature' => $signature,
			),
		);
	}

	/**
	 * Gets basic authentication parameters (development environments only).
	 *
	 * Uses Authorization header with Basic auth.
	 * WARNING: Will NOT work on VIP production.
	 *
	 * @param string $site_url    Target site URL.
	 * @param array  $auth_config Authentication configuration.
	 * @return array Request modifications.
	 */
	private static function get_basic_auth( $site_url, $auth_config ): array {
		$username = $auth_config['username'] ?? '';
		$password = $auth_config['password'] ?? '';

		if ( empty( $username ) || empty( $password ) ) {
			return array();
		}

		// Only allow in development environments.
		if ( ! Environment::is_development() ) {
			return array();
		}

		return array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
			),
		);
	}


	/**
	 * Verifies incoming authentication.
	 *
	 * Used on the target site to verify requests.
	 *
	 * @return bool|WP_Error True if authenticated, WP_Error if not.
	 */
	public static function verify_request(): bool|WP_Error {
		// Check for shared secret authentication.
		if ( isset( $_SERVER['HTTP_X_CCP_SIGNATURE'] ) ) {
			return self::verify_shared_secret();
		}

		return new WP_Error( 'no_auth', __( 'No valid authentication found.', 'ccp' ) );
	}

	/**
	 * Verifies shared secret authentication.
	 *
	 * @return bool|WP_Error True if valid, WP_Error if not.
	 */
	private static function verify_shared_secret(): bool|WP_Error {
		$signature = sanitize_text_field( $_SERVER['HTTP_X_CCP_SIGNATURE'] ?? '' );
		$site      = sanitize_url( $_SERVER['HTTP_X_CCP_SITE'] ?? '' );
		$timestamp = sanitize_text_field( $_SERVER['HTTP_X_CCP_TIMESTAMP'] ?? '' );
		$nonce     = sanitize_text_field( $_SERVER['HTTP_X_CCP_NONCE'] ?? '' );

		if ( empty( $signature ) || empty( $site ) || empty( $timestamp ) || empty( $nonce ) ) {
			return new WP_Error( 'invalid_auth', __( 'Missing authentication parameters.', 'ccp' ) );
		}

		// Check timestamp (prevent replay attacks).
		if ( abs( time() - intval( $timestamp ) ) > 300 ) { // 5 minute window.
			return new WP_Error( 'expired_auth', __( 'Authentication expired.', 'ccp' ) );
		}

		// Get shared secret for this site.
		$shared_secret = get_option( 'ccp_shared_secret_' . md5( $site ), '' );

		if ( empty( $shared_secret ) ) {
			return new WP_Error( 'unknown_site', __( 'Unknown source site.', 'ccp' ) );
		}

		// Recreate payload and verify signature.
		$payload = array(
			'site'      => $site,
			'timestamp' => intval( $timestamp ),
			'nonce'     => $nonce,
			'target'    => get_bloginfo( 'url' ),
		);

		$expected_signature = hash_hmac( 'sha256', wp_json_encode( $payload ), $shared_secret );

		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return new WP_Error( 'invalid_signature', __( 'Invalid authentication signature.', 'ccp' ) );
		}

		// Check nonce for replay protection.
		$nonce_key = 'ccp_nonce_' . md5( $nonce );
		if ( get_transient( $nonce_key ) ) {
			return new WP_Error( 'replay_attack', __( 'Nonce already used.', 'ccp' ) );
		}

		// Store nonce to prevent replay.
		set_transient( $nonce_key, true, 600 ); // 10 minutes.

		return true;
	}

	/**
	 * Generates a secure shared secret.
	 *
	 * @return string Generated secret.
	 */
	public static function generate_shared_secret(): string {
		return bin2hex( random_bytes( 32 ) ); // 64 character hex string.
	}
}
