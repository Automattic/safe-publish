<?php
/**
 * Integration tests for the attention issues store and detection wiring.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

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
use Safe_Publish\Tests\Integration\Source_Posts_API\Source_Posts_API_Test_Base;
use Safe_Publish\Utils\Attention_Issues_Table;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;

/**
 * Exercises import-time detection: tracked degradations open one upserted issue
 * per (post, type, target), re-imports reconcile them, a forced nav rewrite
 * failure opens an error issue, Retry clears it, and issues stay scoped to the
 * path-bearing source identity.
 */
class Attention_Issues_Test extends Source_Posts_API_Test_Base {

	/**
	 * Connection URL for the first subsite of the shared host.
	 */
	private const BLOG_URL = 'https://source.example.com/blog';

	/**
	 * Connection URL for the second subsite of the shared host.
	 */
	private const NEWS_URL = 'https://source.example.com/news';

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Attention issues repository under test.
	 *
	 * @var Attention_Issues_Repository
	 */
	private Attention_Issues_Repository $attention;

	/**
	 * Import service wired with a real (succeeding) rewriter.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $import_service;

	/**
	 * Sets up the import service and a navigation source mock.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->repository     = new History_Repository();
		$this->attention      = new Attention_Issues_Repository();
		$this->import_service = $this->build_import_service(
			new Navigation_Ref_Rewriter()
		);

		add_filter(
			'pre_http_request',
			array( $this, 'mock_navigation_source' ),
			5,
			3
		);
	}

	/**
	 * Removes the navigation source mock.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'pre_http_request',
			array( $this, 'mock_navigation_source' ),
			5
		);
		parent::tearDown();
	}

	/**
	 * Serves the navigation single-post endpoint; other URLs fall through to the
	 * base mock.
	 *
	 * @param false|array|WP_Error $preempt Short-circuit value passed by WP.
	 * @param array                $args    Request args (unused).
	 * @param string               $url     Requested URL.
	 * @return false|array|WP_Error Mock response, or $preempt to defer.
	 */
	public function mock_navigation_source(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): false|array|WP_Error {
		unset( $args );

		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( 1 === preg_match( '#/wp-json/wp/v2/navigation/\d+#', $url ) ) {
			return $this->build_mock_post_response();
		}

		return $preempt;
	}

	/**
	 * Verifies that importing a post with one unresolved block reference opens a
	 * single warning-level issue keyed by (post, type, target).
	 */
	public function test_unresolved_block_reference_opens_one_issue(): void {
		// ARRANGE & ACT: import a post linking to a not-yet-imported source post.
		$result = $this->import_under(
			self::BLOG_URL,
			7100,
			array( 'content' => $this->single_nav_link_content( 700 ) )
		);

		// ASSERT: exactly one open issue, keyed and scoped as expected.
		$this->assertTrue( $result['success'] );
		$rows = $this->open_rows_for_source( self::BLOG_URL );
		$this->assertCount( 1, $rows );
		$this->assertSame( $result['post_id'], (int) $rows[0]['affected_post_id'] );
		$this->assertSame( 'unmapped_block_reference', $rows[0]['issue_type'] );
		$this->assertSame( 700, (int) $rows[0]['target_ref'] );
		$this->assertSame( 'post', $rows[0]['target_kind'] );
		$this->assertSame( 'warning', $rows[0]['severity'] );
		$this->assertSame( self::BLOG_URL, $rows[0]['source_site_url'] );
	}

	/**
	 * Verifies that two unresolved references to different targets open two
	 * distinct rows for the same post.
	 */
	public function test_two_unresolved_refs_open_two_rows(): void {
		// ARRANGE & ACT: import a post linking to an unimported post and term.
		$result = $this->import_under(
			self::BLOG_URL,
			7101,
			array( 'content' => $this->nav_block_content( 700, 701 ) )
		);

		// ASSERT: one row per target, distinguished by target_ref and kind.
		$this->assertTrue( $result['success'] );
		$rows = $this->open_rows_for_source( self::BLOG_URL );
		$this->assertCount( 2, $rows );

		$by_target = array();
		foreach ( $rows as $row ) {
			$by_target[ (int) $row['target_ref'] ] = $row['target_kind'];
		}
		$this->assertSame( 'post', $by_target[700] ?? null );
		$this->assertSame( 'term', $by_target[701] ?? null );
	}

	/**
	 * Verifies that a post reference and a term reference sharing one numeric
	 * source ID open distinct rows rather than colliding on the identity key.
	 */
	public function test_same_source_id_post_and_term_open_distinct_rows(): void {
		// ARRANGE & ACT: import a post linking to an unimported post and term
		// that share the same numeric source ID.
		$result = $this->import_under(
			self::BLOG_URL,
			7106,
			array( 'content' => $this->nav_block_content( 42, 42 ) )
		);

		// ASSERT: the shared number yields two rows, one per kind.
		$this->assertTrue( $result['success'] );
		$rows = $this->open_rows_for_source( self::BLOG_URL );
		$this->assertCount( 2, $rows );

		$by_kind = array();
		foreach ( $rows as $row ) {
			$by_kind[ $row['target_kind'] ] = (int) $row['target_ref'];
		}
		$this->assertSame( 42, $by_kind['post'] ?? null );
		$this->assertSame( 42, $by_kind['term'] ?? null );
	}

	/**
	 * Verifies that re-detecting the same reference refreshes last_seen_gmt and
	 * preserves first_detected_gmt without inserting a duplicate.
	 */
	public function test_redetected_ref_refreshes_without_duplicate(): void {
		// ARRANGE: import once, then backdate last_seen so a refresh is visible.
		$result  = $this->import_under(
			self::BLOG_URL,
			7102,
			array( 'content' => $this->single_nav_link_content( 700 ) )
		);
		$post_id = $result['post_id'];

		$before = $this->attention->get_issue(
			$post_id,
			'unmapped_block_reference',
			700
		);
		$this->assertNotNull( $before );

		$this->force_last_seen(
			$post_id,
			'unmapped_block_reference',
			700,
			'2000-01-01 00:00:00'
		);

		// ACT: re-import the same post with the same unresolved reference.
		$this->import_under(
			self::BLOG_URL,
			7102,
			array( 'content' => $this->single_nav_link_content( 700 ) )
		);

		// ASSERT: still one row; last_seen refreshed; first_detected preserved.
		$this->assertCount( 1, $this->open_rows_for_source( self::BLOG_URL ) );
		$after = $this->attention->get_issue(
			$post_id,
			'unmapped_block_reference',
			700
		);
		$this->assertNotNull( $after );
		$this->assertNotSame( '2000-01-01 00:00:00', $after['last_seen_gmt'] );
		$this->assertSame(
			$before['first_detected_gmt'],
			$after['first_detected_gmt']
		);
	}

	/**
	 * Verifies that a re-import whose reference now resolves clears the issue.
	 */
	public function test_reimport_resolving_ref_resolves_issue(): void {
		// ARRANGE: import with an unresolved reference, opening an issue.
		$result = $this->import_under(
			self::BLOG_URL,
			7103,
			array( 'content' => $this->single_nav_link_content( 700 ) )
		);
		$this->assertCount( 1, $this->open_rows_for_source( self::BLOG_URL ) );

		// ACT: make the target resolvable, then re-import the same post.
		$this->seed_target_post( 700, self::BLOG_URL );
		$this->import_under(
			self::BLOG_URL,
			7103,
			array( 'content' => $this->single_nav_link_content( 700 ) )
		);

		// ASSERT: the row is resolved.
		$this->assertCount( 0, $this->open_rows_for_source( self::BLOG_URL ) );
		$this->assertNull(
			$this->attention->get_issue(
				$result['post_id'],
				'unmapped_block_reference',
				700
			)
		);
	}

	/**
	 * Verifies that hard-deleting an affected post clears only its own issues,
	 * so a gone post leaves no unfixable rows behind without touching others.
	 */
	public function test_deleting_affected_post_clears_only_its_issues(): void {
		// ARRANGE: two imported posts, each with an unresolved reference.
		$deleted = $this->import_under(
			self::BLOG_URL,
			7107,
			array( 'content' => $this->single_nav_link_content( 700 ) )
		);
		$kept    = $this->import_under(
			self::BLOG_URL,
			7108,
			array( 'content' => $this->single_nav_link_content( 701 ) )
		);
		$this->assertCount( 2, $this->open_rows_for_source( self::BLOG_URL ) );

		// ACT: permanently delete the first post.
		wp_delete_post( $deleted['post_id'], true );

		// ASSERT: its row is gone; the other post's row survives.
		$rows = $this->open_rows_for_source( self::BLOG_URL );
		$this->assertCount( 1, $rows );
		$this->assertSame( $kept['post_id'], (int) $rows[0]['affected_post_id'] );
	}

	/**
	 * Verifies that an orphaned parent opens a warning issue when the opt-in
	 * orphan fallback is enabled.
	 */
	public function test_orphaned_parent_opens_issue_when_enabled(): void {
		// ARRANGE: allow orphan imports for this test only.
		add_filter( 'safe_publish_import_allow_orphans', '__return_true' );

		// ACT: import a child page whose source parent is not on the destination.
		$result = $this->import_under(
			self::BLOG_URL,
			8100,
			array( 'parent' => 850 ),
			'pages'
		);

		remove_filter( 'safe_publish_import_allow_orphans', '__return_true' );

		// ASSERT: a single warning issue keyed to the unresolved parent.
		$this->assertTrue( $result['success'] );
		$rows = $this->open_rows_for_source( self::BLOG_URL );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'parent_orphaned', $rows[0]['issue_type'] );
		$this->assertSame( 850, (int) $rows[0]['target_ref'] );
		$this->assertSame( 'warning', $rows[0]['severity'] );
	}

	/**
	 * Verifies that an author fallback creates no issue while a tracked
	 * degradation on the same import does.
	 */
	public function test_author_fallback_creates_no_issue_but_tracked_type_does(): void {
		// ARRANGE: allow the author fallback (opt-in, off by default).
		add_filter( 'safe_publish_import_allow_author_fallback', '__return_true' );

		// ACT: import with an unknown author and an unresolved reference.
		$result = $this->import_under(
			self::BLOG_URL,
			7104,
			array(
				'content'             => $this->single_nav_link_content( 700 ),
				'safe_publish_author' => array(
					'email'        => 'ghost@example.com',
					'login'        => 'ghost',
					'display_name' => 'Ghost Writer',
				),
			)
		);

		remove_filter(
			'safe_publish_import_allow_author_fallback',
			'__return_true'
		);

		// ASSERT: the author fallback fired but only the tracked ref is an issue.
		$this->assertTrue( $result['success'] );
		$this->assertNotNull(
			$this->find_warning( $result['warnings'], 'author_fallback_applied' )
		);

		$rows = $this->open_rows_for_source( self::BLOG_URL );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'unmapped_block_reference', $rows[0]['issue_type'] );
	}

	/**
	 * Verifies that issues stay scoped to the path-bearing source identity, so
	 * two subsites of one host never collide in the listing.
	 */
	public function test_issues_scoped_by_subsite_identity(): void {
		// ARRANGE & ACT: import the same unresolved reference under two subsites.
		$blog = $this->import_under(
			self::BLOG_URL,
			7105,
			array( 'content' => $this->single_nav_link_content( 700 ) )
		);
		$news = $this->import_under(
			self::NEWS_URL,
			7105,
			array( 'content' => $this->single_nav_link_content( 700 ) )
		);

		// ASSERT: each subsite lists only its own issue.
		$blog_rows = $this->open_rows_for_source( self::BLOG_URL );
		$news_rows = $this->open_rows_for_source( self::NEWS_URL );

		$this->assertCount( 1, $blog_rows );
		$this->assertCount( 1, $news_rows );
		$this->assertSame(
			$blog['post_id'],
			(int) $blog_rows[0]['affected_post_id']
		);
		$this->assertSame(
			$news['post_id'],
			(int) $news_rows[0]['affected_post_id']
		);
		$this->assertNotSame(
			$blog_rows[0]['affected_post_id'],
			$news_rows[0]['affected_post_id']
		);
	}

	/**
	 * Verifies that a navigation rewrite failure opens an error issue and Retry
	 * re-runs the fixup to clear it.
	 */
	public function test_nav_rewrite_failure_opens_error_issue_and_retry_clears_it(): void {
		// ARRANGE: a previously imported menu references menu 8300 by source ID.
		$menu_a = $this->seed_referencing_post(
			$this->nav_ref_block( 8300 ),
			self::BLOG_URL,
			8101
		);

		// ACT: import menu 8300 with a rewriter whose write fails.
		$result = $this->import_under(
			self::BLOG_URL,
			8300,
			array(),
			'wp_navigation',
			$this->build_import_service( $this->failing_rewriter() )
		);

		// ASSERT: an error-level issue is keyed to the still-stale menu.
		$this->assertTrue( $result['success'] );
		$issue = $this->attention->get_issue(
			$menu_a,
			'nav_ref_rewrite_failed',
			8300
		);
		$this->assertNotNull( $issue );
		$this->assertSame( 'error', $issue['severity'] );

		// ACT: retry through a succeeding rewriter.
		$this->import_service->retry_nav_ref_rewrite( 8300, self::BLOG_URL );

		// ASSERT: the issue is cleared and the stale ref repointed.
		$this->assertNull(
			$this->attention->get_issue( $menu_a, 'nav_ref_rewrite_failed', 8300 )
		);
		$this->assertStringNotContainsString(
			'"ref":8300',
			(string) get_post_field( 'post_content', $menu_a )
		);
	}

	/**
	 * Builds a Post_Import_Service wired against real dependencies, the shared
	 * attention repository, and the given rewriter.
	 *
	 * @param Navigation_Ref_Rewriter $rewriter Rewriter to inject.
	 * @return Post_Import_Service Service under test.
	 */
	private function build_import_service(
		Navigation_Ref_Rewriter $rewriter
	): Post_Import_Service {
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
			$rewriter,
			$this->attention
		);
	}

	/**
	 * Returns a rewriter whose persistence step always fails.
	 *
	 * @return Navigation_Ref_Rewriter Rewriter that never persists.
	 */
	private function failing_rewriter(): Navigation_Ref_Rewriter {
		return new class() extends Navigation_Ref_Rewriter {
			/**
			 * Reports a failed write without persisting, simulating a DB error.
			 *
			 * @param int    $post_id     Destination post ID (unused).
			 * @param string $new_content Serialized content (unused).
			 * @return bool Always false.
			 */
			#[\Override]
			protected function persist_rewritten_content(
				int $post_id,
				string $new_content
			): bool {
				unset( $post_id, $new_content );
				return false;
			}
		};
	}

	/**
	 * Imports a single source post under the given connection.
	 *
	 * @param string                   $connection Connected source site URL.
	 * @param int                      $source_id  Source post ID.
	 * @param array                    $overrides  Mock fresh-fetch overrides.
	 * @param string                   $post_type  REST post-type endpoint.
	 * @param Post_Import_Service|null $service    Service override.
	 * @return array Import result.
	 */
	private function import_under(
		string $connection,
		int $source_id,
		array $overrides = array(),
		string $post_type = 'posts',
		?Post_Import_Service $service = null
	): array {
		update_option( Options::OPTION_CONNECTED_SITE_URL, $connection );
		$this->mock_post_overrides = $overrides;

		$session_id = $this->repository->create_session( $connection, 'single' );

		return ( $service ?? $this->import_service )->import_post(
			array(
				'id'        => $source_id,
				'title'     => $overrides['title'] ?? 'Post ' . $source_id,
				'link'      => $connection . '/item-' . $source_id,
				'post_type' => $post_type,
			),
			$session_id
		);
	}

	/**
	 * Returns the open issue rows for a source identity, ordered by target.
	 *
	 * @param string $source_site_url Source identity to filter by.
	 * @return array[] Open issue rows.
	 */
	private function open_rows_for_source( string $source_site_url ): array {
		$rows = $this->attention->get_open_issues( $source_site_url, 1, 100 );

		usort(
			$rows,
			static fn( array $a, array $b ): int =>
				(int) $a['target_ref'] <=> (int) $b['target_ref']
		);

		return $rows;
	}

	/**
	 * Backdates an issue's last_seen_gmt so a refresh is observable.
	 *
	 * @param int    $affected_post_id Destination post ID.
	 * @param string $issue_type       Issue type.
	 * @param int    $target_ref       Target source ID.
	 * @param string $value            MySQL datetime to set.
	 */
	private function force_last_seen(
		int $affected_post_id,
		string $issue_type,
		int $target_ref,
		string $value
	): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Attention_Issues_Table::table_name(),
			array( 'last_seen_gmt' => $value ),
			array(
				'affected_post_id' => $affected_post_id,
				'issue_type'       => $issue_type,
				'target_ref'       => $target_ref,
			),
			array( '%s' ),
			array( '%d', '%s', '%d' )
		);
	}

	/**
	 * Creates a destination post tagged with a source post ID and identity.
	 *
	 * @param int    $source_id Source post ID meta value.
	 * @param string $identity  Source site identity meta value.
	 * @return int Created post ID.
	 */
	private function seed_target_post( int $source_id, string $identity ): int {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertIsInt( $post_id );
		update_post_meta( $post_id, Options::META_SOURCE_POST_ID, $source_id );
		update_post_meta( $post_id, Options::META_SOURCE_SITE_URL, $identity );

		return $post_id;
	}

	/**
	 * Creates an imported navigation post carrying the source-tracking meta the
	 * rewriter scopes by.
	 *
	 * @param string $content         Post content.
	 * @param string $source_site_url Source site URL meta value.
	 * @param int    $source_post_id  Source post ID meta value.
	 * @return int Created post ID.
	 */
	private function seed_referencing_post(
		string $content,
		string $source_site_url,
		int $source_post_id
	): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		$this->assertIsInt( $post_id );
		update_post_meta( $post_id, Options::META_SOURCE_POST_ID, $source_post_id );
		update_post_meta( $post_id, Options::META_SOURCE_SITE_URL, $source_site_url );

		return $post_id;
	}

	/**
	 * Returns a serialized void core/navigation block referencing $ref.
	 *
	 * @param int $ref Menu post ID to embed.
	 * @return string Serialized block markup.
	 */
	private function nav_ref_block( int $ref ): string {
		return '<!-- wp:navigation {"ref":' . $ref . '} /-->';
	}

	/**
	 * Builds a core/navigation block with a single post-type nav-link
	 * referencing the given source post ID.
	 *
	 * @param int $post_source_id Source post ID in the nav-link.
	 * @return string Block markup.
	 */
	private function single_nav_link_content( int $post_source_id ): string {
		$post_link = wp_json_encode(
			array(
				'id'    => $post_source_id,
				'kind'  => 'post-type',
				'label' => 'About',
				'url'   => 'https://source.example.com/about',
			)
		);

		return implode(
			"\n",
			array(
				'<!-- wp:navigation -->',
				'<!-- wp:navigation-link ' . $post_link . ' /-->',
				'<!-- /wp:navigation -->',
			)
		);
	}

	/**
	 * Builds a core/navigation block with a post-type and a taxonomy nav-link
	 * referencing the given source IDs.
	 *
	 * @param int $post_source_id Source post ID in the post-type nav-link.
	 * @param int $term_source_id Source term ID in the taxonomy nav-link.
	 * @return string Block markup.
	 */
	private function nav_block_content(
		int $post_source_id,
		int $term_source_id
	): string {
		$post_link = wp_json_encode(
			array(
				'id'    => $post_source_id,
				'kind'  => 'post-type',
				'label' => 'About',
				'url'   => 'https://source.example.com/about',
			)
		);
		$term_link = wp_json_encode(
			array(
				'id'    => $term_source_id,
				'kind'  => 'taxonomy',
				'type'  => 'category',
				'label' => 'News',
				'url'   => 'https://source.example.com/category/news',
			)
		);

		return implode(
			"\n",
			array(
				'<!-- wp:navigation -->',
				'<!-- wp:navigation-link ' . $post_link . ' /-->',
				'<!-- wp:navigation-link ' . $term_link . ' /-->',
				'<!-- /wp:navigation -->',
			)
		);
	}

	/**
	 * Returns the first warning of the given type, or null.
	 *
	 * @param array  $warnings Warnings list from an import result.
	 * @param string $type     Warning type to find.
	 * @return array|null Matching warning, or null.
	 */
	private function find_warning( array $warnings, string $type ): ?array {
		foreach ( $warnings as $warning ) {
			if ( ( $warning['type'] ?? '' ) === $type ) {
				return $warning;
			}
		}

		return null;
	}
}
