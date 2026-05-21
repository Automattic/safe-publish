<?php
/**
 * Seeder content parity integration test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Seeder\Content_Generator;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use Safe_Publish\Utils\Options;
use WP_Ajax_UnitTestCase;

/**
 * Seeder Content Parity Test Class.
 *
 * Coverage is scoped to the rules the asserter classifies; the asserter itself
 * is the source of truth for which fields, meta keys, and term assignments
 * are checked versus deferred.
 */
class Seeder_Content_Parity_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;
	use Per_Source_Id_Post_Api_Mock_Trait;

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
	 * Source post ID where the synthetic batch starts. Chosen high enough
	 * that it can't collide with any factory-created IDs.
	 */
	private const SOURCE_ID_BASE = 1000;

	/**
	 * Source REST bodies keyed by source post ID. Each entry is the full
	 * JSON-decodable body the mock returns when the importer fetches the
	 * source post.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $source_rest_bodies = array();

	/**
	 * Source post ID => destination post ID after the bulk import.
	 *
	 * @var array<int, int>
	 */
	private array $dest_post_ids = array();

	/**
	 * Admin user that owns imported posts and authors REST mock responses.
	 *
	 * @var int
	 */
	private int $admin_user_id;

	/**
	 * Sets up the auth secret, history tables, admin user, connected-site
	 * option, builds the source REST bodies for the batch, registers the
	 * pre_http_request mock, and dispatches the bulk import once so test
	 * methods can read the resulting dest state.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		Imports_Table::create_table();
		Import_Items_Table::create_table();

		$this->admin_user_id = $this->factory()->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $this->admin_user_id );

		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			self::SOURCE_BASE_URL
		);

		$this->source_rest_bodies = $this->build_source_rest_bodies();

		$this->add_per_source_id_post_api_mock();

		$this->dest_post_ids = $this->run_bulk_import();
	}

	/**
	 * Removes the mock filter and clears the connected-site URL.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->remove_per_source_id_post_api_mock();
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Returns the pre-built source REST body for the trait. Falls back to null
	 * so unregistered IDs surface as a WP_Error.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		return $this->source_rest_bodies[ $source_id ] ?? null;
	}

	/**
	 * Builds the source REST bodies for the test batch via Content_Generator.
	 *
	 * Generates six posts with no embedded images so this phase doesn't need
	 * to mock the attachment download/sideload flow. Six is the smallest
	 * batch that exercises the generator's full source-status rotation
	 * (publish / draft / private) and covers the editor mix (Gutenberg +
	 * classic) so the input-parser path is fully covered even though dest
	 * always lands as draft.
	 *
	 * @return array<int, array<string, mixed>> Source ID => REST body.
	 */
	private function build_source_rest_bodies(): array {
		$count = 6;

		$generator = new Content_Generator(
			'post',
			'mixed',
			'1',
			$count,
			1,
			0,
			'',
			self::REFERENCE_TIME,
			self::SOURCE_BASE_URL
		);

		$bodies = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$source_id            = self::SOURCE_ID_BASE + $i;
			$payload              = $generator->generate( $i, array() );
			$bodies[ $source_id ] = $this->payload_to_rest_body(
				$source_id,
				$payload
			);
		}

		return $bodies;
	}

	/**
	 * Wraps a generator payload into a full REST response body.
	 *
	 * Mirrors the shape of a real wp/v2 post response: title/content/excerpt
	 * are wrapped in `[ 'raw' => ... ]`, taxonomy assignments land under
	 * _embedded['wp:term'], and the plugin's safe_publish_author block is
	 * included so the destination's author resolution can run.
	 *
	 * @param int                  $source_id Source post ID.
	 * @param array<string, mixed> $payload   Generator payload.
	 * @return array<string, mixed>
	 */
	private function payload_to_rest_body( int $source_id, array $payload ): array {
		$admin = get_userdata( $this->admin_user_id );

		return array(
			'id'                  => $source_id,
			'title'               => array( 'raw' => $payload['title'] ),
			'featured_media'      => $payload['featured_media'],
			'content'             => array( 'raw' => $payload['content'] ),
			'excerpt'             => array( 'raw' => $payload['excerpt'] ),
			'link'                => $payload['link'],
			'slug'                => $payload['slug'],
			'type'                => $payload['post_type'],
			'status'              => $payload['status'],
			'date'                => $payload['date'],
			'date_gmt'            => $payload['date'],
			'comment_status'      => 'open',
			'ping_status'         => 'open',
			'menu_order'          => 0,
			'password'            => '',
			'parent'              => 0,
			'meta'                => $payload['meta'],
			'safe_publish_author' => array(
				'email'        => false !== $admin ? (string) $admin->user_email : '',
				'login'        => false !== $admin ? (string) $admin->user_login : '',
				'display_name' => false !== $admin ? (string) $admin->display_name : '',
			),
			'_embedded'           => array(
				'wp:term' => $this->embedded_terms( $payload['terms'] ),
			),
		);
	}

	/**
	 * Converts taxonomy => term-name lists into the _embedded['wp:term']
	 * shape the import code expects.
	 *
	 * @param array<string, list<string>> $terms Taxonomy => term names.
	 * @return list<list<array{taxonomy: string, name: string}>>
	 */
	private function embedded_terms( array $terms ): array {
		$groups = array();

		foreach ( $terms as $taxonomy => $term_names ) {
			$group = array();
			foreach ( $term_names as $name ) {
				$group[] = array(
					'taxonomy' => $taxonomy,
					'name'     => $name,
				);
			}
			$groups[] = $group;
		}

		return $groups;
	}

	/**
	 * Dispatches the bulk-import AJAX action for the configured batch and
	 * returns a source ID => destination post ID mapping.
	 *
	 * @return array<int, int>
	 */
	private function run_bulk_import(): array {
		$posts_data = array();

		foreach ( $this->source_rest_bodies as $source_id => $body ) {
			$posts_data[] = array(
				'id'        => $source_id,
				'title'     => $body['title']['raw'],
				'link'      => $body['link'],
				'post_type' => 'posts',
			);
		}

		$_POST = array(
			'nonce'      => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'posts_data' => wp_json_encode( $posts_data ),
		);

		$this->dispatch_ajax_expecting_die( 'safe_publish_bulk_import' );

		$decoded = json_decode( $this->_last_response, true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded['success'] );

		$dest_ids = array();

		foreach ( $decoded['data']['results'] as $result ) {
			$this->assertTrue(
				$result['success'],
				"Import should succeed for source ID {$result['source_post_id']}"
			);
			$dest_ids[ (int) $result['source_post_id'] ] = (int) $result['post_id'];
		}

		// Guard against silent-pass tests: every later assertion iterates
		// $dest_ids, so an empty or short batch would let them pass trivially.
		$this->assertCount(
			count( $this->source_rest_bodies ),
			$dest_ids,
			'Bulk import should return one dest ID per source body'
		);

		return $dest_ids;
	}

	/**
	 * Verifies that identity-style columns (post_type, post_name) match
	 * source for every imported post.
	 */
	public function test_identity_columns_parity(): void {
		// ARRANGE + ACT: batch already imported in setUp.
		// ASSERT: each dest post matches source on identity columns.
		foreach ( $this->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_identity_columns(
				$this->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies that content-style columns (post_title, post_excerpt) match
	 * source for every imported post. post_content is deferred to a later
	 * phase that covers URL/ID rewriting.
	 */
	public function test_content_columns_parity(): void {
		// ARRANGE + ACT: batch already imported in setUp.
		// ASSERT: each dest post matches source on content columns.
		foreach ( $this->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_content_columns(
				$this->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies that status-style columns (comment_status, ping_status,
	 * post_password) match source. post_status diverges by design and is
	 * asserted separately in test_post_status_locks_to_draft.
	 */
	public function test_status_columns_parity(): void {
		// ARRANGE + ACT: batch already imported in setUp.
		// ASSERT: each dest post matches source on status columns.
		foreach ( $this->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_status_columns(
				$this->source_rest_bodies[ $source_id ],
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
		// ARRANGE + ACT: batch already imported in setUp.
		// ASSERT: each dest post matches source on misc columns and WP
		// defaults.
		foreach ( $this->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_misc_columns(
				$this->source_rest_bodies[ $source_id ],
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
		// ARRANGE + ACT: batch imported in setUp.
		// ASSERT: every dest post is draft.
		foreach ( $this->dest_post_ids as $source_id => $dest_id ) {
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

		// ACT + ASSERT: each dest post_date is within 60s of $now_ts.
		foreach ( $this->dest_post_ids as $source_id => $dest_id ) {
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
				60,
				$delta,
				"Source ID {$source_id} post_date should be within 60s of now"
			);
		}
	}

	/**
	 * Verifies that every meta key in the source body's `meta` field
	 * round-trips to the destination post unchanged.
	 */
	public function test_source_matched_meta_parity(): void {
		// ARRANGE + ACT: batch already imported in setUp.
		// ASSERT: each dest post carries the source meta values.
		foreach ( $this->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_source_matched_meta(
				$this->source_rest_bodies[ $source_id ],
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
		// ARRANGE + ACT: batch already imported in setUp.
		// ASSERT: each dest post carries the plugin-added meta keys.
		foreach ( $this->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_plugin_added_meta(
				$this->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}

	/**
	 * Verifies that every meta key listed in DEFERRED_META is absent on each
	 * dest post. Locks the current phase's "not yet emitted" state so that a
	 * future phase emitting one of these keys (e.g. _thumbnail_id in PR 2c)
	 * surfaces as a test failure and forces the registry to be updated.
	 */
	public function test_deferred_meta_keys_absent(): void {
		// ARRANGE + ACT: batch already imported in setUp.
		// ASSERT: no deferred meta key is present on any dest post.
		foreach ( $this->dest_post_ids as $dest_id ) {
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
		// ARRANGE + ACT: batch already imported in setUp.
		// ASSERT: every dest meta key on every imported post is classified.
		foreach ( $this->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_no_unmodeled_meta_keys(
				$this->source_rest_bodies[ $source_id ],
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
		// ARRANGE + ACT: batch already imported in setUp.
		// ASSERT: each dest post has the source term assignments.
		foreach ( $this->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_term_assignments(
				$this->source_rest_bodies[ $source_id ],
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
		// ARRANGE + ACT: batch already imported in setUp.
		// ASSERT: every dest term assignment traces to a source one.
		foreach ( $this->dest_post_ids as $source_id => $dest_id ) {
			Post_Parity_Asserter::assert_no_unmodeled_term_assignments(
				$this->source_rest_bodies[ $source_id ],
				get_post( $dest_id ),
				$this
			);
		}
	}
}
