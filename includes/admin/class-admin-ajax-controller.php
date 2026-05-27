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
use Safe_Publish\API\Request_Actions;
use Safe_Publish\Auth\VIP_Safe_Auth;
use Safe_Publish\Utils\Auth_Credential_Provider;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Topological_Sorter;
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
	 * @param Post_Import_Service $post_import_service Post Import Service instance.
	 * @param Post_Type_Fetcher   $post_type_fetcher   Post Type Fetcher instance.
	 * @param HTTP_Client         $http_client         HTTP Client instance.
	 */
	public function __construct(
		Source_Posts_API $api,
		History_Repository $repository,
		Post_Import_Service $post_import_service,
		Post_Type_Fetcher $post_type_fetcher,
		HTTP_Client $http_client
	) {
		$this->api                 = $api;
		$this->repository          = $repository;
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
	 * Validates input, checks for an existing post with the same source ID,
	 * returns a confirmation prompt when one exists (unless force_update is set),
	 * processes content, creates or updates the post, and logs history.
	 */
	public function ajax_create_draft(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability( 'edit_posts' );

		$this->validate_auth_or_fail();

		$source_post_id = absint( $_POST['source_post_id'] ?? 0 );
		$title          = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$raw_post_type  = sanitize_text_field( wp_unslash( $_POST['post_type'] ?? 'post' ) );
		$force_update   = isset( $_POST['force_update'] ) && 'true' === $_POST['force_update'];

		// Validate basic input before any session or duplicate-detection work so
		// that malformed requests do not leave history rows behind and cannot
		// reach the confirm-prompt branch by way of an existing post lookup.
		// Post_Import_Service::validate_required_fields() and resolve_post_type()
		// repeat these checks as defense-in-depth and to cover the bulk-import
		// code path.
		if ( 0 === $source_post_id ) {
			wp_send_json_error( __( 'Source post ID is required.', 'safe-publish' ) );
		}

		if ( '' === $title ) {
			wp_send_json_error( __( 'Post title is required.', 'safe-publish' ) );
		}

		$post_type = $this->post_import_service->resolve_post_type( $raw_post_type );

		if ( is_wp_error( $post_type ) ) {
			wp_send_json_error( $post_type->get_error_message() );
		}

		// Force-update confirmation prompt is HTTP UX, not import logic: if the
		// post is already imported and the caller hasn't opted into updating,
		// return the prompt response instead of running the import.
		$imported_post = $this->post_import_service->find_imported_post( $source_post_id );

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

		// Session is created only after the request is eligible to proceed —
		// past basic validation and past the confirm-prompt short-circuit — so
		// that rejected requests do not leave rows in the history table.
		$source_site_url = get_option( Options::OPTION_CONNECTED_SITE_URL, '' );
		$session_result  = $this->repository->create_session( $source_site_url, 'single' );

		if ( is_wp_error( $session_result ) ) {
			wp_send_json_error( $session_result->get_error_message() );
		}

		$session_id = $session_result;

		$post_data = array(
			'id'             => $source_post_id,
			'title'          => $title,
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by Post_Import_Service::extract_post_fields().
			'link'           => wp_unslash( $_POST['source_link'] ?? '' ),
			'post_type'      => $raw_post_type,
			'featured_media' => absint( $_POST['featured_media_id'] ?? 0 ),
		);

		// JSON string not sanitized to preserve structure; validated after decode.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$meta_param = isset( $_POST['meta'] ) ? wp_unslash( $_POST['meta'] ) : '';
		if ( is_string( $meta_param ) && '' !== $meta_param ) {
			$decoded_meta = json_decode( $meta_param, true );
			if ( is_array( $decoded_meta ) ) {
				$post_data['meta'] = $decoded_meta;
			}
		}

		// JSON string not sanitized to preserve structure; validated after decode.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$terms_param = isset( $_POST['terms'] ) ? wp_unslash( $_POST['terms'] ) : '';
		if ( is_string( $terms_param ) && '' !== $terms_param ) {
			$decoded_terms = json_decode( $terms_param, true );
			if ( is_array( $decoded_terms ) ) {
				$post_data['terms'] = $decoded_terms;
			}
		}

		$result = $this->post_import_service->import_post(
			$post_data,
			$session_id,
			array( 'force_draft_on_update' => true )
		);

		$this->repository->complete_session( $session_id );

		if ( ! $result['success'] ) {
			wp_send_json_error( $result['error'] );
		}

		$result['message'] = $result['existing']
			? __( 'Existing draft updated with latest content.', 'safe-publish' )
			: __( 'Draft post created successfully.', 'safe-publish' );

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
				array(),
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
				array(),
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
			Request_Actions::PROBE,
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
			$response = $this->http_client->make_request(
				$api_url,
				Request_Actions::PROBE,
				$auth_credentials
			);

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
}
