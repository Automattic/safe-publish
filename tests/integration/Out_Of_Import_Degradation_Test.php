<?php
/**
 * Integration tests for the out-of-import degradation producer contract.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Utils\Attention_Issues_Table;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Navigation_Logger;
use Safe_Publish\Utils\Options;
use WP_UnitTestCase;

/**
 * Exercises the producer contract an out-of-import fixup must follow: a
 * degradation logs a navigation warning and opens an attention issue, and
 * resolving the fixup logs an info event and clears the issue.
 */
class Out_Of_Import_Degradation_Test extends WP_UnitTestCase {

	/**
	 * Issues store under test.
	 *
	 * @var Attention_Issues_Repository
	 */
	private Attention_Issues_Repository $issues;

	/**
	 * Navigation channel logger under test.
	 *
	 * @var Navigation_Logger
	 */
	private Navigation_Logger $logger;

	/**
	 * Creates both stores and instantiates the producer's collaborators.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'navigation' );
		Attention_Issues_Table::create_table();

		$this->issues = new Attention_Issues_Repository();
		$this->logger = new Navigation_Logger();
	}

	/**
	 * Verifies that an out-of-import degradation writes to both the audit log
	 * and the issues store, and that resolving the fixup clears both.
	 */
	public function test_degradation_logs_warning_and_opens_issue_then_resolves(): void {
		// ARRANGE: a destination post tagged with its path-bearing source.
		$source_site_url = 'https://example.com/blog';
		$post_id         = self::factory()->post->create();
		update_post_meta(
			$post_id,
			Options::META_SOURCE_SITE_URL,
			$source_site_url
		);

		// The navigation URL reconciler owns the real issue type; this literal
		// stands in for it to exercise the shared contract.
		$issue_type = 'nav_url_unresolved';
		$target_ref = 4242;
		$source_url = 'https://example.com/blog/about';

		// The producer scopes the issue by the identity stored on the post.
		$stored_source = (string) get_post_meta(
			$post_id,
			Options::META_SOURCE_SITE_URL,
			true
		);

		// ACT: the producer logs a warning and opens the issue.
		$this->logger->link_unresolved( $post_id, $target_ref, $source_url );
		$this->issues->upsert_issue(
			$post_id,
			$issue_type,
			$target_ref,
			'post',
			'warning',
			$stored_source
		);

		// ASSERT: the audit log holds the navigation warning.
		$warnings = Audit_Log_Table::get_events(
			array(
				'channel' => 'navigation',
				'level'   => 'warning',
			)
		);
		$this->assertCount( 1, $warnings );
		$this->assertSame(
			Log_Events::NAV_LINK_UNRESOLVED,
			$warnings[0]['event']
		);

		// ASSERT: the issue is open and scoped to the source identity.
		$issue = $this->issues->get_issue( $post_id, $issue_type, $target_ref );
		$this->assertIsArray( $issue );
		$this->assertSame( 'warning', $issue['severity'] );
		$this->assertSame( $source_site_url, $issue['source_site_url'] );

		// ACT: the fixup succeeded, so log info and clear the issue.
		$this->logger->link_resolved( $post_id, $target_ref, $source_url );
		$resolved = $this->issues->resolve_issue(
			$post_id,
			$issue_type,
			$target_ref,
			'post'
		);

		// ASSERT: one row cleared, an info event recorded, no issue remains.
		$this->assertSame( 1, $resolved );
		$info = Audit_Log_Table::get_events(
			array(
				'channel' => 'navigation',
				'level'   => 'info',
			)
		);
		$this->assertCount( 1, $info );
		$this->assertSame( Log_Events::NAV_LINK_RESOLVED, $info[0]['event'] );
		$this->assertNull(
			$this->issues->get_issue( $post_id, $issue_type, $target_ref )
		);
	}

	/**
	 * Verifies that a failed fixup write logs a navigation error event and
	 * opens an error-severity issue.
	 */
	public function test_rewrite_failure_logs_error_and_opens_error_issue(): void {
		// ARRANGE: a destination post tagged with its source identity.
		$source_site_url = 'https://example.com/blog';
		$post_id         = self::factory()->post->create();
		update_post_meta(
			$post_id,
			Options::META_SOURCE_SITE_URL,
			$source_site_url
		);

		$issue_type = 'nav_url_unresolved';
		$target_ref = 4242;
		$source_url = 'https://example.com/blog/about';

		// ACT: the fixup write failed, so log an error and open an error issue.
		$this->logger->link_rewrite_failed(
			$post_id,
			$target_ref,
			$source_url,
			'db write failed'
		);
		$this->issues->upsert_issue(
			$post_id,
			$issue_type,
			$target_ref,
			'post',
			'error',
			$source_site_url
		);

		// ASSERT: the audit log holds the navigation error event.
		$errors = Audit_Log_Table::get_events(
			array(
				'channel' => 'navigation',
				'level'   => 'error',
			)
		);
		$this->assertCount( 1, $errors );
		$this->assertSame(
			Log_Events::NAV_LINK_REWRITE_FAILED,
			$errors[0]['event']
		);

		// ASSERT: the issue is open at error severity.
		$issue = $this->issues->get_issue( $post_id, $issue_type, $target_ref );
		$this->assertIsArray( $issue );
		$this->assertSame( 'error', $issue['severity'] );
	}
}
