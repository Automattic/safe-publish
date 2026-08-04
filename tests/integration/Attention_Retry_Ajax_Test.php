<?php
/**
 * Integration tests for the attention-issue retry AJAX endpoint.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Admin_Ajax_Controller;
use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Utils\Attention_Issues_Table;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Options;
use WP_Ajax_UnitTestCase;

/**
 * Exercises the controller glue for the attention-issue endpoints: retry type
 * dispatch, target_kind threading, the retryable allowlist, the capability
 * gate, the list payload's reusable-block and resolvable flags, and bulk retry
 * aggregation. The reconciliations themselves are covered in
 * Attention_Issues_Test.
 */
class Attention_Retry_Ajax_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Source identity the issues and targets are scoped to.
	 */
	private const SOURCE = 'https://source.example.com';

	/**
	 * Attention issues repository for seeding and reading rows.
	 *
	 * @var Attention_Issues_Repository
	 */
	private Attention_Issues_Repository $attention;

	/**
	 * Sets up the attention table, an admin user, and the connected source.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Attention_Issues_Table::create_table();
		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'reconcile' );
		$this->attention = new Attention_Issues_Repository();

		wp_set_current_user(
			$this->factory()->user->create( array( 'role' => 'administrator' ) )
		);
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE );
	}

	/**
	 * Removes the connected source option.
	 */
	#[\Override]
	protected function tearDown(): void {
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Verifies that retrying an unmapped block reference dispatches the block
	 * repoint, resolves the issue, and reports a resolved outcome.
	 */
	public function test_retry_unmapped_block_reference_dispatches_and_resolves(): void {
		// ARRANGE: a post with a stale post-type ref, its now-present target, and
		// the open issue.
		$post_id = self::factory()->post->create(
			array( 'post_content' => $this->nav_link_content( 9700, 'post-type' ) )
		);
		$this->seed_target_post( 9700 );
		$this->open_issue( $post_id, 'unmapped_block_reference', 9700, 'post' );

		// ACT: retry through the endpoint.
		$response = $this->retry(
			array(
				'affected_post_id' => (string) $post_id,
				'issue_type'       => 'unmapped_block_reference',
				'target_ref'       => '9700',
				'target_kind'      => 'post',
			)
		);

		// ASSERT: the endpoint reports a resolved outcome with no detail and
		// the row is gone.
		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['resolved'] );
		$this->assertSame( 'resolved', $response['data']['outcome'] );
		$this->assertSame( '', $response['data']['detail'] );
		$this->assertNull(
			$this->attention->get_issue(
				$post_id,
				'unmapped_block_reference',
				9700,
				'post'
			)
		);
	}

	/**
	 * Verifies that retrying an unmapped gallery reference dispatches the
	 * gallery remap, rewrites the shortcode id in place, and resolves the issue.
	 */
	public function test_retry_unmapped_gallery_reference_dispatches_and_resolves(): void {
		// ARRANGE: A post whose [gallery id] points at a now-present source
		// post, and the open issue.
		$post_id = self::factory()->post->create(
			array( 'post_content' => '[gallery id="9700"]' )
		);
		$dest_id = $this->seed_target_post( 9700 );
		$this->open_issue( $post_id, 'unmapped_gallery_reference', 9700, 'post' );

		// ACT: Retry through the endpoint.
		$response = $this->retry(
			array(
				'affected_post_id' => (string) $post_id,
				'issue_type'       => 'unmapped_gallery_reference',
				'target_ref'       => '9700',
				'target_kind'      => 'post',
			)
		);

		// ASSERT: Resolved, the row is gone, and the shortcode holds the dest
		// id — proving the dispatch routed to the gallery remap.
		$this->assertTrue( $response['data']['resolved'] );
		$this->assertSame( 'resolved', $response['data']['outcome'] );
		$this->assertNull(
			$this->attention->get_issue(
				$post_id,
				'unmapped_gallery_reference',
				9700,
				'post'
			)
		);
		$this->assertStringContainsString(
			'[gallery id="' . $dest_id . '"]',
			(string) get_post_field( 'post_content', $post_id )
		);
	}

	/**
	 * Verifies that retrying an orphaned parent dispatches the parent re-link,
	 * setting post_parent and resolving the issue.
	 */
	public function test_retry_parent_orphaned_dispatches_and_resolves(): void {
		// ARRANGE: a top-level child page, its now-present source parent, and the
		// open issue.
		$child_id  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => 0,
			)
		);
		$parent_id = $this->seed_target_post( 9850 );
		$this->open_issue( $child_id, 'parent_orphaned', 9850, 'post' );

		// ACT: retry through the endpoint.
		$response = $this->retry(
			array(
				'affected_post_id' => (string) $child_id,
				'issue_type'       => 'parent_orphaned',
				'target_ref'       => '9850',
				'target_kind'      => 'post',
			)
		);

		// ASSERT: the parent is linked and the row is gone — proving the dispatch
		// routed to the parent reconciliation, not the block one.
		$this->assertTrue( $response['data']['resolved'] );
		$this->assertSame(
			$parent_id,
			(int) get_post_field( 'post_parent', $child_id )
		);
		$this->assertNull(
			$this->attention->get_issue( $child_id, 'parent_orphaned', 9850, 'post' )
		);
	}

	/**
	 * Verifies that the request's target_kind selects the row to act on when a
	 * post and a term reference share a target_ref.
	 */
	public function test_retry_threads_target_kind_to_matching_row(): void {
		// ARRANGE: a post holding both refs, both targets present, both issues
		// open.
		$post_id = self::factory()->post->create(
			array( 'post_content' => $this->two_kind_nav_content( 9042 ) )
		);
		$this->seed_target_post( 9042 );
		$this->seed_target_term( 9042 );
		$this->open_issue( $post_id, 'unmapped_block_reference', 9042, 'post' );
		$this->open_issue( $post_id, 'unmapped_block_reference', 9042, 'term' );

		// ACT: retry only the post kind.
		$response = $this->retry(
			array(
				'affected_post_id' => (string) $post_id,
				'issue_type'       => 'unmapped_block_reference',
				'target_ref'       => '9042',
				'target_kind'      => 'post',
			)
		);

		// ASSERT: only the post row resolved; the term row stays.
		$this->assertTrue( $response['data']['resolved'] );
		$this->assertNull(
			$this->attention->get_issue(
				$post_id,
				'unmapped_block_reference',
				9042,
				'post'
			)
		);
		$this->assertNotNull(
			$this->attention->get_issue(
				$post_id,
				'unmapped_block_reference',
				9042,
				'term'
			)
		);
	}

	/**
	 * Verifies that an unrecognized issue type is rejected.
	 */
	public function test_retry_rejects_unknown_issue_type(): void {
		// ACT: retry with a type outside the retryable allowlist.
		$response = $this->retry(
			array(
				'affected_post_id' => '1',
				'issue_type'       => 'not_a_real_type',
				'target_ref'       => '1',
				'target_kind'      => 'post',
			)
		);

		// ASSERT: rejected before any reconciliation runs.
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Verifies that an invalid target_kind is rejected.
	 */
	public function test_retry_rejects_invalid_target_kind(): void {
		// ACT: retry with a kind outside the post/term whitelist.
		$response = $this->retry(
			array(
				'affected_post_id' => '1',
				'issue_type'       => 'unmapped_block_reference',
				'target_ref'       => '1',
				'target_kind'      => 'taxonomy',
			)
		);

		// ASSERT: rejected before any reconciliation runs.
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Verifies that a user without edit_posts cannot retry.
	 */
	public function test_retry_rejects_user_without_edit_posts(): void {
		// ARRANGE: an open issue and a subscriber-level user.
		$post_id = self::factory()->post->create(
			array( 'post_content' => $this->nav_link_content( 9700, 'post-type' ) )
		);
		$this->seed_target_post( 9700 );
		$this->open_issue( $post_id, 'unmapped_block_reference', 9700, 'post' );
		wp_set_current_user(
			$this->factory()->user->create( array( 'role' => 'subscriber' ) )
		);

		// ACT: retry as the subscriber.
		$response = $this->retry(
			array(
				'affected_post_id' => (string) $post_id,
				'issue_type'       => 'unmapped_block_reference',
				'target_ref'       => '9700',
				'target_kind'      => 'post',
			)
		);

		// ASSERT: forbidden, and the issue is untouched.
		$this->assertFalse( $response['success'] );
		$this->assertNotNull(
			$this->attention->get_issue(
				$post_id,
				'unmapped_block_reference',
				9700,
				'post'
			)
		);
	}

	/**
	 * Verifies that a successful retry records a reconcile info event tagged with
	 * the issue type, completing the dual-write contract.
	 */
	public function test_retry_logs_reconcile_resolved_event(): void {
		// ARRANGE: a resolvable block reference and its open issue.
		$post_id = self::factory()->post->create(
			array( 'post_content' => $this->nav_link_content( 9700, 'post-type' ) )
		);
		$this->seed_target_post( 9700 );
		$this->open_issue( $post_id, 'unmapped_block_reference', 9700, 'post' );

		// ACT: retry through the endpoint.
		$response = $this->retry(
			array(
				'affected_post_id' => (string) $post_id,
				'issue_type'       => 'unmapped_block_reference',
				'target_ref'       => '9700',
				'target_kind'      => 'post',
			)
		);

		// ASSERT: resolved, and a reconcile info event names the issue type.
		$this->assertTrue( $response['data']['resolved'] );
		$events = Audit_Log_Table::get_events(
			array( 'channel' => 'reconcile' )
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'info', $events[0]['level'] );
		$this->assertSame( Log_Events::RECONCILE_RESOLVED, $events[0]['event'] );
		$this->assertSame(
			'unmapped_block_reference',
			$events[0]['data']['issue_type']
		);
	}

	/**
	 * Verifies that retrying a block reference whose target was never imported
	 * returns a target_absent outcome and records a target-absent warning
	 * instead of clearing the issue.
	 */
	public function test_target_absent_block_retry_logs_target_absent(): void {
		// ARRANGE: a stale ref whose target was never imported.
		$post_id = self::factory()->post->create(
			array( 'post_content' => $this->nav_link_content( 9999, 'post-type' ) )
		);
		$this->open_issue( $post_id, 'unmapped_block_reference', 9999, 'post' );

		// ACT: retry; the target is absent, so the issue stays open.
		$response = $this->retry(
			array(
				'affected_post_id' => (string) $post_id,
				'issue_type'       => 'unmapped_block_reference',
				'target_ref'       => '9999',
				'target_kind'      => 'post',
			)
		);

		// ASSERT: a target_absent outcome with a detail, and a reconcile
		// target-absent warning was recorded.
		$this->assertFalse( $response['data']['resolved'] );
		$this->assertSame( 'target_absent', $response['data']['outcome'] );
		$this->assertNotSame( '', $response['data']['detail'] );
		$events = Audit_Log_Table::get_events(
			array(
				'channel' => 'reconcile',
				'level'   => 'warning',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame(
			Log_Events::RECONCILE_TARGET_ABSENT,
			$events[0]['event']
		);
	}

	/**
	 * Verifies that retrying a block reference whose target is present but no
	 * longer appears in the post content returns an unresolved outcome and
	 * records a reconcile unresolved warning.
	 */
	public function test_unresolved_block_retry_logs_unresolved(): void {
		// ARRANGE: the target is importable, but the post content holds no
		// matching reference.
		$post_id = self::factory()->post->create(
			array( 'post_content' => 'No navigation block here.' )
		);
		$this->seed_target_post( 9700 );
		$this->open_issue( $post_id, 'unmapped_block_reference', 9700, 'post' );

		// ACT: retry through the endpoint.
		$response = $this->retry(
			array(
				'affected_post_id' => (string) $post_id,
				'issue_type'       => 'unmapped_block_reference',
				'target_ref'       => '9700',
				'target_kind'      => 'post',
			)
		);

		// ASSERT: an unresolved outcome with a detail, and a plain reconcile
		// unresolved warning recorded.
		$this->assertFalse( $response['data']['resolved'] );
		$this->assertSame( 'unresolved', $response['data']['outcome'] );
		$this->assertNotSame( '', $response['data']['detail'] );
		$events = Audit_Log_Table::get_events(
			array(
				'channel' => 'reconcile',
				'level'   => 'warning',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame(
			Log_Events::RECONCILE_UNRESOLVED,
			$events[0]['event']
		);
	}

	/**
	 * Verifies that retrying an error-severity nav issue whose menu is absent
	 * records a target-absent warning, not an error, since no write was
	 * attempted.
	 */
	public function test_target_absent_nav_retry_logs_target_absent_not_error(): void {
		// ARRANGE: an error-severity nav issue whose menu is absent, so the retry
		// has nothing to reconcile and the row stays.
		$post_id = self::factory()->post->create();
		$this->attention->upsert_issue(
			$post_id,
			'nav_ref_rewrite_failed',
			9300,
			'post',
			'error',
			self::SOURCE
		);

		// ACT: retry through the endpoint.
		$response = $this->retry(
			array(
				'affected_post_id' => (string) $post_id,
				'issue_type'       => 'nav_ref_rewrite_failed',
				'target_ref'       => '9300',
				'target_kind'      => 'post',
			)
		);

		// ASSERT: unresolved, no error event recorded, and a target-absent
		// warning recorded instead.
		$this->assertFalse( $response['data']['resolved'] );
		$this->assertCount(
			0,
			Audit_Log_Table::get_events(
				array(
					'channel' => 'reconcile',
					'level'   => 'error',
				)
			)
		);
		$events = Audit_Log_Table::get_events(
			array(
				'channel' => 'reconcile',
				'level'   => 'warning',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame(
			Log_Events::RECONCILE_TARGET_ABSENT,
			$events[0]['event']
		);
	}

	/**
	 * Verifies that the list endpoint flags a core/block reference as a reusable
	 * block so the drawer can render Patterns-oriented copy, while a non-block
	 * unmapped reference is not flagged.
	 */
	public function test_list_flags_reusable_block_reference(): void {
		// ARRANGE: two open unmapped references — one core/block, one nav — each
		// on its own affected post.
		$reusable_post = self::factory()->post->create();
		$nav_post      = self::factory()->post->create();
		$this->attention->upsert_issue(
			$reusable_post,
			'unmapped_block_reference',
			9300001,
			'post',
			'warning',
			self::SOURCE,
			array( 'block' => 'core/block' )
		);
		$this->attention->upsert_issue(
			$nav_post,
			'unmapped_block_reference',
			9400,
			'post',
			'warning',
			self::SOURCE,
			array( 'block' => 'core/navigation' )
		);

		// ACT: list the open issues.
		$response = $this->list_issues();

		// ASSERT: the flag distinguishes the reusable-block reference.
		$flags = array();
		foreach ( $response['data']['items'] as $item ) {
			$flags[ $item['affected_post_id'] ] = $item['target_is_reusable_block'];
		}
		$this->assertTrue( $flags[ $reusable_post ] );
		$this->assertFalse( $flags[ $nav_post ] );
	}

	/**
	 * Verifies that a post reference reads as resolvable only when its target is
	 * imported on the destination.
	 */
	public function test_list_marks_post_targets_resolvable_by_presence(): void {
		// ARRANGE: Two unmapped post references — one target present, one not.
		$this->seed_target_post( 8801 );
		$this->open_issue(
			self::factory()->post->create(),
			'unmapped_block_reference',
			8801,
			'post'
		);
		$this->open_issue(
			self::factory()->post->create(),
			'unmapped_block_reference',
			8802,
			'post'
		);

		// ACT: List the open issues.
		$resolvable = $this->resolvable_by_target_ref( $this->list_issues() );

		// ASSERT: Only the imported target reads as resolvable now.
		$this->assertTrue( $resolvable[8801] );
		$this->assertFalse( $resolvable[8802] );
	}

	/**
	 * Verifies that a term reference reads as resolvable only when its target
	 * term is imported on the destination.
	 */
	public function test_list_marks_term_targets_resolvable_by_presence(): void {
		// ARRANGE: Two unmapped term references — one term present, one not.
		$this->seed_target_term( 8811 );
		$this->open_issue(
			self::factory()->post->create(),
			'unmapped_block_reference',
			8811,
			'term'
		);
		$this->open_issue(
			self::factory()->post->create(),
			'unmapped_block_reference',
			8812,
			'term'
		);

		// ACT: List the open issues.
		$resolvable = $this->resolvable_by_target_ref( $this->list_issues() );

		// ASSERT: Only the imported term reads as resolvable now.
		$this->assertTrue( $resolvable[8811] );
		$this->assertFalse( $resolvable[8812] );
	}

	/**
	 * Verifies that an orphaned parent reads as resolvable only when the source
	 * parent is imported, exercising the find_imported_post lookup.
	 */
	public function test_list_marks_parent_orphaned_resolvable_by_parent(): void {
		// ARRANGE: Two orphaned children — one parent present, one not.
		$this->seed_target_post( 8850 );
		$this->open_issue(
			self::factory()->post->create( array( 'post_type' => 'page' ) ),
			'parent_orphaned',
			8850,
			'post'
		);
		$this->open_issue(
			self::factory()->post->create( array( 'post_type' => 'page' ) ),
			'parent_orphaned',
			8851,
			'post'
		);

		// ACT: List the open issues.
		$resolvable = $this->resolvable_by_target_ref( $this->list_issues() );

		// ASSERT: The child whose parent is imported is resolvable; the other
		// waits.
		$this->assertTrue( $resolvable[8850] );
		$this->assertFalse( $resolvable[8851] );
	}

	/**
	 * Verifies that a bulk retry runs each issue and aggregates the outcomes into
	 * per-outcome counts.
	 */
	public function test_bulk_retry_aggregates_outcomes(): void {
		// ARRANGE: Two resolvable block references and one whose target is absent.
		$resolvable_a = self::factory()->post->create(
			array( 'post_content' => $this->nav_link_content( 8801, 'post-type' ) )
		);
		$resolvable_b = self::factory()->post->create(
			array( 'post_content' => $this->nav_link_content( 8802, 'post-type' ) )
		);
		$waiting      = self::factory()->post->create(
			array( 'post_content' => $this->nav_link_content( 8803, 'post-type' ) )
		);
		$this->seed_target_post( 8801 );
		$this->seed_target_post( 8802 );
		$this->open_issue( $resolvable_a, 'unmapped_block_reference', 8801, 'post' );
		$this->open_issue( $resolvable_b, 'unmapped_block_reference', 8802, 'post' );
		$this->open_issue( $waiting, 'unmapped_block_reference', 8803, 'post' );

		// ACT: Bulk-retry all three.
		$response = $this->bulk_retry(
			array(
				$this->descriptor( $resolvable_a, 8801, 'post' ),
				$this->descriptor( $resolvable_b, 8802, 'post' ),
				$this->descriptor( $waiting, 8803, 'post' ),
			)
		);

		// ASSERT: Two resolved, one still waiting on its absent target.
		$this->assertTrue( $response['success'] );
		$this->assertSame( 2, $response['data']['resolved'] );
		$this->assertSame( 1, $response['data']['target_absent'] );
		$this->assertSame( 0, $response['data']['write_failed'] );
		$this->assertSame( 0, $response['data']['unresolved'] );
		$this->assertSame( 0, $response['data']['skipped'] );
	}

	/**
	 * Verifies that a batch larger than the cap is rejected before any
	 * reconciliation runs.
	 */
	public function test_bulk_retry_rejects_over_cap(): void {
		// ARRANGE: A resolvable open issue, then padding one past the cap.
		$post_id = self::factory()->post->create(
			array( 'post_content' => $this->nav_link_content( 8804, 'post-type' ) )
		);
		$this->seed_target_post( 8804 );
		$this->open_issue( $post_id, 'unmapped_block_reference', 8804, 'post' );

		$items = array( $this->descriptor( $post_id, 8804, 'post' ) );
		for ( $i = 0; $i < Admin_Ajax_Controller::RETRY_ATTENTION_BATCH_MAX; $i++ ) {
			$items[] = $this->descriptor( 1, 1000 + $i, 'post' );
		}

		// ACT: Dispatch the over-cap batch.
		$response = $this->bulk_retry( $items );

		// ASSERT: Rejected before any reconciliation ran; the issue is untouched.
		$this->assertFalse( $response['success'] );
		$this->assertNotNull(
			$this->attention->get_issue(
				$post_id,
				'unmapped_block_reference',
				8804,
				'post'
			)
		);
	}

	/**
	 * Verifies that a user without edit_posts cannot bulk-retry.
	 */
	public function test_bulk_retry_rejects_user_without_edit_posts(): void {
		// ARRANGE: An open issue and a subscriber-level user.
		$post_id = self::factory()->post->create(
			array( 'post_content' => $this->nav_link_content( 8801, 'post-type' ) )
		);
		$this->seed_target_post( 8801 );
		$this->open_issue( $post_id, 'unmapped_block_reference', 8801, 'post' );
		wp_set_current_user(
			$this->factory()->user->create( array( 'role' => 'subscriber' ) )
		);

		// ACT: Bulk-retry as the subscriber.
		$response = $this->bulk_retry(
			array( $this->descriptor( $post_id, 8801, 'post' ) )
		);

		// ASSERT: Forbidden, and the issue is untouched.
		$this->assertFalse( $response['success'] );
		$this->assertNotNull(
			$this->attention->get_issue(
				$post_id,
				'unmapped_block_reference',
				8801,
				'post'
			)
		);
	}

	/**
	 * Maps a list response's degradation rows to their resolvable flag, keyed by
	 * target ref.
	 *
	 * @param array $response Decoded list response.
	 * @return array<int, bool> Resolvable flag keyed by target ref.
	 */
	private function resolvable_by_target_ref( array $response ): array {
		$map = array();
		foreach ( $response['data']['items'] as $item ) {
			$map[ (int) $item['target_ref'] ] = $item['resolvable'];
		}

		return $map;
	}

	/**
	 * Builds an unmapped_block_reference retry descriptor.
	 *
	 * @param int    $affected_post_id Affected post id.
	 * @param int    $target_ref       Source id of the target.
	 * @param string $target_kind      'post' or 'term'.
	 * @return array Retry descriptor.
	 */
	private function descriptor(
		int $affected_post_id,
		int $target_ref,
		string $target_kind
	): array {
		return array(
			'affected_post_id' => $affected_post_id,
			'issue_type'       => 'unmapped_block_reference',
			'target_ref'       => $target_ref,
			'target_kind'      => $target_kind,
		);
	}

	/**
	 * Dispatches the bulk-retry endpoint and returns the decoded response.
	 *
	 * @param array[] $items Retry descriptors.
	 * @return array Decoded response.
	 */
	private function bulk_retry( array $items ): array {
		$_POST = array(
			'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'items' => wp_json_encode( $items ),
		);

		$this->dispatch_ajax_expecting_die(
			'safe_publish_bulk_retry_attention_issues'
		);

		return json_decode( $this->_last_response, true );
	}

	/**
	 * Dispatches the list endpoint and returns the decoded JSON response.
	 *
	 * @return array Decoded response.
	 */
	private function list_issues(): array {
		$_POST = array( 'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ) );

		$this->dispatch_ajax_expecting_die( 'safe_publish_list_needs_attention' );

		return json_decode( $this->_last_response, true );
	}

	/**
	 * Dispatches the retry endpoint and returns the decoded JSON response.
	 *
	 * @param array $fields POST fields beyond the nonce.
	 * @return array Decoded response.
	 */
	private function retry( array $fields ): array {
		$_POST = array_merge(
			array( 'nonce' => wp_create_nonce( 'safe_publish_ajax_nonce' ) ),
			$fields
		);

		$this->dispatch_ajax_expecting_die( 'safe_publish_retry_attention_issue' );

		return json_decode( $this->_last_response, true );
	}

	/**
	 * Opens an attention issue scoped to the test source.
	 *
	 * @param int    $affected_post_id Affected post id.
	 * @param string $issue_type       Issue type.
	 * @param int    $target_ref       Source id of the target.
	 * @param string $target_kind      'post' or 'term'.
	 */
	private function open_issue(
		int $affected_post_id,
		string $issue_type,
		int $target_ref,
		string $target_kind
	): void {
		$this->attention->upsert_issue(
			$affected_post_id,
			$issue_type,
			$target_ref,
			$target_kind,
			'warning',
			self::SOURCE
		);
	}

	/**
	 * Creates a destination post tagged with a source post id and the source.
	 *
	 * @param int $source_id Source post id meta value.
	 * @return int Created post id.
	 */
	private function seed_target_post( int $source_id ): int {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertIsInt( $post_id );
		update_post_meta( $post_id, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta( $post_id, Options::META_SOURCE_SITE_URL, self::SOURCE );

		return $post_id;
	}

	/**
	 * Creates a destination term tagged with a source term id and the source.
	 *
	 * @param int $source_id Source term id meta value.
	 * @return int Created term id.
	 */
	private function seed_target_term( int $source_id ): int {
		$term_id = self::factory()->term->create(
			array( 'taxonomy' => 'category' )
		);
		$this->assertIsInt( $term_id );
		update_term_meta( $term_id, Options::META_SOURCE_TERM_ID, $source_id );
		update_term_meta( $term_id, Options::META_SOURCE_TERM_URL, self::SOURCE );

		return $term_id;
	}

	/**
	 * Builds a core/navigation block with a single nav-link of the given kind.
	 *
	 * @param int    $id   Source id in the nav-link.
	 * @param string $kind 'post-type' or 'taxonomy'.
	 * @return string Block markup.
	 */
	private function nav_link_content( int $id, string $kind ): string {
		$link = wp_json_encode(
			array(
				'id'    => $id,
				'kind'  => $kind,
				'label' => 'Link',
				'url'   => self::SOURCE . '/item-' . $id,
			)
		);

		return '<!-- wp:navigation --><!-- wp:navigation-link ' . $link
			. ' /--><!-- /wp:navigation -->';
	}

	/**
	 * Builds a core/navigation block with a post-type and a taxonomy nav-link
	 * sharing one numeric source id.
	 *
	 * @param int $id Source id shared by both links.
	 * @return string Block markup.
	 */
	private function two_kind_nav_content( int $id ): string {
		$post_link = wp_json_encode(
			array(
				'id'    => $id,
				'kind'  => 'post-type',
				'label' => 'Page',
				'url'   => self::SOURCE . '/page',
			)
		);
		$term_link = wp_json_encode(
			array(
				'id'    => $id,
				'kind'  => 'taxonomy',
				'type'  => 'category',
				'label' => 'News',
				'url'   => self::SOURCE . '/category/news',
			)
		);

		return '<!-- wp:navigation -->'
			. '<!-- wp:navigation-link ' . $post_link . ' /-->'
			. '<!-- wp:navigation-link ' . $term_link . ' /-->'
			. '<!-- /wp:navigation -->';
	}
}
