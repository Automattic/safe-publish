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
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Content\Embed_Processor;
use DOMDocument;

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
	 * Media Importer instance.
	 *
	 * @var Media_Importer
	 */
	private Media_Importer $media_importer;

	/**
	 * Embed Processor instance.
	 *
	 * @var Embed_Processor
	 */
	private Embed_Processor $embed_processor;

	/**
	 * Constructs the External_Posts_API instance.
	 *
	 * @param HTTP_Client|null     $http_client     Optional. HTTP client for making requests.
	 * @param Media_Importer|null  $media_importer  Optional. Media importer for handling media files.
	 * @param Embed_Processor|null $embed_processor Optional. Embed processor for handling embeds.
	 */
	public function __construct(
		?HTTP_Client $http_client = null,
		?Media_Importer $media_importer = null,
		?Embed_Processor $embed_processor = null
	) {
		$this->http_client     = $http_client ?? new HTTP_Client();
		$this->media_importer  = $media_importer ?? new Media_Importer( $this->http_client );
		$this->embed_processor = $embed_processor ?? new Embed_Processor();
	}

	/**
	 * Fetches available post types from external site.
	 *
	 * @param string $site_url         External site URL.
	 * @param array  $auth_credentials Optional. Authentication credentials array. Default empty array.
	 * @return array|\WP_Error Post types data or error.
	 */
	public function fetch_post_types( string $site_url, array $auth_credentials = array() ): array|\WP_Error {
		// Validate URL first.
		if ( ! URL_Validator::is_valid_external_url( $site_url ) ) {
			return new \WP_Error(
				'invalid_url',
				__( 'Invalid URL provided.', 'safe-publish' )
			);
		}

		// Post types can change and we want to reflect the current state.

		// Build API URL for post types.
		$api_url = trailingslashit( $site_url ) . 'wp-json/wp/v2/types';

		// Make request.
		$response = $this->make_request( $api_url, $auth_credentials );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_body   = wp_remote_retrieve_body( $response );
		$post_types_data = json_decode( $response_body, true );

		// Check for authentication error responses.
		if ( is_array( $post_types_data ) && isset( $post_types_data['code'] ) ) {
			return new \WP_Error(
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
			return new \WP_Error(
				'no_post_types',
				$error_msg
			);
		}

		// Filter to only show post types that support REST API.
		$filtered_post_types = array();
		foreach ( $post_types_data as $slug => $post_type ) {
			// Include if it has a rest_base (which means it's REST API enabled).
			if ( ! empty( $post_type['rest_base'] ) ) {
				$filtered_post_types[ $slug ] = array(
					'slug'        => $slug,
					'name'        => $post_type['name'] ?? $slug,
					'label'       => $post_type['name'] ?? $slug, // Use 'name' instead of nested labels.
					'rest_base'   => $post_type['rest_base'],
					'description' => $post_type['description'] ?? '',
				);
			}
		}

		// No caching - return fresh data directly.
		return $filtered_post_types;
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
	public function make_request( string $url, array $auth_credentials = array() ): array|\WP_Error {
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
	 * Processes and imports media from external post content.
	 *
	 * @param string $content         Post content with external media URLs.
	 * @param string $source_site_url External site URL for resolving relative URLs.
	 * @return string Processed content with imported media.
	 */
	public function process_and_import_media( string $content, string $source_site_url ): string {
		if ( empty( $content ) ) {
			return $content;
		}

		// Parse HTML content with proper UTF-8 encoding.
		$dom = new DOMDocument( '1.0', 'UTF-8' );

		// Suppress libxml errors to handle malformed HTML gracefully.
		$previous_use_errors = libxml_use_internal_errors( true );

		// Prepend meta charset to ensure proper UTF-8 handling.
		$utf8_content = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $content;

		$dom->loadHTML(
			$utf8_content,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
		);

		// Restore previous libxml error setting.
		libxml_use_internal_errors( $previous_use_errors );

		// Process images.
		$images = $dom->getElementsByTagName( 'img' );
		foreach ( $images as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( ! empty( $src ) ) {
				$new_src = $this->import_external_media( $src, $source_site_url );
				if ( $new_src ) {
					$img->setAttribute( 'src', $new_src );
				}
			}
		}

		// Process links to make them absolute.
		$links = $dom->getElementsByTagName( 'a' );
		foreach ( $links as $link ) {
			$href = $link->getAttribute( 'href' );
			if ( ! empty( $href ) && ! filter_var( $href, FILTER_VALIDATE_URL ) ) {
				// Convert relative URLs to absolute.
				$absolute_href = rtrim( $source_site_url, '/' ) . '/' . ltrim( $href, '/' );
				$link->setAttribute( 'href', $absolute_href );
			}
		}

		// Process iframes for embeds.
		$iframes = $dom->getElementsByTagName( 'iframe' );
		foreach ( $iframes as $iframe ) {
			$this->embed_processor->process_iframe( $iframe, $source_site_url );
		}

		// Process video elements.
		$videos = $dom->getElementsByTagName( 'video' );
		foreach ( $videos as $video ) {
			$this->process_video_element( $video, $source_site_url );
		}

		// Process audio elements.
		$audios = $dom->getElementsByTagName( 'audio' );
		foreach ( $audios as $audio ) {
			$this->process_audio_element( $audio, $source_site_url );
		}

		// Process embeds (WordPress specific).
		$embeds = $dom->getElementsByTagName( 'embed' );
		foreach ( $embeds as $embed ) {
			$this->embed_processor->process_embed( $embed, $source_site_url );
		}

		// Process figure elements (often contain embeds).
		$figures = $dom->getElementsByTagName( 'figure' );
		foreach ( $figures as $figure ) {
			$this->embed_processor->process_figure_embeds( $figure, $source_site_url );
		}

		// Process blockquotes (social media embeds).
		$blockquotes = $dom->getElementsByTagName( 'blockquote' );
		foreach ( $blockquotes as $blockquote ) {
			$this->embed_processor->process_blockquote_embeds( $blockquote, $source_site_url );
		}

		// Return processed content with proper UTF-8 handling.
		$body              = $dom->getElementsByTagName( 'body' )->item( 0 );
		$processed_content = '';

		if ( $body ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			foreach ( $body->childNodes as $child ) {
				$processed_content .= $dom->saveHTML( $child );
			}
		} else {
			$processed_content = $dom->saveHTML();
		}

		// Remove the meta charset tag we added for processing.
		$processed_content = preg_replace( '/<meta http-equiv="Content-Type" content="text\/html; charset=utf-8"\s*\/?>/i', '', $processed_content );

		return $processed_content;
	}

	/**
	 * Imports external media file to WordPress media library.
	 *
	 * @param string $media_url       External media URL.
	 * @param string $source_site_url Source site URL for resolving relative URLs.
	 * @return string|false New media URL on success, false on failure.
	 */
	public function import_external_media( string $media_url, string $source_site_url ): string|false {
		return $this->media_importer->import_external_media( $media_url, $source_site_url );
	}

	/**
	 * Imports external media file to media library and returns attachment ID.
	 *
	 * @param string $media_url       External media URL.
	 * @param string $source_site_url Source site URL for resolving relative URLs.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	public function import_external_media_as_attachment( string $media_url, string $source_site_url ): int|false {
		return $this->media_importer->import_external_media_as_attachment( $media_url, $source_site_url );
	}



	/**
	 * Imports featured image from external post.
	 *
	 * @param int    $featured_media_id External featured media ID.
	 * @param string $site_url          External site URL.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	public function import_featured_image( int $featured_media_id, string $site_url ): int|false {
		return $this->media_importer->import_featured_image( $featured_media_id, $site_url, array() );
	}



	/**
	 * Processes video elements and imports video files.
	 *
	 * @param \DOMElement $video           Video element.
	 * @param string      $source_site_url Source site URL.
	 */
	private function process_video_element( \DOMElement $video, string $source_site_url ): void {
		// Process video source elements.
		$sources = $video->getElementsByTagName( 'source' );
		foreach ( $sources as $source ) {
			$src = $source->getAttribute( 'src' );
			if ( ! empty( $src ) ) {
				$new_src = $this->import_external_media( $src, $source_site_url );
				if ( $new_src ) {
					$source->setAttribute( 'src', $new_src );
				}
			}
		}

		// Process direct video src attribute.
		$video_src = $video->getAttribute( 'src' );
		if ( ! empty( $video_src ) ) {
			$new_src = $this->import_external_media( $video_src, $source_site_url );
			if ( $new_src ) {
				$video->setAttribute( 'src', $new_src );
			}
		}

		// Process poster image.
		$poster = $video->getAttribute( 'poster' );
		if ( ! empty( $poster ) ) {
			$new_poster = $this->import_external_media( $poster, $source_site_url );
			if ( $new_poster ) {
				$video->setAttribute( 'poster', $new_poster );
			}
		}

		// Add WordPress video classes.
		$class = $video->getAttribute( 'class' );
		$video->setAttribute( 'class', trim( $class . ' wp-video-shortcode' ) );

		// Ensure responsive behavior.
		$video->setAttribute( 'controls', 'controls' );
		$video->setAttribute( 'preload', 'metadata' );
	}

	/**
	 * Processes audio elements and imports audio files.
	 *
	 * @param \DOMElement $audio           Audio element.
	 * @param string      $source_site_url Source site URL.
	 */
	private function process_audio_element( \DOMElement $audio, string $source_site_url ): void {
		// Process audio source elements.
		$sources = $audio->getElementsByTagName( 'source' );
		foreach ( $sources as $source ) {
			$src = $source->getAttribute( 'src' );
			if ( ! empty( $src ) ) {
				$new_src = $this->import_external_media( $src, $source_site_url );
				if ( $new_src ) {
					$source->setAttribute( 'src', $new_src );
				}
			}
		}

		// Process direct audio src attribute.
		$audio_src = $audio->getAttribute( 'src' );
		if ( ! empty( $audio_src ) ) {
			$new_src = $this->import_external_media( $audio_src, $source_site_url );
			if ( $new_src ) {
				$audio->setAttribute( 'src', $new_src );
			}
		}

		// Add WordPress audio classes.
		$class = $audio->getAttribute( 'class' );
		$audio->setAttribute( 'class', trim( $class . ' wp-audio-shortcode' ) );

		// Ensure controls are visible.
		$audio->setAttribute( 'controls', 'controls' );
		$audio->setAttribute( 'preload', 'metadata' );
	}

	/**
	 * Gets attachment ID from URL using VIP-optimized function when available.
	 *
	 * @param string $url Attachment URL.
	 * @return int Attachment ID, or 0 if not found.
	 */
	public function get_attachment_id_from_url( string $url ): int {
		return $this->media_importer->get_attachment_id_from_url( $url );
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

	/**
	 * Adds WebP MIME type to allowed uploads.
	 *
	 * @deprecated Delegates to Media_Importer.
	 * @param array $mime_types Current allowed MIME types.
	 * @return array Updated MIME types with WebP support.
	 */
	public function add_webp_mime_type( array $mime_types ): array {
		return $this->media_importer->add_webp_mime_type( $mime_types );
	}

	/**
	 * Handles WebP file type validation during upload.
	 *
	 * @deprecated Delegates to Media_Importer.
	 * @param array  $wp_check_filetype_and_ext File data with 'ext', 'type', 'proper_filename' keys.
	 * @param string $_file                     Full path to the file.
	 * @param string $filename                  File name (may differ from $file if in tmp dir).
	 * @return array Modified file data.
	 */
	public function handle_webp_filetype(
		array $wp_check_filetype_and_ext,
		string $_file,
		string $filename
	): array {
		return $this->media_importer->handle_webp_filetype( $wp_check_filetype_and_ext, $_file, $filename );
	}
}
