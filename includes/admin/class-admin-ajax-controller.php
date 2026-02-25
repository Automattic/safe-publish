<?php
/**
 * Admin AJAX Controller class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\API\External_Posts_API;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Utils\Auth_Credential_Provider;
use Safe_Publish\Utils\Options;
use Exception;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles AJAX endpoint registration and request handling for the admin area.
 *
 * Registers and processes all admin AJAX actions, delegating to injected
 * services for post imports, content processing, and history tracking.
 */
final class Admin_Ajax_Controller {

	/**
	 * External Posts API instance.
	 *
	 * @var External_Posts_API
	 */
	private External_Posts_API $api;

	/**
	 * Import History instance.
	 *
	 * @var Import_History
	 */
	private Import_History $import_history;

	/**
	 * Content Processor instance.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $content_processor;

	/**
	 * Post Import Service instance.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $post_import_service;

	/**
	 * Meta Terms Manager instance.
	 *
	 * @var Meta_Terms_Manager
	 */
	private Meta_Terms_Manager $meta_terms_manager;

	/**
	 * Constructs the Admin_Ajax_Controller instance.
	 *
	 * @param External_Posts_API  $api                 External Posts API instance.
	 * @param Import_History      $import_history      Import History instance.
	 * @param Content_Processor   $content_processor   Content Processor instance.
	 * @param Post_Import_Service $post_import_service Post Import Service instance.
	 * @param Meta_Terms_Manager  $meta_terms_manager  Meta Terms Manager instance.
	 */
	public function __construct(
		External_Posts_API $api,
		Import_History $import_history,
		Content_Processor $content_processor,
		Post_Import_Service $post_import_service,
		Meta_Terms_Manager $meta_terms_manager
	) {
		$this->api                 = $api;
		$this->import_history      = $import_history;
		$this->content_processor   = $content_processor;
		$this->post_import_service = $post_import_service;
		$this->meta_terms_manager  = $meta_terms_manager;
	}

	/**
	 * Registers all AJAX action handlers.
	 */
	public function register_handlers(): void {
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

		$auth_credentials = Auth_Credential_Provider::get_credentials();

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

		$auth_credentials = Auth_Credential_Provider::get_credentials();

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
	 * Handles AJAX request for creating a draft post.
	 *
	 * Validates input, checks for an existing post with the same external ID,
	 * returns a confirmation prompt when one exists (unless force_update is set),
	 * processes content, creates or updates the post, and logs history.
	 */
	public function ajax_create_draft(): void {
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
		$source_url = get_option( Options::OPTION_EXTERNAL_SITE_URL, '' );
		$session_id = $this->import_history->create_session( $source_url, 'single' );

		$external_post_id  = absint( $_POST['external_post_id'] ?? 0 );
		$title             = sanitize_text_field( $_POST['title'] ?? '' );
		$external_link     = esc_url_raw( $_POST['external_link'] ?? '' );
		$featured_media_id = absint( $_POST['featured_media_id'] ?? 0 );
		$excerpt           = sanitize_text_field( $_POST['excerpt'] ?? '' );
		$raw_post_type     = sanitize_text_field( $_POST['post_type'] ?? 'post' );

		// Preserve Gutenberg block structure; sanitized after processing.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$content = wp_unslash( $_POST['content'] ?? '' );

		// Ensure content is UTF-8 encoded.
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'auto' );
		}

		// JSON string not sanitized to preserve structure; sanitized in update_meta().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$meta = isset( $_POST['meta'] ) ? json_decode( wp_unslash( $_POST['meta'] ) ) : array();

		// JSON string not sanitized to preserve structure; sanitized in update_terms().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$terms = isset( $_POST['terms'] ) ? json_decode( wp_unslash( $_POST['terms'] ) ) : array();

		$post_type = $this->post_import_service->resolve_post_type( $raw_post_type );

		if ( empty( $title ) ) {
			wp_send_json_error( __( 'Post title is required.', 'safe-publish' ) );
		}

		if ( empty( $external_post_id ) ) {
			wp_send_json_error( __( 'External post ID is required.', 'safe-publish' ) );
		}

		$existing_post = $this->post_import_service->find_existing_post( $external_post_id );
		$force_update  = isset( $_POST['force_update'] ) && 'true' === $_POST['force_update'];

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
			$fresh_data = $this->maybe_fetch_fresh_content( $external_post_id );
			if ( $fresh_data ) {
				$title             = $fresh_data['title'] ?? $title;
				$content           = $fresh_data['content'] ?? $content;
				$featured_media_id = $fresh_data['featured_media'] ?? $featured_media_id;
				$excerpt           = $fresh_data['excerpt'] ?? '';
				$meta              = $fresh_data['meta'] ?? array();
				$terms             = $fresh_data['terms'] ?? array();
			}
		}

		$processed_content = $this->process_draft_content( $content, $external_link );

		if ( $existing_post ) {
			$result = $this->update_existing_draft(
				$existing_post,
				$title,
				$excerpt,
				$post_type,
				$processed_content,
				$external_link,
				$featured_media_id,
				$meta,
				$terms,
				$session_id,
				$external_post_id
			);
		} else {
			$result = $this->create_new_draft(
				$title,
				$excerpt,
				$post_type,
				$processed_content,
				$external_link,
				$external_post_id,
				$featured_media_id,
				$meta,
				$terms,
				$session_id
			);
		}

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( $result['error'] );
		}

		wp_send_json_success( $result );
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

		$source_url     = get_option( Options::OPTION_EXTERNAL_SITE_URL, '' );
		$session_result = $this->import_history->create_session( $source_url, 'bulk' );
		$session_id     = is_wp_error( $session_result ) ? null : $session_result;

		$results    = array();
		$successful = 0;
		$failed     = 0;

		foreach ( $posts_data as $post_data ) {
			$result    = $this->post_import_service->import_post( $post_data, $session_id );
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

		$auth_credentials = Auth_Credential_Provider::get_credentials();

		$api_url = trailingslashit( $site_url ) . 'wp-json/wp/v2/types';

		$auth_params = \Safe_Publish\Auth\VIP_Safe_Auth::get_auth_params(
			$api_url,
			$auth_credentials,
			'GET'
		);

		$debug_info = array(
			'site_url'                   => $site_url,
			'api_url'                    => $api_url,
			'auth_credentials_available' => ! empty( $auth_credentials ),
			'auth_credentials_type'      => ! empty( $auth_credentials['shared_secret'] )
				? 'shared_secret'
				: ( ! empty( $auth_credentials['username'] ) ? 'basic_auth' : 'none' ),
			'auth_params'                => $auth_params,
		);

		try {
			$response = $this->api->make_request( $api_url, $auth_credentials );

			if ( is_wp_error( $response ) ) {
				$debug_info['request_error'] = $response->get_error_message();
			} else {
				$response_body                       = wp_remote_retrieve_body( $response );
				$debug_info['response_code']         = wp_remote_retrieve_response_code( $response );
				$debug_info['response_headers']      = wp_remote_retrieve_headers( $response );
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

	/**
	 * Updates an existing post with fresh imported content.
	 *
	 * Stores rollback data, updates post fields, imports featured image,
	 * updates meta and terms, and logs history.
	 *
	 * @param \WP_Post $existing_post     Existing WordPress post.
	 * @param string   $title             Post title.
	 * @param string   $excerpt           Post excerpt.
	 * @param string   $post_type         Resolved post type slug.
	 * @param string   $processed_content Processed post content.
	 * @param string   $external_link     External post URL.
	 * @param int      $featured_media_id External featured media ID.
	 * @param mixed    $meta              Meta data (array or object).
	 * @param mixed    $terms             Terms data (array or object).
	 * @param int|null $session_id        Import session ID.
	 * @param int      $external_post_id  External post ID.
	 * @return array Result data with post_id, edit_url, message, and existing keys, or error key on failure.
	 */
	private function update_existing_draft(
		\WP_Post $existing_post,
		string $title,
		string $excerpt,
		string $post_type,
		string $processed_content,
		string $external_link,
		int $featured_media_id,
		mixed $meta,
		mixed $terms,
		?int $session_id,
		int $external_post_id
	): array {
		$previous_content = $this->capture_rollback_data( $existing_post );

		$post_id = wp_update_post(
			array(
				'ID'           => $existing_post->ID,
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
				'post_content' => ! empty( $processed_content )
					? $processed_content
					: __( 'Content imported from external source.', 'safe-publish' ),
				'post_status'  => 'draft',
				'post_type'    => $post_type,
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		update_post_meta( $post_id, Options::META_EXTERNAL_LINK, $external_link );
		update_post_meta( $post_id, Options::META_IMPORT_DATE, current_time( 'mysql' ) );

		$this->post_import_service->maybe_import_featured_image( $featured_media_id, $external_link, $post_id );

		$this->meta_terms_manager->update_meta( $post_id, $meta );
		$this->meta_terms_manager->update_terms( $post_id, $terms );

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

		return array(
			'post_id'  => $post_id,
			'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'message'  => __( 'Existing draft updated with latest content.', 'safe-publish' ),
			'existing' => true,
		);
	}

	/**
	 * Creates a new draft post with the imported content.
	 *
	 * Inserts the post, imports featured image, updates meta and terms,
	 * and logs history.
	 *
	 * @param string   $title             Post title.
	 * @param string   $excerpt           Post excerpt.
	 * @param string   $post_type         Resolved post type slug.
	 * @param string   $processed_content Processed post content.
	 * @param string   $external_link     External post URL.
	 * @param int      $external_post_id  External post ID.
	 * @param int      $featured_media_id External featured media ID.
	 * @param mixed    $meta              Meta data (array or object).
	 * @param mixed    $terms             Terms data (array or object).
	 * @param int|null $session_id        Import session ID.
	 * @return array Result data with post_id, edit_url, message, and existing keys, or error key on failure.
	 */
	private function create_new_draft(
		string $title,
		string $excerpt,
		string $post_type,
		string $processed_content,
		string $external_link,
		int $external_post_id,
		int $featured_media_id,
		mixed $meta,
		mixed $terms,
		?int $session_id
	): array {
		$this->content_processor->disable_content_filters();

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => ! empty( $processed_content )
					? $processed_content
					: __( 'Content imported from external source.', 'safe-publish' ),
				'post_status'  => 'draft',
				'post_type'    => $post_type,
				'post_excerpt' => $excerpt,
				'meta_input'   => array(
					Options::META_EXTERNAL_POST_ID => $external_post_id,
					Options::META_EXTERNAL_LINK    => $external_link,
					Options::META_IMPORTED_FROM    => Options::META_IMPORTED_FROM_VALUE,
					Options::META_IMPORT_DATE      => current_time( 'mysql' ),
				),
			)
		);

		$this->content_processor->restore_content_filters();

		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		$this->post_import_service->maybe_import_featured_image( $featured_media_id, $external_link, $post_id );

		$this->meta_terms_manager->update_meta( $post_id, $meta );
		$this->meta_terms_manager->update_terms( $post_id, $terms );

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

		return array(
			'post_id'  => $post_id,
			'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'message'  => __( 'Draft post created successfully.', 'safe-publish' ),
			'existing' => false,
		);
	}

	/**
	 * Processes draft post content by importing media and fixing links.
	 *
	 * Returns the sanitized content unchanged if either argument is empty.
	 *
	 * @param string $content       Raw post content.
	 * @param string $external_link External post URL used to derive site URL.
	 * @return string Processed and sanitized content.
	 */
	private function process_draft_content( string $content, string $external_link ): string {
		$processed = $content;

		if ( ! empty( $content ) && ! empty( $external_link ) ) {
			$site_url  = wp_parse_url( $external_link, PHP_URL_SCHEME )
				. '://'
				. wp_parse_url( $external_link, PHP_URL_HOST );
			$processed = $this->content_processor->process_content( $content, $site_url );
		}

		// Apply sanitization after processing to preserve formatting during processing.
		return \wp_kses_post( $processed );
	}



	/**
	 * Captures rollback data from an existing post before updating it.
	 *
	 * Stores the current title, content, excerpt, featured image, and selected
	 * meta fields so the import can be reverted if needed.
	 *
	 * @param \WP_Post $existing_post Existing WordPress post.
	 * @return array Rollback data keyed by field name.
	 */
	private function capture_rollback_data( \WP_Post $existing_post ): array {
		$previous_content = array(
			'previous_content'        => $existing_post->post_content,
			'previous_title'          => $existing_post->post_title,
			'previous_excerpt'        => $existing_post->post_excerpt,
			'previous_featured_image' => get_post_thumbnail_id( $existing_post->ID ),
			'previous_meta'           => array(),
			'action'                  => 'updated_existing',
		);

		$meta_keys_to_preserve = array(
			'_edit_last',
			'_edit_lock',
			Options::META_EXTERNAL_LINK,
			Options::META_IMPORT_DATE,
		);

		foreach ( $meta_keys_to_preserve as $meta_key ) {
			$meta_value = get_post_meta( $existing_post->ID, $meta_key, true );
			if ( '' !== $meta_value ) {
				$previous_content['previous_meta'][ $meta_key ] = $meta_value;
			}
		}

		return $previous_content;
	}

	/**
	 * Tries to fetch fresh post content from the configured external site.
	 *
	 * Returns null if the site URL is not configured or the request fails.
	 *
	 * @param int $external_post_id External post ID to fetch.
	 * @return array|null Fresh post data or null if unavailable.
	 */
	private function maybe_fetch_fresh_content( int $external_post_id ): ?array {
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
		} catch ( Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Safe Publish: Failed to fetch fresh content for update - ' . $e->getMessage() );
			return null;
		}
	}
}
