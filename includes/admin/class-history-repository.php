<?php
/**
 * History Repository class for import session data storage and retrieval
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use Safe_Publish\Utils\Log_Events;
use WP_Error;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * History Repository Class.
 *
 * Handles all data storage and retrieval operations for import sessions and
 * items, backed by the {$wpdb->prefix}safe_publish_imports and
 * {$wpdb->prefix}safe_publish_import_items tables.
 */
final class History_Repository {

	/**
	 * Import logger instance.
	 *
	 * @var Import_Logger
	 */
	private Import_Logger $logger;

	/**
	 * Constructs the History_Repository instance.
	 */
	public function __construct() {
		$this->logger = new Import_Logger();
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
		global $wpdb;

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Imports_Table::table_name(),
			array(
				'user_id'           => $user_id,
				'user_display_name' => $user
					? $user->display_name
					: __( 'Unknown user', 'safe-publish' ),
				'source_url'        => $source_url,
				'session_type'      => $session_type,
				'status'            => 'in_progress',
				'total_items'       => 0,
				'successful'        => 0,
				'failed'            => 0,
				'updated'           => 0,
				'end_time'          => null,
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'session_insert_failed',
				__( 'Failed to create import session.', 'safe-publish' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Logs an import action.
	 *
	 * @param int         $session_id  Session ID.
	 * @param int         $external_id External post ID.
	 * @param string      $title       Post title.
	 * @param string      $status      Import status (success, error, updated).
	 * @param int|null    $post_id     WordPress post ID; null for error status.
	 * @param string|null $error       Error message; null for success/updated.
	 * @param array       $changes     Changes made during import.
	 * @return int|WP_Error Item ID or error.
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
		global $wpdb;

		$encoded_changes = null;
		if ( count( $changes ) > 0 ) {
			$json = wp_json_encode( $changes );

			if ( false !== $json ) {
				$encoded_changes = $json;
			}
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Import_Items_Table::table_name(),
			array(
				'session_id'      => $session_id,
				'title'           => $title,
				'external_id'     => $external_id,
				'status'          => $status,
				'post_id'         => $post_id,
				'error_message'   => $error,
				'content_changes' => $encoded_changes,
				'rolled_back'     => 0,
				'import_date'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'item_insert_failed',
				__( 'Failed to create import item.', 'safe-publish' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates session stats with a single atomic UPDATE.
	 *
	 * @param int    $session_id Session ID.
	 * @param string $status     Status of the import (success, error, updated).
	 */
	public function update_session_stats( int $session_id, string $status ): void {
		global $wpdb;

		$table = Imports_Table::table_name();

		$sql = "UPDATE `{$table}` SET total_items = total_items + 1";

		switch ( $status ) {
			case 'success':
				$sql .= ', successful = successful + 1';
				break;
			case 'updated':
				$sql .= ', successful = successful + 1, updated = updated + 1';
				break;
			case 'error':
				$sql .= ', failed = failed + 1';
				break;
		}

		$sql .= ' WHERE id = %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( $sql, $session_id ) );
	}

	/**
	 * Completes a session.
	 *
	 * @param int $session_id Session ID.
	 */
	public function complete_session( int $session_id ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Imports_Table::table_name(),
			array(
				'status'   => 'completed',
				'end_time' => current_time( 'mysql' ),
			),
			array( 'id' => $session_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Retrieves import sessions in reverse-chronological order.
	 *
	 * @param int $limit Maximum number of sessions to retrieve.
	 * @return array[] Array of session rows.
	 */
	public function get_sessions( int $limit = 50 ): array {
		global $wpdb;

		$table = Imports_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $rows ? $rows : array();
	}

	/**
	 * Retrieves a single session by ID.
	 *
	 * @param int $session_id Session ID.
	 * @return array|null Session row or null if not found.
	 */
	public function get_session( int $session_id ): ?array {
		global $wpdb;

		$table = Imports_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $session_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ? $row : null;
	}

	/**
	 * Retrieves all items for a session.
	 *
	 * @param int $session_id Session ID.
	 * @return array[] Array of item rows.
	 */
	public function get_session_items( int $session_id ): array {
		global $wpdb;

		$table = Import_Items_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE session_id = %d ORDER BY id ASC",
				$session_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $rows ? $rows : array();
	}

	/**
	 * Retrieves items with specific statuses for a session.
	 *
	 * @param int   $session_id Session ID.
	 * @param array $statuses   Array of statuses to filter by.
	 * @return array[] Array of item rows.
	 */
	public function get_session_items_by_status(
		int $session_id,
		array $statuses
	): array {
		global $wpdb;

		if ( 0 === count( $statuses ) ) {
			return array();
		}

		$table        = Import_Items_Table::table_name();
		$count        = count( $statuses );
		$placeholders = implode( ', ', array_fill( 0, $count, '%s' ) );
		$values       = array_values( $statuses );
		array_unshift( $values, $session_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE session_id = %d"
					. " AND status IN ({$placeholders}) ORDER BY id ASC",
				...$values
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $rows ? $rows : array();
	}

	/**
	 * Retrieves a single item by ID.
	 *
	 * @param int $item_id Item ID.
	 * @return array|null Item row or null if not found.
	 */
	public function get_item( int $item_id ): ?array {
		global $wpdb;

		$table = Import_Items_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $item_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ? $row : null;
	}

	/**
	 * Looks up the most recent item row for a given imported post.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array|null Item row or null if no matching item exists.
	 */
	public function get_item_for_post( int $post_id ): ?array {
		global $wpdb;

		$table = Import_Items_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE post_id = %d ORDER BY id DESC LIMIT 1",
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ? $row : null;
	}

	/**
	 * Marks a session as rolled back and emits an audit log event.
	 *
	 * @param int $session_id Session ID.
	 */
	public function mark_session_rolled_back( int $session_id ): void {
		global $wpdb;

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Imports_Table::table_name(),
			array( 'status' => 'rolled_back' ),
			array( 'id' => $session_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			$this->logger->log_error(
				Log_Events::ROLLBACK_FAILED,
				array(
					'scope'      => 'session',
					'session_id' => $session_id,
				)
			);
			return;
		}

		$this->logger->log_event(
			Log_Events::SESSION_ROLLED_BACK,
			array( 'session_id' => $session_id )
		);
	}

	/**
	 * Marks a single item as rolled back and emits an audit log event.
	 *
	 * @param int $item_id Item ID.
	 */
	public function mark_item_rolled_back( int $item_id ): void {
		global $wpdb;

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Import_Items_Table::table_name(),
			array( 'rolled_back' => 1 ),
			array( 'id' => $item_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			$this->logger->log_error(
				Log_Events::ROLLBACK_FAILED,
				array(
					'scope'   => 'item',
					'item_id' => $item_id,
				)
			);
			return;
		}

		$this->logger->log_event(
			Log_Events::ITEM_ROLLED_BACK,
			array( 'item_id' => $item_id )
		);
	}

	/**
	 * Decodes the JSON value stored in the content_changes column.
	 *
	 * @param mixed $raw Raw column value.
	 * @return array|null Decoded array, or null when no changes are stored.
	 */
	public static function decode_item_changes( $raw ): ?array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Deletes a session and all of its associated items.
	 *
	 * @param int $session_id Session ID.
	 * @return bool True if the session row was removed.
	 */
	public function delete_session( int $session_id ): bool {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Import_Items_Table::table_name(),
			array( 'session_id' => $session_id ),
			array( '%d' )
		);

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			Imports_Table::table_name(),
			array( 'id' => $session_id ),
			array( '%d' )
		);

		return false !== $result && $result > 0;
	}
}
