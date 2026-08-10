<?php
/**
 * Term reconcile degradation integration tests.
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
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;
use WP_Post;
use WP_Term;

/**
 * Drives real imports to prove a term the import cannot reconcile degrades
 * instead of failing the post, and that the write it did make survives a later
 * rollback.
 */
class Term_Conflict_Degradation_Test extends Source_Posts_API_Test_Base {

	private const SOURCE_POST_ID = 9310;
	private const SOURCE_TERM_ID = 9410;

	/**
	 * Attention issues repository used to read back recorded degradations.
	 *
	 * @var Attention_Issues_Repository
	 */
	private Attention_Issues_Repository $attention_issues;

	/**
	 * Sets up the attention issues repository.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->attention_issues = new Attention_Issues_Repository();
	}

	/**
	 * Verifies that a rename the destination blocks records a degradation,
	 * leaves the post imported, and clears once the clash is resolved.
	 */
	public function test_rename_collision_degrades_then_clears(): void {
		// ARRANGE: A post carrying one imported category.
		$this->mock_post_overrides = array(
			'content'            => '<p>First revision.</p>',
			'safe_publish_terms' => array(
				'category' => array( $this->source_term( 'News' ) ),
			),
		);

		$first = $this->import();
		$this->assertTrue( $first['success'] );
		$post_id = (int) $first['post_id'];

		// ARRANGE: The destination already holds the name the source moves to.
		$blocking = wp_insert_term(
			'Updates',
			'category',
			array( 'slug' => 'updates' )
		);
		$this->assertIsArray( $blocking );

		$this->mock_post_overrides['content']                           =
			'<p>Second revision.</p>';
		$this->mock_post_overrides['safe_publish_terms']['category'][0] =
			$this->source_term( 'Updates' );

		// ACT: Re-import the post with the blocked rename.
		$second = $this->import();

		// ASSERT: The post updated, the term kept its name, and the clash is
		// recorded against the post.
		$this->assertTrue( $second['success'] );
		$this->assertStringContainsString(
			'Second revision.',
			$this->dest_post( $post_id )->post_content
		);
		$this->assertSame( 'News', $this->dest_term()->name );

		$issue = $this->conflict_issue( $post_id );
		$this->assertIsArray( $issue );
		$this->assertSame( 'term', $issue['target_kind'] );
		$this->assertSame( 'warning', $issue['severity'] );
		$this->assertSame( array( 'News' ), $issue['detail']['terms'] );
		$this->assertSame( 'name', $issue['detail']['field'] );
		$this->assertSame( 'name_taken', $issue['detail']['reason'] );

		// ARRANGE: Another post carries the same conflicted term.
		$sibling_post_id = self::factory()->post->create();
		$this->attention_issues->upsert_issue(
			$sibling_post_id,
			'term_field_conflict',
			self::SOURCE_TERM_ID,
			'term',
			'warning',
			Options::get_connected_site_url_with_path(),
			array(),
			'news'
		);

		// ACT: Remove the clashing term on the destination and re-import.
		wp_delete_term( (int) $blocking['term_id'], 'category' );
		$third = $this->import();

		// ASSERT: The rename lands, and both posts' degradations resolve.
		$this->assertTrue( $third['success'] );
		$this->assertSame( 'Updates', $this->dest_term()->name );
		$this->assertNull( $this->conflict_issue( $post_id ) );
		$this->assertNull( $this->conflict_issue( $sibling_post_id ) );
	}

	/**
	 * Verifies that reconciling a term through one post clears the conflicts
	 * every other post opened for it, since the term is shared.
	 */
	public function test_reconcile_clears_another_post_s_conflict(): void {
		// ARRANGE: Two posts share a term whose rename the destination blocks,
		// leaving the first post with an open conflict.
		$this->mock_post_overrides = array(
			'content'            => '<p>First revision.</p>',
			'safe_publish_terms' => array(
				'category' => array( $this->source_term( 'News' ) ),
			),
		);

		$first   = $this->import();
		$post_id = (int) $first['post_id'];

		$blocking = wp_insert_term(
			'Updates',
			'category',
			array( 'slug' => 'updates' )
		);
		$this->assertIsArray( $blocking );

		$this->mock_post_overrides['safe_publish_terms']['category'][0] =
			$this->source_term( 'Updates' );
		$this->import();
		$this->assertIsArray( $this->conflict_issue( $post_id ) );

		// ACT: Clear the clash, then import the other post sharing the term.
		wp_delete_term( (int) $blocking['term_id'], 'category' );
		$second = $this->import( self::SOURCE_POST_ID + 1 );

		// ASSERT: The term is current, and the first post's conflict is gone
		// even though that post was not re-imported.
		$this->assertTrue( $second['success'] );
		$this->assertSame( 'Updates', $this->dest_term()->name );
		$this->assertNull( $this->conflict_issue( $post_id ) );
	}

	/**
	 * Verifies that a reconciled term keeps its new fields when a later term
	 * fails and the post is rolled back, since other posts already share it.
	 */
	public function test_reconciled_term_survives_a_post_rollback(): void {
		// ARRANGE: A post carrying one imported category.
		$this->mock_post_overrides = array(
			'content'            => '<p>First revision.</p>',
			'safe_publish_terms' => array(
				'category' => array( $this->source_term( 'News' ) ),
			),
		);

		$first = $this->import();
		$this->assertTrue( $first['success'] );
		$post_id = (int) $first['post_id'];

		// ARRANGE: The source renames the term and adds one that cannot be
		// created, so the import aborts after the rename is written.
		$this->mock_post_overrides['content'] = '<p>Second revision.</p>';

		$this->mock_post_overrides['safe_publish_terms'] = array(
			'category' => array(
				$this->source_term( 'Updates' ),
				array(
					'id'          => self::SOURCE_TERM_ID + 1,
					'name'        => 'Uncreatable',
					'slug'        => 'uncreatable',
					'parent'      => 0,
					'description' => '',
					'assigned'    => true,
				),
			),
		);

		$fail = static fn() => new WP_Error(
			'insert_term_failed',
			'Simulated.'
		);
		add_filter( 'pre_insert_term', $fail );

		// ACT: Re-import, failing on the second term.
		$second = $this->import();
		remove_filter( 'pre_insert_term', $fail );

		// ASSERT: The post rolled back, the shared term kept its new name.
		$this->assertFalse( $second['success'] );
		$this->assertStringContainsString(
			'First revision.',
			$this->dest_post( $post_id )->post_content
		);
		$this->assertSame( 'Updates', $this->dest_term()->name );
	}

	/**
	 * Imports the source post through a service of its own, since each import
	 * is a separate request in production and the reconcile memo is per run.
	 *
	 * @param int $source_post_id Source post to import.
	 * @return array<string, mixed> Import result.
	 */
	private function import(
		int $source_post_id = self::SOURCE_POST_ID
	): array {
		$media_importer = new Media_Importer( new HTTP_Client() );

		$service = new Post_Import_Service(
			new Source_Posts_API( new HTTP_Client() ),
			$media_importer,
			new Content_Processor(
				$media_importer,
				new Content_Media_Processor( $media_importer ),
				new Shortcode_ID_Rewriter()
			),
			new History_Repository(),
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter(),
			$this->attention_issues
		);

		return $service->import_post( $this->post_data( $source_post_id ) );
	}

	/**
	 * Builds the source post payload handed to the importer.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return array<string, mixed> Source post data.
	 */
	private function post_data( int $source_post_id ): array {
		return array(
			'id'        => $source_post_id,
			'title'     => 'Term Conflict Post',
			'content'   => '<p>Snapshot content.</p>',
			'link'      => 'https://source.example.com/term-conflict-post',
			'post_type' => 'posts',
		);
	}

	/**
	 * Builds the source term record the mocked response carries.
	 *
	 * @param string $name Term name on the source.
	 * @return array<string, mixed> Source term record.
	 */
	private function source_term( string $name ): array {
		return array(
			'id'          => self::SOURCE_TERM_ID,
			'name'        => $name,
			'slug'        => 'news',
			'parent'      => 0,
			'description' => '',
			'assigned'    => true,
		);
	}

	/**
	 * Fetches the destination post, failing the test when it is missing.
	 *
	 * @param int $post_id Destination post ID.
	 * @return WP_Post Destination post.
	 */
	private function dest_post( int $post_id ): WP_Post {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		return $post;
	}

	/**
	 * Fetches the imported term, failing the test when it is missing.
	 *
	 * @return WP_Term Destination term.
	 */
	private function dest_term(): WP_Term {
		$term = get_term_by( 'slug', 'news', 'category' );

		$this->assertInstanceOf( WP_Term::class, $term );

		return $term;
	}

	/**
	 * Reads the term conflict degradation recorded against a post.
	 *
	 * @param int $post_id Destination post ID.
	 * @return array|null Issue row, or null when none is open.
	 */
	private function conflict_issue( int $post_id ): ?array {
		return $this->attention_issues->get_issue(
			$post_id,
			'term_field_conflict',
			self::SOURCE_TERM_ID,
			'term',
			'news'
		);
	}
}
