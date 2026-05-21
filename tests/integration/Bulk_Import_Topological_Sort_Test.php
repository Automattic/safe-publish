<?php
/**
 * Bulk import topological sort integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use Safe_Publish\Utils\Options;
use WP_Ajax_UnitTestCase;

/**
 * Bulk Import Topological Sort Test Class.
 */
class Bulk_Import_Topological_Sort_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;
	use Per_Source_Id_Post_Api_Mock_Trait;

	/**
	 * Fallback shared secret used when no environment constant is defined.
	 */
	private const FALLBACK_SECRET = 'integration-test-secret-key-32chars-ok';

	/**
	 * Source post payloads keyed by source ID. Each entry is a partial REST
	 * response body merged into the mock default.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $source_payloads = array();

	/**
	 * Admin user ID for the AJAX request.
	 *
	 * @var int
	 */
	private int $admin_user_id;

	/**
	 * Sets up the auth secret, tables, admin user, connected-site URL, and the
	 * per-source-id HTTP mock.
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
			'https://source.example.com'
		);

		$this->add_per_source_id_post_api_mock();
	}

	/**
	 * Tears down fixtures and removes the HTTP mock.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->remove_per_source_id_post_api_mock();
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		$this->source_payloads = array();
		parent::tearDown();
	}

	/**
	 * Builds the per-source-id REST body for the trait. Each test registers
	 * the relevant IDs in $this->source_payloads; unregistered IDs surface as
	 * a WP_Error via the trait.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		if ( ! isset( $this->source_payloads[ $source_id ] ) ) {
			return null;
		}

		$override = $this->source_payloads[ $source_id ];
		$admin    = get_userdata( $this->admin_user_id );

		return array(
			'id'                  => $source_id,
			'title'               => array(
				'raw' => $override['title'] ?? "Source Post {$source_id}",
			),
			'featured_media'      => 0,
			'content'             => array( 'raw' => '<p>Content.</p>' ),
			'excerpt'             => array( 'raw' => '' ),
			'link'                => "https://source.example.com/post-{$source_id}",
			'slug'                => "post-{$source_id}",
			'comment_status'      => '',
			'ping_status'         => '',
			'menu_order'          => 0,
			'password'            => '',
			'parent'              => $override['parent'] ?? 0,
			'meta'                => array(),
			'safe_publish_author' => array(
				'email'        => false !== $admin ? (string) $admin->user_email : '',
				'login'        => false !== $admin ? (string) $admin->user_login : '',
				'display_name' => false !== $admin ? (string) $admin->display_name : '',
			),
		);
	}

	/**
	 * Dispatches the bulk-import AJAX action with the given source posts
	 * payload and returns the decoded JSON response.
	 *
	 * @param array $posts_data Request payload sent as posts_data JSON.
	 * @return array Decoded response.
	 */
	private function dispatch_bulk_import( array $posts_data ): array {
		$_POST = array(
			'nonce'      => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'posts_data' => wp_json_encode( $posts_data ),
		);

		$this->dispatch_ajax_expecting_die( 'safe_publish_bulk_import' );

		$decoded = json_decode( $this->_last_response, true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded['success'] );

		return $decoded['data'];
	}

	/**
	 * Builds a `posts_data` entry for the given source ID and post type.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $post_type REST endpoint (e.g. 'pages').
	 * @return array
	 */
	private function payload_entry( int $source_id, string $post_type = 'pages' ): array {
		return array(
			'id'        => $source_id,
			'title'     => "Source Post {$source_id}",
			'link'      => "https://source.example.com/post-{$source_id}",
			'post_type' => $post_type,
		);
	}

	/**
	 * Returns the destination post ID for a given source ID, or 0 when no
	 * imported post exists yet.
	 *
	 * @param int $source_id Source post ID.
	 * @return int Destination post ID, or 0.
	 */
	private function destination_id_for( int $source_id ): int {
		$posts = get_posts(
			array(
				'post_type'        => 'page',
				'posts_per_page'   => 1,
				'meta_key'         => Options::META_SOURCE_POST_ID,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => $source_id,
				'post_status'      => 'any',
				'suppress_filters' => false,
			)
		);

		return count( $posts ) > 0 ? (int) $posts[0]->ID : 0;
	}

	/**
	 * Verifies that a batch in request-order [parent, child] succeeds and the
	 * child is reparented to the freshly-imported destination parent.
	 */
	public function test_request_order_parent_then_child_imports_in_order(): void {
		// ARRANGE: Two pages, parent first in input.
		$this->source_payloads = array(
			10 => array( 'parent' => 0 ),
			20 => array( 'parent' => 10 ),
		);

		// ACT: Dispatch the bulk import.
		$data = $this->dispatch_bulk_import(
			array(
				$this->payload_entry( 10 ),
				$this->payload_entry( 20 ),
			)
		);

		// ASSERT: Both succeed and child's post_parent points at parent.
		$this->assertSame( 2, $data['successful'] );
		$this->assertSame( 0, $data['failed'] );

		$parent_dest = $this->destination_id_for( 10 );
		$child_dest  = $this->destination_id_for( 20 );
		$this->assertNotSame( 0, $parent_dest );
		$this->assertNotSame( 0, $child_dest );
		$this->assertSame(
			$parent_dest,
			(int) get_post( $child_dest )->post_parent
		);
	}

	/**
	 * Verifies that a batch in reverse request-order [child, parent] is
	 * sorted topologically so the parent imports first and the child still
	 * resolves correctly.
	 */
	public function test_reverse_request_order_sorts_parent_first(): void {
		// ARRANGE: Child listed before parent.
		$this->source_payloads = array(
			30 => array( 'parent' => 0 ),
			40 => array( 'parent' => 30 ),
		);

		// ACT: Dispatch with the reverse order.
		$data = $this->dispatch_bulk_import(
			array(
				$this->payload_entry( 40 ),
				$this->payload_entry( 30 ),
			)
		);

		// ASSERT: Both succeed, parent processed first (results in topological
		// order), child's post_parent set correctly.
		$this->assertSame( 2, $data['successful'] );
		$this->assertSame( 0, $data['failed'] );

		$this->assertSame( 30, $data['results'][0]['source_post_id'] );
		$this->assertSame( 40, $data['results'][1]['source_post_id'] );

		$parent_dest = $this->destination_id_for( 30 );
		$child_dest  = $this->destination_id_for( 40 );
		$this->assertSame(
			$parent_dest,
			(int) get_post( $child_dest )->post_parent
		);
	}

	/**
	 * Verifies that a deep chain A → B → C → D imports root-first regardless
	 * of input order, with each child pointing at the previous link.
	 */
	public function test_deep_chain_imports_in_dependency_order(): void {
		// ARRANGE: Chain in scrambled input order.
		$this->source_payloads = array(
			100 => array( 'parent' => 0 ),
			200 => array( 'parent' => 100 ),
			300 => array( 'parent' => 200 ),
			400 => array( 'parent' => 300 ),
		);

		// ACT: Dispatch with a scrambled request order.
		$data = $this->dispatch_bulk_import(
			array(
				$this->payload_entry( 300 ),
				$this->payload_entry( 100 ),
				$this->payload_entry( 400 ),
				$this->payload_entry( 200 ),
			)
		);

		// ASSERT: All four succeed and each link's post_parent matches the
		// previous link's destination ID.
		$this->assertSame( 4, $data['successful'] );
		$this->assertSame( 0, $data['failed'] );

		$dest = array(
			100 => $this->destination_id_for( 100 ),
			200 => $this->destination_id_for( 200 ),
			300 => $this->destination_id_for( 300 ),
			400 => $this->destination_id_for( 400 ),
		);

		$this->assertSame( 0, (int) get_post( $dest[100] )->post_parent );
		$this->assertSame( $dest[100], (int) get_post( $dest[200] )->post_parent );
		$this->assertSame( $dest[200], (int) get_post( $dest[300] )->post_parent );
		$this->assertSame( $dest[300], (int) get_post( $dest[400] )->post_parent );
	}

	/**
	 * Verifies that one parent with multiple siblings imports the parent first
	 * and all siblings resolve to the same destination parent ID.
	 */
	public function test_wide_tree_resolves_all_siblings(): void {
		// ARRANGE: One parent and three children.
		$this->source_payloads = array(
			500 => array( 'parent' => 0 ),
			510 => array( 'parent' => 500 ),
			520 => array( 'parent' => 500 ),
			530 => array( 'parent' => 500 ),
		);

		// ACT: Dispatch in input order.
		$data = $this->dispatch_bulk_import(
			array(
				$this->payload_entry( 500 ),
				$this->payload_entry( 510 ),
				$this->payload_entry( 520 ),
				$this->payload_entry( 530 ),
			)
		);

		// ASSERT: All four succeed and each child shares the same parent dest.
		$this->assertSame( 4, $data['successful'] );
		$parent_dest = $this->destination_id_for( 500 );
		$this->assertSame(
			$parent_dest,
			(int) get_post( $this->destination_id_for( 510 ) )->post_parent
		);
		$this->assertSame(
			$parent_dest,
			(int) get_post( $this->destination_id_for( 520 ) )->post_parent
		);
		$this->assertSame(
			$parent_dest,
			(int) get_post( $this->destination_id_for( 530 ) )->post_parent
		);
	}

	/**
	 * Verifies that a batch mixing a new post and an existing imported post
	 * routes both through the topological flow correctly.
	 */
	public function test_mixed_new_and_update_flows_share_parent_resolution(): void {
		// ARRANGE: Pre-existing destination page imported from source 600.
		$existing_id = self::factory()->post->create(
			array( 'post_type' => 'page' )
		);
		update_post_meta(
			$existing_id,
			Options::META_SOURCE_POST_ID,
			600
		);

		$this->source_payloads = array(
			600 => array( 'parent' => 0 ),
			610 => array( 'parent' => 600 ),
		);

		// ACT: Both source 600 (update) and source 610 (new) in batch.
		$data = $this->dispatch_bulk_import(
			array(
				$this->payload_entry( 610 ),
				$this->payload_entry( 600 ),
			)
		);

		// ASSERT: Both succeed; the update reuses the existing destination ID
		// and the new child reparents to it.
		$this->assertSame( 2, $data['successful'] );
		$this->assertSame( $existing_id, $this->destination_id_for( 600 ) );
		$child_dest = $this->destination_id_for( 610 );
		$this->assertSame(
			$existing_id,
			(int) get_post( $child_dest )->post_parent
		);
	}

	/**
	 * Verifies that duplicate source IDs in posts_data produce one result per
	 * input entry — the total count must align with successful + failed so
	 * the import-results modal never reports phantom failures.
	 */
	public function test_duplicate_source_ids_keep_total_aligned_with_outcomes(): void {
		// ARRANGE: Two payload entries with the same source ID.
		$this->source_payloads = array(
			900 => array( 'parent' => 0 ),
		);

		// ACT: Dispatch the bulk import.
		$data = $this->dispatch_bulk_import(
			array(
				$this->payload_entry( 900 ),
				$this->payload_entry( 900 ),
			)
		);

		// ASSERT: Whatever the post count is, total equals successful + failed
		// so the modal does not report unaccounted-for posts.
		$this->assertSame(
			$data['successful'] + $data['failed'],
			$data['total'],
			'Total must equal successful + failed for every input combination.'
		);
		$this->assertCount(
			$data['total'],
			$data['results'],
			'Results array length must match the reported total.'
		);
	}

	/**
	 * Verifies that a two-node cycle does not crash the batch — both posts
	 * fail through the unresolvable-parent path with the in-batch error.
	 */
	public function test_cycle_in_source_data_falls_through_gracefully(): void {
		// ARRANGE: A ↔ B cycle.
		$this->source_payloads = array(
			700 => array( 'parent' => 710 ),
			710 => array( 'parent' => 700 ),
		);

		// ACT: Dispatch with both cycle members.
		$data = $this->dispatch_bulk_import(
			array(
				$this->payload_entry( 700 ),
				$this->payload_entry( 710 ),
			)
		);

		// ASSERT: Both fail through the in-batch error and no post is created.
		$this->assertSame( 0, $data['successful'] );
		$this->assertSame( 2, $data['failed'] );

		foreach ( $data['results'] as $result ) {
			$this->assertFalse( $result['success'] );
			$this->assertStringContainsString(
				'failed to import earlier in this batch',
				$result['error']
			);
		}

		$this->assertSame( 0, $this->destination_id_for( 700 ) );
		$this->assertSame( 0, $this->destination_id_for( 710 ) );
	}
}
