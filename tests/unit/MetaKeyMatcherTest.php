<?php
/**
 * Meta Key Matcher test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Utils\Meta_Key_Matcher;

/**
 * Tests how Meta_Key_Matcher compares post meta keys.
 */
class MetaKeyMatcherTest extends TestCase {

	/**
	 * Verifies that on SQLite only an ASCII case variant matches, as SQLite
	 * compares keys, and that no query runs.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_matches_folds_only_ascii_case_on_sqlite(): void {
		// ARRANGE: The SQLite driver, faked to count the queries it runs.
		$database = new class() {
			/**
			 * Postmeta table name.
			 *
			 * @var string
			 */
			public string $postmeta = 'wp_postmeta';

			/**
			 * Number of queries run.
			 *
			 * @var int
			 */
			public int $queries = 0;

			/**
			 * Returns an empty query.
			 *
			 * @return string Query.
			 */
			public function prepare(): string {
				return '';
			}

			/**
			 * Counts a row query, which returns no row.
			 *
			 * @return null No row.
			 */
			public function get_row() {
				++$this->queries;
				return null;
			}

			/**
			 * Counts a value query, which fails.
			 *
			 * @return null No value.
			 */
			public function get_var() {
				++$this->queries;
				return null;
			}
		};
		class_alias( get_class( $database ), 'WP_SQLite_DB' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wpdb'] = $database;

		// ACT: Match variants SQLite reads as distinct keys, and one it folds.
		$matched = array_filter(
			array(
				'_Thumbnail_ID',
				'_thumbnaíl_id',
				'_thumbnail_id ',
				'größe',
			),
			static fn( string $key ): bool => Meta_Key_Matcher::matches(
				$key,
				array( '_thumbnail_id' )
			)
		);

		// ASSERT: Only the ASCII case variant matches, without a query.
		$this->assertSame( array( '_Thumbnail_ID' ), array_values( $matched ) );
		$this->assertSame( 0, $database->queries );
	}
}
