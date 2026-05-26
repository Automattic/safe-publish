<?php
/**
 * Post Type Fetcher class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\API;

use Safe_Publish\Validators\URL_Validator;
use WP_Error;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post Type Fetcher Class.
 *
 * Handles fetching and filtering post types from source WordPress sites.
 */
class Post_Type_Fetcher {

	/**
	 * HTTP Client instance.
	 *
	 * @var HTTP_Client
	 */
	private HTTP_Client $http_client;

	/**
	 * Constructs the Post_Type_Fetcher instance.
	 *
	 * @param HTTP_Client $http_client HTTP client for making requests.
	 */
	public function __construct( HTTP_Client $http_client ) {
		$this->http_client = $http_client;
	}

	/**
	 * Fetches available post types from source site.
	 *
	 * @param string $source_site_url  Source site URL.
	 * @param array  $auth_credentials Optional. Authentication credentials array. Default empty array.
	 * @return array|WP_Error Post types data or error.
	 */
	public function fetch_post_types( string $source_site_url, array $auth_credentials = array() ): array|WP_Error {
		// Validate URL first.
		if ( ! URL_Validator::is_valid_external_url( $source_site_url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'Invalid URL provided.', 'safe-publish' )
			);
		}

		// Build API URL for post types.
		$api_url = trailingslashit( $source_site_url ) . 'wp-json/wp/v2/types';

		// Make request.
		$response = $this->http_client->make_request(
			$api_url,
			Request_Actions::LIST_ITEMS,
			$auth_credentials
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body   = wp_remote_retrieve_body( $response );
		$post_types_data = json_decode( $response_body, true );

		// Check for authentication error responses.
		if ( is_array( $post_types_data ) && isset( $post_types_data['code'] ) ) {
			return new WP_Error(
				'api_error',
				$post_types_data['message'] ?? __( 'Unknown API error occurred.', 'safe-publish' )
			);
		}

		if ( empty( $post_types_data ) || ! is_array( $post_types_data ) ) {
			$error_msg = sprintf(
				/* translators: %s: Response body snippet */
				__( 'No post types found. Response: %s', 'safe-publish' ),
				substr( $response_body, 0, 200 ) . ( strlen( $response_body ) > 200 ? '…' : '' )
			);
			return new WP_Error(
				'no_post_types',
				$error_msg
			);
		}

		// Filter to only show post types that support REST API.
		return $this->filter_rest_enabled_post_types( $post_types_data );
	}

	/**
	 * Filters post types to only those the catalog will actually serve.
	 *
	 * Requires both a `rest_base` AND `viewable === true` so the dropdown
	 * matches the Catalog_REST_Controller's `public && show_in_rest`
	 * contract — back-office CPTs would otherwise appear in the picker but
	 * fail with a 400 when selected.
	 *
	 * @param array $post_types_data Raw post types data from API.
	 * @return array Filtered post types.
	 */
	private function filter_rest_enabled_post_types( array $post_types_data ): array {
		$filtered_post_types = array();

		foreach ( $post_types_data as $slug => $post_type ) {
			if ( ! isset( $post_type['rest_base'] ) || '' === $post_type['rest_base'] ) {
				continue;
			}

			if ( true !== ( $post_type['viewable'] ?? false ) ) {
				continue;
			}

			$filtered_post_types[ $slug ] = array(
				'slug'        => $slug,
				'name'        => $post_type['name'] ?? $slug,
				'label'       => $post_type['name'] ?? $slug,
				'rest_base'   => $post_type['rest_base'],
				'description' => $post_type['description'] ?? '',
			);
		}

		return $filtered_post_types;
	}
}
