<?php
/**
 * Admin Handler class
 *
 * @package CCP
 */

namespace CCP\Admin;

use CCP\API\External_Posts_API;
use CCP\Admin\Import_History;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Handler Class.
 */
class Admin_Handler {

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
	 * Constructs the Admin_Handler instance.
	 *
	 * @param External_Posts_API $api External Posts API instance.
	 */
	public function __construct( External_Posts_API $api ) {
		$this->api = $api;
		$this->import_history = new Import_History();
	}

	/**
	 * Initializes admin functionality.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// Initialize import history.
		$this->import_history->init();

		// Early VIP-specific asset preparation.
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'prepare_vip_dependencies' ), 5 );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_ccp_fetch_posts', array( $this, 'ajax_fetch_posts' ) );
		add_action( 'wp_ajax_ccp_fetch_post_types', array( $this, 'ajax_fetch_post_types' ) );
		add_action( 'wp_ajax_ccp_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_ccp_create_draft', array( $this, 'ajax_create_draft' ) );
		add_action( 'wp_ajax_ccp_bulk_import', array( $this, 'ajax_bulk_import' ) );
		add_action( 'wp_ajax_ccp_debug_auth', array( $this, 'ajax_debug_auth' ) );
	}

	/**
	 * Adds admin menu.
	 */
	public function add_admin_menu(): void {
		// Main menu page - Tools.
		add_menu_page(
			__( 'Compliant Content Publisher', 'ccp' ), // Page title
			__( 'CC Publisher', 'ccp' ),                // Menu title
			'manage_options',                            // Capability
			'compliant-content-publisher',               // Menu slug
			array( $this, 'render_admin_page' ),        // Callback
			'dashicons-external',                        // Icon
			99                                           // Position
		);

		// Settings submenu page.
		add_submenu_page(
			'compliant-content-publisher',               // Parent slug
			__( 'CCP Settings', 'ccp' ),                // Page title
			__( 'Settings', 'ccp' ),                     // Menu title
			'manage_options',                            // Capability
			'ccp-settings',                              // Menu slug
			array( $this, 'render_settings_page' )      // Callback
		);
	}

	/**
	 * Handles admin initialization.
	 */
	public function admin_init(): void {
		register_setting(
			'ccp_settings',
			'ccp_external_site_url',
			array(
				'sanitize_callback' => array( $this, 'sanitize_url' ),
				'default'           => '',
			)
		);

		register_setting(
			'ccp_settings',
			'ccp_number_of_posts',
			array(
				'sanitize_callback' => array( $this, 'sanitize_number_of_posts' ),
				'default'           => 10,
			)
		);

		// VIP-safe authentication settings.
		register_setting(
			'ccp_settings',
			'ccp_shared_secret',
			array(
				'sanitize_callback' => array( $this, 'sanitize_shared_secret' ),
				'default'           => '',
			)
		);

		// Basic authentication settings (development only).
		if ( \ccp_is_development_environment() ) {
			register_setting(
				'ccp_settings',
				'ccp_username',
				array(
					'sanitize_callback' => array( $this, 'sanitize_username' ),
					'default'           => '',
				)
			);

			register_setting(
				'ccp_settings',
				'ccp_password',
				array(
					'sanitize_callback' => array( $this, 'sanitize_password' ),
					'default'           => '',
				)
			);
		}
	}

	/**
	 * Prepares VIP-specific dependencies early.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function prepare_vip_dependencies( $hook_suffix ): void {
		// Only on our specific admin page.
		if ( 'toplevel_page_compliant-content-publisher' !== $hook_suffix ) {
			return;
		}

		// Force registration of required WordPress core scripts in VIP environment.
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );

		// Try to register wp-dataviews if available.
		if ( ! wp_script_is( 'wp-dataviews', 'registered' ) ) {
			// Attempt to register wp-dataviews if the file exists.
			$dataviews_path = ABSPATH . WPINC . '/js/dist/dataviews.min.js';
			if ( file_exists( $dataviews_path ) ) {
				wp_register_script(
					'wp-dataviews',
					includes_url( 'js/dist/dataviews.min.js' ),
					array( 'wp-element', 'wp-components' ),
					get_bloginfo( 'version' ),
					true
				);
			}
		}
	}

	/**
	 * Renders the admin page.
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ccp' ) );
		}

		$admin_page = new Admin_Page( $this->api );
		$admin_page->render();
	}

	/**
	 * Renders the settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ccp' ) );
		}

		$settings_page = new Settings_Page();
		$settings_page->render();
	}

	/**
	 * Enqueues admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook_suffix ): void {
		// Only enqueue on our main admin page (tools page).
		if ( 'toplevel_page_compliant-content-publisher' !== $hook_suffix ) {
			return;
		}

		$admin_page = new Admin_Page( $this->api );
		$admin_page->enqueue_assets();
	}

	/**
	 * Handles AJAX request for fetching posts.
	 */
	public function ajax_fetch_posts(): void {
		// Security check.
		check_ajax_referer( 'ccp_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$site_url        = sanitize_text_field( $_POST['site_url'] ?? '' );
		$number_of_posts = absint( $_POST['number_of_posts'] ?? 10 );
		$post_type       = sanitize_text_field( $_POST['post_type'] ?? 'posts' );

		if ( empty( $site_url ) ) {
			wp_send_json_error( __( 'Site URL is required.', 'ccp' ) );
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
		check_ajax_referer( 'ccp_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$site_url = sanitize_text_field( $_POST['site_url'] ?? '' );

		if ( empty( $site_url ) ) {
			wp_send_json_error( __( 'Site URL is required.', 'ccp' ) );
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
		check_ajax_referer( 'ccp_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$site_url = sanitize_text_field( $_POST['site_url'] ?? '' );

		if ( empty( $site_url ) ) {
			wp_send_json_error( __( 'Site URL is required.', 'ccp' ) );
		}

		$results = $this->api->test_connection( $site_url );

		wp_send_json_success( $results );
	}

	/**
	 * Handles AJAX request for creating draft post.
	 */
	public function ajax_create_draft(): void {
		global $ccp_plugin;

		// Security check.
		\check_ajax_referer( 'ccp_ajax_nonce', 'nonce' );

		if ( ! \current_user_can( 'edit_posts' ) ) {
			\wp_send_json_error(
				array(
					'message' => \__( 'You do not have permission to create posts.', 'ccp' ),
					'debug' => array(
						'user_id' => \get_current_user_id(),
						'capabilities' => array(
							'edit_posts' => \current_user_can( 'edit_posts' ),
							'edit_pages' => \current_user_can( 'edit_pages' ),
							'manage_options' => \current_user_can( 'manage_options' )
						)
					)
				)
			);
		}

		// Create single import session for tracking.
		$source_url = get_option( 'ccp_external_site_url', '' );
		$session_id = $this->import_history->create_session( $source_url, 'single' );

		$ccp_api = $ccp_plugin->get_ccp_api();

		$external_post_id  = \absint( $_POST['external_post_id'] ?? 0 );
		$title             = \sanitize_text_field( $_POST['title'] ?? '' );
		$content           = \wp_unslash( $_POST['content'] ?? '' ); // Preserve original formatting.

		// Ensure content is properly UTF-8 encoded.
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'auto' );
		}
		$external_link     = \esc_url_raw( $_POST['external_link'] ?? '' );
		$featured_media_id = \absint( $_POST['featured_media_id'] ?? 0 );
		$excerpt           = \sanitize_text_field( $_POST['excerpt'] ?? '' );
		$meta			   = isset( $_POST['meta'] ) ? json_decode( \wp_unslash( $_POST['meta'] ) ) : array();
		$terms			   = isset( $_POST['terms'] ) ? json_decode( \wp_unslash( $_POST['terms'] ) ) : array();
		$raw_post_type     = \sanitize_text_field( $_POST['post_type'] ?? 'post' );

		// Convert plural post types to singular for WordPress compatibility.
		$post_type_mapping = array(
			'posts' => 'post',
			'pages' => 'page',
			'attachments' => 'attachment',
			'revisions' => 'revision',
			'nav_menu_items' => 'nav_menu_item',
		);

		$post_type = isset( $post_type_mapping[ $raw_post_type ] )
			? $post_type_mapping[ $raw_post_type ]
			: $raw_post_type;

		// For debugging - temporarily disable validation to see if that's the issue.
		// Just ensure the post type exists.
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		} else {
			// More permissive check - if user is admin or has manage_options, allow any post type.
			if ( current_user_can( 'manage_options' ) ) {
				// Admin can create any post type that exists.
			} elseif ( 'page' === $post_type && ! current_user_can( 'edit_pages' ) ) {
				$post_type = 'post'; // Fallback to post if can't create pages.
			} elseif ( 'page' !== $post_type && ! current_user_can( 'edit_posts' ) ) {
				$post_type = 'post'; // Fallback for other post types.
			}
		}
		// Comment out permission checking for now to test.

		if ( empty( $title ) ) {
			\wp_send_json_error( \__( 'Post title is required.', 'ccp' ) );
		}

		if ( empty( $external_post_id ) ) {
			\wp_send_json_error( \__( 'External post ID is required.', 'ccp' ) );
		}

		// Check if a draft already exists for this external post.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts,WordPressVIPMinimum.Performance.MetaQueryUsage.MetaQueryUsage -- suppress_filters is set to false for VIP caching compatibility
		$existing_posts = get_posts(
			array(
				// phpcs:disable WordPressVIPMinimum.Performance.MetaQueryUsage.MetaQueryUsage
				'meta_query'       => array(
					array(
						'key'     => 'ccp_external_post_id',
						'value'   => $external_post_id,
						'compare' => '=',
					),
				),
				// phpcs:enable WordPressVIPMinimum.Performance.MetaQueryUsage.MetaQueryUsage
				'post_status'      => array( 'draft', 'publish', 'pending', 'private' ),
				'numberposts'      => 1,
				'suppress_filters' => false, // Enable caching for VIP compatibility.
			)
		);

		$existing_post = null;
		if ( ! empty( $existing_posts ) ) {
			$existing_post = $existing_posts[0];
		}

		// Check if user wants to force update (confirmation received).
		$force_update = isset( $_POST['force_update'] ) && $_POST['force_update'] === 'true';

		// If post exists and no force update, ask for confirmation.
		if ( $existing_post && ! $force_update ) {
			wp_send_json_success(
				array(
					'existing'     => true,
					'post_id'      => $existing_post->ID,
					'post_title'   => $existing_post->post_title,
					'edit_url'     => admin_url( 'post.php?post=' . $existing_post->ID . '&action=edit' ),
					'message'      => sprintf(
						__( 'Post "%s" already exists. Do you want to update it with the latest content from the external site?', 'ccp' ),
						$existing_post->post_title
					),
					'confirm_action' => 'update_existing',
				)
			);
			return;
		}

		// Fetch fresh content from external site if updating existing post.
		if ( $existing_post && $force_update ) {
			// Get the configured site URL from settings.
			$configured_site_url = get_option( 'ccp_site_url', '' );

			if ( ! empty( $configured_site_url ) ) {
				// Get authentication credentials for fresh content request.
				$auth_credentials = $this->get_auth_credentials();

				// Try to get fresh content from the API.
				try {
					$fresh_post_data = $this->api->fetch_fresh_post_content( $external_post_id, $configured_site_url, $auth_credentials );
					if ( $fresh_post_data ) {
						$title = $fresh_post_data['title'] ?? $title;
						$content = $fresh_post_data['content'] ?? $content;
						$featured_media_id = $fresh_post_data['featured_media'] ?? $featured_media_id;
						$excerpt = $fresh_post_data['excerpt'] ?? '';
						$meta = $fresh_post_data['meta'] ?? array();
						$terms = $fresh_post_data['terms'] ?? array();
					}
				} catch ( Exception $e ) {
					// Continue with provided content if fresh fetch fails.
					error_log( 'CCP: Failed to fetch fresh content for update - ' . $e->getMessage() );
				}
			}
		}

		// Process content to import media and fix links (done once for both new and existing posts).
		$processed_content = $content;
		if ( ! empty( $content ) && ! empty( $external_link ) ) {
			// Extract the site URL from the external link.
			$site_url = wp_parse_url( $external_link, PHP_URL_SCHEME ) . '://' . wp_parse_url( $external_link, PHP_URL_HOST );

			// Check if content contains Gutenberg blocks.
			if ( $this->is_gutenberg_content( $content ) ) {
				$processed_content = $this->process_gutenberg_blocks( $content, $site_url );
			} else {
				// Fallback to traditional content processing.
				$processed_content = $this->api->process_and_import_media( $content, $site_url );
				// Process oEmbeds using WordPress functionality.
				$processed_content = $this->process_oembed_content( $processed_content );
			}

			// Only replace external URLs if we actually processed the content.
			if ( $processed_content !== $content ) {
				$processed_content = $this->replace_external_urls( $processed_content, $site_url );
			}
		}

		// Apply sanitization after processing to preserve formatting during processing.
		// $processed_content = \wp_kses_post( $processed_content );

		if ( $existing_post ) {
			// Store previous content for potential rollback.
			$previous_content = array(
				'previous_content' => $existing_post->post_content,
				'previous_title' => $existing_post->post_title,
				'previous_excerpt' => $existing_post->post_excerpt,
				'previous_featured_image' => \get_post_thumbnail_id( $existing_post->ID ),
				'previous_meta' => array(),
				'action' => 'updated_existing',
			);

			// Store important meta fields that might be changed.
			$meta_keys_to_preserve = array(
				'_edit_last',
				'_edit_lock',
				'ccp_external_link',
				'ccp_import_date'
			);

			foreach ( $meta_keys_to_preserve as $meta_key ) {
				$meta_value = \get_post_meta( $existing_post->ID, $meta_key, true );
				if ( $meta_value !== '' ) {
					$previous_content['previous_meta'][ $meta_key ] = $meta_value;
				}
			}

			// Update existing post.
			$post_data_array = array(
				'ID'           => $existing_post->ID,
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
				'post_content' => ! empty( $processed_content ) ? $processed_content : __( 'Content imported from external source.', 'ccp' ),
				'post_status'  => 'draft',
				'post_type'    => $post_type,
			);

			$post_id = \wp_update_post( $post_data_array );

			if ( \is_wp_error( $post_id ) ) {
				\wp_send_json_error( $post_id->get_error_message() );
			}

			// Update meta data.
			\update_post_meta( $post_id, 'ccp_external_link', $external_link );
			\update_post_meta( $post_id, 'ccp_import_date', \current_time( 'mysql' ) );

			// Import featured image if provided.
			if ( ! empty( $featured_media_id ) && ! empty( $external_link ) ) {
				$site_url               = wp_parse_url( $external_link, PHP_URL_SCHEME ) . '://' . wp_parse_url( $external_link, PHP_URL_HOST );
				$featured_attachment_id = $this->api->import_featured_image( $featured_media_id, $site_url );

				if ( $featured_attachment_id ) {
					\set_post_thumbnail( $post_id, $featured_attachment_id );
				}
			}

			// Update meta and terms.
			$ccp_api->update_meta( $post_id, $meta );
			$ccp_api->update_terms( $post_id, $terms );

			$edit_url = \admin_url( 'post.php?post=' . $post_id . '&action=edit' );

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
					'message'  => __( 'Existing draft updated with latest content.', 'ccp' ),
					'existing' => true,
				)
			);
			return; // Exit here to prevent creating a new post.
		}

		// Create new draft post with content filtering temporarily disabled.
		$this->disable_content_filters();

		$post_data = array(
			'post_title'   => $title,
			'post_content' => ! empty( $processed_content ) ? $processed_content : __( 'Content imported from external source.', 'ccp' ),
			'post_status'  => 'draft',
			'post_type'    => $post_type,
			'post_excerpt' => $excerpt,
			'meta_input'   => array(
				'ccp_external_post_id' => $external_post_id,
				'ccp_external_link'    => $external_link,
				'ccp_imported_from'    => 'ccp',
				'ccp_import_date'      => \current_time( 'mysql' ),
			),
		);

		$post_id = \wp_insert_post( $post_data );

		// Re-enable content filters.
		$this->restore_content_filters();

		if ( \is_wp_error( $post_id ) ) {
			\wp_send_json_error( $post_id->get_error_message() );
		}

		// Import featured image if provided.
		if ( ! empty( $featured_media_id ) && ! empty( $external_link ) ) {
			$site_url               = \wp_parse_url( $external_link, \PHP_URL_SCHEME ) . '://' . \wp_parse_url( $external_link, \PHP_URL_HOST );
			$featured_attachment_id = $this->api->import_featured_image( $featured_media_id, $site_url );

			if ( $featured_attachment_id ) {
				\set_post_thumbnail( $post_id, $featured_attachment_id );
			}
		}

		// Update meta and terms.
		$ccp_api->update_meta( $post_id, $meta );
		$ccp_api->update_terms( $post_id, $terms );

		// Return success with edit URL.
		$edit_url = \admin_url( 'post.php?post=' . $post_id . '&action=edit' );

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

		\wp_send_json_success(
			array(
				'post_id'  => $post_id,
				'edit_url' => $edit_url,
				'message'  => __( 'Draft post created successfully.', 'ccp' ),
				'existing' => false,
			)
		);
	}

	/**
	 * Handles AJAX request for bulk importing posts.
	 */
	public function ajax_bulk_import(): void {
		// Security check.
		check_ajax_referer( 'ccp_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( -1, 403 );
		}

		// Get raw posts data with minimal sanitization to preserve JSON structure.
		$posts_data_json = isset( $_POST['posts_data'] ) ? \stripslashes( $_POST['posts_data'] ) : '';

		if ( empty( $posts_data_json ) ) {
			wp_send_json_error( __( 'Posts data is required.', 'ccp' ) );
		}

		$posts_data = json_decode( $posts_data_json, true );

		if ( ! is_array( $posts_data ) || empty( $posts_data ) ) {
			wp_send_json_error( __( 'Invalid posts data provided.', 'ccp' ) );
		}

		// Limit bulk operations to prevent timeout/memory issues.
		if ( count( $posts_data ) > 50 ) {
			wp_send_json_error( __( 'Bulk import limited to 50 posts at a time.', 'ccp' ) );
		}

		// Create import session.
		$source_url = get_option( 'ccp_external_site_url', '' );
		$session_id = $this->import_history->create_session( $source_url, 'bulk' );

		$results = array();
		$successful = 0;
		$failed = 0;

		foreach ( $posts_data as $post_data ) {
			$result = $this->import_single_post( $post_data, $session_id );
			$results[] = $result;

			if ( $result['success'] ) {
				$successful++;
				$status = $result['existing'] ? 'updated' : 'success';
				$this->import_history->update_session_stats( $session_id, $status );
			} else {
				$failed++;
				$this->import_history->update_session_stats( $session_id, 'error' );
			}
		}

		// Complete the session.
		$this->import_history->complete_session( $session_id );

		wp_send_json_success( array(
			'total' => count( $posts_data ),
			'successful' => $successful,
			'failed' => $failed,
			'results' => $results,
			'session_id' => $session_id,
		) );
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
			$external_post_id  = absint( $post_data['id'] ?? 0 );
			$title             = sanitize_text_field( $post_data['title'] ?? '' );
			$content           = wp_unslash( $post_data['content'] ?? '' ); // Preserve original formatting.

			// Ensure content is properly UTF-8 encoded.
			if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
				$content = mb_convert_encoding( $content, 'UTF-8', 'auto' );
			}
			$external_link     = esc_url_raw( $post_data['link'] ?? '' );
			$featured_media_id = absint( $post_data['featured_media'] ?? 0 );
			$raw_post_type     = sanitize_text_field( $post_data['post_type'] ?? 'post' );

			if ( empty( $title ) || empty( $external_post_id ) ) {
				return array(
					'external_id' => $external_post_id,
					'title' => $title,
					'success' => false,
					'error' => __( 'Missing required post data.', 'ccp' ),
				);
			}

			// Convert plural post types to singular for WordPress compatibility.
			$post_type_mapping = array(
				'posts' => 'post',
				'pages' => 'page',
				'attachments' => 'attachment',
				'revisions' => 'revision',
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
				$site_url = wp_parse_url( $external_link, PHP_URL_SCHEME ) . '://' . wp_parse_url( $external_link, PHP_URL_HOST );

				// Check if content contains Gutenberg blocks.
				if ( $this->is_gutenberg_content( $content ) ) {
					$processed_content = $this->process_gutenberg_blocks( $content, $site_url );
				} else {
					// Fallback to traditional content processing.
					$processed_content = $this->api->process_and_import_media( $content, $site_url );
					// Process oEmbeds using WordPress functionality.
					$processed_content = $this->process_oembed_content( $processed_content );
				}

				// Only replace external URLs if we actually processed the content.
				if ( $processed_content !== $content ) {
					$processed_content = $this->replace_external_urls( $processed_content, $site_url );
				}
			}

			// Check if a draft already exists for this external post.
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts,WordPressVIPMinimum.Performance.MetaQueryUsage.MetaQueryUsage -- suppress_filters is set to false for VIP caching compatibility
			$existing_posts = get_posts(
				array(
					// phpcs:disable WordPressVIPMinimum.Performance.MetaQueryUsage.MetaQueryUsage
					'meta_query'       => array(
						array(
							'key'     => 'ccp_external_post_id',
							'value'   => $external_post_id,
							'compare' => '=',
						),
					),
					// phpcs:enable WordPressVIPMinimum.Performance.MetaQueryUsage.MetaQueryUsage
					'post_status'      => array( 'draft', 'publish', 'pending', 'private' ),
					'numberposts'      => 1,
					'suppress_filters' => false, // Enable caching for VIP compatibility.
				)
			);

			$existing_post = null;
			if ( ! empty( $existing_posts ) ) {
				$existing_post = $existing_posts[0];

				// Fetch fresh content from external site when updating existing post.
				$configured_site_url = get_option( 'ccp_site_url', '' );

				if ( ! empty( $configured_site_url ) ) {
					// Get authentication credentials for fresh content request.
					$auth_credentials = $this->get_auth_credentials();

					// Try to get fresh content from the API.
					try {
						$fresh_post_data = $this->api->fetch_fresh_post_content( $external_post_id, $configured_site_url, $auth_credentials );
						if ( $fresh_post_data ) {
							$title = $fresh_post_data['title'] ?? $title;
							$content = $fresh_post_data['content'] ?? $content;
							$featured_media_id = $fresh_post_data['featured_media'] ?? $featured_media_id;
						}
					} catch ( \Exception $e ) {
						// Continue with provided content if fresh fetch fails.
					}
				}
			}

			if ( $existing_post ) {
				// Update existing post with content filtering temporarily disabled.
				$this->disable_content_filters();

				$post_data_array = array(
					'ID'           => $existing_post->ID,
					'post_title'   => $title,
					'post_content' => ! empty( $processed_content ) ? $processed_content : __( 'Content imported from external source.', 'ccp' ),
					'post_type'    => $post_type,
				);

				$post_id = \wp_update_post( $post_data_array );

				// Re-enable content filters.
				$this->restore_content_filters();

				if ( is_wp_error( $post_id ) ) {
					return array(
						'external_id' => $external_post_id,
						'title' => $title,
						'success' => false,
						'error' => $post_id->get_error_message(),
					);
				}

				// Update meta data.
				\update_post_meta( $post_id, 'ccp_external_link', $external_link );
				\update_post_meta( $post_id, 'ccp_import_date', \current_time( 'mysql' ) );

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
						array( 'action' => 'updated_existing', 'previous_content_preserved' => true )
					);
				}

				return array(
					'external_id' => $external_post_id,
					'title' => $title,
					'success' => true,
					'post_id' => $post_id,
					'edit_url' => $edit_url,
					'existing' => true,
				);
			} else {
				// Create new draft post with content filtering temporarily disabled.
				$this->disable_content_filters();

				$post_data_array = array(
					'post_title'   => $title,
					'post_content' => ! empty( $processed_content ) ? $processed_content : __( 'Content imported from external source.', 'ccp' ),
					'post_status'  => 'draft',
					'post_type'    => $post_type,
					'meta_input'   => array(
						'ccp_external_post_id' => $external_post_id,
						'ccp_external_link'    => $external_link,
						'ccp_imported_from'    => 'ccp',
						'ccp_import_date'      => current_time( 'mysql' ),
					),
				);

				$post_id = wp_insert_post( $post_data_array );

				// Re-enable content filters.
				$this->restore_content_filters();

				if ( is_wp_error( $post_id ) ) {
					return array(
						'external_id' => $external_post_id,
						'title' => $title,
						'success' => false,
						'error' => $post_id->get_error_message(),
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
					'title' => $title,
					'success' => true,
					'post_id' => $post_id,
					'edit_url' => $edit_url,
					'existing' => false,
				);
			}
		} catch ( \Exception $e ) {
			// Log the error.
			if ( $session_id ) {
				$this->import_history->log_import_action(
					$session_id,
					$post_data['id'] ?? 0,
					$post_data['title'] ?? 'Unknown',
					'error',
					null,
					$e->getMessage()
				);
			}

			return array(
				'external_id' => $post_data['id'] ?? 0,
				'title' => $post_data['title'] ?? 'Unknown',
				'success' => false,
				'error' => $e->getMessage(),
			);
		}
	}

	/**
	 * Sanitizes URL setting.
	 *
	 * @param string $url URL to sanitize.
	 * @return string Sanitized URL.
	 */
	public function sanitize_url( $url ): string {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return '';
		}

		// Use the URL validator.
		if ( ! \CCP\Validators\URL_Validator::is_valid_external_url( $url ) ) {
			add_settings_error(
				'ccp_external_site_url',
				'invalid_url',
				__( 'Please enter a valid external site URL.', 'ccp' )
			);
			return get_option( 'ccp_external_site_url', '' );
		}

		return $url;
	}

	/**
	 * Sanitizes number of posts setting.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return int Sanitized number of posts.
	 */
	public function sanitize_number_of_posts( $value ): int {
		$number = absint( $value );

		if ( $number < 1 || $number > 100 ) {
			add_settings_error(
				'ccp_number_of_posts',
				'invalid_number',
				__( 'Number of posts must be between 1 and 100.', 'ccp' )
			);
			return get_option( 'ccp_number_of_posts', 10 );
		}

		return $number;
	}

	/**
	 * Sanitizes checkbox value.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return bool Sanitized checkbox value.
	 */
	public function sanitize_checkbox( $value ): bool {
		return (bool) $value;
	}

	/**
	 * Sanitizes shared secret for VIP-safe authentication.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized shared secret.
	 */
	public function sanitize_shared_secret( $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		$secret = sanitize_text_field( $value );

		// Validate length - shared secrets should be at least 32 characters for security.
		if ( strlen( $secret ) < 32 ) {
			add_settings_error(
				'ccp_shared_secret',
				'invalid_secret',
				__( 'Shared secret must be at least 32 characters long for security.', 'ccp' )
			);
			return get_option( 'ccp_shared_secret', '' );
		}

		return $secret;
	}

	/**
	 * Sanitizes username for Basic authentication (development only).
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized username.
	 */
	public function sanitize_username( $value ): string {
		if ( ! \ccp_is_development_environment() ) {
			return '';
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Sanitizes password for Basic authentication (development only).
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized password.
	 */
	public function sanitize_password( $value ): string {
		if ( ! \ccp_is_development_environment() ) {
			return '';
		}

		// Don't sanitize passwords beyond trimming whitespace.
		return trim( $value );
	}

	/**
	 * Gets authentication credentials from settings.
	 *
	 * @return array Authentication credentials array with appropriate keys.
	 */
	private function get_auth_credentials(): array {
		// Try VIP-safe authentication first.
		$shared_secret = get_option( 'ccp_shared_secret', '' );

		if ( ! empty( $shared_secret ) ) {
			return array(
				'shared_secret' => $shared_secret,
			);
		}

		// Fallback to Basic auth in development environments only.
		if ( \ccp_is_development_environment() ) {
			$username = get_option( 'ccp_username', '' );
			$password = get_option( 'ccp_password', '' );

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
	 * Processes content to handle oEmbed URLs.
	 *
	 * @param string $content Post content.
	 * @return string Processed content with oEmbeds.
	 */
	public function process_oembed_content( $content ): string {
		if ( empty( $content ) ) {
			return $content;
		}

		// Get WordPress oEmbed handler.
		global $wp_embed;

		// Process auto-embeds (URLs on their own line).
		$content = $wp_embed->autoembed( $content );

		// Process shortcode embeds.
		$content = $wp_embed->run_shortcode( $content );

		// Handle common embed patterns that might not be caught.
		$content = $this->process_manual_embeds( $content );

		return $content;
	}

	/**
	 * Processes manual embed patterns.
	 *
	 * @param string $content Post content.
	 * @return string Processed content.
	 */
	public function process_manual_embeds( $content ): string {
		// YouTube embed patterns.
		$content = preg_replace_callback(
			'/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/',
			function ( $matches ) {
				return "[embed]https://www.youtube.com/watch?v={$matches[1]}[/embed]";
			},
			$content
		);

		// Vimeo embed patterns.
		$content = preg_replace_callback(
			'/(?:https?:\/\/)?(?:www\.)?vimeo\.com\/([0-9]+)/',
			function ( $matches ) {
				return "[embed]https://vimeo.com/{$matches[1]}[/embed]";
			},
			$content
		);

		// Twitter embed patterns.
		$content = preg_replace_callback(
			'/(?:https?:\/\/)?(?:www\.)?twitter\.com\/\w+\/status\/([0-9]+)/',
			function ( $matches ) {
				return "[embed]https://twitter.com/user/status/{$matches[1]}[/embed]";
			},
			$content
		);

		// Instagram embed patterns.
		$content = preg_replace_callback(
			'/(?:https?:\/\/)?(?:www\.)?instagram\.com\/p\/([a-zA-Z0-9_-]+)/',
			function ( $matches ) {
				return "[embed]https://www.instagram.com/p/{$matches[1]}[/embed]";
			},
			$content
		);

		return $content;
	}

	/**
	 * Checks if content contains Gutenberg blocks.
	 *
	 * @param string $content Post content.
	 * @return bool True if content contains blocks.
	 */
	public function is_gutenberg_content( $content ): bool {
		// Check for block comment syntax.
		return false !== strpos( $content, '<!-- wp:' );
	}

	/**
	 * Processes Gutenberg blocks and imports media.
	 *
	 * @param string $content  Post content with blocks.
	 * @param string $site_url Source site URL.
	 * @return string Processed content.
	 */
	public function process_gutenberg_blocks( $content, $site_url ): string {
		if ( empty( $content ) ) {
			return $content;
		}

		// Store original content for whitespace preservation.
		$original_content = $content;

		// Parse blocks using WordPress parse_blocks function.
		$blocks = \parse_blocks( $content );

		if ( empty( $blocks ) ) {
			return $content;
		}

		// Check if any processing is actually needed to avoid unnecessary serialization.
		$needs_processing = $this->content_needs_media_processing( $content, $site_url );

		if ( ! $needs_processing ) {
			// If no external media/links found, return original content to preserve formatting.
			return $original_content;;
		}

		// Process each block.
		$processed_blocks = array_map(
			function ( $block ) use ( $site_url ) {
				return $this->process_single_block( $block, $site_url );
			},
			$blocks
		);

		// Serialize blocks back to content.
		$serialized_content = \serialize_blocks( $processed_blocks );

		return $serialized_content;
	}

	/**
	 * Processes a single Gutenberg block.
	 *
	 * @param array  $block    Block data.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	public function process_single_block( $block, $site_url ): array {
		if ( empty( $block['blockName'] ) ) {
			return $block;
		}

		switch ( $block['blockName'] ) {
			case 'core/image':
				$block = $this->process_image_block( $block, $site_url );
				break;

			case 'core/gallery':
				$block = $this->process_gallery_block( $block, $site_url );
				break;

			case 'core/video':
				$block = $this->process_video_block( $block, $site_url );
				break;

			case 'core/audio':
				$block = $this->process_audio_block( $block, $site_url );
				break;

			case 'core/embed':
			case 'core-embed/youtube':
			case 'core-embed/vimeo':
			case 'core-embed/twitter':
			case 'core-embed/instagram':
				$block = $this->process_embed_block( $block, $site_url );
				break;

			case 'core/html':
				$block = $this->process_html_block( $block, $site_url );
				break;

			case 'core/paragraph':
			case 'core/heading':
			case 'core/list':
			case 'core/quote':
				$block = $this->process_text_block( $block, $site_url );
				break;

			default:

				// Process innerHTML for any blocks that might contain media or links.
				if ( ! empty( $block['innerHTML'] ) ) {
					$block['innerHTML'] = $this->api->process_and_import_media( $block['innerHTML'], $site_url );
				}
				break;
		}

		return $block;
	}

	/**
	 * Processes image block.
	 *
	 * @param array  $block    Image block.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	public function process_image_block( $block, $site_url ): array {
		$original_url = '';

		// First try to get URL from block attributes.
		if ( ! empty( $block['attrs']['url'] ) ) {
			$original_url = $block['attrs']['url'];
		} elseif ( ! empty( $block['innerHTML'] ) ) {
			// Extract URL from innerHTML img src attribute.
			$original_url = $this->extract_img_src_from_html( $block['innerHTML'] );
		}

		// If we found a URL, process it.
		if ( ! empty( $original_url ) ) {
			$attachment_id = false;
			$new_url       = false;

			// Method 1: Try to import and get attachment ID directly.
			$attachment_id = $this->api->import_external_media_as_attachment( $original_url, $site_url );

			if ( $attachment_id && is_numeric( $attachment_id ) ) {
				// Get the new URL from the attachment ID.
				$new_url = \wp_get_attachment_url( $attachment_id );
			} else {
				// Method 2: Fallback - use original method if the first didn't work.
				$new_url = $this->api->import_external_media( $original_url, $site_url );

				if ( $new_url ) {
					// Try to get attachment ID from the URL.
					$attachment_id = $this->api->get_attachment_id_from_url( $new_url );
				}
			}

			// If we successfully imported the media, update the block.
			if ( $new_url && $attachment_id ) {
				// Initialize attrs if it doesn't exist.
				if ( ! isset( $block['attrs'] ) ) {
					$block['attrs'] = array();
				}

				// Update block attributes with local URL and attachment ID.
				$block['attrs']['url'] = $new_url;
				$block['attrs']['id']  = $attachment_id;

				// Also update other common image attributes that might reference the URL.
				if ( isset( $block['attrs']['src'] ) ) {
					$block['attrs']['src'] = $new_url;
				}

				// Update innerHTML with new URL and wp-image class if it exists.
				if ( ! empty( $block['innerHTML'] ) ) {
					$updated_html = $this->update_img_src_in_html( $block['innerHTML'], $original_url, $new_url );
					// Also update the wp-image class with the new attachment ID.
					$updated_html         = $this->update_wp_image_class( $updated_html, $attachment_id );
					$block['innerHTML']   = $updated_html;
				}

				// Update innerContent array if it exists (used by serialize_blocks).
				if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
					foreach ( $block['innerContent'] as $index => $content ) {
						if ( is_string( $content ) ) {
							$updated_content = $this->update_img_src_in_html( $content, $original_url, $new_url );
							// Also update the wp-image class with the new attachment ID.
							$updated_content                  = $this->update_wp_image_class( $updated_content, $attachment_id );
							$block['innerContent'][ $index ] = $updated_content;
						}
					}
				}
			}
		}

		return $block;
	}

	/**
	 * Processes gallery block.
	 *
	 * @param array  $block    Gallery block.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	public function process_gallery_block( $block, $site_url ): array {
		// Handle traditional gallery format with images in attributes.
		if ( ! empty( $block['attrs']['images'] ) && is_array( $block['attrs']['images'] ) ) {
			foreach ( $block['attrs']['images'] as $index => $image ) {
				if ( ! empty( $image['url'] ) ) {
					$original_url = $image['url'];

					// Import the media and get the attachment ID directly.
					$attachment_id = $this->api->import_external_media_as_attachment( $original_url, $site_url );

					if ( $attachment_id ) {
						// Get the new URL from the attachment ID.
						$new_url = \wp_get_attachment_url( $attachment_id );

						if ( $new_url ) {
							// Update block attributes.
							$block['attrs']['images'][ $index ]['url'] = $new_url;
							$block['attrs']['images'][ $index ]['id'] = $attachment_id;

							// Also update innerHTML with new URL if it exists.
							if ( ! empty( $block['innerHTML'] ) ) {
								$updated_html = $this->update_img_src_in_html( $block['innerHTML'], $original_url, $new_url );
								$updated_html = $this->update_wp_image_class( $updated_html, $attachment_id );
								$block['innerHTML'] = $updated_html;
							}

							// Update innerContent array if it exists (used by serialize_blocks).
							if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
								foreach ( $block['innerContent'] as $content_index => $content ) {
									if ( is_string( $content ) ) {
										$updated_content = $this->update_img_src_in_html( $content, $original_url, $new_url );
										$updated_content = $this->update_wp_image_class( $updated_content, $attachment_id );
										$block['innerContent'][ $content_index ] = $updated_content;
									}
								}
							}
						}
					}
				}
			}
		}

		// Handle block-based gallery format with innerBlocks containing image blocks.
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $index => $inner_block ) {
				// Process nested image blocks using the existing process_image_block function.
				if ( ! empty( $inner_block['blockName'] ) && $inner_block['blockName'] === 'core/image' ) {
					$block['innerBlocks'][ $index ] = $this->process_image_block( $inner_block, $site_url );
				} else {
					// Process other types of inner blocks recursively.
					$block['innerBlocks'][ $index ] = $this->process_single_block( $inner_block, $site_url );
				}
			}

			// Update innerContent array to reflect any changes in innerBlocks.
			if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				// Regenerate innerContent based on processed innerBlocks.
				// This is needed because innerContent is used by serialize_blocks().
				$new_inner_content = array();
				$inner_block_index = 0;

				foreach ( $block['innerContent'] as $content ) {
					if ( is_null( $content ) ) {
						// null values represent positions where inner blocks should be inserted.
						if ( isset( $block['innerBlocks'][ $inner_block_index ] ) ) {
							$new_inner_content[] = null; // Keep the null placeholder.
							$inner_block_index++;
						}
					} else {
						// String content remains as is.
						$new_inner_content[] = $content;
					}
				}

				$block['innerContent'] = $new_inner_content;
			}
		}

		return $block;
	}

	/**
	 * Processes video block.
	 *
	 * @param array  $block    Video block.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	public function process_video_block( $block, $site_url ): array {
		if ( ! empty( $block['attrs']['src'] ) ) {
			$original_url = $block['attrs']['src'];

			// Import the media and get the attachment ID directly.
			$attachment_id = $this->api->import_external_media_as_attachment( $original_url, $site_url );

			if ( $attachment_id ) {
				// Get the new URL from the attachment ID.
				$new_url = \wp_get_attachment_url( $attachment_id );

				if ( $new_url ) {
					$block['attrs']['src'] = $new_url;
					$block['attrs']['id'] = $attachment_id;
				}
			}
		}

		return $block;
	}

	/**
	 * Processes audio block.
	 *
	 * @param array  $block    Audio block.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	public function process_audio_block( $block, $site_url ): array {
		if ( ! empty( $block['attrs']['src'] ) ) {
			$original_url = $block['attrs']['src'];

			// Import the media and get the attachment ID directly.
			$attachment_id = $this->api->import_external_media_as_attachment( $original_url, $site_url );

			if ( $attachment_id ) {
				// Get the new URL from the attachment ID.
				$new_url = \wp_get_attachment_url( $attachment_id );

				if ( $new_url ) {
					$block['attrs']['src'] = $new_url;
					$block['attrs']['id'] = $attachment_id;
				}
			}
		}

		return $block;
	}

	/**
	 * Processes embed block.
	 *
	 * @param array  $block    Embed block.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	public function process_embed_block( $block, $site_url ): array {
		// Most embed blocks work with URLs that don't need media import,
		// but we can process the innerHTML for any embedded media.
		if ( ! empty( $block['innerHTML'] ) ) {
			$block['innerHTML'] = $this->api->process_and_import_media( $block['innerHTML'], $site_url );
		}

		return $block;
	}

	/**
	 * Processes HTML block.
	 *
	 * @param array  $block    HTML block.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	public function process_html_block( $block, $site_url ): array {
		if ( ! empty( $block['attrs']['content'] ) ) {
			$block['attrs']['content'] = $this->api->process_and_import_media( $block['attrs']['content'], $site_url );
		}

		if ( ! empty( $block['innerHTML'] ) ) {
			$block['innerHTML'] = $this->api->process_and_import_media( $block['innerHTML'], $site_url );
		}

		return $block;
	}

	/**
	 * Processes text blocks (paragraph, heading, list, quote).
	 *
	 * @param array  $block    Text block.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	public function process_text_block( $block, $site_url ): array {
		// Process innerHTML which contains the actual content with potential links/media.
		if ( ! empty( $block['innerHTML'] ) ) {
			$block['innerHTML'] = $this->api->process_and_import_media( $block['innerHTML'], $site_url );
		}

		return $block;
	}

	/**
	 * Extracts image src attribute from HTML content.
	 *
	 * @param string $html HTML content.
	 * @return string Extracted src URL or empty string if not found.
	 */
	public function extract_img_src_from_html( $html ): string {
		if ( empty( $html ) ) {
			return '';
		}

		// Use DOMDocument for safe HTML parsing.
		$dom = new \DOMDocument();

		// Suppress errors for malformed HTML and use UTF-8 encoding.
		$previous_use_errors = \libxml_use_internal_errors( true );
		$dom->loadHTML( $html, \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD );
		\libxml_use_internal_errors( $previous_use_errors );

		$images = $dom->getElementsByTagName( 'img' );

		if ( $images->length > 0 ) {
			$img = $images->item( 0 ); // Get the first image.
			$src = $img->getAttribute( 'src' );

			if ( ! empty( $src ) ) {
				return trim( $src );
			}
		}

		// Fallback to regex if DOMDocument fails.
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Updates image src attribute in HTML content with proper DOM handling.
	 *
	 * @param string $html    HTML content.
	 * @param string $old_url Old image URL to replace.
	 * @param string $new_url New image URL.
	 * @return string Updated HTML content.
	 */
	public function update_img_src_in_html( $html, $old_url, $new_url ): string {
		if ( empty( $html ) || empty( $old_url ) || empty( $new_url ) ) {
			return $html;
		}

		// Store original formatting by preserving whitespace context.
		$original_html = $html;

		// Use a more targeted regex approach to avoid XML declaration issues.
		// This is safer for block innerHTML since we're only replacing the src attribute.
		$pattern = '/(<img[^>]+src=["\'])' . preg_quote( $old_url, '/' ) . '(["\'][^>]*>)/i';
		$replacement = '${1}' . $new_url . '${2}';

		$updated_html = preg_replace( $pattern, $replacement, $html );

		// If regex replacement worked, apply minimal normalization.
		if ( $updated_html !== null && $updated_html !== $html ) {
			// Only normalize Gutenberg-specific spacing - preserve other whitespace.
			// Gutenberg requires NO space before /> for self-closing tags.
			$updated_html = preg_replace( '/\s+\/>/', '/>', $updated_html );

			// Only compress excessive consecutive spaces within attributes, but preserve line breaks.
			$updated_html = preg_replace( '/( [a-zA-Z-]+=")(\s{2,})/', '${1} ', $updated_html );

			return $updated_html;
		}

		// Fallback to simple string replacement with minimal normalization.
		$fallback_html = str_replace( $old_url, $new_url, $html );

		// Apply the same minimal normalization to the fallback.
		$fallback_html = preg_replace( '/\s+\/>/', '/>', $fallback_html );
		$fallback_html = preg_replace( '/( [a-zA-Z-]+=")(\s{2,})/', '${1} ', $fallback_html );

		return $fallback_html;
	}

	/**
	 * Updates wp-image class with new attachment ID.
	 *
	 * @param string $html              HTML content.
	 * @param int    $new_attachment_id New attachment ID.
	 * @return string Updated HTML content.
	 */
	public function update_wp_image_class( $html, $new_attachment_id ): string {
		if ( empty( $html ) || empty( $new_attachment_id ) ) {
			return $html;
		}

		// Pattern to match wp-image-{number} class.
		$pattern = '/wp-image-\d+/';
		$replacement = 'wp-image-' . $new_attachment_id;

		$updated_html = preg_replace( $pattern, $replacement, $html );

		// If no existing wp-image class found, add it to the img tag.
		if ( $updated_html === $html && strpos( $html, '<img' ) !== false ) {
			// Add wp-image class to img tag that doesn't have one.
			$pattern = '/(<img[^>]+class=["\'])([^"\']*?)(["\'][^>]*>)/i';
			$replacement = '${1}${2} wp-image-' . $new_attachment_id . '${3}';
			$updated_html = preg_replace( $pattern, $replacement, $html );

			// If img tag has no class attribute at all, add one.
			if ( $updated_html === $html ) {
				$pattern = '/(<img[^>]+)(\s*\/?>)/i';
				$replacement = '${1} class="wp-image-' . $new_attachment_id . '"${2}';
				$updated_html = preg_replace( $pattern, $replacement, $html );
			}
		}

		return $updated_html ?: $html;
	}

	/**
	 * Replaces external site URLs with current site URLs in content.
	 *
	 * @param string $content           Content to process.
	 * @param string $external_site_url External site URL to replace.
	 * @return string Content with URLs replaced.
	 */
	public function replace_external_urls( $content, $external_site_url ): string {
		if ( empty( $content ) || empty( $external_site_url ) ) {
			return $content;
		}

		$current_site_url = get_site_url();
		$external_host = wp_parse_url( $external_site_url, PHP_URL_HOST );
		$current_host = wp_parse_url( $current_site_url, PHP_URL_HOST );

		// Skip if URLs are the same.
		if ( $external_host === $current_host ) {
			return $content;
		}

		// Parse HTML content.
		$dom = new \DOMDocument();
		$dom->loadHTML(
			$content,
			\LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD | \LIBXML_NOERROR | \LIBXML_NOWARNING
		);

		// Process anchor tags (links).
		$links = $dom->getElementsByTagName( 'a' );
		foreach ( $links as $link ) {
			$href = $link->getAttribute( 'href' );
			if ( ! empty( $href ) ) {
				$updated_href = $this->replace_url_domain( $href, $external_site_url, $current_site_url );
				if ( $updated_href !== $href ) {
					$link->setAttribute( 'href', $updated_href );
				}
			}
		}

		// Process other elements that might have URLs (like images, forms, etc.).
		$elements_with_urls = array(
			'img' => 'src',
			'form' => 'action',
			'iframe' => 'src',
			'embed' => 'src',
			'object' => 'data',
		);

		foreach ( $elements_with_urls as $tag => $attribute ) {
			$elements = $dom->getElementsByTagName( $tag );
			foreach ( $elements as $element ) {
				$url = $element->getAttribute( $attribute );
				if ( ! empty( $url ) ) {
					$updated_url = $this->replace_url_domain( $url, $external_site_url, $current_site_url );
					if ( $updated_url !== $url ) {
						$element->setAttribute( $attribute, $updated_url );
					}
				}
			}
		}

		// Return processed content.
		$processed_content = '';
		foreach ( $dom->childNodes as $child ) {
			if ( $child->nodeType === \XML_DOCUMENT_TYPE_NODE ) {
				continue;
			}
			$processed_content .= $dom->saveHTML( $child );
		}

		return $processed_content ?: $content;
	}

	/**
	 * Replaces domain in a URL if it matches the external site.
	 *
	 * @param string $url              URL to process.
	 * @param string $external_site_url External site URL.
	 * @param string $current_site_url  Current site URL.
	 * @return string Processed URL.
	 */
	public function replace_url_domain( $url, $external_site_url, $current_site_url ): string {
		// Skip empty URLs, anchors, or non-HTTP URLs.
		if ( empty( $url ) || strpos( $url, '#' ) === 0 || strpos( $url, 'mailto:' ) === 0 || strpos( $url, 'tel:' ) === 0 ) {
			return $url;
		}

		$external_host = wp_parse_url( $external_site_url, PHP_URL_HOST );
		$url_host = wp_parse_url( $url, PHP_URL_HOST );

		// If URL is relative, make it absolute with external site first.
		if ( empty( $url_host ) ) {
			if ( strpos( $url, '/' ) === 0 ) {
				// Absolute path.
				$url = rtrim( $external_site_url, '/' ) . $url;
			} else {
				// Relative path - skip for now as it's complex to resolve.
				return $url;
			}
			$url_host = $external_host;
		}

		// Replace domain if it matches the external site.
		if ( $url_host === $external_host ) {
			$url_parts = wp_parse_url( $url );
			$current_parts = wp_parse_url( $current_site_url );

			$new_url = $current_parts['scheme'] . '://' . $current_parts['host'];

			if ( isset( $current_parts['port'] ) ) {
				$new_url .= ':' . $current_parts['port'];
			}

			if ( isset( $url_parts['path'] ) ) {
				$new_url .= $url_parts['path'];
			}

			if ( isset( $url_parts['query'] ) ) {
				$new_url .= '?' . $url_parts['query'];
			}

			if ( isset( $url_parts['fragment'] ) ) {
				$new_url .= '#' . $url_parts['fragment'];
			}

			return $new_url;
		}

		return $url;
	}

	/**
	 * Handles debug authentication AJAX request.
	 */
	public function ajax_debug_auth(): void {
		// Security check.
		check_ajax_referer( 'ccp_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1, 403 );
		}

		$site_url = sanitize_text_field( $_POST['site_url'] ?? '' );

		if ( empty( $site_url ) ) {
			wp_send_json_error( __( 'Site URL is required.', 'ccp' ) );
		}

		// Get authentication credentials from settings.
		$auth_credentials = $this->get_auth_credentials();

		// Test the authentication with a simple types endpoint call.
		$api_url = trailingslashit( $site_url ) . 'wp-json/wp/v2/types';

		// Get VIP Safe Auth parameters.
		$auth_params = \CCP\Auth\VIP_Safe_Auth::get_auth_params( $api_url, $auth_credentials, 'GET' );

		$debug_info = array(
			'site_url' => $site_url,
			'api_url' => $api_url,
			'auth_credentials_available' => ! empty( $auth_credentials ),
			'auth_credentials_type' => ! empty( $auth_credentials['shared_secret'] ) ? 'shared_secret' : ( ! empty( $auth_credentials['username'] ) ? 'basic_auth' : 'none' ),
			'auth_params' => $auth_params,
		);

		// Try to make the actual request.
		try {
			$response = $this->api->make_request( $api_url, $auth_credentials );

			if ( is_wp_error( $response ) ) {
				$debug_info['request_error'] = $response->get_error_message();
			} else {
				$debug_info['response_code'] = wp_remote_retrieve_response_code( $response );
				$debug_info['response_headers'] = wp_remote_retrieve_headers( $response );
				$response_body = wp_remote_retrieve_body( $response );
				$debug_info['response_body_length'] = strlen( $response_body );
				$debug_info['response_body_preview'] = substr( $response_body, 0, 200 );

				$json_data = json_decode( $response_body, true );
				if ( $json_data ) {
					$debug_info['response_json_keys'] = array_keys( $json_data );
					$debug_info['post_types_count'] = count( $json_data );
				}
			}
		} catch ( \Exception $e ) {
			$debug_info['exception'] = $e->getMessage();
		}

		wp_send_json_success( $debug_info );
	}

	/**
	 * Checks if content needs media processing to avoid unnecessary serialization.
	 *
	 * @param string $content  Content to check.
	 * @param string $site_url Source site URL.
	 * @return bool True if content needs processing.
	 */
	private function content_needs_media_processing( $content, $site_url ): bool {
		if ( empty( $content ) || empty( $site_url ) ) {
			return false;
		}

		$external_domain = wp_parse_url( $site_url, PHP_URL_HOST );

		// Check for external media URLs.
		if ( strpos( $content, $external_domain ) !== false ) {
			return true;
		}

		// Check for common media file extensions.
		$media_extensions = array( '.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg', '.mp4', '.mov', '.avi', '.mp3', '.wav' );
		foreach ( $media_extensions as $ext ) {
			if ( strpos( $content, $ext ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Preserves original content formatting after processing.
	 *
	 * @param string $original_content  Original content.
	 * @param string $processed_content Processed content.
	 * @return string Content with preserved formatting.
	 */
	/**
	 * Stores for temporarily disabled filters.
	 *
	 * @var array
	 */
	private $disabled_filters = array();

	/**
	 * Temporarily disables content formatting filters during import.
	 */
	private function disable_content_filters(): void {
		global $wp_filter;

		// Store filters that might affect content formatting.
		$filters_to_disable = array(
			'the_content',
			'content_save_pre',
			'wp_insert_post_data',
		);

		foreach ( $filters_to_disable as $filter_name ) {
			if ( isset( $wp_filter[ $filter_name ] ) ) {
				$this->disabled_filters[ $filter_name ] = $wp_filter[ $filter_name ];
				unset( $wp_filter[ $filter_name ] );
			}
		}

		// Specifically remove common formatting filters.
		\remove_filter( 'the_content', 'wpautop' );
		\remove_filter( 'the_content', 'wptexturize' );
		\remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
		\remove_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );
	}

	/**
	 * Restores content formatting filters after import.
	 */
	private function restore_content_filters(): void {
		global $wp_filter;

		// Restore previously disabled filters.
		foreach ( $this->disabled_filters as $filter_name => $filter_callbacks ) {
			$wp_filter[ $filter_name ] = $filter_callbacks;
		}

		// Clear stored filters.
		$this->disabled_filters = array();

		// Re-add common formatting filters with default priorities.
		\add_filter( 'the_content', 'wpautop' );
		\add_filter( 'the_content', 'wptexturize' );
		\add_filter( 'content_save_pre', 'wp_filter_post_kses' );
		\add_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );
	}

}
