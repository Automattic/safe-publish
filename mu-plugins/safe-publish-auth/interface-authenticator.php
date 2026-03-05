<?php
/**
 * Authenticator interface.
 *
 * @package Safe_Publish_Auth
 */

namespace Safe_Publish\Auth;

/**
 * Interface for authentication handlers.
 */
interface Authenticator {
	/**
	 * Authenticates a REST API request.
	 *
	 * @param \WP_REST_Response|\WP_Error|null $result  Response to return instead of continuing.
	 * @param \WP_REST_Server|null             $server  Server instance.
	 * @param \WP_REST_Request                 $request Request object.
	 * @return \WP_REST_Response|\WP_Error|null Authentication result.
	 */
	public function authenticate_request(
		\WP_REST_Response|\WP_Error|null $result,
		?\WP_REST_Server $server,
		\WP_REST_Request $request
	): \WP_REST_Response|\WP_Error|null;

	/**
	 * Checks whether the current request is authenticated.
	 *
	 * @return bool True if authenticated.
	 */
	public function is_authenticated(): bool;
}
