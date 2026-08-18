<?php
/**
 * Integration tests for the stored session source identity
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use WP_Error;

/**
 * Exercises write-time normalization of the session source identity, the
 * backfill that rewrites values stored before it, and the purge of sessions
 * recorded without a source.
 */
class Imports_Source_Identity_Test extends Integration_Test_Case {

	/**
	 * Option key tracking the installed table schema version, spelled out
	 * independently of the production constant so a change has to be
	 * deliberate.
	 */
	private const VERSION_OPTION = 'safe_publish_imports_version';

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Set up test environment.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->repository = new History_Repository();
	}

	/**
	 * Tear down test environment.
	 */
	#[\Override]
	protected function tearDown(): void {
		global $wpdb;

		// Dropped before the base class truncates, so a forced failure cannot
		// reach its cleanup queries.
		remove_all_filters( 'query' );
		$wpdb->suppress_errors( false );

		parent::tearDown();
	}

	/**
	 * Verifies that create_session stores the normalized path-bearing
	 * identity rather than the raw connection URL.
	 */
	public function test_create_session_stores_the_normalized_identity(): void {
		// ARRANGE: A connection URL entered with a trailing slash.
		$raw = 'https://example.com/blog/';

		// ACT: Open a session for it.
		$session_id = $this->repository->create_session( $raw, 'bulk' );

		// ASSERT: The trailing slash is gone from the stored identity.
		$this->assertIsInt( $session_id );
		$this->assertSame(
			'https://example.com/blog',
			$this->stored_url( $session_id )
		);
	}

	/**
	 * Verifies that create_session keeps a value it cannot parse instead of
	 * storing an empty identity.
	 */
	public function test_create_session_preserves_an_unparseable_value(): void {
		// ARRANGE: A connection URL saved without a scheme.
		$raw = 'example.com/blog';

		// ACT: Open a session for it.
		$session_id = $this->repository->create_session( $raw, 'single' );

		// ASSERT: The original value survived the insert.
		$this->assertIsInt( $session_id );
		$this->assertSame( $raw, $this->stored_url( $session_id ) );
	}

	/**
	 * Verifies that create_session refuses an empty source instead of recording
	 * a session with no identity.
	 */
	public function test_create_session_rejects_an_empty_source(): void {
		// ARRANGE: The row count to compare against.
		$before = $this->count_sessions();

		// ACT: Open a session with no source.
		$result = $this->repository->create_session( '', 'single' );

		// ASSERT: The call failed and inserted nothing.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'session_no_source_site_url',
			$result->get_error_code()
		);
		$this->assertSame( $before, $this->count_sessions() );
	}

	/**
	 * Verifies that create_session refuses a whitespace-only source, which
	 * carries no more identity than an empty one.
	 */
	public function test_create_session_rejects_a_whitespace_only_source(): void {
		// ARRANGE: The row count to compare against.
		$before = $this->count_sessions();

		// ACT: Open a session for a source that is only whitespace.
		$result = $this->repository->create_session( "  \t ", 'single' );

		// ASSERT: The call failed and inserted nothing.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			'session_no_source_site_url',
			$result->get_error_code()
		);
		$this->assertSame( $before, $this->count_sessions() );
	}

	/**
	 * Verifies that create_session stores a padded source as the identity it
	 * normalizes to, without the surrounding whitespace.
	 */
	public function test_create_session_trims_a_padded_source(): void {
		// ACT: Open a session for a connection URL stored with padding.
		$session_id = $this->repository->create_session(
			'  https://example.com/blog  ',
			'single'
		);

		// ASSERT: The stored identity carries no padding.
		$this->assertIsInt( $session_id );
		$this->assertSame(
			'https://example.com/blog',
			$this->stored_url( $session_id )
		);
	}

	/**
	 * Verifies that the backfill rewrites a row stored before write-time
	 * normalization existed.
	 */
	public function test_backfill_rewrites_a_legacy_row(): void {
		// ARRANGE: A legacy row holding a denormalized identity.
		$session_id = $this->insert_legacy_row( 'https://example.com/blog/' );

		// ACT: Run the table's migration entry point.
		Imports_Table::create_table();

		// ASSERT: The row now carries the normalized identity.
		$this->assertSame(
			'https://example.com/blog',
			$this->stored_url( $session_id )
		);
	}

	/**
	 * Verifies that the backfill is idempotent and rewrites nothing but the
	 * source identity.
	 */
	public function test_backfill_is_idempotent_and_leaves_the_row_intact(): void {
		// ARRANGE: A legacy row with a known value in every column.
		$session_id = $this->insert_legacy_row( 'https://example.com/blog/' );

		// ACT: Run the migration twice.
		Imports_Table::create_table();
		Imports_Table::create_table();

		// ASSERT: The identity settled on the normalized form and every other
		// column is untouched.
		$row = $this->stored_row( $session_id );
		$this->assertSame(
			'https://example.com/blog',
			$row['source_site_url']
		);
		$this->assertSame( 'bulk', $row['session_type'] );
		$this->assertSame( 'in_progress', $row['status'] );
		$this->assertSame( '2026-01-02 03:04:05', $row['created_at_gmt'] );
		$this->assertSame( 7, (int) $row['user_id'] );
		$this->assertSame( 'Legacy user', $row['user_display_name'] );
	}

	/**
	 * Verifies that the backfill rewrites every distinct legacy value, not
	 * just the first one it finds.
	 */
	public function test_backfill_rewrites_every_distinct_legacy_value(): void {
		// ARRANGE: Two connections' rows, both stored denormalized.
		$blog_id = $this->insert_legacy_row( 'https://example.com/blog/' );
		$news_id = $this->insert_legacy_row( 'https://example.test/news/?x=1' );

		// ACT: Run the migration.
		Imports_Table::create_table();

		// ASSERT: Both were normalized, query string included.
		$this->assertSame(
			'https://example.com/blog',
			$this->stored_url( $blog_id )
		);
		$this->assertSame(
			'https://example.test/news',
			$this->stored_url( $news_id )
		);
	}

	/**
	 * Verifies that the backfill leaves an already-normalized identity and an
	 * unparseable one alone.
	 */
	public function test_backfill_leaves_normalized_and_unparseable_values(): void {
		// ARRANGE: One row already normalized, one that cannot be parsed.
		$normalized_id  = $this->insert_legacy_row( 'https://example.test/blog' );
		$unparseable_id = $this->insert_legacy_row( 'example.com/blog' );

		// ACT: Run the migration.
		Imports_Table::create_table();

		// ASSERT: Neither value changed.
		$this->assertSame(
			'https://example.test/blog',
			$this->stored_url( $normalized_id )
		);
		$this->assertSame(
			'example.com/blog',
			$this->stored_url( $unparseable_id )
		);
	}

	/**
	 * Verifies that a completed migration records the schema version.
	 */
	public function test_completed_migration_records_the_schema_version(): void {
		// ARRANGE: No recorded version, as on an install yet to migrate.
		delete_option( self::VERSION_OPTION );

		// ACT: Run the migration.
		Imports_Table::create_table();

		// ASSERT: The version was recorded.
		$this->assertNotFalse( get_option( self::VERSION_OPTION ) );
	}

	/**
	 * Verifies that a failed backfill leaves the schema version unrecorded, so
	 * the migration is retried rather than marked complete over stale rows.
	 */
	public function test_failed_backfill_leaves_the_version_unrecorded(): void {
		// ARRANGE: A legacy row, no recorded version, and writes to the
		// imports table forced to fail.
		$session_id = $this->insert_legacy_row( 'https://example.com/blog/' );
		delete_option( self::VERSION_OPTION );
		$this->fail_table_queries( 'UPDATE' );

		// ACT: Run the migration.
		Imports_Table::create_table();

		// ASSERT: The version stayed unrecorded and the row was not rewritten.
		$this->assertFalse( get_option( self::VERSION_OPTION ) );
		$this->assertSame(
			'https://example.com/blog/',
			$this->stored_url( $session_id )
		);
	}

	/**
	 * Verifies that a failed read leaves the schema version unrecorded, so an
	 * unreadable table is not mistaken for an already-normalized one.
	 */
	public function test_failed_backfill_read_leaves_the_version_unrecorded(): void {
		// ARRANGE: No recorded version and the backfill's read forced to fail.
		delete_option( self::VERSION_OPTION );
		$this->fail_table_queries( 'SELECT' );

		// ACT: Run the migration.
		Imports_Table::create_table();

		// ASSERT: The version stayed unrecorded.
		$this->assertFalse( get_option( self::VERSION_OPTION ) );
	}

	/**
	 * Verifies that the purge removes a session recorded without a source
	 * along with its items.
	 */
	public function test_purge_removes_a_session_recorded_without_a_source(): void {
		// ARRANGE: An empty-identity session whose items are all failures.
		$session_id = $this->insert_legacy_row( '' );
		$this->insert_item( array( 'session_id' => $session_id ) );
		$this->insert_item( array( 'session_id' => $session_id ) );

		// ACT: Run the table's migration entry point.
		Imports_Table::create_table();

		// ASSERT: The session and both of its items are gone.
		$this->assertFalse( $this->session_exists( $session_id ) );
		$this->assertSame( 0, $this->item_count( $session_id ) );
	}

	/**
	 * Verifies that the purge removes a session recorded without a source that
	 * logged no items, as one abandoned before its first item would be.
	 */
	public function test_purge_removes_a_session_without_a_source_or_items(): void {
		// ARRANGE: An empty-identity session with nothing recorded under it.
		$session_id = $this->insert_legacy_row( '' );

		// ACT: Run the table's migration entry point.
		Imports_Table::create_table();

		// ASSERT: The session is gone.
		$this->assertFalse( $this->session_exists( $session_id ) );
	}

	/**
	 * Verifies that the purge keeps a session recorded without a source when it
	 * holds a success or an update, so the delete cannot reach recorded work.
	 */
	public function test_purge_keeps_a_session_without_a_source_holding_work(): void {
		// ARRANGE: Two empty-identity sessions, one holding a success and one
		// an update, each alongside a failure.
		$success_id = $this->insert_legacy_row( '' );
		$updated_id = $this->insert_legacy_row( '' );
		$this->insert_item( array( 'session_id' => $success_id ) );
		$this->insert_item(
			array(
				'session_id' => $success_id,
				'status'     => 'success',
				'post_id'    => 4242,
			)
		);
		$this->insert_item( array( 'session_id' => $updated_id ) );
		$this->insert_item(
			array(
				'session_id' => $updated_id,
				'status'     => 'updated',
				'post_id'    => 4243,
			)
		);

		// ACT: Run the table's migration entry point.
		Imports_Table::create_table();

		// ASSERT: Both sessions survived with every item, failures included.
		$this->assertTrue( $this->session_exists( $success_id ) );
		$this->assertSame( 2, $this->item_count( $success_id ) );
		$this->assertTrue( $this->session_exists( $updated_id ) );
		$this->assertSame( 2, $this->item_count( $updated_id ) );
	}

	/**
	 * Verifies that the purge leaves a session carrying a source identity
	 * alone, even when its only item is a failure.
	 */
	public function test_purge_leaves_a_session_carrying_a_source_identity(): void {
		// ARRANGE: A named-source session whose only item is a failure.
		$session_id = $this->insert_legacy_row( 'https://example.com/blog' );
		$this->insert_item( array( 'session_id' => $session_id ) );

		// ACT: Run the table's migration entry point.
		Imports_Table::create_table();

		// ASSERT: Both the session and its failure survived.
		$this->assertTrue( $this->session_exists( $session_id ) );
		$this->assertSame( 1, $this->item_count( $session_id ) );
	}

	/**
	 * Verifies that a failed purge leaves the schema version unrecorded, so the
	 * migration is retried rather than marked complete over rows it kept.
	 */
	public function test_failed_purge_leaves_the_version_unrecorded(): void {
		// ARRANGE: An empty-identity session of failures, no recorded version,
		// and deletes against the imports table forced to fail.
		$session_id = $this->insert_legacy_row( '' );
		$this->insert_item( array( 'session_id' => $session_id ) );
		delete_option( self::VERSION_OPTION );
		$this->fail_table_queries( 'DELETE' );

		// ACT: Run the migration.
		Imports_Table::create_table();

		// ASSERT: The version stayed unrecorded and the session survived. Its
		// items went first, so the failure left none of them orphaned.
		$this->assertFalse( get_option( self::VERSION_OPTION ) );
		$this->assertTrue( $this->session_exists( $session_id ) );
		$this->assertSame( 0, $this->item_count( $session_id ) );
	}

	/**
	 * Verifies that a failed purge read leaves the schema version unrecorded,
	 * so an unreadable items table is not mistaken for nothing to purge.
	 */
	public function test_failed_purge_read_leaves_the_version_unrecorded(): void {
		// ARRANGE: No recorded version and only the purge's read of the items
		// table forced to fail, leaving the backfill's own read intact.
		delete_option( self::VERSION_OPTION );
		$this->fail_table_queries( 'SELECT', Import_Items_Table::table_name() );

		// ACT: Run the migration.
		Imports_Table::create_table();

		// ASSERT: The version stayed unrecorded.
		$this->assertFalse( get_option( self::VERSION_OPTION ) );
	}

	/**
	 * Verifies that the upgrade adds the index that source-scoped queries
	 * rely on.
	 */
	public function test_upgrade_adds_the_source_identity_index(): void {
		// ARRANGE: A table without the index, as installed before the bump.
		$this->drop_index( 'source_site_url' );
		$this->assertSame( array(), $this->index_columns( 'source_site_url' ) );

		// ACT: Run the table's migration entry point.
		Imports_Table::create_table();

		// ASSERT: The key is in place and covers the identity column.
		$this->assertSame(
			array( 'source_site_url' ),
			$this->index_columns( 'source_site_url' )
		);
	}

	/**
	 * Inserts a session row without going through create_session, so the
	 * source identity is stored exactly as given.
	 *
	 * @param string $source_site_url Value to store verbatim.
	 * @return int Inserted session ID.
	 */
	private function insert_legacy_row( string $source_site_url ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Imports_Table::table_name(),
			array(
				'user_id'           => 7,
				'user_display_name' => 'Legacy user',
				'source_site_url'   => $source_site_url,
				'session_type'      => 'bulk',
				'status'            => 'in_progress',
				'ended_at_gmt'      => null,
				'created_at_gmt'    => '2026-01-02 03:04:05',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Inserts an items-table row for a session, defaulting to a failure.
	 *
	 * @param array $overrides Field overrides.
	 * @return int Item id.
	 */
	private function insert_item( array $overrides ): int {
		global $wpdb;

		$defaults = array(
			'session_id'           => 0,
			'title'                => 'Legacy item',
			'source_post_id'       => null,
			'status'               => 'error',
			'post_id'              => null,
			'error_message'        => 'Import failed',
			'content_changes'      => null,
			'warnings'             => null,
			'has_previous_content' => 0,
			'rolled_back'          => 0,
			'import_date_gmt'      => '2026-01-02 03:04:05',
			'source_modified_gmt'  => null,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Import_Items_Table::table_name(),
			array_merge( $defaults, $overrides ),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Reports whether a session row is still present.
	 *
	 * @param int $session_id Session ID.
	 * @return bool True when the row exists.
	 */
	private function session_exists( int $session_id ): bool {
		global $wpdb;

		$table = Imports_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE id = %d",
				$session_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return 0 < (int) $found;
	}

	/**
	 * Counts a session's item rows.
	 *
	 * @param int $session_id Session ID.
	 * @return int Number of item rows.
	 */
	private function item_count( int $session_id ): int {
		global $wpdb;

		$table = Import_Items_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE session_id = %d",
				$session_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * Reads a session row straight from the table.
	 *
	 * @param int $session_id Session ID.
	 * @return array<string, string> Row keyed by column name.
	 */
	private function stored_row( int $session_id ): array {
		global $wpdb;

		$table = Imports_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE id = %d",
				$session_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertIsArray( $row );

		return $row;
	}

	/**
	 * Counts the rows in the imports table.
	 *
	 * @return int Number of session rows.
	 */
	private function count_sessions(): int {
		global $wpdb;

		$table = Imports_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Reads the stored source identity for a session.
	 *
	 * @param int $session_id Session ID.
	 * @return string Stored source identity.
	 */
	private function stored_url( int $session_id ): string {
		return (string) $this->stored_row( $session_id )['source_site_url'];
	}

	/**
	 * Forces one table's statements of one kind to fail, standing in for a
	 * database error during the migration.
	 *
	 * @param string      $statement Leading SQL keyword to break, e.g. UPDATE.
	 * @param string|null $table     Table to break; defaults to imports.
	 */
	private function fail_table_queries(
		string $statement,
		?string $table = null
	): void {
		global $wpdb;

		$table ??= Imports_Table::table_name();

		$wpdb->suppress_errors( true );

		add_filter(
			'query',
			static function ( $query ) use ( $statement, $table ): string {
				$query = (string) $query;

				if ( 0 !== stripos( ltrim( $query ), $statement )
					|| false === strpos( $query, $table )
				) {
					return $query;
				}

				return "SELECT no_such_column FROM `{$table}`";
			}
		);
	}

	/**
	 * Drops an index from the imports table, ignoring an absent one.
	 *
	 * @param string $index Index name.
	 */
	private function drop_index( string $index ): void {
		global $wpdb;

		if ( array() === $this->index_columns( $index ) ) {
			return;
		}

		$table = Imports_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$index}`" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	/**
	 * Lists the columns an index covers, in key order.
	 *
	 * @param string $index Index name.
	 * @return string[] Covered column names, empty when the index is absent.
	 */
	private function index_columns( string $index ): array {
		global $wpdb;

		$table = Imports_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW INDEX FROM `{$table}` WHERE Key_name = %s",
				$index
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$columns = array();
		foreach ( $rows as $row ) {
			$columns[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
		}
		ksort( $columns );

		return array_values( $columns );
	}
}
