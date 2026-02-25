<?php
/**
 * External Posts API class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\API;

use Safe_Publish\Validators\URL_Validator;
use Safe_Publish\Auth\VIP_Safe_Auth;

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
	 * Constructs the External_Posts_API instance.
	 *
	 * @param HTTP_Client|null $http_client Optional. HTTP client for making requests.
	 */
	public function __construct( ?HTTP_Client $http_client = null ) {
		$this->http_client = $http_client ?? new HTTP_Client();
	}

	/**
	 * Fetches posts from external site.
	 *
	 * @param string $site_url         External site URL.
	 * @param int    $number_of_posts  Optional. Number of posts to fetch. Default 10.
	 * @param array  $auth_credentials Optional. Authentication credentials array. Default empty array.
	 * @param string $post_type        Optional. Post type to fetch. Default 'posts'.
	 * @return array|\WP_Error Posts data or error.
	 */
	public function fetch_posts( string $site_url, int $number_of_posts = 10, array $auth_credentials = array(), string $post_type = 'posts' ): array|\WP_Error {
		// Validate URL first.
		if ( ! URL_Validator::is_valid_external_url( $site_url ) ) {
			return new \WP_Error(
				'invalid_url',
				__( 'Invalid URL provided.', 'safe-publish' )
			);
		}

		// Build API URL.
		$api_url = $this->build_api_url( $site_url, $number_of_posts, $auth_credentials, $post_type );

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
	 * @param string $site_url         Base site URL.
	 * @param int    $number_of_posts  Number of posts.
	 * @param array  $auth_credentials Optional. Authentication credentials. Default empty array.
	 * @param string $post_type        Optional. Post type to fetch. Default 'posts'.
	 * @return string Built API URL.
	 */
	private function build_api_url( string $site_url, int $number_of_posts, array $auth_credentials = array(), string $post_type = 'posts' ): string {
		// Use 'posts' as default endpoint for 'post' post type, otherwise use the post type slug.
		$endpoint     = ( 'post' === $post_type ) ? 'posts' : $post_type;
		$api_endpoint = trailingslashit( $site_url ) . 'wp-json/wp/v2/' . $endpoint;

		$query_args = array(
			'orderby'  => 'modified',
			'order'    => 'desc',
			'per_page' => min( $number_of_posts, 100 ), // Max 100 per request.
			// '_fields' => 'id,link,title,modified,featured_media,content,excerpt', // Fetch all needed fields.
			'_embed'   => '1',
		);

		// Add edit context if we have authentication credentials.
		// This allows us to get raw content data including Gutenberg blocks.
		if ( VIP_Safe_Auth::is_authorized( $site_url, $auth_credentials ) ) {
			$query_args['context'] = 'edit'; // Get raw edit data for Gutenberg blocks.
		}

		/**
		 * Filters API query arguments.
		 *
		 * @param array  $query_args      Query arguments.
		 * @param string $site_url        Site URL.
		 * @param int    $number_of_posts Number of posts.
		 */
		$query_args = apply_filters( 'safe_publish_api_query_args', $query_args, $site_url, $number_of_posts );

		$final_url = add_query_arg( $query_args, $api_endpoint );

		return $final_url;
	}

	/**
	 * Makes HTTP request using shared HTTP client.
	 *
	 * @param string $url              Request URL.
	 * @param array  $auth_credentials Optional. Authentication credentials. Default empty array.
	 * @return array|\WP_Error Response or error.
	 */
	private function make_request( string $url, array $auth_credentials = array() ): array|\WP_Error {
		return $this->http_client->make_request( $url, $auth_credentials );
	}

	/**
	 * Processes API response.
	 *
	 * @param array  $response  HTTP response.
	 * @param string $post_type Optional. Post type being fetched. Default 'posts'.
	 * @return array|\WP_Error Processed posts or error.
	 */
	private function process_response( array $response, string $post_type = 'posts' ): array|\WP_Error {
		$body  = wp_remote_retrieve_body( $response );
		$posts = json_decode( $body, true );

		if ( ! is_array( $posts ) ) {
			return new \WP_Error(
				'invalid_response',
				__( 'Invalid response from external API.', 'safe-publish' ),
				array( 'response_body' => $body )
			);
		}

		// Sanitize and filter posts.
		$filtered_posts = array();
		foreach ( $posts as $post ) {
			$filtered_post = $this->sanitize_post( $post, $post_type );
			if ( $filtered_post ) {
				$filtered_posts[] = $filtered_post;
			}
		}

		return $filtered_posts;
	}

	/**
	 * Sanitizes a single post.
	 *
	 * @param array  $post      Raw post data.
	 * @param string $post_type Optional. Post type being processed. Default 'posts'.
	 * @return array|false Sanitized post or false if invalid.
	 */
	private function sanitize_post( array $post, string $post_type = 'posts' ): array|false {
		if ( ! is_array( $post ) ) {
			return false;
		}

		$sanitized_post = array(
			'id'             => isset( $post['id'] ) ? absint( $post['id'] ) : 0,
			'link'           => isset( $post['link'] ) ? esc_url( $post['link'] ) : '#',
			'title'          => isset( $post['title']['rendered'] ) ? sanitize_text_field( wp_strip_all_tags( $post['title']['rendered'] ) ) : __( 'No Title', 'safe-publish' ),
			'modified'       => isset( $post['modified'] ) ? sanitize_text_field( $post['modified'] ) : '',
			'thumbnail'      => isset( $post['featured_media'] ) ? esc_url( get_the_post_thumbnail_url( $post['id'], 'thumbnail' ) ) : '', // Default to empty if no thumbnail.
			'featured_media' => isset( $post['featured_media'] ) ? absint( $post['featured_media'] ) : 0,
			// Add any other fields you need to sanitize.
			'content'        => isset( $post['content']['raw'] ) ? $post['content']['raw'] : // Use raw content without sanitization to preserve formatting.
				( isset( $post['content']['rendered'] ) ? $post['content']['rendered'] : // Fallback to rendered content without sanitization.
					'' ),
			'excerpt'        => isset( $post['excerpt']['rendered'] ) ? wp_kses_post( $post['excerpt']['rendered'] ) : '',
			'post_type'      => sanitize_text_field( $post_type ), // Add the post type.
			'meta'           => isset( $post['meta'] ) && is_array( $post['meta'] ) ? $post['meta'] : array(),
			'terms'          => isset( $post['_embedded'] ) && is_array( $post['_embedded'] ) ? $post['_embedded'] : array(), // Use _embedded for terms and related data.
		);

		// Validate required fields.
		if ( empty( $sanitized_post['id'] ) || empty( $sanitized_post['title'] ) ) {
			return false;
		}

		$sanitized_post['meta'] = isset( $post['meta'] ) && is_array( $post['meta'] ) ? $post['meta'] : array();

		// Extract terms from embedded response if available.
		$incoming_terms = array();
		if ( ! empty( $post['_embedded']['wp:term'] ) && is_array( $post['_embedded']['wp:term'] ) ) {
			foreach ( $post['_embedded']['wp:term'] as $term_group ) {
				foreach ( $term_group as $term ) {
					$tax = isset( $term['taxonomy'] ) ? $term['taxonomy'] : 'term';
					if ( ! isset( $incoming_terms[ $tax ] ) ) {
						$incoming_terms[ $tax ] = array();
					}
					$incoming_terms[ $tax ][] = isset( $term['name'] ) ? $term['name'] : '';
				}
			}
		}

		$sanitized_post['terms'] = $incoming_terms;

		/**
		 * Filters sanitized post data.
		 *
		 * @param array $sanitized_post Sanitized post data.
		 * @param array $post           Original post data.
		 */
		return apply_filters( 'safe_publish_sanitized_post', $sanitized_post, $post );
	}

	/**
	 * Tests API connection.
	 *
	 * @param string $site_url Site URL to test.
	 * @return array Test results.
	 */
	public function test_connection( string $site_url ): array {
		$test_url = trailingslashit( $site_url ) . 'wp-json/wp/v2/posts?per_page=1&_fields=id';

		$start_time = microtime( true );
		$response   = $this->make_request( $test_url, array() );
		$end_time   = microtime( true );

		$results = array(
			'success'       => false,
			'response_time' => round( ( $end_time - $start_time ) * 1000, 2 ),
			'message'       => '',
		);


		if ( is_wp_error( $response ) ) {
			$results['message'] = $response->get_error_message();
		} else {
			$results['success'] = true;
			$results['message'] = __( 'Connection successful.', 'safe-publish' );
		}

		return $results;
	}

	/**
	 * Fetches fresh post content from external site.
	 *
	 * @param int    $external_post_id  External post ID.
	 * @param string $site_url          Site URL.
	 * @param array  $auth_credentials  Optional. Authentication credentials. Default empty array.
	 * @return array|false Post data array on success, false on failure.
	 */
	public function fetch_fresh_post_content( int $external_post_id, string $site_url, array $auth_credentials = array() ): array|false {
		// Validate URL first.
		if ( ! URL_Validator::is_valid_external_url( $site_url ) ) {
			return false;
		}

		// Build API URL for single post.
		$api_endpoint = trailingslashit( $site_url ) . 'wp-json/wp/v2/posts/' . $external_post_id;

		$query_args = array(
			'_embed' => '1',
			/**
			 * TODO: Check if we want/need this.
			 *
			 * '_fields' => 'id,link,title,modified,featured_media,content,excerpt,tags,categories,meta', // Fetch all needed fields
			 */
		);

		// If user and password are provided add edit context.
		if ( ! empty( $auth_credentials['username'] ) && ! empty( $auth_credentials['password'] ) ) {
			$query_args['context'] = 'edit'; // Get raw edit data for Gutenberg blocks.
		}

		$api_url = add_query_arg( $query_args, $api_endpoint );

		// Make request.
		$response = $this->make_request( $api_url, $auth_credentials );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}

		// Extract post data.
		$post_data = array();

		if ( isset( $data['title']['rendered'] ) ) {
			$post_data['title'] = $data['title']['rendered'];
		}

		// Prioritize raw content when available (edit context), fallback to rendered.
		if ( isset( $data['content']['raw'] ) ) {
			$post_data['content'] = $data['content']['raw'];
		} elseif ( isset( $data['content']['rendered'] ) ) {
			$post_data['content'] = $data['content']['rendered'];
		}

		if ( isset( $data['featured_media'] ) && $data['featured_media'] > 0 ) {
			$post_data['featured_media'] = $data['featured_media'];
		}

		if ( isset( $data['link'] ) ) {
			$post_data['link'] = $data['link'];
		}

		$post_data['excerpt'] = isset( $data['excerpt']['rendered'] ) ? $data['excerpt']['rendered'] : '';

		$post_data['meta'] = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		// Extract terms from embedded response if available.
		$incoming_terms = array();
		if ( ! empty( $data['_embedded']['wp:term'] ) && is_array( $data['_embedded']['wp:term'] ) ) {
			foreach ( $data['_embedded']['wp:term'] as $term_group ) {
				foreach ( $term_group as $term ) {
					$tax = isset( $term['taxonomy'] ) ? $term['taxonomy'] : 'term';
					if ( ! isset( $incoming_terms[ $tax ] ) ) {
						$incoming_terms[ $tax ] = array();
					}
					$incoming_terms[ $tax ][] = isset( $term['name'] ) ? $term['name'] : '';
				}
			}
		}

		$post_data['terms'] = $incoming_terms;

		return $post_data;
	}
}
