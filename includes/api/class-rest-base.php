<?php
/**
 * REST Base class
 *
 * @package CCP
 */

declare(strict_types = 1);

namespace CCP\API;

use CCP\Auth\VIP_Safe_Auth;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST Base Class.
 */
abstract class REST_Base {

	/**
	 * REST API base route.
	 *
	 * @var string
	 */
	const REST_BASE = 'ccp/v1';

	/**
	 * HTTP Client instance.
	 *
	 * @var HTTP_Client
	 */
	protected $http_client;

	/**
	 * Constructs the REST_Base instance.
	 */
	public function __construct() {
		$this->http_client = new HTTP_Client();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers REST API routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * Makes HTTP request using shared HTTP client.
	 *
	 * @param string $url              Request URL.
	 * @param array  $auth_credentials Optional. Authentication credentials. Default empty array.
	 * @param array  $additional_args  Optional. Additional request arguments. Default empty array.
	 * @return array|\WP_Error Response or error.
	 */
	public function make_request( string $url, array $auth_credentials = array(), array $additional_args = array() ): array|\WP_Error {
		return $this->http_client->make_request( $url, $auth_credentials, $additional_args );
	}

	/**
	 * Gets user agent string.
	 *
	 * @return string
	 */
	public function get_user_agent(): string {
		return $this->http_client->get_user_agent();
	}

	/**
	 * Makes safe remote GET request with VIP compatibility.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Optional. Request arguments. Default empty array.
	 * @return array|\WP_Error Response or error.
	 */
	public function safe_remote_get( string $url, array $args = array() ): array|\WP_Error {
		return $this->http_client->safe_remote_get( $url, $args );
	}

	/**
	 * Checks if the current environment is a development environment.
	 *
	 * @return bool True if development environment.
	 */
	public function is_development_environment(): bool {
		return $this->http_client->is_development_environment();
	}

	/**
	 * Determines whether to verify SSL certificates based on environment and URL.
	 *
	 * @param string $url URL being requested.
	 * @return bool Whether to verify SSL certificates.
	 */
	public function should_verify_ssl( string $url ): bool {
		return $this->http_client->should_verify_ssl( $url );
	}
}
