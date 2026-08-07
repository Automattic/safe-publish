<?php
/**
 * Integration tests for the attention issues table schema upgrade.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Utils\Attention_Issues_Table;

/**
 * Exercises the v2 to v3 upgrade: The slug column lands, the identity key is
 * rebuilt to include it, and rows written under v2 survive.
 */
class Attention_Issues_Schema_Test extends Integration_Test_Case {

	/**
	 * Source identity used across the tests.
	 */
	private const SOURCE_URL = 'https://source.example.com/blog';

	/**
	 * Columns the v3 identity key covers, spelled out independently of the
	 * production constant so a change to either has to be deliberate.
	 *
	 * @var string[]
	 */
	private const EXPECTED_IDENTITY_COLUMNS = array(
		'affected_post_id',
		'issue_type',
		'target_ref',
		'target_kind',
		'target_slug',
	);

	/**
	 * Attention issues repository under test.
	 *
	 * @var Attention_Issues_Repository
	 */
	private Attention_Issues_Repository $attention;

	/**
	 * Set up test environment.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->attention = new Attention_Issues_Repository();
	}

	/**
	 * Verifies that upgrading a v2 table adds target_slug, widens target_kind,
	 * and rebuilds the identity key across all five columns.
	 */
	public function test_upgrade_from_v2_lands_the_v3_shape(): void {
		// ARRANGE: A table at the v2 shape.
		$this->create_v2_table();

		// ACT: Run the upgrade.
		Attention_Issues_Table::create_table();

		// ASSERT: The slug column exists, target_kind is widened, and the
		// identity key covers the full five-column tuple.
		$columns = $this->column_types();
		$this->assertSame( 'varchar(100)', $columns['target_slug'] ?? '' );
		$this->assertSame( 'varchar(16)', $columns['target_kind'] ?? '' );
		$this->assertSame(
			self::EXPECTED_IDENTITY_COLUMNS,
			$this->identity_columns()
		);
	}

	/**
	 * Verifies that rows written under v2 survive the upgrade with an empty
	 * target_slug, so no dedup pass is needed.
	 */
	public function test_upgrade_preserves_existing_rows(): void {
		// ARRANGE: A v2 table holding one row.
		$this->create_v2_table();
		$this->insert_v2_row( 4001, 'nav_ref_rewrite_failed', 7001, 'post' );

		// ACT: Run the upgrade.
		Attention_Issues_Table::create_table();

		// ASSERT: The row is intact and its slug defaulted to empty.
		$issue = $this->attention->get_issue(
			4001,
			'nav_ref_rewrite_failed',
			7001,
			'post'
		);
		$this->assertNotNull( $issue );
		$this->assertSame( '', $issue['target_slug'] );
		$this->assertSame(
			1,
			$this->attention->count_open_issues( self::SOURCE_URL )
		);
	}

	/**
	 * Verifies that the upgraded key admits two slug-keyed rows that share a
	 * post, type, and zero target_ref — the collision v2 could not hold.
	 */
	public function test_upgraded_key_admits_two_slug_keyed_rows(): void {
		// ARRANGE: A v2 table upgraded in place.
		$this->create_v2_table();
		Attention_Issues_Table::create_table();

		// ACT: Upsert two issues that differ only by slug.
		foreach ( array( 'genre', 'mood' ) as $slug ) {
			$this->attention->upsert_issue(
				4002,
				'unregistered_taxonomy',
				0,
				'taxonomy',
				'warning',
				self::SOURCE_URL,
				array(),
				$slug
			);
		}

		// ASSERT: Both rows are open and independently addressable.
		$this->assertSame(
			2,
			$this->attention->count_open_issues( self::SOURCE_URL )
		);
		foreach ( array( 'genre', 'mood' ) as $slug ) {
			$this->assertNotNull(
				$this->attention->get_issue(
					4002,
					'unregistered_taxonomy',
					0,
					'taxonomy',
					$slug
				)
			);
		}
	}

	/**
	 * Verifies that the upgrade is idempotent: A second run leaves the identity
	 * key in place rather than dropping it.
	 */
	public function test_upgrade_is_idempotent(): void {
		// ARRANGE: A table already upgraded to v3.
		$this->create_v2_table();
		Attention_Issues_Table::create_table();

		// ACT: Run the upgrade again.
		Attention_Issues_Table::create_table();

		// ASSERT: The identity key still covers the five-column tuple.
		$this->assertSame(
			self::EXPECTED_IDENTITY_COLUMNS,
			$this->identity_columns()
		);
	}

	/**
	 * Replaces the table with its v2 shape: no target_slug, narrow target_kind,
	 * and the four-column identity key.
	 */
	private function create_v2_table(): void {
		global $wpdb;

		$table   = Attention_Issues_Table::table_name();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		$wpdb->query(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				affected_post_id BIGINT UNSIGNED NOT NULL,
				issue_type VARCHAR(40) NOT NULL,
				target_ref BIGINT UNSIGNED NOT NULL,
				target_kind VARCHAR(8) NOT NULL,
				severity VARCHAR(8) NOT NULL,
				source_site_url VARCHAR(255) NOT NULL,
				detail LONGTEXT NULL DEFAULT NULL,
				first_detected_gmt DATETIME NOT NULL,
				last_seen_gmt DATETIME NOT NULL,
				status VARCHAR(10) NOT NULL DEFAULT 'open',
				ignored_gmt DATETIME NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY issue_identity (affected_post_id, issue_type, target_ref, target_kind),
				KEY source_status (source_site_url, status),
				KEY target_lookup (issue_type, target_ref, source_site_url)
			) {$charset};"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		delete_option( 'safe_publish_attention_issues_version' );
	}

	/**
	 * Inserts one open row using the v2 column set.
	 *
	 * @param int    $affected_post_id Destination post id.
	 * @param string $issue_type       Issue type.
	 * @param int    $target_ref       Source id of the target.
	 * @param string $target_kind      Target kind.
	 */
	private function insert_v2_row(
		int $affected_post_id,
		string $issue_type,
		int $target_ref,
		string $target_kind
	): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Attention_Issues_Table::table_name(),
			array(
				'affected_post_id'   => $affected_post_id,
				'issue_type'         => $issue_type,
				'target_ref'         => $target_ref,
				'target_kind'        => $target_kind,
				'severity'           => 'error',
				'source_site_url'    => self::SOURCE_URL,
				'detail'             => '{}',
				'first_detected_gmt' => $now,
				'last_seen_gmt'      => $now,
				'status'             => 'open',
			)
		);
	}

	/**
	 * Reads the table's column types keyed by column name.
	 *
	 * @return array<string, string> Column name to lowercased SQL type.
	 */
	private function column_types(): array {
		global $wpdb;

		$table = Attention_Issues_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$types = array();
		foreach ( (array) $rows as $row ) {
			$types[ (string) $row['Field'] ] = strtolower( (string) $row['Type'] );
		}

		return $types;
	}

	/**
	 * Reads the columns covered by the identity key, in order.
	 *
	 * @return string[] Column names.
	 */
	private function identity_columns(): array {
		global $wpdb;

		$table = Attention_Issues_Table::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SHOW INDEX FROM `{$table}` WHERE Key_name = 'issue_identity'",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$columns = array();
		foreach ( (array) $rows as $row ) {
			$columns[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
		}
		ksort( $columns );

		return array_values( $columns );
	}
}
