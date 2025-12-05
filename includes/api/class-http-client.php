<?php declare(strict_types = 1);
/**
 * HTTP Client service for making external requests
 *
 * @package CCP
 */

namespace CCP\API;

use CCP\Auth\VIP_Safe_Auth;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP Client Class.
 *
 * Provides a centralized service for making HTTP requests with VIP
 * compatibility, authentication handling, and error management.
 */
class HTTP_Client {

	/**
	 * Makes HTTP request with VIP compatibility.
	 *
	 * @param string $url              Request URL.
	 * @param array  $auth_credentials Optional. Authentication credentials. Default empty array.
	 * @param array  $additional_args  Optional. Additional request arguments. Default empty array.
	 * @return array|\WP_Error Response or error.
	 */
	public function make_request( string $url, array $auth_credentials = array(), array $additional_args = array() ): array|\WP_Error {
		// VIP-optimized timeout (max 10 seconds recommended for VIP environments)
		$timeout = apply_filters( 'ccp_request_timeout', 10 );

		// Determine SSL verification based on environment
		$sslverify = $this->should_verify_ssl( $url );

		$request_args = array_merge(
			array(
				'timeout' => $timeout,
				'user-agent' => $this->get_user_agent(),
				'sslverify' => $sslverify,
				'redirection' => 0, // Prevent redirects for security
			),
			$additional_args
		);

		// Use VIP-safe authentication instead of Basic Auth
		$auth_params = VIP_Safe_Auth::get_auth_params( $url, $auth_credentials, 'GET' );

		// Add authentication headers if available
		if ( ! empty( $auth_params['headers'] ) ) {
			$request_args['headers'] = array_merge(
				$request_args['headers'] ?? array(),
				$auth_params['headers']
			);
		}

		// Add query parameters for authentication if needed
		if ( ! empty( $auth_params['query_args'] ) ) {
			$url = add_query_arg( $auth_params['query_args'], $url );
		}

		// Fallback authentication handling
		if ( empty( $auth_params['headers'] ) && empty( $auth_params['query_args'] ) && ! empty( $auth_credentials ) ) {
			$auth_credentials = $this->get_fallback_auth_credentials( $auth_credentials );
			// Retry getting auth params with fallback credentials
			$auth_params = VIP_Safe_Auth::get_auth_params( $url, $auth_credentials, 'GET' );

			// Apply the new auth params
			if ( ! empty( $auth_params['headers'] ) ) {
				$request_args['headers'] = array_merge(
					$request_args['headers'] ?? array(),
					$auth_params['headers']
				);
			}

			if ( ! empty( $auth_params['query_args'] ) ) {
				$url = add_query_arg( $auth_params['query_args'], $url );
			}
		}

		// Fallback to Basic Auth for backward compatibility (will fail on VIP)
		if ( empty( $auth_params ) && ! empty( $auth_credentials['username'] ) && ! empty( $auth_credentials['password'] ) ) {
			$request_args['headers'] = array_merge(
				$request_args['headers'] ?? array(),
				array(
					'Authorization' => 'Basic ' . base64_encode( $auth_credentials['username'] . ':' . $auth_credentials['password'] ),
				)
			);
		}

		/**
		 * Filters request arguments.
		 *
		 * @param array  $request_args Request arguments.
		 * @param string $url          Request URL.
		 */
		$request_args = apply_filters( 'ccp_request_args', $request_args, $url );

		$response = $this->safe_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'request_failed',
				__( 'Failed to fetch data from external site.', 'ccp' ) . ' ' . $response->get_error_message()
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new \WP_Error(
				'http_error',
				sprintf(
					/* translators: %d: HTTP response code */
					__( 'External site returned HTTP error %d.', 'ccp' ),
					$response_code
				)
			);
		}

		return $response;
	}

	/**
	 * Gets user agent string.
	 *
	 * @return string User agent string.
	 */
	public function get_user_agent(): string {
		$plugin_version = defined( 'CCP_VERSION' ) ? CCP_VERSION : '1.1.0';
		$site_url = get_bloginfo( 'url' );

		return sprintf(
			'Compliant Content Publisher/%s; %s',
			$plugin_version,
			$site_url
		);
	}

	/**
	 * Makes safe remote GET request with VIP compatibility.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Optional. Request arguments. Default empty array.
	 * @return array|\WP_Error Response or error.
	 */
	public function safe_remote_get( string $url, array $args = array() ): array|\WP_Error {
		// Use VIP-optimized function when available, fallback to core function
		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			return vip_safe_wp_remote_get( $url, '', 3, 5, 20, $args );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Fallback for non-VIP environments
		return wp_remote_get( $url, $args );
	}

	/**
	 * Gets authentication credentials from settings or provided array.
	 *
	 * @param array $provided_credentials Optional. Provided credentials. Default empty array.
	 * @return array Authentication credentials array with appropriate keys.
	 */
	public function get_fallback_auth_credentials( array $provided_credentials = array() ): array {
		if ( ! empty( $provided_credentials ) ) {
			return $provided_credentials;
		}

		// Try VIP-safe authentication first
		$shared_secret = get_option( 'ccp_shared_secret', '' );

		if ( ! empty( $shared_secret ) ) {
			return array(
				'shared_secret' => $shared_secret,
			);
		}

		// Fallback to Basic auth in development environments only
		if ( $this->is_development_environment() ) {
			$username = get_option( 'ccp_username', '' );
			$password = get_option( 'ccp_password', '' );

			if ( ! empty( $username ) && ! empty( $password ) ) {
				return array(
					'username' => $username,
					'password' => $password,
				);
			}
		}

		return array();
	}

	/**
	 * Checks if the current environment is a development environment.
	 *
	 * @return bool True if development environment.
	 */
	public function is_development_environment(): bool {
		return ccp_is_development_environment();
	}

	/**
	 * Determines whether to verify SSL certificates based on environment and URL.
	 *
	 * @param string $url URL being requested.
	 * @return bool Whether to verify SSL certificates.
	 */
	public function should_verify_ssl( string $url ): bool {
		// Always verify SSL in VIP production environments
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && constant( 'WPCOM_IS_VIP_ENV' ) ) {
			return true;
		}

		// Parse URL to check for development indicators
		$parsed_url = wp_parse_url( $url );
		$host = $parsed_url['host'] ?? '';

		// Development domains where SSL verification can be disabled
		$dev_domains = array(
			'.test',
			'.local',
			'.dev',
			'localhost',
			'127.0.0.1',
			'::1',
		);

		// Check if this is a development domain
		foreach ( $dev_domains as $dev_domain ) {
			if ( $host === $dev_domain ||
				( function_exists( 'str_ends_with' ) && str_ends_with( $host, $dev_domain ) ) ||
				( ! function_exists( 'str_ends_with' ) && substr( $host, -strlen( $dev_domain ) ) === $dev_domain ) ) {
				// Allow filtering for specific development needs
				return apply_filters( 'ccp_dev_ssl_verify', false, $url );
			}
		}

		// For production domains, always verify SSL
		return true;
	}

	/**
	 * Cleans up temporary file with VIP compatibility.
	 *
	 * @param string $temp_file Path to temporary file.
	 */
	public function cleanup_temp_file( string $temp_file ): void {
		if ( empty( $temp_file ) || is_wp_error( $temp_file ) ) {
			return;
		}

		// Only attempt cleanup if file exists and is a temp file
		if ( file_exists( $temp_file ) && strpos( $temp_file, '/tmp/' ) !== false ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Temp file cleanup after media import, file is in /tmp/ directory
			unlink( $temp_file );
		}
	}

	/**
	 * Downloads external file using WordPress core function.
	 *
	 * @param string $url External file URL.
	 * @return string|\WP_Error|false Path to downloaded file on success, WP_Error or false on failure.
	 */
	public function download_external_file( string $url ): string|\WP_Error|false {
		// Use download_url for proper file handling - WordPress core function
		return download_url( $url );
	}
}
