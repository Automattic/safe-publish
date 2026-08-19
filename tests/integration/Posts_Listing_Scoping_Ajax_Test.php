<?php
/**
 * Integration tests for source scoping on the Posts listing AJAX endpoint.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use Safe_Publish\Utils\Options;
use WP_Ajax_UnitTestCase;
use WP_Error;

/**
 * Verifies that safe_publish_list_posts scopes every state to the connected
 * source: the catalog annotation, the local listing, and the focused-state
 * chip each resolve a numeric source post id against that source's imports
 * only, so an id also imported from a previously connected site neither
 * disappears from Available nor routes its row to the other site's post.
 */
class Posts_Listing_Scoping_Ajax_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Connected source the catalog is fetched from.
	 */
	private const SOURCE = 'https://source.example.com';

	/**
	 * A previously connected source whose imports must not annotate.
	 */
	private const OTHER_SOURCE = 'https://old-source.example.com';

	/**
	 * Numeric source post id both sources carry.
	 */
	private const COLLIDING_ID = 960;

	/**
	 * Fallback shared secret used when no environment constant is defined.
	 */
	private const FALLBACK_SECRET = 'integration-test-secret-key-32chars-ok';

	/**
	 * History repository for seeding sessions and import rows.
	 *
	 * @var History_Repository
	 */
	private History_Repository $history;

	/**
	 * Source ids the stubbed catalog offers. Left empty for the local-primary
	 * states, which list from the items table rather than the catalog.
	 *
	 * @var int[]
	 */
	private array $catalog_ids = array();

	/**
	 * Sets up the custom tables, an admin user, and the connected source.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Imports_Table::create_table();
		Import_Items_Table::create_table();

		// Required by validate_auth_or_fail() on the catalog path.
		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		$this->history = new History_Repository();

		wp_set_current_user(
			$this->factory()->user->create( array( 'role' => 'administrator' ) )
		);
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE );

		add_filter( 'pre_http_request', array( $this, 'stub_catalog' ), 1, 3 );
	}

	/**
	 * Removes the catalog stub and the connected source.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'stub_catalog' ), 1 );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Verifies that a catalog post whose numeric id was imported only under a
	 * previously connected source is still offered in Available.
	 */
	public function test_colliding_catalog_post_stays_in_available(): void {
		// ARRANGE: The connected source offers an id imported only elsewhere.
		$this->catalog_ids = array( self::COLLIDING_ID, 961 );
		$this->import_under( self::OTHER_SOURCE, self::COLLIDING_ID );

		// ACT: Request the Available list.
		$response = $this->list_posts( 'available' );

		// ASSERT: The other source's import does not claim the catalog post.
		$this->assertTrue( $response['success'] );
		$this->assertSame(
			array( self::COLLIDING_ID, 961 ),
			array_column( $response['data']['items'], 'source_post_id' )
		);
	}

	/**
	 * Verifies that the All state reports a catalog post imported only under
	 * another source as available rather than as imported.
	 */
	public function test_colliding_catalog_post_reads_as_available_in_all(): void {
		// ARRANGE: The connected source offers an id imported only elsewhere.
		$this->catalog_ids = array( self::COLLIDING_ID );
		$this->import_under( self::OTHER_SOURCE, self::COLLIDING_ID );

		// ACT: Request the All list.
		$response = $this->list_posts( 'all' );

		// ASSERT: The row carries no local state and no dangling edit link.
		$row = $response['data']['items'][0];
		$this->assertFalse( $row['is_imported'] );
		$this->assertSame( 'available', $row['local_state'] );
		$this->assertNull( $row['post_id'] );
		$this->assertSame( '', $row['edit_url'] );
	}

	/**
	 * Verifies that an id imported under both sources annotates against the
	 * connected source's import, even though the other one is newer.
	 */
	public function test_annotation_resolves_the_connected_sources_post(): void {
		// ARRANGE: The same id imported here first, then under the other
		// source, so the newer row across sources is the wrong one.
		$this->catalog_ids = array( self::COLLIDING_ID );
		$mine              = $this->import_under(
			self::SOURCE,
			self::COLLIDING_ID
		);
		$other             = $this->import_under(
			self::OTHER_SOURCE,
			self::COLLIDING_ID
		);

		// ACT: Request the All list.
		$response = $this->list_posts( 'all' );

		// ASSERT: The row points at this source's post, not the newer one.
		$row = $response['data']['items'][0];
		$this->assertTrue( $row['is_imported'] );
		$this->assertSame( $mine, $row['post_id'] );
		$this->assertStringContainsString( 'post=' . $mine, $row['edit_url'] );
		$this->assertStringNotContainsString(
			'post=' . $other,
			$row['edit_url']
		);
	}

	/**
	 * Verifies that a rolled-back update preserves the imported catalog payload.
	 */
	public function test_rolled_back_update_preserves_imported_catalog_payload(): void {
		// ARRANGE: An imported draft whose newer update was rolled back.
		$source_id         = 972;
		$this->catalog_ids = array( $source_id );
		$session           = $this->history->create_session( self::SOURCE, 'single' );
		$post_id           = $this->factory()->post->create(
			array( 'post_status' => 'draft' )
		);
		$this->assertIsInt( $session );
		$this->assertIsInt( $post_id );

		$initial = $this->history->log_import_action(
			$session,
			$source_id,
			'Imported draft',
			'success',
			$post_id
		);
		$update  = $this->history->log_import_action(
			$session,
			$source_id,
			'Updated draft',
			'updated',
			$post_id
		);
		$this->assertIsInt( $initial );
		$this->assertIsInt( $update );
		$this->history->mark_item_rolled_back( $update );

		// ACT: Request the catalog-backed All listing used by the Manage screen.
		$response = $this->list_posts( 'all' );

		// ASSERT: The payload exposes the preceding import and its Edit target.
		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $response['data']['items'] );
		$row = $response['data']['items'][0];
		$this->assertTrue( $row['is_imported'] );
		$this->assertSame( 'up-to-date', $row['local_state'] );
		$this->assertSame( $initial, $row['item_id'] );
		$this->assertSame( $post_id, $row['post_id'] );
		$this->assertSame( 'draft', $row['wp_post_status'] );
		$this->assertStringContainsString( 'post=' . $post_id, $row['edit_url'] );
	}

	/**
	 * Verifies that the up-to-date state lists only the connected source's
	 * imported posts.
	 */
	public function test_local_listing_excludes_another_sources_posts(): void {
		// ARRANGE: One post imported here and one imported elsewhere.
		$mine = $this->import_under( self::SOURCE, 970 );
		$this->import_under( self::OTHER_SOURCE, 971 );

		// ACT: Request the up-to-date list.
		$response = $this->list_posts( 'up-to-date' );

		// ASSERT: Only this source's import is listed.
		$this->assertSame(
			array( 970 ),
			array_column( $response['data']['items'], 'source_post_id' )
		);
		$this->assertSame( $mine, $response['data']['items'][0]['post_id'] );
	}

	/**
	 * Verifies that a focus deep-link for an id imported only under another
	 * source resolves to available.
	 */
	public function test_focus_deep_link_ignores_another_sources_import(): void {
		// ARRANGE: The id is imported only under the other source.
		$this->catalog_ids = array( self::COLLIDING_ID );
		$this->import_under( self::OTHER_SOURCE, self::COLLIDING_ID );

		// ACT: Request the listing focused on that source id.
		$response = $this->list_posts(
			'all',
			array( 'focus_source_id' => (string) self::COLLIDING_ID )
		);

		// ASSERT: The focused chip reports available.
		$this->assertSame( 'available', $response['data']['focused_state'] );
		$this->assertSame(
			self::COLLIDING_ID,
			$response['data']['focused_source_post_id']
		);
	}

	/**
	 * Verifies that a disconnected install annotates nothing as imported and
	 * lists nothing locally, matching the empty-identity scope.
	 */
	public function test_disconnected_install_sees_no_imports(): void {
		// ARRANGE: A post imported from the source, then the connection
		// removed. The request still names the source, as the frontend would.
		$this->catalog_ids = array( self::COLLIDING_ID );
		$this->import_under( self::SOURCE, self::COLLIDING_ID );
		delete_option( Options::OPTION_CONNECTED_SITE_URL );

		// ACT: Request the All and up-to-date lists.
		$all   = $this->list_posts( 'all' );
		$local = $this->list_posts( 'up-to-date' );

		// ASSERT: The catalog row reads as available and nothing lists locally.
		$this->assertFalse( $all['data']['items'][0]['is_imported'] );
		$this->assertSame( array(), $local['data']['items'] );
	}

	/**
	 * Short-circuits catalog requests with a page built from catalog_ids.
	 *
	 * @param false|array|WP_Error $_preempt Filtered short-circuit value.
	 * @param array                $_args    Request arguments.
	 * @param string               $_url     Request URL.
	 * @return array Faked HTTP response.
	 */
	public function stub_catalog(
		false|array|WP_Error $_preempt,
		array $_args,
		string $_url
	): array {
		$items = array_map(
			static fn( int $id ): array => array(
				'id'           => $id,
				'title'        => 'Source post ' . $id,
				'link'         => self::SOURCE . '/post-' . $id,
				'post_type'    => 'post',
				'status'       => 'publish',
				'date_gmt'     => '2026-01-01T00:00:00Z',
				'modified_gmt' => '2026-01-01T00:00:00Z',
			),
			$this->catalog_ids
		);

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'items'    => $items,
					'has_more' => false,
				)
			),
			'headers'  => array(),
		);
	}

	/**
	 * Records a successful import of a source id under a source, backed by a
	 * real local post.
	 *
	 * @param string $source_site_url Source the session imports from.
	 * @param int    $source_post_id  Source post id the item records.
	 * @return int Local post id the import created.
	 */
	private function import_under(
		string $source_site_url,
		int $source_post_id
	): int {
		$session = $this->history->create_session( $source_site_url, 'single' );
		$post_id = $this->factory()->post->create();
		$this->assertIsInt( $session );
		$this->assertIsInt( $post_id );

		$this->history->log_import_action(
			$session,
			$source_post_id,
			'Imported ' . $source_post_id,
			'success',
			$post_id
		);

		return $post_id;
	}

	/**
	 * Dispatches the Posts listing endpoint and decodes its response.
	 *
	 * @param string $state Listing state to request.
	 * @param array  $extra Additional POST fields.
	 * @return array Decoded AJAX response.
	 */
	private function list_posts(
		string $state,
		array $extra = array()
	): array {
		$nonce = wp_create_nonce( 'safe_publish_ajax_nonce' );
		$_POST = array_merge(
			array(
				'nonce'           => $nonce,
				'source_site_url' => self::SOURCE,
				'state'           => $state,
			),
			$extra
		);

		// _handleAjax appends, so clear it to keep repeat calls decodable.
		$this->_last_response = '';

		$this->dispatch_ajax_expecting_die( 'safe_publish_list_posts' );

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response );

		return $response;
	}
}
