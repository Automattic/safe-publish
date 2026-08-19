<?php
/**
 * Base test case for integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Source_Post_Type_Resolver;
use Safe_Publish\Utils\Attention_Issues_Table;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use WP_UnitTestCase;

/**
 * Integration Test Case Class.
 *
 * Provides common setup for integration tests that use the import history
 * tables.
 */
abstract class Integration_Test_Case extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		Source_Post_Type_Resolver::reset_cache();

		wp_set_current_user(
			self::factory()->user->create(
				array( 'role' => 'administrator' )
			)
		);

		Imports_Table::create_table();
		Import_Items_Table::create_table();
		Attention_Issues_Table::create_table();

		$this->truncate_history_tables();
	}

	/**
	 * Resets the history tables between tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->truncate_history_tables();
		parent::tearDown();
	}

	/**
	 * Removes all rows from the import history and attention issue tables.
	 */
	private function truncate_history_tables(): void {
		global $wpdb;

		$items     = Import_Items_Table::table_name();
		$sessions  = Imports_Table::table_name();
		$attention = Attention_Issues_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DELETE FROM `{$items}`" );
		$wpdb->query( "DELETE FROM `{$sessions}`" );
		$wpdb->query( "DELETE FROM `{$attention}`" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
