<?php
/**
 * Session Formatter class for formatting import session data
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use WP_Post;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Session Formatter Class.
 *
 * Formats import session and log data for AJAX responses.
 */
final class Session_Formatter {

	/**
	 * Formats a collection of sessions for display.
	 *
	 * @param WP_Post[] $sessions Array of session posts.
	 * @return array[] Formatted session data.
	 */
	public function format_sessions( array $sessions ): array {
		$formatted = array();

		foreach ( $sessions as $session ) {
			$formatted[] = $this->format_session( $session );
		}

		return $formatted;
	}

	/**
	 * Formats a single session for display.
	 *
	 * @param WP_Post $session Session post object.
	 * @return array Formatted session data.
	 */
	public function format_session( WP_Post $session ): array {
		$total      = (int) get_post_meta( $session->ID, 'total_items', true );
		$successful = (int) get_post_meta( $session->ID, 'successful', true );
		$failed     = (int) get_post_meta( $session->ID, 'failed', true );
		$updated    = (int) get_post_meta( $session->ID, 'updated', true );
		$status     = get_post_meta( $session->ID, 'status', true );
		$source_url = get_post_meta( $session->ID, 'source_url', true );

		$status_labels = $this->get_status_labels();

		return array(
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

	/**
	 * Formats session logs for display.
	 *
	 * @param WP_Post[] $logs   Array of log posts.
	 * @param string    $status Session status.
	 * @return array[] Formatted log data.
	 */
	public function format_logs( array $logs, string $status ): array {
		if ( 'rolled_back' === $status ) {
			return array();
		}

		$formatted = array();

		foreach ( $logs as $log ) {
			$formatted[] = $this->format_log( $log );
		}

		return $formatted;
	}

	/**
	 * Formats a single log entry for display.
	 *
	 * @param WP_Post $log Log post object.
	 * @return array Formatted log data.
	 */
	public function format_log( WP_Post $log ): array {
		$log_status  = get_post_meta( $log->ID, 'status', true );
		$post_id     = get_post_meta( $log->ID, 'post_id', true );
		$external_id = get_post_meta( $log->ID, 'external_id', true );

		$log_data = json_decode( $log->post_content, true );
		$error    = $log_data['error_message'] ?? null;

		$changes              = get_post_meta( $log->ID, 'content_changes', true );
		$has_previous_content = is_array( $changes ) && ! empty( $changes['previous_content'] );

		$is_updated_post     = ( 'updated' === $log_status );
		$should_show_changes = $has_previous_content || $is_updated_post;

		$is_rolled_back = (bool) get_post_meta( $log->ID, 'rolled_back', true );

		$can_rollback_item = $this->can_rollback_log(
			$is_rolled_back,
			$post_id,
			$log_status
		);

		$rollback_action = $this->determine_rollback_action(
			$log_status,
			$has_previous_content
		);

		$status_labels = $this->get_log_status_labels();

		return array(
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
			'rollback_action' => $rollback_action,
		);
	}

	/**
	 * Determines if a log entry can be rolled back.
	 *
	 * @param bool   $is_rolled_back Whether already rolled back.
	 * @param mixed  $post_id        WordPress post ID.
	 * @param string $log_status     Log status.
	 * @return bool Whether rollback is possible.
	 */
	private function can_rollback_log(
		bool $is_rolled_back,
		$post_id,
		string $log_status
	): bool {
		if ( $is_rolled_back ) {
			return false;
		}

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return false;
		}

		return in_array( $log_status, array( 'success', 'updated' ), true );
	}

	/**
	 * Determines the rollback action for a log entry.
	 *
	 * @param string $log_status         Log status.
	 * @param bool   $has_previous_content Whether previous content exists.
	 * @return string Rollback action ('delete' or 'restore').
	 */
	private function determine_rollback_action(
		string $log_status,
		bool $has_previous_content
	): string {
		if ( 'success' === $log_status ) {
			return 'delete';
		}

		return $has_previous_content ? 'restore' : 'delete';
	}

	/**
	 * Gets session status labels.
	 *
	 * @return array<string, string> Status labels.
	 */
	private function get_status_labels(): array {
		return array(
			'in_progress' => __( 'In Progress', 'safe-publish' ),
			'completed'   => __( 'Completed', 'safe-publish' ),
			'failed'      => __( 'Failed', 'safe-publish' ),
			'rolled_back' => __( 'Rolled Back', 'safe-publish' ),
		);
	}

	/**
	 * Gets log status labels.
	 *
	 * @return array<string, string> Status labels.
	 */
	private function get_log_status_labels(): array {
		return array(
			'success' => __( 'Success', 'safe-publish' ),
			'updated' => __( 'Updated', 'safe-publish' ),
			'error'   => __( 'Error', 'safe-publish' ),
		);
	}
}
