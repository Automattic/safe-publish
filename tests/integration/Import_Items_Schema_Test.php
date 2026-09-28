<?php
/**
 * Integration tests for the import items table schema upgrade
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Import_Items_Table;

/**
 * Exercises the has_previous_content realignment the upgrade performs, which
 * brings rows written under the old derivation in line with the condition
 * rollback dispatches on.
 */
class Import_Items_Schema_Test extends Integration_Test_Case {

	/**
	 * Inserts an item row with the flag and payload written verbatim.
	 *
	 * @param string      $status  Import status.
	 * @param string|null $changes Encoded content_changes, or null.
	 * @param int         $flag    Stored has_previous_content value.
	 * @return int Inserted row ID.
	 */
	private function seed_row( string $status, ?string $changes, int $flag ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Import_Items_Table::table_name(),
			array(
				'session_id'           => 1,
				'title'                => 'Legacy row',
				'status'               => $status,
				'post_id'              => 99,
				'content_changes'      => $changes,
				'has_previous_content' => $flag,
				'rolled_back'          => 0,
				'import_date_gmt'      => '2026-01-01 00:00:00',
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Reads back a row's stored flag.
	 *
	 * @param int $row_id Row ID.
	 * @return string Stored has_previous_content value.
	 */
	private function stored_flag( int $row_id ): string {
		global $wpdb;

		$table = Import_Items_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT has_previous_content FROM {$table} WHERE id = %d",
				$row_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Verifies that the upgrade realigns the stored delete-versus-restore
	 * prediction with what a rollback of each row would do.
	 */
	public function test_upgrade_realigns_has_previous_content(): void {
		// ARRANGE: Rows the old derivation flagged wrongly in both directions,
		// beside two it already agreed on.
		$empty_snapshot = $this->seed_row(
			'updated',
			'{"previous_content":"","previous_title":"Before"}',
			0
		);
		$unstored       = $this->seed_row( 'updated', null, 1 );
		$created        = $this->seed_row( 'success', '{"action":"created_new"}', 1 );
		$restorable     = $this->seed_row(
			'updated',
			'{"previous_content":"Old content."}',
			1
		);

		// ACT: Run the table upgrade.
		Import_Items_Table::create_table();

		// ASSERT: An update over an empty post now predicts the restore it
		// performs, rather than warning about a permanent deletion.
		$this->assertSame( '1', $this->stored_flag( $empty_snapshot ) );

		// ASSERT: A row whose snapshot never stored no longer promises one.
		$this->assertSame( '0', $this->stored_flag( $unstored ) );

		// ASSERT: A created row predicts its deletion.
		$this->assertSame( '0', $this->stored_flag( $created ) );

		// ASSERT: A row the old rule already had right is left alone.
		$this->assertSame( '1', $this->stored_flag( $restorable ) );
	}
}
