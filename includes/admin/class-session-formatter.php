<?php
/**
 * Session Formatter class for formatting import session data
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Session Formatter Class.
 *
 * Formats import session and item rows for AJAX responses.
 */
final class Session_Formatter {

	/**
	 * Formats a collection of sessions for display.
	 *
	 * @param array[] $sessions Array of session rows.
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
	 * @param array $session Session row.
	 * @return array Formatted session data.
	 */
	public function format_session( array $session ): array {
		$total      = (int) $session['total_items'];
		$successful = (int) $session['successful'];
		$failed     = (int) $session['failed'];
		$updated    = (int) $session['updated'];
		$status     = (string) $session['status'];
		$source_url = (string) $session['source_url'];
		$created    = (string) $session['created_at_gmt'];

		$user = (string) $session['user_display_name'];
		if ( '' === $user ) {
			$user = __( 'Unknown user', 'safe-publish' );
		}

		$status_labels = $this->get_status_labels();

		return array(
			'id'           => (int) $session['id'],
			'date'         => str_replace( ' ', 'T', $created ) . 'Z',
			'user'         => $user,
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
	 * Formats session items for display.
	 *
	 * @param array[] $items  Array of item rows.
	 * @param string  $status Session status.
	 * @return array[] Formatted item data.
	 */
	public function format_items( array $items, string $status ): array {
		if ( 'rolled_back' === $status ) {
			return array();
		}

		$formatted = array();

		foreach ( $items as $item ) {
			$formatted[] = $this->format_item( $item );
		}

		return $formatted;
	}

	/**
	 * Formats a single item row for display.
	 *
	 * @param array $item Item row.
	 * @return array Formatted item data.
	 */
	public function format_item( array $item ): array {
		$item_status = (string) $item['status'];
		$post_id     = (int) ( $item['post_id'] ?? 0 );
		$external_id = null !== $item['external_id'] ? (int) $item['external_id'] : null;
		$error_msg   = (string) ( $item['error_message'] ?? '' );
		$error       = '' !== $error_msg ? $error_msg : null;

		$has_previous_content = 1 === (int) $item['has_previous_content'];

		$is_updated_post     = ( 'updated' === $item_status );
		$should_show_changes = $has_previous_content || $is_updated_post;

		$is_rolled_back = 0 !== (int) $item['rolled_back'];

		$can_rollback_item = $this->can_rollback_item(
			$is_rolled_back,
			$post_id,
			$item_status
		);

		$rollback_action = $this->determine_rollback_action(
			$item_status,
			$has_previous_content
		);

		$edit_url = $post_id > 0
			? admin_url( "post.php?post={$post_id}&action=edit" )
			: null;

		$status_labels = $this->get_item_status_labels();

		return array(
			'id'              => (int) $item['id'],
			'title'           => (string) $item['title'],
			'status'          => $item_status,
			'status_label'    => $status_labels[ $item_status ] ?? $item_status,
			'external_id'     => $external_id,
			'post_id'         => $post_id > 0 ? $post_id : null,
			'error'           => $error,
			'has_changes'     => $should_show_changes,
			'edit_url'        => $edit_url,
			'can_rollback'    => $can_rollback_item,
			'is_rolled_back'  => $is_rolled_back,
			'rollback_action' => $rollback_action,
		);
	}

	/**
	 * Determines if an item can be rolled back.
	 *
	 * @param bool   $is_rolled_back Whether already rolled back.
	 * @param int    $post_id        WordPress post ID.
	 * @param string $item_status    Item status.
	 * @return bool Whether rollback is possible.
	 */
	private function can_rollback_item(
		bool $is_rolled_back,
		int $post_id,
		string $item_status
	): bool {
		if ( $is_rolled_back ) {
			return false;
		}

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return false;
		}

		return in_array( $item_status, array( 'success', 'updated' ), true );
	}

	/**
	 * Determines the rollback action for an item.
	 *
	 * @param string $item_status          Item status.
	 * @param bool   $has_previous_content Whether previous content exists.
	 * @return string Rollback action ('delete' or 'restore').
	 */
	private function determine_rollback_action(
		string $item_status,
		bool $has_previous_content
	): string {
		if ( 'success' === $item_status ) {
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
	 * Gets item status labels.
	 *
	 * @return array<string, string> Status labels.
	 */
	private function get_item_status_labels(): array {
		return array(
			'success' => __( 'Success', 'safe-publish' ),
			'updated' => __( 'Updated', 'safe-publish' ),
			'error'   => __( 'Error', 'safe-publish' ),
		);
	}
}
