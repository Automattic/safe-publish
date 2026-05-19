<?php
/**
 * Import History class for admin UI coordination and rollback
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Audit_Log_Table;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import History Class.
 *
 * Coordinates the import history admin UI: menu pages, AJAX endpoints,
 * rendering, and session rollback.
 */
final class Import_History {

	use Verifies_Ajax_Request;

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
	 * Handles AJAX request for getting import sessions.
	 */
	public function ajax_get_import_sessions(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability();

		$sessions           = $this->repository->get_sessions( 50 );
		$formatted_sessions = $this->formatter->format_sessions( $sessions );

		wp_send_json_success( $formatted_sessions );
	}

	/**
	 * Handles AJAX request for getting session details.
	 */
	public function ajax_get_session_details(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability();

		$session_id = absint( $_POST['session_id'] ?? 0 );

		if ( ! $session_id ) {
			wp_send_json_error( __( 'Invalid session ID', 'safe-publish' ) );
		}

		$session = $this->repository->get_session( $session_id );

		if ( ! $session ) {
			wp_send_json_error( __( 'Session not found', 'safe-publish' ) );
		}

		$session_data    = $this->formatter->format_session( $session );
		$items           = $this->repository->get_session_items( $session_id );
		$formatted_items = $this->formatter->format_items( $items );

		wp_send_json_success(
			array(
				'session' => $session_data,
				'items'   => $formatted_items,
			)
		);
	}

	/**
	 * Handles AJAX request for rolling back a session.
	 */
	public function ajax_rollback_session(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability();

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
				'failed_count'   => $result['failed_count'],
			)
		);
	}

	/**
	 * Handles AJAX request for rolling back a single item.
	 */
	public function ajax_rollback_item(): void {
		check_ajax_referer( 'safe_publish_ajax_nonce', 'nonce' );
		$this->verify_ajax_capability();

		$item_id = absint( $_POST['item_id'] ?? 0 );

		if ( ! $item_id ) {
			wp_send_json_error( __( 'Invalid item ID', 'safe-publish' ) );
		}

		$result = $this->rollback_service->rollback_item( $item_id );

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
		$this->verify_ajax_capability();

		$post_id = absint( $_POST['post_id'] ?? 0 );

		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID', 'safe-publish' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			wp_send_json_error( __( 'Post not found', 'safe-publish' ) );
		}

		$item = $this->repository->get_item_for_post( $post_id );

		if ( null === $item ) {
			wp_send_json_error( __( 'No import history found for this post', 'safe-publish' ) );
		}

		$changes = History_Repository::decode_item_changes( $item['content_changes'] ?? null ) ?? array();

		$old_content = (string) ( $changes['previous_content'] ?? '' );
		$old_title   = (string) ( $changes['previous_title'] ?? '' );
		$old_excerpt = (string) ( $changes['previous_excerpt'] ?? '' );

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
		$this->verify_ajax_capability();

		$session_id = absint( $_POST['session_id'] ?? 0 );

		if ( ! $session_id ) {
			wp_send_json_error( __( 'Invalid session ID', 'safe-publish' ) );
		}

		$session = $this->repository->get_session( $session_id );

		if ( ! $session ) {
			wp_send_json_error( __( 'Session not found', 'safe-publish' ) );
		}

		// Delete the session and all its items.
		$success = $this->repository->delete_session( $session_id );

		if ( $success ) {
			wp_send_json_success(
				array(
					'message' => __( 'Session and all associated items deleted successfully.', 'safe-publish' ),
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
		$this->verify_ajax_capability();

		$rows = Audit_Log_Table::get_events(
			array(
				'channel' => 'export',
				'limit'   => 100,
			)
		);

		$events = array_map(
			static function ( array $row ): array {
				$data    = $row['data'];
				$created = (string) $row['created_at_gmt'];

				return array(
					'id'                   => (int) $row['id'],
					'date'                 => str_replace( ' ', 'T', $created ) . 'Z',
					'level'                => $row['level'],
					'event'                => $row['event'],
					'destination_site_url' => $data['destination_site_url'] ?? '',
					'post_ids'             => array_map( 'intval', (array) ( $data['post_ids'] ?? array() ) ),
					'post_count'           => isset( $data['post_count'] ) ? (int) $data['post_count'] : count( $data['post_ids'] ?? array() ),
				);
			},
			$rows
		);

		wp_send_json_success( $events );
	}
}
