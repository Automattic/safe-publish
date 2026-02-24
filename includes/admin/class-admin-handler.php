<?php
/**
 * Admin Handler class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\API\External_Posts_API;
use Safe_Publish\Admin\Import_History;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\History_Renderer;
use Safe_Publish\Utils\Environment;
use Exception;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Handler Class.
 */
final class Admin_Handler {

	/**
	 * External Posts API instance.
	 *
	 * @var External_Posts_API
	 */
	private $api;

	/**
	 * Import History instance.
	 *
	 * @var Import_History
	 */
	private $import_history;

	/**
	 * Admin Menu Manager instance.
	 *
	 * @var Admin_Menu_Manager
	 */
	private Admin_Menu_Manager $menu_manager;

	/**
	 * Settings Sanitizer instance.
	 *
	 * @var Settings_Sanitizer
	 */
	private Settings_Sanitizer $settings_sanitizer;

	/**
	 * Content Processor instance.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $content_processor;

	/**
	 * Constructs the Admin_Handler instance.
	 *
	 * @param External_Posts_API $api External Posts API instance.
	 */
	public function __construct( External_Posts_API $api ) {
		$this->api                = $api;
		$this->menu_manager       = new Admin_Menu_Manager( $api );
		$this->settings_sanitizer = new Settings_Sanitizer();
		$this->content_processor  = new Content_Processor( $api );

		$repository       = new History_Repository();
		$renderer         = new History_Renderer();
		$formatter        = new Session_Formatter();
		$rollback_service = new Session_Rollback_Service( $repository );

		$this->import_history = new Import_History(
			$repository,
			$renderer,
			$formatter,
			$rollback_service
		);
	}

	/**
	 * Initializes admin functionality.
	 */
	public function init(): void {
		$this->menu_manager->register();
		$this->settings_sanitizer->register();

		// Initialize import history.
		$this->import_history->init();

		add_action( 'wp_ajax_safe_publish_fetch_posts', array( $this, 'ajax_fetch_posts' ) );
		add_action( 'wp_ajax_safe_publish_fetch_post_types', array( $this, 'ajax_fetch_post_types' ) );
		add_action( 'wp_ajax_safe_publish_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_safe_publish_create_draft', array( $this, 'ajax_create_draft' ) );
		add_action( 'wp_ajax_safe_publish_bulk_import', array( $this, 'ajax_bulk_import' ) );
		add_action( 'wp_ajax_safe_publish_debug_auth', array( $this, 'ajax_debug_auth' ) );
	}

	/**
	 * Handles AJAX request for fetching posts.
	 */
	public function ajax_fetch_posts(): void {
		// Security check.
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}

		$site_url        = sanitize_text_field( $_POST['site_url'] ?? '' );
		$number_of_posts = absint( $_POST['number_of_posts'] ?? 10 );
		$post_type       = sanitize_text_field( $_POST['post_type'] ?? 'posts' );

		if ( empty( $site_url ) ) {
			wp_send_json_error( __( 'Site URL is required.', 'safe-publish' ) );
		}

		// Get authentication credentials from settings.
		$auth_credentials = $this->get_auth_credentials();

		$posts = $this->api->fetch_posts( $site_url, $number_of_posts, $auth_credentials, $post_type );

		if ( is_wp_error( $posts ) ) {
			wp_send_json_error( $posts->get_error_message() );
		}

		wp_send_json_success( $posts );
	}

	/**
	 * Handles AJAX request for fetching post types.
	 */
	public function ajax_fetch_post_types(): void {
		// Security check.
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}

		$site_url = sanitize_text_field( $_POST['site_url'] ?? '' );

		if ( empty( $site_url ) ) {
			wp_send_json_error( __( 'Site URL is required.', 'safe-publish' ) );
		}

		// Get authentication credentials from settings.
		$auth_credentials = $this->get_auth_credentials();

		$post_types = $this->api->fetch_post_types( $site_url, $auth_credentials );

		if ( is_wp_error( $post_types ) ) {
			wp_send_json_error( $post_types->get_error_message() );
		}

		wp_send_json_success( $post_types );
	}

	/**
	 * Handles AJAX request for testing connection.
	 */
	public function ajax_test_connection(): void {
		// Security check.
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}

		$site_url = sanitize_text_field( $_POST['site_url'] ?? '' );

		if ( empty( $site_url ) ) {
			wp_send_json_error( __( 'Site URL is required.', 'safe-publish' ) );
		}

		$results = $this->api->test_connection( $site_url );

		wp_send_json_success( $results );
	}

	/**
	 * Handles AJAX request for creating draft post.
	 */
	public function ajax_create_draft(): void {
		global $safe_publish_plugin;

		// Security check.
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to create posts.', 'safe-publish' ),
					'debug'   => array(
						'user_id'      => get_current_user_id(),
						'capabilities' => array(
							'edit_posts'     => current_user_can( 'edit_posts' ),
							'edit_pages'     => current_user_can( 'edit_pages' ),
							'manage_options' => current_user_can( 'manage_options' ),
						),
					),
				)
			);
		}

		// Create single import session for tracking.
		$source_url = get_option( 'safe_publish_external_site_url', '' );
		$session_id = $this->import_history->create_session( $source_url, 'single' );

		$safe_publish_api = $safe_publish_plugin->get_safe_publish_api();

		$external_post_id = absint( $_POST['external_post_id'] ?? 0 );
		$title            = sanitize_text_field( $_POST['title'] ?? '' );
		// Preserve Gutenberg block structure; sanitized after processing.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$content = wp_unslash( $_POST['content'] ?? '' );

		// Ensure content is UTF-8 encoded.
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'auto' );
		}
		$external_link     = esc_url_raw( $_POST['external_link'] ?? '' );
		$featured_media_id = absint( $_POST['featured_media_id'] ?? 0 );
		$excerpt           = sanitize_text_field( $_POST['excerpt'] ?? '' );
		// JSON string not sanitized to preserve structure; sanitized in update_meta().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$meta = isset( $_POST['meta'] ) ? json_decode( wp_unslash( $_POST['meta'] ) ) : array();
		// JSON string not sanitized to preserve structure; sanitized in update_terms().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$terms         = isset( $_POST['terms'] ) ? json_decode( wp_unslash( $_POST['terms'] ) ) : array();
		$raw_post_type = sanitize_text_field( $_POST['post_type'] ?? 'post' );

		// Convert plural post types to singular for WordPress compatibility.
		$post_type_mapping = array(
			'posts'          => 'post',
			'pages'          => 'page',
			'attachments'    => 'attachment',
			'revisions'      => 'revision',
			'nav_menu_items' => 'nav_menu_item',
		);

		$post_type = isset( $post_type_mapping[ $raw_post_type ] )
			? $post_type_mapping[ $raw_post_type ]
			: $raw_post_type;

		// For debugging - temporarily disable validation to see if that's the issue.
		// Just ensure the post type exists.
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedElseif
		} elseif ( current_user_can( 'manage_options' ) ) {
			// More permissive check - if user is admin or has manage_options, allow any post type.
			// Admin can create any post type that exists.
		} elseif ( 'page' === $post_type && ! current_user_can( 'edit_pages' ) ) {
			$post_type = 'post'; // Fallback to post if can't create pages.
		} elseif ( 'page' !== $post_type && ! current_user_can( 'edit_posts' ) ) {
			$post_type = 'post'; // Fallback for other post types.
		}
		// Comment out permission checking for now to test.

		if ( empty( $title ) ) {
			wp_send_json_error( __( 'Post title is required.', 'safe-publish' ) );
		}

		if ( empty( $external_post_id ) ) {
			wp_send_json_error( __( 'External post ID is required.', 'safe-publish' ) );
		}

		// Check if a draft already exists for this external post.
		$existing_posts = get_posts(
			array(
				'meta_key'         => 'safe_publish_external_post_id',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $external_post_id,
				'post_status'      => array( 'draft', 'publish', 'pending', 'private' ),
				'posts_per_page'   => 1,
				'suppress_filters' => false, // Enable caching for VIP compatibility.
			)
		);

		$existing_post = null;
		if ( ! empty( $existing_posts ) ) {
			$existing_post = $existing_posts[0];
		}

		// Check if user wants to force update (confirmation received).
		$force_update = isset( $_POST['force_update'] ) && 'true' === $_POST['force_update'];

		// If post exists and no force update, ask for confirmation.
		if ( $existing_post && ! $force_update ) {
			wp_send_json_success(
				array(
					'existing'       => true,
					'post_id'        => $existing_post->ID,
					'post_title'     => $existing_post->post_title,
					'edit_url'       => admin_url( 'post.php?post=' . $existing_post->ID . '&action=edit' ),
					'message'        => sprintf(
						/* translators: %s: title of the existing post */
						__( 'Post "%s" already exists. Do you want to update it with the latest content from the external site?', 'safe-publish' ),
						$existing_post->post_title
					),
					'confirm_action' => 'update_existing',
				)
			);
		}

		// Fetch fresh content from external site if updating existing post.
		if ( $existing_post && $force_update ) {
			// Get the configured site URL from settings.
			$configured_site_url = get_option( 'safe_publish_site_url', '' );

			if ( ! empty( $configured_site_url ) ) {
				// Get authentication credentials for fresh content request.
				$auth_credentials = $this->get_auth_credentials();

				// Try to get fresh content from the API.
				try {
					$fresh_post_data = $this->api->fetch_fresh_post_content( $external_post_id, $configured_site_url, $auth_credentials );
					if ( $fresh_post_data ) {
						$title             = $fresh_post_data['title'] ?? $title;
						$content           = $fresh_post_data['content'] ?? $content;
						$featured_media_id = $fresh_post_data['featured_media'] ?? $featured_media_id;
						$excerpt           = $fresh_post_data['excerpt'] ?? '';
						$meta              = $fresh_post_data['meta'] ?? array();
						$terms             = $fresh_post_data['terms'] ?? array();
					}
				} catch ( Exception $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'Safe Publish: Failed to fetch fresh content for update - ' . $e->getMessage() );
				}
			}
		}

		// Process content to import media and fix links (done once for both new and existing posts).
		$processed_content = $content;
		if ( ! empty( $content ) && ! empty( $external_link ) ) {
			// Extract the site URL from the external link.
			$site_url          = wp_parse_url( $external_link, PHP_URL_SCHEME ) . '://' . wp_parse_url( $external_link, PHP_URL_HOST );
			$processed_content = $this->content_processor->process_content( $content, $site_url );
		}

		// Apply sanitization after processing to preserve formatting during processing.
		$processed_content = \wp_kses_post( $processed_content );

		if ( $existing_post ) {
			// Store previous content for potential rollback.
			$previous_content = array(
				'previous_content'        => $existing_post->post_content,
				'previous_title'          => $existing_post->post_title,
				'previous_excerpt'        => $existing_post->post_excerpt,
				'previous_featured_image' => get_post_thumbnail_id( $existing_post->ID ),
				'previous_meta'           => array(),
				'action'                  => 'updated_existing',
			);

			// Store important meta fields that might be changed.
			$meta_keys_to_preserve = array(
				'_edit_last',
				'_edit_lock',
				'safe_publish_external_link',
				'safe_publish_import_date',
			);

			foreach ( $meta_keys_to_preserve as $meta_key ) {
				$meta_value = get_post_meta( $existing_post->ID, $meta_key, true );
				if ( '' !== $meta_value ) {
					$previous_content['previous_meta'][ $meta_key ] = $meta_value;
				}
			}

			// Update existing post.
			$post_data_array = array(
				'ID'           => $existing_post->ID,
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
				'post_content' => ! empty( $processed_content ) ? $processed_content : __( 'Content imported from external source.', 'safe-publish' ),
				'post_status'  => 'draft',
				'post_type'    => $post_type,
			);

			$post_id = wp_update_post( $post_data_array );

			if ( is_wp_error( $post_id ) ) {
				wp_send_json_error( $post_id->get_error_message() );
			}

			// Update meta data.
			update_post_meta( $post_id, 'safe_publish_external_link', $external_link );
			update_post_meta( $post_id, 'safe_publish_import_date', current_time( 'mysql' ) );

			// Import featured image if provided.
			if ( ! empty( $featured_media_id ) && ! empty( $external_link ) ) {
				$site_url               = wp_parse_url( $external_link, PHP_URL_SCHEME ) . '://' . wp_parse_url( $external_link, PHP_URL_HOST );
				$featured_attachment_id = $this->api->import_featured_image( $featured_media_id, $site_url );

				if ( $featured_attachment_id ) {
					set_post_thumbnail( $post_id, $featured_attachment_id );
				}
			}

			// Update meta and terms.
			$safe_publish_api->update_meta( $post_id, $meta );
			$safe_publish_api->update_terms( $post_id, $terms );

			$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

			// Log the import action with previous content for rollback.
			$this->import_history->log_import_action(
				$session_id,
				$external_post_id,
				$title,
				'updated',
				$post_id,
				null,
				$previous_content
			);
			$this->import_history->update_session_stats( $session_id, 'updated' );
			$this->import_history->complete_session( $session_id );

			wp_send_json_success(
				array(
					'post_id'  => $post_id,
					'edit_url' => $edit_url,
					'message'  => __( 'Existing draft updated with latest content.', 'safe-publish' ),
					'existing' => true,
				)
			);
		}

		// Create new draft post with content filtering temporarily disabled.
		$this->content_processor->disable_content_filters();

		$post_data = array(
			'post_title'   => $title,
			'post_content' => ! empty( $processed_content ) ? $processed_content : __( 'Content imported from external source.', 'safe-publish' ),
			'post_status'  => 'draft',
			'post_type'    => $post_type,
			'post_excerpt' => $excerpt,
			'meta_input'   => array(
				'safe_publish_external_post_id' => $external_post_id,
				'safe_publish_external_link'    => $external_link,
				'safe_publish_imported_from'    => 'safe-publish',
				'safe_publish_import_date'      => current_time( 'mysql' ),
			),
		);

		$post_id = wp_insert_post( $post_data );

		// Re-enable content filters.
		$this->content_processor->restore_content_filters();

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( $post_id->get_error_message() );
		}

		// Import featured image if provided.
		if ( ! empty( $featured_media_id ) && ! empty( $external_link ) ) {
			$site_url               = wp_parse_url( $external_link, PHP_URL_SCHEME ) . '://' . wp_parse_url( $external_link, PHP_URL_HOST );
			$featured_attachment_id = $this->api->import_featured_image( $featured_media_id, $site_url );

			if ( $featured_attachment_id ) {
				set_post_thumbnail( $post_id, $featured_attachment_id );
			}
		}

		// Update meta and terms.
		$safe_publish_api->update_meta( $post_id, $meta );
		$safe_publish_api->update_terms( $post_id, $terms );

		// Return success with edit URL.
		$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

		// Log the import action and complete session.
		$this->import_history->log_import_action(
			$session_id,
			$external_post_id,
			$title,
			'success',
			$post_id,
			null,
			array( 'action' => 'created_new_post' )
		);
		$this->import_history->update_session_stats( $session_id, 'success' );
		$this->import_history->complete_session( $session_id );

		wp_send_json_success(
			array(
				'post_id'  => $post_id,
				'edit_url' => $edit_url,
				'message'  => __( 'Draft post created successfully.', 'safe-publish' ),
				'existing' => false,
			)
		);
	}

	/**
	 * Handles AJAX request for bulk importing posts.
	 */
	public function ajax_bulk_import(): void {
		// Security check.
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Forbidden', 403 );
		}

		// JSON string not sanitized to preserve structure; validated after decode.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$posts_data_json = isset( $_POST['posts_data'] ) ? wp_unslash( $_POST['posts_data'] ) : '';

		if ( empty( $posts_data_json ) ) {
			wp_send_json_error( __( 'Posts data is required.', 'safe-publish' ) );
		}

		$posts_data = json_decode( $posts_data_json, true );

		if ( ! is_array( $posts_data ) || empty( $posts_data ) ) {
			wp_send_json_error( __( 'Invalid posts data provided.', 'safe-publish' ) );
		}

		// Limit bulk operations to prevent timeout/memory issues.
		if ( count( $posts_data ) > 50 ) {
			wp_send_json_error( __( 'Bulk import limited to 50 posts at a time.', 'safe-publish' ) );
		}

		// Create import session.
		$source_url = get_option( 'safe_publish_external_site_url', '' );
		$session_id = $this->import_history->create_session( $source_url, 'bulk' );

		$results    = array();
		$successful = 0;
		$failed     = 0;

		foreach ( $posts_data as $post_data ) {
			$result    = $this->import_single_post( $post_data, $session_id );
			$results[] = $result;

			if ( $result['success'] ) {
				++$successful;
				$status = $result['existing'] ? 'updated' : 'success';
				$this->import_history->update_session_stats( $session_id, $status );
			} else {
				++$failed;
				$this->import_history->update_session_stats( $session_id, 'error' );
			}
		}

		// Complete the session.
		$this->import_history->complete_session( $session_id );

		wp_send_json_success(
			array(
				'total'      => count( $posts_data ),
				'successful' => $successful,
				'failed'     => $failed,
				'results'    => $results,
				'session_id' => $session_id,
			)
		);
	}

	/**
	 * Imports a single post as part of bulk import.
	 *
	 * @param array $post_data  Post data to import.
	 * @param int   $session_id Optional. Import session ID. Default null.
	 * @return array Result data for this post.
	 */
	private function import_single_post( $post_data, $session_id = null ): array {
		try {
			$external_post_id = absint( $post_data['id'] ?? 0 );
			$title            = sanitize_text_field( $post_data['title'] ?? '' );
			$content          = wp_unslash( $post_data['content'] ?? '' ); // Preserve original formatting.

			// Ensure content is UTF-8 encoded.
			if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
				$content = mb_convert_encoding( $content, 'UTF-8', 'auto' );
			}
			$external_link     = esc_url_raw( $post_data['link'] ?? '' );
			$featured_media_id = absint( $post_data['featured_media'] ?? 0 );
			$raw_post_type     = sanitize_text_field( $post_data['post_type'] ?? 'post' );

			if ( empty( $title ) || empty( $external_post_id ) ) {
				return array(
					'external_id' => $external_post_id,
					'title'       => $title,
					'success'     => false,
					'error'       => __( 'Missing required post data.', 'safe-publish' ),
				);
			}

			// Convert plural post types to singular for WordPress compatibility.
			$post_type_mapping = array(
				'posts'          => 'post',
				'pages'          => 'page',
				'attachments'    => 'attachment',
				'revisions'      => 'revision',
				'nav_menu_items' => 'nav_menu_item',
			);

			$post_type = isset( $post_type_mapping[ $raw_post_type ] )
				? $post_type_mapping[ $raw_post_type ]
				: $raw_post_type;

			// Ensure the post type exists.
			if ( ! post_type_exists( $post_type ) ) {
				$post_type = 'post';
			}

			// Check user permissions for this post type.
			if ( 'page' === $post_type && ! current_user_can( 'edit_pages' ) ) {
				$post_type = 'post'; // Fallback to post if can't create pages.
			} elseif ( 'page' !== $post_type && ! current_user_can( 'edit_posts' ) ) {
				$post_type = 'post'; // Fallback for other post types.
			}

			// Process content to import media and fix links (done once for both new and existing posts).
			$processed_content = $content;
			if ( ! empty( $content ) && ! empty( $external_link ) ) {
				// Extract the site URL from the external link.
				$site_url          = wp_parse_url( $external_link, PHP_URL_SCHEME ) . '://' . wp_parse_url( $external_link, PHP_URL_HOST );
				$processed_content = $this->content_processor->process_content( $content, $site_url );
			}

			// Check if a draft already exists for this external post.
			$existing_posts = get_posts(
				array(
					'meta_key'         => 'safe_publish_external_post_id',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'       => $external_post_id,
					'post_status'      => array( 'draft', 'publish', 'pending', 'private' ),
					'posts_per_page'   => 1,
					'suppress_filters' => false, // Enable caching for VIP compatibility.
				)
			);

			$existing_post = null;
			if ( ! empty( $existing_posts ) ) {
				$existing_post = $existing_posts[0];

				// Fetch fresh content from external site when updating existing post.
				$configured_site_url = get_option( 'safe_publish_site_url', '' );

				if ( ! empty( $configured_site_url ) ) {
					// Get authentication credentials for fresh content request.
					$auth_credentials = $this->get_auth_credentials();

					// Try to get fresh content from the API.
					try {
						$fresh_post_data = $this->api->fetch_fresh_post_content( $external_post_id, $configured_site_url, $auth_credentials );
						if ( $fresh_post_data ) {
							$title             = $fresh_post_data['title'] ?? $title;
							$featured_media_id = $fresh_post_data['featured_media'] ?? $featured_media_id;
						}
					// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					} catch ( Exception $e ) {
						// Continue with provided content if fresh fetch fails.
					}
				}
			}

			if ( $existing_post ) {
				// Update existing post with content filtering temporarily disabled.
				$this->content_processor->disable_content_filters();

				$post_data_array = array(
					'ID'           => $existing_post->ID,
					'post_title'   => $title,
					'post_content' => ! empty( $processed_content ) ? $processed_content : __( 'Content imported from external source.', 'safe-publish' ),
					'post_type'    => $post_type,
				);

				$post_id = wp_update_post( $post_data_array );

				// Re-enable content filters.
				$this->content_processor->restore_content_filters();

				if ( is_wp_error( $post_id ) ) {
					return array(
						'external_id' => $external_post_id,
						'title'       => $title,
						'success'     => false,
						'error'       => $post_id->get_error_message(),
					);
				}

				// Update meta data.
				update_post_meta( $post_id, 'safe_publish_external_link', $external_link );
				update_post_meta( $post_id, 'safe_publish_import_date', current_time( 'mysql' ) );

				$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

				// Log the import action.
				if ( $session_id ) {
					$this->import_history->log_import_action(
						$session_id,
						$external_post_id,
						$title,
						'updated',
						$post_id,
						null,
						array(
							'action'                     => 'updated_existing',
							'previous_content_preserved' => true,
						)
					);
				}

				return array(
					'external_id' => $external_post_id,
					'title'       => $title,
					'success'     => true,
					'post_id'     => $post_id,
					'edit_url'    => $edit_url,
					'existing'    => true,
				);
			} else {
				// Create new draft post with content filtering temporarily disabled.
				$this->content_processor->disable_content_filters();

				$post_data_array = array(
					'post_title'   => $title,
					'post_content' => ! empty( $processed_content ) ? $processed_content : __( 'Content imported from external source.', 'safe-publish' ),
					'post_status'  => 'draft',
					'post_type'    => $post_type,
					'meta_input'   => array(
						'safe_publish_external_post_id' => $external_post_id,
						'safe_publish_external_link'    => $external_link,
						'safe_publish_imported_from'    => 'safe-publish',
						'safe_publish_import_date'      => current_time( 'mysql' ),
					),
				);

				$post_id = wp_insert_post( $post_data_array );

				// Re-enable content filters.
				$this->content_processor->restore_content_filters();

				if ( is_wp_error( $post_id ) ) {
					return array(
						'external_id' => $external_post_id,
						'title'       => $title,
						'success'     => false,
						'error'       => $post_id->get_error_message(),
					);
				}

				// Import featured image if provided.
				if ( ! empty( $featured_media_id ) && ! empty( $external_link ) ) {
					$site_url               = wp_parse_url( $external_link, PHP_URL_SCHEME ) . '://' . wp_parse_url( $external_link, PHP_URL_HOST );
					$featured_attachment_id = $this->api->import_featured_image( $featured_media_id, $site_url );

					if ( $featured_attachment_id ) {
						set_post_thumbnail( $post_id, $featured_attachment_id );
					}
				}

				$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

				// Log the import action.
				if ( $session_id ) {
					$this->import_history->log_import_action(
						$session_id,
						$external_post_id,
						$title,
						'success',
						$post_id,
						null,
						array( 'action' => 'created_new_post' )
					);
				}

				return array(
					'external_id' => $external_post_id,
					'title'       => $title,
					'success'     => true,
					'post_id'     => $post_id,
					'edit_url'    => $edit_url,
					'existing'    => false,
				);
			}
		} catch ( Exception $e ) {
			// Log the error.
			if ( $session_id ) {
				$this->import_history->log_import_action(
					$session_id,
					$post_data['id'] ?? 0,
					$post_data['title'] ?? __( 'Unknown', 'safe-publish' ),
					'error',
					null,
					$e->getMessage()
				);
			}

			return array(
				'external_id' => $post_data['id'] ?? 0,
				'title'       => $post_data['title'] ?? __( 'Unknown', 'safe-publish' ),
				'success'     => false,
				'error'       => $e->getMessage(),
			);
		}
	}

	/**
	 * Gets authentication credentials from settings.
	 *
	 * @return array Authentication credentials array with appropriate keys.
	 */
	private function get_auth_credentials(): array {
		// Try VIP-safe authentication first.
		$shared_secret = get_option( 'safe_publish_shared_secret', '' );

		if ( ! empty( $shared_secret ) ) {
			return array(
				'shared_secret' => $shared_secret,
			);
		}

		// Fallback to Basic auth in development environments only.
		if ( Environment::is_development() ) {
			$username = get_option( 'safe_publish_username', '' );
			$password = get_option( 'safe_publish_password', '' );

			if ( ! empty( $username ) && ! empty( $password ) ) {
				return array(
					'username' => $username,
					'password' => $password,
				);
			}
		}

		return array();
	}

	/**
	 * Gets the Content_Processor instance.
	 *
	 * @return Content_Processor Content processor instance.
	 */
	public function get_content_processor(): Content_Processor {
		return $this->content_processor;
	}

	/**
	 * Handles debug authentication AJAX request.
	 */
	public function ajax_debug_auth(): void {
		// Security check.
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}

		$site_url = sanitize_text_field( $_POST['site_url'] ?? '' );

		if ( empty( $site_url ) ) {
			wp_send_json_error( __( 'Site URL is required.', 'safe-publish' ) );
		}

		// Get authentication credentials from settings.
		$auth_credentials = $this->get_auth_credentials();

		// Test the authentication with a simple types endpoint call.
		$api_url = trailingslashit( $site_url ) . 'wp-json/wp/v2/types';

		// Get VIP Safe Auth parameters.
		$auth_params = \Safe_Publish\Auth\VIP_Safe_Auth::get_auth_params( $api_url, $auth_credentials, 'GET' );

		$debug_info = array(
			'site_url'                   => $site_url,
			'api_url'                    => $api_url,
			'auth_credentials_available' => ! empty( $auth_credentials ),
			'auth_credentials_type'      => ! empty( $auth_credentials['shared_secret'] ) ? 'shared_secret' : ( ! empty( $auth_credentials['username'] ) ? 'basic_auth' : 'none' ),
			'auth_params'                => $auth_params,
		);

		// Try to make the actual request.
		try {
			$response = $this->api->make_request( $api_url, $auth_credentials );

			if ( is_wp_error( $response ) ) {
				$debug_info['request_error'] = $response->get_error_message();
			} else {
				$debug_info['response_code']         = wp_remote_retrieve_response_code( $response );
				$debug_info['response_headers']      = wp_remote_retrieve_headers( $response );
				$response_body                       = wp_remote_retrieve_body( $response );
				$debug_info['response_body_length']  = strlen( $response_body );
				$debug_info['response_body_preview'] = substr( $response_body, 0, 200 );

				$json_data = json_decode( $response_body, true );
				if ( $json_data ) {
					$debug_info['response_json_keys'] = array_keys( $json_data );
					$debug_info['post_types_count']   = count( $json_data );
				}
			}
		} catch ( Exception $e ) {
			$debug_info['exception'] = $e->getMessage();
		}

		wp_send_json_success( $debug_info );
	}
}
