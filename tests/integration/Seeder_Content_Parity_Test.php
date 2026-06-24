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
	 * Source post ID where the synthetic post batch starts. Chosen high enough
	 * that it can't collide with any factory-created IDs.
	 */
	private const SOURCE_ID_BASE = 1000;

	/**
	 * Source media ID where the synthetic batch starts. Chosen high enough not
	 * to collide with post IDs or factory-created IDs.
	 */
	private const SOURCE_MEDIA_ID_BASE = 5000;

	/**
	 * Class-wide imported batch shared by every test method.
	 *
	 * @var Seeder_Parity_Fixture
	 */
	private static Seeder_Parity_Fixture $fixture;

	/**
	 * Admin user that owns imported posts and authors REST mock responses.
	 *
	 * @var int
	 */
	private static int $admin_user_id;

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

		self::$admin_user_id = $factory->user->create(
			array( 'role' => 'administrator' )
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
			self::batch_slices()
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
	 * status, and image-mode rotations.
	 *
	 * @return list<array{type: string, endpoint: string, count: int, source_id_base: int, assign_terms: bool}>
	 */
	private static function batch_slices(): array {
		return array(
			array(
				'type'           => 'post',
				'endpoint'       => 'posts',
				'count'          => 6,
				'source_id_base' => self::SOURCE_ID_BASE,
				'assign_terms'   => true,
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
	 * Guards the comparator's coverage assumptions: the seeder must not emit
	 * gallery blocks or data-id attributes, since neither is exercised by
	 * the URL/ID parity checks today. Grow comparator coverage before
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
	 * Verifies that imported posts get a post_date close to the import
	 * time, not the source publish date. Counterpart to the post_date entry
	 * in DIVERGENCE_REGISTRY.
	 *
	 * Uses post_date rather than post_date_gmt because WordPress leaves
	 * post_date_gmt as "0000-00-00 00:00:00" for drafts until they are
	 * published, and imported posts land as drafts (see the post_status
	 * reverse-assertion above).
	 */
	public function test_post_date_locks_to_import_time(): void {
		// ARRANGE: capture an upper bound for the dest post_date. The test
		// runs in wp-env which defaults to UTC, so post_date parses cleanly
		// as a UTC timestamp.
		$now_ts = time();

		// ACT + ASSERT: each dest post_date is recent — the window only has to
		// beat the 90-day source date spread, so it stays generous against the
		// gap between the class-wide import and this method.
		foreach ( self::$fixture->dest_post_ids as $source_id => $dest_id ) {
			$dest    = get_post( $dest_id );
			$dest_ts = strtotime( $dest->post_date . ' UTC' );

			$this->assertIsInt(
				$dest_ts,
				"Source ID {$source_id} post_date should parse"
			);

			$delta = $now_ts - (int) $dest_ts;
			$this->assertGreaterThanOrEqual(
				0,
				$delta,
				"Source ID {$source_id} post_date should not be in the future"
			);
			$this->assertLessThan(
				600,
				$delta,
				"Source ID {$source_id} post_date should be near the import time"
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
