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
 * Exercises the table upgrade, including the has_previous_content realignment
 * that brings rows written under the old derivation in line with the
 * condition rollback dispatches on.
 */
class Import_Items_Schema_Test extends Integration_Test_Case {

	use Failing_Query_Trait;

	/**
	 * Option key tracking the installed table schema version, spelled out
	 * independently of the production constant so a change has to be
	 * deliberate.
	 */
	private const VERSION_OPTION = 'safe_publish_import_items_version';

	/**
	 * Tear down test environment.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->restore_failing_queries();

		parent::tearDown();
	}

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

	/**
	 * Verifies that a completed upgrade records the schema version.
	 */
	public function test_completed_upgrade_records_the_schema_version(): void {
		// ARRANGE: No recorded version, as on an install yet to upgrade.
		delete_option( self::VERSION_OPTION );

		// ACT: Run the table upgrade.
		Import_Items_Table::create_table();

		// ASSERT: The version was recorded.
		$this->assertNotFalse( get_option( self::VERSION_OPTION ) );
	}

	/**
	 * Verifies that a failed row update leaves the schema version unrecorded,
	 * so the upgrade is retried rather than marked complete over stale rows.
	 *
	 * @dataProvider upgrade_query_provider
	 *
	 * @param string $fingerprint SQL fragment unique to the query to break.
	 */
	public function test_failed_upgrade_query_leaves_the_version_unrecorded(
		string $fingerprint
	): void {
		// ARRANGE: No recorded version, and one row update forced to fail.
		delete_option( self::VERSION_OPTION );
		$this->fail_queries_matching( $fingerprint );

		// ACT: Run the table upgrade.
		Import_Items_Table::create_table();

		// ASSERT: The version stayed unrecorded.
		$this->assertFalse( get_option( self::VERSION_OPTION ) );
	}

	/**
	 * Provides a fingerprint for each row update the upgrade runs.
	 *
	 * @return array<string, array{string}> Row update cases.
	 */
	public function upgrade_query_provider(): array {
		return array(
			'modified-date seed' => array( 'SET source_modified_gmt =' ),
			'flag realignment'   => array( 'SET has_previous_content = 1' ),
			'flag clearing'      => array( 'SET has_previous_content = 0' ),
		);
	}
}
