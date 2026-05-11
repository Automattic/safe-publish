<?php
/**
 * Authentication Logger class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Auth;

use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Logger;

/**
 * Logger for Safe Publish authentication events.
 */
class Auth_Logger extends Logger {

	/**
	 * Constructs the Auth_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'auth';
	}

	/**
	 * Logs a request that failed because no shared secret is configured.
	 *
	 * @param string $route  REST route of the request.
	 * @param string $method HTTP method of the request.
	 */
	public function no_secret_configured(
		string $route,
		string $method
	): void {
		$this->log_error(
			Log_Events::NO_SECRET_CONFIGURED,
			array(
				'route'  => $route,
				'method' => $method,
			)
		);
	}

	/**
	 * Logs a request whose timestamp is outside the allowed window.
	 *
	 * @param string $route             REST route of the request.
	 * @param string $method            HTTP method of the request.
	 * @param int    $request_timestamp Timestamp claimed by the request.
	 * @param int    $current_time      Server time at validation.
	 * @param int    $time_diff         Absolute difference in seconds.
	 * @param int    $max_allowed       Configured maximum allowed difference.
	 */
	public function timestamp_expired(
		string $route,
		string $method,
		int $request_timestamp,
		int $current_time,
		int $time_diff,
		int $max_allowed
	): void {
		$this->log_error(
			Log_Events::TIMESTAMP_EXPIRED,
			array(
				'route'             => $route,
				'method'            => $method,
				'request_timestamp' => $request_timestamp,
				'current_time'      => $current_time,
				'time_diff'         => $time_diff,
				'max_allowed'       => $max_allowed,
			)
		);
	}

	/**
	 * Logs a request whose X-Safe-Publish-Content-Hash header is missing.
	 *
	 * @param string $route  REST route of the request.
	 * @param string $method HTTP method of the request.
	 */
	public function content_hash_missing(
		string $route,
		string $method
	): void {
		$this->log_error(
			Log_Events::CONTENT_HASH_MISSING,
			array(
				'route'  => $route,
				'method' => $method,
			)
		);
	}

	/**
	 * Logs a request whose content hash does not match the body.
	 *
	 * @param string $route  REST route of the request.
	 * @param string $method HTTP method of the request.
	 */
	public function content_hash_mismatch(
		string $route,
		string $method
	): void {
		$this->log_error(
			Log_Events::CONTENT_HASH_MISMATCH,
			array(
				'route'  => $route,
				'method' => $method,
			)
		);
	}

	/**
	 * Logs a request that failed because no connected site URL is configured.
	 *
	 * @param string $route  REST route of the request.
	 * @param string $method HTTP method of the request.
	 */
	public function no_connected_url_configured(
		string $route,
		string $method
	): void {
		$this->log_error(
			Log_Events::NO_CONNECTED_URL_CONFIGURED,
			array(
				'route'  => $route,
				'method' => $method,
			)
		);
	}

	/**
	 * Logs a request whose X-Safe-Publish-Site-URL header is missing.
	 *
	 * @param string $route  REST route of the request.
	 * @param string $method HTTP method of the request.
	 */
	public function site_url_header_missing(
		string $route,
		string $method
	): void {
		$this->log_error(
			Log_Events::SITE_URL_HEADER_MISSING,
			array(
				'route'  => $route,
				'method' => $method,
			)
		);
	}

	/**
	 * Logs a request whose origin does not match the connected site URL.
	 *
	 * @param string $route              REST route of the request.
	 * @param string $method             HTTP method of the request.
	 * @param string $request_site_url   URL claimed by the request.
	 * @param string $connected_site_url URL configured on this site.
	 */
	public function site_url_mismatch(
		string $route,
		string $method,
		string $request_site_url,
		string $connected_site_url
	): void {
		$this->log_error(
			Log_Events::SITE_URL_MISMATCH,
			array(
				'route'              => $route,
				'method'             => $method,
				'request_site_url'   => $request_site_url,
				'connected_site_url' => $connected_site_url,
			)
		);
	}

	/**
	 * Logs an HMAC signature that failed validation.
	 *
	 * @param string $route                REST route of the request.
	 * @param string $method               HTTP method of the request.
	 * @param int    $request_timestamp    Timestamp claimed by the request.
	 * @param string $request_site_url     URL claimed by the request.
	 * @param int    $received_sig_length  Length of the rejected signature string.
	 */
	public function signature_invalid(
		string $route,
		string $method,
		int $request_timestamp,
		string $request_site_url,
		int $received_sig_length
	): void {
		$this->log_error(
			Log_Events::SIGNATURE_INVALID,
			array(
				'route'               => $route,
				'method'              => $method,
				'request_timestamp'   => $request_timestamp,
				'request_site_url'    => $request_site_url,
				'received_sig_length' => $received_sig_length,
			)
		);
	}

	/**
	 * Logs a request that authenticated successfully.
	 *
	 * @param string $route             REST route of the request.
	 * @param string $method            HTTP method of the request.
	 * @param int    $request_timestamp Timestamp claimed by the request.
	 * @param string $request_site_url  URL claimed by the request.
	 */
	public function auth_success(
		string $route,
		string $method,
		int $request_timestamp,
		string $request_site_url
	): void {
		$this->log_event(
			Log_Events::AUTH_SUCCESS,
			array(
				'route'             => $route,
				'method'            => $method,
				'request_timestamp' => $request_timestamp,
				'request_site_url'  => $request_site_url,
			)
		);
	}

	/**
	 * Logs that capability-based authentication context was set up.
	 *
	 * @param string $route    REST route of the request.
	 * @param string $method   HTTP method of the request.
	 * @param string $approach Approach used (e.g. 'capability_only').
	 * @param string $reason   Free-text reason for using the approach.
	 */
	public function capability_based_auth_setup(
		string $route,
		string $method,
		string $approach,
		string $reason
	): void {
		$this->log_event(
			Log_Events::CAPABILITY_BASED_AUTH_SETUP,
			array(
				'route'    => $route,
				'method'   => $method,
				'approach' => $approach,
				'reason'   => $reason,
			)
		);
	}

	/**
	 * Logs that the permission check was intercepted before route dispatch.
	 *
	 * @param string      $route            REST route of the request.
	 * @param string      $method           HTTP method of the request.
	 * @param string|null $context          REST 'context' param (or null when absent).
	 * @param string      $handler_callback 'set' if the handler has a callback, else 'not_set'.
	 */
	public function permission_check_intercepted(
		string $route,
		string $method,
		?string $context,
		string $handler_callback
	): void {
		$this->log_event(
			Log_Events::PERMISSION_CHECK_INTERCEPTED,
			array(
				'route'            => $route,
				'method'           => $method,
				'context'          => $context,
				'handler_callback' => $handler_callback,
			)
		);
	}

	/**
	 * Logs a meta capability override.
	 *
	 * @param string $capability     Capability being checked.
	 * @param int    $target_user_id User ID the check ran against.
	 * @param array  $original_caps  Caps originally returned before override.
	 */
	public function meta_cap_override(
		string $capability,
		int $target_user_id,
		array $original_caps
	): void {
		$this->log_event(
			Log_Events::META_CAP_OVERRIDE,
			array(
				'capability'     => $capability,
				'target_user_id' => $target_user_id,
				'original_caps'  => $original_caps,
			)
		);
	}

	/**
	 * Logs that the permission callback returned an override for the request.
	 *
	 * @param string      $route   REST route of the request.
	 * @param string      $method  HTTP method of the request.
	 * @param string|null $context REST 'context' param (or null when absent).
	 */
	public function permission_override_applied(
		string $route,
		string $method,
		?string $context
	): void {
		$this->log_event(
			Log_Events::PERMISSION_OVERRIDE_APPLIED,
			array(
				'route'   => $route,
				'method'  => $method,
				'context' => $context,
			)
		);
	}

	/**
	 * Logs that a REST endpoint's permission_callback was overridden.
	 *
	 * @param string $route   REST route whose endpoint was overridden.
	 * @param string $methods HTTP methods string for the handler.
	 */
	public function permission_callback_overridden(
		string $route,
		string $methods
	): void {
		$this->log_event(
			Log_Events::PERMISSION_CALLBACK_OVERRIDDEN,
			array(
				'route'   => $route,
				'methods' => $methods,
			)
		);
	}

	/**
	 * Logs that the collection_params context default was overridden to 'edit'.
	 *
	 * @param string $post_type       Post type slug whose params were overridden.
	 * @param string $default_context New default context (typically 'edit').
	 */
	public function collection_params_overridden(
		string $post_type,
		string $default_context
	): void {
		$this->log_event(
			Log_Events::COLLECTION_PARAMS_OVERRIDDEN,
			array(
				'post_type'       => $post_type,
				'default_context' => $default_context,
			)
		);
	}

	/**
	 * Logs that a rest_forbidden_context error was overridden and re-dispatched.
	 *
	 * @param string      $original_error Error message returned before override.
	 * @param string      $route          REST route of the request.
	 * @param string      $method         HTTP method of the request.
	 * @param string|null $context        REST 'context' param (or null when absent).
	 */
	public function context_error_overridden(
		string $original_error,
		string $route,
		string $method,
		?string $context
	): void {
		$this->log_event(
			Log_Events::CONTEXT_ERROR_OVERRIDDEN,
			array(
				'original_error' => $original_error,
				'route'          => $route,
				'method'         => $method,
				'context'        => $context,
			)
		);
	}

	/**
	 * Logs that an edit-context response was served for an imported post.
	 *
	 * @param int    $post_id   Post ID served in edit context.
	 * @param string $post_type Post type of the served post.
	 * @param string $route     REST route of the request.
	 */
	public function edit_context_allowed(
		int $post_id,
		string $post_type,
		string $route
	): void {
		$this->log_event(
			Log_Events::EDIT_CONTEXT_ALLOWED,
			array(
				'post_id'   => $post_id,
				'post_type' => $post_type,
				'route'     => $route,
			)
		);
	}

	/**
	 * Logs that a permission error was intercepted late in the response cycle.
	 *
	 * @param string      $error_code    Error code from the WP_Error response.
	 * @param string      $error_message Error message from the WP_Error response.
	 * @param string      $route         REST route of the request.
	 * @param string      $method        HTTP method of the request.
	 * @param string|null $context       REST 'context' param (or null when absent).
	 */
	public function permission_error_intercepted(
		string $error_code,
		string $error_message,
		string $route,
		string $method,
		?string $context
	): void {
		$this->log_event(
			Log_Events::PERMISSION_ERROR_INTERCEPTED,
			array(
				'error_code'    => $error_code,
				'error_message' => $error_message,
				'route'         => $route,
				'method'        => $method,
				'context'       => $context,
			)
		);
	}

	/**
	 * Logs that the auth audit log was cleared.
	 */
	public function logs_cleared(): void {
		$this->log_event( Log_Events::LOGS_CLEARED );
	}

	/**
	 * Logs that the auth test endpoint was accessed.
	 *
	 * @param bool   $headers_present Whether Safe Publish headers were present.
	 * @param string $test_type       Identifier for the type of test invocation.
	 */
	public function test_endpoint_accessed(
		bool $headers_present,
		string $test_type
	): void {
		$this->log_event(
			Log_Events::TEST_ENDPOINT_ACCESSED,
			array(
				'headers_present' => $headers_present,
				'test_type'       => $test_type,
			)
		);
	}
}
