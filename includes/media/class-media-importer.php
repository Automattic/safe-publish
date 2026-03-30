<?php
/**
 * Media Importer class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Media;

use Safe_Publish\API\HTTP_Client;
use Safe_Publish\Media\Media_Logger;
use Safe_Publish\Utils\Logger;
use Safe_Publish\Utils\Options;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Importer Class.
 *
 * Handles importing media files from external sites into the WordPress media library.
 */
class Media_Importer {

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
	 * Constructs the Media_Importer instance.
	 *
	 * @param HTTP_Client $http_client HTTP client for downloading files.
	 */
	public function __construct( HTTP_Client $http_client ) {
		$this->http_client = $http_client;
		$this->logger      = new Media_Logger();
	}

	/**
	 * Imports external media file to WordPress media library.
	 *
	 * @param string $media_url       External media URL.
	 * @param string $source_site_url Source site URL for resolving relative URLs.
	 * @return string|false New media URL on success, false on failure.
	 */
	public function import_external_media(
		string $media_url,
		string $source_site_url
	): string|false {
		// Make URL absolute if it's relative.
		if ( ! filter_var( $media_url, FILTER_VALIDATE_URL ) ) {
			$media_url = rtrim( $source_site_url, '/' ) . '/' . ltrim( $media_url, '/' );
		}

		/*
		 * TODO: Check if we want/need this.
		 *
		 * Check if media is from the same domain as source.
		 * Uncomment to enable same-domain validation:
		 *
		 * $source_domain = wp_parse_url( $source_site_url, PHP_URL_HOST );
		 * $media_domain  = wp_parse_url( $media_url, PHP_URL_HOST );
		 *
		 * Only import media from the same domain for security.
		 * if ( $source_domain !== $media_domain ) {
		 *     return false;
		 * }
		 */

		// Strip query parameters for consistency with import_external_media_as_attachment().
		$media_url = strtok( $media_url, '?' );

		// Check if we already imported this media.
		$existing_attachment = $this->get_attachment_by_url( $media_url );
		if ( $existing_attachment ) {
			return wp_get_attachment_url( $existing_attachment );
		}

		$this->ensure_media_functions_loaded();

		// Download file.
		$temp_file = download_url( $media_url );

		if ( is_wp_error( $temp_file ) ) {
			$this->logger->log_error(
				'MEDIA_DOWNLOAD_FAILED',
				array(
					'url'   => $media_url,
					'error' => $temp_file->get_error_message(),
				)
			);
			return false;
		}

		// Get file info.
		$file_info = pathinfo( $media_url );
		$filename  = sanitize_file_name( $file_info['basename'] );

		// Prepare file array for wp_handle_sideload.
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $temp_file,
		);

		// Import to media library.
		// Prevent WordPress from potentially degrading the original image quality.
		add_filter( 'big_image_size_threshold', '__return_false' );
		$attachment_id = media_handle_sideload( $file_array, 0 );
		remove_filter( 'big_image_size_threshold', '__return_false' );

		// Clean up temp file - VIP-compatible cleanup.
		$this->http_client->cleanup_temp_file( $temp_file );

		if ( is_wp_error( $attachment_id ) ) {
			$this->logger->log_error(
				'MEDIA_SIDELOAD_FAILED',
				array(
					'url'   => $media_url,
					'error' => $attachment_id->get_error_message(),
				)
			);
			return false;
		}

		// Store the original URL as meta for tracking.
		update_post_meta( $attachment_id, Options::META_ORIGINAL_URL, $media_url );
		update_post_meta( $attachment_id, Options::META_IMPORTED_FROM, $source_site_url );

		return wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Imports external media file to media library and returns attachment ID.
	 *
	 * @param string $media_url       External media URL.
	 * @param string $source_site_url Source site URL for resolving relative URLs.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	public function import_external_media_as_attachment(
		string $media_url,
		string $source_site_url
	): int|false {
		// Make URL absolute if it's relative.
		if ( ! filter_var( $media_url, FILTER_VALIDATE_URL ) ) {
			$media_url = rtrim( $source_site_url, '/' ) . '/' . ltrim( $media_url, '/' );
		}

		$media_url = strtok( $media_url, '?' ); // Remove query parameters.

		// Check if we already imported this media.
		$existing_attachment = $this->get_attachment_by_url( $media_url );
		if ( $existing_attachment ) {
			return $existing_attachment;
		}

		$this->ensure_media_functions_loaded();

		// Temporarily enable WebP uploads during import.
		$webp_filter_added = false;
		if ( ! $this->is_webp_supported() ) {
			// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.upload_mimes
			add_filter( 'upload_mimes', array( $this, 'add_webp_mime_type' ) );
			$webp_filter_added = true;
		}

		// Also add a filter specifically for media_handle_sideload to bypass restrictions.
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'handle_webp_filetype' ), 10, 3 );

		// Download file using VIP-compatible method.
		$temp_file = $this->http_client->download_external_file( $media_url );

		if ( is_wp_error( $temp_file ) || ! $temp_file ) {
			// Remove the filter if we added it.
			if ( $webp_filter_added ) {
				remove_filter( 'upload_mimes', array( $this, 'add_webp_mime_type' ) );
			}
			return false;
		}

		// Get file info and validate.
		$file_info = pathinfo( $media_url );
		$filename  = sanitize_file_name( $file_info['basename'] ); // Sanitize filename.

		// Ensure we have a proper file extension.
		if ( empty( $file_info['extension'] ) ) {
			// Try to detect file type from downloaded file.
			$file_type = wp_check_filetype( $temp_file );
			if ( ! empty( $file_type['ext'] ) ) {
				$filename .= '.' . $file_type['ext'];
			}
		}

		// Validate file type is allowed.
		$file_type = wp_check_filetype( $filename );

		// Add WebP support if not natively supported.
		if ( ! $file_type['type'] && isset( $file_info['extension'] ) && 'webp' === strtolower( $file_info['extension'] ) ) {
			$file_type = array(
				'ext'  => 'webp',
				'type' => 'image/webp',
			);
		}

		if ( ! $file_type['type'] ) {
			$this->http_client->cleanup_temp_file( $temp_file );
			return false;
		}

		$file_size = filesize( $temp_file );

		// Prepare file array for media_handle_sideload.
		$file_array = array(
			'name'     => $filename,
			'type'     => $file_type['type'],
			'tmp_name' => $temp_file,
			'error'    => 0,
			'size'     => $file_size ? $file_size : 0,
		);

		// Import to media library with error handling.
		// Prevent WordPress from potentially degrading the original image quality.
		add_filter( 'big_image_size_threshold', '__return_false' );
		/** @psalm-suppress InvalidArgument - $_FILES['size'] is int */
		$attachment_id = media_handle_sideload(
			$file_array,
			0,
			null,
			array(
				'test_form' => false, // Skip form validation.
				'test_type' => true,  // But keep type validation.
			)
		);
		remove_filter( 'big_image_size_threshold', '__return_false' );

		// Clean up temp file.
		$this->http_client->cleanup_temp_file( $temp_file );

		// Remove the WebP filter if we added it.
		if ( $webp_filter_added ) {
			remove_filter( 'upload_mimes', array( $this, 'add_webp_mime_type' ) );
		}

		// Remove the filetype filter.
		remove_filter( 'wp_check_filetype_and_ext', array( $this, 'handle_webp_filetype' ) );

		if ( is_wp_error( $attachment_id ) ) {
			$this->logger->log_error(
				'MEDIA_IMPORT_FAILED',
				array(
					'url'   => $media_url,
					'error' => $attachment_id->get_error_message(),
				)
			);
			return false;
		}

		// Verify the attachment was actually created.
		if ( ! $attachment_id || ! is_numeric( $attachment_id ) ) {
			$this->logger->log_error(
				'INVALID_ATTACHMENT_ID',
				array( 'url' => $media_url )
			);
			return false;
		}

		// Store the original URL as meta for tracking.
		update_post_meta( $attachment_id, Options::META_ORIGINAL_URL, $media_url );
		update_post_meta( $attachment_id, Options::META_IMPORTED_FROM, $source_site_url );

		return $attachment_id;
	}

	/**
	 * Imports featured image from external post.
	 *
	 * @param int    $featured_media_id External featured media ID.
	 * @param string $site_url          External site URL.
	 * @param array  $auth_credentials  Optional. Authentication credentials. Default empty array.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	public function import_featured_image(
		int $featured_media_id,
		string $site_url,
		array $auth_credentials = array()
	): int|false {
		if ( empty( $featured_media_id ) || empty( $site_url ) ) {
			return false;
		}

		// Check if we already imported this featured image.
		$existing_attachment = $this->get_attachment_by_featured_media_id( $featured_media_id, $site_url );
		if ( $existing_attachment ) {
			return $existing_attachment;
		}

		// Fetch media details from external site.
		$media_api_url = trailingslashit( $site_url ) . 'wp-json/wp/v2/media/' . $featured_media_id;
		$response      = $this->http_client->make_request( $media_api_url, $auth_credentials );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$response_body = wp_remote_retrieve_body( $response );
		$media_data    = json_decode( $response_body, true );
		if ( empty( $media_data['source_url'] ) ) {
			return false;
		}

		// Import the media and get the attachment ID.
		$attachment_id = $this->import_external_media_as_attachment( $media_data['source_url'], $site_url );

		if ( $attachment_id ) {
			// Store additional metadata for featured images.
			update_post_meta( $attachment_id, Options::META_FEATURED_MEDIA_ID, $featured_media_id );
			update_post_meta( $attachment_id, Options::META_MEDIA_TYPE, 'featured_image' );

			return $attachment_id;
		}

		return false;
	}

	/**
	 * Returns a URL with the query string parameters from another URL reapplied
	 * onto it.
	 *
	 * @param string $original_url The source URL whose query parameters should be reapplied.
	 * @param string $clean_url    The target URL, which has no query parameters.
	 * @return string The target URL with the source query parameters appended.
	 */
	public static function reapply_query_parameters( string $original_url, string $clean_url ): string {
		$query = (string) wp_parse_url( $original_url, PHP_URL_QUERY );

		if ( ! $query ) {
			return $clean_url;
		}

		$params = array();
		parse_str( $query, $params );

		return $clean_url . '?' . http_build_query( $params );
	}

	/**
	 * Gets attachment ID from URL using VIP-optimized function when available.
	 *
	 * @param string $url Attachment URL.
	 * @return int Attachment ID, or 0 if not found.
	 */
	public function get_attachment_id_from_url( string $url ): int {
		// Use VIP-optimized function when available, fallback to core function.
		if ( function_exists( 'wpcom_vip_attachment_url_to_postid' ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.attachment_url_to_postid_wpcom_vip_attachment_url_to_postid
			return wpcom_vip_attachment_url_to_postid( $url );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.attachment_url_to_postid_attachment_url_to_postid
		return attachment_url_to_postid( $url );
	}

	/**
	 * Adds WebP MIME type to allowed uploads.
	 *
	 * @param array $mime_types Current allowed MIME types.
	 * @return array Updated MIME types with WebP support.
	 */
	public function add_webp_mime_type( array $mime_types ): array {
		$mime_types['webp'] = 'image/webp';
		return $mime_types;
	}

	/**
	 * Handles WebP file type validation during upload.
	 *
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
		if ( ! $wp_check_filetype_and_ext['type'] && ! $wp_check_filetype_and_ext['ext'] ) {
			$info = pathinfo( $filename );
			if ( isset( $info['extension'] ) && 'webp' === strtolower( $info['extension'] ) ) {
				$wp_check_filetype_and_ext['ext']  = 'webp';
				$wp_check_filetype_and_ext['type'] = 'image/webp';
			}
		}
		return $wp_check_filetype_and_ext;
	}

	/**
	 * Ensures WordPress media functions are loaded.
	 *
	 * @psalm-suppress MissingFile
	 */
	private function ensure_media_functions_loaded(): void {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Gets attachment ID by original URL.
	 *
	 * @param string $original_url Original external URL.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	private function get_attachment_by_url( string $original_url ): int|false {
		// Check by the exact URL stored in metadata.
		$attachments = get_posts(
			array(
				'post_type'        => 'attachment',
				'meta_key'         => Options::META_ORIGINAL_URL,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $original_url,
				'posts_per_page'   => 1,
				'suppress_filters' => false, // Enable caching for VIP compatibility.
			)
		);

		return ! empty( $attachments ) ? $attachments[0]->ID : false;
	}

	/**
	 * Gets attachment ID by external featured media ID.
	 *
	 * @param int    $featured_media_id External featured media ID.
	 * @param string $site_url          External site URL.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	private function get_attachment_by_featured_media_id(
		int $featured_media_id,
		string $site_url
	): int|false {
		$attachments = get_posts(
			array(
				'post_type'        => 'attachment',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => Options::META_FEATURED_MEDIA_ID,
						'value' => $featured_media_id,
					),
					array(
						'key'   => Options::META_IMPORTED_FROM,
						'value' => $site_url,
					),
				),
				'posts_per_page'   => 1,
				'suppress_filters' => false, // Enable caching for VIP compatibility.
			)
		);

		return ! empty( $attachments ) ? $attachments[0]->ID : false;
	}

	/**
	 * Checks if WebP is supported by WordPress.
	 *
	 * @return bool True if WebP is supported.
	 */
	private function is_webp_supported(): bool {
		$mime_types = get_allowed_mime_types();
		return isset( $mime_types['webp'] ) || in_array( 'image/webp', $mime_types, true );
	}
}
