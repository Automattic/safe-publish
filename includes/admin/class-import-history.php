<?php
/**
 * Import History class for tracking import sessions and rollbacks
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use WP_Error;
use WP_Query;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import History Class.
 *
 * Coordinates import history functionality and delegates data operations to the repository.
 */
final class Import_History {

	/**
	 * Custom post type for import sessions.
	 */
	const SESSION_POST_TYPE = 'safe_publish_import_session';

	/**
	 * Custom post type for import logs.
	 */
	const LOG_POST_TYPE = 'safe_publish_import_log';

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param History_Repository $repository History repository instance.
	 */
	public function __construct( History_Repository $repository ) {
		$this->repository = $repository;
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
	}

	/**
	 * Registers custom post types for import tracking.
	 */
	public function register_post_types(): void {
		// Register import session post type.
		register_post_type(
			self::SESSION_POST_TYPE,
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
			self::LOG_POST_TYPE,
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
			__( 'Import History', 'safe-publish' ),
			__( 'Import History', 'safe-publish' ),
			'manage_options',
			'safe-publish-import-history',
			array( $this, 'render_history_page' )
		);
	}

	/**
	 * Renders the import history page.
	 */
	public function render_history_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'safe-publish' ) );
		}

		// Enqueue necessary scripts and styles for React.
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );
		wp_enqueue_script( 'wp-i18n' );

		// Try to enqueue wp-dataviews if available.
		if ( wp_script_is( 'wp-dataviews', 'registered' ) ) {
			wp_enqueue_script( 'wp-dataviews' );
		}

		// Enqueue custom CSS.
		$css_file = plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/import-history.css';
		wp_enqueue_style( 'safe-publish-import-history', $css_file, array(), '1.0.0' );

		// Enqueue DataViews styles with VIP-safe versioning.
		$style_file_path = plugin_dir_path( dirname( __DIR__ ) ) . 'build/style-index.css';
		$style_file_url  = plugin_dir_url( dirname( __DIR__ ) ) . 'build/style-index.css';

		if ( file_exists( $style_file_path ) ) {
			wp_enqueue_style(
				'safe-publish-admin-dataviews-style',
				$style_file_url,
				array( 'wp-components' ),
				filemtime( $style_file_path )
			);
		}

		// Enqueue the compiled import history JavaScript.
		$js_file       = plugin_dir_url( dirname( __DIR__ ) ) . 'build/import-history.js';
		$js_asset_file = dirname( __DIR__ ) . '/build/import-history.asset.php';

		$asset_data = array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-i18n' ),
			'version'      => '1.0.0',
		);

		if ( file_exists( $js_asset_file ) ) {
			$asset_data = require $js_asset_file;
		}

		wp_enqueue_script(
			'safe-publish-import-history',
			$js_file,
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);

		$json_data = wp_json_encode(
			array(
				'ajaxurl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'safe_publish_ajax_nonce' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			)
		);

		if ( false === $json_data || '' === $json_data ) {
			$json_data = '{}';
		}

		wp_add_inline_script(
			'safe-publish-import-history',
			sprintf( 'window.safePublishAdminData = %s;', $json_data ),
			'before'
		);

		?>
		<div class="wrap" id="safe-publish-import-history">
			<h1><?php esc_html_e( 'Import History', 'safe-publish' ); ?></h1>

			<!-- React component will be rendered here -->
			<div id="safe-publish-import-history-container">
				<div class="safe-publish-loading">
					<p><?php esc_html_e( 'Loading import history…', 'safe-publish' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Creates a new import session.
	 *
	 * @param string $source_url   Source site URL.
	 * @param string $session_type Type of import (single, bulk).
	 * @return int|WP_Error Session ID or error.
	 */
	public function create_session( $source_url, $session_type = 'bulk' ): int|WP_Error {
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
		$session_id,
		$external_id,
		$title,
		$status,
		$post_id = null,
		$error = null,
		$changes = array()
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
	public function update_session_stats( $session_id, $status ): void {
		$this->repository->update_session_stats( $session_id, $status );
	}

	/**
	 * Completes a session.
	 *
	 * @param int $session_id Session ID.
	 */
	public function complete_session( $session_id ): void {
		$this->repository->complete_session( $session_id );
	}

	/**
	 * Stores content diff for rollback purposes.
	 *
	 * @param int    $post_id     WordPress post ID.
	 * @param string $old_content Previous content.
	 * @param string $new_content New content.
	 */
	public function store_content_diff( $post_id, $old_content, $new_content ): void {
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

		$sessions = $this->repository->get_sessions( 50 );

		$formatted_sessions = array();

		foreach ( $sessions as $session ) {
			$total      = (int) get_post_meta( $session->ID, 'total_items', true );
			$successful = (int) get_post_meta( $session->ID, 'successful', true );
			$failed     = (int) get_post_meta( $session->ID, 'failed', true );
			$updated    = (int) get_post_meta( $session->ID, 'updated', true );
			$status     = get_post_meta( $session->ID, 'status', true );
			$source_url = get_post_meta( $session->ID, 'source_url', true );

			$status_labels = array(
				'in_progress' => __( 'In Progress', 'safe-publish' ),
				'completed'   => __( 'Completed', 'safe-publish' ),
				'failed'      => __( 'Failed', 'safe-publish' ),
				'rolled_back' => __( 'Rolled Back', 'safe-publish' ),
			);

			$formatted_sessions[] = array(
				'id'           => $session->ID,
				'date'         => get_the_date( 'Y-m-d H:i:s', $session ),
				'user'         => get_the_author_meta( 'display_name', (int) $session->post_author ),
				'total_items'  => $total,
				'successful'   => $successful,
				'failed'       => $failed,
				'updated'      => $updated,
				'status'       => $status,
				'status_label' => $status_labels[ $status ] ?? $status,
				'source_url'   => $source_url,
				'can_rollback' => ( 'completed' === $status && $successful > 0 ),
			);
		}

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

		// Get session data.
		$total      = (int) get_post_meta( $session_id, 'total_items', true );
		$successful = (int) get_post_meta( $session_id, 'successful', true );
		$failed     = (int) get_post_meta( $session_id, 'failed', true );
		$updated    = (int) get_post_meta( $session_id, 'updated', true );
		$status     = get_post_meta( $session_id, 'status', true );
		$source_url = get_post_meta( $session_id, 'source_url', true );

		$status_labels = array(
			'in_progress' => __( 'In Progress', 'safe-publish' ),
			'completed'   => __( 'Completed', 'safe-publish' ),
			'failed'      => __( 'Failed', 'safe-publish' ),
			'rolled_back' => __( 'Rolled Back', 'safe-publish' ),
		);

		$session_data = array(
			'id'           => $session->ID,
			'date'         => get_the_date( 'Y-m-d H:i:s', $session ),
			'user'         => get_the_author_meta( 'display_name', (int) $session->post_author ),
			'total_items'  => $total,
			'successful'   => $successful,
			'failed'       => $failed,
			'updated'      => $updated,
			'status'       => $status,
			'status_label' => $status_labels[ $status ] ?? $status,
			'source_url'   => $source_url,
			'can_rollback' => 'completed' === $status && $successful > 0,
		);

		// Get logs - but don't show details for rolled back sessions.
		$formatted_logs = array();

		if ( 'rolled_back' !== $status ) {
			$logs = $this->repository->get_session_logs( $session_id );

			foreach ( $logs as $log ) {
				$log_status  = get_post_meta( $log->ID, 'status', true );
				$post_id     = get_post_meta( $log->ID, 'post_id', true );
				$external_id = get_post_meta( $log->ID, 'external_id', true );

				$log_data = json_decode( $log->post_content, true );
				$error    = $log_data['error_message'] ?? null;

				$status_labels = array(
					'success' => __( 'Success', 'safe-publish' ),
					'updated' => __( 'Updated', 'safe-publish' ),
					'error'   => __( 'Error', 'safe-publish' ),
				);

				$changes              = get_post_meta( $log->ID, 'content_changes', true );
				$has_previous_content = is_array( $changes ) && ! empty( $changes['previous_content'] );

				// For debugging: also show button for updated posts even without previous content.
				$is_updated_post     = ( 'updated' === $log_status );
				$should_show_changes = $has_previous_content || $is_updated_post;

				// Check if this item has been individually rolled back.
				$is_rolled_back = get_post_meta( $log->ID, 'rolled_back', true );

				// Determine if this item can be rolled back.
				$can_rollback_item = false;
				if ( ! $is_rolled_back && $post_id && get_post( $post_id ) ) {
					// Can rollback if it's a success (delete) or updated with previous content (restore).
					$can_rollback_item = ( 'success' === $log_status ) ||
						( 'updated' === $log_status && $has_previous_content ) ||
						( 'updated' === $log_status && ! $has_previous_content ); // Legacy case - will delete.
				}

				$formatted_log = array(
					'id'              => $log->ID,
					'title'           => $log->post_title,
					'status'          => $log_status,
					'status_label'    => $status_labels[ $log_status ] ?? $log_status,
					'external_id'     => $external_id,
					'post_id'         => $post_id,
					'error'           => $error,
					'has_changes'     => $should_show_changes,
					'edit_url'        => $post_id ? admin_url( "post.php?post={$post_id}&action=edit" ) : null,
					'can_rollback'    => $can_rollback_item,
					'is_rolled_back'  => $is_rolled_back,
					'rollback_action' => 'success' === $log_status ? 'delete' :
						( $has_previous_content ? 'restore' : 'delete' ),
				);

				$formatted_logs[] = $formatted_log;
			}
		}

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

		$session = $this->repository->get_session( $session_id );

		if ( ! $session ) {
			wp_send_json_error( __( 'Session not found', 'safe-publish' ) );
		}

		// Get all successful imports from this session.
		$logs = $this->repository->get_session_logs_by_status( $session_id, array( 'success', 'updated' ) );

		$deleted_count  = 0;
		$restored_count = 0;

		foreach ( $logs as $log ) {
			$post_id = get_post_meta( $log->ID, 'post_id', true );
			$status  = get_post_meta( $log->ID, 'status', true );
			$changes = get_post_meta( $log->ID, 'content_changes', true );

			if ( ! $post_id || ! get_post( $post_id ) ) {
				continue;
			}

			if ( 'success' === $status ) {
				// This was a newly created post - delete it.
				if ( wp_delete_post( $post_id, true ) ) {
					++$deleted_count;
				}
			} elseif ( 'updated' === $status && ! empty( $changes ) && isset( $changes['previous_content'] ) ) {
				// This was an updated post - restore previous content.
				$restore_data = array(
					'ID' => $post_id,
				);

				// Restore previous content if available.
				if ( isset( $changes['previous_content'] ) ) {
					$restore_data['post_content'] = $changes['previous_content'];
				}

				// Restore previous title if available.
				if ( isset( $changes['previous_title'] ) ) {
					$restore_data['post_title'] = $changes['previous_title'];
				}

				// Restore previous excerpt if available.
				if ( isset( $changes['previous_excerpt'] ) ) {
					$restore_data['post_excerpt'] = $changes['previous_excerpt'];
				}

				// Update the post with previous content.
				$updated = wp_update_post( $restore_data, true );

				if ( ! is_wp_error( $updated ) ) {
					// Restore previous meta data if available.
					if ( isset( $changes['previous_meta'] ) && is_array( $changes['previous_meta'] ) ) {
						foreach ( $changes['previous_meta'] as $meta_key => $meta_value ) {
							update_post_meta( $post_id, $meta_key, $meta_value );
						}
					}

					// Restore previous featured image if available.
					if ( isset( $changes['previous_featured_image'] ) ) {
						if ( $changes['previous_featured_image'] ) {
							set_post_thumbnail( $post_id, $changes['previous_featured_image'] );
						} else {
							delete_post_thumbnail( $post_id );
						}
					}

					++$restored_count;
				}
			} elseif ( 'updated' === $status ) {
				// Updated post but no previous content stored - just delete it.
				// This handles legacy cases where previous content wasn't stored.
				if ( wp_delete_post( $post_id, true ) ) {
					++$deleted_count;
				}
			}
		}

		// Mark session as rolled back.
		$this->repository->mark_session_rolled_back( $session_id );

		wp_send_json_success(
			array(
				'deleted_count'  => $deleted_count,
				'restored_count' => $restored_count,
				'message'        => sprintf(
					/* translators: 1: number of posts deleted, 2: number of posts restored */
					__( '%1$d posts deleted and %2$d posts restored successfully.', 'safe-publish' ),
					$deleted_count,
					$restored_count
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

		$log = $this->repository->get_log( $log_id );

		if ( ! $log ) {
			wp_send_json_error( __( 'Import log not found', 'safe-publish' ) );
		}

		$post_id = get_post_meta( $log_id, 'post_id', true );
		$status  = get_post_meta( $log_id, 'status', true );
		$changes = get_post_meta( $log_id, 'content_changes', true );

		if ( ! $post_id ) {
			wp_send_json_error( __( 'No post ID found for this log entry', 'safe-publish' ) );
		}

		// Check if the post still exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( __( 'The post no longer exists', 'safe-publish' ) );
		}

		$result = array(
			'action'     => '',
			'post_id'    => $post_id,
			'post_title' => $post->post_title,
		);

		if ( 'success' === $status ) {
			// This was a newly created post - delete it.
			if ( wp_delete_post( $post_id, true ) ) {
				$result['action']  = 'deleted';
				$result['message'] = __( 'Post successfully deleted', 'safe-publish' );
			} else {
				wp_send_json_error( __( 'Failed to delete the post', 'safe-publish' ) );
			}
		} elseif ( 'updated' === $status && ! empty( $changes ) && isset( $changes['previous_content'] ) ) {
			// This was an updated post - restore previous content.
			$restore_data = array(
				'ID' => $post_id,
			);

			// Restore previous content if available.
			if ( isset( $changes['previous_content'] ) ) {
				$restore_data['post_content'] = $changes['previous_content'];
			}

			// Restore previous title if available.
			if ( isset( $changes['previous_title'] ) ) {
				$restore_data['post_title'] = $changes['previous_title'];
			}

			// Restore previous excerpt if available.
			if ( isset( $changes['previous_excerpt'] ) ) {
				$restore_data['post_excerpt'] = $changes['previous_excerpt'];
			}

			// Update the post with previous content.
			$updated = wp_update_post( $restore_data, true );

			if ( is_wp_error( $updated ) ) {
				wp_send_json_error( __( 'Failed to restore post: ', 'safe-publish' ) . $updated->get_error_message() );
			}

			// Restore previous meta data if available.
			if ( isset( $changes['previous_meta'] ) && is_array( $changes['previous_meta'] ) ) {
				foreach ( $changes['previous_meta'] as $meta_key => $meta_value ) {
					update_post_meta( $post_id, $meta_key, $meta_value );
				}
			}

			// Restore previous featured image if available.
			if ( isset( $changes['previous_featured_image'] ) ) {
				if ( $changes['previous_featured_image'] ) {
					set_post_thumbnail( $post_id, $changes['previous_featured_image'] );
				} else {
					delete_post_thumbnail( $post_id );
				}
			}

			$result['action']  = 'restored';
			$result['message'] = __( 'Post successfully restored to previous version', 'safe-publish' );
		} elseif ( 'updated' === $status ) {
			// Updated post but no previous content stored - delete it.
			if ( wp_delete_post( $post_id, true ) ) {
				$result['action']  = 'deleted';
				$result['message'] = __( 'Post deleted (no previous content available for restoration)', 'safe-publish' );
			} else {
				wp_send_json_error( __( 'Failed to delete the post', 'safe-publish' ) );
			}
		} else {
			wp_send_json_error( __( 'Cannot rollback this item: unsupported status', 'safe-publish' ) );
		}

		// Mark this specific log entry as rolled back.
		$this->repository->mark_log_rolled_back( $log_id );

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
				'post_type'      => self::LOG_POST_TYPE,
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
			$diff_html  = '<div class="safe-publish-no-diff-message" style="padding: 20px; text-align: center; background: #f9f9f9; border-radius: 4px;">';
			$diff_html .= '<h4>' . __( 'No Previous Content Available', 'safe-publish' ) . '</h4>';
			$diff_html .= '<p>' . __( 'This import was processed before content change tracking was enabled. Only the current content is available.', 'safe-publish' ) . '</p>';
			$diff_html .= '<div style="background: #fff; padding: 15px; border-radius: 4px; margin-top: 15px; text-align: left;">';
			$diff_html .= '<h5>' . __( 'Current Content:', 'safe-publish' ) . '</h5>';
			if ( $new_title ) {
				$diff_html .= '<p><strong>' . __( 'Title:', 'safe-publish' ) . '</strong> ' . esc_html( $new_title ) . '</p>';
			}
			if ( $new_excerpt ) {
				$diff_html .= '<p><strong>' . __( 'Excerpt:', 'safe-publish' ) . '</strong></p>';
				$diff_html .= '<div style="background: #f8f8f8; padding: 10px; border-radius: 3px; margin: 5px 0;"><pre style="white-space: pre-wrap; margin: 0;">' . esc_html( $new_excerpt ) . '</pre></div>';
			}
			$diff_html .= '<p><strong>' . __( 'Content:', 'safe-publish' ) . '</strong></p>';
			$diff_html .= '<div style="background: #f8f8f8; padding: 10px; border-radius: 3px; max-height: 300px; overflow-y: auto;"><pre style="white-space: pre-wrap; margin: 0;">' . esc_html( $new_content ) . '</pre></div>';
			$diff_html .= '</div>';
			$diff_html .= '</div>';
		} else {
			// Generate diff HTML for content, title, and excerpt.
			$diff_html = $this->generate_comprehensive_diff_html(
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
	 * Generates comprehensive HTML diff between old and new content, including title and excerpt.
	 *
	 * @param string $old_title   Old title.
	 * @param string $new_title   New title.
	 * @param string $old_excerpt Old excerpt.
	 * @param string $new_excerpt New excerpt.
	 * @param string $old_content Old content.
	 * @param string $new_content New content.
	 * @return string HTML diff.
	 */
	private function generate_comprehensive_diff_html( $old_title, $new_title, $old_excerpt, $new_excerpt, $old_content, $new_content ): string {
		$diff_html = '<div class="safe-publish-diff-container">';

		// Title diff.
		if ( $old_title !== $new_title ) {
			$diff_html .= '<div class="safe-publish-diff-section">';
			$diff_html .= '<h4>' . __( 'Title Changes', 'safe-publish' ) . '</h4>';
			$diff_html .= '<div class="safe-publish-diff-comparison">';
			$diff_html .= '<div class="safe-publish-diff-before" style="background: #f8d7da; padding: 10px; margin-bottom: 10px; border-radius: 4px;">';
			$diff_html .= '<strong>' . __( 'Before:', 'safe-publish' ) . '</strong><br>';
			$diff_html .= esc_html( $old_title );
			$diff_html .= '</div>';
			$diff_html .= '<div class="safe-publish-diff-after" style="background: #d4edda; padding: 10px; border-radius: 4px;">';
			$diff_html .= '<strong>' . __( 'After:', 'safe-publish' ) . '</strong><br>';
			$diff_html .= esc_html( $new_title );
			$diff_html .= '</div>';
			$diff_html .= '</div>';
			$diff_html .= '</div>';
		}

		// Excerpt diff.
		if ( $old_excerpt !== $new_excerpt ) {
			$diff_html .= '<div class="safe-publish-diff-section" style="margin-top: 20px;">';
			$diff_html .= '<h4>' . __( 'Excerpt Changes', 'safe-publish' ) . '</h4>';
			$diff_html .= '<div class="safe-publish-diff-comparison">';
			$diff_html .= '<div class="safe-publish-diff-before" style="background: #f8d7da; padding: 10px; margin-bottom: 10px; border-radius: 4px;">';
			$diff_html .= '<strong>' . __( 'Before:', 'safe-publish' ) . '</strong><br>';
			$diff_html .= '<pre>' . esc_html( $old_excerpt ) . '</pre>';
			$diff_html .= '</div>';
			$diff_html .= '<div class="safe-publish-diff-after" style="background: #d4edda; padding: 10px; border-radius: 4px;">';
			$diff_html .= '<strong>' . __( 'After:', 'safe-publish' ) . '</strong><br>';
			$diff_html .= '<pre>' . esc_html( $new_excerpt ) . '</pre>';
			$diff_html .= '</div>';
			$diff_html .= '</div>';
			$diff_html .= '</div>';
		}

		// Content diff.
		$diff_html .= '<div class="safe-publish-diff-section" style="margin-top: 20px;">';
		$diff_html .= '<h4>' . __( 'Content Changes', 'safe-publish' ) . '</h4>';
		$diff_html .= '<div class="safe-publish-diff-comparison">';
		$diff_html .= '<div class="safe-publish-diff-before" style="background: #f8d7da; padding: 10px; margin-bottom: 10px; border-radius: 4px;">';
		$diff_html .= '<strong>' . __( 'Before (Original Content):', 'safe-publish' ) . '</strong><br>';
		$diff_html .= '<pre style="white-space: pre-wrap; word-wrap: break-word; max-height: 300px; overflow-y: auto;">' . esc_html( $old_content ) . '</pre>';
		$diff_html .= '</div>';
		$diff_html .= '<div class="safe-publish-diff-after" style="background: #d4edda; padding: 10px; border-radius: 4px;">';
		$diff_html .= '<strong>' . __( 'After (Imported Content):', 'safe-publish' ) . '</strong><br>';
		$diff_html .= '<pre style="white-space: pre-wrap; word-wrap: break-word; max-height: 300px; overflow-y: auto;">' . esc_html( $new_content ) . '</pre>';
		$diff_html .= '</div>';
		$diff_html .= '</div>';
		$diff_html .= '</div>';

		$diff_html .= '</div>';

		return $diff_html;
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
}
