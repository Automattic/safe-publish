<?php
/**
 * Post Import Service class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\API\External_Posts_API;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Auth_Credential_Provider;
use Safe_Publish\Utils\Options;
use Exception;
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
	 * @param External_Posts_API $api               External Posts API instance.
	 * @param Media_Importer     $media_importer    Media Importer instance.
	 * @param Content_Processor  $content_processor Content Processor instance.
	 * @param Import_History     $import_history    Import History instance.
	 */
	public function __construct(
		External_Posts_API $api,
		Media_Importer $media_importer,
		Content_Processor $content_processor,
		Import_History $import_history
	) {
		$this->api               = $api;
		$this->media_importer    = $media_importer;
		$this->content_processor = $content_processor;
		$this->import_history    = $import_history;
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

		$post_type         = $this->resolve_post_type( $fields['raw_post_type'] );
		$processed_content = $this->process_post_content(
			$fields['content'],
			$fields['external_link']
		);
		$existing_post     = $this->find_existing_post( $fields['external_post_id'] );

		if ( $existing_post ) {
			return $this->handle_existing_post(
				$existing_post,
				$fields,
				$post_type,
				$processed_content,
				$session_id
			);
		}

		return $this->handle_new_post(
			$fields,
			$post_type,
			$processed_content,
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
		$content = wp_unslash( $post_data['content'] ?? '' );

		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'auto' );
		}

		return array(
			'external_post_id'  => absint( $post_data['id'] ?? 0 ),
			'title'             => sanitize_text_field( $post_data['title'] ?? '' ),
			'content'           => $content,
			'external_link'     => esc_url_raw( $post_data['link'] ?? '' ),
			'featured_media_id' => absint( $post_data['featured_media'] ?? 0 ),
			'raw_post_type'     => sanitize_text_field( $post_data['post_type'] ?? 'post' ),
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
	 * Processes raw post content by importing media and fixing URLs.
	 *
	 * Returns the original content unchanged if either argument is empty.
	 *
	 * @param string $content       Raw post content.
	 * @param string $external_link External post URL used to derive site URL.
	 * @return string Processed content.
	 */
	private function process_post_content( string $content, string $external_link ): string {
		if ( empty( $content ) || empty( $external_link ) ) {
			return $content;
		}

		$site_url = wp_parse_url( $external_link, PHP_URL_SCHEME )
			. '://'
			. wp_parse_url( $external_link, PHP_URL_HOST );

		return $this->content_processor->process_content( $content, $site_url );
	}

	/**
	 * Finds an existing WordPress post by its external post ID.
	 *
	 * @param int $external_post_id External post ID stored in post meta.
	 * @return WP_Post|null Existing post or null if not found.
	 */
	public function find_existing_post( int $external_post_id ): ?WP_Post {
		$existing_posts = get_posts(
			array(
				'meta_key'         => Options::META_EXTERNAL_POST_ID,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $external_post_id,
				'post_status'      => array( 'draft', 'publish', 'pending', 'private' ),
				'posts_per_page'   => 1,
				'suppress_filters' => false,
			)
		);

		return ! empty( $existing_posts ) ? $existing_posts[0] : null;
	}

	/**
	 * Handles import flow when a matching post already exists in WordPress.
	 *
	 * Attempts to fetch fresh content from the external site, updates the
	 * existing post, and logs the action.
	 *
	 * @param WP_Post  $existing_post     Existing WordPress post.
	 * @param array    $fields            Sanitized post fields.
	 * @param string   $post_type         Resolved post type slug.
	 * @param string   $processed_content Processed post content.
	 * @param int|null $session_id        Import session ID for logging.
	 * @return array Import result data.
	 */
	private function handle_existing_post(
		WP_Post $existing_post,
		array $fields,
		string $post_type,
		string $processed_content,
		?int $session_id
	): array {
		$fresh_data = $this->fetch_fresh_content( $fields['external_post_id'] );

		if ( $fresh_data ) {
			$fields['title']             = $fresh_data['title'] ?? $fields['title'];
			$fields['featured_media_id'] = $fresh_data['featured_media'] ?? $fields['featured_media_id'];
		}

		$this->content_processor->disable_content_filters();

		$post_id = wp_update_post(
			array(
				'ID'           => $existing_post->ID,
				'post_title'   => $fields['title'],
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
		update_post_meta( $post_id, Options::META_IMPORT_DATE, current_time( 'mysql' ) );

		$this->log_import_if_session(
			$session_id,
			$fields['external_post_id'],
			$fields['title'],
			'updated',
			$post_id,
			null,
			array(
				'action'                     => 'updated_existing',
				'previous_content_preserved' => true,
			)
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
	 * Creates a new draft post with the imported content and metadata,
	 * imports the featured image, and logs the action.
	 *
	 * @param array    $fields            Sanitized post fields.
	 * @param string   $post_type         Resolved post type slug.
	 * @param string   $processed_content Processed post content.
	 * @param int|null $session_id        Import session ID for logging.
	 * @return array Import result data.
	 */
	private function handle_new_post(
		array $fields,
		string $post_type,
		string $processed_content,
		?int $session_id
	): array {
		$this->content_processor->disable_content_filters();

		$post_id = wp_insert_post(
			array(
				'post_title'   => $fields['title'],
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

		$this->maybe_import_featured_image(
			$fields['featured_media_id'],
			$fields['external_link'],
			$post_id
		);

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
	 * Returns null silently if the site URL is not configured, or if the
	 * API request fails.
	 *
	 * @param int $external_post_id External post ID to fetch.
	 * @return array|null Fresh post data or null if unavailable.
	 */
	private function fetch_fresh_content( int $external_post_id ): ?array {
		$configured_site_url = get_option( Options::OPTION_SOURCE_SITE_URL, '' );

		if ( empty( $configured_site_url ) ) {
			return null;
		}

		$auth_credentials = Auth_Credential_Provider::get_credentials();

		try {
			$fresh_data = $this->api->fetch_fresh_post_content(
				$external_post_id,
				$configured_site_url,
				$auth_credentials
			);

			return $fresh_data ? $fresh_data : null;
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		} catch ( Exception $e ) {
			// Continue with provided content if fresh fetch fails.
			return null;
		}
	}

	/**
	 * Imports a featured image and sets it as the post thumbnail if available.
	 *
	 * Does nothing if either the media ID or external link is empty.
	 *
	 * @param int    $featured_media_id External featured media ID.
	 * @param string $external_link     External post URL used to derive site URL.
	 * @param int    $post_id           WordPress post ID to attach the thumbnail to.
	 */
	public function maybe_import_featured_image(
		int $featured_media_id,
		string $external_link,
		int $post_id
	): void {
		if ( empty( $featured_media_id ) || empty( $external_link ) ) {
			return;
		}

		$site_url = wp_parse_url( $external_link, PHP_URL_SCHEME )
			. '://'
			. wp_parse_url( $external_link, PHP_URL_HOST );

		$featured_attachment_id = $this->media_importer->import_featured_image(
			$featured_media_id,
			$site_url
		);

		if ( $featured_attachment_id ) {
			set_post_thumbnail( $post_id, $featured_attachment_id );
		}
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

		if ( null !== $session_id ) {
			$this->import_history->log_import_action(
				$session_id,
				$external_id,
				$title,
				'error',
				null,
				$e->getMessage()
			);
		}

		return array(
			'external_id' => $external_id,
			'title'       => $title,
			'success'     => false,
			'error'       => $e->getMessage(),
		);
	}
}
