<?php
/**
 * Source-identity lookup coverage for post types excluded from site search
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
use Safe_Publish\API\Diff_Renderer;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Source_Identity_Lookup;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;
use WP_Post;

/**
 * Covers the source-ID lookups against post types registered
 * exclude_from_search=true, which WP_Query's 'any' token omits.
 *
 * Patterns, navigation menus, and hidden custom types all reach the catalog,
 * so every lookup that maps a source ID to its destination post must see
 * them. Also locks the newest-by-ID tie-break shared by the import lookup and
 * the block-reference remap.
 */
class Source_Identity_Lookup_Test extends Source_Posts_API_Test_Base {

	/**
	 * Source site identity used by every fixture in this class.
	 */
	private const SOURCE_URL = 'https://source.example.com';

	/**
	 * Hierarchical custom post type kept out of site search.
	 */
	private const HIDDEN_TYPE = 'sp_hidden';

	/**
	 * Post import service under test.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $import_service;

	/**
	 * Content processor backing the block-reference remap.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $content_processor;

	/**
	 * Registers the hidden CPT, the source mock, and the import service.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		add_filter(
			'pre_http_request',
			array( $this, 'mock_hidden_type_source' ),
			5,
			3
		);

		// public=true with exclude_from_search=true needs no allowlisting to
		// reach the catalog, so any site with such a type is affected.
		register_post_type(
			self::HIDDEN_TYPE,
			array(
				'public'              => true,
				'exclude_from_search' => true,
				'show_in_rest'        => true,
				'hierarchical'        => true,
				'rest_base'           => 'sp_hiddens',
				'supports'            => array( 'title', 'editor' ),
			)
		);

		$media_importer          = new Media_Importer( new HTTP_Client() );
		$this->content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		$this->import_service = new Post_Import_Service(
			new Source_Posts_API( new HTTP_Client() ),
			$media_importer,
			$this->content_processor,
			new History_Repository(),
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter(),
			new Attention_Issues_Repository()
		);
	}

	/**
	 * Removes the source mock and unregisters the hidden CPT.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'pre_http_request',
			array( $this, 'mock_hidden_type_source' ),
			5
		);
		unregister_post_type( self::HIDDEN_TYPE );
		parent::tearDown();
	}

	/**
	 * Serves the catalog map and the single-post endpoints for the types under
	 * test, letting all other URLs fall through to the base mock.
	 *
	 * @param false|array|WP_Error $preempt Short-circuit value passed by WP.
	 * @param array                $_args   Request arguments (unused).
	 * @param string               $url     Requested URL.
	 * @return false|array|WP_Error Mock response, or $preempt to defer.
	 */
	public function mock_hidden_type_source(
		false|array|WP_Error $preempt,
		array $_args,
		string $url
	): false|array|WP_Error {
		if ( false !== $preempt ) {
			return $preempt;
		}

		if ( str_contains( $url, '/safe-publish/v1/catalog/post-types' ) ) {
			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => (string) wp_json_encode(
					array(
						array(
							'slug'       => 'wp_block',
							'name'       => 'Patterns',
							'label'      => 'Patterns',
							'rest_base'  => 'blocks',
							'raw_fields' => array( 'title', 'content' ),
						),
						array(
							'slug'       => 'wp_navigation',
							'name'       => 'Navigation',
							'label'      => 'Navigation',
							'rest_base'  => 'navigation',
							'raw_fields' => array( 'title', 'content' ),
						),
						array(
							'slug'       => self::HIDDEN_TYPE,
							'name'       => 'Hidden',
							'label'      => 'Hidden',
							'rest_base'  => 'sp_hiddens',
							'raw_fields' => array( 'title', 'content' ),
						),
					)
				),
				'headers'  => array(),
			);
		}

		$pattern = '#/wp-json/wp/v2/(?:blocks|navigation|sp_hiddens)/\d+#';

		if ( 1 === preg_match( $pattern, $url ) ) {
			return $this->build_mock_post_response();
		}

		return $preempt;
	}

	/**
	 * Post types whose registration excludes them from site search, with the
	 * post_type value the catalog sends for each.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function hidden_post_type_provider(): array {
		return array(
			'pattern'         => array( 'blocks', 'wp_block', 7201 ),
			'navigation menu' => array( 'navigation', 'wp_navigation', 7202 ),
			'hidden CPT'      => array(
				self::HIDDEN_TYPE,
				self::HIDDEN_TYPE,
				7203,
			),
		);
	}

	/**
	 * Verifies that re-importing a post type excluded from site search updates
	 * the existing destination post instead of creating a second copy.
	 *
	 * @dataProvider hidden_post_type_provider
	 *
	 * @param string $catalog_type Post type as the catalog sends it.
	 * @param string $wp_type      Expected destination post type slug.
	 * @param int    $source_id    Source post ID under test.
	 */
	public function test_reimport_updates_hidden_type_in_place(
		string $catalog_type,
		string $wp_type,
		int $source_id
	): void {
		// ARRANGE: A source post of a type 'any' cannot see.
		$this->mock_post_overrides = array(
			'title'   => 'First import',
			'content' => '<p>v1</p>',
		);

		$post_data = array(
			'id'        => $source_id,
			'title'     => 'First import',
			'content'   => '<p>v1</p>',
			'link'      => self::SOURCE_URL . '/hidden-' . $source_id,
			'post_type' => $catalog_type,
		);

		$first = $this->import_service->import_post( $post_data );
		$this->assertTrue(
			$first['success'],
			'First import should succeed: ' . ( $first['error'] ?? '' )
		);
		$this->assertSame( $wp_type, get_post( $first['post_id'] )->post_type );

		// ACT: Re-import the same source post with changed content.
		$this->mock_post_overrides['content'] = '<p>v2</p>';
		$second                               = $this->import_service
			->import_post( $post_data );

		// ASSERT: The re-import updated the first copy rather than adding one.
		$this->assertTrue( $second['success'], 'Re-import should succeed.' );
		$this->assertTrue(
			$second['existing'],
			'Re-import must be recognized as an update.'
		);
		$this->assertSame(
			$first['post_id'],
			$second['post_id'],
			'Re-import must write to the post the first import created.'
		);
		$this->assertSame(
			array( $first['post_id'] ),
			$this->claiming_post_ids( $source_id ),
			'Exactly one destination post may claim the source ID.'
		);
		$this->assertStringContainsString(
			'v2',
			get_post( $second['post_id'] )->post_content,
			'Source edits must reach the existing destination post.'
		);
	}

	/**
	 * Verifies that the batched lookup resolves each post type excluded from
	 * site search to the destination post that claims its source ID.
	 */
	public function test_batched_lookup_resolves_hidden_types(): void {
		// ARRANGE: One claiming post per affected type.
		$types    = array( 'wp_block', 'wp_navigation', self::HIDDEN_TYPE );
		$expected = array();
		foreach ( $types as $offset => $type ) {
			$source_id              = 7300 + $offset;
			$expected[ $source_id ] = $this->create_claiming_post(
				$source_id,
				$type
			);
		}

		// ACT: Ask the batched lookup for all three at once.
		$found = $this->import_service->fetch_imported_posts_by_source_ids(
			array_keys( $expected ),
			self::SOURCE_URL
		);

		// ASSERT: Each source ID maps to its own destination post.
		foreach ( $expected as $source_id => $post_id ) {
			$this->assertArrayHasKey( $source_id, $found );
			$this->assertSame( $post_id, $found[ $source_id ]->ID );
		}
	}

	/**
	 * Verifies that a hierarchical parent of a type excluded from site search
	 * resolves to its destination ID instead of failing the child's import.
	 */
	public function test_resolve_source_parent_finds_hidden_parent(): void {
		// ARRANGE: An imported parent of the hidden hierarchical type.
		$parent_id = $this->create_claiming_post( 7400, self::HIDDEN_TYPE );

		// ACT: Resolve the child's source parent reference.
		$resolved = $this->import_service->resolve_source_parent(
			7400,
			self::HIDDEN_TYPE,
			self::SOURCE_URL
		);

		// ASSERT: The destination parent ID comes back, not a WP_Error.
		$this->assertSame( $parent_id, $resolved );
	}

	/**
	 * Verifies that a source ID with two claiming posts cannot push another
	 * source ID out of a batched lookup's result set.
	 */
	public function test_batched_lookup_keeps_every_source_id(): void {
		// ARRANGE: Source 7502 is claimed first, then source 7501 twice, so
		// both duplicates outrank 7502 under the newest-by-ID order and would
		// fill a two-row cap between them.
		$only_7502 = $this->create_claiming_post( 7502, 'wp_block' );
		$this->create_claiming_post( 7501, 'wp_block' );
		$newest_7501 = $this->create_claiming_post( 7501, 'wp_block' );

		// ACT: Request both source IDs through each batched lookup.
		$found    = $this->import_service->fetch_imported_posts_by_source_ids(
			array( 7501, 7502 ),
			self::SOURCE_URL
		);
		$remapped = $this->content_processor->map_target_refs(
			array(
				7501 => true,
				7502 => true,
			),
			array(),
			self::SOURCE_URL
		);

		// ASSERT: The duplicate consumed no slot belonging to 7502.
		$this->assertSame( $newest_7501, $found[7501]->ID );
		$this->assertSame( $only_7502, $found[7502]->ID );
		$this->assertSame( $newest_7501, $remapped['post'][7501] );
		$this->assertSame( $only_7502, $remapped['post'][7502] );
	}

	/**
	 * Verifies that the diff compares against the copy a re-import would write
	 * to when two posts claim one source ID.
	 */
	public function test_diff_targets_the_copy_an_import_writes_to(): void {
		// ARRANGE: Two claims whose ID and date orders disagree.
		list( , $newer ) = $this->create_date_inverted_claims( 7900, 'post' );

		// ACT: Ask the diff renderer and the import lookup for the same ID.
		$local         = ( new Diff_Renderer() )->find_local_post(
			7900,
			'post',
			self::SOURCE_URL
		);
		$import_target = $this->import_service->find_imported_post(
			7900,
			self::SOURCE_URL
		);

		// ASSERT: Both resolve to the same, higher-ID copy.
		$this->assertInstanceOf( WP_Post::class, $local );
		$this->assertInstanceOf( WP_Post::class, $import_target );
		$this->assertSame( $newer, $local->ID );
		$this->assertSame( $import_target->ID, $local->ID );
	}

	/**
	 * Verifies that the import lookup and the block-reference remap pick the
	 * same copy when two posts claim one source ID, keyed on ID rather than
	 * the editable post date.
	 */
	public function test_duplicate_claims_resolve_to_newest_by_id(): void {
		// ARRANGE: Two claims whose ID and date orders disagree.
		list( , $newer ) = $this->create_date_inverted_claims(
			7600,
			'wp_block'
		);

		// ACT: Ask both lookups which copy the source ID resolves to.
		$import_target = $this->import_service->find_imported_post(
			7600,
			self::SOURCE_URL
		);
		$remapped      = $this->content_processor->map_target_refs(
			array( 7600 => true ),
			array(),
			self::SOURCE_URL
		);

		// ASSERT: Both pick the higher ID, so updates and references agree.
		$this->assertInstanceOf( WP_Post::class, $import_target );
		$this->assertSame( $newer, $import_target->ID );
		$this->assertSame( $newer, $remapped['post'][7600] );
	}

	/**
	 * Verifies that a revision carrying the identity meta never outranks its
	 * own parent, which it would under a newest-by-ID tie-break.
	 */
	public function test_find_imported_post_ignores_revisions(): void {
		// ARRANGE: Opt the identity meta into revisions, then force one.
		add_filter(
			'wp_post_revision_meta_keys',
			static fn( array $keys ): array => array_merge(
				$keys,
				array(
					Options::META_SOURCE_POST_ID,
					Options::META_SOURCE_SITE_URL,
				)
			)
		);

		$post_id = $this->create_claiming_post( 7700, 'post' );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p>Revised.</p>',
			)
		);

		// ARRANGE: The revision exists and does carry the identity meta, so a
		// pass below cannot come from the revision being invisible anyway.
		$revisions = wp_get_post_revisions( $post_id );
		$this->assertNotSame( array(), $revisions );

		$revision_id = (int) array_key_first( $revisions );
		$this->assertGreaterThan( $post_id, $revision_id );
		$this->assertSame(
			'7700',
			get_post_meta(
				$revision_id,
				Options::META_SOURCE_POST_ID,
				true
			)
		);

		// ACT: Resolve the source ID.
		$found = $this->import_service->find_imported_post(
			7700,
			self::SOURCE_URL
		);

		// ASSERT: The parent post wins, not its higher-ID revision.
		$this->assertInstanceOf( WP_Post::class, $found );
		$this->assertSame( $post_id, $found->ID );
	}

	/**
	 * Verifies that the post-insert duplicate guard sees a sibling whose post
	 * type is excluded from site search, and still lets the oldest claim win.
	 */
	public function test_duplicate_guard_sees_pattern_sibling(): void {
		// ARRANGE: A pattern already claims this source ID.
		$winner_id = $this->create_claiming_post( 7800, 'wp_block' );

		// ACT: Insert a second post for the same source ID.
		$result = $this->import_service->persist_new_post(
			array(
				'post_title'   => 'Duplicate pattern',
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'wp_block',
				'meta_input'   => array(
					Options::META_SOURCE_POST_ID  => 7800,
					Options::META_SOURCE_SITE_URL => self::SOURCE_URL,
					Options::META_SOURCE_LINK     => self::SOURCE_URL . '/p',
					Options::META_IMPORTED_FROM   =>
						Options::META_IMPORTED_FROM_VALUE,
				),
			),
			0,
			array(),
			array(),
			array(),
			0
		);

		// ASSERT: The guard fired, naming the older claim as the winner.
		$this->assertWPError( $result );
		$this->assertSame( 'duplicate_import', $result->get_error_code() );
		$this->assertSame(
			$winner_id,
			(int) $result->get_error_data()['winning_post_id']
		);

		// ASSERT: The loser was discarded, leaving the original claim alone.
		$this->assertSame(
			array( $winner_id ),
			$this->claiming_post_ids( 7800 )
		);
	}

	/**
	 * Verifies that revisions are absent from the shared post type list while
	 * the types 'any' omits are present.
	 */
	public function test_post_types_span_hidden_types_not_revisions(): void {
		// ACT: Read the list every source-identity lookup queries.
		$post_types = Source_Identity_Lookup::post_types();

		// ASSERT: The types 'any' drops are covered, revisions are not.
		$this->assertContains( 'wp_block', $post_types );
		$this->assertContains( 'wp_navigation', $post_types );
		$this->assertContains( self::HIDDEN_TYPE, $post_types );
		$this->assertNotContains( 'revision', $post_types );
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
	 * Creates two posts claiming one source ID, giving the older one the later
	 * date so an ID-keyed and a date-keyed tie-break disagree.
	 *
	 * @param int    $source_id Source post ID both posts claim.
	 * @param string $post_type Destination post type.
	 * @return array{0: int, 1: int} Older then newer post ID.
	 */
	private function create_date_inverted_claims(
		int $source_id,
		string $post_type
	): array {
		$older = $this->create_claiming_post( $source_id, $post_type );
		$newer = $this->create_claiming_post( $source_id, $post_type );

		wp_update_post(
			array(
				'ID'            => $older,
				'post_date'     => '2030-01-01 00:00:00',
				'post_date_gmt' => '2030-01-01 00:00:00',
			)
		);

		return array( $older, $newer );
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
}
