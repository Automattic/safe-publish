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
use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Logger;
use Safe_Publish\Utils\Post_Type_Map;
use Safe_Publish\Validators\URL_Validator;

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
	 * @var Logger
	 */
	private Logger $logger;

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
		$endpoint     = Post_Type_Map::to_rest_endpoint( $post_type );
		$api_endpoint = trailingslashit( $site_url ) . 'wp-json/wp/v2/' . $endpoint;

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
	 * Uses `rendered` field values since this data is display-only and never
	 * stored. The actual import always re-fetches via fetch_fresh_post_content()
	 * which requires raw values.
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
			'title'          => isset( $post['title']['rendered'] ) ? sanitize_text_field( wp_strip_all_tags( $post['title']['rendered'] ) ) : __( 'No Title', 'safe-publish' ),
			'modified'       => isset( $post['modified'] ) ? sanitize_text_field( $post['modified'] ) : '',
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
	 * source site actually grants edit context, not just whether a public
	 * endpoint responds.
	 *
	 * @param string $site_url         Site URL to test.
	 * @param array  $auth_credentials Authentication credentials.
	 * @return array Test results: success, status, response_time, message.
	 */
	public function test_connection(
		string $site_url,
		array $auth_credentials
	): array {
		$start_time = microtime( true );
		$probe      = VIP_Safe_Auth::test_authorization(
			$site_url,
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
					'Source site accepts the shared secret and grants edit context.',
					'safe-publish'
				);
			case VIP_Safe_Auth::STATUS_UNAUTHORIZED:
				return __(
					'Source site rejected the shared secret. Verify SAFE_PUBLISH_SHARED_SECRET matches on both sites in wp-config.php.',
					'safe-publish'
				);
			case VIP_Safe_Auth::STATUS_UNREACHABLE:
				return __(
					'Source site could not be reached. Verify the URL and that the site is online.',
					'safe-publish'
				);
			case VIP_Safe_Auth::STATUS_URL_UNSET:
				return __(
					'Source site URL is not configured.',
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
	 * @param string $site_url          Site URL.
	 * @param array  $auth_credentials  Optional. Authentication credentials. Default empty array.
	 * @param string $post_type         Optional. Post type slug or REST endpoint. Default 'posts'.
	 * @return array|false Post data array on success, false on failure.
	 */
	public function fetch_fresh_post_content(
		int $external_post_id,
		string $site_url,
		array $auth_credentials = array(),
		string $post_type = 'posts'
	): array|false {
		// Validate URL first.
		if ( ! URL_Validator::is_valid_external_url( $site_url ) ) {
			return false;
		}

		// Build API URL for single post.
		$endpoint     = Post_Type_Map::to_rest_endpoint( $post_type );
		$api_endpoint = trailingslashit( $site_url ) . 'wp-json/wp/v2/' . $endpoint . '/' . $external_post_id;

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
			$this->logger->log_error(
				Log_Events::CONTENT_FETCH_FAILED,
				array(
					'post_id'  => $external_post_id,
					'site_url' => $site_url,
					'error'    => $response->get_error_message(),
				)
			);

			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || array() === $data ) {
			$this->logger->log_error(
				Log_Events::CONTENT_FETCH_INVALID_RESPONSE,
				array(
					'post_id'  => $external_post_id,
					'site_url' => $site_url,
				)
			);

			return false;
		}

		// Require raw field values (edit context) to preserve data parity.
		if (
			! isset( $data['title']['raw'] ) ||
			! isset( $data['content']['raw'] ) ||
			! isset( $data['excerpt']['raw'] )
		) {
			$this->logger->log_error(
				Log_Events::CONTENT_FETCH_RAW_UNAVAILABLE,
				array(
					'post_id'  => $external_post_id,
					'site_url' => $site_url,
				)
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
}
