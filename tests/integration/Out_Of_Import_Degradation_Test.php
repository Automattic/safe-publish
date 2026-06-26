<?php
/**
 * Integration tests for the reconciliation producer contract.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Utils\Attention_Issues_Table;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Reconcile_Logger;
use WP_UnitTestCase;

/**
 * Exercises the Reconcile_Logger helpers and the matching issues-store writes —
 * for each outcome, the reconcile event plus opening or clearing the issue.
 */
class Out_Of_Import_Degradation_Test extends WP_UnitTestCase {

	/**
	 * Issues store under test.
	 *
	 * @var Attention_Issues_Repository
	 */
	private Attention_Issues_Repository $issues;

	/**
	 * Reconcile channel logger under test.
	 *
	 * @var Reconcile_Logger
	 */
	private Reconcile_Logger $logger;

	/**
	 * Creates both stores and instantiates the producer's collaborators.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'reconcile' );
		Attention_Issues_Table::create_table();

		$this->issues = new Attention_Issues_Repository();
		$this->logger = new Reconcile_Logger();
	}

	/**
	 * Verifies that an unresolved reference writes a warning to both the audit
	 * log and the issues store, and that resolving it clears both.
	 */
	public function test_unresolved_logs_warning_then_resolves(): void {
		// ARRANGE: a destination post tagged with its path-bearing source.
		$source_site_url = 'https://example.com/blog';
		$post_id         = self::factory()->post->create();
		update_post_meta(
			$post_id,
			Options::META_SOURCE_SITE_URL,
			$source_site_url
		);

		// The producer scopes the issue by the identity stored on the post.
		$stored_source = (string) get_post_meta(
			$post_id,
			Options::META_SOURCE_SITE_URL,
			true
		);

		$issue_type  = 'unmapped_block_reference';
		$target_ref  = 4242;
		$target_kind = 'post';

		// ACT: the producer logs a warning and opens the issue.
		$this->logger->unresolved(
			$issue_type,
			$post_id,
			$target_ref,
			$target_kind
		);
		$this->issues->upsert_issue(
			$post_id,
			$issue_type,
			$target_ref,
			$target_kind,
			'warning',
			$stored_source
		);

		// ASSERT: the audit log holds the reconcile warning, tagged by type.
		$warnings = Audit_Log_Table::get_events(
			array(
				'channel' => 'reconcile',
				'level'   => 'warning',
			)
		);
		$this->assertCount( 1, $warnings );
		$this->assertSame(
			Log_Events::RECONCILE_UNRESOLVED,
			$warnings[0]['event']
		);
		$this->assertSame( $issue_type, $warnings[0]['data']['issue_type'] );

		// ASSERT: the issue is open and scoped to the source identity.
		$issue = $this->issues->get_issue( $post_id, $issue_type, $target_ref );
		$this->assertIsArray( $issue );
		$this->assertSame( 'warning', $issue['severity'] );
		$this->assertSame( $source_site_url, $issue['source_site_url'] );

		// ACT: the reconciliation succeeded, so log info and clear the issue.
		$this->logger->resolved(
			$issue_type,
			$post_id,
			$target_ref,
			$target_kind
		);
		$cleared = $this->issues->resolve_issue(
			$post_id,
			$issue_type,
			$target_ref,
			$target_kind
		);

		// ASSERT: one row cleared, an info event recorded, no issue remains.
		$this->assertSame( 1, $cleared );
		$info = Audit_Log_Table::get_events(
			array(
				'channel' => 'reconcile',
				'level'   => 'info',
			)
		);
		$this->assertCount( 1, $info );
		$this->assertSame( Log_Events::RECONCILE_RESOLVED, $info[0]['event'] );
		$this->assertNull(
			$this->issues->get_issue( $post_id, $issue_type, $target_ref )
		);
	}

	/**
	 * Verifies that a failed reconciliation write logs a reconcile error and
	 * opens an error-severity issue.
	 */
	public function test_failed_write_logs_error_and_opens_error_issue(): void {
		// ARRANGE: a destination post tagged with its source identity.
		$source_site_url = 'https://example.com/blog';
		$post_id         = self::factory()->post->create();
		update_post_meta(
			$post_id,
			Options::META_SOURCE_SITE_URL,
			$source_site_url
		);

		$issue_type  = 'nav_ref_rewrite_failed';
		$target_ref  = 4242;
		$target_kind = 'post';

		// ACT: the write failed, so log an error and open an error issue.
		$this->logger->failed(
			$issue_type,
			$post_id,
			$target_ref,
			$target_kind,
			'db write failed'
		);
		$this->issues->upsert_issue(
			$post_id,
			$issue_type,
			$target_ref,
			$target_kind,
			'error',
			$source_site_url
		);

		// ASSERT: the audit log holds the reconcile error event.
		$errors = Audit_Log_Table::get_events(
			array(
				'channel' => 'reconcile',
				'level'   => 'error',
			)
		);
		$this->assertCount( 1, $errors );
		$this->assertSame( Log_Events::RECONCILE_FAILED, $errors[0]['event'] );

		// ASSERT: the issue is open at error severity.
		$issue = $this->issues->get_issue( $post_id, $issue_type, $target_ref );
		$this->assertIsArray( $issue );
		$this->assertSame( 'error', $issue['severity'] );
	}
}
