<?php
/**
 * Admin AJAX Controller class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Post_Type_Fetcher;
use Safe_Publish\Auth\VIP_Safe_Auth;
use Safe_Publish\Utils\Auth_Credential_Provider;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Topological_Sorter;
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

	use Sanitizes_Content;
	use Verifies_Ajax_Request;

	/**
	 * Site transient key for the cached auth probe result.
	 *
	 * @var string
	 */
	const AUTH_STATUS_TRANSIENT = 'safe_publish_auth_status';

	/**
	 * TTL for the auth-status site transient.
	 *
	 * @var int
	 */
	const AUTH_STATUS_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Source Posts API instance.
	 *
	 * @var Source_Posts_API
	 */
	private Source_Posts_API $api;

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

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
	 * Constructs the Admin_Ajax_Controller instance.
	 *
	 * @param Source_Posts_API    $api                 Source Posts API instance.
	 * @param History_Repository  $repository          History repository instance.
	 * @param Content_Processor   $content_processor   Content Processor instance.
	 * @param Post_Import_Service $post_import_service Post Import Service instance.
	 * @param Post_Type_Fetcher   $post_type_fetcher   Post Type Fetcher instance.
	 * @param HTTP_Client         $http_client         HTTP Client instance.
	 */
	public function __construct(
		Source_Posts_API $api,
		History_Repository $repository,
		Content_Processor $content_processor,
		Post_Import_Service $post_import_service,
		Post_Type_Fetcher $post_type_fetcher,
		HTTP_Client $http_client
	) {
		$this->api                 = $api;
		$this->repository          = $repository;
		$this->content_processor   = $content_processor;
		$this->post_import_service = $post_import_service;
		$this->post_type_fetcher   = $post_type_fetcher;
		$this->http_client         = $http_client;
	}

	/**
	 * Registers all AJAX action handlers.
	 */
	public function register_handlers(): void {
		add_action( 'wp_ajax_safe_publish_fetch_posts', array( $this, 'ajax_fetch_posts' ) );
		add_action( 'wp_ajax_safe_publish_fetch_post_types', array( $this, 'ajax_fetch_post_types' ) );
		add_action( 'wp_ajax_safe_publish_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_safe_publish_auth_status', array( $this, 'ajax_auth_status' ) );
		add_action( 'wp_ajax_safe_publish_create_draft', array( $this, 'ajax_create_draft' ) );
		add_action( 'wp_ajax_safe_publish_bulk_import', array( $this, 'ajax_bulk_import' ) );
		add_action( 'wp_ajax_safe_publish_delete_post', array( $this, 'ajax_delete_post' ) );
		add_action( 'wp_ajax_safe_publish_debug_auth', array( $this, 'ajax_debug_auth' ) );

		$this->register_auth_status_invalidation();
	}

	/**
	 * Registers option-update hooks that bust the auth-status transient when
	 * any authentication-related setting changes.
	 */
	private function register_auth_status_invalidation(): void {
		$options  = array(
			Options::OPTION_CONNECTED_SITE_URL,
			Options::OPTION_BASIC_AUTH_USERNAME,
			Options::OPTION_BASIC_AUTH_PASSWORD,
		);
		$callback = array( __CLASS__, 'bust_auth_status_cache' );

		foreach ( $options as $option ) {
			add_action( 'add_option_' . $option, $callback );
			add_action( 'update_option_' . $option, $callback );
		}
	}

	/**
	 * Deletes the cached auth-status site transient.
	 */
	public static function bust_auth_status_cache(): void {
		delete_site_transient( self::AUTH_STATUS_TRANSIENT );
	}

	/**
	 * Handles AJAX request for fetching posts.
	 */
	public function ajax_fetch_posts(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability();

		$source_site_url = sanitize_text_field( wp_unslash( $_POST['source_site_url'] ?? '' ) );
		$number_of_posts = absint( $_POST['number_of_posts'] ?? 10 );
		$post_type       = sanitize_text_field( wp_unslash( $_POST['post_type'] ?? 'posts' ) );

		if ( empty( $source_site_url ) ) {
			wp_send_json_error( __( 'Source site URL is required.', 'safe-publish' ) );
		}

		$this->validate_auth_or_fail();

		$auth_credentials = Auth_Credential_Provider::get_credentials();

		$posts = $this->api->fetch_posts( $source_site_url, $number_of_posts, $auth_credentials, $post_type );

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
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability();

		$source_site_url = sanitize_text_field( wp_unslash( $_POST['source_site_url'] ?? '' ) );

		if ( empty( $source_site_url ) ) {
			wp_send_json_error( __( 'Source site URL is required.', 'safe-publish' ) );
		}

		$this->validate_auth_or_fail();

		$auth_credentials = Auth_Credential_Provider::get_credentials();

		$post_types = $this->post_type_fetcher->fetch_post_types( $source_site_url, $auth_credentials );

		if ( is_wp_error( $post_types ) ) {
			wp_send_json_error( $post_types->get_error_message() );
		}

		wp_send_json_success( $post_types );
	}

	/**
	 * Handles AJAX request for testing connection.
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability();

		$connected_site_url = sanitize_text_field( wp_unslash( $_POST['connected_site_url'] ?? '' ) );

		if ( empty( $connected_site_url ) ) {
			wp_send_json_error( __( 'Connected site URL is required.', 'safe-publish' ) );
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

		$results = $this->api->test_connection( $connected_site_url, $auth_credentials );

		wp_send_json_success( $results );
	}

	/**
	 * Handles AJAX request for the cached auth-status probe.
	 *
	 * Returns the cached probe result so the import and settings UIs can
	 * surface live auth state on page load without each one issuing its own
	 * network request.
	 */
	public function ajax_auth_status(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability();

		wp_send_json_success( $this->get_cached_auth_status() );
	}

	/**
	 * Returns the cached auth-status probe result, refreshing it if absent.
	 *
	 * @return array Probe result from VIP_Safe_Auth::test_authorization().
	 */
	private function get_cached_auth_status(): array {
		$cached = get_site_transient( self::AUTH_STATUS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['status'] ) ) {
			return $cached;
		}

		$result = VIP_Safe_Auth::test_authorization(
			get_option( Options::OPTION_CONNECTED_SITE_URL, '' ),
			Auth_Credential_Provider::get_credentials()
		);

		set_site_transient(
			self::AUTH_STATUS_TRANSIENT,
			$result,
			self::AUTH_STATUS_TTL
		);

		return $result;
	}

	/**
	 * Handles AJAX request for creating a draft post.
	 *
	 * Validates input, checks for an existing post with the same source post ID,
	 * returns a confirmation prompt when one exists (unless force_update is set),
	 * processes content, creates or updates the post, and logs history.
	 */
	public function ajax_create_draft(): void {
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

		$this->validate_auth_or_fail();

		$source_post_id = absint( $_POST['source_post_id'] ?? 0 );
		$title          = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$source_link    = esc_url_raw( wp_unslash( $_POST['source_link'] ?? '' ) );
		$raw_post_type  = sanitize_text_field( wp_unslash( $_POST['post_type'] ?? 'post' ) );

		$post_type = $this->post_import_service->resolve_post_type( $raw_post_type );

		if ( is_wp_error( $post_type ) ) {
			wp_send_json_error( $post_type->get_error_message() );
		}

		if ( empty( $title ) ) {
			wp_send_json_error( __( 'Post title is required.', 'safe-publish' ) );
		}

		if ( empty( $source_post_id ) ) {
			wp_send_json_error( __( 'Source post ID is required.', 'safe-publish' ) );
		}

		$imported_post = $this->post_import_service->find_imported_post( $source_post_id );
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
						__( 'Post "%s" already exists. Do you want to update it with the latest content from the source site?', 'safe-publish' ),
						$imported_post->post_title
					),
					'confirm_action' => 'update_existing',
				)
			);
		}

		// Create single import session for tracking.
		$source_site_url = get_option( Options::OPTION_CONNECTED_SITE_URL, '' );
		$session_result  = $this->repository->create_session( $source_site_url, 'single' );

		if ( is_wp_error( $session_result ) ) {
			wp_send_json_error( $session_result->get_error_message() );
		}

		$session_id = $session_result;

		// Fetch fresh content from the source site.
		$fresh_result = $this->api->fetch_fresh_post(
			$source_post_id,
			$raw_post_type
		);

		if ( is_wp_error( $fresh_result ) ) {
			$error_message = $fresh_result->get_error_message();

			$this->repository->log_import_action(
				$session_id,
				$source_post_id,
				$title,
				'error',
				null,
				$error_message,
				array( 'action' => 'fetch_failed' )
			);
			$this->repository->complete_session( $session_id );

			wp_send_json_error( $error_message );
		}

		$title             = $fresh_result['title'];
		$featured_media_id = $fresh_result['featured_media'];
		$slug              = $fresh_result['slug'];
		$comment_status    = $fresh_result['comment_status'];
		$ping_status       = $fresh_result['ping_status'];
		$menu_order        = $fresh_result['menu_order'];
		$password          = $fresh_result['password'];
		$source_author     = is_array( $fresh_result['source_author'] ?? null )
			? $fresh_result['source_author']
			: null;

		// Resolve the source author before any media or content processing so a
		// failed resolution does not leave orphan attachments behind.
		$matched_author_id = $this->post_import_service->resolve_source_author( $source_author );
		$warnings          = array();

		if ( is_wp_error( $matched_author_id ) ) {
			$fallback = $this->post_import_service->apply_author_fallback(
				$matched_author_id,
				$source_author,
				$imported_post ? (int) $imported_post->post_author : null
			);

			if ( is_wp_error( $fallback ) ) {
				$error_data    = $fallback->get_error_data();
				$error_action  = is_array( $error_data ) && isset( $error_data['action'] )
					? (string) $error_data['action']
					: $fallback->get_error_code();
				$error_message = $fallback->get_error_message();

				$this->repository->log_import_action(
					$session_id,
					$source_post_id,
					$title,
					'error',
					null,
					$error_message,
					array( 'action' => $error_action )
				);
				$this->repository->complete_session( $session_id );

				wp_send_json_error( $error_message );
			}

			$matched_author_id = $fallback['author_id'];
			$warnings[]        = $fallback['warning'];
		}

		// Resolve the source parent next so a strict failure aborts before
		// any media or content processing.
		$source_parent_id = absint( $fresh_result['parent'] ?? 0 );
		$post_parent_id   = 0;
		$resolved_parent  = $this->post_import_service->resolve_source_parent(
			$source_parent_id,
			$post_type
		);

		if ( $resolved_parent instanceof WP_Error ) {
			$fallback = $this->post_import_service->apply_parent_fallback(
				$resolved_parent
			);

			if ( is_wp_error( $fallback ) ) {
				$error_data    = $fallback->get_error_data();
				$error_action  = is_array( $error_data ) && isset( $error_data['action'] )
					? (string) $error_data['action']
					: $fallback->get_error_code();
				$error_message = $fallback->get_error_message();

				$this->repository->log_import_action(
					$session_id,
					$source_post_id,
					$title,
					'error',
					null,
					$error_message,
					array( 'action' => $error_action )
				);
				$this->repository->complete_session( $session_id );

				wp_send_json_error( $error_message );
			}

			$post_parent_id = $fallback['post_parent_id'];
			$warnings[]     = $fallback['warning'];
		} elseif ( null !== $resolved_parent ) {
			$post_parent_id = (int) $resolved_parent;
		}

		$excerpt = $this->sanitize_field(
			$fresh_result['excerpt'],
			self::FIELD_EXCERPT
		);

		if ( is_wp_error( $excerpt ) ) {
			$error_message = $excerpt->get_error_message();

			$this->repository->log_import_action(
				$session_id,
				$source_post_id,
				$title,
				'error',
				null,
				$error_message,
				array( 'action' => 'excerpt_sanitization_failed' )
			);
			$this->repository->complete_session( $session_id );

			wp_send_json_error( $error_message );
		}

		// Unsanitized values; sanitized downstream before being stored.
		$content = $fresh_result['content'] ?? '';
		$meta    = $fresh_result['meta'] ?? array();
		$terms   = $fresh_result['terms'] ?? array();

		$processed_content = $this->process_draft_content( $content, $source_link );

		if ( is_wp_error( $processed_content ) ) {
			$error_message = $processed_content->get_error_message();

			$this->repository->log_import_action(
				$session_id,
				$source_post_id,
				$title,
				'error',
				null,
				$error_message,
				array( 'action' => 'content_processing_failed' )
			);
			$this->repository->complete_session( $session_id );
			$this->content_processor->delete_newly_created_media();

			wp_send_json_error( $error_message );
		}

		$media_error = $this->get_media_processing_error();

		if ( null !== $media_error ) {
			$this->repository->log_import_action(
				$session_id,
				$source_post_id,
				$title,
				'error',
				null,
				$media_error['message'],
				array( 'action' => $media_error['action'] )
			);
			$this->repository->complete_session( $session_id );
			$this->content_processor->delete_newly_created_media();

			wp_send_json_error( $media_error['message'] );
		}

		if ( $imported_post ) {
			$result = $this->update_imported_draft(
				$imported_post,
				$title,
				$excerpt,
				$post_type,
				$processed_content,
				$source_link,
				$featured_media_id,
				$meta,
				$terms,
				$session_id,
				$source_post_id,
				$slug,
				$comment_status,
				$ping_status,
				$menu_order,
				$password,
				$matched_author_id,
				$source_author,
				$source_parent_id,
				$post_parent_id,
				$warnings
			);
		} else {
			$result = $this->create_new_draft(
				$title,
				$excerpt,
				$post_type,
				$processed_content,
				$source_link,
				$source_post_id,
				$featured_media_id,
				$meta,
				$terms,
				$session_id,
				$slug,
				$comment_status,
				$ping_status,
				$menu_order,
				$password,
				$matched_author_id,
				$source_author,
				$source_parent_id,
				$post_parent_id,
				$warnings
			);
		}

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( $result['error'] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handles AJAX request for bulk importing posts.
	 *
	 * Runs in two passes so parent-child relationships are preserved across a
	 * batch: pass 1 fetches each post's fresh REST payload without writing to
	 * the DB, and pass 2 processes the batch in topological order so a source
	 * parent is imported before its children.
	 */
	public function ajax_bulk_import(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability( 'edit_posts' );

		$this->validate_auth_or_fail();

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

		$source_site_url = get_option( Options::OPTION_CONNECTED_SITE_URL, '' );
		$session_result  = $this->repository->create_session( $source_site_url, 'bulk' );

		if ( is_wp_error( $session_result ) ) {
			wp_send_json_error( $session_result->get_error_message() );
		}

		$session_id = $session_result;

		// Pass 1: fetch each post's REST payload without touching the DB. The
		// payload is the same source of truth used by pass 2, so prefetched
		// posts skip the in-pipeline fetch when they're processed.
		$batch_fresh_data = array();
		$request_index    = array();
		foreach ( $posts_data as $index => $post_data ) {
			$source_post_id = absint( $post_data['id'] ?? 0 );
			if ( 0 === $source_post_id ) {
				continue;
			}

			$post_type = sanitize_text_field( $post_data['post_type'] ?? 'posts' );
			$fresh     = $this->api->fetch_fresh_post( $source_post_id, $post_type );
			if ( is_wp_error( $fresh ) ) {
				continue;
			}

			$batch_fresh_data[ $source_post_id ] = $fresh;
			$request_index[ $source_post_id ]    = $index;
		}

		// Topologically sort so each source parent is processed before its
		// children. Cycle leftovers fall through to the normal unresolvable-
		// parent error path.
		$parent_map = array();
		foreach ( $batch_fresh_data as $source_id => $fresh ) {
			$parent_map[ $source_id ] = absint( $fresh['parent'] ?? 0 );
		}

		$sort_result  = Topological_Sorter::sort( $parent_map );
		$sorted_order = array_merge( $sort_result['sorted'], $sort_result['leftover'] );
		$processed    = array();

		$results    = array();
		$successful = 0;
		$failed     = 0;

		// Pass 2: process in topological order, then append items whose pass-1
		// fetch failed (or was skipped) in request order — import_post() will
		// re-fetch them and surface the underlying failure.
		foreach ( $sorted_order as $source_id ) {
			$index     = $request_index[ $source_id ];
			$post_data = $posts_data[ $index ];
			$prefetch  = $batch_fresh_data[ $source_id ];

			$result    = $this->post_import_service->import_post(
				$post_data,
				$session_id,
				$prefetch,
				$batch_fresh_data
			);
			$results[] = $result;

			$processed[ $source_id ] = true;

			if ( $result['success'] ) {
				++$successful;
			} else {
				++$failed;
			}
		}

		foreach ( $posts_data as $post_data ) {
			$source_post_id = absint( $post_data['id'] ?? 0 );
			if ( $source_post_id > 0 && isset( $processed[ $source_post_id ] ) ) {
				continue;
			}

			$result    = $this->post_import_service->import_post(
				$post_data,
				$session_id,
				null,
				$batch_fresh_data
			);
			$results[] = $result;

			if ( $result['success'] ) {
				++$successful;
			} else {
				++$failed;
			}
		}

		$this->repository->complete_session( $session_id );

		wp_send_json_success(
			array(
				'total'      => count( $results ),
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
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability();

		$connected_site_url = sanitize_text_field( wp_unslash( $_POST['connected_site_url'] ?? '' ) );

		if ( empty( $connected_site_url ) ) {
			wp_send_json_error( __( 'Connected site URL is required.', 'safe-publish' ) );
		}

		$auth_credentials = Auth_Credential_Provider::get_credentials();

		$api_url = trailingslashit( $connected_site_url ) . 'wp-json/wp/v2/types';

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
			'connected_site_url'         => $connected_site_url,
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
	 * Moves the post to trash by its source post ID.
	 */
	public function ajax_delete_post(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability( 'delete_posts' );

		$source_post_id = absint( $_POST['source_post_id'] ?? 0 );

		if ( ! $source_post_id ) {
			wp_send_json_error( __( 'Source post ID is required.', 'safe-publish' ) );
		}

		$imported_post = $this->post_import_service->find_imported_post( $source_post_id );

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
	 * Delegates the write to Post_Import_Service::persist_updated_post().
	 * Intentional differences from the bulk-import update path:
	 * - Resets post_status to 'draft' (keeps the single-import review flow
	 *   intact).
	 * - Captures previous content for the session rollback history log.
	 *
	 * @param WP_Post $imported_post     Imported WordPress post.
	 * @param string  $title             Post title.
	 * @param string  $excerpt           Post excerpt.
	 * @param string  $post_type         Resolved post type slug.
	 * @param string  $processed_content Processed post content.
	 * @param string  $source_link       Source post URL.
	 * @param int     $featured_media_id Source featured media ID.
	 * @param mixed   $meta              Meta data (array or object).
	 * @param mixed   $terms             Terms data (array or object).
	 * @param int     $session_id        Import session ID.
	 * @param int     $source_post_id    Source post ID.
	 * @param string  $slug              Post slug.
	 * @param string  $comment_status    Comment status ('open' or 'closed').
	 * @param string  $ping_status       Ping status ('open' or 'closed').
	 * @param int     $menu_order        Menu order.
	 * @param string  $password          Post password.
	 * @param int     $matched_author_id Destination user ID to assign as post_author.
	 * @param array   $source_author     Source author payload (email, login, display_name).
	 * @param int     $source_parent_id  Source post's parent ID for diagnostic meta.
	 * @param int     $post_parent_id    Resolved destination post_parent (0 when none).
	 * @param array   $warnings          Non-fatal warnings raised during import.
	 * @return array Result data with post_id, edit_url, message, existing, and
	 *               warnings keys, or error key on failure.
	 */
	private function update_imported_draft(
		WP_Post $imported_post,
		string $title,
		string $excerpt,
		string $post_type,
		string $processed_content,
		string $source_link,
		int $featured_media_id,
		mixed $meta,
		mixed $terms,
		int $session_id,
		int $source_post_id,
		string $slug,
		string $comment_status,
		string $ping_status,
		int $menu_order,
		string $password,
		int $matched_author_id,
		array $source_author,
		int $source_parent_id,
		int $post_parent_id,
		array $warnings
	): array {
		$previous_content = $this->capture_previous_content( $imported_post );

		// Sideload the featured image before writing the post so that a
		// failure here does not leave the post in a partially-updated state.
		$featured_attachment_id = $this->post_import_service->import_featured_image_attachment(
			$featured_media_id,
			$source_link
		);

		if ( false === $featured_attachment_id ) {
			return $this->log_single_error_and_return(
				$session_id,
				$source_post_id,
				$title,
				$imported_post->ID,
				__( 'Failed to import featured image.', 'safe-publish' ),
				'featured_image_import_failed'
			);
		}

		// Single-import: force draft status for the review flow.
		$post_id = $this->post_import_service->persist_updated_post(
			array(
				'ID'             => $imported_post->ID,
				'post_title'     => $title,
				'post_excerpt'   => $excerpt,
				'post_content'   => $processed_content,
				'post_status'    => 'draft',
				'post_type'      => $post_type,
				'post_name'      => $slug,
				'post_parent'    => $post_parent_id,
				'comment_status' => $comment_status,
				'ping_status'    => $ping_status,
				'menu_order'     => $menu_order,
				'post_password'  => $password,
				'post_author'    => $matched_author_id,
			),
			$featured_attachment_id,
			$source_link,
			$meta,
			$terms,
			$source_author,
			$source_parent_id
		);

		if ( is_wp_error( $post_id ) ) {
			$error_data = $post_id->get_error_data();
			$action     = is_array( $error_data ) && isset( $error_data['action'] )
				? $error_data['action']
				: 'post_update_failed';

			return $this->log_single_error_and_return(
				$session_id,
				$source_post_id,
				$title,
				$imported_post->ID,
				$post_id->get_error_message(),
				$action
			);
		}

		$this->repository->log_import_action(
			$session_id,
			$source_post_id,
			$title,
			'updated',
			$post_id,
			null,
			$previous_content,
			$warnings
		);
		$this->repository->complete_session( $session_id );

		return array(
			'post_id'  => $post_id,
			'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'message'  => __( 'Existing draft updated with latest content.', 'safe-publish' ),
			'existing' => true,
			'warnings' => $warnings,
		);
	}

	/**
	 * Creates a new draft post with the imported content.
	 *
	 * Delegates the write to Post_Import_Service::persist_new_post().
	 *
	 * @param string $title             Post title.
	 * @param string $excerpt           Post excerpt.
	 * @param string $post_type         Resolved post type slug.
	 * @param string $processed_content Processed post content.
	 * @param string $source_link       Source post URL.
	 * @param int    $source_post_id    Source post ID.
	 * @param int    $featured_media_id Source featured media ID.
	 * @param mixed  $meta              Meta data (array or object).
	 * @param mixed  $terms             Terms data (array or object).
	 * @param int    $session_id        Import session ID.
	 * @param string $slug              Post slug.
	 * @param string $comment_status    Comment status ('open' or 'closed').
	 * @param string $ping_status       Ping status ('open' or 'closed').
	 * @param int    $menu_order        Menu order.
	 * @param string $password          Post password.
	 * @param int    $matched_author_id Destination user ID to assign as post_author.
	 * @param array  $source_author     Source author payload (email, login, display_name).
	 * @param int    $source_parent_id  Source post's parent ID for diagnostic meta.
	 * @param int    $post_parent_id    Resolved destination post_parent (0 when none).
	 * @param array  $warnings          Non-fatal warnings raised during import.
	 * @return array Result data with post_id, edit_url, message, existing, and
	 *               warnings keys, or error key on failure.
	 */
	private function create_new_draft(
		string $title,
		string $excerpt,
		string $post_type,
		string $processed_content,
		string $source_link,
		int $source_post_id,
		int $featured_media_id,
		mixed $meta,
		mixed $terms,
		int $session_id,
		string $slug,
		string $comment_status,
		string $ping_status,
		int $menu_order,
		string $password,
		int $matched_author_id,
		array $source_author,
		int $source_parent_id,
		int $post_parent_id,
		array $warnings
	): array {
		// Sideload the featured image before creating the post so that a
		// failure here does not leave an orphaned draft in the DB.
		$featured_attachment_id = $this->post_import_service->import_featured_image_attachment(
			$featured_media_id,
			$source_link
		);

		if ( false === $featured_attachment_id ) {
			return $this->log_single_error_and_return(
				$session_id,
				$source_post_id,
				$title,
				null,
				__( 'Failed to import featured image.', 'safe-publish' ),
				'featured_image_import_failed'
			);
		}

		$post_id = $this->post_import_service->persist_new_post(
			array(
				'post_title'     => $title,
				'post_content'   => $processed_content,
				'post_status'    => 'draft',
				'post_type'      => $post_type,
				'post_excerpt'   => $excerpt,
				'post_name'      => $slug,
				'post_parent'    => $post_parent_id,
				'comment_status' => $comment_status,
				'ping_status'    => $ping_status,
				'menu_order'     => $menu_order,
				'post_password'  => $password,
				'post_author'    => $matched_author_id,
				'meta_input'     => array(
					Options::META_SOURCE_POST_ID  => $source_post_id,
					Options::META_SOURCE_LINK     => $source_link,
					Options::META_IMPORTED_FROM   => Options::META_IMPORTED_FROM_VALUE,
					Options::META_IMPORT_DATE_GMT => current_time( 'mysql', true ),
				),
			),
			$featured_attachment_id,
			$meta,
			$terms,
			$source_author,
			$source_parent_id
		);

		if ( is_wp_error( $post_id ) ) {
			$error_data = $post_id->get_error_data();
			$action     = is_array( $error_data ) && isset( $error_data['action'] )
				? $error_data['action']
				: 'post_create_failed';

			return $this->log_single_error_and_return(
				$session_id,
				$source_post_id,
				$title,
				null,
				$post_id->get_error_message(),
				$action
			);
		}

		$this->repository->log_import_action(
			$session_id,
			$source_post_id,
			$title,
			'success',
			$post_id,
			null,
			array( 'action' => 'created_new_post' ),
			$warnings
		);
		$this->repository->complete_session( $session_id );

		return array(
			'post_id'  => $post_id,
			'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'message'  => __( 'Draft post created successfully.', 'safe-publish' ),
			'existing' => false,
			'warnings' => $warnings,
		);
	}

	/**
	 * Logs a single-import error to history, finalizes the session, and returns
	 * the standard error array.
	 *
	 * @param int      $session_id       Import session ID.
	 * @param int      $source_post_id   Source post ID.
	 * @param string   $title            Post title.
	 * @param int|null $post_id          WordPress post ID or null.
	 * @param string   $error_message    Error description.
	 * @param string   $action           Log action identifier.
	 * @return array Error result with 'error' key.
	 */
	private function log_single_error_and_return(
		int $session_id,
		int $source_post_id,
		string $title,
		?int $post_id,
		string $error_message,
		string $action
	): array {
		$this->repository->log_import_action(
			$session_id,
			$source_post_id,
			$title,
			'error',
			$post_id,
			$error_message,
			array( 'action' => $action )
		);
		$this->repository->complete_session( $session_id );

		return array( 'error' => $error_message );
	}

	/**
	 * Sends a JSON error response when the Shared Secret does not satisfy
	 * VIP_Safe_Auth::has_valid_credential_format(). Splits the failure into
	 * "missing" and "too short" so the operator gets an actionable message.
	 */
	private function validate_auth_or_fail(): void {
		$credentials = Auth_Credential_Provider::get_credentials();

		if ( VIP_Safe_Auth::has_valid_credential_format( $credentials ) ) {
			return;
		}

		if ( '' === ( $credentials['shared_secret'] ?? '' ) ) {
			wp_send_json_error(
				__(
					'Shared Secret is not configured. Add SAFE_PUBLISH_SHARED_SECRET to wp-config.php on both sites.',
					'safe-publish'
				),
				401
			);
		} else {
			wp_send_json_error(
				__(
					'Shared Secret is too short. SAFE_PUBLISH_SHARED_SECRET must be at least 16 characters.',
					'safe-publish'
				),
				401
			);
		}
	}

	/**
	 * Returns combined media processing error info, or null when no failures
	 * occurred.
	 *
	 * @return array{message: string, action: string}|null
	 */
	private function get_media_processing_error(): ?array {
		$download_msg = $this->content_processor
			->get_failed_media_error_message();
		$markup_msg   = $this->content_processor
			->get_unprocessable_media_error_message();

		if ( null === $download_msg && null === $markup_msg ) {
			return null;
		}

		$messages = array_filter( array( $download_msg, $markup_msg ) );

		$action = null !== $download_msg
			? 'media_download_failed'
			: 'malformed_media_markup';

		return array(
			'message' => implode( ' ', $messages ),
			'action'  => $action,
		);
	}

	/**
	 * Processes draft post content by importing media and fixing links.
	 *
	 * @param string $content     Raw post content.
	 * @param string $source_link Source post URL used to derive site URL.
	 * @return string|WP_Error Processed content, or WP_Error on failure.
	 */
	private function process_draft_content( string $content, string $source_link ): string|WP_Error {
		$processed = $content;

		if ( ! empty( $content ) && ! empty( $source_link ) ) {
			$source_site_url = wp_parse_url( $source_link, PHP_URL_SCHEME )
				. '://' . wp_parse_url( $source_link, PHP_URL_HOST );
			$processed       = $this->content_processor->process_content( $content, $source_site_url );

			if ( is_wp_error( $processed ) ) {
				return $processed;
			}
		}

		return $this->sanitize_field( $processed, self::FIELD_CONTENT );
	}

	/**
	 * Captures previous post content for the session rollback history log.
	 *
	 * Stores the current post fields, featured image, and selected meta so the
	 * import can be reverted via session rollback.
	 *
	 * @param WP_Post $existing_post Existing WordPress post.
	 * @return array Previous content keyed by field name.
	 */
	private function capture_previous_content( WP_Post $existing_post ): array {
		$previous_content = array(
			'previous_content'        => $existing_post->post_content,
			'previous_title'          => $existing_post->post_title,
			'previous_excerpt'        => $existing_post->post_excerpt,
			'previous_slug'           => $existing_post->post_name,
			'previous_comment_status' => $existing_post->comment_status,
			'previous_ping_status'    => $existing_post->ping_status,
			'previous_menu_order'     => $existing_post->menu_order,
			'previous_password'       => $existing_post->post_password,
			'previous_featured_image' => get_post_thumbnail_id( $existing_post->ID ),
			'previous_meta'           => array(),
			'action'                  => 'updated_existing',
		);

		$meta_keys_to_preserve = array(
			'_edit_last',
			'_edit_lock',
			Options::META_SOURCE_LINK,
			Options::META_IMPORT_DATE_GMT,
		);

		foreach ( $meta_keys_to_preserve as $meta_key ) {
			$meta_value = get_post_meta( $existing_post->ID, $meta_key, true );
			if ( '' !== $meta_value ) {
				$previous_content['previous_meta'][ $meta_key ] = $meta_value;
			}
		}

		return $previous_content;
	}
}
