<?php
/**
 * Seeder content parity integration test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

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
			self::batch_slices( self::$admin_user_id, self::$page_author_id )
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
	 * shortcode parity on pages too. Pages carry no category/post_tag
	 * assignments, mirroring a real page source.
	 *
	 * @param int $post_author_id Dest user the post slice is authored by.
	 * @param int $page_author_id Dest user the page slice is authored by.
	 * @return list<array{type: string, endpoint: string, count: int, source_id_base: int, assign_terms: bool, author_user_id: int}>
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
			),
			array(
				'type'           => 'page',
				'endpoint'       => 'pages',
				'count'          => 4,
				'source_id_base' => self::SOURCE_PAGE_ID_BASE,
				'assign_terms'   => false,
				'author_user_id' => $page_author_id,
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
	 * Builds the source ID => dest ID sideload map for the imported batch.
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
	 * Guards the comparator's coverage assumptions: the seeder must not emit
	 * gallery blocks, data-id attributes, or non-caption shortcodes, since none
	 * is exercised by the parity checks today. Grow comparator coverage before
	 * relaxing this.
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
		$this->assertStringNotContainsString(
			'[gallery',
			$raw,
			'Seeder must not emit gallery shortcodes until the rewriter covers'
			. ' their bare attachment IDs.'
		);
		$this->assertStringNotContainsString(
			'[playlist',
			$raw,
			'Seeder must not emit playlist shortcodes until the rewriter covers'
			. ' their bare attachment IDs.'
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
	 * Verifies that every meta key listed in DEFERRED_META is absent on each
	 * dest post. Locks the current phase's "not yet emitted" state so that a
	 * future phase emitting one of these keys (e.g. META_SOURCE_POST_PARENT_ID
	 * when hierarchical-post support ships) surfaces as a test failure and
	 * forces the registry to be updated.
	 */
	public function test_deferred_meta_keys_absent(): void {
		// ARRANGE + ACT: batch already imported.
		// ASSERT: no deferred meta key is present on any dest post.
		foreach ( self::$fixture->dest_post_ids as $dest_id ) {
			Post_Parity_Asserter::assert_deferred_meta_absent(
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
					$this
				);
			}
		}
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
}
