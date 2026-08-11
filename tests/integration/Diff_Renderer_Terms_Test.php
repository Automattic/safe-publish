<?php
/**
 * Diff renderer taxonomy integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Diff_Renderer;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Post_Type_Resolver;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Utils\Options;
use WP_REST_Request;
use WP_Term;

/**
 * Covers the taxonomies section of the diff: The term hierarchy and
 * description differences it surfaces, the notes it attaches to the ones the
 * import would not apply, and its fallback when the source sends neither.
 *
 * @psalm-suppress InvalidArgument
 */
class Diff_Renderer_Terms_Test extends Integration_Test_Case {

	private const SOURCE         = 'https://source.example.com';
	private const OTHER_SOURCE   = 'https://other.example.com';
	private const SOURCE_POST_ID = 4242;

	/**
	 * Local post the diff runs against.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Number of term writes since the counters were installed.
	 *
	 * @var int
	 */
	private int $term_writes = 0;

	/**
	 * Sets up test dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Source_Post_Type_Resolver::reset_cache();
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE );

		$this->post_id = self::factory()->post->create();
		update_post_meta(
			$this->post_id,
			Options::META_SOURCE_POST_ID,
			self::SOURCE_POST_ID
		);
		update_post_meta(
			$this->post_id,
			Options::META_SOURCE_SITE_URL,
			Options::get_connected_site_url_with_path()
		);

		$this->term_writes = 0;
		add_action( 'edited_term', array( $this, 'count_term_write' ) );
		add_action( 'created_term', array( $this, 'count_term_write' ) );
	}

	/**
	 * Removes the write counters.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_action( 'edited_term', array( $this, 'count_term_write' ) );
		remove_action( 'created_term', array( $this, 'count_term_write' ) );
		parent::tearDown();
	}

	/**
	 * Counts one term write.
	 */
	public function count_term_write(): void {
		++$this->term_writes;
	}

	/**
	 * Verifies that a term's changed description reaches the diff, which the
	 * embedded payload alone cannot carry.
	 */
	public function test_description_change_shows_without_a_note(): void {
		// ARRANGE: An imported term carrying a description.
		$this->import_terms(
			array( $this->record( 101, 'News', 'news', 0, 'Morning desk' ) )
		);

		// ACT: The source rewrites the description.
		$html = $this->render_taxonomies(
			array( $this->record( 101, 'News', 'news', 0, 'Evening desk' ) )
		);

		// ASSERT: Both descriptions show, and nothing is annotated, since the
		// import will apply the change. Core word-diffs the line, so assert the
		// words that differ rather than the whole description.
		$this->assertStringContainsString( 'Morning', $html );
		$this->assertStringContainsString( 'Evening', $html );
		$this->assertSame( array(), $this->notes( $html ) );
	}

	/**
	 * Verifies that a description core narrows on the way in does not read as a
	 * pending change. Comparing the source's own form would report one on every
	 * import, for as long as the description carries the markup.
	 */
	public function test_narrowed_description_is_not_a_change(): void {
		// ARRANGE: A term imported with markup a term description narrows away.
		$description = '<h2>Desk</h2>';
		$this->import_terms(
			array( $this->record( 101, 'News', 'news', 0, $description ) )
		);

		// ACT: The source sends the same description again.
		$html = $this->render_taxonomies(
			array( $this->record( 101, 'News', 'news', 0, $description ) )
		);

		// ASSERT: Nothing is rendered, whichever form the destination stored.
		$this->assertSame( '', $html );
	}

	/**
	 * Verifies that a term moved under a new parent shows the move, and that a
	 * parent the import would create on the way is not mistaken for a missing
	 * one.
	 */
	public function test_parent_change_shows_without_a_note(): void {
		// ARRANGE: An imported term under an imported parent.
		$this->import_terms(
			array(
				$this->record( 100, 'Politics', 'politics', 0, '' ),
				$this->record( 101, 'News', 'news', 100, '' ),
			)
		);

		// ACT: The source moves it under an ancestor this site does not have.
		$html = $this->render_taxonomies(
			array(
				$this->record( 102, 'World', 'world', 0, '', false ),
				$this->record( 101, 'News', 'news', 102, '' ),
			)
		);

		// ASSERT: Both parents show, and the move is not annotated.
		$this->assertStringContainsString( 'News (category) parent', $html );
		$this->assertStringContainsString( 'Politics', $html );
		$this->assertStringContainsString( 'World', $html );
		$this->assertSame( array(), $this->notes( $html ) );
	}

	/**
	 * Verifies that a term this plugin did not create for the connected source
	 * shows its difference and is annotated, since the import reuses it as is.
	 */
	public function test_ineligible_term_is_annotated(): void {
		// ARRANGE: A hand-authored term and one imported from another source.
		self::factory()->term->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'News',
				'slug'        => 'news',
				'description' => 'Local desk',
			)
		);
		$this->import_terms(
			array( $this->record( 102, 'Sports', 'sports', 0, 'Local games' ) ),
			self::OTHER_SOURCE
		);

		// ACT: The source rewrites both descriptions.
		$html = $this->render_taxonomies(
			array(
				$this->record( 101, 'News', 'news', 0, 'Source desk' ),
				$this->record( 102, 'Sports', 'sports', 0, 'Source games' ),
			)
		);

		// ASSERT: Each term is named once, with the field that will not move.
		$this->assertSame(
			array(
				'News (category): description not updated on import',
				'Sports (category): description not updated on import',
			),
			$this->notes( $html )
		);
	}

	/**
	 * Verifies that a description the source cleared shows unannotated, since
	 * the import clears the destination to match.
	 */
	public function test_cleared_source_description_is_not_annotated(): void {
		// ARRANGE: An imported term carrying a description.
		$this->import_terms(
			array( $this->record( 101, 'News', 'news', 0, 'Desk' ) )
		);

		// ACT: The source clears it.
		$html = $this->render_taxonomies(
			array( $this->record( 101, 'News', 'news', 0, '' ) )
		);

		// ASSERT: The difference shows, with no note, as the import applies it.
		$this->assertStringContainsString( 'Desk', $html );
		$this->assertSame( array(), $this->notes( $html ) );
	}

	/**
	 * Verifies that a parent the source dropped shows unannotated, since the
	 * import flattens the destination term to the root to match.
	 */
	public function test_cleared_source_parent_is_not_annotated(): void {
		// ARRANGE: An imported term under an imported parent.
		$this->import_terms(
			array(
				$this->record( 100, 'Politics', 'politics', 0, '' ),
				$this->record( 101, 'News', 'news', 100, '' ),
			)
		);

		// ACT: The source moves it to the top level.
		$html = $this->render_taxonomies(
			array( $this->record( 101, 'News', 'news', 0, '' ) )
		);

		// ASSERT: The dropped parent shows, with no note, as the import applies
		// it.
		$this->assertStringContainsString( 'Politics', $html );
		$this->assertSame( array(), $this->notes( $html ) );
	}

	/**
	 * Verifies that a rename another term already blocks is annotated as a
	 * forecast rather than as a settled outcome.
	 */
	public function test_blocked_rename_is_annotated_as_a_forecast(): void {
		// ARRANGE: An imported term, and another already holding the name the
		// source renames it to.
		$this->import_terms(
			array( $this->record( 101, 'News', 'news', 0, '' ) )
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Updates',
				'slug'     => 'updates',
			)
		);

		// ACT: The source renames it onto the taken name.
		$html = $this->render_taxonomies(
			array( $this->record( 101, 'Updates', 'news', 0, '' ) )
		);

		// ASSERT: The rename shows, hedged on the clash.
		$this->assertSame(
			array( 'News (category): may not apply — name taken' ),
			$this->notes( $html )
		);
	}

	/**
	 * Verifies that the note names the taxonomy, so terms sharing a name across
	 * taxonomies stay distinct.
	 */
	public function test_notes_name_the_taxonomy(): void {
		// ARRANGE: A hand-authored category and tag of the same name, both on
		// the post.
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$term_id = self::factory()->term->create(
				array(
					'taxonomy'    => $taxonomy,
					'name'        => 'News',
					'slug'        => 'news',
					'description' => 'Local',
				)
			);
			wp_set_post_terms( $this->post_id, array( $term_id ), $taxonomy );
		}

		// ACT: The source rewrites both descriptions.
		$html = $this->render_taxonomies(
			array( $this->record( 101, 'News', 'news', 0, 'Source' ) ),
			array( $this->record( 201, 'News', 'news', 0, 'Source' ) )
		);

		// ASSERT: The two notes are told apart by taxonomy.
		$this->assertSame(
			array(
				'News (category): description not updated on import',
				'News (post_tag): description not updated on import',
			),
			$this->notes( $html )
		);
	}

	/**
	 * Verifies that a term the post is about to gain is annotated only for the
	 * values the comparison shows. Its destination description is not on the
	 * current side, so a note about it would point at nothing.
	 */
	public function test_added_term_is_annotated_on_shown_values_only(): void {
		// ARRANGE: A term the post does not carry, described locally.
		self::factory()->term->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'News',
				'slug'        => 'news',
				'description' => 'Local desk',
			)
		);

		// ACT: The source assigns it, carrying no description of its own.
		$html = $this->render_taxonomies(
			array( $this->record( 101, 'News', 'news', 0, '' ) )
		);

		// ASSERT: The term is added and nothing is annotated, since the
		// description the import leaves alone is on neither side.
		$this->assertNotSame( '', $html );
		$this->assertStringContainsString( 'News', $html );
		$this->assertSame( array(), $this->notes( $html ) );
	}

	/**
	 * Verifies that unchanged terms render no section at all, so the client
	 * keeps omitting it.
	 */
	public function test_matching_terms_render_nothing(): void {
		// ARRANGE: An imported term with a parent and a description.
		$records = array(
			$this->record( 100, 'Politics', 'politics', 0, 'Desk', false ),
			$this->record( 101, 'News', 'news', 100, 'Daily' ),
		);
		$this->import_terms( $records );

		// ACT: The source sends the same terms again.
		$html = $this->render_taxonomies( $records );

		// ASSERT: Nothing is rendered, notes included.
		$this->assertSame( '', $html );
	}

	/**
	 * Verifies that ancestors the source sends unassigned stay out of the
	 * comparison, since the import creates but does not attach them.
	 */
	public function test_unassigned_ancestors_are_not_compared(): void {
		// ARRANGE: An imported term under an imported parent.
		$records = array(
			$this->record( 100, 'Politics', 'politics', 0, '', false ),
			$this->record( 101, 'News', 'news', 100, '' ),
		);
		$this->import_terms( $records );

		// ACT: The source sends the same set, then one with a new description
		// on the child alone.
		$unchanged = $this->render_taxonomies( $records );
		$changed   = $this->render_taxonomies(
			array(
				$this->record( 100, 'Politics', 'politics', 0, '', false ),
				$this->record( 101, 'News', 'news', 100, 'Daily' ),
			)
		);

		// ASSERT: The unassigned ancestor never reads as an added term.
		$this->assertSame( '', $unchanged );
		$this->assertStringContainsString( 'category: News', $changed );
		$this->assertStringNotContainsString( 'News, Politics', $changed );
	}

	/**
	 * Verifies that a source sending no safe_publish_terms falls back to the
	 * embedded names, with no annotations, since eligibility cannot be judged
	 * without the field.
	 */
	public function test_absent_source_terms_field_falls_back_to_names(): void {
		// ARRANGE: An imported term carrying a description.
		$this->import_terms(
			array( $this->record( 101, 'News', 'news', 0, 'Desk' ) )
		);

		// ACT: A source on an older plugin version sends only the embedded
		// payload, naming a different term.
		$result = $this->render(
			array(
				'_embedded' => array(
					'wp:term' => array(
						array(
							array(
								'id'       => 101,
								'taxonomy' => 'category',
								'name'     => 'Updates',
								'slug'     => 'news',
							),
						),
					),
				),
			)
		);
		$html   = (string) $result['nonContentDiffs']['taxonomies'];

		// ASSERT: The names compare as before, with no fields and no notes.
		$this->assertStringContainsString( 'Updates', $html );
		$this->assertStringNotContainsString( 'description', $html );
		$this->assertSame( array(), $this->notes( $html ) );
	}

	/**
	 * Verifies that rendering the diff writes no terms, even when it reports
	 * changes the import would make.
	 */
	public function test_render_diff_writes_no_terms(): void {
		// ARRANGE: An imported term, with the write counters reset.
		$this->import_terms(
			array( $this->record( 101, 'News', 'news', 0, 'Desk' ) )
		);
		$term_id           = (int) $this->term_by_slug( 'news' )->term_id;
		$this->term_writes = 0;

		// ACT: Render a diff that renames, re-describes, and re-parents it.
		$this->render_taxonomies(
			array(
				$this->record( 100, 'Politics', 'politics', 0, '', false ),
				$this->record( 101, 'Updates', 'news', 100, 'New desk' ),
			)
		);

		// ASSERT: The term is untouched and no term was created.
		$term = get_term( $term_id, 'category' );
		$this->assertSame( 0, $this->term_writes );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 'News', $term->name );
		$this->assertSame( 'Desk', $term->description );
		$this->assertSame( 0, (int) $term->parent );
		$this->assertFalse( get_term_by( 'slug', 'politics', 'category' ) );
	}

	/**
	 * Renders the diff and returns its taxonomies section.
	 *
	 * @param array $categories Source category records.
	 * @param array $tags       Source tag records.
	 * @return string Taxonomies diff HTML.
	 */
	private function render_taxonomies(
		array $categories,
		array $tags = array()
	): string {
		$terms = array( 'category' => $categories );

		if ( array() !== $tags ) {
			$terms['post_tag'] = $tags;
		}

		$result = $this->render( array( 'safe_publish_terms' => $terms ) );

		return (string) $result['nonContentDiffs']['taxonomies'];
	}

	/**
	 * Renders the diff against a mocked source response.
	 *
	 * @param array $body Source response fields merged over the defaults.
	 * @return array Diff result.
	 */
	private function render( array $body ): array {
		$response = array_merge(
			array(
				'title'   => array( 'raw' => 'Title' ),
				'content' => array( 'raw' => 'Content' ),
				'excerpt' => array( 'raw' => '' ),
			),
			$body
		);

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$make_request = static function ( $_url, $_action, $_credentials ) use ( $response ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode( $response ),
			);
		};

		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', self::SOURCE_POST_ID );
		$request->set_param( 'postType', 'post' );
		$request->set_param( 'mode', 'split' );

		$result = ( new Diff_Renderer() )->render_diff(
			$request,
			$make_request,
			array()
		);

		$this->assertIsArray( $result, 'The diff should render without erroring.' );

		return $result;
	}

	/**
	 * Reads the note lines out of a rendered taxonomies section.
	 *
	 * @param string $html Taxonomies diff HTML.
	 * @return string[] Note lines.
	 */
	private function notes( string $html ): array {
		$list    = array();
		$pattern = '#<ul class="safe-publish-term-notes">(.*)</ul>#s';

		if ( 1 !== preg_match( $pattern, $html, $list ) ) {
			return array();
		}

		$items = array();
		preg_match_all( '#<li>(.*?)</li>#s', $list[1], $items );

		return array_map(
			static fn( string $item ): string => html_entity_decode( $item, ENT_QUOTES ),
			$items[1]
		);
	}

	/**
	 * Runs one import of the given records, failing the test when the terms
	 * could not be assigned.
	 *
	 * @param array  $records         Source term records.
	 * @param string $source_site_url Source the import runs for.
	 */
	private function import_terms(
		array $records,
		string $source_site_url = self::SOURCE
	): void {
		$terms = Source_Posts_API::extract_source_terms(
			array( 'safe_publish_terms' => array( 'category' => $records ) )
		);

		$this->assertIsArray( $terms );

		$result = ( new Meta_Terms_Manager() )->update_terms(
			$this->post_id,
			$terms,
			$source_site_url
		);

		$this->assertIsArray( $result, 'Terms should import without erroring.' );
	}

	/**
	 * Builds one source term record, in the payload's own shape.
	 *
	 * @param int    $source_term_id Source term ID.
	 * @param string $name           Term name.
	 * @param string $slug           Term slug.
	 * @param int    $parent_id      Source parent term ID.
	 * @param string $description    Term description.
	 * @param bool   $assigned       Whether the post carries the term.
	 * @return array<string, string|int|bool> Source term record.
	 */
	private function record(
		int $source_term_id,
		string $name,
		string $slug,
		int $parent_id,
		string $description,
		bool $assigned = true
	): array {
		return array(
			'id'          => $source_term_id,
			'name'        => $name,
			'slug'        => $slug,
			'parent'      => $parent_id,
			'description' => $description,
			'assigned'    => $assigned,
		);
	}

	/**
	 * Fetches a destination term by slug, failing the test when it is missing.
	 *
	 * @param string $slug Term slug.
	 * @return WP_Term Matching term.
	 */
	private function term_by_slug( string $slug ): WP_Term {
		$term = get_term_by( 'slug', $slug, 'category' );

		$this->assertInstanceOf( WP_Term::class, $term );

		return $term;
	}
}
