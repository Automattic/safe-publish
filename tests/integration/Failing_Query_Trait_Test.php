<?php
/**
 * Integration tests for the forced query failure harness
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Utils\Imports_Table;

/**
 * Exercises the harness the failure-path tests rely on, so a matcher that
 * stops injecting, or a restore that stops cleaning up, fails here rather
 * than silently turning those tests green.
 */
class Failing_Query_Trait_Test extends Integration_Test_Case {

	use Failing_Query_Trait;

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
		$this->restore_failing_queries();

		parent::tearDown();
	}

	/**
	 * Verifies that fail_table_queries breaks the statements it selects and
	 * that restoring lets the same statement succeed again.
	 */
	public function test_fail_table_queries_breaks_only_until_restored(): void {
		// ARRANGE: A session row, with the imports table's UPDATEs forced to
		// fail.
		$session_id = $this->seed_session();
		$this->fail_table_queries( 'UPDATE', Imports_Table::table_name() );

		// ACT: Write through the injection, then restore and write again.
		$blocked = $this->retype_session( $session_id, 'blocked' );
		$this->restore_failing_queries();
		$allowed = $this->retype_session( $session_id, 'single' );

		// ASSERT: The first write was rejected.
		$this->assertFalse( $blocked );

		// ASSERT: The second landed, so the restore really cleaned up.
		$this->assertSame( 1, $allowed );
		$this->assertSame( 'single', $this->stored_type( $session_id ) );
	}

	/**
	 * Verifies that fail_queries_matching breaks every query carrying the
	 * fingerprint and that restoring lets them through again.
	 */
	public function test_fail_queries_matching_breaks_only_until_restored(): void {
		// ARRANGE: A session row, with its own table name as the fingerprint
		// so reads of it are selected whatever their statement kind.
		$session_id = $this->seed_session();
		$this->fail_queries_matching( Imports_Table::table_name() );

		// ACT: Read through the injection, then restore and read again.
		$blocked = $this->stored_type( $session_id );
		$this->restore_failing_queries();
		$allowed = $this->stored_type( $session_id );

		// ASSERT: The read was broken, then whole again.
		$this->assertNull( $blocked );
		$this->assertSame( 'bulk', $allowed );
	}

	/**
	 * Verifies that restoring returns error suppression to its prior value,
	 * so a forced failure cannot mute genuine errors in later tests.
	 */
	public function test_restoring_returns_error_suppression(): void {
		// ARRANGE: Suppression explicitly off, so a leak from an earlier test
		// cannot let these assertions pass for the wrong reason.
		global $wpdb;
		$wpdb->suppress_errors( false );

		// ACT: Register two failures, then restore once.
		$this->fail_table_queries( 'UPDATE', Imports_Table::table_name() );
		$this->fail_queries_matching( 'no_such_fingerprint' );
		$suppressed = $wpdb->suppress_errors;
		$this->restore_failing_queries();

		// ASSERT: Suppression was turned on, then handed back off.
		$this->assertTrue( $suppressed );
		$this->assertFalse( $wpdb->suppress_errors );
	}

	/**
	 * Creates a session row for these tests to read and write.
	 */
	private function seed_session(): int {
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$this->assertIsInt( $session_id );

		return $session_id;
	}

	/**
	 * Rewrites a session's type, returning what wpdb reported.
	 *
	 * @param int    $session_id Session to rewrite.
	 * @param string $type       Session type to store.
	 *
	 * @return int|false Rows updated, or false when the write was rejected.
	 */
	private function retype_session( int $session_id, string $type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->update(
			Imports_Table::table_name(),
			array( 'session_type' => $type ),
			array( 'id' => $session_id )
		);
	}

	/**
	 * Reads a session's stored type, or null when the read was rejected.
	 *
	 * @param int $session_id Session to read.
	 */
	private function stored_type( int $session_id ): ?string {
		global $wpdb;

		$table = Imports_Table::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$type = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT session_type FROM `{$table}` WHERE id = %d",
				$session_id
			)
		);

		return null === $type ? null : (string) $type;
	}
}
