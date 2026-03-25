<?php
/**
 * Permission Manager class.
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Auth;

use Safe_Publish\API\Export_Logger;
use WP_Error;
use WP_HTTP_Response;
use WP_Post;
use WP_Post_Type;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

/**
 * Manages permissions for authenticated Safe Publish requests.
 *
 * Grants and overrides WordPress capabilities and REST API permission
 * callbacks for requests authenticated via HMAC-SHA256.
 */
class Permission_Manager {

	/**
	 * Logger instance.
	 *
	 * @var Auth_Logger
	 */
	private Auth_Logger $logger;

	/**
	 * Export logger instance.
	 *
	 * @var Export_Logger
	 */
	private Export_Logger $export_logger;

	/**
	 * Whether the current request is authenticated.
	 *
	 * @var bool
	 */
	private bool $authenticated = false;

	/**
	 * Virtual user context object used for logging purposes.
	 *
	 * @var object|null
	 */
	private ?object $virtual_user = null;

	/**
	 * Whether a context permission override re-dispatch is in progress.
	 *
	 * @var bool
	 */
	private bool $context_override = false;

	/**
	 * Constructor.
	 *
	 * @param Auth_Logger   $logger        Auth logger instance.
	 * @param Export_Logger $export_logger Export logger instance.
	 */
	public function __construct( Auth_Logger $logger, Export_Logger $export_logger ) {
		$this->logger        = $logger;
		$this->export_logger = $export_logger;
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
	 * Sets up authenticated context for Safe Publish requests.
	 *
	 * Grants necessary permissions for REST API operations.
	 *
	 * VIP 2FA COMPLIANCE NOTE:
	 * This uses a capability-based authentication approach instead of
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
	public function setup_authenticated_context(
		WP_REST_Request $request
	): void {
		$this->authenticated = true;

		add_filter( 'user_has_cap', array( $this, 'grant_api_capabilities' ), 10, 4 );

		// VIP-friendly approach: use capability system without creating actual users.
		// This avoids 2FA requirements and is more secure.
		$this->logger->log_event(
			'CAPABILITY_BASED_AUTH_SETUP',
			array(
				'route'    => $request->get_route(),
				'method'   => $request->get_method(),
				'approach' => 'capability_only',
				'reason'   => 'VIP 2FA compliance - no user creation needed',
			)
		);

		$this->virtual_user = (object) array(
			'ID'           => 0,
			'user_login'   => 'safe-publish-system',
			'user_email'   => 'safe-publish-system@virtual',
			'display_name' => 'Safe Publish System (Virtual)',
		);

		add_filter( 'rest_pre_dispatch', array( $this, 'bypass_permission_checks' ), 11, 3 );
		add_filter( 'rest_post_collection_params', array( $this, 'override_collection_params' ), 10, 2 );
		add_filter( 'rest_prepare_post', array( $this, 'ensure_edit_context_access' ), 10, 3 );
		add_filter( 'rest_prepare_page', array( $this, 'ensure_edit_context_access' ), 10, 3 );
		add_filter( 'rest_endpoints', array( $this, 'override_endpoint_permissions' ) );
		add_filter( 'map_meta_cap', array( $this, 'override_meta_capabilities' ), 10, 4 );
		add_filter( 'rest_post_dispatch', array( $this, 'override_context_permissions' ), 5, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'log_export_event' ), 20, 3 );
	}

	/**
	 * Handles permission checks before REST callbacks are executed.
	 *
	 * Intercepts the permission check that causes rest_forbidden_context errors
	 * and grants a comprehensive set of capabilities for Safe Publish requests.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|null $response Response to replace.
	 * @param array                                           $handler  Route handler used for the request.
	 * @param WP_REST_Request                                 $request  Request used to generate the response.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|null Modified response.
	 */
	public function handle_permission_check(
		WP_REST_Response|WP_HTTP_Response|WP_Error|null $response,
		array $handler,
		WP_REST_Request $request
	): WP_REST_Response|WP_HTTP_Response|WP_Error|null {
		if ( ! $this->authenticated ) {
			return $response;
		}

		$route = $request->get_route();
		if ( ! $route || strpos( $route, '/wp/v2/' ) !== 0 ) {
			return $response;
		}

		add_filter(
			'user_has_cap',
			function ( $allcaps, $_caps, $_args, $_user ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
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
		);

		$this->logger->log_event(
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

	/**
	 * Overrides meta capabilities for Safe Publish authenticated requests.
	 *
	 * Handles capability mapping that occurs before user_has_cap.
	 *
	 * @param array  $caps    Required capabilities.
	 * @param string $cap     Capability being checked.
	 * @param int    $user_id User ID.
	 * @param array  $_args   Arguments passed to capability check.
	 * @return array Modified capabilities.
	 */
	public function override_meta_capabilities( // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		array $caps,
		string $cap,
		int $user_id,
		array $_args
	): array {
		if ( ! $this->authenticated ) {
			return $caps;
		}

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

		if ( ! in_array( $cap, $edit_caps, true ) ) {
			return $caps;
		}

		$this->logger->log_event(
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

	/**
	 * Grants API capabilities for Safe Publish authenticated requests.
	 *
	 * @param array   $allcaps All capabilities for the user.
	 * @param array   $caps    Required capabilities.
	 * @param array   $_args   Arguments for capability check.
	 * @param WP_User $_user   User object.
	 * @return array Modified capabilities.
	 */
	public function grant_api_capabilities( // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		array $allcaps,
		array $caps,
		array $_args,
		WP_User $_user
	): array {
		if ( ! $this->authenticated ) {
			return $allcaps;
		}

		$api_caps = array(
			'read',
			'edit_posts',
		);

		foreach ( $api_caps as $cap ) {
			$allcaps[ $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * Permission callback that allows all operations for Safe Publish authenticated requests.
	 *
	 * @param WP_REST_Request|null $request Optional. REST request object.
	 * @return bool True for Safe Publish authenticated requests, otherwise result of capability check.
	 */
	public function allow_all_permissions(
		?WP_REST_Request $request = null
	): bool {
		if ( ! $this->authenticated ) {
			return current_user_can( 'read' );
		}

		$this->logger->log_event(
			'PERMISSION_OVERRIDE_APPLIED',
			array(
				'route'   => $request ? $request->get_route() : 'unknown',
				'method'  => $request ? $request->get_method() : 'unknown',
				'context' => $request ? $request->get_param( 'context' ) : 'unknown',
			)
		);

		return true;
	}

	/**
	 * Overrides REST endpoint permissions for Safe Publish authenticated requests.
	 *
	 * @param array $endpoints Registered REST endpoints.
	 * @return array Modified endpoints.
	 */
	public function override_endpoint_permissions( array $endpoints ): array {
		if ( ! $this->authenticated ) {
			return $endpoints;
		}

		$post_routes = array( '/wp/v2/posts', '/wp/v2/pages' );

		foreach ( $post_routes as $route ) {
			if ( ! isset( $endpoints[ $route ] ) ) {
				continue;
			}

			foreach ( $endpoints[ $route ] as &$handler ) {
				if (
					! isset( $handler['methods'] ) ||
					( 'GET' !== $handler['methods'] && false === strpos( $handler['methods'], 'GET' ) )
				) {
					continue;
				}

				$handler['permission_callback'] = array( $this, 'allow_all_permissions' );

				$this->logger->log_event(
					'PERMISSION_CALLBACK_OVERRIDDEN',
					array(
						'route'   => $route,
						'methods' => $handler['methods'],
					)
				);
			}
		}

		return $endpoints;
	}

	/**
	 * Overrides collection parameters to allow edit context for Safe Publish.
	 *
	 * @param array        $params    Collection parameters.
	 * @param WP_Post_Type $post_type Post type object.
	 * @return array Modified collection parameters.
	 */
	public function override_collection_params(
		array $params,
		WP_Post_Type $post_type
	): array {
		if ( ! $this->authenticated ) {
			return $params;
		}

		if ( ! isset( $params['context'] ) ) {
			return $params;
		}

		$params['context']['default'] = 'edit';
		unset( $params['context']['required'] );

		$this->logger->log_event(
			'COLLECTION_PARAMS_OVERRIDDEN',
			array(
				'post_type'       => $post_type->name,
				'default_context' => 'edit',
			)
		);

		return $params;
	}

	/**
	 * Overrides context permissions for REST API responses.
	 *
	 * Specifically handles the rest_forbidden_context error by re-dispatching
	 * the request with elevated permissions.
	 *
	 * @param WP_REST_Response|WP_Error $result  Response object.
	 * @param WP_REST_Server            $server  Server instance.
	 * @param WP_REST_Request           $request Request object.
	 * @return WP_REST_Response|WP_Error Modified or re-dispatched response.
	 */
	public function override_context_permissions(
		WP_REST_Response|WP_Error $result,
		WP_REST_Server $server,
		WP_REST_Request $request
	): WP_REST_Response|WP_Error {
		if ( ! $this->authenticated ) {
			return $result;
		}

		// Prevent infinite recursion if re-dispatch also returns a forbidden error.
		if ( $this->context_override ) {
			return $result;
		}

		if ( ! is_wp_error( $result ) || 'rest_forbidden_context' !== $result->get_error_code() ) {
			return $result;
		}

		$this->logger->log_event(
			'CONTEXT_ERROR_OVERRIDDEN',
			array(
				'original_error' => $result->get_error_message(),
				'route'          => $request->get_route(),
				'method'         => $request->get_method(),
				'context'        => $request->get_param( 'context' ),
			)
		);

		$this->context_override = true;

		// Temporarily grant all capabilities and re-dispatch.
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

		$new_result = $server->dispatch( $request );

		$this->context_override = false;

		return $new_result;
	}

	/**
	 * Ensures edit context access for Safe Publish authenticated requests.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_Post          $post     Post object.
	 * @param WP_REST_Request  $request  Request object.
	 * @return WP_REST_Response Response object, unchanged.
	 */
	public function ensure_edit_context_access(
		WP_REST_Response $response,
		WP_Post $post,
		WP_REST_Request $request
	): WP_REST_Response {
		if ( ! $this->authenticated ) {
			return $response;
		}

		add_filter(
			'user_has_cap',
			function ( $allcaps, $_caps, $_args, $_user ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$allcaps['edit_posts']         = true;
				$allcaps['edit_others_posts']  = true;
				$allcaps['edit_private_posts'] = true;
				$allcaps['read_private_posts'] = true;
				return $allcaps;
			},
			999,
			4
		);

		$this->logger->log_event(
			'EDIT_CONTEXT_ACCESS_ENSURED',
			array(
				'post_id'   => $post->ID,
				'post_type' => $post->post_type,
				'context'   => $request->get_param( 'context' ),
			)
		);

		return $response;
	}

	/**
	 * Bypasses additional permission checks for Safe Publish authenticated requests.
	 *
	 * @param WP_REST_Response|WP_Error|null $result  Response to replace the requested version with.
	 * @param WP_REST_Server                 $_server Server instance.
	 * @param WP_REST_Request                $_request Request used to generate the response.
	 * @return WP_REST_Response|WP_Error|null Original result, unchanged.
	 */
	public function bypass_permission_checks( // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		WP_REST_Response|WP_Error|null $result,
		WP_REST_Server $_server,
		WP_REST_Request $_request
	): WP_REST_Response|WP_Error|null {
		if ( ! $this->authenticated ) {
			return $result;
		}

		add_filter( 'rest_allow_anonymous_comments', '__return_true' );
		add_filter( 'rest_prepare_post', array( $this, 'prepare_post_for_edit_context' ), 10, 3 );
		add_filter( 'rest_prepare_page', array( $this, 'prepare_post_for_edit_context' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'ensure_response_success' ), 10, 3 );

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
	public function prepare_post_for_edit_context(
		WP_REST_Response $response,
		WP_Post $post,
		WP_REST_Request $request
	): WP_REST_Response {
		if ( ! $this->authenticated ) {
			return $response;
		}

		if ( 'edit' === $request->get_param( 'context' ) ) {
			$this->logger->log_event(
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
	 * Logs permission errors for diagnostic purposes.
	 *
	 * @param WP_REST_Response|WP_Error $response Response object.
	 * @param WP_REST_Server            $_server  Server instance.
	 * @param WP_REST_Request           $request  Request used to generate the response.
	 * @return WP_REST_Response|WP_Error Response, potentially modified.
	 */
	public function ensure_response_success(
		WP_REST_Response|WP_Error $response,
		WP_REST_Server $_server, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		WP_REST_Request $request
	): WP_REST_Response|WP_Error {
		if ( ! $this->authenticated ) {
			return $response;
		}

		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		$error_code = $response->get_error_code();

		if ( ! in_array( $error_code, array( 'rest_forbidden', 'rest_cannot_edit', 'rest_forbidden_context' ), true ) ) {
			return $response;
		}

		$this->logger->log_event(
			'PERMISSION_ERROR_INTERCEPTED',
			array(
				'error_code'    => $error_code,
				'error_message' => $response->get_error_message(),
				'route'         => $request->get_route(),
				'method'        => $request->get_method(),
				'context'       => $request->get_param( 'context' ),
			)
		);

		if ( 'rest_forbidden_context' === $error_code ) {
			// Fallback logging — the proper fix is in the capability system above.
			$this->logger->log_event(
				'CONTEXT_PERMISSION_OVERRIDE_NEEDED',
				array(
					'route'          => $request->get_route(),
					'original_error' => $response->get_error_message(),
				)
			);
		}

		return $response;
	}

	/**
	 * Logs a CONTENT_EXPORTED event after a successful authenticated export.
	 *
	 * Fires on rest_post_dispatch at priority 20, so it runs after all
	 * permission overrides and context re-dispatches are complete. Skipped
	 * during context-override re-dispatch to avoid duplicate entries.
	 *
	 * @param WP_REST_Response|WP_Error $response Response object.
	 * @param WP_REST_Server            $_server  Server instance.
	 * @param WP_REST_Request           $request  Request used to generate the response.
	 * @return WP_REST_Response|WP_Error Unmodified response.
	 */
	public function log_export_event(
		WP_REST_Response|WP_Error $response,
		WP_REST_Server $_server, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		WP_REST_Request $request
	): WP_REST_Response|WP_Error {
		if ( ! $this->authenticated || $this->context_override ) {
			return $response;
		}

		$route = $request->get_route();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__HTTP_USER_AGENT__
		$raw_user_agent  = $_SERVER['HTTP_USER_AGENT'] ?? '';
		$destination_url = $this->parse_destination_url( $raw_user_agent );

		if ( is_wp_error( $response ) ) {
			$this->export_logger->log_error(
				'EXPORT_FAILED',
				array(
					'route'           => $route,
					'destination_url' => $destination_url,
					'error_code'      => $response->get_error_code(),
					'error_message'   => $response->get_error_message(),
				)
			);
			return $response;
		}

		$status = $response->get_status();

		if ( 200 !== $status ) {
			$this->export_logger->log_error(
				'EXPORT_FAILED',
				array(
					'route'           => $route,
					'destination_url' => $destination_url,
					'status'          => $status,
				)
			);
			return $response;
		}

		if ( 1 === preg_match( '#^/wp/v2/([^/]+)$#', $route, $matches ) ) {
			$data     = $response->get_data();
			$post_ids = is_array( $data ) ? array_values( array_filter( array_column( $data, 'id' ), 'is_int' ) ) : array();

			$this->export_logger->log_event(
				'CONTENT_EXPORTED',
				array(
					'rest_base'       => $matches[1],
					'destination_url' => $destination_url,
					'post_ids'        => $post_ids,
					'post_count'      => count( $post_ids ),
				)
			);
		} elseif ( 1 === preg_match( '#^/wp/v2/([^/]+)/(\d+)$#', $route, $matches ) ) {
			$data    = $response->get_data();
			$post_id = is_array( $data ) && isset( $data['id'] ) && is_int( $data['id'] ) ? $data['id'] : (int) $matches[2];

			$this->export_logger->log_event(
				'CONTENT_EXPORTED',
				array(
					'rest_base'       => $matches[1],
					'destination_url' => $destination_url,
					'post_ids'        => array( $post_id ),
					'post_count'      => 1,
				)
			);
		}

		return $response;
	}

	/**
	 * Extracts the destination site URL from a Safe Publish User-Agent string.
	 *
	 * The destination sends: "Safe Publish/VERSION; https://dest.example.com".
	 * Returns the URL portion, or the full string if the expected format is not
	 * matched.
	 *
	 * @param string $user_agent Raw HTTP_USER_AGENT value.
	 * @return string Destination URL, or empty string if header is absent.
	 */
	private function parse_destination_url( string $user_agent ): string {
		if ( '' === $user_agent ) {
			return '';
		}

		// Format: "Safe Publish/x.y.z; https://dest.example.com".
		$parts = explode( '; ', $user_agent, 2 );

		return isset( $parts[1] ) ? trim( $parts[1] ) : $user_agent;
	}
}
