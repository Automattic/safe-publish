<?php
/**
 * History Repository class for import session data storage and retrieval
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
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
	 * @param string $source_site_url Source site URL.
	 * @param string $session_type    Type of import (single, bulk).
	 * @return int|WP_Error Session ID or error.
	 */
	public function create_session(
		string $source_site_url,
		string $session_type = 'bulk'
	): int|WP_Error {
		global $wpdb;

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			Imports_Table::table_name(),
			array(
				'user_id'           => $user_id,
				'user_display_name' => $user
					? $user->display_name
					: __( 'Unknown user', 'safe-publish' ),
				'source_site_url'   => $source_site_url,
				'session_type'      => $session_type,
				'status'            => 'in_progress',
				'ended_at_gmt'      => null,
				'created_at_gmt'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
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
	 * @param int         $session_id       Session ID.
	 * @param int|null    $source_post_id   Source post ID, or null if not provided.
	 * @param string      $title            Post title.
	 * @param string      $status           Import status (success, error, updated).
	 * @param int|null    $post_id          WordPress post ID; null for error status.
	 * @param string|null $error            Error message; null for success/updated.
	 * @param array       $changes          Changes made during import.
	 * @param array       $warnings         Non-fatal warnings raised during import.
	 * @return int|WP_Error Item ID or error.
	 */
	public function log_import_action(
		int $session_id,
		?int $source_post_id,
		string $title,
		string $status,
		?int $post_id = null,
		?string $error = null,
		array $changes = array(),
		array $warnings = array()
	): int|WP_Error {
		global $wpdb;

		$encoded_changes      = null;
		$has_previous_content = 0;

		if ( count( $changes ) > 0 ) {
			$json = wp_json_encode( $changes );

			if ( false !== $json ) {
				$encoded_changes = $json;
			}

			if ( '' !== ( $changes['previous_content'] ?? '' ) ) {
				$has_previous_content = 1;
			}
		}

		$encoded_warnings = null;

		if ( count( $warnings ) > 0 ) {
			$json = wp_json_encode( $warnings );

			if ( false !== $json ) {
				$encoded_warnings = $json;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			Import_Items_Table::table_name(),
			array(
				'session_id'           => $session_id,
				'title'                => $title,
				'source_post_id'       => $source_post_id,
				'status'               => $status,
				'post_id'              => $post_id,
				'error_message'        => $error,
				'content_changes'      => $encoded_changes,
				'warnings'             => $encoded_warnings,
				'has_previous_content' => $has_previous_content,
				'rolled_back'          => 0,
				'import_date_gmt'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
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
	 * Completes a session.
	 *
	 * @param int $session_id Session ID.
	 */
	public function complete_session( int $session_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Imports_Table::table_name(),
			array(
				'status'       => 'completed',
				'ended_at_gmt' => current_time( 'mysql', true ),
			),
			array( 'id' => $session_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Retrieves import sessions in reverse-chronological order with item
	 * counts projected from the items table.
	 *
	 * @param int $limit Maximum number of sessions to retrieve.
	 * @return array[] Array of session rows including total_items, successful,
	 *                 updated, and failed counts.
	 */
	public function get_sessions( int $limit = 50 ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$this->build_session_select_sql(
					'GROUP BY i.id ORDER BY i.created_at_gmt DESC, i.id DESC LIMIT %d'
				),
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $rows ? $rows : array();
	}

	/**
	 * Retrieves a single session by ID with item counts projected from the
	 * items table.
	 *
	 * @param int $session_id Session ID.
	 * @return array|null Session row including total_items, successful,
	 *                   updated, and failed counts, or null if not found.
	 */
	public function get_session( int $session_id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				$this->build_session_select_sql(
					'WHERE i.id = %d GROUP BY i.id'
				),
				$session_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ? $row : null;
	}

	/**
	 * Builds a session SELECT statement that projects per-session item counts
	 * by joining the items table.
	 *
	 * @param string $tail_clause WHERE/GROUP BY/ORDER BY/LIMIT tail.
	 * @return string Composed SQL statement.
	 */
	private function build_session_select_sql( string $tail_clause ): string {
		$imports = Imports_Table::table_name();
		$items   = Import_Items_Table::table_name();

		$counts = 'COUNT(it.id) AS total_items,'
			. " COALESCE(SUM(it.status IN ('success', 'updated')), 0)"
			. ' AS successful,'
			. " COALESCE(SUM(it.status = 'updated'), 0) AS updated,"
			. " COALESCE(SUM(it.status = 'error'), 0) AS failed";

		return "SELECT i.*, {$counts} FROM `{$imports}` i"
			. " LEFT JOIN `{$items}` it ON it.session_id = i.id"
			. " {$tail_clause}";
	}

	/**
	 * Retrieves all items for a session, excluding the content_changes LONGTEXT
	 * column.
	 *
	 * The has_previous_content flag is read directly so callers can decide
	 * whether to lazily fetch the full payload.
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
				'SELECT id, session_id, title, source_post_id, status, post_id,'
					. ' error_message, has_previous_content, rolled_back,'
					. " import_date_gmt FROM `{$table}` WHERE session_id = %d"
					. ' ORDER BY id ASC',
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			Imports_Table::table_name(),
			array( 'status' => 'rolled_back' ),
			array( 'id' => $session_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			$this->logger->session_rollback_failed( $session_id, $wpdb->last_error );
			return;
		}

		if ( 0 === $updated ) {
			$this->logger->session_rollback_noop( $session_id );
		} else {
			$this->logger->session_rolled_back( $session_id );
		}
	}

	/**
	 * Marks a single item as rolled back and emits an audit log event.
	 *
	 * @param int $item_id Item ID.
	 */
	public function mark_item_rolled_back( int $item_id ): void {
		global $wpdb;

		$table = Import_Items_Table::table_name();
		// Snapshot session_id and post_id before the UPDATE so the audit row
		// can link to both parents regardless of update outcome.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT session_id, post_id FROM {$table} WHERE id = %d",
				$item_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$session_id = isset( $item['session_id'] ) ? (int) $item['session_id'] : 0;
		$post_id    = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array( 'rolled_back' => 1 ),
			array( 'id' => $item_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			$this->logger->item_rollback_failed(
				$item_id,
				$session_id,
				$post_id,
				$wpdb->last_error
			);
			return;
		}

		if ( 0 === $updated ) {
			$this->logger->item_rollback_noop( $item_id, $session_id, $post_id );
		} else {
			$this->logger->item_rolled_back( $item_id, $session_id, $post_id );
		}
	}

	/**
	 * Decodes the JSON value stored in the content_changes column.
	 *
	 * @param mixed $raw Raw column value.
	 * @return array|null Decoded array, or null when no changes are stored.
	 */
	public static function decode_item_changes( mixed $raw ): ?array {
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

		$imports_table = Imports_Table::table_name();
		// Snapshot source_site_url before delete so the audit row can describe
		// the session that was removed (the row is gone by the time the event
		// is recorded).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$session_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT source_site_url FROM {$imports_table} WHERE id = %d",
				$session_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$source_site_url = isset( $session_row['source_site_url'] )
			? (string) $session_row['source_site_url']
			: '';

		// Bail out on a DB error to avoid orphaning items and emitting a
		// misleading `items_deleted` count in the audit log.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$items_result = $wpdb->delete(
			Import_Items_Table::table_name(),
			array( 'session_id' => $session_id ),
			array( '%d' )
		);

		if ( false === $items_result ) {
			$this->logger->session_delete_failed(
				$session_id,
				$source_site_url,
				$wpdb->last_error
			);
			return false;
		}

		$items_deleted = (int) $items_result;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$imports_table,
			array( 'id' => $session_id ),
			array( '%d' )
		);

		if ( false === $result ) {
			$this->logger->session_delete_failed(
				$session_id,
				$source_site_url,
				$wpdb->last_error
			);
			return false;
		}

		$deleted = $result > 0;

		if ( $deleted ) {
			$this->logger->session_deleted(
				$session_id,
				$source_site_url,
				$items_deleted
			);
		}

		return $deleted;
	}
}
