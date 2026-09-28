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
 * stops injecting, one that injects too widely, or a restore that stops
 * cleaning up, fails here rather than silently turning those tests green.
 */
class Failing_Query_Trait_Test extends Integration_Test_Case {

	use Failing_Query_Trait;

	/**
	 * Fingerprint matching only the read in stored_type, chosen so it cannot
	 * also catch the metadata SQL wpdb issues before a write.
	 */
	private const READ_FINGERPRINT = 'SELECT session_type FROM';

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

		$this->restore_failing_queries();
		$wpdb->suppress_errors( false );

		parent::tearDown();
	}

	/**
	 * Prior error suppression states a test could be holding.
	 *
	 * @return array<string, array{bool}>
	 */
	public function suppression_provider(): array {
		return array(
			'errors reported'   => array( false ),
			'errors suppressed' => array( true ),
		);
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
	 * Verifies that fail_table_queries leaves every query it did not select
	 * alone, so a test cannot mistake a broken harness for the failure it
	 * meant to force.
	 */
	public function test_fail_table_queries_spares_other_queries(): void {
		// ARRANGE: A session and a post, with only the imports table's
		// UPDATEs forced to fail.
		$session_id = $this->seed_session();
		$post_id    = $this->factory()->post->create();
		$this->assertIsInt( $post_id );
		$this->fail_table_queries( 'UPDATE', Imports_Table::table_name() );

		// ACT: Run a read of the same table and a write of another one, both
		// of which the matcher should ignore.
		$read    = $this->stored_type( $session_id );
		$written = wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Untouched',
			)
		);

		// ASSERT: The other statement kind on the named table survived.
		$this->assertSame( 'bulk', $read );

		// ASSERT: The same statement kind on another table survived.
		$this->assertSame( $post_id, $written );
		$this->assertSame( 'Untouched', get_post( $post_id )->post_title );
	}

	/**
	 * Verifies that fail_queries_matching breaks every query carrying the
	 * fingerprint, spares the rest, and stops once restored.
	 */
	public function test_fail_queries_matching_breaks_only_until_restored(): void {
		// ARRANGE: A session row, with only its read fingerprinted.
		$session_id = $this->seed_session();
		$this->fail_queries_matching( self::READ_FINGERPRINT );

		// ACT: Read through the injection, write past it, then restore and
		// read again.
		$blocked = $this->stored_type( $session_id );
		$written = $this->retype_session( $session_id, 'single' );
		$this->restore_failing_queries();
		$allowed = $this->stored_type( $session_id );

		// ASSERT: The fingerprinted read was broken.
		$this->assertNull( $blocked );

		// ASSERT: A query without the fingerprint was left alone.
		$this->assertSame( 1, $written );

		// ASSERT: The read came back whole, showing what the write stored.
		$this->assertSame( 'single', $allowed );
	}

	/**
	 * Verifies that restoring hands error suppression back at the value the
	 * test held, so a forced failure cannot mute genuine errors later.
	 *
	 * @dataProvider suppression_provider
	 *
	 * @param bool $prior Error suppression the test starts out holding.
	 */
	public function test_restoring_returns_prior_error_suppression(
		bool $prior
	): void {
		// ARRANGE: A known suppression state, set rather than read, so a leak
		// from an earlier test cannot make this pass for the wrong reason.
		global $wpdb;
		$wpdb->suppress_errors( $prior );

		// ACT: Register two failures, then restore once.
		$this->fail_table_queries( 'UPDATE', Imports_Table::table_name() );
		$this->fail_queries_matching( 'no_such_fingerprint' );
		$suppressed = $wpdb->suppress_errors;
		$this->restore_failing_queries();

		// ASSERT: Suppression was turned on for the injection.
		$this->assertTrue( $suppressed );

		// ASSERT: It came back at the value the test held, not a fixed one.
		$this->assertSame( $prior, $wpdb->suppress_errors );
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
	private function retype_session(
		int $session_id,
		string $type
	): int|false {
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
