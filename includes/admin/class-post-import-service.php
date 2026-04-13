<?php
/**
 * Post Import Service class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\API\External_Posts_API;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Auth_Credential_Provider;
use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Options;
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
	 * Import History instance.
	 *
	 * @var Import_History
	 */
	private Import_History $import_history;

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
	 * Maps plural REST API post type names to singular WordPress post type slugs.
	 *
	 * @var array<string, string>
	 */
	private array $post_type_map = array(
		'posts'          => 'post',
		'pages'          => 'page',
		'attachments'    => 'attachment',
		'revisions'      => 'revision',
		'nav_menu_items' => 'nav_menu_item',
	);

	/**
	 * Constructs the Post_Import_Service instance.
	 *
	 * @param External_Posts_API $api                External Posts API instance.
	 * @param Media_Importer     $media_importer     Media Importer instance.
	 * @param Content_Processor  $content_processor  Content Processor instance.
	 * @param Import_History     $import_history     Import History instance.
	 * @param Meta_Terms_Manager $meta_terms_manager Meta Terms Manager instance.
	 */
	public function __construct(
		External_Posts_API $api,
		Media_Importer $media_importer,
		Content_Processor $content_processor,
		Import_History $import_history,
		Meta_Terms_Manager $meta_terms_manager
	) {
		$this->api                = $api;
		$this->media_importer     = $media_importer;
		$this->content_processor  = $content_processor;
		$this->import_history     = $import_history;
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
			return $validation_error;
		}

		$post_type     = $this->resolve_post_type( $fields['raw_post_type'] );
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
	 * @return array Sanitized post fields keyed by field name.
	 */
	private function extract_post_fields( array $post_data ): array {
		return array(
			'external_post_id'  => absint( $post_data['id'] ?? 0 ),
			'title'             => sanitize_text_field( $post_data['title'] ?? '' ),
			'external_link'     => esc_url_raw( $post_data['link'] ?? '' ),
			'featured_media_id' => absint( $post_data['featured_media'] ?? 0 ),
			'raw_post_type'     => sanitize_text_field( $post_data['post_type'] ?? 'post' ),
			'excerpt'           => wp_kses_post( $post_data['excerpt'] ?? '' ),
			'meta'              => is_array( $post_data['meta'] ?? null ) ? $post_data['meta'] : array(),
			'terms'             => is_array( $post_data['terms'] ?? null ) ? $post_data['terms'] : array(),
		);
	}

	/**
	 * Validates that required fields are present for import.
	 *
	 * @param array $fields Extracted post fields.
	 * @return array|null Error result array on failure, null on success.
	 */
	private function validate_required_fields( array $fields ): ?array {
		if ( empty( $fields['title'] ) || empty( $fields['external_post_id'] ) ) {
			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => __( 'Missing required post data.', 'safe-publish' ),
			);
		}

		return null;
	}

	/**
	 * Resolves a raw post type string to a valid WordPress post type.
	 *
	 * Converts plural REST API post type names to singular, validates that the
	 * post type exists, and falls back to 'post' based on capability checks.
	 * Administrators (manage_options) may create any registered post type.
	 *
	 * @param string $raw_post_type Raw post type string from external API.
	 * @return string Resolved post type slug.
	 */
	public function resolve_post_type( string $raw_post_type ): string {
		$post_type = $this->post_type_map[ $raw_post_type ] ?? $raw_post_type;

		if ( ! post_type_exists( $post_type ) ) {
			return 'post';
		}

		// Admins can create any registered post type.
		if ( current_user_can( 'manage_options' ) ) {
			return $post_type;
		}

		if ( 'page' === $post_type && ! current_user_can( 'edit_pages' ) ) {
			return 'post';
		}

		if ( 'page' !== $post_type && ! current_user_can( 'edit_posts' ) ) {
			return 'post';
		}

		return $post_type;
	}

	/**
	 * Builds an error result if any media files failed to download during
	 * content processing.
	 *
	 * @param array $fields Sanitized post fields from extract_post_fields().
	 * @return array|null Error result array on failure, null when no failures.
	 */
	private function get_failed_media_error( array $fields ): ?array {
		$error_message = $this->content_processor->get_failed_media_error_message();

		if ( null === $error_message ) {
			return null;
		}

		return array(
			'external_id' => $fields['external_post_id'],
			'title'       => $fields['title'],
			'success'     => false,
			'error'       => $error_message,
		);
	}

	/**
	 * Processes raw post content by importing media and fixing URLs.
	 *
	 * Returns the original content unchanged if external_link is empty.
	 *
	 * @param string $content       Raw post content.
	 * @param string $external_link External post URL used to derive site URL.
	 * @return string Processed and sanitized content.
	 */
	private function process_post_content( string $content, string $external_link ): string {
		if ( empty( $external_link ) ) {
			return $content;
		}

		$site_url = wp_parse_url( $external_link, PHP_URL_SCHEME )
			. '://'
			. wp_parse_url( $external_link, PHP_URL_HOST );

		$processed = $this->content_processor->process_content( $content, $site_url );

		// Apply sanitization after processing to preserve formatting during processing.
		return wp_kses_post( $processed );
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
				$external_modified      = strtotime( $post['modified'] );
				$local_modified         = strtotime( $imported->post_modified );
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
	 * Fetches fresh content from the external site and updates the existing post.
	 * Aborts with an error result if the fetch fails; the post will not be updated
	 * with stale snapshot data.
	 *
	 * Intentional differences from the single-import update path
	 * (@see Admin_Ajax_Controller::update_imported_draft()):
	 * - Post status is preserved (not reset to 'draft') to avoid silently
	 *   unpublishing live posts during automated bulk runs.
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
		$fresh_result = $this->fetch_fresh_content( $fields['external_post_id'] );

		if ( is_wp_error( $fresh_result ) ) {
			$error_message = $fresh_result->get_error_message();

			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				null,
				$error_message,
				array( 'action' => 'fetch_failed' )
			);

			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => $error_message,
			);
		}

		$fields['title']             = $fresh_result['title'];
		$fields['featured_media_id'] = $fresh_result['featured_media'];
		$fields['excerpt']           = $fresh_result['excerpt'];
		$processed_content           = $this->process_post_content(
			$fresh_result['content'] ?? '',
			$fields['external_link']
		);

		$failed_media_error = $this->get_failed_media_error( $fields );

		if ( null !== $failed_media_error ) {
			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				null,
				$failed_media_error['error'],
				array( 'action' => 'media_download_failed' )
			);
			$this->content_processor->delete_newly_created_media();
			return $failed_media_error;
		}

		// Unsanitized values; sanitized downstream before being stored.
		$fields['meta']  = is_array( $fresh_result['meta'] ?? null ) ? $fresh_result['meta'] : $fields['meta'];
		$fields['terms'] = is_array( $fresh_result['terms'] ?? null ) ? $fresh_result['terms'] : $fields['terms'];

		// Sideload the featured image before writing the post so that a failure
		// here does not leave the post in a partially-updated state.
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

			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => $error_message,
			);
		}

		$this->content_processor->disable_content_filters();

		$post_id = wp_update_post(
			array(
				'ID'           => $imported_post->ID,
				'post_title'   => $fields['title'],
				'post_excerpt' => $fields['excerpt'],
				'post_content' => ! empty( $processed_content )
					? $processed_content
					: __( 'Content imported from external source.', 'safe-publish' ),
				'post_type'    => $post_type,
			)
		);

		$this->content_processor->restore_content_filters();

		if ( is_wp_error( $post_id ) ) {
			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => $post_id->get_error_message(),
			);
		}

		update_post_meta( $post_id, Options::META_EXTERNAL_LINK, $fields['external_link'] );

		delete_post_meta( $post_id, Options::META_IMPORT_DATE );
		if ( false === update_post_meta(
			$post_id,
			Options::META_IMPORT_DATE,
			current_time( 'mysql' )
		) ) {
			$error_message = __(
				'Failed to update post tracking metadata.',
				'safe-publish'
			);

			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				$post_id,
				$error_message,
				array( 'action' => 'meta_update_failed' )
			);

			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => $error_message,
			);
		}

		if ( $featured_attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $featured_attachment_id );
		}

		$meta_result = $this->meta_terms_manager->update_meta(
			$post_id,
			$fields['meta']
		);

		if ( is_wp_error( $meta_result ) ) {
			$error_message = $meta_result->get_error_message();

			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				$post_id,
				$error_message,
				array( 'action' => 'meta_update_failed' )
			);

			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => $error_message,
			);
		}

		$terms_result = $this->meta_terms_manager->update_terms(
			$post_id,
			$fields['terms']
		);

		if ( is_wp_error( $terms_result ) ) {
			$error_message = $terms_result->get_error_message();

			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				$post_id,
				$error_message,
				array( 'action' => 'terms_update_failed' )
			);

			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => $error_message,
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

		return array(
			'external_id' => $fields['external_post_id'],
			'title'       => $fields['title'],
			'success'     => true,
			'post_id'     => $post_id,
			'edit_url'    => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'existing'    => true,
		);
	}

	/**
	 * Handles import flow when no matching post exists yet in WordPress.
	 *
	 * Fetches fresh content from the external site and creates a new draft post.
	 * Aborts with an error result if the fetch fails; the post will not be created
	 * with stale snapshot data.
	 *
	 * @see Admin_Ajax_Controller::create_new_draft() for the single-import equivalent.
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
		$fresh_result = $this->fetch_fresh_content( $fields['external_post_id'] );

		if ( is_wp_error( $fresh_result ) ) {
			$error_message = $fresh_result->get_error_message();

			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				null,
				$error_message,
				array( 'action' => 'fetch_failed' )
			);

			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => $error_message,
			);
		}

		$fields['title']             = $fresh_result['title'];
		$fields['featured_media_id'] = $fresh_result['featured_media'];
		$fields['excerpt']           = $fresh_result['excerpt'];
		$processed_content           = $this->process_post_content(
			$fresh_result['content'] ?? '',
			$fields['external_link']
		);

		$failed_media_error = $this->get_failed_media_error( $fields );

		if ( null !== $failed_media_error ) {
			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				null,
				$failed_media_error['error'],
				array( 'action' => 'media_download_failed' )
			);
			$this->content_processor->delete_newly_created_media();
			return $failed_media_error;
		}

		// Unsanitized values; sanitized downstream before being stored.
		$fields['meta']  = is_array( $fresh_result['meta'] ?? null ) ? $fresh_result['meta'] : $fields['meta'];
		$fields['terms'] = is_array( $fresh_result['terms'] ?? null ) ? $fresh_result['terms'] : $fields['terms'];

		// Sideload the featured image before creating the post so that a failure
		// here does not leave an orphaned draft in the DB.
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

			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => $error_message,
			);
		}

		$this->content_processor->disable_content_filters();

		$post_id = wp_insert_post(
			array(
				'post_title'   => $fields['title'],
				'post_excerpt' => $fields['excerpt'],
				'post_content' => ! empty( $processed_content )
					? $processed_content
					: __( 'Content imported from external source.', 'safe-publish' ),
				'post_status'  => 'draft',
				'post_type'    => $post_type,
				'meta_input'   => array(
					Options::META_EXTERNAL_POST_ID => $fields['external_post_id'],
					Options::META_EXTERNAL_LINK    => $fields['external_link'],
					Options::META_IMPORTED_FROM    => Options::META_IMPORTED_FROM_VALUE,
					Options::META_IMPORT_DATE      => current_time( 'mysql' ),
				),
			)
		);

		$this->content_processor->restore_content_filters();

		if ( is_wp_error( $post_id ) ) {
			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => $post_id->get_error_message(),
			);
		}

		if ( $featured_attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $featured_attachment_id );
		}

		$meta_result = $this->meta_terms_manager->update_meta(
			$post_id,
			$fields['meta']
		);

		if ( is_wp_error( $meta_result ) ) {
			wp_delete_post( $post_id, true );
			$this->content_processor->delete_newly_created_media();
			$error_message = $meta_result->get_error_message();

			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				null,
				$error_message,
				array( 'action' => 'meta_update_failed' )
			);

			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => $error_message,
			);
		}

		$terms_result = $this->meta_terms_manager->update_terms(
			$post_id,
			$fields['terms']
		);

		if ( is_wp_error( $terms_result ) ) {
			wp_delete_post( $post_id, true );
			$this->content_processor->delete_newly_created_media();
			$error_message = $terms_result->get_error_message();

			$this->log_import_if_session(
				$session_id,
				$fields['external_post_id'],
				$fields['title'],
				'error',
				null,
				$error_message,
				array( 'action' => 'terms_update_failed' )
			);

			return array(
				'external_id' => $fields['external_post_id'],
				'title'       => $fields['title'],
				'success'     => false,
				'error'       => $error_message,
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

		return array(
			'external_id' => $fields['external_post_id'],
			'title'       => $fields['title'],
			'success'     => true,
			'post_id'     => $post_id,
			'edit_url'    => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'existing'    => false,
		);
	}

	/**
	 * Fetches fresh post content from the configured external site.
	 *
	 * Returns a WP_Error when the fetch fails for any reason, including when no
	 * source site URL is configured. Callers should abort the import on error.
	 *
	 * @param int $external_post_id External post ID to fetch.
	 * @return array|WP_Error Fresh post data, or an error on failure.
	 */
	private function fetch_fresh_content( int $external_post_id ): array|WP_Error {
		$configured_site_url = get_option( Options::OPTION_CONNECTED_SITE_URL, '' );

		if ( empty( $configured_site_url ) ) {
			return new WP_Error(
				'fresh_content_fetch_no_source_url',
				__( 'No source site URL is configured.', 'safe-publish' )
			);
		}

		$auth_credentials = Auth_Credential_Provider::get_credentials();

		try {
			$fresh_data = $this->api->fetch_fresh_post_content(
				$external_post_id,
				$configured_site_url,
				$auth_credentials
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

		$site_url = wp_parse_url( $external_link, PHP_URL_SCHEME )
			. '://'
			. wp_parse_url( $external_link, PHP_URL_HOST );

		$attachment_id = $this->media_importer->import_featured_image(
			$featured_media_id,
			$site_url
		);

		return $attachment_id;
	}

	/**
	 * Logs an import action to history, only when a session ID is provided.
	 *
	 * @param int|null    $session_id  Import session ID.
	 * @param int         $external_id External post ID.
	 * @param string      $title       Post title.
	 * @param string      $status      Import status (success, updated, error).
	 * @param int|null    $post_id     WordPress post ID or null on failure.
	 * @param string|null $error       Error message or null on success.
	 * @param array       $changes     Contextual changes data for the log entry.
	 */
	private function log_import_if_session(
		?int $session_id,
		int $external_id,
		string $title,
		string $status,
		?int $post_id,
		?string $error,
		array $changes
	): void {
		if ( null === $session_id ) {
			return;
		}

		$this->import_history->log_import_action(
			$session_id,
			$external_id,
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
		$external_id = (int) ( $post_data['id'] ?? 0 );
		$title       = $post_data['title'] ?? __( 'Unknown', 'safe-publish' );

		$this->log_import_if_session(
			$session_id,
			$external_id,
			$title,
			'error',
			null,
			$e->getMessage(),
			array()
		);

		return array(
			'external_id' => $external_id,
			'title'       => $title,
			'success'     => false,
			'error'       => $e->getMessage(),
		);
	}
}
