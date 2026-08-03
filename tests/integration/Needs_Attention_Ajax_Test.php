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

/**
 * Verifies that safe_publish_list_needs_attention concatenates failures before
 * degradations into one server-paginated stream, reports the combined count,
 * and resolves a failed update's edit link from its live post.
 */
class Needs_Attention_Ajax_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Source identity the failures and degradations are scoped to.
	 */
	private const SOURCE = 'https://source.example.com';

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
	 * Creates an import session for the connected source.
	 *
	 * @return int Session id.
	 */
	private function create_session(): int {
		$id = $this->history->create_session( self::SOURCE, 'bulk' );
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
	): void {
		$this->history->log_import_action(
			$session,
			$source_post_id,
			$title,
			'error',
			null,
			'Boom'
		);
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
	 * @param int $page     1-indexed page number.
	 * @param int $per_page Items per page.
	 * @return array Decoded response.
	 */
	private function list_needs_attention( int $page = 1, int $per_page = 20 ): array {
		$_POST = array(
			'nonce'    => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'page'     => (string) $page,
			'per_page' => (string) $per_page,
		);

		$this->dispatch_ajax_expecting_die( 'safe_publish_list_needs_attention' );

		return json_decode( $this->_last_response, true );
	}
}
