<?php
/**
 * VIP-Safe Authentication Handler
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Auth;

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
 * 1. Shared Secret (HMAC authentication) - Required for all environments.
 * 2. Basic Authentication - Optional, can be layered on top of Shared Secret.
 */
final class VIP_Safe_Auth {

	/**
	 * Probe status: source site accepted the credentials and granted edit
	 * context.
	 *
	 * @var string
	 */
	const STATUS_AUTHORIZED = 'authorized';

	/**
	 * Probe status: source site rejected the credentials (HTTP 401/403).
	 *
	 * @var string
	 */
	const STATUS_UNAUTHORIZED = 'unauthorized';

	/**
	 * Probe status: source site could not be reached (network failure or
	 * unexpected response code).
	 *
	 * @var string
	 */
	const STATUS_UNREACHABLE = 'unreachable';

	/**
	 * Probe status: source site URL is not configured.
	 *
	 * @var string
	 */
	const STATUS_URL_UNSET = 'url_unset';

	/**
	 * Gets authentication parameters for requests.
	 *
	 * @param string $site_url    Destination site URL.
	 * @param array  $auth_config Optional. Authentication configuration array. Default empty array.
	 * @param string $method      Optional. HTTP method for the request. Default 'GET'.
	 * @param string $body        Optional. Request body for content hash generation. Default ''.
	 * @return array Request modifications (headers, query params, etc.).
	 */
	public static function get_auth_params( $site_url, $auth_config = array(), $method = 'GET', $body = '' ): array {
		// Shared secret is required.
		if ( empty( $auth_config['shared_secret'] ) ) {
			return array();
		}

		$params = self::get_shared_secret_auth( $site_url, $auth_config, $method, $body );

		// Basic auth can be layered on top of shared secret auth.
		if ( ! empty( $auth_config['username'] ) && ! empty( $auth_config['password'] ) ) {
			$basic_params = self::get_basic_auth( $site_url, $auth_config );
			if ( ! empty( $basic_params['headers'] ) ) {
				$params['headers'] = array_merge( $params['headers'] ?? array(), $basic_params['headers'] );
			}
		}

		return $params;
	}

	/**
	 * Checks whether the configured credentials are well-formed.
	 *
	 * Validates the shared secret's presence and minimum length. Does not
	 * verify that the source site accepts the credentials — use
	 * test_authorization() for that.
	 *
	 * @param array $auth_config Optional. Authentication configuration array. Default empty array.
	 * @return bool True if the credentials are well-formed, false otherwise.
	 */
	public static function has_valid_credential_format( $auth_config = array() ): bool {
		return strlen( $auth_config['shared_secret'] ?? '' ) >= 16;
	}

	/**
	 * Probes the source site to verify the shared secret is accepted.
	 *
	 * Returns `url_unset` for an empty URL or `unauthorized` if the format
	 * check rejects the credentials. Otherwise, hits
	 * `wp/v2/posts?context=edit&per_page=1` and inspects the response code:
	 * 200 means the source accepts the HMAC signature and grants edit
	 * context; 401/403 means the credentials are rejected; anything else is
	 * treated as unreachable.
	 *
	 * @param string $site_url    Source site URL.
	 * @param array  $auth_config Optional. Authentication configuration array. Default empty array.
	 * @return array Probe result with a `status` key plus optional `code` and `message`.
	 */
	public static function test_authorization( string $site_url, array $auth_config = array() ): array {
		if ( '' === $site_url ) {
			return array( 'status' => self::STATUS_URL_UNSET );
		}

		// Without well-formed credentials we cannot sign the request, so the
		// source would reject it as unauthorized.
		if ( ! self::has_valid_credential_format( $auth_config ) ) {
			return array( 'status' => self::STATUS_UNAUTHORIZED );
		}

		$test_url    = add_query_arg(
			array(
				'context'  => 'edit',
				'per_page' => 1,
			),
			trailingslashit( $site_url ) . 'wp-json/wp/v2/posts'
		);
		$auth_params = self::get_auth_params( $test_url, $auth_config, 'GET' );

		$request_args = array(
			'timeout'     => 3,
			'redirection' => 0,
			'user-agent'  => 'Safe-Publish-Auth-Test/1.0',
		);

		if ( isset( $auth_params['headers'] ) ) {
			$request_args['headers'] = $auth_params['headers'];
		}

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = vip_safe_wp_remote_get( $test_url, '', 3, 5, 20, $request_args );
		} else {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Fallback for non-VIP environments
			$response = wp_remote_get( $test_url, $request_args );
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => self::STATUS_UNREACHABLE,
				'message' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array(
				'status' => self::STATUS_AUTHORIZED,
				'code'   => $code,
			);
		}

		if ( 401 === $code || 403 === $code ) {
			return array(
				'status' => self::STATUS_UNAUTHORIZED,
				'code'   => $code,
			);
		}

		return array(
			'status' => self::STATUS_UNREACHABLE,
			'code'   => $code,
		);
	}

	/**
	 * Gets shared secret authentication parameters.
	 *
	 * Uses HMAC signature in custom headers that VIP allows.
	 * Compatible with the Safe Publish VIP mu-plugin authentication handler.
	 *
	 * @param string $site_url    Destination site URL.
	 * @param array  $auth_config Authentication configuration.
	 * @param string $method      Optional. HTTP method for the request. Default 'GET'.
	 * @param string $body        Optional. Request body for content hash generation. Default ''.
	 * @return array Request modifications.
	 */
	private static function get_shared_secret_auth( $site_url, $auth_config, $method = 'GET', $body = '' ): array {
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

		// Include this site's URL in the signature, so the destination can
		// verify the request origin against its configured connected site URL.
		$source_site_url = untrailingslashit( home_url() );

		// Create signature string: METHOD|URI|TIMESTAMP|CONTENT_HASH|SOURCE_SITE_URL.
		$content_hash   = hash( 'sha256', $body );
		$string_to_sign = $method . '|' . $path . '|' . $timestamp . '|' . $content_hash . '|' . $source_site_url;

		return array(
			'headers' => array(
				'X-Safe-Publish-Timestamp'    => $timestamp,
				'X-Safe-Publish-Content-Hash' => $content_hash,
				'X-Safe-Publish-Signature'    => hash_hmac( 'sha256', $string_to_sign, $shared_secret ),
				'X-Safe-Publish-Site-URL'     => $source_site_url,
			),
		);
	}

	/**
	 * Gets basic authentication parameters.
	 *
	 * Uses Authorization header with Basic auth. Intended as an optional layer
	 * on top of the required Shared Secret authentication.
	 *
	 * @param string $site_url    Destination site URL.
	 * @param array  $auth_config Authentication configuration.
	 * @return array Request modifications.
	 */
	private static function get_basic_auth( $site_url, $auth_config ): array {
		$username = $auth_config['username'] ?? '';
		$password = $auth_config['password'] ?? '';

		if ( empty( $username ) || empty( $password ) ) {
			return array();
		}

		return array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
			),
		);
	}
}
