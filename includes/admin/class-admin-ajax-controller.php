<?php
/**
 * Admin AJAX Controller class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\API\External_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Post_Type_Fetcher;
use Safe_Publish\Utils\Auth_Credential_Provider;
use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Logger;
use Safe_Publish\Utils\Options;
use Exception;
use WP_Error;
use WP_Post;

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
	 * Post Type Fetcher instance.
	 *
	 * @var Post_Type_Fetcher
	 */
	private Post_Type_Fetcher $post_type_fetcher;

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
	 * Constructs the Admin_Ajax_Controller instance.
	 *
	 * @param External_Posts_API  $api                 External Posts API instance.
	 * @param Import_History      $import_history      Import History instance.
	 * @param Content_Processor   $content_processor   Content Processor instance.
	 * @param Post_Import_Service $post_import_service Post Import Service instance.
	 * @param Meta_Terms_Manager  $meta_terms_manager  Meta Terms Manager instance.
	 * @param Post_Type_Fetcher   $post_type_fetcher   Post Type Fetcher instance.
	 * @param HTTP_Client         $http_client         HTTP Client instance.
	 */
	public function __construct(
		External_Posts_API $api,
		Import_History $import_history,
		Content_Processor $content_processor,
		Post_Import_Service $post_import_service,
		Meta_Terms_Manager $meta_terms_manager,
		Post_Type_Fetcher $post_type_fetcher,
		HTTP_Client $http_client
	) {
		$this->api                 = $api;
		$this->import_history      = $import_history;
		$this->content_processor   = $content_processor;
		$this->post_import_service = $post_import_service;
		$this->meta_terms_manager  = $meta_terms_manager;
		$this->post_type_fetcher   = $post_type_fetcher;
		$this->http_client         = $http_client;
		$this->logger              = new Content_Logger();
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
		add_action( 'wp_ajax_safe_publish_delete_post', array( $this, 'ajax_delete_post' ) );
		add_action( 'wp_ajax_safe_publish_debug_auth', array( $this, 'ajax_debug_auth' ) );
	}

	/**
	 * Handles AJAX request for fetching posts.
	 */
	public function ajax_fetch_posts(): void {
		// Security check.
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Forbidden', 'safe-publish' ), 403 );
		}

		$site_url        = sanitize_text_field( wp_unslash( $_POST['site_url'] ?? '' ) );
		$number_of_posts = absint( $_POST['number_of_posts'] ?? 10 );
		$post_type       = sanitize_text_field( wp_unslash( $_POST['post_type'] ?? 'posts' ) );

		if ( empty( $site_url ) ) {
			wp_send_json_error( __( 'Site URL is required.', 'safe-publish' ) );
		}

		$this->validate_auth_or_fail();

		$auth_credentials = Auth_Credential_Provider::get_credentials();

		$posts = $this->api->fetch_posts( $site_url, $number_of_posts, $auth_credentials, $post_type );

		if ( is_wp_error( $posts ) ) {
			wp_send_json_error( $posts->get_error_message() );
		}

		$this->post_import_service->annotate_posts_with_import_status( $posts );

		wp_send_json_success( $posts );
	}

	/**
	 * Handles AJAX request for fetching post types.
	 */
	public function ajax_fetch_post_types(): void {
		// Security check.
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Forbidden', 'safe-publish' ), 403 );
		}

		$site_url = sanitize_text_field( wp_unslash( $_POST['site_url'] ?? '' ) );

		if ( empty( $site_url ) ) {
			wp_send_json_error( __( 'Site URL is required.', 'safe-publish' ) );
		}

		$this->validate_auth_or_fail();

		$auth_credentials = Auth_Credential_Provider::get_credentials();

		$post_types = $this->post_type_fetcher->fetch_post_types( $site_url, $auth_credentials );

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
			wp_send_json_error( __( 'Forbidden', 'safe-publish' ), 403 );
		}

		$site_url = sanitize_text_field( wp_unslash( $_POST['site_url'] ?? '' ) );

		if ( empty( $site_url ) ) {
			wp_send_json_error( __( 'Site URL is required.', 'safe-publish' ) );
		}

		$this->validate_auth_or_fail();

		$auth_credentials = Auth_Credential_Provider::get_credentials();

		// When the settings form submits live credential fields, always honour
		// them — including when they are empty — so cleared fields override any
		// previously saved Basic Auth credentials.
		if ( array_key_exists( 'username', $_POST ) && array_key_exists( 'password', $_POST ) ) {
			$username = sanitize_text_field( wp_unslash( $_POST['username'] ) );
			$password = sanitize_text_field( wp_unslash( $_POST['password'] ) );

			if ( ! empty( $username ) && ! empty( $password ) ) {
				$auth_credentials['username'] = $username;
				$auth_credentials['password'] = $password;
			} else {
				unset( $auth_credentials['username'], $auth_credentials['password'] );
			}
		}

		$results = $this->api->test_connection( $site_url, $auth_credentials );

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

		$external_post_id = absint( $_POST['external_post_id'] ?? 0 );
		$title            = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$external_link    = esc_url_raw( wp_unslash( $_POST['external_link'] ?? '' ) );
		$raw_post_type    = sanitize_text_field( wp_unslash( $_POST['post_type'] ?? 'post' ) );

		$post_type = $this->post_import_service->resolve_post_type( $raw_post_type );

		if ( empty( $title ) ) {
			wp_send_json_error( __( 'Post title is required.', 'safe-publish' ) );
		}

		if ( empty( $external_post_id ) ) {
			wp_send_json_error( __( 'External post ID is required.', 'safe-publish' ) );
		}

		$imported_post = $this->post_import_service->find_imported_post( $external_post_id );
		$force_update  = isset( $_POST['force_update'] ) && 'true' === $_POST['force_update'];

		// If post was previously imported and no force update, ask for confirmation.
		if ( $imported_post && ! $force_update ) {
			wp_send_json_success(
				array(
					'existing'       => true,
					'post_id'        => $imported_post->ID,
					'post_title'     => $imported_post->post_title,
					'edit_url'       => admin_url( 'post.php?post=' . $imported_post->ID . '&action=edit' ),
					'message'        => sprintf(
						/* translators: %s: title of the existing post */
						__( 'Post "%s" already exists. Do you want to update it with the latest content from the external site?', 'safe-publish' ),
						$imported_post->post_title
					),
					'confirm_action' => 'update_existing',
				)
			);
		}

		// Create single import session for tracking.
		$source_url     = get_option( Options::OPTION_CONNECTED_SITE_URL, '' );
		$session_result = $this->import_history->create_session( $source_url, 'single' );

		if ( is_wp_error( $session_result ) ) {
			wp_send_json_error( $session_result->get_error_message() );
		}

		$session_id = $session_result;

		// Fetch fresh content from the external site.
		$fresh_result = $this->maybe_fetch_fresh_content( $external_post_id );

		if ( is_wp_error( $fresh_result ) ) {
			$error_message = $fresh_result->get_error_message();

			$this->import_history->log_import_action(
				$session_id,
				$external_post_id,
				$title,
				'error',
				null,
				$error_message,
				array( 'action' => 'fetch_failed' )
			);
			$this->import_history->update_session_stats( $session_id, 'error' );
			$this->import_history->complete_session( $session_id );

			wp_send_json_error( $error_message );
		}

		$title             = $fresh_result['title'];
		$featured_media_id = $fresh_result['featured_media'];
		$excerpt           = $fresh_result['excerpt'];

		// Unsanitized values; sanitized downstream before being stored.
		$content = $fresh_result['content'] ?? '';
		$meta    = $fresh_result['meta'] ?? array();
		$terms   = $fresh_result['terms'] ?? array();

		$processed_content = $this->process_draft_content( $content, $external_link );

		$failed_media = $this->content_processor->get_failed_media();

		if ( ! empty( $failed_media ) ) {
			$error_message = $this->content_processor->get_failed_media_error_message();

			$this->import_history->log_import_action(
				$session_id,
				$external_post_id,
				$title,
				'error',
				null,
				$error_message,
				array( 'action' => 'media_download_failed' )
			);
			$this->import_history->update_session_stats( $session_id, 'error' );
			$this->import_history->complete_session( $session_id );
			$this->content_processor->delete_newly_created_media();

			wp_send_json_error( $error_message );
		}

		if ( $imported_post ) {
			$result = $this->update_imported_draft(
				$imported_post,
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
			wp_send_json_error( __( 'Forbidden', 'safe-publish' ), 403 );
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

		$source_url     = get_option( Options::OPTION_CONNECTED_SITE_URL, '' );
		$session_result = $this->import_history->create_session( $source_url, 'bulk' );

		if ( is_wp_error( $session_result ) ) {
			wp_send_json_error( $session_result->get_error_message() );
		}

		$session_id = $session_result;

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
			wp_send_json_error( __( 'Forbidden', 'safe-publish' ), 403 );
		}

		$site_url = sanitize_text_field( wp_unslash( $_POST['site_url'] ?? '' ) );

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

		$auth_type = 'none';
		if ( ! empty( $auth_credentials['shared_secret'] ) ) {
			$auth_type = ! empty( $auth_credentials['username'] ) ? 'shared_secret+basic_auth' : 'shared_secret';
		}

		$debug_info = array(
			'site_url'                   => $site_url,
			'api_url'                    => $api_url,
			'auth_credentials_available' => ! empty( $auth_credentials['shared_secret'] ),
			'auth_credentials_type'      => $auth_type,
			'auth_params'                => $auth_params,
		);

		try {
			$response = $this->http_client->make_request( $api_url, $auth_credentials );

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
	 * Handles AJAX request for deleting a locally imported post.
	 *
	 * Moves the post to trash by its external post ID.
	 */
	public function ajax_delete_post(): void {
		// Security check.
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error( __( 'Forbidden', 'safe-publish' ), 403 );
		}

		$external_post_id = absint( $_POST['external_post_id'] ?? 0 );

		if ( ! $external_post_id ) {
			wp_send_json_error( __( 'External post ID is required.', 'safe-publish' ) );
		}

		$imported_post = $this->post_import_service->find_imported_post( $external_post_id );

		if ( ! $imported_post ) {
			wp_send_json_error( __( 'Post not found.', 'safe-publish' ) );
		}

		if ( ! current_user_can( 'delete_post', $imported_post->ID ) ) {
			wp_send_json_error( __( 'Forbidden', 'safe-publish' ), 403 );
		}

		$result = wp_trash_post( $imported_post->ID );

		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to delete the post.', 'safe-publish' ) );
		}

		wp_send_json_success( array( 'message' => __( 'Post moved to trash.', 'safe-publish' ) ) );
	}

	/**
	 * Updates an existing post with fresh imported content.
	 *
	 * Sideloads featured image, updates post fields, updates meta and terms,
	 * and logs history.
	 *
	 * @see Post_Import_Service::handle_imported_post() for the bulk-import equivalent.
	 *      Intentional differences here vs the bulk path:
	 *      - Resets post_status to 'draft' (keeps the single-import review flow intact).
	 *      - Captures previous content for the session rollback history log.
	 *      - Does not call disable_content_filters() (standard WP filters apply for
	 *        user-triggered imports).
	 *
	 * @param WP_Post $imported_post     Imported WordPress post.
	 * @param string  $title             Post title.
	 * @param string  $excerpt           Post excerpt.
	 * @param string  $post_type         Resolved post type slug.
	 * @param string  $processed_content Processed post content.
	 * @param string  $external_link     External post URL.
	 * @param int     $featured_media_id External featured media ID.
	 * @param mixed   $meta              Meta data (array or object).
	 * @param mixed   $terms             Terms data (array or object).
	 * @param int     $session_id        Import session ID.
	 * @param int     $external_post_id  External post ID.
	 * @return array Result data with post_id, edit_url, message, and existing keys, or error key on failure.
	 */
	private function update_imported_draft(
		WP_Post $imported_post,
		string $title,
		string $excerpt,
		string $post_type,
		string $processed_content,
		string $external_link,
		int $featured_media_id,
		mixed $meta,
		mixed $terms,
		int $session_id,
		int $external_post_id
	): array {
		$previous_content = $this->capture_previous_content( $imported_post );

		// Sideload the featured image before writing the post so that a failure
		// here does not leave the post in a partially-updated state.
		$featured_attachment_id = $this->post_import_service->import_featured_image_attachment(
			$featured_media_id,
			$external_link
		);

		if ( false === $featured_attachment_id ) {
			$error_message = __( 'Failed to import featured image.', 'safe-publish' );

			$this->import_history->log_import_action(
				$session_id,
				$external_post_id,
				$title,
				'error',
				$imported_post->ID,
				$error_message,
				array( 'action' => 'featured_image_import_failed' )
			);
			$this->import_history->update_session_stats( $session_id, 'error' );
			$this->import_history->complete_session( $session_id );

			return array( 'error' => $error_message );
		}

		$post_id = wp_update_post(
			array(
				'ID'           => $imported_post->ID,
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

		if ( $featured_attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $featured_attachment_id );
		}

		$this->meta_terms_manager->update_meta( $post_id, $meta );
		$terms_result = $this->meta_terms_manager->update_terms(
			$post_id,
			$terms
		);

		if ( is_wp_error( $terms_result ) ) {
			$error_message = $terms_result->get_error_message();

			$this->import_history->log_import_action(
				$session_id,
				$external_post_id,
				$title,
				'error',
				$post_id,
				$error_message,
				array( 'action' => 'terms_update_failed' )
			);
			$this->import_history->update_session_stats( $session_id, 'error' );
			$this->import_history->complete_session( $session_id );

			return array( 'error' => $error_message );
		}

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
	 * @see Post_Import_Service::handle_new_post() for the bulk-import equivalent.
	 *
	 * @param string $title             Post title.
	 * @param string $excerpt           Post excerpt.
	 * @param string $post_type         Resolved post type slug.
	 * @param string $processed_content Processed post content.
	 * @param string $external_link     External post URL.
	 * @param int    $external_post_id  External post ID.
	 * @param int    $featured_media_id External featured media ID.
	 * @param mixed  $meta              Meta data (array or object).
	 * @param mixed  $terms             Terms data (array or object).
	 * @param int    $session_id        Import session ID.
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
		int $session_id
	): array {
		// Sideload the featured image before creating the post so that a failure
		// here does not leave an orphaned draft in the DB.
		$featured_attachment_id = $this->post_import_service->import_featured_image_attachment(
			$featured_media_id,
			$external_link
		);

		if ( false === $featured_attachment_id ) {
			$error_message = __( 'Failed to import featured image.', 'safe-publish' );

			$this->import_history->log_import_action(
				$session_id,
				$external_post_id,
				$title,
				'error',
				null,
				$error_message,
				array( 'action' => 'featured_image_import_failed' )
			);
			$this->import_history->update_session_stats( $session_id, 'error' );
			$this->import_history->complete_session( $session_id );

			return array( 'error' => $error_message );
		}

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

		if ( $featured_attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $featured_attachment_id );
		}

		$this->meta_terms_manager->update_meta( $post_id, $meta );
		$terms_result = $this->meta_terms_manager->update_terms(
			$post_id,
			$terms
		);

		if ( is_wp_error( $terms_result ) ) {
			wp_delete_post( $post_id, true );
			$this->content_processor->delete_newly_created_media();
			$error_message = $terms_result->get_error_message();

			$this->import_history->log_import_action(
				$session_id,
				$external_post_id,
				$title,
				'error',
				null,
				$error_message,
				array( 'action' => 'terms_update_failed' )
			);
			$this->import_history->update_session_stats( $session_id, 'error' );
			$this->import_history->complete_session( $session_id );

			return array( 'error' => $error_message );
		}

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
	 * Sends a JSON error response when the Shared Secret is not configured.
	 */
	private function validate_auth_or_fail(): void {
		$credentials = Auth_Credential_Provider::get_credentials();

		if ( empty( $credentials['shared_secret'] ) ) {
			wp_send_json_error(
				__(
					'Shared Secret is not configured. Add SAFE_PUBLISH_SHARED_SECRET to wp-config.php on both sites.',
					'safe-publish'
				),
				401
			);
		}
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
		return wp_kses_post( $processed );
	}

	/**
	 * Captures previous post content for the session rollback history log.
	 *
	 * Stores the current title, content, excerpt, featured image, and selected
	 * meta fields so the import can be reverted via the session rollback feature.
	 *
	 * @param WP_Post $existing_post Existing WordPress post.
	 * @return array Previous content keyed by field name.
	 */
	private function capture_previous_content( WP_Post $existing_post ): array {
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
	 * Fetches fresh post content from the configured external site.
	 *
	 * Returns a WP_Error when the fetch fails for any reason, including when no
	 * source site URL is configured. Callers should abort the import on error.
	 *
	 * @param int $external_post_id External post ID to fetch.
	 * @return array|WP_Error Fresh post data, or an error on failure.
	 */
	private function maybe_fetch_fresh_content( int $external_post_id ): array|WP_Error {
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
}
