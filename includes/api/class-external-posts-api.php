<?php
/**
 * External Posts API class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\API;

use Safe_Publish\Admin\Content_Logger;
use Safe_Publish\Auth\VIP_Safe_Auth;
use Safe_Publish\Utils\Auth_Credential_Provider;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Post_Type_Map;
use Safe_Publish\Validators\URL_Validator;
use WP_Error;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * External Posts API Class.
 */
class External_Posts_API {

	/**
	 * HTTP Client instance.
	 *
	 * @var HTTP_Client
	 */
	private HTTP_Client $http_client;

	/**
	 * Logger instance.
	 *
	 * @var Content_Logger
	 */
	private Content_Logger $logger;

	/**
	 * Constructs the External_Posts_API instance.
	 *
	 * @param HTTP_Client|null $http_client Optional. HTTP client for making requests.
	 */
	public function __construct( ?HTTP_Client $http_client = null ) {
		$this->http_client = $http_client ?? new HTTP_Client();
		$this->logger      = new Content_Logger();
	}

	/**
	 * Extracts taxonomy terms from an embedded REST API response.
	 *
	 * Parses the `wp:term` embedded data and groups term names by taxonomy.
	 * Terms with empty names are skipped.
	 *
	 * @param array $response_data Decoded REST API response for a single post.
	 * @return array<string, list<string>> Term names grouped by taxonomy slug.
	 */
	public static function extract_embedded_terms( array $response_data ): array {
		$terms = array();

		if (
			! isset( $response_data['_embedded']['wp:term'] ) ||
			! is_array( $response_data['_embedded']['wp:term'] ) ||
			count( $response_data['_embedded']['wp:term'] ) === 0
		) {
			return $terms;
		}

		foreach ( $response_data['_embedded']['wp:term'] as $term_group ) {
			foreach ( $term_group as $term ) {
				$tax = isset( $term['taxonomy'] ) ? $term['taxonomy'] : 'term';
				if ( ! isset( $terms[ $tax ] ) ) {
					$terms[ $tax ] = array();
				}
				if ( isset( $term['name'] ) && '' !== $term['name'] ) {
					$terms[ $tax ][] = $term['name'];
				}
			}
		}

		return $terms;
	}

	/**
	 * Fetches posts from external site.
	 *
	 * @param string $source_site_url  Source site URL.
	 * @param int    $number_of_posts  Optional. Number of posts to fetch. Default 10.
	 * @param array  $auth_credentials Optional. Authentication credentials array. Default empty array.
	 * @param string $post_type        Optional. Post type to fetch. Default 'posts'.
	 * @return array|WP_Error Posts data or error.
	 */
	public function fetch_posts( string $source_site_url, int $number_of_posts = 10, array $auth_credentials = array(), string $post_type = 'posts' ): array|WP_Error {
		// Validate URL first.
		if ( ! URL_Validator::is_valid_external_url( $source_site_url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'Invalid URL provided.', 'safe-publish' )
			);
		}

		// Build API URL.
		$api_url = $this->build_api_url( $source_site_url, $number_of_posts, $auth_credentials, $post_type );

		// Make request.
		$response = $this->make_request( $api_url, $auth_credentials );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Process response.
		$posts = $this->process_response( $response, $post_type );

		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		return $posts;
	}

	/**
	 * Builds API URL.
	 *
	 * @param string $source_site_url  Source site URL.
	 * @param int    $number_of_posts  Number of posts.
	 * @param array  $auth_credentials Optional. Authentication credentials. Default empty array.
	 * @param string $post_type        Optional. Post type to fetch. Default 'posts'.
	 * @return string Built API URL.
	 */
	private function build_api_url( string $source_site_url, int $number_of_posts, array $auth_credentials = array(), string $post_type = 'posts' ): string {
		$endpoint     = Post_Type_Map::to_rest_endpoint( $post_type );
		$api_endpoint = trailingslashit( $source_site_url ) . 'wp-json/wp/v2/' . $endpoint;

		$query_args = array(
			'orderby'  => 'modified',
			'order'    => 'desc',
			'per_page' => min( $number_of_posts, 100 ), // Max 100 per request.
			// '_fields' => 'id,link,title,modified,featured_media,content,excerpt,slug,comment_status,ping_status,menu_order', // Fetch all needed fields.
			'_embed'   => '1',
		);

		// Edit context provides raw field values (title, content, excerpt)
		// needed to preserve data parity during import.
		if ( VIP_Safe_Auth::has_valid_credential_format( $auth_credentials ) ) {
			$query_args['context'] = 'edit';
		}

		/**
		 * Filters API query arguments.
		 *
		 * @param array  $query_args      Query arguments.
		 * @param string $source_site_url Source site URL.
		 * @param int    $number_of_posts Number of posts.
		 */
		$query_args = apply_filters( 'safe_publish_api_query_args', $query_args, $source_site_url, $number_of_posts );

		$final_url = add_query_arg( $query_args, $api_endpoint );

		return $final_url;
	}

	/**
	 * Makes HTTP request using shared HTTP client.
	 *
	 * @param string $url              Request URL.
	 * @param array  $auth_credentials Optional. Authentication credentials. Default empty array.
	 * @return array|WP_Error Response or error.
	 */
	private function make_request( string $url, array $auth_credentials = array() ): array|WP_Error {
		return $this->http_client->make_request( $url, $auth_credentials );
	}

	/**
	 * Processes API response.
	 *
	 * @param array  $response  HTTP response.
	 * @param string $post_type Optional. Post type being fetched. Default 'posts'.
	 * @return array|WP_Error Processed posts or error.
	 */
	private function process_response( array $response, string $post_type = 'posts' ): array|WP_Error {
		$body  = wp_remote_retrieve_body( $response );
		$posts = json_decode( $body, true );

		if ( ! is_array( $posts ) ) {
			return new WP_Error(
				'invalid_response',
				__( 'Invalid response from external API.', 'safe-publish' ),
				array( 'response_body' => $body )
			);
		}

		// Prepare posts for the listing UI.
		$filtered_posts = array();
		foreach ( $posts as $post ) {
			$filtered_post = $this->prepare_post_for_listing( $post, $post_type );
			if ( $filtered_post ) {
				$filtered_posts[] = $filtered_post;
			}
		}

		return $filtered_posts;
	}

	/**
	 * Prepares a single post for display in the admin listing UI.
	 *
	 * The output is consumed for display only and never stored; the actual
	 * import re-fetches via fetch_fresh_post_content() to obtain raw values.
	 *
	 * @param array  $post      Raw post data from the REST API.
	 * @param string $post_type Post type being listed. Default 'posts'.
	 * @return array|false Prepared post or false if invalid.
	 */
	private function prepare_post_for_listing( array $post, string $post_type = 'posts' ): array|false {
		if ( ! is_array( $post ) ) {
			return false;
		}

		$prepared_post = array(
			'id'             => isset( $post['id'] ) ? absint( $post['id'] ) : 0,
			'link'           => isset( $post['link'] ) ? esc_url_raw( $post['link'] ) : '#',
			'title'          => isset( $post['title']['rendered'] )
				? sanitize_text_field(
					wp_strip_all_tags(
						html_entity_decode(
							$post['title']['rendered'],
							ENT_QUOTES | ENT_HTML5,
							'UTF-8'
						)
					)
				)
				: __( 'No Title', 'safe-publish' ),
			'modified_gmt'   => isset( $post['modified_gmt'] )
				? sanitize_text_field( $post['modified_gmt'] ) . 'Z'
				: '',
			'thumbnail'      => isset( $post['featured_media'] ) ? esc_url( get_the_post_thumbnail_url( $post['id'], 'thumbnail' ) ) : '',
			'featured_media' => isset( $post['featured_media'] ) ? absint( $post['featured_media'] ) : 0,
			'excerpt'        => isset( $post['excerpt']['rendered'] ) ? wp_kses_post( $post['excerpt']['rendered'] ) : '',
			'post_type'      => sanitize_text_field( $post_type ),
			'content'        => isset( $post['content']['rendered'] ) ? $post['content']['rendered'] : '',
			'meta'           => isset( $post['meta'] ) && is_array( $post['meta'] ) ? $post['meta'] : array(),
		);

		// Validate required fields.
		if ( 0 === $prepared_post['id'] || '' === $prepared_post['title'] ) {
			return false;
		}

		$prepared_post['terms'] = self::extract_embedded_terms( $post );

		/**
		 * Filters post data prepared for the listing UI.
		 *
		 * @param array $prepared_post Prepared post data.
		 * @param array $post          Original post data.
		 */
		return apply_filters( 'safe_publish_sanitized_post', $prepared_post, $post );
	}

	/**
	 * Tests API connection.
	 *
	 * Delegates to the shared-secret probe so the result reflects whether the
	 * connected site actually grants edit context, not just whether a public
	 * endpoint responds.
	 *
	 * @param string $connected_site_url Connected site URL to test.
	 * @param array  $auth_credentials   Authentication credentials.
	 * @return array Test results: success, status, response_time, message.
	 */
	public function test_connection(
		string $connected_site_url,
		array $auth_credentials
	): array {
		$start_time = microtime( true );
		$probe      = VIP_Safe_Auth::test_authorization(
			$connected_site_url,
			$auth_credentials
		);
		$end_time   = microtime( true );

		$status = $probe['status'] ?? VIP_Safe_Auth::STATUS_UNREACHABLE;

		return array(
			'success'       => VIP_Safe_Auth::STATUS_AUTHORIZED === $status,
			'status'        => $status,
			'response_time' => round( ( $end_time - $start_time ) * 1000, 2 ),
			'message'       => self::describe_auth_status( $status ),
		);
	}

	/**
	 * Returns a human-readable message for an auth probe status.
	 *
	 * @param string $status Status from VIP_Safe_Auth::test_authorization().
	 * @return string Translated description for display.
	 */
	public static function describe_auth_status( string $status ): string {
		switch ( $status ) {
			case VIP_Safe_Auth::STATUS_AUTHORIZED:
				return __(
					'Connected site accepts the shared secret and grants edit context.',
					'safe-publish'
				);
			case VIP_Safe_Auth::STATUS_UNAUTHORIZED:
				return __(
					'Connected site rejected the shared secret. Verify SAFE_PUBLISH_SHARED_SECRET matches on both sites in wp-config.php.',
					'safe-publish'
				);
			case VIP_Safe_Auth::STATUS_UNREACHABLE:
				return __(
					'Connected site could not be reached. Verify the URL and that the site is online.',
					'safe-publish'
				);
			case VIP_Safe_Auth::STATUS_URL_UNSET:
				return __(
					'Connected site URL is not configured.',
					'safe-publish'
				);
			default:
				return __( 'Unknown authentication status.', 'safe-publish' );
		}
	}

	/**
	 * Fetches fresh post content from external site.
	 *
	 * `content`, `meta`, and `terms` are returned unsanitized. `content` must
	 * pass through the block processor first, and `meta`/`terms` require
	 * type-aware sanitization in Meta_Terms_Manager.
	 *
	 * @param int    $external_post_id  External post ID.
	 * @param string $source_site_url   Source site URL.
	 * @param array  $auth_credentials  Optional. Authentication credentials. Default empty array.
	 * @param string $post_type         Optional. Post type slug or REST endpoint. Default 'posts'.
	 * @return array|false Post data array on success, false on failure.
	 */
	public function fetch_fresh_post_content(
		int $external_post_id,
		string $source_site_url,
		array $auth_credentials = array(),
		string $post_type = 'posts'
	): array|false {
		// Validate URL first.
		if ( ! URL_Validator::is_valid_external_url( $source_site_url ) ) {
			return false;
		}

		// Build API URL for single post.
		$endpoint     = Post_Type_Map::to_rest_endpoint( $post_type );
		$api_endpoint = trailingslashit( $source_site_url ) . 'wp-json/wp/v2/' . $endpoint . '/' . $external_post_id;

		$query_args = array(
			'_embed' => '1',
			/**
			 * TODO: Check if we want/need this.
			 *
			 * '_fields' => 'id,link,title,modified,featured_media,content,excerpt,tags,categories,meta,slug,comment_status,ping_status,menu_order,password', // Fetch all needed fields
			 */
		);

		// Edit context provides raw field values (title, content, excerpt)
		// needed to preserve data parity during import.
		if ( VIP_Safe_Auth::has_valid_credential_format( $auth_credentials ) ) {
			$query_args['context'] = 'edit';
		}

		$api_url = add_query_arg( $query_args, $api_endpoint );

		// Make request.
		$response = $this->make_request( $api_url, $auth_credentials );

		if ( is_wp_error( $response ) ) {
			$this->logger->content_fetch_failed(
				$external_post_id,
				$source_site_url,
				$response->get_error_message()
			);

			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || array() === $data ) {
			$this->logger->content_fetch_invalid_response(
				$external_post_id,
				$source_site_url
			);

			return false;
		}

		// Require raw field values (edit context) to preserve data parity.
		if (
			! isset( $data['title']['raw'] ) ||
			! isset( $data['content']['raw'] ) ||
			! isset( $data['excerpt']['raw'] )
		) {
			$this->logger->content_fetch_raw_unavailable(
				$external_post_id,
				$source_site_url
			);

			return false;
		}

		// Extract post data.
		$post_data = array();

		$post_data['title']          = sanitize_text_field( $data['title']['raw'] );
		$post_data['featured_media'] = absint( $data['featured_media'] ?? 0 );
		$post_data['slug']           = sanitize_text_field( $data['slug'] ?? '' );
		$post_data['comment_status'] = sanitize_text_field( $data['comment_status'] ?? '' );
		$post_data['ping_status']    = sanitize_text_field( $data['ping_status'] ?? '' );
		$post_data['menu_order']     = absint( $data['menu_order'] ?? 0 );
		$post_data['password']       = sanitize_text_field( $data['password'] ?? '' );

		if ( isset( $data['link'] ) ) {
			$post_data['link'] = esc_url_raw( $data['link'] );
		}

		// HTML fields: sanitized at the import point with modification
		// detection to prevent silent data loss during migration.
		$post_data['content'] = $data['content']['raw'];
		$post_data['excerpt'] = $data['excerpt']['raw'];

		$post_data['meta'] = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		$post_data['terms'] = self::extract_embedded_terms( $data );

		return $post_data;
	}

	/**
	 * Fetches fresh post content using the configured connected site URL.
	 *
	 * Convenience wrapper around fetch_fresh_post_content() that reads the
	 * connected site URL from options, obtains credentials, and converts
	 * the underlying false return into a WP_Error so callers can abort
	 * the import on a uniform error type.
	 *
	 * @param int    $external_post_id External post ID to fetch.
	 * @param string $post_type        Post type slug or REST endpoint.
	 * @return array|WP_Error Fresh post data, or an error on failure.
	 */
	public function fetch_fresh_post(
		int $external_post_id,
		string $post_type
	): array|WP_Error {
		$source_site_url = get_option( Options::OPTION_CONNECTED_SITE_URL, '' );

		if ( '' === $source_site_url ) {
			return new WP_Error(
				'fresh_content_fetch_no_connected_site_url',
				__( 'No connected site URL is configured.', 'safe-publish' )
			);
		}

		$auth_credentials = Auth_Credential_Provider::get_credentials();

		$fresh_data = $this->fetch_fresh_post_content(
			$external_post_id,
			$source_site_url,
			$auth_credentials,
			$post_type
		);

		if ( false === $fresh_data ) {
			return new WP_Error(
				'fresh_content_fetch_failed',
				__( 'Could not fetch fresh content from the source site. The post was not imported.', 'safe-publish' )
			);
		}

		return $fresh_data;
	}
}
