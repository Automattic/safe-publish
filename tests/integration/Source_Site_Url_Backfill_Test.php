<?php
/**
 * Integration tests for the source-site scoping backfill.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Navigation_Ref_Rewriter;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\Source_Posts_API\Source_Posts_API_Test_Base;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Source_Site_Url_Backfill;
use Safe_Publish\Utils\Telemetry_Service;

/**
 * Exercises the one-time backfill that attributes pre-scoping imports to their
 * source site so they stay recognized by the path-bearing scoped lookups.
 */
class Source_Site_Url_Backfill_Test extends Source_Posts_API_Test_Base {

	/**
	 * Subsite connection (host plus path).
	 */
	private const SUBSITE = 'https://source.example.com/blog';

	/**
	 * Single-site connection (host only).
	 */
	private const SINGLE_SITE = 'https://source.example.com';

	/**
	 * History repository used to seed and create import session rows.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Import service used by the byte-identity test to import under the
	 * production path.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $import_service;

	/**
	 * Sets up the repository and import service with real dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		// A prior AJAX test class may have defined DOING_AJAX for the process.
		// The backfill skips AJAX, so force a non-AJAX context for the direct
		// maybe_run() calls these tests make.
		add_filter( 'wp_doing_ajax', '__return_false' );

		$this->repository = new History_Repository();

		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		$this->import_service = new Post_Import_Service(
			new Source_Posts_API( new HTTP_Client() ),
			$media_importer,
			$content_processor,
			$this->repository,
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter()
		);
	}

	/**
	 * Tears down the test environment.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'wp_doing_ajax', '__return_false' );
		parent::tearDown();
	}

	/**
	 * Verifies that a single subsite connection backfills keyless posts and
	 * terms with the path-bearing identity an import would write.
	 */
	public function test_single_subsite_connection_backfills_path_bearing_identity(): void {
		// ARRANGE: one subsite source in history, keyless post and term.
		$this->seed_history( self::SUBSITE );
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SUBSITE );
		$post = $this->seed_keyless_post( 50 );
		$term = $this->seed_keyless_term( 51 );

		// ACT: run the backfill.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: both carry the path-bearing identity, byte-identical to the
		// value an import writes for this connection.
		$this->assertSame( self::SUBSITE, $this->post_identity( $post ) );
		$this->assertSame( self::SUBSITE, $this->term_identity( $term ) );
		$this->assertSame(
			Options::get_connected_site_url_with_path(),
			$this->post_identity( $post )
		);
	}

	/**
	 * Verifies that a single-site connection (no path) backfills the host-only
	 * identity.
	 */
	public function test_single_site_connection_backfills_host_only_identity(): void {
		// ARRANGE: one host-only source in history, keyless post and term.
		$this->seed_history( self::SINGLE_SITE );
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SINGLE_SITE );
		$post = $this->seed_keyless_post( 40 );
		$term = $this->seed_keyless_term( 41 );

		// ACT.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: the bare host is written.
		$this->assertSame( self::SINGLE_SITE, $this->post_identity( $post ) );
		$this->assertSame( self::SINGLE_SITE, $this->term_identity( $term ) );
	}

	/**
	 * Verifies that history written by a pre-scoping version (host only, no
	 * path) still backfills the path-bearing identity recovered from the
	 * current subsite connection.
	 */
	public function test_legacy_host_only_history_backfills_path_bearing_identity(): void {
		// ARRANGE: history holds only the host, as a pre-scoping version wrote
		// it, while the connection is a subsite.
		$this->seed_history( self::SINGLE_SITE );
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SUBSITE );
		$post = $this->seed_keyless_post( 60 );

		// ACT.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: the path comes from the connection, not the host-only history.
		$this->assertSame( self::SUBSITE, $this->post_identity( $post ) );
	}

	/**
	 * Verifies that with no import history the backfill attributes keyless
	 * records to the current connection.
	 */
	public function test_no_history_falls_back_to_current_connection(): void {
		// ARRANGE: keyless post but an empty imports table.
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SUBSITE );
		$post = $this->seed_keyless_post( 70 );

		// ACT.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: attributed to the current connection.
		$this->assertSame( self::SUBSITE, $this->post_identity( $post ) );
	}

	/**
	 * Verifies that with no history and no connection the backfill writes
	 * nothing and stays pending, then backfills once a connection is set.
	 */
	public function test_no_signal_defers_until_a_connection_is_set(): void {
		// ARRANGE: a keyless post, empty imports table, no connection.
		update_option( Options::OPTION_CONNECTED_SITE_URL, '' );
		$post = $this->seed_keyless_post( 90 );

		// ACT: a run with no source signal.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: nothing written and not flagged, so a later run can still act.
		$this->assertSame( '', $this->post_identity( $post ) );
		$this->assertFalse( Source_Site_Url_Backfill::needs_attention() );

		// ACT: configure the connection, then run again.
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SUBSITE );
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: the post is now backfilled.
		$this->assertSame( self::SUBSITE, $this->post_identity( $post ) );
	}

	/**
	 * Verifies that the backfill does no work during an AJAX request and leaves
	 * the work for a later page load.
	 */
	public function test_skips_during_ajax_requests(): void {
		// ARRANGE: a resolvable connection with a keyless post.
		$this->seed_history( self::SUBSITE );
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SUBSITE );
		$post = $this->seed_keyless_post( 95 );

		// ACT: run inside an AJAX request (overrides setUp's non-AJAX default).
		add_filter( 'wp_doing_ajax', '__return_true', 20 );
		Source_Site_Url_Backfill::maybe_run();
		remove_filter( 'wp_doing_ajax', '__return_true', 20 );

		// ASSERT: nothing written and no terminal state, so a page load can act.
		$this->assertSame( '', $this->post_identity( $post ) );
		$this->assertFalse( Source_Site_Url_Backfill::needs_attention() );

		// ACT: a later non-AJAX load.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: now backfilled.
		$this->assertSame( self::SUBSITE, $this->post_identity( $post ) );
	}

	/**
	 * Verifies that the backfill skips while another request holds the lock,
	 * then proceeds once it is released.
	 */
	public function test_skips_while_the_lock_is_held(): void {
		// ARRANGE: a resolvable connection with a keyless post, lock held by a
		// simulated concurrent request.
		$this->seed_history( self::SUBSITE );
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SUBSITE );
		$post = $this->seed_keyless_post( 96 );
		wp_cache_add( Source_Site_Url_Backfill::LOCK_KEY, 1, '', 30 );

		// ACT: run while the lock is held.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: nothing written.
		$this->assertSame( '', $this->post_identity( $post ) );

		// ACT: release the lock, then run again.
		wp_cache_delete( Source_Site_Url_Backfill::LOCK_KEY );
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: now backfilled.
		$this->assertSame( self::SUBSITE, $this->post_identity( $post ) );
	}

	/**
	 * Verifies that a destination whose history spans more than one source
	 * writes nothing and is flagged for operator attention.
	 */
	public function test_multi_connection_writes_nothing_and_flags_attention(): void {
		// ARRANGE: two distinct source hosts in history, keyless records.
		$this->seed_history( 'https://source-a.example.com' );
		$this->seed_history( 'https://source-b.example.com' );
		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			'https://source-a.example.com'
		);
		$post = $this->seed_keyless_post( 10 );
		$term = $this->seed_keyless_term( 11 );

		// ACT.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: nothing written, destination flagged.
		$this->assertSame( '', $this->post_identity( $post ) );
		$this->assertSame( '', $this->term_identity( $term ) );
		$this->assertTrue( Source_Site_Url_Backfill::needs_attention() );
	}

	/**
	 * Verifies that a single historical source no longer matching the current
	 * connection writes nothing and is flagged for operator attention.
	 */
	public function test_changed_connection_writes_nothing_and_flags_attention(): void {
		// ARRANGE: history points at one host, the connection at another.
		$this->seed_history( 'https://old-source.example.com' );
		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			'https://new-source.example.com'
		);
		$post = $this->seed_keyless_post( 80 );

		// ACT.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: nothing written, destination flagged.
		$this->assertSame( '', $this->post_identity( $post ) );
		$this->assertTrue( Source_Site_Url_Backfill::needs_attention() );
	}

	/**
	 * Verifies that records already carrying a source-site identity are left
	 * untouched, even when their identity differs from the connection.
	 */
	public function test_already_keyed_records_are_left_untouched(): void {
		// ARRANGE: a post and term keyed to a different source.
		$this->seed_history( self::SUBSITE );
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SUBSITE );

		$keyed_post = self::factory()->post->create();
		update_post_meta( $keyed_post, Options::META_SOURCE_POST_ID, 30 );
		update_post_meta(
			$keyed_post,
			Options::META_SOURCE_SITE_URL,
			'https://elsewhere.example.com'
		);

		$keyed_term = self::factory()->term->create(
			array( 'taxonomy' => 'category' )
		);
		update_term_meta( $keyed_term, Options::META_SOURCE_TERM_ID, 31 );
		update_term_meta(
			$keyed_term,
			Options::META_SOURCE_TERM_URL,
			'https://elsewhere.example.com'
		);

		// ACT.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: their existing identities are preserved.
		$this->assertSame(
			'https://elsewhere.example.com',
			$this->post_identity( $keyed_post )
		);
		$this->assertSame(
			'https://elsewhere.example.com',
			$this->term_identity( $keyed_term )
		);
	}

	/**
	 * Verifies that a site with no keyless records completes silently: nothing
	 * written, nothing flagged, and the run marked done.
	 */
	public function test_no_keyless_records_completes_without_a_notice(): void {
		// ARRANGE: an already-keyed post and no keyless records.
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SUBSITE );
		$keyed = self::factory()->post->create();
		update_post_meta( $keyed, Options::META_SOURCE_POST_ID, 1 );
		update_post_meta( $keyed, Options::META_SOURCE_SITE_URL, self::SUBSITE );

		// ACT.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: not flagged for attention.
		$this->assertFalse( Source_Site_Url_Backfill::needs_attention() );

		// ASSERT: the run is done, so a record appearing later is left alone.
		$late = $this->seed_keyless_post( 2 );
		Source_Site_Url_Backfill::maybe_run();
		$this->assertSame( '', $this->post_identity( $late ) );
	}

	/**
	 * Verifies that once the backfill completes a later run is a no-op, leaving
	 * a record that appears afterward keyless.
	 */
	public function test_completed_backfill_does_not_run_again(): void {
		// ARRANGE: a single-connection backfill that completes.
		$this->seed_history( self::SUBSITE );
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SUBSITE );
		$first = $this->seed_keyless_post( 20 );
		Source_Site_Url_Backfill::maybe_run();
		$this->assertSame( self::SUBSITE, $this->post_identity( $first ) );

		// A keyless record appears after completion.
		$late = $this->seed_keyless_post( 21 );

		// ACT: a second run.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: the done flag short-circuits, so the late record stays keyless.
		$this->assertSame( '', $this->post_identity( $late ) );
	}

	/**
	 * Verifies that keyless posts beyond one batch are all backfilled across
	 * successive admin loads.
	 */
	public function test_backfill_spans_multiple_admin_loads(): void {
		// ARRANGE: one more keyless post than a single batch holds.
		$this->seed_history( self::SUBSITE );
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SUBSITE );
		$post_ids = array();
		for ( $i = 0; $i < 101; $i++ ) {
			$post_ids[] = $this->seed_keyless_post( 1000 + $i );
		}

		// ACT: the first load backfills exactly one batch.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: 100 backfilled, the rest still keyless.
		$this->assertSame( 100, $this->keyed_count( $post_ids ) );

		// ACT: a second load drains the remainder.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: every post now carries the identity.
		$this->assertSame( count( $post_ids ), $this->keyed_count( $post_ids ) );
	}

	/**
	 * Verifies that the backfilled identity is byte-identical to the value an
	 * actual import writes for the same connection.
	 */
	public function test_backfill_matches_the_value_an_import_writes(): void {
		// ARRANGE: import one post under a subsite connection, then add a
		// separate keyless post for the same connection.
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SUBSITE );
		$session_id = $this->repository->create_session( self::SUBSITE, 'single' );
		$imported   = $this->import_service->import_post(
			array(
				'id'        => 4242,
				'title'     => 'Imported',
				'link'      => self::SUBSITE . '/item-4242',
				'post_type' => 'posts',
			),
			$session_id
		);
		$this->assertTrue( $imported['success'] );
		$import_identity = $this->post_identity( $imported['post_id'] );

		$keyless = $this->seed_keyless_post( 4243 );

		// ACT: run the backfill.
		Source_Site_Url_Backfill::maybe_run();

		// ASSERT: the backfilled value equals the imported post's identity.
		$this->assertSame( self::SUBSITE, $import_identity );
		$this->assertSame( $import_identity, $this->post_identity( $keyless ) );
	}

	/**
	 * Seeds an import session row carrying the given source site URL.
	 *
	 * @param string $source_site_url Source site URL to record.
	 */
	private function seed_history( string $source_site_url ): void {
		$this->repository->create_session( $source_site_url, 'single' );
	}

	/**
	 * Creates a keyless post: a source post ID but no source-site identity.
	 *
	 * @param int $source_id Source post ID meta value.
	 * @return int Created post ID.
	 */
	private function seed_keyless_post( int $source_id ): int {
		$post_id = self::factory()->post->create();
		$this->assertIsInt( $post_id );
		update_post_meta( $post_id, Options::META_SOURCE_POST_ID, $source_id );

		return $post_id;
	}

	/**
	 * Creates a keyless term: a source term ID but no source-site identity.
	 *
	 * @param int $source_id Source term ID meta value.
	 * @return int Created term ID.
	 */
	private function seed_keyless_term( int $source_id ): int {
		$term_id = self::factory()->term->create(
			array( 'taxonomy' => 'category' )
		);
		$this->assertIsInt( $term_id );
		update_term_meta( $term_id, Options::META_SOURCE_TERM_ID, $source_id );

		return $term_id;
	}

	/**
	 * Returns the source-site identity stored on a destination post.
	 *
	 * @param int $post_id Destination post ID.
	 * @return string Stored identity meta value.
	 */
	private function post_identity( int $post_id ): string {
		return (string) get_post_meta(
			$post_id,
			Options::META_SOURCE_SITE_URL,
			true
		);
	}

	/**
	 * Returns the source-site identity stored on a destination term.
	 *
	 * @param int $term_id Destination term ID.
	 * @return string Stored identity meta value.
	 */
	private function term_identity( int $term_id ): string {
		return (string) get_term_meta(
			$term_id,
			Options::META_SOURCE_TERM_URL,
			true
		);
	}

	/**
	 * Counts how many of the given posts carry a source-site identity.
	 *
	 * @param int[] $post_ids Destination post IDs.
	 * @return int Number carrying the identity.
	 */
	private function keyed_count( array $post_ids ): int {
		$count = 0;
		foreach ( $post_ids as $post_id ) {
			if ( '' !== $this->post_identity( $post_id ) ) {
				++$count;
			}
		}

		return $count;
	}
}
