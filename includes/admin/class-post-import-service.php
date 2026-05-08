<?php
/**
 * Post Import Service class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

use Safe_Publish\API\External_Posts_API;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Auth_Credential_Provider;
use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Post_Type_Map;
use Exception;
use WP_Error;
use WP_Post;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles post import orchestration for both single and bulk imports.
 *
 * Coordinates content processing, post creation or updating, featured image
 * import, and history logging for each imported post.
 */
class Post_Import_Service {

	use Sanitizes_Content;

	/**
	 * External Posts API instance.
	 *
	 * @var External_Posts_API
	 */
	private External_Posts_API $api;

	/**
	 * Media Importer instance.
	 *
	 * @var Media_Importer
	 */
	private Media_Importer $media_importer;

	/**
	 * Content Processor instance.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $content_processor;

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Meta Terms Manager instance.
	 *
	 * @var Meta_Terms_Manager
	 */
	private Meta_Terms_Manager $meta_terms_manager;

	/**
	 * Logger instance.
	 *
	 * @var Content_Logger
	 */
	private Content_Logger $logger;


	/**
	 * Constructs the Post_Import_Service instance.
	 *
	 * @param External_Posts_API $api                External Posts API instance.
	 * @param Media_Importer     $media_importer     Media Importer instance.
	 * @param Content_Processor  $content_processor  Content Processor instance.
	 * @param History_Repository $repository         History repository instance.
	 * @param Meta_Terms_Manager $meta_terms_manager Meta Terms Manager instance.
	 */
	public function __construct(
		External_Posts_API $api,
		Media_Importer $media_importer,
		Content_Processor $content_processor,
		History_Repository $repository,
		Meta_Terms_Manager $meta_terms_manager
	) {
		$this->api                = $api;
		$this->media_importer     = $media_importer;
		$this->content_processor  = $content_processor;
		$this->repository         = $repository;
		$this->meta_terms_manager = $meta_terms_manager;
		$this->logger             = new Content_Logger();
	}

	/**
	 * Imports a single post from external post data.
	 *
	 * @param array    $post_data  Post data array containing id, title, content, link, etc.
	 * @param int|null $session_id Optional import session ID for history tracking.
	 * @return array Result data with success status, post_id, edit_url, and error keys.
	 */
	public function import_post( array $post_data, ?int $session_id = null ): array {
		try {
			return $this->process_post_import( $post_data, $session_id );
		} catch ( Exception $e ) {
			return $this->build_exception_result( $post_data, $session_id, $e );
		}
	}

	/**
	 * Processes the post import workflow end-to-end.
	 *
	 * @param array    $post_data  Raw post data.
	 * @param int|null $session_id Import session ID.
	 * @return array Import result data.
	 */
	private function process_post_import( array $post_data, ?int $session_id ): array {
		$fields = $this->extract_post_fields( $post_data );

		$validation_error = $this->validate_required_fields( $fields );
		if ( null !== $validation_error ) {
			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				null,
				$validation_error['error'],
				array( 'action' => 'validation_failed' )
			);

			return $validation_error;
		}

		$post_type = $this->resolve_post_type( $fields['raw_post_type'] );

		if ( is_wp_error( $post_type ) ) {
			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				null,
				$post_type->get_error_message(),
				array( 'action' => $post_type->get_error_code() )
			);

			return $this->build_error_result(
				$fields,
				$post_type->get_error_message()
			);
		}

		$imported_post = $this->find_imported_post( $fields['external_post_id'] );

		if ( $imported_post ) {
			return $this->handle_imported_post(
				$imported_post,
				$fields,
				$post_type,
				$session_id
			);
		}

		return $this->handle_new_post(
			$fields,
			$post_type,
			$session_id
		);
	}

	/**
	 * Extracts and sanitizes post fields from raw post data.
	 *
	 * @param array $post_data Raw post data array.
	 * @return array Sanitized post fields. The external_post_id key
	 *               is null if not provided.
	 */
	private function extract_post_fields( array $post_data ): array {
		$external_post_id = absint( $post_data['id'] ?? 0 );

		return array(
			'external_post_id'  => $external_post_id > 0 ? $external_post_id : null,
			'title'             => sanitize_text_field( $post_data['title'] ?? '' ),
			'external_link'     => esc_url_raw( $post_data['link'] ?? '' ),
			'featured_media_id' => absint( $post_data['featured_media'] ?? 0 ),
			'raw_post_type'     => sanitize_text_field( $post_data['post_type'] ?? 'post' ),
			'slug'              => sanitize_text_field( $post_data['slug'] ?? '' ),
			'comment_status'    => sanitize_text_field( $post_data['comment_status'] ?? '' ),
			'ping_status'       => sanitize_text_field( $post_data['ping_status'] ?? '' ),
			'menu_order'        => absint( $post_data['menu_order'] ?? 0 ),
			'password'          => sanitize_text_field( $post_data['password'] ?? '' ),
			'meta'              => is_array( $post_data['meta'] ?? null ) ? $post_data['meta'] : array(),
			'terms'             => is_array( $post_data['terms'] ?? null ) ? $post_data['terms'] : array(),
		);
	}

	/**
	 * Builds a standardized error result array for an import operation.
	 *
	 * @param array  $fields        Sanitized post fields.
	 * @param string $error_message Error description.
	 * @return array Error result with external_post_id, title, success, and error keys.
	 */
	private function build_error_result( array $fields, string $error_message ): array {
		return array(
			'external_post_id' => $fields['external_post_id'],
			'title'            => $fields['title'],
			'success'          => false,
			'error'            => $error_message,
		);
	}

	/**
	 * Builds a standardized success result array for an import operation.
	 *
	 * @param array $fields   Sanitized post fields.
	 * @param int   $post_id  Created or updated WordPress post ID.
	 * @param bool  $existing Whether the post was updated (true) or newly created (false).
	 * @return array Success result with external_post_id, title, success, post_id, edit_url, and existing keys.
	 */
	private function build_success_result( array $fields, int $post_id, bool $existing ): array {
		return array(
			'external_post_id' => $fields['external_post_id'],
			'title'            => $fields['title'],
			'success'          => true,
			'post_id'          => $post_id,
			'edit_url'         => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'existing'         => $existing,
		);
	}

	/**
	 * Validates that required fields are present for import.
	 *
	 * @param array $fields Extracted post fields.
	 * @return array|null Error result array on failure, null on success.
	 */
	private function validate_required_fields( array $fields ): ?array {
		if ( '' === $fields['title'] || null === $fields['external_post_id'] ) {
			return $this->build_error_result(
				$fields,
				__( 'Missing required post data.', 'safe-publish' )
			);
		}

		return null;
	}

	/**
	 * Resolves a raw post type string to a valid WordPress post type.
	 *
	 * Converts plural REST API post type names to singular and validates
	 * that the post type is registered on the destination site. Returns
	 * a WP_Error when the post type does not exist or the current user
	 * lacks the required capability.
	 *
	 * @param string $raw_post_type Raw post type string from external API.
	 * @return string|WP_Error Resolved post type slug, or WP_Error on failure.
	 */
	public function resolve_post_type( string $raw_post_type ): string|WP_Error {
		$post_type = Post_Type_Map::to_wp_slug( $raw_post_type );

		if ( ! post_type_exists( $post_type ) ) {
			$message = sprintf(
				/* translators: %s: post type slug */
				__( 'Post type "%s" is not registered on this site.', 'safe-publish' ),
				$post_type
			);

			return new WP_Error( 'post_type_not_registered', $message );
		}

		// Admins can create any registered post type.
		if ( current_user_can( 'manage_options' ) ) {
			return $post_type;
		}

		$capability = 'page' === $post_type ? 'edit_pages' : 'edit_posts';

		if ( ! current_user_can( $capability ) ) {
			$message = sprintf(
				/* translators: %s: post type slug */
				__( 'You do not have permission to create "%s" posts.', 'safe-publish' ),
				$post_type
			);

			return new WP_Error( 'post_type_capability_denied', $message );
		}

		return $post_type;
	}

	/**
	 * Returns a WP_Error if media processing encountered any failures (download
	 * errors, malformed HTML, or both).
	 *
	 * Combines both error types into a single message when both are present so
	 * the user sees all issues at once.
	 *
	 * @param array $fields Sanitized post fields.
	 * @return WP_Error|null WP_Error on failure, null when no failures.
	 */
	private function get_media_processing_error( array $fields ): ?WP_Error {
		$download_msg = $this->content_processor
			->get_failed_media_error_message();
		$markup_msg   = $this->content_processor
			->get_unprocessable_media_error_message();

		if ( null === $download_msg && null === $markup_msg ) {
			return null;
		}

		$messages = array_filter( array( $download_msg, $markup_msg ) );

		$error_code = null !== $download_msg
			? 'media_download_failed'
			: 'malformed_media_markup';

		return new WP_Error(
			$error_code,
			implode( ' ', $messages ),
			array( 'fields' => $fields )
		);
	}

	/**
	 * Extracts the site base URL (scheme + host) from a full URL.
	 *
	 * @param string $url Full URL to extract the base from.
	 * @return string Site base URL (e.g. "https://example.com").
	 */
	private function extract_site_url( string $url ): string {
		return wp_parse_url( $url, PHP_URL_SCHEME )
			. '://'
			. wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Processes raw post content by importing media and fixing URLs.
	 *
	 * Returns a WP_Error if content processing fails or if kses is enabled and
	 * sanitization would modify the content.
	 *
	 * @param string $content       Raw post content.
	 * @param string $external_link External post URL used to derive site URL.
	 * @return string|WP_Error Processed content, or WP_Error on failure.
	 */
	private function process_post_content( string $content, string $external_link ): string|WP_Error {
		if ( empty( $external_link ) ) {
			return $this->sanitize_field( $content, self::FIELD_CONTENT );
		}

		$source_site_url = $this->extract_site_url( $external_link );
		$processed       = $this->content_processor->process_content( $content, $source_site_url );

		if ( is_wp_error( $processed ) ) {
			return $processed;
		}

		return $this->sanitize_field( $processed, self::FIELD_CONTENT );
	}

	/**
	 * Annotates each post in an array with its local import status.
	 *
	 * Adds `is_imported` (bool), `has_update` (bool), `local_status` (string),
	 * and `local_edit_url` (string) keys to every element based on whether a
	 * matching local post exists and whether the external post's modified date
	 * is newer.
	 *
	 * @param array $posts Posts array fetched from the external API, passed by reference.
	 */
	public function annotate_posts_with_import_status( array &$posts ): void {
		foreach ( $posts as &$post ) {
			$imported            = $this->find_imported_post( absint( $post['id'] ?? 0 ) );
			$post['is_imported'] = (bool) $imported;

			if ( $imported ) {
				$external_modified = strtotime( $post['modified_gmt'] );
				$local_modified    = strtotime( $imported->post_modified_gmt );

				$post['has_update']     = false !== $external_modified
					&& false !== $local_modified
					&& $external_modified > $local_modified;
				$post['local_status']   = $imported->post_status;
				$post['local_edit_url'] = get_edit_post_link( $imported->ID, 'raw' );
			} else {
				$post['has_update']     = false;
				$post['local_status']   = null;
				$post['local_edit_url'] = null;
			}
		}

		unset( $post );
	}

	/**
	 * Finds a previously imported WordPress post by its external post ID.
	 *
	 * @param int $external_post_id External post ID stored in post meta.
	 * @return WP_Post|null Imported post or null if not found.
	 */
	public function find_imported_post( int $external_post_id ): ?WP_Post {
		$existing_posts = get_posts(
			array(
				'meta_key'         => Options::META_EXTERNAL_POST_ID,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $external_post_id,
				// 'any' excludes 'trash', 'auto-draft', and statuses with
				// exclude_from_search=true
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'suppress_filters' => false,
			)
		);

		return ! empty( $existing_posts ) ? $existing_posts[0] : null;
	}

	/**
	 * Handles import flow when a matching post already exists in WordPress.
	 *
	 * Fetches fresh content from the source site and updates the existing
	 * post. Aborts with an error result if the fetch fails; the post will
	 * not be updated with stale snapshot data.
	 *
	 * Post status is preserved (not reset to 'draft') to avoid silently
	 * unpublishing live posts during automated bulk runs.
	 *
	 * @param WP_Post  $imported_post Imported WordPress post.
	 * @param array    $fields        Sanitized post fields.
	 * @param string   $post_type     Resolved post type slug.
	 * @param int|null $session_id    Import session ID for logging.
	 * @return array Import result data.
	 */
	private function handle_imported_post(
		WP_Post $imported_post,
		array $fields,
		string $post_type,
		?int $session_id
	): array {
		$prepared = $this->prepare_fresh_content( $fields );

		if ( is_wp_error( $prepared ) ) {
			$error_data   = $prepared->get_error_data();
			$error_fields = is_array( $error_data ) && isset( $error_data['fields'] )
				? $error_data['fields']
				: $fields;

			$this->log_import_if_session(
				$session_id,
				$error_fields['external_post_id'],
				$error_fields['title'],
				'error',
				null,
				$prepared->get_error_message(),
				array( 'action' => $prepared->get_error_code() )
			);

			return $this->build_error_result(
				$error_fields,
				$prepared->get_error_message()
			);
		}

		$fields            = $prepared['fields'];
		$processed_content = $prepared['processed_content'];

		// Sideload the featured image before writing the post so that a
		// failure here does not leave the post in a partially-updated state.
		$featured_attachment_id = $this->import_featured_image_attachment(
			$fields['featured_media_id'],
			$fields['external_link']
		);

		if ( false === $featured_attachment_id ) {
			$error_message = __( 'Failed to import featured image.', 'safe-publish' );

			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				null,
				$error_message,
				array( 'action' => 'featured_image_import_failed' )
			);

			return $this->build_error_result( $fields, $error_message );
		}

		$post_id = $this->persist_updated_post(
			array(
				'ID'             => $imported_post->ID,
				'post_title'     => $fields['title'],
				'post_excerpt'   => $fields['excerpt'],
				'post_content'   => $processed_content,
				'post_type'      => $post_type,
				'post_name'      => $fields['slug'],
				'comment_status' => $fields['comment_status'],
				'ping_status'    => $fields['ping_status'],
				'menu_order'     => $fields['menu_order'],
				'post_password'  => $fields['password'],
			),
			$featured_attachment_id,
			$fields['external_link'],
			$fields['meta'],
			$fields['terms']
		);

		if ( is_wp_error( $post_id ) ) {
			$error_data = $post_id->get_error_data();
			$action     = is_array( $error_data ) && isset( $error_data['action'] )
				? $error_data['action']
				: 'post_update_failed';

			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				$imported_post->ID,
				$post_id->get_error_message(),
				array( 'action' => $action )
			);

			return $this->build_error_result(
				$fields,
				$post_id->get_error_message()
			);
		}

		$this->log_import_if_session(
			$session_id,
			$fields['external_post_id'],
			$fields['title'],
			'updated',
			$post_id,
			null,
			array( 'action' => 'updated_existing' )
		);

		return $this->build_success_result( $fields, $post_id, true );
	}

	/**
	 * Handles import flow when no matching post exists yet in WordPress.
	 *
	 * Fetches fresh content from the source site and creates a new draft
	 * post. Aborts with an error result if the fetch fails; the post will
	 * not be created with stale snapshot data.
	 *
	 * @param array    $fields     Sanitized post fields.
	 * @param string   $post_type  Resolved post type slug.
	 * @param int|null $session_id Import session ID for logging.
	 * @return array Import result data.
	 */
	private function handle_new_post(
		array $fields,
		string $post_type,
		?int $session_id
	): array {
		$prepared = $this->prepare_fresh_content( $fields );

		if ( is_wp_error( $prepared ) ) {
			$error_data   = $prepared->get_error_data();
			$error_fields = is_array( $error_data ) && isset( $error_data['fields'] )
				? $error_data['fields']
				: $fields;

			$this->log_import_if_session(
				$session_id,
				$error_fields['external_post_id'],
				$error_fields['title'],
				'error',
				null,
				$prepared->get_error_message(),
				array( 'action' => $prepared->get_error_code() )
			);

			return $this->build_error_result(
				$error_fields,
				$prepared->get_error_message()
			);
		}

		$fields            = $prepared['fields'];
		$processed_content = $prepared['processed_content'];

		// Sideload the featured image before creating the post so that a
		// failure here does not leave an orphaned draft in the DB.
		$featured_attachment_id = $this->import_featured_image_attachment(
			$fields['featured_media_id'],
			$fields['external_link']
		);

		if ( false === $featured_attachment_id ) {
			$error_message = __( 'Failed to import featured image.', 'safe-publish' );

			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				null,
				$error_message,
				array( 'action' => 'featured_image_import_failed' )
			);

			return $this->build_error_result( $fields, $error_message );
		}

		$post_id = $this->persist_new_post(
			array(
				'post_title'     => $fields['title'],
				'post_excerpt'   => $fields['excerpt'],
				'post_content'   => $processed_content,
				'post_status'    => 'draft',
				'post_type'      => $post_type,
				'post_name'      => $fields['slug'],
				'comment_status' => $fields['comment_status'],
				'ping_status'    => $fields['ping_status'],
				'menu_order'     => $fields['menu_order'],
				'post_password'  => $fields['password'],
				'meta_input'     => array(
					Options::META_EXTERNAL_POST_ID => $fields['external_post_id'],
					Options::META_EXTERNAL_LINK    => $fields['external_link'],
					Options::META_IMPORTED_FROM    => Options::META_IMPORTED_FROM_VALUE,
					Options::META_IMPORT_DATE_GMT  => current_time( 'mysql', true ),
				),
			),
			$featured_attachment_id,
			$fields['meta'],
			$fields['terms']
		);

		if ( is_wp_error( $post_id ) ) {
			$error_data = $post_id->get_error_data();
			$action     = is_array( $error_data ) && isset( $error_data['action'] )
				? $error_data['action']
				: 'post_create_failed';

			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				null,
				$post_id->get_error_message(),
				array( 'action' => $action )
			);

			return $this->build_error_result(
				$fields,
				$post_id->get_error_message()
			);
		}

		$this->log_import_if_session(
			$session_id,
			$fields['external_post_id'],
			$fields['title'],
			'success',
			$post_id,
			null,
			array( 'action' => 'created_new_post' )
		);

		return $this->build_success_result( $fields, $post_id, false );
	}

	/**
	 * Fetches fresh content and prepares all fields for import.
	 *
	 * Handles the fetch, field updates, excerpt sanitization, content
	 * processing, and media error checks that are common to both the new and
	 * existing post import flows.
	 *
	 * @param array $fields Sanitized post fields from extract_post_fields().
	 * @return array{fields: array, processed_content: string}|WP_Error Prepared data or error.
	 */
	private function prepare_fresh_content( array $fields ): array|WP_Error {
		$fresh_result = $this->fetch_fresh_content(
			$fields['external_post_id'],
			$fields['raw_post_type']
		);

		if ( is_wp_error( $fresh_result ) ) {
			return new WP_Error(
				'fetch_failed',
				$fresh_result->get_error_message()
			);
		}

		$fields['title']             = $fresh_result['title'];
		$fields['featured_media_id'] = $fresh_result['featured_media'];
		$fields['slug']              = $fresh_result['slug'];
		$fields['comment_status']    = $fresh_result['comment_status'];
		$fields['ping_status']       = $fresh_result['ping_status'];
		$fields['menu_order']        = $fresh_result['menu_order'];
		$fields['password']          = $fresh_result['password'];

		$sanitized_excerpt = $this->sanitize_field(
			$fresh_result['excerpt'],
			self::FIELD_EXCERPT
		);

		if ( is_wp_error( $sanitized_excerpt ) ) {
			return new WP_Error(
				'excerpt_sanitization_failed',
				$sanitized_excerpt->get_error_message(),
				array( 'fields' => $fields )
			);
		}

		$fields['excerpt'] = $sanitized_excerpt;

		$processed_content = $this->process_post_content(
			$fresh_result['content'] ?? '',
			$fields['external_link']
		);

		if ( is_wp_error( $processed_content ) ) {
			$this->content_processor->delete_newly_created_media();

			return new WP_Error(
				'content_processing_failed',
				$processed_content->get_error_message(),
				array( 'fields' => $fields )
			);
		}

		$media_error = $this->get_media_processing_error( $fields );

		if ( null !== $media_error ) {
			$this->content_processor->delete_newly_created_media();

			return $media_error;
		}

		// Unsanitized values; sanitized downstream before being stored.
		$fields['meta']  = is_array( $fresh_result['meta'] ?? null )
			? $fresh_result['meta']
			: $fields['meta'];
		$fields['terms'] = is_array( $fresh_result['terms'] ?? null )
			? $fresh_result['terms']
			: $fields['terms'];

		return array(
			'fields'            => $fields,
			'processed_content' => $processed_content,
		);
	}

	/**
	 * Persists an existing post update with all associated data.
	 *
	 * Handles wp_update_post, external link meta, import date, thumbnail,
	 * custom meta, and terms. If any step after wp_update_post() fails, the
	 * post is rolled back to its pre-update state and any media sideloaded
	 * during this attempt is deleted. Used by both single and bulk import
	 * paths.
	 *
	 * @param array        $post_args              Arguments for wp_update_post().
	 * @param int          $featured_attachment_id  Sideloaded featured image attachment ID (0 = none).
	 * @param string       $external_link           External post URL for meta tracking.
	 * @param array|object $meta                    Meta data.
	 * @param array|object $terms                   Terms data.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public function persist_updated_post(
		array $post_args,
		int $featured_attachment_id,
		string $external_link,
		array|object $meta,
		array|object $terms
	): int|WP_Error {
		$post_id  = $post_args['ID'];
		$snapshot = $this->capture_pre_update_state(
			$post_id,
			$meta,
			$terms
		);

		$this->content_processor->disable_content_filters();
		$result = wp_update_post( $post_args );
		$this->content_processor->restore_content_filters();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 0 === $result ) {
			return new WP_Error(
				'post_update_failed',
				__( 'Failed to update post.', 'safe-publish' ),
				array( 'action' => 'post_update_failed' )
			);
		}

		update_post_meta(
			$post_id,
			Options::META_EXTERNAL_LINK,
			$external_link
		);

		delete_post_meta( $post_id, Options::META_IMPORT_DATE_GMT );
		if ( false === update_post_meta(
			$post_id,
			Options::META_IMPORT_DATE_GMT,
			current_time( 'mysql', true )
		) ) {
			$this->rollback_failed_update( $post_id, $snapshot );

			return new WP_Error(
				'import_date_update_failed',
				__(
					'Failed to update post tracking metadata.',
					'safe-publish'
				),
				array( 'action' => 'meta_update_failed' )
			);
		}

		if ( $featured_attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $featured_attachment_id );
		}

		$meta_result = $this->meta_terms_manager->update_meta(
			$post_id,
			$meta
		);

		if ( is_wp_error( $meta_result ) ) {
			$this->rollback_failed_update( $post_id, $snapshot );

			return new WP_Error(
				'meta_update_failed',
				$meta_result->get_error_message(),
				array( 'action' => 'meta_update_failed' )
			);
		}

		$terms_result = $this->meta_terms_manager->update_terms(
			$post_id,
			$terms
		);

		if ( is_wp_error( $terms_result ) ) {
			$this->rollback_failed_update( $post_id, $snapshot );

			return new WP_Error(
				'terms_update_failed',
				$terms_result->get_error_message(),
				array( 'action' => 'terms_update_failed' )
			);
		}

		return $post_id;
	}

	/**
	 * Captures the pre-update state of a post for rollback.
	 *
	 * Snapshots post fields, tracking meta, featured image, custom meta keys
	 * about to be overwritten, and term assignments for taxonomies about to be
	 * updated.
	 *
	 * @param int          $post_id Post ID.
	 * @param array|object $meta    Meta about to be written.
	 * @param array|object $terms   Terms about to be written.
	 * @return array Snapshot data.
	 */
	private function capture_pre_update_state(
		int $post_id,
		array|object $meta,
		array|object $terms
	): array {
		$post = get_post( $post_id, ARRAY_A );

		$snapshot = array(
			'post_fields'    => $post,
			'tracking_meta'  => array(
				Options::META_EXTERNAL_LINK   => get_post_meta(
					$post_id,
					Options::META_EXTERNAL_LINK,
					true
				),
				Options::META_IMPORT_DATE_GMT => get_post_meta(
					$post_id,
					Options::META_IMPORT_DATE_GMT,
					true
				),
			),
			'featured_image' => get_post_thumbnail_id( $post_id ),
			'custom_meta'    => array(),
			'terms'          => array(),
		);

		foreach ( (array) $meta as $key => $_ ) {
			$key                             = sanitize_text_field( (string) $key );
			$snapshot['custom_meta'][ $key ] = get_post_meta(
				$post_id,
				$key,
				true
			);
		}

		foreach ( (array) $terms as $taxonomy => $_ ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			$existing = wp_get_object_terms(
				$post_id,
				$taxonomy,
				array( 'fields' => 'ids' )
			);

			if ( ! is_wp_error( $existing ) ) {
				$snapshot['terms'][ $taxonomy ] = $existing;
			}
		}

		return $snapshot;
	}

	/**
	 * Restores a post to its pre-update state from a snapshot.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $snapshot Snapshot from capture_pre_update_state().
	 */
	private function restore_pre_update_state(
		int $post_id,
		array $snapshot
	): void {
		wp_update_post( $snapshot['post_fields'] );

		foreach ( $snapshot['tracking_meta'] as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		if ( $snapshot['featured_image'] ) {
			set_post_thumbnail( $post_id, $snapshot['featured_image'] );
		} else {
			delete_post_thumbnail( $post_id );
		}

		foreach ( $snapshot['custom_meta'] as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		foreach ( $snapshot['terms'] as $taxonomy => $term_ids ) {
			wp_set_object_terms( $post_id, $term_ids, $taxonomy );
		}
	}

	/**
	 * Rolls back a failed update: restores the post to its pre-update state and
	 * deletes any media sideloaded during the failed attempt.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $snapshot Snapshot from capture_pre_update_state().
	 */
	private function rollback_failed_update(
		int $post_id,
		array $snapshot
	): void {
		$this->restore_pre_update_state( $post_id, $snapshot );
		$this->content_processor->delete_newly_created_media();
	}

	/**
	 * Persists a new post with all associated data.
	 *
	 * Handles wp_insert_post, thumbnail, custom meta, and terms. On meta or
	 * terms failure the post and any sideloaded media are cleaned up. Used by
	 * both single and bulk import paths.
	 *
	 * @param array        $post_args              Arguments for wp_insert_post() (including meta_input).
	 * @param int          $featured_attachment_id  Sideloaded featured image attachment ID (0 = none).
	 * @param array|object $meta                    Meta data.
	 * @param array|object $terms                   Terms data.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public function persist_new_post(
		array $post_args,
		int $featured_attachment_id,
		array|object $meta,
		array|object $terms
	): int|WP_Error {
		$this->content_processor->disable_content_filters();
		$post_id = wp_insert_post( $post_args );
		$this->content_processor->restore_content_filters();

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $featured_attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $featured_attachment_id );
		}

		$meta_result = $this->meta_terms_manager->update_meta(
			$post_id,
			$meta
		);

		if ( is_wp_error( $meta_result ) ) {
			wp_delete_post( $post_id, true );
			$this->content_processor->delete_newly_created_media();

			return new WP_Error(
				'meta_update_failed',
				$meta_result->get_error_message(),
				array( 'action' => 'meta_update_failed' )
			);
		}

		$terms_result = $this->meta_terms_manager->update_terms(
			$post_id,
			$terms
		);

		if ( is_wp_error( $terms_result ) ) {
			wp_delete_post( $post_id, true );
			$this->content_processor->delete_newly_created_media();

			return new WP_Error(
				'terms_update_failed',
				$terms_result->get_error_message(),
				array( 'action' => 'terms_update_failed' )
			);
		}

		return $post_id;
	}

	/**
	 * Fetches fresh post content from the configured source site.
	 *
	 * Returns a WP_Error when the fetch fails for any reason, including when no
	 * connected site URL is configured. Callers should abort the import on error.
	 *
	 * @param int    $external_post_id External post ID to fetch.
	 * @param string $post_type        Post type slug or REST endpoint.
	 * @return array|WP_Error Fresh post data, or an error on failure.
	 */
	private function fetch_fresh_content(
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

		try {
			$fresh_data = $this->api->fetch_fresh_post_content(
				$external_post_id,
				$source_site_url,
				$auth_credentials,
				$post_type
			);

			if ( ! $fresh_data ) {
				return new WP_Error(
					'fresh_content_fetch_failed',
					__( 'Could not fetch fresh content from the source site. The post was not imported.', 'safe-publish' )
				);
			}

			return $fresh_data;
		} catch ( Exception $e ) {
			$this->logger->log_error(
				Log_Events::CONTENT_FETCH_FAILED,
				array( 'error' => $e->getMessage() )
			);

			return new WP_Error(
				'fresh_content_fetch_exception',
				$e->getMessage()
			);
		}
	}

	/**
	 * Sideloads a featured image from an external post without setting it as a
	 * post thumbnail.
	 *
	 * Separating the sideload from thumbnail assignment allows callers to fetch
	 * the image before the post exists in the DB, so a download failure does not
	 * leave the post in a partially-written state.
	 *
	 * Returns 0 when no featured image is configured (no-op). Returns the
	 * attachment ID (> 0) on a successful import. Returns false when a featured
	 * media ID is set but the import fails.
	 *
	 * @param int    $featured_media_id External featured media ID.
	 * @param string $external_link     External post URL used to derive site URL.
	 * @return int|false Attachment ID on success, 0 when not configured, false on failure.
	 */
	public function import_featured_image_attachment(
		int $featured_media_id,
		string $external_link
	): int|false {
		if ( empty( $featured_media_id ) || empty( $external_link ) ) {
			return 0;
		}

		$source_site_url = $this->extract_site_url( $external_link );

		$attachment_id = $this->media_importer->import_featured_image(
			$featured_media_id,
			$source_site_url
		);

		return $attachment_id;
	}

	/**
	 * Logs an import action to history, only when a session ID is provided.
	 *
	 * @param int|null    $session_id       Import session ID.
	 * @param int|null    $external_post_id External post ID, or null if not provided.
	 * @param string      $title            Post title.
	 * @param string      $status           Import status (success, updated, error).
	 * @param int|null    $post_id          WordPress post ID or null on failure.
	 * @param string|null $error            Error message or null on success.
	 * @param array       $changes          Contextual changes data for the item.
	 */
	private function log_import_if_session(
		?int $session_id,
		?int $external_post_id,
		string $title,
		string $status,
		?int $post_id,
		?string $error,
		array $changes
	): void {
		if ( null === $session_id ) {
			return;
		}

		$this->repository->log_import_action(
			$session_id,
			$external_post_id,
			$title,
			$status,
			$post_id,
			$error,
			$changes
		);
	}

	/**
	 * Builds an error result array after an unexpected exception during import.
	 *
	 * Also logs the exception to history if a session ID is available.
	 *
	 * @param array     $post_data  Original post data passed to import.
	 * @param int|null  $session_id Import session ID.
	 * @param Exception $e          The caught exception.
	 * @return array Error result data.
	 */
	private function build_exception_result(
		array $post_data,
		?int $session_id,
		Exception $e
	): array {
		$fields = $this->extract_post_fields( $post_data );

		if ( '' === $fields['title'] ) {
			$fields['title'] = __( 'Unknown', 'safe-publish' );
		}

		$this->log_import_if_session(
			$session_id,
			$fields['external_post_id'],
			$fields['title'],
			'error',
			null,
			$e->getMessage(),
			array()
		);

		return $this->build_error_result( $fields, $e->getMessage() );
	}
}
