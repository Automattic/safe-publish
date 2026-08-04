<?php
/**
 * Seeder content parity integration test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Admin_Ajax_Controller;
use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Seeder\Content_Generator;
use Safe_Publish\Utils\Attention_Issues_Table;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use Safe_Publish\Utils\Options;
use WP_UnitTestCase;
use WP_UnitTest_Factory;

/**
 * Seeder Content Parity Test Class.
 *
 * Imports a seeded batch once in wpSetUpBeforeClass() and runs read-only parity
 * assertions across many test methods. Coverage is scoped to the rules the
 * asserter classifies; the asserter itself is the source of truth for which
 * fields, meta keys, and term assignments are checked versus deferred.
 */
class Seeder_Content_Parity_Test extends WP_UnitTestCase {

	/**
	 * Fallback shared secret used when no environment constant is defined.
	 */
	private const FALLBACK_SECRET = 'integration-test-shared-secret-32c';

	/**
	 * Source-site base URL passed to the generator and asserted against.
	 */
	private const SOURCE_BASE_URL = 'https://source.example.com';

	/**
	 * Fixed reference Unix timestamp (2025-01-01 00:00:00 UTC) so generated
	 * dates are deterministic across runs.
	 */
	private const REFERENCE_TIME = 1735689600;

	/**
	 * Source post ID where the synthetic post batch starts. Kept far above the
	 * integration suite's wp_posts AUTO_INCREMENT range so a fresh dest post ID
	 * can never coincidentally equal a source ID (see
	 * test_id_diverges_from_source).
	 */
	private const SOURCE_ID_BASE = 9000000;

	/**
	 * Source post ID where the synthetic page batch starts. Offset from the
	 * post base so post and page source IDs never collide, in the same high
	 * range as SOURCE_ID_BASE.
	 */
	private const SOURCE_PAGE_ID_BASE = 9100000;

	/**
	 * Source media ID where the synthetic batch starts. Same high range, kept
	 * distinct from the post and page bases.
	 */
	private const SOURCE_MEDIA_ID_BASE = 9500000;

	/**
	 * Source post IDs for the bespoke edge-case pages (non-ASCII, empty, embed,
	 * footnotes, reusable-block, cross-post gallery, the gallery/playlist
	 * shortcodes, and the bare gallery/playlist). Same high range, distinct from
	 * every other base. All seed as pages so the category-less body never picks
	 * up the default category that WordPress auto-assigns to a post.
	 */
	private const EDGE_NON_ASCII_SOURCE_ID          = 9200001;
	private const EDGE_EMPTY_SOURCE_ID              = 9200002;
	private const EDGE_EMBED_SOURCE_ID              = 9200003;
	private const EDGE_FOOTNOTES_SOURCE_ID          = 9200004;
	private const EDGE_REUSABLE_BLOCK_SOURCE_ID     = 9200005;
	private const EDGE_GALLERY_SHORTCODE_SOURCE_ID  = 9200006;
	private const EDGE_PLAYLIST_SHORTCODE_SOURCE_ID = 9200007;
	private const EDGE_BARE_GALLERY_SOURCE_ID       = 9200008;
	private const EDGE_BARE_PLAYLIST_SOURCE_ID      = 9200009;
	private const EDGE_CROSS_POST_GALLERY_SOURCE_ID = 9200010;

	/**
	 * Source attachment IDs the gallery-shortcode edge page references, spread
	 * across its ids, include, and exclude attributes so all three are rewritten
	 * end-to-end. Kept clear of the SOURCE_MEDIA_ID_BASE slice-image range so a
	 * dest attachment ID can never coincide with a source ID.
	 *
	 * @var list<int>
	 */
	private const GALLERY_SHORTCODE_MEDIA_IDS = array(
		9600001,
		9600002,
		9600003,
		9600004,
	);

	/**
	 * Source attachment ID referenced by the playlist-shortcode edge page.
	 *
	 * @var list<int>
	 */
	private const PLAYLIST_SHORTCODE_MEDIA_IDS = array( 9600005 );

	/**
	 * Source attachment IDs the bare-gallery edge page's attached image set is
	 * seeded from, in the same 9600xxx shortcode-media range.
	 *
	 * @var list<int>
	 */
	private const BARE_GALLERY_MEDIA_IDS = array( 9600006, 9600007 );

	/**
	 * Source attachment ID the bare-playlist edge page's attached video set is
	 * seeded from.
	 *
	 * @var list<int>
	 */
	private const BARE_PLAYLIST_MEDIA_IDS = array( 9600008 );

	/**
	 * Provider host of the embed edge page's url. Distinct from
	 * SOURCE_BASE_URL's host so test_embed_url_parity exercises an external URL
	 * the importer must leave untouched.
	 */
	private const EDGE_EMBED_PROVIDER_HOST = 'www.youtube.com';

	/**
	 * Class-wide imported batch shared by every test method.
	 *
	 * @var Seeder_Parity_Fixture
	 */
	private static Seeder_Parity_Fixture $fixture;

	/**
	 * Admin user that runs the import and authors the post slice.
	 *
	 * @var int
	 */
	private static int $admin_user_id;

	/**
	 * Authors the page slice, distinct from the importing admin so the suite
	 * exercises resolution to a non-current user.
	 *
	 * @var int
	 */
	private static int $page_author_id;

	/**
	 * Defines the auth secret and history tables, creates the admin user and
	 * connected-site option, then seeds and imports the batch once. The import
	 * is committed here because each test method runs in a rolled-back
	 * transaction.
	 *
	 * @param WP_UnitTest_Factory $factory Shared fixture factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		Imports_Table::create_table();
		Import_Items_Table::create_table();
		Attention_Issues_Table::create_table();

		self::$admin_user_id  = $factory->user->create(
			array( 'role' => 'administrator' )
		);
		self::$page_author_id = $factory->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'page-author@dest.example',
				'user_login' => 'page-author',
			)
		);
		wp_set_current_user( self::$admin_user_id );

		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			self::SOURCE_BASE_URL
		);

		self::$fixture = new Seeder_Parity_Fixture(
			self::SOURCE_BASE_URL,
			self::REFERENCE_TIME,
			self::SOURCE_MEDIA_ID_BASE,
			self::$admin_user_id,
			self::batch_slices( self::$admin_user_id, self::$page_author_id ),
			self::batch_edge_cases( self::$page_author_id )
		);
		self::$fixture->seed();
	}

	/**
	 * Removes everything the class committed: the imported posts, attachments,
	 * and terms, the admin user, and the connected-site URL.
	 */
	public static function wpTearDownAfterClass(): void {
		self::$fixture->cleanup();
		self::delete_user( self::$admin_user_id );
		self::delete_user( self::$page_author_id );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
	}

	/**
	 * Restores the importing user so attachment-author assertions see the same
	 * current user the committed fixture was imported under.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::$admin_user_id );
	}

	/**
	 * Describes the batch: six posts covering the generator's full editor,
	 * status, and image-mode rotations, plus four pages for post_type coverage.
	 * Page index 3 lands on the classic editor, so the batch exercises block and
	 * shortcode parity on pages too; it is also seeded as a child of page index
	 * 1, non-adjacent in import order, so post_parent resolution is proven by
	 * source ID rather than import position. Pages carry no category/post_tag
	 * assignments, mirroring a real page source. Posts stay flat
	 * (non-hierarchical). Even-indexed bodies carry non-default
	 * comment/ping/password/menu_order scalars and odd-indexed stay on the
	 * WordPress defaults (see Seeder_Parity_Fixture::scalars_for_index()).
	 * Bespoke edge cases are seeded separately by batch_edge_cases().
	 *
	 * @param int $post_author_id Dest user the post slice is authored by.
	 * @param int $page_author_id Dest user the page slice is authored by.
	 * @return list<array{type: string, endpoint: string, count: int, source_id_base: int, assign_terms: bool, author_user_id: int, parent_links: array<int, int>}>
	 */
	private static function batch_slices(
		int $post_author_id,
		int $page_author_id
	): array {
		return array(
			array(
				'type'           => 'post',
				'endpoint'       => 'posts',
				'count'          => 6,
				'source_id_base' => self::SOURCE_ID_BASE,
				'assign_terms'   => true,
				'author_user_id' => $post_author_id,
				'parent_links'   => array(),
			),
			array(
				'type'           => 'page',
				'endpoint'       => 'pages',
				'count'          => 4,
				'source_id_base' => self::SOURCE_PAGE_ID_BASE,
				'assign_terms'   => false,
				'author_user_id' => $page_author_id,
				'parent_links'   => array( 3 => 1 ),
			),
		);
	}

	/**
	 * Describes the bespoke edge-case bodies appended to the batch: a non-ASCII
	 * page (multibyte title/slug/content plus unescaped entities), an
	 * empty-content page, an embed page (a core/embed block with an external
	 * provider url), a footnotes page (a core/footnotes block plus matching
	 * footnotes meta), a reusable-block page (a core/block referencing an
	 * unimported wp_block), a cross-post gallery page (a [gallery id] referencing
	 * an unimported post), and gallery/playlist shortcode pages (bare source
	 * attachment IDs the rewriter resolves and rewrites). All seed as pages so
	 * the category-less body never picks up WordPress' default category, and all
	 * stay top-level on default scalars; the fixture supplies their content.
	 *
	 * @param int $page_author_id Dest user the edge-case pages are authored by.
	 * @return list<array{kind: string, endpoint: string, source_id: int, author_user_id: int, media_ids?: list<int>}>
	 */
	private static function batch_edge_cases( int $page_author_id ): array {
		return array(
			array(
				'kind'           => 'non_ascii',
				'endpoint'       => 'pages',
				'source_id'      => self::EDGE_NON_ASCII_SOURCE_ID,
				'author_user_id' => $page_author_id,
			),
			array(
				'kind'           => 'empty',
				'endpoint'       => 'pages',
				'source_id'      => self::EDGE_EMPTY_SOURCE_ID,
				'author_user_id' => $page_author_id,
			),
			array(
				'kind'           => 'embed',
				'endpoint'       => 'pages',
				'source_id'      => self::EDGE_EMBED_SOURCE_ID,
				'author_user_id' => $page_author_id,
			),
			array(
				'kind'           => 'footnotes',
				'endpoint'       => 'pages',
				'source_id'      => self::EDGE_FOOTNOTES_SOURCE_ID,
				'author_user_id' => $page_author_id,
			),
			array(
				'kind'           => 'reusable_block',
				'endpoint'       => 'pages',
				'source_id'      => self::EDGE_REUSABLE_BLOCK_SOURCE_ID,
				'author_user_id' => $page_author_id,
			),
			array(
				'kind'           => 'cross_post_gallery',
				'endpoint'       => 'pages',
				'source_id'      => self::EDGE_CROSS_POST_GALLERY_SOURCE_ID,
				'author_user_id' => $page_author_id,
			),
			array(
				'kind'           => 'gallery_shortcode',
				'endpoint'       => 'pages',
				'source_id'      => self::EDGE_GALLERY_SHORTCODE_SOURCE_ID,
				'author_user_id' => $page_author_id,
				'media_ids'      => self::GALLERY_SHORTCODE_MEDIA_IDS,
			),
			array(
				'kind'           => 'playlist_shortcode',
				'endpoint'       => 'pages',
				'source_id'      => self::EDGE_PLAYLIST_SHORTCODE_SOURCE_ID,
				'author_user_id' => $page_author_id,
				'media_ids'      => self::PLAYLIST_SHORTCODE_MEDIA_IDS,
			),
			array(
				'kind'           => 'bare_gallery',
				'endpoint'       => 'pages',
				'source_id'      => self::EDGE_BARE_GALLERY_SOURCE_ID,
				'author_user_id' => $page_author_id,
				'media_ids'      => self::BARE_GALLERY_MEDIA_IDS,
			),
			array(
				'kind'           => 'bare_playlist',
				'endpoint'       => 'pages',
				'source_id'      => self::EDGE_BARE_PLAYLIST_SOURCE_ID,
				'author_user_id' => $page_author_id,
				'media_ids'      => self::BARE_PLAYLIST_MEDIA_IDS,
			),
		);
	}

	/**
	 * Builds the source URL => dest URL sideload map for the imported batch.
	 *
	 * @return array<string, string>
	 */
	private function build_source_url_to_dest_url_map(): array {
		$map = array();

		foreach ( self::$fixture->image_refs_by_source_id as $refs ) {
			foreach ( $refs as $ref ) {
				$dest = $this->find_dest_attachment_by_source_url( $ref['url'] );
				if ( null === $dest ) {
					continue;
				}

				$dest_url = wp_get_attachment_url( $dest->ID );
				if ( false === $dest_url ) {
					continue;
				}

				$map[ $ref['url'] ] = $dest_url;
			}
		}

		return $map;
	}

	/**
	 * Builds the source ID => dest ID sideload map for the imported batch,
	 * covering both inline images and gallery/playlist shortcode media.
	 *
	 * @return array<int, int>
	 */
	private function build_source_id_to_dest_id_map(): array {
		$map = array();

		foreach ( self::$fixture->image_refs_by_source_id as $refs ) {
			foreach ( $refs as $ref ) {
				$dest = $this->find_dest_attachment_by_source_url( $ref['url'] );
				if ( null === $dest ) {
					continue;
				}

				$map[ $ref['id'] ] = $dest->ID;
			}
		}

		foreach ( self::$fixture->shortcode_media_refs as $ref ) {
			$dest = $this->find_dest_attachment_by_source_url( $ref['url'] );
			if ( null !== $dest ) {
				$map[ $ref['id'] ] = $dest->ID;
			}
		}

		return $map;
	}

	/**
	 * Resolves the dest attachment whose META_ORIGINAL_URL equals the given
	 * source URL, or null when missing.
	 *
	 * @param string $source_url Source URL.
	 * @return \WP_Post|null
	 */
	private function find_dest_attachment_by_source_url(
		string $source_url
	): ?\WP_Post {
		$attachments = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'         => Options::META_ORIGINAL_URL,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $source_url,
				'posts_per_page'   => 1,
				'suppress_filters' => false,
			)
		);

		return $attachments[0] ?? null;
	}

	/**
	 * Returns the raw source post_content for a source ID.
	 *
	 * @param int $source_id Source post ID.
	 * @return string
	 */
	private function source_content( int $source_id ): string {
		return (string) self::$fixture->source_rest_bodies[ $source_id ]['content']['raw'];
	}

	/**
	 * Asserts a wp_posts MySQL datetime parses and falls in the recent import
	 * window: not in the future and within 600 seconds of $now_ts. The window
	 * only has to beat the generator's 90-day source date spread, so it stays
	 * generous against the gap between the class-wide import and the calling
	 * method. wp-env defaults to UTC, so the value parses cleanly as UTC.
	 *
	 * @param string $mysql_datetime wp_posts datetime column value.
	 * @param int    $now_ts         Upper bound captured by the caller.
	 * @param string $label          Label prefixed to failure messages.
	 */
	private function assert_datetime_is_recent(
		string $mysql_datetime,
		int $now_ts,
		string $label
	): void {
		$ts = strtotime( $mysql_datetime . ' UTC' );

		$this->assertIsInt( $ts, "{$label} should parse" );

		$delta = $now_ts - (int) $ts;
		$this->assertGreaterThanOrEqual(
			0,
			$delta,
			"{$label} should not be in the future"
		);
		$this->assertLessThan(
			600,
			$delta,
			"{$label} should be near the import time"
		);
	}

	/**
	 * Verifies that every source body imported to a distinct destination post.
	 * Guards against silent-pass tests: every later assertion iterates the
	 * dest map, so an empty or short batch would let them pass trivially.
	 */
	public function test_every_source_post_imported(): void {
		// ARRANGE + ACT: batch imported in wpSetUpBeforeClass.
		// ASSERT: one dest ID per source body.
		$this->assertCount(
			count( self::$fixture->source_rest_bodies ),
			self::$fixture->dest_post_ids,
			'Import should return one dest ID per source body'
		);
	}

	/**
	 * Verifies that identity-style columns (post_type, post_name) match
	 * source for every imported post.
	 */
	public function test_identity_columns_parity(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post matches source on identity columns.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_identity_columns(
				self::$fixture->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies that content-style columns (post_title, post_excerpt) match
	 * source for every imported post. post_content lives in
	 * CONTENT_BODY_COLUMNS and is exercised by test_content_body_parity().
	 */
	public function test_content_columns_parity(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post matches source on content columns.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_content_columns(
				self::$fixture->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies post_content URL/ID rewrite parity and reverse-asserts no
	 * source host leak or double-encoded entities on dest.
	 */
	public function test_content_body_parity(): void {
		// ARRANGE: build URL/ID sideload maps from the imported attachments.
		$url_map = $this->build_source_url_to_dest_url_map();
		$id_map  = $this->build_source_id_to_dest_id_map();

		// ACT + ASSERT: each dest post's content matches source after the
		// importer's URL/ID rewriting.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_content_body_parity(
				self::$fixture->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$url_map,
				$id_map,
				self::SOURCE_BASE_URL,
				$this
			);
		}
	}

	/**
	 * Verifies Gutenberg block-structural parity for every imported post: dest
	 * parses into at least one block, carries the same multiset of block names
	 * as source, and has no orphaned block-comment delimiters.
	 */
	public function test_block_structural_parity(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post's block structure matches source.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Content_Parity_Comparator::assert_block_structural_parity(
				$this->source_content( $source_id ),
				(string) get_post( $dest_id )->post_content,
				$this
			);
		}
	}

	/**
	 * Verifies caption-shortcode parity for every imported post: dest preserves
	 * the source caption count, keeps the tags balanced, and carries identical
	 * non-id attributes.
	 */
	public function test_shortcode_parity(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post's caption shortcodes match source.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Content_Parity_Comparator::assert_shortcode_parity(
				$this->source_content( $source_id ),
				(string) get_post( $dest_id )->post_content,
				$this
			);
		}
	}

	/**
	 * Verifies that gallery/playlist shortcode attachment IDs are rewritten to
	 * their destination attachments end-to-end: each dest shortcode keeps its
	 * count, order, and non-id attributes, maps every source id to its dest id
	 * in order, and leaves no source id behind. Guards against a vacuous pass by
	 * requiring both a gallery and a playlist shortcode to be verified.
	 */
	public function test_media_shortcode_parity(): void {
		// ARRANGE: the source ID => dest ID map covers the shortcode media.
		$id_map            = $this->build_source_id_to_dest_id_map();
		$gallery_verified  = false;
		$playlist_verified = false;

		// ACT + ASSERT: every shortcode-bearing post rewrote its ids to dest.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			$source       = $this->source_content( $source_id );
			$has_gallery  = str_contains( $source, '[gallery' );
			$has_playlist = str_contains( $source, '[playlist' );

			if ( ! $has_gallery && ! $has_playlist ) {
				continue;
			}

			Content_Parity_Comparator::assert_media_shortcode_parity(
				$source,
				(string) get_post( $dest_id )->post_content,
				$id_map,
				$this
			);

			$gallery_verified  = $gallery_verified || $has_gallery;
			$playlist_verified = $playlist_verified || $has_playlist;
		}

		// ASSERT: both kinds were verified, so the loop was not vacuous.
		$this->assertTrue(
			$gallery_verified,
			'Batch should seed a [gallery] shortcode to verify'
		);
		$this->assertTrue(
			$playlist_verified,
			'Batch should seed a [playlist] shortcode to verify'
		);
	}

	/**
	 * Verifies that every imported post preserves its source embed-block url
	 * multiset verbatim, locking process_embed_block()'s no-rewrite contract.
	 */
	public function test_embed_url_parity(): void {
		// ARRANGE: build the URL sideload map from the imported attachments.
		$url_map = $this->build_source_url_to_dest_url_map();

		// ACT + ASSERT: each dest post preserves its source embed url multiset.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Content_Parity_Comparator::assert_embed_url_parity(
				$this->source_content( $source_id ),
				(string) get_post( $dest_id )->post_content,
				$url_map,
				$this
			);
		}
	}

	/**
	 * Verifies that inline <img alt> text round-trips verbatim for every
	 * imported post, and guards against a vacuous pass by requiring the batch
	 * to seed the distinctive block-image alt.
	 */
	public function test_inline_img_alt_parity(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post preserves its source inline alt multiset, and
		// the batch seeded a distinctive non-empty alt so the check isn't
		// vacuous.
		$seeded_distinctive_alt = false;
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			$source = $this->source_content( $source_id );

			Content_Parity_Comparator::assert_inline_img_alt_parity(
				$source,
				(string) get_post( $dest_id )->post_content,
				$this
			);

			if ( str_contains( $source, Content_Generator::BLOCK_IMAGE_ALT ) ) {
				$seeded_distinctive_alt = true;
			}
		}

		$this->assertTrue(
			$seeded_distinctive_alt,
			'Batch should seed the distinctive block-image alt so the alt'
			. ' multiset check is not vacuously satisfied'
		);
	}

	/**
	 * Guards the comparator's coverage assumptions: the seeder must not emit
	 * gallery blocks or data-id attributes, since neither is exercised by the
	 * parity checks today. Grow comparator coverage before relaxing this.
	 * Gallery/playlist shortcodes are emitted and verified by
	 * test_media_shortcode_parity; the reusable block (core/block) is verified by
	 * test_reusable_block_edge_surfaces_degradation.
	 */
	public function test_seeder_does_not_emit_unsupported_references(): void {
		// ARRANGE + ACT: concatenate every seeded source content body.
		$raw = '';
		foreach ( self::$fixture->source_rest_bodies as $body ) {
			$raw .= (string) ( $body['content']['raw'] ?? '' ) . "\n";
		}

		// ASSERT: no unsupported reference types appear in the batch.
		$this->assertStringNotContainsString(
			'<!-- wp:gallery',
			$raw,
			'Seeder must not emit gallery blocks until the comparator covers'
			. ' gallery `attrs.ids` rewriting.'
		);
		$this->assertStringNotContainsString(
			'data-id=',
			$raw,
			'Seeder must not emit data-id attributes until the comparator'
			. ' covers data-id rewriting.'
		);
	}

	/**
	 * Verifies that status-style columns (comment_status, ping_status,
	 * post_password) match source. post_status diverges by design and is
	 * asserted separately in test_post_status_locks_to_draft.
	 */
	public function test_status_columns_parity(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post matches source on status columns.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_status_columns(
				self::$fixture->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies miscellaneous column parity (menu_order) and that columns
	 * with no source-side value land on the WordPress default on dest.
	 */
	public function test_misc_columns_parity(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post matches source on misc columns and WP
		// defaults.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_misc_columns(
				self::$fixture->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies that each imported post's post_author resolves to a destination
	 * user whose email matches the source safe_publish_author block.
	 */
	public function test_author_columns_parity(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post_author matches the source author by email.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_author_columns(
				self::$fixture->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies that author resolution is keyed on the source email: posts that
	 * share an author email resolve to one dest user (dedup), and the page
	 * slice's distinct author resolves to a different dest user than the post
	 * slice. Proves the importer matches by email rather than attributing every
	 * post to the importing user.
	 */
	public function test_author_resolves_to_distinct_dest_users(): void {
		// ARRANGE: collect the dest user each source author email resolved to,
		// asserting along the way that one email never maps to two dest users.
		$dest_user_by_email = array();
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			$body      = self::$fixture->source_rest_bodies[ $source_id ];
			$email     = (string) ( $body['safe_publish_author']['email'] ?? '' );
			$author_id = (int) get_post( $dest_id )->post_author;

			if ( isset( $dest_user_by_email[ $email ] ) ) {
				$this->assertSame(
					$dest_user_by_email[ $email ],
					$author_id,
					"Source author '{$email}' should resolve to one dest user"
				);
			} else {
				$dest_user_by_email[ $email ] = $author_id;
			}
		}

		// ASSERT: the batch exercised more than one author, and distinct source
		// emails resolved to distinct dest users.
		$this->assertGreaterThan(
			1,
			count( $dest_user_by_email ),
			'Batch should seed more than one source author email'
		);
		$this->assertSame(
			count( $dest_user_by_email ),
			count( array_unique( array_values( $dest_user_by_email ) ) ),
			'Distinct source author emails should resolve to distinct dest users'
		);
	}

	/**
	 * Verifies that the child page's post_parent resolves to its source
	 * parent's dest ID, while posts and top-level pages keep post_parent 0.
	 * Also guards against a silent pass where the batch seeds no child at all.
	 */
	public function test_parent_columns_parity(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post_parent matches the source parent mapping, and
		// the batch exercised at least one non-zero parent.
		$child_count = 0;
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			$source_body = self::$fixture->source_rest_bodies[ $source_id ];
			if ( (int) ( $source_body['parent'] ?? 0 ) > 0 ) {
				++$child_count;
			}

			Post_Parity_Asserter::assert_parent_columns(
				$source_body,
				get_post( $dest_id ),
				self::$fixture->dest_post_ids,
				$this
			);
		}

		$this->assertGreaterThan(
			0,
			$child_count,
			'Batch should seed at least one child post to exercise post_parent'
		);
	}

	/**
	 * Verifies that every wp_posts column has been classified by the
	 * asserter. Guards against silent gaps when WordPress adds a column or
	 * a column is omitted from the rules.
	 */
	public function test_no_unmodeled_columns(): void {
		// ARRANGE + ACT: nothing to set up; pure static check.
		// ASSERT: every wp_posts column is classified.
		Post_Parity_Asserter::assert_no_unmodeled_columns( $this );
	}

	/**
	 * Verifies that imported posts land as draft regardless of source
	 * status, locking in the documented re-publish workflow. Counterpart to
	 * the post_status entry in DIVERGENCE_REGISTRY — if this assertion ever
	 * starts failing, the divergence reason needs revisiting alongside the
	 * import code change.
	 */
	public function test_post_status_locks_to_draft(): void {
		// ARRANGE + ACT: batch imported.
		// ASSERT: every dest post is draft.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			$dest = get_post( $dest_id );
			$this->assertSame(
				'draft',
				$dest->post_status,
				"Source ID {$source_id} should import as draft"
			);
		}
	}

	/**
	 * Verifies that imported posts get a post_date close to the import time,
	 * not the source publish date. Counterpart to the post_date entry in
	 * DIVERGENCE_REGISTRY.
	 *
	 * Uses post_date rather than post_date_gmt because WordPress leaves the GMT
	 * date columns at the draft sentinel until publish (locked in
	 * test_gmt_dates_lock_to_zero_for_drafts).
	 */
	public function test_post_date_locks_to_import_time(): void {
		// ARRANGE: capture an upper bound for the dest post_date.
		$now_ts = time();

		// ACT + ASSERT: each dest post_date is recent.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			$this->assert_datetime_is_recent(
				(string) get_post( $dest_id )->post_date,
				$now_ts,
				"Source ID {$source_id} post_date"
			);
		}
	}

	/**
	 * Verifies that imported posts get post_modified set to the import time,
	 * not a propagated source modified time. Counterpart to the post_modified
	 * entry in DIVERGENCE_REGISTRY. post_modified_gmt stays the draft sentinel
	 * (locked in test_gmt_dates_lock_to_zero_for_drafts).
	 */
	public function test_post_modified_locks_to_import_time(): void {
		// ARRANGE: capture an upper bound for the dest post_modified.
		$now_ts = time();

		// ACT + ASSERT: each dest post_modified is recent.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			$this->assert_datetime_is_recent(
				(string) get_post( $dest_id )->post_modified,
				$now_ts,
				"Source ID {$source_id} post_modified"
			);
		}
	}

	/**
	 * Verifies that imported posts keep both GMT date columns (post_date_gmt
	 * and post_modified_gmt) at WordPress' draft sentinel "0000-00-00 00:00:00".
	 * Counterpart to those entries in DIVERGENCE_REGISTRY: WordPress only fills
	 * them at publish time, so a non-zero value would mean a source date leaked
	 * through or the post no longer lands as a draft.
	 */
	public function test_gmt_dates_lock_to_zero_for_drafts(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest GMT date column is the draft sentinel.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			$dest = get_post( $dest_id );
			$this->assertSame(
				'0000-00-00 00:00:00',
				$dest->post_date_gmt,
				"Source ID {$source_id} post_date_gmt should stay the draft"
				. ' sentinel until publish'
			);
			$this->assertSame(
				'0000-00-00 00:00:00',
				$dest->post_modified_gmt,
				"Source ID {$source_id} post_modified_gmt should stay the draft"
				. ' sentinel until publish'
			);
		}
	}

	/**
	 * Verifies that each imported post lands on a fresh destination ID instead
	 * of reusing the source ID. Counterpart to the ID entry in
	 * DIVERGENCE_REGISTRY: source and dest are separate WordPress ID spaces.
	 * The source IDs are kept clear of the dest ID range (see SOURCE_ID_BASE),
	 * so a match can never be coincidental.
	 */
	public function test_id_diverges_from_source(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest ID differs from its source ID.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			$this->assertNotSame(
				$source_id,
				$dest_id,
				"Source ID {$source_id} should import to a fresh dest ID"
			);
		}
	}

	/**
	 * Verifies that imported posts get a destination-generated guid rather than
	 * the source guid. Counterpart to the guid entry in DIVERGENCE_REGISTRY:
	 * WordPress core's own importer preserves the source guid, so locking the
	 * regeneration forces an explicit decision if that behavior ever changes.
	 */
	public function test_guid_regenerated_on_dest(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: dest guid is non-empty and does not reuse the source guid.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			$dest_guid   = (string) get_post( $dest_id )->guid;
			$source_guid = (string) self::$fixture
				->source_rest_bodies[ $source_id ]['guid'];

			// Guard the comparison below: a missing source guid would let the
			// reuse check pass trivially.
			$this->assertNotSame(
				'',
				$source_guid,
				"Source ID {$source_id} should seed a source guid"
			);
			$this->assertNotSame(
				'',
				$dest_guid,
				"Source ID {$source_id} dest guid should be non-empty"
			);
			$this->assertNotSame(
				$source_guid,
				$dest_guid,
				"Source ID {$source_id} dest guid should not reuse source guid"
			);
		}
	}

	/**
	 * Verifies that every meta key in the source body's meta field
	 * round-trips to the destination post unchanged.
	 */
	public function test_source_matched_meta_parity(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post carries the source meta values.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_source_matched_meta(
				self::$fixture->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies that every plugin-added meta key (source post ID, link,
	 * imported-from marker, source author email/login) is present on each
	 * dest post with the expected value, and that the import-date meta has
	 * the right shape.
	 */
	public function test_plugin_added_meta_present(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post carries the plugin-added meta keys.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_plugin_added_meta(
				self::$fixture->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies that every meta key actually present on each dest post is
	 * classified by the asserter (source-matched, plugin-added, WP default,
	 * or deferred). Guards against silent gaps when the import pipeline
	 * starts emitting a new meta key.
	 */
	public function test_no_unmodeled_meta_keys(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: every dest meta key on every imported post is classified.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_no_unmodeled_meta_keys(
				self::$fixture->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies that for each source taxonomy assignment, the dest post is
	 * assigned a matching term and that the dest term lands with the
	 * importer's default parent (0) and description ('').
	 */
	public function test_term_assignments_parity(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each dest post has the source term assignments.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_term_assignments(
				self::$fixture->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies that every dest term assignment in the checked taxonomies
	 * (category, post_tag) traces back to a source assignment. Locks in the
	 * importer's behavior of attaching only the terms the source asked for.
	 */
	public function test_no_unmodeled_term_assignments(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: every dest term assignment traces to a source one.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_no_unmodeled_term_assignments(
				self::$fixture->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies that every source-referenced image URL (featured and inline)
	 * has exactly one dest attachment carrying the expected MIME, the source
	 * URL on META_ORIGINAL_URL, and the divergent/deferred classifications
	 * the asserter documents.
	 */
	public function test_source_referenced_attachments_imported(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: each source URL resolves to a single classified dest
		// attachment. Featured/inline classification is driven by the source
		// body's `featured_media` field (same source-of-truth the asserter
		// uses) rather than image_refs ordering.
		foreach ( self::$fixture->image_refs_by_source_id as $source_id => $refs ) {
			$featured_source_id = (int) (
				self::$fixture->source_rest_bodies[ $source_id ]['featured_media'] ?? 0
			);

			foreach ( $refs as $ref ) {
				$featured_id = $ref['id'] === $featured_source_id
					? $ref['id']
					: null;

				Post_Parity_Asserter::assert_imported_attachment_for_source_url(
					$ref['url'],
					self::SOURCE_BASE_URL,
					'image/jpeg',
					$featured_id,
					self::$fixture->source_media_metadata( $ref['id'] ),
					$source_id,
					0,
					self::$fixture->dest_post_ids,
					$this
				);
			}
		}
	}

	/**
	 * Verifies that each image/video a bare [gallery]/[playlist] renders is
	 * imported, parented to its destination edge post, and carries its source
	 * menu_order — the ordered attached set core needs to render the bare
	 * shortcode. Guards against a vacuous pass by requiring both a gallery-set
	 * image and a playlist-set video.
	 */
	public function test_bare_shortcode_attached_set_imported(): void {
		// ARRANGE + ACT: Batch already imported.
		$gallery_checked  = false;
		$playlist_checked = false;

		// ASSERT: Each attached-set item lands parented and menu_order-ordered.
		foreach ( self::$fixture->bare_shortcode_media_refs as $ref ) {
			Post_Parity_Asserter::assert_imported_attachment_for_source_url(
				$ref['url'],
				self::SOURCE_BASE_URL,
				$ref['mime'],
				null,
				self::$fixture->source_media_metadata( $ref['id'] ),
				$ref['parent'],
				$ref['menu_order'],
				self::$fixture->dest_post_ids,
				$this
			);

			$gallery_checked  = $gallery_checked || 'image/jpeg' === $ref['mime'];
			$playlist_checked = $playlist_checked || 'video/mp4' === $ref['mime'];
		}

		$this->assertTrue(
			$gallery_checked,
			'Batch should seed a bare-gallery image to verify'
		);
		$this->assertTrue(
			$playlist_checked,
			'Batch should seed a bare-playlist video to verify'
		);
	}

	/**
	 * Verifies that the asserter's attachment-column classifications cover
	 * every wp_posts column that applies to attachments. Guards against a
	 * future WordPress schema bump silently leaving a column unmodeled on the
	 * attachment side.
	 */
	public function test_no_unmodeled_attachment_columns(): void {
		// ARRANGE + ACT: nothing to set up; pure static check.
		// ASSERT: every attachment-applicable column is classified.
		Post_Parity_Asserter::assert_no_unmodeled_attachment_columns( $this );
	}

	/**
	 * Verifies that source library metadata (alt, title, caption, description)
	 * propagates to every sideloaded attachment, matching the raw source values
	 * after the importer's per-field sanitization. Guards against a vacuous pass
	 * by requiring the batch to exercise both a featured-flagged and an
	 * inline-only attachment.
	 *
	 * The seeder embeds every image inline, so here the featured attachment is
	 * written by the inline map path; the by-ID featured path is covered in
	 * isolation by Media_Importer_Featured_Metadata_Test.
	 */
	public function test_library_metadata_propagates_to_all_attachments(): void {
		// ARRANGE + ACT: batch already imported.
		$featured_checked = 0;
		$inline_checked   = 0;

		// ASSERT: each attachment carries the source library metadata.
		foreach ( self::$fixture->image_refs_by_source_id as $source_id => $refs ) {
			$featured_source_id = (int) (
				self::$fixture->source_rest_bodies[ $source_id ]['featured_media'] ?? 0
			);

			foreach ( $refs as $ref ) {
				$dest = $this->find_dest_attachment_by_source_url( $ref['url'] );
				$this->assertNotNull(
					$dest,
					"Source URL {$ref['url']} should resolve a dest attachment"
				);

				$meta = self::$fixture->source_media_metadata( $ref['id'] );

				$this->assertSame(
					wp_strip_all_tags( $meta['alt'], true ),
					(string) get_post_meta(
						$dest->ID,
						'_wp_attachment_image_alt',
						true
					),
					"Attachment {$dest->ID} alt should match source"
				);
				$this->assertSame(
					sanitize_text_field( $meta['title'] ),
					$dest->post_title,
					"Attachment {$dest->ID} title should match source"
				);
				$this->assertSame(
					wp_kses_post( $meta['caption'] ),
					$dest->post_excerpt,
					"Attachment {$dest->ID} caption should match source"
				);
				$this->assertSame(
					wp_kses_post( $meta['description'] ),
					$dest->post_content,
					"Attachment {$dest->ID} description should match source"
				);

				if ( $ref['id'] === $featured_source_id ) {
					++$featured_checked;
				} else {
					++$inline_checked;
				}
			}
		}

		$this->assertGreaterThan(
			0,
			$featured_checked,
			'Batch should exercise at least one featured attachment'
		);
		$this->assertGreaterThan(
			0,
			$inline_checked,
			'Batch should exercise at least one inline-only attachment'
		);
	}

	/**
	 * Verifies that the empty-content edge page imports with empty post_content
	 * and no injected markers, locking the empty-body path.
	 */
	public function test_empty_content_imports_empty(): void {
		// ARRANGE + ACT: batch already imported.
		$this->assertArrayHasKey(
			self::EDGE_EMPTY_SOURCE_ID,
			self::$fixture->dest_post_ids,
			'Empty-content edge page should import to a dest post'
		);

		// ASSERT: the empty-content page kept empty post_content.
		$dest_id = self::$fixture->dest_post_ids[ self::EDGE_EMPTY_SOURCE_ID ];
		$this->assertSame(
			'',
			(string) get_post( $dest_id )->post_content,
			'Empty source content should import as empty post_content'
		);
	}

	/**
	 * Verifies that the non-ASCII edge page seeds multibyte characters, an
	 * emoji, and unescaped entities, so the encoding and slug parity checks that
	 * run over the whole batch are not vacuously satisfied by ASCII input.
	 */
	public function test_non_ascii_edge_seeds_multibyte_and_entities(): void {
		// ARRANGE: read the non-ASCII edge page's source body.
		$body    = self::$fixture->source_rest_bodies[ self::EDGE_NON_ASCII_SOURCE_ID ];
		$content = (string) $body['content']['raw'];
		$slug    = (string) $body['slug'];

		// ASSERT: it imported, carries the multibyte/entity payload, and uses a
		// slug that sanitize_title() actually transforms.
		$this->assertArrayHasKey(
			self::EDGE_NON_ASCII_SOURCE_ID,
			self::$fixture->dest_post_ids,
			'Non-ASCII edge page should import to a dest post'
		);
		$this->assertStringContainsString(
			"\u{65e5}\u{672c}\u{8a9e}",
			$content,
			'Non-ASCII content should carry CJK characters'
		);
		$this->assertStringContainsString(
			"\u{1f389}",
			$content,
			'Non-ASCII content should carry a 4-byte emoji'
		);
		$this->assertStringContainsString(
			'&amp;',
			$content,
			'Non-ASCII content should carry an unescaped entity'
		);
		$this->assertStringContainsString(
			'&mdash;',
			$content,
			'Non-ASCII content should carry a named entity'
		);
		$this->assertNotSame(
			$slug,
			sanitize_title( $slug ),
			'Non-ASCII slug should be transformed by sanitize_title()'
		);
	}

	/**
	 * Verifies that the embed edge page seeds a core/embed block whose url is
	 * on a host distinct from the source site, so test_embed_url_parity is not
	 * vacuously satisfied by a batch with no external embed.
	 */
	public function test_embed_edge_seeds_external_provider_url(): void {
		// ARRANGE: read the embed edge page's source content.
		$content = $this->source_content( self::EDGE_EMBED_SOURCE_ID );

		// ASSERT: it imported, carries a core/embed block, and references an
		// external provider host the importer must leave untouched.
		$this->assertArrayHasKey(
			self::EDGE_EMBED_SOURCE_ID,
			self::$fixture->dest_post_ids,
			'Embed edge page should import to a dest post'
		);
		$this->assertStringContainsString(
			'<!-- wp:embed',
			$content,
			'Embed edge content should carry a core/embed block'
		);
		$this->assertStringContainsString(
			self::EDGE_EMBED_PROVIDER_HOST,
			$content,
			'Embed edge content should reference the external provider host'
		);
	}

	/**
	 * Verifies that the footnotes edge page round-trips both channels WordPress
	 * footnotes use — the core/footnotes block in post_content and the
	 * separately stored footnotes meta JSON — confirming both survive the
	 * import together.
	 */
	public function test_footnotes_edge_round_trips_block_and_meta(): void {
		// ARRANGE + ACT: batch imported; read the seed and dest post.
		$this->assertArrayHasKey(
			self::EDGE_FOOTNOTES_SOURCE_ID,
			self::$fixture->dest_post_ids,
			'Footnotes edge page should import to a dest post'
		);
		$body           = self::$fixture->source_rest_bodies[ self::EDGE_FOOTNOTES_SOURCE_ID ];
		$source_content = (string) $body['content']['raw'];
		$source_meta    = (string) ( $body['meta']['footnotes'] ?? '' );
		$dest_id        = self::$fixture->dest_post_ids[ self::EDGE_FOOTNOTES_SOURCE_ID ];
		$dest_meta      = (string) get_post_meta( $dest_id, 'footnotes', true );

		// ASSERT: the seed has a core/footnotes block and non-empty meta JSON
		// (so the checks below aren't vacuous), then both survive the import.
		$this->assertStringContainsString(
			'<!-- wp:footnotes /-->',
			$source_content,
			'Footnotes edge content should seed a core/footnotes block'
		);
		$this->assertStringContainsString(
			'<!-- wp:footnotes /-->',
			(string) get_post( $dest_id )->post_content,
			'Dest content should preserve the core/footnotes block'
		);
		$source_footnotes = json_decode( $source_meta, true );
		$this->assertIsArray(
			$source_footnotes,
			'Footnotes source meta should decode to an array'
		);
		$this->assertNotSame(
			array(),
			$source_footnotes,
			'Footnotes source meta should carry at least one footnote'
		);
		$this->assertSame(
			$source_footnotes,
			json_decode( $dest_meta, true ),
			'Footnotes meta should round-trip structurally to the dest post'
		);
	}

	/**
	 * Verifies that no imported post other than the reusable-block and cross-post
	 * gallery edges raised an import warning, reverse-asserting that the clean
	 * batch, including the empty, non-ASCII, embed, and footnotes edge pages,
	 * triggers no degradation. Those two pages' degradations are asserted by
	 * test_reusable_block_edge_surfaces_degradation and
	 * test_cross_post_gallery_edge_surfaces_degradation.
	 */
	public function test_import_raised_no_warnings(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: Warnings captured for every imported post; only the reusable-
		// block and cross-post gallery edges degrade, every other is warning-free.
		$this->assertSame(
			array_keys( self::$fixture->dest_post_ids ),
			array_keys( self::$fixture->warnings_by_source_id ),
			'Warnings should be captured for every imported post'
		);
		foreach ( self::$fixture->warnings_by_source_id as $source_id => $warnings ) {
			if (
				self::EDGE_REUSABLE_BLOCK_SOURCE_ID === $source_id
				|| self::EDGE_CROSS_POST_GALLERY_SOURCE_ID === $source_id
			) {
				continue;
			}
			$this->assertSame(
				array(),
				$warnings,
				"Source ID {$source_id} should import without warnings"
			);
		}
	}

	/**
	 * Verifies that the reusable-block edge page surfaces a retryable
	 * unmapped_block_reference degradation: the import raises the warning keyed
	 * to core/block, opens a retryable attention issue carrying the source ref
	 * and the reusable-block detail, and leaves the ref in place since this
	 * batch does not import the target wp_block.
	 */
	public function test_reusable_block_edge_surfaces_degradation(): void {
		// ARRANGE + ACT: batch imported; locate the reusable-block edge page.
		$this->assertArrayHasKey(
			self::EDGE_REUSABLE_BLOCK_SOURCE_ID,
			self::$fixture->dest_post_ids,
			'Reusable-block edge page should import to a dest post'
		);
		$dest_id = self::$fixture->dest_post_ids[ self::EDGE_REUSABLE_BLOCK_SOURCE_ID ];
		$ref     = Seeder_Parity_Fixture::REUSABLE_BLOCK_SOURCE_REF;

		// ASSERT: the import raised exactly the core/block unmapped reference.
		$this->assertSame(
			array(
				array(
					'type'      => 'unmapped_block_reference',
					'kind'      => 'post',
					'block'     => 'core/block',
					'source_id' => $ref,
				),
			),
			self::$fixture->warnings_by_source_id[ self::EDGE_REUSABLE_BLOCK_SOURCE_ID ],
			'Reusable-block edge import should raise one core/block unmapped reference'
		);

		// ASSERT: a retryable attention issue carries the source ref and the
		// reusable-block detail that drives the Patterns-oriented copy.
		$issue = ( new Attention_Issues_Repository() )->get_issue(
			$dest_id,
			'unmapped_block_reference',
			$ref,
			'post'
		);
		$this->assertNotNull(
			$issue,
			'Reusable-block degradation should open an attention issue'
		);
		$this->assertSame( 'warning', $issue['severity'] );
		$this->assertSame(
			'core/block',
			$issue['detail']['block'] ?? '',
			'Issue detail should record the core/block name'
		);
		$this->assertContains(
			'unmapped_block_reference',
			Admin_Ajax_Controller::ATTENTION_ISSUE_RETRYABLE_TYPES,
			'Reusable-block issue must be retryable'
		);

		// ASSERT: the core/block ref is left in place on the destination.
		$this->assertStringContainsString(
			'<!-- wp:block {"ref":' . $ref . '} /-->',
			(string) get_post( $dest_id )->post_content,
			'Dest content should preserve the unresolved core/block ref'
		);
	}

	/**
	 * Verifies that the cross-post gallery edge page surfaces a retryable
	 * unmapped_gallery_reference degradation: the import raises the warning
	 * carrying the referenced source post id, opens a retryable attention issue
	 * keyed to it, and leaves the shortcode id in place since this batch does not
	 * import the referenced post.
	 */
	public function test_cross_post_gallery_edge_surfaces_degradation(): void {
		// ARRANGE + ACT: Batch imported; locate the cross-post gallery edge page.
		$this->assertArrayHasKey(
			self::EDGE_CROSS_POST_GALLERY_SOURCE_ID,
			self::$fixture->dest_post_ids,
			'Cross-post gallery edge page should import to a dest post'
		);
		$dest_id = self::$fixture->dest_post_ids[ self::EDGE_CROSS_POST_GALLERY_SOURCE_ID ];
		$ref     = Seeder_Parity_Fixture::CROSS_POST_GALLERY_SOURCE_REF;

		// ASSERT: The import raised exactly the unmapped gallery reference.
		$this->assertSame(
			array(
				array(
					'type'      => 'unmapped_gallery_reference',
					'source_id' => $ref,
				),
			),
			self::$fixture->warnings_by_source_id[ self::EDGE_CROSS_POST_GALLERY_SOURCE_ID ],
			'Cross-post gallery edge import should raise one unmapped gallery reference'
		);

		// ASSERT: A retryable attention issue is keyed to the referenced post.
		$issue = ( new Attention_Issues_Repository() )->get_issue(
			$dest_id,
			'unmapped_gallery_reference',
			$ref,
			'post'
		);
		$this->assertNotNull(
			$issue,
			'Cross-post gallery degradation should open an attention issue'
		);
		$this->assertSame( 'warning', $issue['severity'] );
		$this->assertContains(
			'unmapped_gallery_reference',
			Admin_Ajax_Controller::ATTENTION_ISSUE_RETRYABLE_TYPES,
			'Gallery-reference issue must be retryable'
		);

		// ASSERT: The shortcode id is left in place on the destination.
		$this->assertStringContainsString(
			'[gallery id="' . $ref . '"]',
			(string) get_post( $dest_id )->post_content,
			'Dest content should preserve the unresolved gallery id'
		);
	}
}
