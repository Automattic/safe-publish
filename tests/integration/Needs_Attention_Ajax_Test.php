<?php
/**
 * Integration tests for the Needs attention inbox AJAX endpoint.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Utils\Attention_Issues_Table;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use Safe_Publish\Utils\Options;
use WP_Ajax_UnitTestCase;
use WP_Error;

/**
 * Verifies that safe_publish_list_needs_attention concatenates the connected
 * source's failures before its degradations into one server-paginated stream,
 * reports the combined count, and resolves a failed update's edit link from its
 * live post.
 */
class Needs_Attention_Ajax_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Source identity the failures and degradations are scoped to.
	 */
	private const SOURCE = 'https://source.example.com';

	/**
	 * A previously connected source whose rows must stay out of the inbox.
	 */
	private const OTHER_SOURCE = 'https://old-source.example.com';

	/**
	 * Fallback shared secret used when no environment constant is defined.
	 */
	private const FALLBACK_SECRET = 'integration-test-secret-key-32chars-ok';

	/**
	 * History repository for seeding failure and success rows.
	 *
	 * @var History_Repository
	 */
	private History_Repository $history;

	/**
	 * Attention issues repository for seeding degradations.
	 *
	 * @var Attention_Issues_Repository
	 */
	private Attention_Issues_Repository $attention;

	/**
	 * Sets up the custom tables, an admin user, and the connected source.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Imports_Table::create_table();
		Import_Items_Table::create_table();
		Attention_Issues_Table::create_table();
		Audit_Log_Table::create_table();

		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		$this->history   = new History_Repository();
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
	 * Verifies that failures list before degradations with the combined count.
	 */
	public function test_lists_failures_before_degradations(): void {
		// ARRANGE: One failure and one degradation for the connected source.
		$session = $this->create_session();
		$this->seed_failure( $session, 500, 'Broken import' );
		$this->open_degradation( self::factory()->post->create(), 8300 );

		// ACT: Request the inbox.
		$response = $this->list_needs_attention();

		// ASSERT: The failure precedes the degradation and both are counted.
		$this->assertTrue( $response['success'] );
		$items = $response['data']['items'];
		$this->assertCount( 2, $items );
		$this->assertSame( 'failure', $items[0]['kind'] );
		$this->assertSame( 'degradation', $items[1]['kind'] );
		$this->assertSame( 2, $response['data']['needs_attention_count'] );
		$this->assertFalse( $response['data']['has_more'] );
	}

	/**
	 * Verifies that the first page straddles the failure/degradation boundary.
	 */
	public function test_first_page_straddles_the_boundary(): void {
		// ARRANGE: Three failures and three degradations.
		$this->seed_three_failures_and_degradations();

		// ACT: Request the first page of four rows.
		$page = $this->list_needs_attention( 1, 4 );

		// ASSERT: Three failures then one degradation, with more to come.
		$this->assertSame(
			array( 'failure', 'failure', 'failure', 'degradation' ),
			array_column( $page['data']['items'], 'kind' )
		);
		$this->assertTrue( $page['data']['has_more'] );
		$this->assertSame( 6, $page['data']['needs_attention_count'] );
	}

	/**
	 * Verifies that the next page continues past the boundary without a gap or
	 * overlap.
	 */
	public function test_second_page_continues_past_the_boundary(): void {
		// ARRANGE: Three failures and three degradations.
		$this->seed_three_failures_and_degradations();

		// ACT: Request the second page of four rows.
		$page = $this->list_needs_attention( 2, 4 );

		// ASSERT: Only the two remaining degradations, and the stream ends.
		$this->assertSame(
			array( 'degradation', 'degradation' ),
			array_column( $page['data']['items'], 'kind' )
		);
		$this->assertFalse( $page['data']['has_more'] );
	}

	/**
	 * Verifies that a failed update carries an edit link to its live post while
	 * a first-import failure carries none.
	 */
	public function test_failed_update_links_to_live_post(): void {
		// ARRANGE: A source imported once (live post) then a failed re-import,
		// plus a first-import failure with no source id.
		$session = $this->create_session();
		$post_id = self::factory()->post->create();
		$this->history->log_import_action(
			$session,
			600,
			'Updated post',
			'success',
			$post_id
		);
		$this->seed_failure( $session, 600, 'Updated post' );
		$this->seed_failure( $session, null, 'First import failure' );

		// ACT: Request the inbox and key the failures by title.
		$response = $this->list_needs_attention();
		$by_title = array();
		foreach ( $response['data']['items'] as $item ) {
			$by_title[ $item['title'] ] = $item;
		}

		// ASSERT: The failed update links to its live post; the orphan does not.
		$this->assertNotSame( '', $by_title['Updated post']['edit_url'] );
		$this->assertStringContainsString(
			'post.php',
			$by_title['Updated post']['edit_url']
		);
		$this->assertSame( '', $by_title['First import failure']['edit_url'] );
	}

	/**
	 * Verifies that the ignore endpoint flags a failure and a degradation in one
	 * bulk call, dropping both from the open sets.
	 */
	public function test_ignore_endpoint_flags_failure_and_degradation(): void {
		// ARRANGE: A source-linked failure and a degradation.
		$session = $this->create_session();
		$this->seed_failure( $session, 500, 'Broken import' );
		$post_id = self::factory()->post->create();
		$this->open_degradation( $post_id, 8300 );

		// ACT: Ignore both in one bulk call.
		$response = $this->set_ignored( $this->both_descriptors( $post_id ), true );

		// ASSERT: The endpoint reports both flagged and the open sets are empty.
		$this->assertTrue( $response['success'] );
		$this->assertSame( 2, $response['data']['updated'] );
		$this->assertSame( 0, $this->history->count_failures( self::SOURCE ) );
		$this->assertSame(
			1,
			$this->history->count_failures( self::SOURCE, true )
		);
		$this->assertSame(
			0,
			$this->attention->count_open_issues( self::SOURCE )
		);
		$this->assertSame(
			1,
			$this->attention->count_open_issues( self::SOURCE, true )
		);
	}

	/**
	 * Verifies that the inbox exposes each slug-keyed degradation's slug and
	 * unattached terms, and scopes row_id by the slug so two of them on one
	 * post stay separate rows in the client.
	 */
	public function test_list_exposes_slug_scoped_degradation_rows(): void {
		// ARRANGE: One post carrying two degradations that differ only by slug.
		$post_id = self::factory()->post->create();
		$seeded  = array(
			'genre' => array( 'Jazz' ),
			'mood'  => array( 'Calm' ),
		);
		foreach ( $seeded as $slug => $terms ) {
			$this->attention->upsert_issue(
				$post_id,
				'unregistered_taxonomy',
				0,
				'taxonomy',
				'warning',
				self::SOURCE,
				array(
					'taxonomy' => $slug,
					'terms'    => $terms,
				),
				$slug
			);
		}

		// ACT: List the open inbox.
		$response = $this->list_needs_attention();

		// ASSERT: Both rows arrive with their own slug, terms, and row id.
		$this->assertTrue( $response['success'] );
		$rows = $response['data']['items'];
		$this->assertCount( 2, $rows );
		$terms_by_slug = array();
		foreach ( $rows as $row ) {
			$terms_by_slug[ $row['target_slug'] ] = $row['target_terms'];
		}
		ksort( $terms_by_slug );
		$this->assertSame( $seeded, $terms_by_slug );
		$this->assertNotSame( $rows[0]['row_id'], $rows[1]['row_id'] );
	}

	/**
	 * Verifies that the ignore endpoint accepts a slug-keyed degradation, whose
	 * target kind falls outside the narrower set the retry paths take.
	 */
	public function test_ignore_endpoint_flags_a_slug_keyed_degradation(): void {
		// ARRANGE: A degradation keyed by a slug rather than an id.
		$post_id = self::factory()->post->create();
		$this->attention->upsert_issue(
			$post_id,
			'unregistered_taxonomy',
			0,
			'taxonomy',
			'warning',
			self::SOURCE,
			array(),
			'genre'
		);

		// ACT: Ignore it by its full identity.
		$response = $this->set_ignored(
			array(
				array(
					'kind'             => 'degradation',
					'affected_post_id' => $post_id,
					'issue_type'       => 'unregistered_taxonomy',
					'target_ref'       => 0,
					'target_kind'      => 'taxonomy',
					'target_slug'      => 'genre',
				),
			),
			true
		);

		// ASSERT: The row moved to the ignored set.
		$this->assertTrue( $response['success'] );
		$this->assertSame( 1, $response['data']['updated'] );
		$this->assertSame(
			0,
			$this->attention->count_open_issues( self::SOURCE )
		);
		$this->assertSame(
			1,
			$this->attention->count_open_issues( self::SOURCE, true )
		);
	}

	/**
	 * Verifies that the un-ignore endpoint restores a previously ignored failure
	 * and degradation to the open sets.
	 */
	public function test_unignore_endpoint_restores_both_kinds(): void {
		// ARRANGE: A failure and a degradation, both already ignored.
		$session = $this->create_session();
		$this->seed_failure( $session, 500, 'Broken import' );
		$post_id = self::factory()->post->create();
		$this->open_degradation( $post_id, 8300 );
		$this->ignore_both( $post_id );

		// ACT: Un-ignore both in one call.
		$response = $this->set_ignored(
			$this->both_descriptors( $post_id ),
			false
		);

		// ASSERT: Both are back in the open sets.
		$this->assertTrue( $response['success'] );
		$this->assertSame( 1, $this->history->count_failures( self::SOURCE ) );
		$this->assertSame(
			0,
			$this->history->count_failures( self::SOURCE, true )
		);
		$this->assertSame(
			1,
			$this->attention->count_open_issues( self::SOURCE )
		);
	}

	/**
	 * Verifies that the ignored view lists only ignored rows while the tab count
	 * keeps reporting the open total.
	 */
	public function test_list_ignored_view_excludes_from_count(): void {
		// ARRANGE: An ignored failure and degradation, plus one still-open
		// failure that stays in the count.
		$session = $this->create_session();
		$this->seed_failure( $session, 500, 'Ignored import' );
		$post_id = self::factory()->post->create();
		$this->open_degradation( $post_id, 8300 );
		$this->ignore_both( $post_id );
		$this->seed_failure( $session, 501, 'Still open' );

		// ACT: Request the ignored view.
		$response = $this->list_needs_attention( 1, 20, 'ignored' );

		// ASSERT: It lists both ignored rows; the count reports the open failure.
		$this->assertCount( 2, $response['data']['items'] );
		$this->assertSame( 1, $response['data']['needs_attention_count'] );
	}

	/**
	 * Verifies that ignoring is gated at manage_options: An editor, who has
	 * edit_posts but not manage_options, is forbidden.
	 */
	public function test_set_ignored_requires_manage_options(): void {
		// ARRANGE: An editor — edit_posts but not manage_options.
		wp_set_current_user(
			$this->factory()->user->create( array( 'role' => 'editor' ) )
		);

		// ACT: Attempt to ignore a row as the editor.
		$response = $this->set_ignored(
			array(
				array(
					'kind'           => 'failure',
					'item_id'        => 0,
					'source_post_id' => 500,
				),
			),
			true
		);

		// ASSERT: Forbidden — ignoring manages plugin data, like Remove.
		$this->assertFalse( $response['success'] );
	}

	/**
	 * Verifies that Remove deletes a failure that is currently ignored,
	 * clearing it from both the open and ignored sets.
	 */
	public function test_remove_deletes_an_ignored_failure(): void {
		// ARRANGE: A source-linked failure that has been ignored.
		$session = $this->create_session();
		$this->seed_failure( $session, 500, 'Broken import' );
		$this->history->set_failed_items_ignored(
			array(),
			array( 500 ),
			true,
			self::SOURCE
		);
		$this->assertSame(
			1,
			$this->history->count_failures( self::SOURCE, true )
		);

		// ACT: Remove the ignored failure by its source id.
		$response = $this->remove_failures( array(), array( 500 ) );

		// ASSERT: It is deleted from both the open and ignored sets.
		$this->assertTrue( $response['success'] );
		$this->assertSame( 1, $response['data']['deleted'] );
		$this->assertSame( 0, $this->history->count_failures( self::SOURCE ) );
		$this->assertSame(
			0,
			$this->history->count_failures( self::SOURCE, true )
		);
	}

	/**
	 * Verifies that the inbox and its badge report only the connected source's
	 * failures, leaving a prior source's rows out.
	 */
	public function test_inbox_and_badge_exclude_another_source(): void {
		// ARRANGE: One failure and one degradation for the connected source,
		// plus a source-linked and an orphan failure under a prior source.
		$this->seed_failure( $this->create_session(), 500, 'Broken import' );
		$this->open_degradation( self::factory()->post->create(), 8300 );
		$old = $this->create_session( self::OTHER_SOURCE );
		$this->seed_failure( $old, 501, 'Prior source failure' );
		$this->seed_failure( $old, null, 'Prior source orphan' );

		// ACT: Request the inbox.
		$response = $this->list_needs_attention();

		// ASSERT: Only the connected source's two rows list and count.
		$this->assertSame(
			array( 'failure', 'degradation' ),
			array_column( $response['data']['items'], 'kind' )
		);
		$this->assertSame( 2, $response['data']['needs_attention_count'] );
		$this->assertFalse( $response['data']['has_more'] );
	}

	/**
	 * Verifies that a newer row under another source for the same numeric
	 * source post id no longer suppresses the connected source's failure.
	 */
	public function test_cross_source_newer_row_does_not_hide_a_failure(): void {
		// ARRANGE: A failure for source post 500, then a later successful
		// import of the same numeric id under a different source.
		$this->seed_failure( $this->create_session(), 500, 'Broken import' );
		$this->history->log_import_action(
			$this->create_session( self::OTHER_SOURCE ),
			500,
			'Other source post',
			'success',
			self::factory()->post->create()
		);

		// ACT: Request the inbox.
		$response = $this->list_needs_attention();

		// ASSERT: The failure is still listed and counted.
		$items = $response['data']['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 500, $items[0]['source_post_id'] );
		$this->assertSame( 1, $response['data']['needs_attention_count'] );
	}

	/**
	 * Verifies that a failure's edit link resolves against the connected
	 * source, not a same-numbered post imported from another one.
	 */
	public function test_failure_edit_link_ignores_another_source_post(): void {
		// ARRANGE: A failed re-import of source post 600 after a successful
		// import, plus a newer import of the same numeric id elsewhere.
		$session = $this->create_session();
		$post_id = self::factory()->post->create();
		$this->history->log_import_action(
			$session,
			600,
			'Mine',
			'success',
			$post_id
		);
		$this->seed_failure( $session, 600, 'Mine' );
		$this->history->log_import_action(
			$this->create_session( self::OTHER_SOURCE ),
			600,
			'Theirs',
			'success',
			self::factory()->post->create()
		);

		// ACT: Request the inbox.
		$response = $this->list_needs_attention();

		// ASSERT: The link points at this source's post.
		$this->assertStringContainsString(
			'post=' . $post_id,
			$response['data']['items'][0]['edit_url']
		);
	}

	/**
	 * Verifies that the Ignored view also leaves another source's ignored
	 * failures out.
	 */
	public function test_ignored_view_excludes_another_source(): void {
		// ARRANGE: An ignored failure under each source.
		$this->seed_failure( $this->create_session(), 500, 'Mine ignored' );
		$this->seed_failure(
			$this->create_session( self::OTHER_SOURCE ),
			501,
			'Theirs ignored'
		);
		$this->history->set_failed_items_ignored(
			array(),
			array( 500 ),
			true,
			self::SOURCE
		);
		$this->history->set_failed_items_ignored(
			array(),
			array( 501 ),
			true,
			self::OTHER_SOURCE
		);

		// ACT: Request the Ignored view.
		$response = $this->list_needs_attention( 1, 20, 'ignored' );

		// ASSERT: Only the connected source's ignored failure is listed.
		$items = $response['data']['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'Mine ignored', $items[0]['title'] );
	}

	/**
	 * Verifies that a disconnected install lists no failures and shows a zero
	 * badge, matching how the degradations half already behaves.
	 */
	public function test_disconnected_install_shows_an_empty_inbox(): void {
		// ARRANGE: A failure and a degradation, then the connection removed.
		$this->seed_failure( $this->create_session(), 500, 'Broken import' );
		$this->open_degradation( self::factory()->post->create(), 8300 );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );

		// ACT: Request the inbox.
		$response = $this->list_needs_attention();

		// ASSERT: Nothing lists and the badge is zero.
		$this->assertCount( 0, $response['data']['items'] );
		$this->assertSame( 0, $response['data']['needs_attention_count'] );
		$this->assertFalse( $response['data']['has_more'] );
	}

	/**
	 * Verifies that the failure/degradation boundary math holds under scoped
	 * counts when another source contributes failures the page must skip.
	 */
	public function test_pages_straddle_the_boundary_with_another_source(): void {
		// ARRANGE: Three failures and three degradations for the connected
		// source, plus two failures under another source.
		$this->seed_three_failures_and_degradations();
		$old = $this->create_session( self::OTHER_SOURCE );
		$this->seed_failure( $old, 601, 'Theirs 1' );
		$this->seed_failure( $old, 602, 'Theirs 2' );

		// ACT: Request both pages of four rows.
		$first  = $this->list_needs_attention( 1, 4 );
		$second = $this->list_needs_attention( 2, 4 );

		// ASSERT: The pages partition the connected source's six rows exactly.
		$this->assertSame(
			array( 'failure', 'failure', 'failure', 'degradation' ),
			array_column( $first['data']['items'], 'kind' )
		);
		$this->assertTrue( $first['data']['has_more'] );
		$this->assertSame(
			array( 'degradation', 'degradation' ),
			array_column( $second['data']['items'], 'kind' )
		);
		$this->assertFalse( $second['data']['has_more'] );
		$this->assertSame( 6, $first['data']['needs_attention_count'] );
	}

	/**
	 * Verifies that the Posts listing's needs-attention flag reports the same
	 * connected-source total as the inbox badge.
	 */
	public function test_list_posts_count_flag_excludes_another_source(): void {
		// ARRANGE: A failure and a degradation for the connected source, plus a
		// failure under a prior one, behind a stubbed catalog.
		$this->seed_failure( $this->create_session(), 500, 'Broken import' );
		$this->open_degradation( self::factory()->post->create(), 8300 );
		$this->seed_failure(
			$this->create_session( self::OTHER_SOURCE ),
			501,
			'Prior source failure'
		);
		$stub = array( $this, 'stub_empty_catalog' );
		add_filter( 'pre_http_request', $stub, 1, 3 );

		try {
			// ACT: Request the Posts listing with the count flag set.
			$nonce = wp_create_nonce( 'safe_publish_ajax_nonce' );
			$_POST = array(
				'nonce'                      => $nonce,
				'source_site_url'            => self::SOURCE,
				'with_needs_attention_count' => '1',
			);
			$this->dispatch_ajax_expecting_die( 'safe_publish_list_posts' );
			$response = json_decode( $this->_last_response, true );
		} finally {
			remove_filter( 'pre_http_request', $stub, 1 );
		}

		// ASSERT: The flag counts the connected source's two rows only.
		$this->assertTrue( $response['success'] );
		$this->assertSame( 2, $response['data']['needs_attention_count'] );
	}

	/**
	 * Verifies that Remove cannot delete another source's orphan failure, the
	 * row id being the only handle a stale listing has on one.
	 */
	public function test_remove_by_item_id_leaves_another_source_intact(): void {
		// ARRANGE: An orphan failure recorded under a previous source.
		$item_id = $this->seed_failure(
			$this->create_session( self::OTHER_SOURCE ),
			null,
			'Prior source orphan'
		);

		// ACT: Remove it by row id, as a stale tab would.
		$response = $this->remove_failures( array( $item_id ), array() );

		// ASSERT: Nothing is deleted and the row survives for a reconnect.
		$this->assertTrue( $response['success'] );
		$this->assertSame( 0, $response['data']['deleted'] );
		$this->assertSame(
			1,
			$this->history->count_failures( self::OTHER_SOURCE )
		);
	}

	/**
	 * Verifies that Ignore cannot flag another source's orphan failure, which
	 * routes by row id for the same reason Remove does.
	 */
	public function test_ignore_by_item_id_leaves_another_source_open(): void {
		// ARRANGE: An orphan failure recorded under a previous source.
		$item_id = $this->seed_failure(
			$this->create_session( self::OTHER_SOURCE ),
			null,
			'Prior source orphan'
		);

		// ACT: Ignore it by row id, as a stale tab would.
		$response = $this->set_ignored(
			array(
				array(
					'kind'           => 'failure',
					'item_id'        => $item_id,
					'source_post_id' => 0,
				),
			),
			true
		);

		// ASSERT: Nothing is flagged and the row stays in the other source's
		// open set.
		$this->assertTrue( $response['success'] );
		$this->assertSame( 0, $response['data']['updated'] );
		$this->assertSame(
			1,
			$this->history->count_failures( self::OTHER_SOURCE )
		);
		$this->assertSame(
			0,
			$this->history->count_failures( self::OTHER_SOURCE, true )
		);
	}

	/**
	 * Verifies that changing the connection hides a source's rows and
	 * reconnecting brings them back, the round trip the docs promise.
	 */
	public function test_reconnecting_a_source_restores_its_rows(): void {
		// ARRANGE: A failure and a degradation for the connected source.
		$this->seed_failure( $this->create_session(), 500, 'Broken import' );
		$this->open_degradation( self::factory()->post->create(), 8300 );

		// ACT: List the inbox, switch the connection away, and reconnect.
		$connected = $this->list_needs_attention();
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::OTHER_SOURCE );
		$switched = $this->list_needs_attention();
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE );
		$restored = $this->list_needs_attention();

		// ASSERT: Both rows leave the inbox and the badge, then return.
		$this->assertCount( 2, $connected['data']['items'] );
		$this->assertCount( 0, $switched['data']['items'] );
		$this->assertSame( 0, $switched['data']['needs_attention_count'] );
		$this->assertSame(
			array( 'failure', 'degradation' ),
			array_column( $restored['data']['items'], 'kind' )
		);
		$this->assertSame( 2, $restored['data']['needs_attention_count'] );
	}

	/**
	 * Short-circuits every HTTP request with an empty catalog page.
	 *
	 * @param false|array|WP_Error $_preempt Filtered short-circuit value.
	 * @param array                $_args    Request arguments.
	 * @param string               $_url     Request URL.
	 * @return array Faked HTTP response.
	 */
	public function stub_empty_catalog(
		false|array|WP_Error $_preempt,
		array $_args,
		string $_url
	): array {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'items'    => array(),
					'has_more' => false,
				)
			),
			'headers'  => array(),
		);
	}

	/**
	 * Descriptors for the shared source-linked failure and degradation.
	 *
	 * @param int $post_id Degradation's affected post id.
	 * @return array[] Ignore/restore descriptors.
	 */
	private function both_descriptors( int $post_id ): array {
		return array(
			array(
				'kind'           => 'failure',
				'item_id'        => 0,
				'source_post_id' => 500,
			),
			array(
				'kind'             => 'degradation',
				'affected_post_id' => $post_id,
				'issue_type'       => 'nav_ref_rewrite_failed',
				'target_ref'       => 8300,
				'target_kind'      => 'post',
			),
		);
	}

	/**
	 * Ignores the shared failure (source 500) and degradation via the repos.
	 *
	 * @param int $post_id Degradation's affected post id.
	 */
	private function ignore_both( int $post_id ): void {
		$this->history->set_failed_items_ignored(
			array(),
			array( 500 ),
			true,
			self::SOURCE
		);
		$this->attention->set_issue_ignored(
			$post_id,
			'nav_ref_rewrite_failed',
			8300,
			'post',
			'',
			true
		);
	}

	/**
	 * Seeds three failures and three degradations for the connected source.
	 */
	private function seed_three_failures_and_degradations(): void {
		$session = $this->create_session();
		$this->seed_failure( $session, 501, 'F1' );
		$this->seed_failure( $session, 502, 'F2' );
		$this->seed_failure( $session, 503, 'F3' );
		$this->open_degradation( self::factory()->post->create(), 9001 );
		$this->open_degradation( self::factory()->post->create(), 9002 );
		$this->open_degradation( self::factory()->post->create(), 9003 );
	}

	/**
	 * Creates an import session for a source.
	 *
	 * @param string $source_site_url Source the session imports from.
	 * @return int Session id.
	 */
	private function create_session(
		string $source_site_url = self::SOURCE
	): int {
		$id = $this->history->create_session( $source_site_url, 'bulk' );
		return is_int( $id ) ? $id : 0;
	}

	/**
	 * Logs a failed import item.
	 *
	 * @param int      $session        Session id.
	 * @param int|null $source_post_id Source post id, or null for an orphan.
	 * @param string   $title          Attempted title.
	 */
	private function seed_failure(
		int $session,
		?int $source_post_id,
		string $title
	): int {
		$id = $this->history->log_import_action(
			$session,
			$source_post_id,
			$title,
			'error',
			null,
			'Boom'
		);

		return is_int( $id ) ? $id : 0;
	}

	/**
	 * Opens a warning-level degradation scoped to the connected source.
	 *
	 * @param int $affected_post_id Destination post id.
	 * @param int $target_ref       Source id of the unresolved target.
	 */
	private function open_degradation(
		int $affected_post_id,
		int $target_ref
	): void {
		$this->attention->upsert_issue(
			$affected_post_id,
			'nav_ref_rewrite_failed',
			$target_ref,
			'post',
			'warning',
			self::SOURCE
		);
	}

	/**
	 * Dispatches the inbox endpoint and returns the decoded JSON response.
	 *
	 * @param int    $page     1-indexed page number.
	 * @param int    $per_page Items per page.
	 * @param string $view     'open' or 'ignored'.
	 * @return array Decoded response.
	 */
	private function list_needs_attention(
		int $page = 1,
		int $per_page = 20,
		string $view = 'open'
	): array {
		$_POST = array(
			'nonce'    => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'page'     => (string) $page,
			'per_page' => (string) $per_page,
			'view'     => $view,
		);

		// _handleAjax appends, so clear it to keep repeat calls decodable.
		$this->_last_response = '';

		$this->dispatch_ajax_expecting_die( 'safe_publish_list_needs_attention' );

		return json_decode( $this->_last_response, true );
	}

	/**
	 * Dispatches the ignore/restore endpoint and returns the decoded response.
	 *
	 * @param array[] $items   Row descriptors carrying their kind.
	 * @param bool    $ignored True to ignore, false to restore.
	 * @return array Decoded response.
	 */
	private function set_ignored( array $items, bool $ignored ): array {
		$_POST = array(
			'nonce'   => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'ignored' => $ignored ? '1' : '0',
			'items'   => wp_json_encode( $items ),
		);

		$this->dispatch_ajax_expecting_die(
			'safe_publish_set_needs_attention_ignored'
		);

		return json_decode( $this->_last_response, true );
	}

	/**
	 * Dispatches the failure-removal endpoint and returns the decoded response.
	 *
	 * @param int[] $item_ids        Failure row ids (orphans).
	 * @param int[] $source_post_ids Source post ids whose failures to remove.
	 * @return array Decoded response.
	 */
	private function remove_failures(
		array $item_ids,
		array $source_post_ids
	): array {
		$_POST = array(
			'nonce'           => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'item_ids'        => array_map( 'strval', $item_ids ),
			'source_post_ids' => array_map( 'strval', $source_post_ids ),
		);

		$this->dispatch_ajax_expecting_die( 'safe_publish_delete_failed_items' );

		return json_decode( $this->_last_response, true );
	}
}
