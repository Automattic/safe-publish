<?php
/**
 * Import behavior when a post already claims a source identity
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Navigation_Ref_Rewriter;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;

/**
 * Covers the import decision when a post already claims a source identity.
 *
 * WP_Query's 'any' token hides every status registered exclude_from_search,
 * so an import used to create a second post claiming the same identity. The
 * lookup now spans all statuses: a claim outside the trash is updated, and a
 * trashed claim standing alone refuses rather than duplicating.
 */
class Import_Identity_Claim_Test extends Source_Posts_API_Test_Base {

	/**
	 * Source site identity used by every fixture in this class.
	 */
	private const SOURCE_URL = 'https://source.example.com';

	/**
	 * Editorial status kept out of site search, as an internal one is.
	 */
	private const HIDDEN_STATUS = 'sp_archived';

	/**
	 * Editorial status deregistered after a post was parked in it.
	 */
	private const GONE_STATUS = 'sp_retired';

	/**
	 * History repository shared by the service under test.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Post import service under test.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $import_service;

	/**
	 * Sets up the audit table and the import service.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'import' );

		$this->repository     = new History_Repository();
		$this->import_service = $this->build_import_service();
	}

	/**
	 * Unregisters the editorial statuses, which core keeps in a global.
	 */
	#[\Override]
	protected function tearDown(): void {
		unset(
			$GLOBALS['wp_post_statuses'][ self::HIDDEN_STATUS ],
			$GLOBALS['wp_post_statuses'][ self::GONE_STATUS ]
		);
		parent::tearDown();
	}

	/**
	 * Verifies that an import refuses while a trashed post is the only claim
	 * on the source identity, leaving that post the sole claim.
	 */
	public function test_import_refuses_while_only_claim_is_trashed(): void {
		// ARRANGE: An imported post, then trashed through the plugin's own
		// Trash action.
		$imported = $this->import( 8101 );
		$this->trash( $imported['post_id'] );

		// ACT: Import the same source post again.
		$refused = $this->import( 8101 );

		// ASSERT: The import is refused and names the trashed post by title and
		// ID, so an untitled or duplicate-titled post is still findable.
		$this->assertFalse( $refused['success'], 'Import must be refused.' );
		$this->assertStringContainsString(
			get_post( $imported['post_id'] )->post_title,
			(string) $refused['error'],
			'The refusal must name the trashed post.'
		);
		$this->assertStringContainsString(
			'ID ' . $imported['post_id'],
			(string) $refused['error'],
			'The refusal must carry the trashed post ID.'
		);

		// ASSERT: No second claim was created.
		$this->assertSame(
			array( $imported['post_id'] ),
			$this->claiming_post_ids( 8101 ),
			'The trashed post must remain the only claim.'
		);
	}

	/**
	 * Verifies that a refusal over an untitled trashed claim names it with the
	 * no-title placeholder rather than an empty quote.
	 */
	public function test_refusal_over_an_untitled_claim_reads_no_title(): void {
		// ARRANGE: An untitled trashed post claiming the source identity.
		$claim = $this->create_claiming_post( 8112, 'post' );
		wp_update_post(
			array(
				'ID'         => $claim,
				'post_title' => '',
			)
		);
		$this->trash( $claim );

		// ACT: Import the source post it claims.
		$refused = $this->import( 8112 );

		// ASSERT: The placeholder stands in for the missing title.
		$this->assertFalse( $refused['success'], 'Import must be refused.' );
		$this->assertStringContainsString(
			'"(no title)", ID ' . $claim,
			(string) $refused['error'],
			'An untitled claim must read as (no title).'
		);
	}

	/**
	 * Verifies that a live claim is updated even while a trashed claim exists,
	 * so a site already holding both resolves without intervention.
	 */
	public function test_live_claim_is_updated_despite_a_trashed_claim(): void {
		// ARRANGE: A trashed claim created before the live one.
		$trashed = $this->create_claiming_post( 8102, 'post' );
		$this->trash( $trashed );
		$live = $this->create_claiming_post( 8102, 'post' );

		// ACT: Import the source post both claim.
		$result = $this->import( 8102 );

		// ASSERT: The live post was updated, and no third post appeared.
		$this->assertTrue( $result['success'], 'Import must succeed.' );
		$this->assertTrue( $result['existing'], 'Import must be an update.' );
		$this->assertSame(
			$live,
			$result['post_id'],
			'The live claim must be the update target.'
		);
		$this->assertSame(
			array( $trashed, $live ),
			$this->claiming_post_ids( 8102 ),
			'No further claim may be created.'
		);
	}

	/**
	 * Verifies that a claim held in a status registered exclude_from_search is
	 * updated rather than duplicated, since 'any' alone cannot see it.
	 */
	public function test_claim_in_a_hidden_status_is_updated(): void {
		// ARRANGE: A claim parked in an internal editorial status, which core
		// derives exclude_from_search from.
		register_post_status( self::HIDDEN_STATUS, array( 'internal' => true ) );
		$this->assertTrue(
			get_post_status_object( self::HIDDEN_STATUS )->exclude_from_search
		);

		$claim = $this->create_claiming_post( 8110, 'post' );
		wp_update_post(
			array(
				'ID'          => $claim,
				'post_status' => self::HIDDEN_STATUS,
			)
		);

		// ACT: Import the source post it claims.
		$result = $this->import( 8110 );

		// ASSERT: The hidden claim was the update target, not a second post.
		$this->assertTrue( $result['success'], 'Import must succeed.' );
		$this->assertTrue( $result['existing'], 'Import must be an update.' );
		$this->assertSame( $claim, $result['post_id'] );
		$this->assertSame(
			array( $claim ),
			$this->claiming_post_ids( 8110 ),
			'No second claim may be created.'
		);
	}

	/**
	 * Verifies that a claim held in a status that is no longer registered is
	 * updated rather than duplicated, as deactivating its plugin leaves it.
	 */
	public function test_claim_in_a_deregistered_status_is_updated(): void {
		// ARRANGE: A claim parked in a custom status, then deregistered.
		register_post_status( self::GONE_STATUS, array( 'public' => true ) );

		$claim = $this->create_claiming_post( 8111, 'post' );
		wp_update_post(
			array(
				'ID'          => $claim,
				'post_status' => self::GONE_STATUS,
			)
		);
		$this->assertSame( self::GONE_STATUS, get_post( $claim )->post_status );

		unset( $GLOBALS['wp_post_statuses'][ self::GONE_STATUS ] );
		$this->assertNull( get_post_status_object( self::GONE_STATUS ) );

		// ACT: Import the source post it claims.
		$result = $this->import( 8111 );

		// ASSERT: The claim was the update target, not a second post.
		$this->assertTrue(
			$result['success'],
			'Import must succeed: ' . ( $result['error'] ?? '' )
		);
		$this->assertTrue( $result['existing'], 'Import must be an update.' );
		$this->assertSame( $claim, $result['post_id'] );
		$this->assertSame(
			array( $claim ),
			$this->claiming_post_ids( 8111 ),
			'No second claim may be created.'
		);
	}

	/**
	 * Verifies that a trashed claim newer than the live one does not hide it,
	 * which an uncapped lookup is what prevents.
	 */
	public function test_newer_trashed_claim_does_not_mask_an_older_live_one(): void {
		// ARRANGE: The live claim first, so the trashed one has the higher ID
		// and wins the newest-by-ID order the lookup applies.
		$live    = $this->create_claiming_post( 8103, 'post' );
		$trashed = $this->create_claiming_post( 8103, 'post' );
		$this->trash( $trashed );
		$this->assertGreaterThan( $live, $trashed );

		// ACT: Import the source post both claim.
		$result = $this->import( 8103 );

		// ASSERT: The live post was updated rather than the import refused.
		$this->assertTrue(
			$result['success'],
			'A newer trashed claim must not refuse the import: '
				. ( $result['error'] ?? '' )
		);
		$this->assertSame(
			$live,
			$result['post_id'],
			'The live claim must be the update target.'
		);
	}

	/**
	 * Verifies that restoring the trashed post makes the next import update it
	 * in place rather than create a second copy.
	 */
	public function test_import_after_restore_updates_in_place(): void {
		// ARRANGE: An imported post, trashed and then restored.
		$imported = $this->import( 8104 );
		$this->trash( $imported['post_id'] );
		wp_untrash_post( $imported['post_id'] );
		$this->assertNotSame(
			'trash',
			get_post_status( $imported['post_id'] ),
			'The post must be out of the trash.'
		);

		// ACT: Import the same source post again.
		$this->mock_post_overrides['content'] = '<p>v2</p>';
		$result                               = $this->import( 8104 );

		// ASSERT: The restored post was updated and is still the only claim.
		$this->assertTrue( $result['success'], 'Import must succeed.' );
		$this->assertTrue( $result['existing'], 'Import must be an update.' );
		$this->assertSame( $imported['post_id'], $result['post_id'] );
		$this->assertStringContainsString(
			'v2',
			get_post( $result['post_id'] )->post_content,
			'Source edits must reach the restored post.'
		);
		$this->assertSame(
			array( $imported['post_id'] ),
			$this->claiming_post_ids( 8104 )
		);
	}

	/**
	 * Verifies that permanently deleting the trashed post lets the next import
	 * create a fresh copy.
	 */
	public function test_import_after_permanent_delete_creates_a_fresh_copy(): void {
		// ARRANGE: An imported post, trashed and then permanently deleted.
		$imported = $this->import( 8105 );
		$this->trash( $imported['post_id'] );
		wp_delete_post( $imported['post_id'], true );

		// ACT: Import the same source post again.
		$result = $this->import( 8105 );

		// ASSERT: A new post was created and claims the source identity alone.
		$this->assertTrue( $result['success'], 'Import must succeed.' );
		$this->assertFalse( $result['existing'], 'Import must create a post.' );
		$this->assertNotSame( $imported['post_id'], $result['post_id'] );
		$this->assertSame(
			array( $result['post_id'] ),
			$this->claiming_post_ids( 8105 )
		);
	}

	/**
	 * Verifies that a trashed claim held by another post type refuses too,
	 * since a cross-type claim is no less ambiguous.
	 */
	public function test_trashed_claim_of_another_post_type_refuses(): void {
		// ARRANGE: A trashed page claiming a source ID imported as a post.
		$trashed = $this->create_claiming_post( 8106, 'page' );
		$this->trash( $trashed );

		// ACT: Import the source post as a post.
		$result = $this->import( 8106 );

		// ASSERT: Refused, with the trashed page still the only claim.
		$this->assertFalse( $result['success'], 'Import must be refused.' );
		$this->assertSame(
			array( $trashed ),
			$this->claiming_post_ids( 8106 )
		);
	}

	/**
	 * Verifies that a refusal inside a session records an error item row and an
	 * IMPORT_ITEM_FAILED event carrying the refusal's error code.
	 */
	public function test_refusal_is_recorded_in_history_and_telemetry(): void {
		// ARRANGE: An imported post, trashed, and a session for the re-import.
		$imported = $this->import( 8107 );
		$this->trash( $imported['post_id'] );
		$session_id = $this->create_session();

		// ACT: Import the same source post inside that session.
		$refused = $this->import( 8107, $session_id );

		// ASSERT: The session holds one error item carrying the refusal text.
		$items = $this->repository->get_session_items( $session_id );
		$this->assertCount( 1, $items );
		$this->assertSame( 'error', $items[0]['status'] );
		$this->assertSame( $refused['error'], $items[0]['error_message'] );

		// ASSERT: Telemetry classifies the failure rather than reporting it as
		// unknown, which an unlisted error code would.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => Log_Events::IMPORT_ITEM_FAILED,
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame(
			'trashed_copy_exists',
			$events[0]['data']['error_code']
		);
	}

	/**
	 * Verifies that the Manage listing reports a trashed destination as
	 * trashed, so the row is distinguishable from one never imported.
	 */
	public function test_listing_reports_a_trashed_destination_as_trashed(): void {
		// ARRANGE: One imported source post trashed, one never imported.
		$imported = $this->import( 8108, $this->create_session() );
		$this->trash( $imported['post_id'] );

		$posts = array(
			array( 'id' => 8108 ),
			array( 'id' => 8109 ),
		);

		// ACT: Annotate the rows the listing renders.
		$this->import_service->annotate_posts_with_import_status( $posts );

		// ASSERT: The trashed row reports its status, still routed as available.
		$this->assertSame( 'trash', $posts[0]['wp_post_status'] );
		$this->assertSame( 'available', $posts[0]['local_state'] );

		// ASSERT: A row with no destination post reports no status.
		$this->assertNull( $posts[1]['wp_post_status'] );
		$this->assertSame( 'available', $posts[1]['local_state'] );
	}

	/**
	 * Imports one source post through the service under test.
	 *
	 * @param int      $source_id  Source post ID to import.
	 * @param int|null $session_id Import session, null when unsessioned.
	 * @return array Import result.
	 */
	private function import( int $source_id, ?int $session_id = null ): array {
		return $this->import_service->import_post(
			array(
				'id'        => $source_id,
				'title'     => 'Claim ' . $source_id,
				'link'      => self::SOURCE_URL . '/post-' . $source_id,
				'post_type' => 'posts',
			),
			$session_id
		);
	}

	/**
	 * Trashes a post, failing the test if it does not land in the trash, which
	 * an EMPTY_TRASH_DAYS of 0 would cause.
	 *
	 * @param int $post_id Post to trash.
	 */
	private function trash( int $post_id ): void {
		wp_trash_post( $post_id );

		$this->assertSame(
			'trash',
			get_post_status( $post_id ),
			'The fixture post must be in the trash.'
		);
	}

	/**
	 * Creates a destination post claiming a source ID for this class's source
	 * site, mirroring what an import writes.
	 *
	 * @param int    $source_id Source post ID to claim.
	 * @param string $post_type Destination post type.
	 * @return int Created post ID.
	 */
	private function create_claiming_post(
		int $source_id,
		string $post_type
	): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => 'Claim ' . $source_id,
				'meta_input'  => array(
					Options::META_SOURCE_POST_ID  => $source_id,
					Options::META_SOURCE_SITE_URL => self::SOURCE_URL,
				),
			)
		);

		if ( ! is_int( $post_id ) ) {
			$this->fail( 'Could not create a post claiming the source ID.' );
		}

		return $post_id;
	}

	/**
	 * Returns every post claiming a source ID, ascending, without relying on
	 * the lookups under test.
	 *
	 * @param int $source_id Source post ID to count claims for.
	 * @return int[] Claiming post IDs.
	 */
	private function claiming_post_ids( int $source_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value = %s
				 ORDER BY post_id ASC",
				Options::META_SOURCE_POST_ID,
				(string) $source_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Creates a bulk session for this class's source site.
	 *
	 * @return int Session ID.
	 */
	private function create_session(): int {
		$session_id = $this->repository->create_session(
			self::SOURCE_URL,
			'bulk'
		);

		$this->assertIsInt( $session_id );

		return $session_id;
	}

	/**
	 * Builds the import service with real collaborators.
	 *
	 * @return Post_Import_Service Service under test.
	 */
	private function build_import_service(): Post_Import_Service {
		$media_importer = new Media_Importer( new HTTP_Client() );

		return new Post_Import_Service(
			new Source_Posts_API( new HTTP_Client() ),
			$media_importer,
			new Content_Processor(
				$media_importer,
				new Content_Media_Processor( $media_importer ),
				new Shortcode_ID_Rewriter()
			),
			$this->repository,
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter(),
			new Attention_Issues_Repository()
		);
	}
}
