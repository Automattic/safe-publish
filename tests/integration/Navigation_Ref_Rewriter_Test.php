<?php
/**
 * Integration tests for Navigation_Ref_Rewriter.
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
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;
use WP_Post;

/**
 * Exercises Navigation_Ref_Rewriter directly and through a full menu import,
 * asserting stale core/navigation refs are repointed to the destination ID,
 * scoped to the source site, without touching post_modified.
 */
class Navigation_Ref_Rewriter_Test extends Integration_Test_Case {

	use Mock_Post_API_Trait;

	private const SOURCE_A = 'https://source.example.com';
	private const SOURCE_B = 'https://other-source.example.com';

	/**
	 * Configures the connected source and a navigation single-post mock so the
	 * end-to-end import tests can fetch fresh menu content.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->mock_post_overrides = array(
			'omit_excerpt' => true,
			'type'         => 'wp_navigation',
		);

		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE_A );
		add_filter(
			'pre_http_request',
			array( $this, 'mock_navigation_source' ),
			5,
			3
		);
	}

	/**
	 * Removes the navigation mock and connected-site option.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'pre_http_request',
			array( $this, 'mock_navigation_source' ),
			5
		);
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Serves the navigation single-post endpoint; other URLs fall through.
	 *
	 * @param false|array|WP_Error $preempt Short-circuit value passed by WP.
	 * @param array                $_args   Request arguments.
	 * @param string               $url     Requested URL.
	 * @return false|array|WP_Error Mock response, or $preempt to defer.
	 */
	public function mock_navigation_source(
		false|array|WP_Error $preempt,
		array $_args,
		string $url
	): false|array|WP_Error {
		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( 1 === preg_match( '#/wp-json/wp/v2/navigation/\d+#', $url ) ) {
			return $this->build_mock_post_response();
		}

		return $preempt;
	}

	/**
	 * Verifies that a stale ref is repointed to the destination ID, an audit
	 * timestamp is written, and post_modified is left untouched.
	 */
	public function test_rewrites_stale_ref_and_preserves_post_modified(): void {
		// ARRANGE: An imported page that still references the source menu ID.
		$post_id         = $this->seed_referencing_post(
			$this->nav_ref_block( 99001 ),
			self::SOURCE_A,
			91001
		);
		$modified_before = get_post( $post_id )->post_modified;

		// ACT: Rewrite refs to 99001 onto destination menu 50001.
		$result = ( new Navigation_Ref_Rewriter() )->rewrite_cross_refs(
			99001,
			50001,
			self::SOURCE_A
		);

		// ASSERT: One rewrite, content repointed, timestamp set, modified kept.
		$this->assertSame( 1, $result['rewritten'] );
		$this->assertSame( array(), $result['failed'] );

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertStringContainsString( '"ref":50001', $post->post_content );
		$this->assertStringNotContainsString( '"ref":99001', $post->post_content );
		$this->assertSame( $modified_before, $post->post_modified );
		$rewritten_block = parse_blocks( $post->post_content )[0];
		$this->assertSame(
			array(
				array(
					'post_id' => $post_id,
					'blocks'  => array(
						array(
							'after_block_hash' => $this->compact_hash(
								serialize_block( $rewritten_block )
							),
							'paths'            => array( array( 0 ) ),
						),
					),
				),
			),
			$result['changes']
		);
		$this->assertNotSame(
			'',
			get_post_meta(
				$post_id,
				Navigation_Ref_Rewriter::META_REWRITTEN_AT,
				true
			)
		);
	}

	/**
	 * Verifies that only posts imported from the scoped source are rewritten,
	 * so two destinations sharing a numeric source ID do not collide.
	 */
	public function test_skips_posts_from_a_different_source(): void {
		// ARRANGE: Two posts hold the same ref but were imported from
		// different sources.
		$source_a_post = $this->seed_referencing_post(
			$this->nav_ref_block( 99002 ),
			self::SOURCE_A,
			91002
		);
		$source_b_post = $this->seed_referencing_post(
			$this->nav_ref_block( 99002 ),
			self::SOURCE_B,
			91003
		);

		// ACT: Rewrite under source A only.
		$result = ( new Navigation_Ref_Rewriter() )->rewrite_cross_refs(
			99002,
			50002,
			self::SOURCE_A
		);

		// ASSERT: Source A repointed; source B left untouched.
		$this->assertSame( 1, $result['rewritten'] );
		$this->assertStringContainsString(
			'"ref":50002',
			get_post( $source_a_post )->post_content
		);
		$this->assertStringContainsString(
			'"ref":99002',
			get_post( $source_b_post )->post_content
		);
	}

	/**
	 * Verifies that rewriting scopes by the full subsite identity, so two
	 * subsites of one host sharing a numeric source ID do not collide.
	 */
	public function test_skips_posts_from_a_different_subsite(): void {
		// ARRANGE: Two posts hold the same ref but were imported from two
		// subsites of the same host.
		$blog_url  = self::SOURCE_A . '/blog';
		$news_url  = self::SOURCE_A . '/news';
		$blog_post = $this->seed_referencing_post(
			$this->nav_ref_block( 99008 ),
			$blog_url,
			91009
		);
		$news_post = $this->seed_referencing_post(
			$this->nav_ref_block( 99008 ),
			$news_url,
			91010
		);

		// ACT: Rewrite under the /blog subsite only.
		$result = ( new Navigation_Ref_Rewriter() )->rewrite_cross_refs(
			99008,
			50008,
			$blog_url
		);

		// ASSERT: The /blog post is repointed; /news is left untouched.
		$this->assertSame( 1, $result['rewritten'] );
		$this->assertStringContainsString(
			'"ref":50008',
			get_post( $blog_post )->post_content
		);
		$this->assertStringContainsString(
			'"ref":99008',
			get_post( $news_post )->post_content
		);
	}

	/**
	 * Verifies that auto-draft posts are excluded from the candidate query.
	 */
	public function test_skips_auto_draft_posts(): void {
		// ARRANGE: An auto-draft that would otherwise match.
		$post_id = $this->seed_referencing_post(
			$this->nav_ref_block( 99003 ),
			self::SOURCE_A,
			91004,
			'post',
			'auto-draft'
		);

		// ACT: Run the rewrite against the seeded auto-draft.
		$result = ( new Navigation_Ref_Rewriter() )->rewrite_cross_refs(
			99003,
			50003,
			self::SOURCE_A
		);

		// ASSERT: Nothing rewritten; content unchanged.
		$this->assertSame( 0, $result['rewritten'] );
		$this->assertStringContainsString(
			'"ref":99003',
			get_post( $post_id )->post_content
		);
	}

	/**
	 * Verifies that trashed posts are excluded from the candidate query, so a
	 * user-trashed import is not silently rewritten.
	 */
	public function test_skips_trashed_posts(): void {
		// ARRANGE: A trashed post that would otherwise match.
		$post_id = $this->seed_referencing_post(
			$this->nav_ref_block( 99007 ),
			self::SOURCE_A,
			91008,
			'post',
			'trash'
		);

		// ACT: Run the rewrite against the trashed post's source menu.
		$result = ( new Navigation_Ref_Rewriter() )->rewrite_cross_refs(
			99007,
			50007,
			self::SOURCE_A
		);

		// ASSERT: Nothing rewritten; content unchanged.
		$this->assertSame( 0, $result['rewritten'] );
		$this->assertStringContainsString(
			'"ref":99007',
			get_post( $post_id )->post_content
		);
	}

	/**
	 * Verifies that a core/navigation block nested inside another block is
	 * rewritten via the recursive walk.
	 */
	public function test_rewrites_nested_navigation_block(): void {
		// ARRANGE: The navigation block sits inside a group container.
		$content = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
			. '<div class="wp-block-group">'
			. $this->nav_ref_block( 99004 )
			. '</div><!-- /wp:group -->';
		$post_id = $this->seed_referencing_post(
			$content,
			self::SOURCE_A,
			91005
		);

		// ACT: Run the rewrite for the nested navigation block.
		$result = ( new Navigation_Ref_Rewriter() )->rewrite_cross_refs(
			99004,
			50004,
			self::SOURCE_A
		);

		// ASSERT: The nested ref was repointed.
		$this->assertSame( 1, $result['rewritten'] );
		$post = get_post( $post_id );
		$this->assertStringContainsString( '"ref":50004', $post->post_content );
		$this->assertStringNotContainsString( '"ref":99004', $post->post_content );
	}

	/**
	 * Verifies that an empty source URL refuses to rewrite anything, leaving an
	 * otherwise matching post untouched.
	 */
	public function test_empty_source_url_rewrites_nothing(): void {
		// ARRANGE: A post whose own source URL is empty, so only the guard —
		// not the query's scoping — keeps it from being rewritten.
		$post_id = $this->seed_referencing_post(
			$this->nav_ref_block( 99005 ),
			'',
			91006
		);

		// ACT: Opt out of scoping.
		$result = ( new Navigation_Ref_Rewriter() )->rewrite_cross_refs(
			99005,
			50005,
			''
		);

		// ASSERT: No rewrite, post untouched.
		$this->assertSame( 0, $result['rewritten'] );
		$this->assertSame( array(), $result['failed'] );
		$this->assertStringContainsString(
			'"ref":99005',
			get_post( $post_id )->post_content
		);
	}

	/**
	 * Verifies that a post whose write fails is reported in the failed list and
	 * left unchanged, rather than silently dropped.
	 */
	public function test_reports_failed_posts_without_modifying_them(): void {
		// ARRANGE: A matching post and a rewriter whose write always fails.
		$post_id          = $this->seed_referencing_post(
			$this->nav_ref_block( 99006 ),
			self::SOURCE_A,
			91007
		);
		$failing_rewriter = $this->failing_rewriter();

		// ACT: Run the rewrite with a failing writer.
		$result = $failing_rewriter->rewrite_cross_refs(
			99006,
			50006,
			self::SOURCE_A
		);

		// ASSERT: The post is reported failed, not rewritten, and untouched.
		$this->assertSame( 0, $result['rewritten'] );
		$this->assertSame( array( $post_id ), $result['failed'] );
		$this->assertStringContainsString(
			'"ref":99006',
			get_post( $post_id )->post_content
		);
		$this->assertSame(
			'',
			get_post_meta(
				$post_id,
				Navigation_Ref_Rewriter::META_REWRITTEN_AT,
				true
			)
		);
	}

	/**
	 * Verifies that importing a menu repoints a previously imported navigation
	 * that referenced it by source ID, covering both the import hook and the
	 * in-batch inter-nav case.
	 */
	public function test_menu_import_repoints_referencing_navigation(): void {
		// ARRANGE: A previously imported menu A embedding menu B (source 8200).
		$menu_a = $this->seed_referencing_post(
			$this->nav_ref_block( 8200 ),
			self::SOURCE_A,
			8100,
			'wp_navigation'
		);

		$repository = new History_Repository();
		$session_id = $repository->create_session( self::SOURCE_A, 'single' );

		// ACT: Import menu B (source 8200) through the full import path.
		$result = $this->build_import_service(
			new Navigation_Ref_Rewriter(),
			$repository
		)
			->import_post(
				array(
					'id'        => 8200,
					'title'     => 'Menu B',
					'link'      => self::SOURCE_A . '/menu-b',
					'post_type' => 'wp_navigation',
				),
				$session_id
			);

		// ASSERT: Import succeeded and menu A now points at menu B's dest ID.
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString(
			'"ref":' . (int) $result['post_id'],
			get_post( $menu_a )->post_content
		);
		$this->assertStringNotContainsString(
			'"ref":8200',
			get_post( $menu_a )->post_content
		);
		$items   = $repository->get_session_items_by_status(
			$session_id,
			array( 'success' )
		);
		$changes = History_Repository::decode_item_changes(
			$items[0]['content_changes']
		);
		$this->assertIsArray( $changes );
		$this->assertArrayNotHasKey( 'previous_content', $changes );
		$nav = $changes['rollback_snapshot']['navigation_rewrites'];
		$this->assertSame( 8200, $nav['source_ref'] );
		$this->assertSame( (int) $result['post_id'], $nav['destination_ref'] );
		$this->assertSame( $menu_a, $nav['posts'][0]['post_id'] );
		$this->assertSame( array( array( 0 ) ), $nav['posts'][0]['blocks'][0]['paths'] );
	}

	/**
	 * Verifies that a rewrite failure during a menu import surfaces a warning
	 * naming the still-stale post, without failing the import.
	 */
	public function test_menu_import_surfaces_warning_on_rewrite_failure(): void {
		// ARRANGE: A referencing menu and an import service whose rewriter
		// fails to persist.
		$menu_a = $this->seed_referencing_post(
			$this->nav_ref_block( 8300 ),
			self::SOURCE_A,
			8101,
			'wp_navigation'
		);

		// ACT: Import the referenced menu (source 8300).
		$result = $this->build_import_service( $this->failing_rewriter() )
			->import_post(
				array(
					'id'        => 8300,
					'title'     => 'Menu C',
					'link'      => self::SOURCE_A . '/menu-c',
					'post_type' => 'wp_navigation',
				)
			);

		// ASSERT: Import still succeeds, and a warning names the stale post.
		$this->assertTrue( $result['success'] );
		$warning = $this->find_warning(
			$result['warnings'],
			'nav_ref_rewrite_failed'
		);
		$this->assertNotNull( $warning );
		$this->assertContains( $menu_a, $warning['failed_post_ids'] );
	}

	/**
	 * Verifies that an unrecordable new-menu import restores cross-post refs.
	 */
	public function test_history_failure_restores_navigation_rewrites(): void {
		// ARRANGE: An imported post references a menu that is about to arrive.
		$referencing_post = $this->seed_referencing_post(
			$this->nav_ref_block( 8400 ),
			self::SOURCE_A,
			8102
		);
		$repository       = new History_Repository();
		$session_id       = $repository->create_session( self::SOURCE_A, 'single' );
		$attention        = new Attention_Issues_Repository();
		$attention->upsert_issue(
			$referencing_post,
			'nav_ref_rewrite_failed',
			8400,
			'post',
			'error',
			self::SOURCE_A,
			array( 'source_nav_id' => 8400 )
		);

		global $wpdb;
		$history_table = $wpdb->prefix . 'safe_publish_import_items';
		$fail_insert   = static function ( string $query ) use ( $history_table ): string {
			if ( str_starts_with( $query, "INSERT INTO `$history_table`" ) ) {
				return str_replace( $history_table, $history_table . '_missing', $query );
			}

			return $query;
		};
		add_filter( 'query', $fail_insert );
		$wpdb->suppress_errors();

		// ACT: Import the menu while its success row cannot be written.
		$result = $this->build_import_service(
			new Navigation_Ref_Rewriter(),
			$repository,
			$attention
		)->import_post(
			array(
				'id'        => 8400,
				'title'     => 'Unrecorded Menu',
				'link'      => self::SOURCE_A . '/unrecorded-menu',
				'post_type' => 'wp_navigation',
			),
			$session_id
		);

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $fail_insert );

		// ASSERT: The import fails and the referencing post returns to its
		// source ref rather than pointing at the discarded destination menu.
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString(
			'"ref":8400',
			get_post( $referencing_post )->post_content
		);
		$this->assertSame(
			array(),
			$repository->get_session_items_by_status(
				$session_id,
				array( 'success' )
			)
		);
		$this->assertIsArray(
			$attention->get_issue(
				$referencing_post,
				'nav_ref_rewrite_failed',
				8400
			)
		);
	}

	/**
	 * Verifies that compensation does not overwrite a concurrent nav edit.
	 */
	public function test_history_failure_preserves_concurrent_navigation_edit(): void {
		// ARRANGE: A referencing post that changes again before compensation.
		$referencing_post = $this->seed_referencing_post(
			$this->nav_ref_block( 8401 ),
			self::SOURCE_A,
			8103
		);
		$repository       = new History_Repository();
		$session_id       = $repository->create_session( self::SOURCE_A, 'single' );
		$concurrent       = '<!-- wp:paragraph --><p>Concurrent edit.</p><!-- /wp:paragraph -->';

		global $wpdb;
		$history_table = $wpdb->prefix . 'safe_publish_import_items';
		$fail_insert   = static function ( string $query ) use (
			$history_table,
			$referencing_post,
			$concurrent,
			$wpdb
		): string {
			if ( str_starts_with( $query, "INSERT INTO `$history_table`" ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Simulates a concurrent writer during the history insert.
				$wpdb->update(
					$wpdb->posts,
					array( 'post_content' => $concurrent ),
					array( 'ID' => $referencing_post )
				);
				clean_post_cache( $referencing_post );

				return str_replace( $history_table, $history_table . '_missing', $query );
			}

			return $query;
		};
		add_filter( 'query', $fail_insert );
		$wpdb->suppress_errors();

		// ACT: Fail history after the concurrent writer replaces the rewrite.
		$result = $this->build_import_service(
			new Navigation_Ref_Rewriter(),
			$repository
		)->import_post(
			array(
				'id'        => 8401,
				'title'     => 'Concurrent Menu',
				'link'      => self::SOURCE_A . '/concurrent-menu',
				'post_type' => 'wp_navigation',
			),
			$session_id
		);

		$wpdb->suppress_errors( false );
		remove_filter( 'query', $fail_insert );

		// ASSERT: Compensation reports failure and preserves the newer edit.
		$this->assertFalse( $result['success'] );
		$this->assertSame( $concurrent, get_post( $referencing_post )->post_content );
	}

	/**
	 * Builds a Post_Import_Service wired against real dependencies and the
	 * given rewriter.
	 *
	 * @param Navigation_Ref_Rewriter          $rewriter   Rewriter to inject.
	 * @param History_Repository|null          $repository History repository, or null.
	 * @param Attention_Issues_Repository|null $attention Attention repository, or null.
	 * @return Post_Import_Service Service under test.
	 */
	private function build_import_service(
		Navigation_Ref_Rewriter $rewriter,
		?History_Repository $repository = null,
		?Attention_Issues_Repository $attention = null
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
			$repository ?? new History_Repository(),
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			$rewriter,
			$attention ?? new Attention_Issues_Repository()
		);
	}

	/**
	 * Returns a rewriter whose persistence step always fails, simulating a
	 * write error.
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
	 * Creates an imported post carrying the source-tracking meta the rewriter
	 * scopes by.
	 *
	 * @param string $content         Post content.
	 * @param string $source_site_url Source site URL meta value.
	 * @param int    $source_post_id  Source post ID meta value.
	 * @param string $post_type       Destination post type.
	 * @param string $post_status     Destination post status.
	 * @return int Created post ID.
	 */
	private function seed_referencing_post(
		string $content,
		string $source_site_url,
		int $source_post_id,
		string $post_type = 'post',
		string $post_status = 'publish'
	): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => $post_type,
				'post_status'  => $post_status,
				'post_content' => $content,
			)
		);
		$this->assertIsInt( $post_id );

		update_post_meta(
			$post_id,
			Options::META_SOURCE_POST_ID,
			$source_post_id
		);
		update_post_meta(
			$post_id,
			Options::META_SOURCE_SITE_URL,
			$source_site_url
		);

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
	 * Returns the version-1 compact digest for a rewritten block.
	 *
	 * @param string $value Value to hash.
	 * @return string Unpadded Base64URL SHA-256 digest.
	 */
	private function compact_hash( string $value ): string {
		return rtrim(
			strtr( base64_encode( hash( 'sha256', $value, true ) ), '+/', '-_' ),
			'='
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
