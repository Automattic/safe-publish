<?php
/**
 * History Repository class for import session data storage and retrieval
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Options;
use WP_Error;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * History Repository Class.
 *
 * Handles all data storage and retrieval operations for import sessions and logs.
 */
final class History_Repository {

	/**
	 * Custom post type for import sessions.
	 */
	const SESSION_POST_TYPE = 'sp_import_session';

	/**
	 * Custom post type for import logs.
	 */
	const LOG_POST_TYPE = 'sp_import_log';

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
		$session_id = wp_insert_post(
			array(
				'post_type'   => self::SESSION_POST_TYPE,
				'post_title'  => sprintf(
					/* translators: %s: timestamp of the import session */
					__( 'Import Session - %s', 'safe-publish' ),
					current_time( 'Y-m-d H:i:s' )
				),
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
				'meta_input'  => array(
					'source_url'   => $source_url,
					'session_type' => $session_type,
					'total_items'  => 0,
					'successful'   => 0,
					'failed'       => 0,
					'updated'      => 0,
					'status'       => 'in_progress',
					'start_time'   => current_time( 'mysql' ),
				),
			)
		);

		return $session_id;
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
		$log_data = array(
			'session_id'    => $session_id,
			'external_id'   => $external_id,
			'status'        => $status,
			'post_id'       => $post_id,
			'error_message' => $error,
			'changes'       => $changes,
		);

		$log_content = wp_json_encode( $log_data );

		$log_id = wp_insert_post(
			array(
				'post_type'    => self::LOG_POST_TYPE,
				'post_title'   => $title,
				'post_content' => false !== $log_content ? $log_content : '',
				'post_status'  => 'publish',
				'post_parent'  => $session_id,
				'meta_input'   => array(
					'session_id'  => $session_id,
					'external_id' => $external_id,
					'status'      => $status,
					'post_id'     => $post_id,
					'import_date' => current_time( 'mysql' ),
				),
			)
		);

		// Store changes as post meta if they exist.
		if ( ! empty( $changes ) ) {
			update_post_meta( $log_id, 'content_changes', $changes );
		}

		return $log_id;
	}

	/**
	 * Updates session stats.
	 *
	 * @param int    $session_id Session ID.
	 * @param string $status     Status of the import (success, error, updated).
	 */
	public function update_session_stats( int $session_id, string $status ): void {
		$total      = (int) get_post_meta( $session_id, 'total_items', true );
		$successful = (int) get_post_meta( $session_id, 'successful', true );
		$failed     = (int) get_post_meta( $session_id, 'failed', true );
		$updated    = (int) get_post_meta( $session_id, 'updated', true );

		update_post_meta( $session_id, 'total_items', $total + 1 );

		switch ( $status ) {
			case 'success':
				update_post_meta( $session_id, 'successful', $successful + 1 );
				break;
			case 'updated':
				update_post_meta( $session_id, 'successful', $successful + 1 );
				update_post_meta( $session_id, 'updated', $updated + 1 );
				break;
			case 'error':
				update_post_meta( $session_id, 'failed', $failed + 1 );
				break;
		}
	}

	/**
	 * Completes a session.
	 *
	 * @param int $session_id Session ID.
	 */
	public function complete_session( int $session_id ): void {
		update_post_meta( $session_id, 'status', 'completed' );
		update_post_meta( $session_id, 'end_time', current_time( 'mysql' ) );
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
		$diff_data = array(
			'old_content' => $old_content,
			'new_content' => $new_content,
			'diff_date'   => current_time( 'mysql' ),
		);

		update_post_meta( $post_id, Options::META_CONTENT_HISTORY, $diff_data );
	}

	/**
	 * Retrieves all import sessions.
	 *
	 * @param int $limit Maximum number of sessions to retrieve.
	 * @return array Array of session posts.
	 */
	public function get_sessions( int $limit = 50 ): array {
		$sessions = get_posts(
			array(
				'post_type'      => self::SESSION_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(), // Empty meta_query.
			)
		);

		return $sessions;
	}

	/**
	 * Retrieves a single session by ID.
	 *
	 * @param int $session_id Session ID.
	 * @return \WP_Post|null Session post or null if not found.
	 */
	public function get_session( int $session_id ): ?\WP_Post {
		$session = get_post( $session_id );

		if ( ! $session || self::SESSION_POST_TYPE !== $session->post_type ) {
			return null;
		}

		return $session;
	}

	/**
	 * Retrieves all logs for a session.
	 *
	 * @param int $session_id Session ID.
	 * @return array Array of log posts.
	 */
	public function get_session_logs( int $session_id ): array {
		$logs = get_posts(
			array(
				'post_type'      => self::LOG_POST_TYPE,
				'post_status'    => 'publish',
				'post_parent'    => $session_id,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		return $logs;
	}

	/**
	 * Retrieves logs with specific status for a session.
	 *
	 * @param int   $session_id Session ID.
	 * @param array $statuses   Array of statuses to filter by.
	 * @return array Array of log posts.
	 */
	public function get_session_logs_by_status(
		int $session_id,
		array $statuses
	): array {
		$logs = get_posts(
			array(
				'post_type'      => self::LOG_POST_TYPE,
				'post_status'    => 'publish',
				'post_parent'    => $session_id,
				'posts_per_page' => -1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array( // Admin-only operation, scoped by post_parent.
					array(
						'key'     => 'status',
						'value'   => $statuses,
						'compare' => 'IN',
					),
				),
			)
		);

		return $logs;
	}

	/**
	 * Retrieves a single log by ID.
	 *
	 * @param int $log_id Log ID.
	 * @return \WP_Post|null Log post or null if not found.
	 */
	public function get_log( int $log_id ): ?\WP_Post {
		$log = get_post( $log_id );

		if ( ! $log || self::LOG_POST_TYPE !== $log->post_type ) {
			return null;
		}

		return $log;
	}

	/**
	 * Marks a session as rolled back.
	 *
	 * @param int $session_id Session ID.
	 */
	public function mark_session_rolled_back( int $session_id ): void {
		update_post_meta( $session_id, 'status', 'rolled_back' );
		update_post_meta( $session_id, 'rollback_date', current_time( 'mysql' ) );
		update_post_meta( $session_id, 'rollback_user', get_current_user_id() );
	}

	/**
	 * Marks a log entry as rolled back.
	 *
	 * @param int $log_id Log ID.
	 */
	public function mark_log_rolled_back( int $log_id ): void {
		update_post_meta( $log_id, 'rolled_back', true );
		update_post_meta( $log_id, 'rollback_date', current_time( 'mysql' ) );
		update_post_meta( $log_id, 'rollback_user', get_current_user_id() );
	}

	/**
	 * Deletes a session and all its associated logs.
	 *
	 * @param int $session_id Session ID.
	 * @return bool True if successful, false otherwise.
	 */
	public function delete_session( int $session_id ): bool {
		// Get all logs associated with this session.
		$logs = $this->get_session_logs( $session_id );

		// Delete all associated logs first.
		foreach ( $logs as $log ) {
			wp_delete_post( $log->ID, true );
		}

		// Delete the session itself.
		$result = wp_delete_post( $session_id, true );

		return false !== $result;
	}
}
