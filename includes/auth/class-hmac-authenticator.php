<?php
/**
 * HMAC Authenticator class.
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Auth;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * HMAC-based request authenticator.
 *
 * Validates HMAC-SHA256 signatures on REST API requests using a shared secret.
 * The shared secret is read from a PHP constant or environment variable.
 */
class HMAC_Authenticator {
	/**
	 * Logger instance.
	 *
	 * @var Auth_Logger
	 */
	private Auth_Logger $logger;

	/**
	 * Permission manager instance.
	 *
	 * @var Permission_Manager
	 */
	private Permission_Manager $permission_manager;

	/**
	 * Shared secret for HMAC validation.
	 *
	 * @var string
	 */
	private string $shared_secret;

	/**
	 * Whether the current request has been authenticated.
	 *
	 * @var bool
	 */
	private bool $authenticated = false;

	/**
	 * Constructor.
	 *
	 * @param Auth_Logger        $logger             Logger instance.
	 * @param Permission_Manager $permission_manager Permission manager instance.
	 * @param string             $shared_secret      Shared secret for HMAC validation.
	 */
	public function __construct( Auth_Logger $logger, Permission_Manager $permission_manager, string $shared_secret ) {
		$this->logger             = $logger;
		$this->permission_manager = $permission_manager;
		$this->shared_secret      = $shared_secret;
	}

	/**
	 * Authenticates a REST API request.
	 *
	 * Covers all /wp/v2/ and /safe-publish/v1/ routes, except those in
	 * $excluded_routes. Requests without Safe Publish headers pass through.
	 *
	 * @param WP_REST_Response|WP_Error|null $result  Response to return instead of continuing.
	 * @param WP_REST_Server|null            $server  Server instance.
	 * @param WP_REST_Request                $request Request object.
	 * @return WP_REST_Response|WP_Error|null Original result on pass-through, or WP_Error on failure.
	 */
	public function authenticate_request(
		WP_REST_Response|WP_Error|null $result,
		?WP_REST_Server $server,
		WP_REST_Request $request
	): WP_REST_Response|WP_Error|null {
		$route = $request->get_route();

		// auth-test is a diagnostic endpoint that must remain publicly reachable.
		$excluded_routes = array(
			'/safe-publish/v1/auth-test',
		);

		$is_wp_route           = $route && 0 === strpos( $route, '/wp/v2/' );
		$is_safe_publish_route = $route && 0 === strpos( $route, '/safe-publish/v1/' )
			&& ! in_array( $route, $excluded_routes, true );

		if ( ! $is_wp_route && ! $is_safe_publish_route ) {
			return $result;
		}

		$headers = $request->get_headers();

		if ( ! isset( $headers['x_safe_publish_timestamp'] ) || ! isset( $headers['x_safe_publish_signature'] ) ) {
			return $result;
		}

		return $this->authenticate_shared_secret( $request, $headers, $result );
	}

	/**
	 * Checks whether the current request is authenticated.
	 *
	 * @return bool True if authenticated.
	 */
	public function is_authenticated(): bool {
		return $this->authenticated;
	}

	/**
	 * Authenticates a request using shared secret HMAC validation.
	 *
	 * Validates timestamp, content hash, and HMAC-SHA256 signature in sequence.
	 * On success, marks the request as authenticated and sets up the
	 * authenticated context via the injected Permission_Manager.
	 *
	 * @param WP_REST_Request                $request REST request object.
	 * @param array                          $headers Request headers.
	 * @param WP_REST_Response|WP_Error|null $result  Original result to pass through on success.
	 * @return WP_REST_Response|WP_Error|null Original result on success, or WP_Error on failure.
	 */
	private function authenticate_shared_secret(
		WP_REST_Request $request,
		array $headers,
		WP_REST_Response|WP_Error|null $result
	): WP_REST_Response|WP_Error|null {
		$route  = $request->get_route();
		$method = $request->get_method();

		$shared_secret = $this->shared_secret;

		if ( empty( $shared_secret ) ) {
			$this->logger->log_error(
				'NO_SECRET_CONFIGURED',
				array(
					'route'  => $route,
					'method' => $method,
				)
			);

			return new WP_Error(
				'safe_publish_auth_no_secret',
				'Safe Publish shared secret not configured in VIP environment',
				array( 'status' => 500 )
			);
		}

		$timestamp = (int) $headers['x_safe_publish_timestamp'][0];
		$signature = $headers['x_safe_publish_signature'][0];
		$max_diff  = $this->get_max_time_diff();

		if ( ! $this->validate_timestamp( $timestamp, $max_diff ) ) {
			$time_diff = abs( time() - $timestamp );

			$this->logger->log_error(
				'TIMESTAMP_EXPIRED',
				array(
					'route'        => $route,
					'method'       => $method,
					'timestamp'    => $timestamp,
					'current_time' => time(),
					'time_diff'    => $time_diff,
					'max_allowed'  => $max_diff,
				)
			);

			return new WP_Error(
				'safe_publish_auth_expired',
				sprintf( 'Request timestamp expired (difference: %d seconds)', $time_diff ),
				array( 'status' => 401 )
			);
		}

		if ( ! isset( $headers['x_safe_publish_content_hash'] ) ) {
			$this->logger->log_error(
				'CONTENT_HASH_MISSING',
				array(
					'route'  => $route,
					'method' => $method,
				)
			);

			return new WP_Error(
				'safe_publish_auth_content_hash_missing',
				'Missing content hash header',
				array( 'status' => 401 )
			);
		}

		$received_hash = $headers['x_safe_publish_content_hash'][0];
		$body          = $request->get_body();

		if ( ! $this->validate_content_hash( $body, $received_hash ) ) {
			$this->logger->log_error(
				'CONTENT_HASH_MISMATCH',
				array(
					'route'  => $route,
					'method' => $method,
				)
			);

			return new WP_Error(
				'safe_publish_auth_content_hash_invalid',
				'Content hash verification failed',
				array( 'status' => 401 )
			);
		}

		if ( ! $this->validate_signature( $signature, $method, $route, $timestamp, $received_hash ) ) {
			$string_to_sign = $method . '|' . $route . '|' . $timestamp . '|' . $received_hash;

			$this->logger->log_error(
				'SIGNATURE_INVALID',
				array(
					'route'               => $route,
					'method'              => $method,
					'timestamp'           => $timestamp,
					'string_to_sign'      => $string_to_sign,
					'expected_sig_length' => strlen( hash_hmac( 'sha256', $string_to_sign, $shared_secret ) ),
					'received_sig_length' => strlen( $signature ),
				)
			);

			return new WP_Error(
				'safe_publish_auth_invalid',
				'Invalid Safe Publish authentication signature',
				array( 'status' => 401 )
			);
		}

		$this->authenticated = true;

		$this->logger->log_event(
			'AUTH_SUCCESS',
			array(
				'route'      => $route,
				'method'     => $method,
				'timestamp'  => $timestamp,
				'user_agent' => $request->get_header( 'user_agent' ),
			)
		);

		if ( ! headers_sent() ) {
			header( 'X-Safe-Publish-Auth: success' );
		}

		// Set up user context and permissions for wp/v2 routes only.
		// Monitoring endpoints (/safe-publish/v1/) only need the auth flag.
		if ( 0 === strpos( $route, '/wp/v2/' ) ) {
			$this->permission_manager->setup_authenticated_context( $request );
		}

		return $result;
	}

	/**
	 * Returns the maximum allowed timestamp difference in seconds.
	 *
	 * Reads from the `safe_publish_auth_max_time_diff` filter, clamped to a
	 * minimum of 30 seconds and a maximum of 900 seconds to preserve
	 * replay-attack protection regardless of filter values.
	 *
	 * @return int Clamped max time difference in seconds.
	 */
	private function get_max_time_diff(): int {
		$max_diff = (int) apply_filters( 'safe_publish_auth_max_time_diff', 300 );
		return max( 30, min( $max_diff, 900 ) );
	}

	/**
	 * Validates the request timestamp is within the allowed window.
	 *
	 * @param int $timestamp Unix timestamp from request header.
	 * @param int $max_diff  Maximum allowed difference in seconds.
	 * @return bool True if within allowed window.
	 */
	private function validate_timestamp( int $timestamp, int $max_diff ): bool {
		return abs( time() - $timestamp ) <= $max_diff;
	}

	/**
	 * Validates the SHA-256 content hash against the request body.
	 *
	 * @param string $body          Raw request body.
	 * @param string $received_hash Declared hash from X-Safe-Publish-Content-Hash header.
	 * @return bool True if hashes match.
	 */
	private function validate_content_hash( string $body, string $received_hash ): bool {
		return hash_equals( hash( 'sha256', $body ), $received_hash );
	}

	/**
	 * Validates the HMAC-SHA256 signature.
	 *
	 * Signature covers: METHOD|URI|TIMESTAMP|CONTENT_HASH
	 *
	 * @param string $signature    Provided signature.
	 * @param string $method       HTTP method.
	 * @param string $uri          Request URI.
	 * @param int    $timestamp    Request timestamp.
	 * @param string $content_hash SHA-256 hash of request body.
	 * @return bool True if valid.
	 */
	private function validate_signature(
		string $signature,
		string $method,
		string $uri,
		int $timestamp,
		string $content_hash
	): bool {
		$string_to_sign = $method . '|' . $uri . '|' . $timestamp . '|' . $content_hash;
		$expected       = hash_hmac( 'sha256', $string_to_sign, $this->shared_secret );
		return hash_equals( $expected, $signature );
	}
}
