<?php
/**
 * Import History class for tracking import sessions and rollbacks
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Event_Table;
use WP_Error;
use WP_Query;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import History Class.
 *
 * Coordinates import history functionality and delegates data operations to the
 * repository.
 */
final class Import_History {

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * History renderer instance.
	 *
	 * @var History_Renderer
	 */
	private History_Renderer $renderer;

	/**
	 * Session formatter instance.
	 *
	 * @var Session_Formatter
	 */
	private Session_Formatter $formatter;

	/**
	 * Rollback service instance.
	 *
	 * @var Session_Rollback_Service
	 */
	private Session_Rollback_Service $rollback_service;

	/**
	 * Constructor.
	 *
	 * @param History_Repository       $repository       History repository instance.
	 * @param History_Renderer         $renderer         History renderer instance.
	 * @param Session_Formatter        $formatter        Session formatter instance.
	 * @param Session_Rollback_Service $rollback_service Rollback service instance.
	 */
	public function __construct(
		History_Repository $repository,
		History_Renderer $renderer,
		Session_Formatter $formatter,
		Session_Rollback_Service $rollback_service
	) {
		$this->repository       = $repository;
		$this->renderer         = $renderer;
		$this->formatter        = $formatter;
		$this->rollback_service = $rollback_service;
	}

	/**
	 * Initializes the import history functionality.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
		add_action( 'wp_ajax_safe_publish_get_import_sessions', array( $this, 'ajax_get_import_sessions' ) );
		add_action( 'wp_ajax_safe_publish_get_session_details', array( $this, 'ajax_get_session_details' ) );
		add_action( 'wp_ajax_safe_publish_rollback_session', array( $this, 'ajax_rollback_session' ) );
		add_action( 'wp_ajax_safe_publish_rollback_item', array( $this, 'ajax_rollback_item' ) );
		add_action( 'wp_ajax_safe_publish_get_post_diff', array( $this, 'ajax_get_post_diff' ) );
		add_action( 'wp_ajax_safe_publish_delete_session', array( $this, 'ajax_delete_session' ) );
		add_action( 'wp_ajax_safe_publish_get_export_events', array( $this, 'ajax_get_export_events' ) );
	}

	/**
	 * Initializes history for export-only mode.
	 *
	 * Registers only the History submenu page (under the settings parent) and
	 * the export events AJAX handler. Import-specific functionality is omitted
	 * because export-only sites do not import content.
	 */
	public function init_export_only(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu_page_settings' ) );
		add_action( 'wp_ajax_safe_publish_get_export_events', array( $this, 'ajax_get_export_events' ) );
	}

	/**
	 * Adds the History submenu page under the settings-only top-level menu.
	 *
	 * Used in export-only mode where the top-level slug is 'safe-publish-settings'.
	 */
	public function add_submenu_page_settings(): void {
		add_submenu_page(
			'safe-publish-settings',
			__( 'History', 'safe-publish' ),
			__( 'History', 'safe-publish' ),
			'manage_options',
			'safe-publish-history',
			array( $this, 'render_history_page' )
		);
	}

	/**
	 * Registers custom post types for import tracking.
	 */
	public function register_post_types(): void {
		// Register import session post type.
		register_post_type(
			History_Repository::SESSION_POST_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'Import Sessions', 'safe-publish' ),
					'singular_name' => __( 'Import Session', 'safe-publish' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'custom-fields' ),
				'show_in_rest'       => false,
			)
		);

		// Register import log post type.
		register_post_type(
			History_Repository::LOG_POST_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'Import Logs', 'safe-publish' ),
					'singular_name' => __( 'Import Log', 'safe-publish' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'content', 'custom-fields' ),
				'show_in_rest'       => false,
			)
		);
	}

	/**
	 * Adds submenu page for import history.
	 */
	public function add_submenu_page(): void {
		add_submenu_page(
			'safe-publish',
			__( 'Safe Publish History', 'safe-publish' ),
			__( 'History', 'safe-publish' ),
			'manage_options',
			'safe-publish-history',
			array( $this, 'render_history_page' )
		);
	}

	/**
	 * Renders the import history page.
	 */
	public function render_history_page(): void {
		$this->renderer->render_history_page();
	}

	/**
	 * Creates a new import session.
	 *
	 * @param string $source_url   Source site URL.
	 * @param string $session_type Type of import (single, bulk).
	 * @return int|WP_Error Session ID or error.
	 */
	public function create_session(
		string $source_url,
		string $session_type = 'bulk'
	): int|WP_Error {
		return $this->repository->create_session( $source_url, $session_type );
	}

	/**
	 * Logs an import action.
	 *
	 * @param int    $session_id  Session ID.
	 * @param int    $external_id External post ID.
	 * @param string $title       Post title.
	 * @param string $status      Import status (success, error, updated).
	 * @param int    $post_id     WordPress post ID (if successful).
	 * @param string $error       Error message (if failed).
	 * @param array  $changes     Changes made during import.
	 * @return int|WP_Error Log ID or error.
	 */
	public function log_import_action(
		int $session_id,
		int $external_id,
		string $title,
		string $status,
		?int $post_id = null,
		?string $error = null,
		array $changes = array()
	): int|WP_Error {
		return $this->repository->log_import_action(
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
	 * Updates session stats.
	 *
	 * @param int    $session_id Session ID.
	 * @param string $status     Status of the import (success, error, updated).
	 */
	public function update_session_stats( int $session_id, string $status ): void {
		$this->repository->update_session_stats( $session_id, $status );
	}

	/**
	 * Completes a session.
	 *
	 * @param int $session_id Session ID.
	 */
	public function complete_session( int $session_id ): void {
		$this->repository->complete_session( $session_id );
	}

	/**
	 * Stores content diff for rollback purposes.
	 *
	 * @param int    $post_id     WordPress post ID.
	 * @param string $old_content Previous content.
	 * @param string $new_content New content.
	 */
	public function store_content_diff(
		int $post_id,
		string $old_content,
		string $new_content
	): void {
		$this->repository->store_content_diff( $post_id, $old_content, $new_content );
	}

	/**
	 * Handles AJAX request for getting import sessions.
	 */
	public function ajax_get_import_sessions(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'safe-publish' ) );
		}

		$sessions           = $this->repository->get_sessions( 50 );
		$formatted_sessions = $this->formatter->format_sessions( $sessions );

		wp_send_json_success( $formatted_sessions );
	}

	/**
	 * Handles AJAX request for getting session details.
	 */
	public function ajax_get_session_details(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'safe-publish' ) );
		}

		$session_id = absint( $_POST['session_id'] ?? 0 );

		if ( ! $session_id ) {
			wp_send_json_error( __( 'Invalid session ID', 'safe-publish' ) );
		}

		$session = $this->repository->get_session( $session_id );

		if ( ! $session ) {
			wp_send_json_error( __( 'Session not found', 'safe-publish' ) );
		}

		$status         = get_post_meta( $session_id, 'status', true );
		$session_data   = $this->formatter->format_session( $session );
		$logs           = $this->repository->get_session_logs( $session_id );
		$formatted_logs = $this->formatter->format_logs( $logs, $status );

		wp_send_json_success(
			array(
				'session' => $session_data,
				'logs'    => $formatted_logs,
			)
		);
	}

	/**
	 * Handles AJAX request for rolling back a session.
	 */
	public function ajax_rollback_session(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'safe-publish' ) );
		}

		$session_id = absint( $_POST['session_id'] ?? 0 );

		if ( ! $session_id ) {
			wp_send_json_error( __( 'Invalid session ID', 'safe-publish' ) );
		}

		$result = $this->rollback_service->rollback_session( $session_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'deleted_count'  => $result['deleted_count'],
				'restored_count' => $result['restored_count'],
				'message'        => sprintf(
					/* translators: 1: number of posts deleted, 2: number of posts restored */
					__( '%1$d posts deleted and %2$d posts restored successfully.', 'safe-publish' ),
					$result['deleted_count'],
					$result['restored_count']
				),
			)
		);
	}

	/**
	 * Handles AJAX request for rolling back a single item.
	 */
	public function ajax_rollback_item(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'safe-publish' ) );
		}

		$log_id = absint( $_POST['log_id'] ?? 0 );

		if ( ! $log_id ) {
			wp_send_json_error( __( 'Invalid log ID', 'safe-publish' ) );
		}

		$result = $this->rollback_service->rollback_item( $log_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$messages = array(
			'deleted'  => __( 'Post successfully deleted', 'safe-publish' ),
			'restored' => __( 'Post successfully restored to previous version', 'safe-publish' ),
		);

		$result['message'] = $messages[ $result['action'] ] ?? $result['action'];

		wp_send_json_success( $result );
	}

	/**
	 * Handles AJAX request for getting post diff.
	 */
	public function ajax_get_post_diff(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'safe-publish' ) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID', 'safe-publish' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			wp_send_json_error( __( 'Post not found', 'safe-publish' ) );
		}

		// Find the import log entry for this post to get the previous content.
		$log_query = new WP_Query(
			array(
				'post_type'      => History_Repository::LOG_POST_TYPE,
				'post_status'    => 'publish',
				'meta_key'       => 'post_id',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'     => $post_id,
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! $log_query->have_posts() ) {
			wp_send_json_error( __( 'No import history found for this post', 'safe-publish' ) );
		}

		$log_post = $log_query->posts[0];
		$changes  = get_post_meta( $log_post->ID, 'changes', true );

		// For backward compatibility, handle cases where changes might not have complete data.
		if ( empty( $changes ) || ! is_array( $changes ) ) {
			// Fallback: show current content vs empty for legacy logs.
			$changes = array();
		}

		// Get previous content from changes array (fallback to empty if not available).
		$old_content = $changes['previous_content'] ?? '';
		$old_title   = $changes['previous_title'] ?? '';
		$old_excerpt = $changes['previous_excerpt'] ?? '';

		// Current content.
		$new_content = $post->post_content;
		$new_title   = $post->post_title;
		$new_excerpt = $post->post_excerpt;

		// If no previous content available, show a message instead of empty diff.
		if ( empty( $old_content ) && empty( $old_title ) && empty( $old_excerpt ) ) {
			$diff_html = $this->renderer->generate_no_diff_message(
				$new_title,
				$new_excerpt,
				$new_content
			);
		} else {
			// Generate diff HTML for content, title, and excerpt.
			$diff_html = $this->renderer->generate_comprehensive_diff_html(
				$old_title,
				$new_title,
				$old_excerpt,
				$new_excerpt,
				$old_content,
				$new_content
			);
		}

		wp_send_json_success(
			array(
				'diff_html' => $diff_html,
			)
		);
	}

	/**
	 * Handles AJAX request for deleting a session.
	 */
	public function ajax_delete_session(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'safe-publish' ) );
		}

		$session_id = absint( $_POST['session_id'] ?? 0 );

		if ( ! $session_id ) {
			wp_send_json_error( __( 'Invalid session ID', 'safe-publish' ) );
		}

		$session = $this->repository->get_session( $session_id );

		if ( ! $session ) {
			wp_send_json_error( __( 'Session not found', 'safe-publish' ) );
		}

		// Delete the session and all its logs.
		$success = $this->repository->delete_session( $session_id );

		if ( $success ) {
			wp_send_json_success(
				array(
					'message' => __( 'Session and all associated log entries deleted successfully.', 'safe-publish' ),
				)
			);
		} else {
			wp_send_json_error( __( 'Failed to delete session', 'safe-publish' ) );
		}
	}

	/**
	 * Handles AJAX request for getting export events.
	 */
	public function ajax_get_export_events(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'safe-publish' ) );
		}

		$rows = Event_Table::get_events(
			array(
				'channel' => 'export',
				'limit'   => 100,
			)
		);

		$events = array_map(
			static function ( array $row ): array {
				$data = $row['data'];
				return array(
					'id'              => (int) $row['id'],
					'date'            => $row['created_at'],
					'level'           => $row['level'],
					'event'           => $row['event'],
					'destination_url' => $data['destination_url'] ?? '',
					'post_ids'        => array_map( 'intval', (array) ( $data['post_ids'] ?? array() ) ),
					'post_count'      => isset( $data['post_count'] ) ? (int) $data['post_count'] : count( $data['post_ids'] ?? array() ),
				);
			},
			$rows
		);

		wp_send_json_success( $events );
	}
}
