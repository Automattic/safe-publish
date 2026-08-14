<?php
/**
 * Term field reconcile integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Term_Conflict;
use Safe_Publish\Utils\Term_Reconcile_Report;
use WP_Error;
use WP_Term;

/**
 * Covers the reconcile of an existing term's fields on re-import: What it
 * updates, the terms it refuses to touch, and the conflicts it degrades on
 * instead of failing the post.
 */
class Term_Field_Reconcile_Test extends Integration_Test_Case {

	private const SOURCE       = 'https://source.example.com';
	private const OTHER_SOURCE = 'https://other.example.com';

	/**
	 * System under test.
	 *
	 * @var Meta_Terms_Manager
	 */
	private Meta_Terms_Manager $manager;

	/**
	 * Post the terms are assigned to.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Number of wp_update_term() writes since the counter was installed.
	 *
	 * @var int
	 */
	private int $term_updates = 0;

	/**
	 * Sets up test dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->manager = new Meta_Terms_Manager();
		$this->post_id = self::factory()->post->create();

		$this->term_updates = 0;
		add_action( 'edited_term', array( $this, 'count_term_update' ) );
	}

	/**
	 * Removes the write counter.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_action( 'edited_term', array( $this, 'count_term_update' ) );
		parent::tearDown();
	}

	/**
	 * Counts one wp_update_term() write.
	 */
	public function count_term_update(): void {
		++$this->term_updates;
	}

	/**
	 * Verifies that re-importing a term the plugin created updates its name,
	 * description, and parent to the source's current values.
	 */
	public function test_reconciles_name_description_and_parent(): void {
		// ARRANGE: A term imported as a root category with a description.
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, 'Old description' ) )
		);

		// ACT: The source renames it, rewrites the description, and moves it
		// under a new ancestor.
		$conflicts = $this->import_terms(
			array(
				$this->record( 500, 'Section', 'section', 0, '', false ),
				$this->record( 501, 'Updates', 'news', 500, 'New description' ),
			)
		);

		// ASSERT: All three fields follow the source, the slug does not, and
		// the term is marked as created for this source.
		$term = $this->term_by_slug( 'news' );
		$this->assertSame(
			self::SOURCE,
			$this->origin_marker( (int) $term->term_id )
		);
		$this->assertSame( 'Updates', $term->name );
		$this->assertSame( 'New description', $term->description );
		$this->assertSame(
			$this->term_by_slug( 'section' )->term_id,
			$term->parent
		);
		$this->assertSame( 'news', $term->slug );
		$this->assertSame( array(), $conflicts );
	}

	/**
	 * Verifies that a term whose source fields are unchanged is not written to
	 * again.
	 */
	public function test_unchanged_term_is_not_written(): void {
		// ARRANGE: A term imported once, with the write counter reset.
		$record = $this->record( 501, 'News', 'news', 0, 'Description' );
		$this->import_terms( array( $record ) );
		$this->term_updates = 0;

		// ACT: Re-import the identical record.
		$this->import_terms( array( $record ) );

		// ASSERT: Nothing was written.
		$this->assertSame( 0, $this->term_updates );
	}

	/**
	 * Verifies that a description core narrows on save is not rewritten by
	 * every later import.
	 */
	public function test_narrowed_description_is_not_rewritten(): void {
		// ARRANGE: A term whose description carries markup core strips from
		// term descriptions on save.
		$record = $this->record(
			501,
			'News',
			'news',
			0,
			'<p>Rich <em>copy</em>.</p>'
		);
		$this->import_terms( array( $record ) );
		$this->term_updates = 0;

		// ACT: Re-import the identical record.
		$this->import_terms( array( $record ) );

		// ASSERT: The stored description stands, with no further write.
		$this->assertSame(
			'Rich <em>copy</em>.',
			$this->term_by_slug( 'news' )->description
		);
		$this->assertSame( 0, $this->term_updates );
	}

	/**
	 * Verifies that a description kept verbatim, on a site that lifts core's
	 * term-description filtering, is not rewritten by every later import.
	 */
	public function test_unfiltered_description_is_not_rewritten(): void {
		// ARRANGE: A site whose term descriptions keep their markup. The test
		// harness restores hooks, so the filter returns after this test.
		remove_filter( 'pre_term_description', 'wp_filter_kses' );
		$record = $this->record(
			501,
			'News',
			'news',
			0,
			'<p>Rich <em>copy</em>.</p>'
		);
		$this->import_terms( array( $record ) );
		$this->term_updates = 0;

		// ACT: Re-import the identical record.
		$this->import_terms( array( $record ) );

		// ASSERT: The markup survives, with no further write.
		$this->assertSame(
			'<p>Rich <em>copy</em>.</p>',
			$this->term_by_slug( 'news' )->description
		);
		$this->assertSame( 0, $this->term_updates );
	}

	/**
	 * Verifies that a slug rename on the source leaves the destination slug in
	 * place, keeping destination URLs stable.
	 */
	public function test_slug_is_never_reconciled(): void {
		// ARRANGE: An imported term matched by source identity on re-import.
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, '' ) )
		);

		// ACT: The source renames the term and its slug.
		$this->import_terms(
			array( $this->record( 501, 'Updates', 'updates', 0, '' ) )
		);

		// ASSERT: The name follows, the slug stays.
		$term = $this->term_by_slug( 'news' );
		$this->assertSame( 'Updates', $term->name );
		$this->assertSame( 'news', $term->slug );
	}

	/**
	 * Verifies that an existing term the plugin did not create is reused
	 * unchanged, so a hand-authored term is never overwritten.
	 */
	public function test_unmarked_term_is_left_untouched(): void {
		// ARRANGE: A term authored on the destination, sharing the source slug.
		$created = wp_insert_term(
			'Hand Written',
			'category',
			array(
				'slug'        => 'news',
				'description' => 'Authored here',
			)
		);
		$this->assertIsArray( $created );

		// ACT: Import a source term that matches it by slug.
		$conflicts = $this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, 'From source' ) )
		);

		// ASSERT: Both fields and the origin marker are untouched.
		$term = $this->term_by_slug( 'news' );
		$this->assertSame( 'Hand Written', $term->name );
		$this->assertSame( 'Authored here', $term->description );
		$this->assertSame( '', $this->origin_marker( (int) $term->term_id ) );
		$this->assertSame( array(), $conflicts );
	}

	/**
	 * Verifies that a term recovered from the term_exists race is not marked as
	 * plugin-created, so a later import does not reconcile it.
	 */
	public function test_term_exists_recovery_is_not_marked(): void {
		// ARRANGE: A term the resolver cannot match, returned by a simulated
		// concurrent insert winning the race.
		$existing = wp_insert_term(
			'Raced Term',
			'category',
			array( 'slug' => 'raced-term' )
		);
		$this->assertIsArray( $existing );
		$existing_id = (int) $existing['term_id'];

		$race = static fn() => new WP_Error(
			'term_exists',
			'Raced.',
			$existing_id
		);
		add_filter( 'pre_insert_term', $race );

		// ACT: Import a term whose insert loses the race.
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, 'From source' ) )
		);
		remove_filter( 'pre_insert_term', $race );

		// ASSERT: The recovered term carries no origin marker.
		$this->assertSame( '', $this->origin_marker( $existing_id ) );

		// ACT: Re-import with changed fields, now matched by source identity.
		$this->import_terms(
			array( $this->record( 501, 'Renamed', 'news', 0, 'Rewritten' ) )
		);

		// ASSERT: The unmarked term is still reused unchanged.
		$term = get_term( $existing_id, 'category' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 'Raced Term', $term->name );
		$this->assertSame( '', $term->description );
	}

	/**
	 * Verifies that a term created for one source is not overwritten by an
	 * import from another, so a two-way setup cannot clobber either site.
	 */
	public function test_term_of_another_source_is_not_overwritten(): void {
		// ARRANGE: A term imported from one source.
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, 'From first' ) )
		);

		// ACT: A second source imports a term matching it by slug.
		$conflicts = $this->import_terms(
			array(
				$this->record( 501, 'Nouvelles', 'news', 0, 'From second' ),
			),
			self::OTHER_SOURCE
		);

		// ASSERT: The first source's term stands.
		$term = $this->term_by_slug( 'news' );
		$this->assertSame( 'News', $term->name );
		$this->assertSame( 'From first', $term->description );
		$this->assertSame( array(), $conflicts );
	}

	/**
	 * Verifies that a source field the payload does not carry leaves the
	 * destination value in place instead of clearing it.
	 */
	public function test_absent_fields_do_not_downgrade_the_term(): void {
		// ARRANGE: An imported child term with a description.
		$this->import_terms(
			array(
				$this->record( 500, 'Section', 'section', 0, '', false ),
				$this->record( 501, 'News', 'news', 500, 'Description' ),
			)
		);
		$parent_id = (int) $this->term_by_slug( 'section' )->term_id;

		// ACT: Re-import from a source that sends neither field.
		$conflicts = $this->import_terms(
			array( $this->record( 501, 'News', 'news', null, null ) )
		);

		// ASSERT: The description and the parent both stand.
		$term = $this->term_by_slug( 'news' );
		$this->assertSame( 'Description', $term->description );
		$this->assertSame( $parent_id, $term->parent );
		$this->assertSame( array(), $conflicts );
	}

	/**
	 * Verifies that the embedded fallback, which an older source's payload
	 * reaches the reconcile through, carries neither field. It is what puts a
	 * record that omits them in front of the reconcile.
	 */
	public function test_embedded_fallback_omits_both_fields(): void {
		// ARRANGE: An imported child term with a description.
		$this->import_terms(
			array(
				$this->record( 500, 'Section', 'section', 0, '', false ),
				$this->record( 501, 'News', 'news', 500, 'Description' ),
			)
		);
		$parent_id = (int) $this->term_by_slug( 'section' )->term_id;

		// ACT: Re-import the term as the embedded payload extracts it.
		$terms = Source_Posts_API::extract_embedded_terms(
			array(
				'_embedded' => array(
					'wp:term' => array(
						array(
							array(
								'id'       => 501,
								'name'     => 'News',
								'slug'     => 'news',
								'taxonomy' => 'category',
							),
						),
					),
				),
			)
		);

		$conflicts = $this->import_terms( $terms['category'] );

		// ASSERT: Neither key is extracted, so both destination values stand.
		$this->assertArrayNotHasKey( 'parent', $terms['category'][0] );
		$this->assertArrayNotHasKey( 'description', $terms['category'][0] );

		$term = $this->term_by_slug( 'news' );
		$this->assertSame( 'Description', $term->description );
		$this->assertSame( $parent_id, $term->parent );
		$this->assertSame( array(), $conflicts );
	}

	/**
	 * Verifies that a description the source cleared is cleared on the
	 * destination, since a current source sends the empty string.
	 */
	public function test_cleared_description_is_propagated(): void {
		// ARRANGE: An imported term carrying a description.
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, 'Description' ) )
		);

		// ACT: The source clears it.
		$conflicts = $this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, '' ) )
		);

		// ASSERT: The destination description is empty too.
		$this->assertSame( '', $this->term_by_slug( 'news' )->description );
		$this->assertSame( array(), $conflicts );
	}

	/**
	 * Verifies that a term the source moved to the top level is flattened on
	 * the destination.
	 */
	public function test_source_root_flattens_the_term(): void {
		// ARRANGE: An imported child term.
		$this->import_terms(
			array(
				$this->record( 500, 'Section', 'section', 0, '', false ),
				$this->record( 501, 'News', 'news', 500, '' ),
			)
		);
		$this->assertNotSame( 0, $this->term_by_slug( 'news' )->parent );

		// ACT: The source moves it to the top level.
		$conflicts = $this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, '' ) )
		);

		// ASSERT: The destination term is a root, with no conflict recorded.
		$this->assertSame( 0, $this->term_by_slug( 'news' )->parent );
		$this->assertSame( array(), $conflicts );
	}

	/**
	 * Verifies that a hierarchy the source inverted converges in one import,
	 * rather than deadlocking on the loop guard.
	 */
	public function test_inverted_hierarchy_converges(): void {
		// ARRANGE: An imported chain of Section, then News beneath it.
		$this->import_terms(
			array(
				$this->record( 500, 'Section', 'section', 0, '', false ),
				$this->record( 501, 'News', 'news', 500, '' ),
			)
		);

		// ACT: The source swaps the two, so News becomes the root.
		$conflicts = $this->import_terms(
			array(
				$this->record( 501, 'News', 'news', 0, '', false ),
				$this->record( 500, 'Section', 'section', 501, '' ),
			)
		);

		// ASSERT: The destination follows the source both ways.
		$news = $this->term_by_slug( 'news' );
		$this->assertSame( 0, $news->parent );
		$this->assertSame(
			(int) $news->term_id,
			$this->term_by_slug( 'section' )->parent
		);
		$this->assertSame( array(), $conflicts );
	}

	/**
	 * Verifies that a backslash in a name or description survives the write and
	 * is not rewritten by every later import.
	 */
	public function test_backslashes_survive_the_write(): void {
		// ARRANGE: A term whose name and description carry backslashes.
		$record = $this->record(
			501,
			'App\Models',
			'app-models',
			0,
			'Path: C:\builds\out'
		);
		$this->import_terms( array( $record ) );

		// ASSERT: Created verbatim.
		$term = $this->term_by_slug( 'app-models' );
		$this->assertSame( 'App\Models', $term->name );
		$this->assertSame( 'Path: C:\builds\out', $term->description );

		// ACT: Re-import the identical record.
		$this->term_updates = 0;
		$this->import_terms( array( $record ) );

		// ASSERT: Both fields stand, and the compare converged.
		$term = $this->term_by_slug( 'app-models' );
		$this->assertSame( 'App\Models', $term->name );
		$this->assertSame( 'Path: C:\builds\out', $term->description );
		$this->assertSame( 0, $this->term_updates );
	}

	/**
	 * Verifies that a rename that only changes case is applied, since the
	 * database name compare that guards it is case-insensitive.
	 */
	public function test_case_only_rename_is_applied(): void {
		// ARRANGE: An imported term.
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, '' ) )
		);

		// ACT: The source upper-cases the name.
		$conflicts = $this->import_terms(
			array( $this->record( 501, 'NEWS', 'news', 0, '' ) )
		);

		// ASSERT: The rename landed, and the term did not block itself.
		$this->assertSame( 'NEWS', $this->term_by_slug( 'news' )->name );
		$this->assertSame( array(), $conflicts );
	}

	/**
	 * Verifies that a case-only rename onto a name another term holds is still
	 * refused: The term itself also matches the case-insensitive lookup, so the
	 * blocker is only visible when the lookup reaches past the first match.
	 */
	public function test_case_only_rename_onto_a_taken_name_conflicts(): void {
		// ARRANGE: An imported tag, and another tag holding the target name in
		// a different case under its own slug.
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, '' ) ),
			self::SOURCE,
			'post_tag'
		);
		$blocking = wp_insert_term(
			'NEWS',
			'post_tag',
			array( 'slug' => 'news-blocking' )
		);
		$this->assertIsArray( $blocking );

		// ACT: The source upper-cases the imported tag's name.
		$conflicts = $this->import_terms(
			array( $this->record( 501, 'NEWS', 'news', 0, '' ) ),
			self::SOURCE,
			'post_tag'
		);

		// ASSERT: The name stands and the clash is reported.
		$this->assertSame(
			'News',
			$this->term_by_slug( 'news', 'post_tag' )->name
		);
		$this->assertSame( 1, count( $conflicts ) );
		$this->assertSame( 'name_taken', $conflicts[0]->reason );
	}

	/**
	 * Verifies that a term core recovers as a pre-existing duplicate is not
	 * marked as plugin-created, so a later import does not reconcile it.
	 */
	public function test_duplicate_recovery_is_not_marked(): void {
		// ARRANGE: A hand-authored term, with core's late duplicate check
		// pointed at it to stand in for the insert race it guards.
		$existing = wp_insert_term(
			'Hand Written',
			'category',
			array( 'slug' => 'hand-written' )
		);
		$this->assertIsArray( $existing );
		$existing_id = (int) $existing['term_id'];

		$duplicate = get_term( $existing_id, 'category' );
		$this->assertInstanceOf( WP_Term::class, $duplicate );
		$race = static fn() => $duplicate;
		add_filter( 'wp_insert_term_duplicate_term_check', $race );

		// ACT: Import a term whose insert core resolves to the existing one.
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, 'From source' ) )
		);
		remove_filter( 'wp_insert_term_duplicate_term_check', $race );

		// ASSERT: The recovered term carries no origin marker.
		$this->assertSame( '', $this->origin_marker( $existing_id ) );

		// ACT: Re-import with changed fields, now matched by source identity.
		$this->import_terms(
			array( $this->record( 501, 'Renamed', 'news', 0, 'Rewritten' ) )
		);

		// ASSERT: The hand-authored term is still reused unchanged.
		$term = get_term( $existing_id, 'category' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 'Hand Written', $term->name );
		$this->assertSame( '', $term->description );
	}

	/**
	 * Verifies that a rename onto a name another term already holds keeps the
	 * current name and reports a conflict instead of failing the post.
	 */
	public function test_name_collision_keeps_the_name_and_conflicts(): void {
		// ARRANGE: An imported tag, and a second tag already named Updates.
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, '' ) ),
			self::SOURCE,
			'post_tag'
		);
		$blocking = wp_insert_term(
			'Updates',
			'post_tag',
			array( 'slug' => 'updates' )
		);
		$this->assertIsArray( $blocking );

		// ACT: The source renames the imported tag onto the taken name.
		$conflicts = $this->import_terms(
			array( $this->record( 501, 'Updates', 'news', 0, '' ) ),
			self::SOURCE,
			'post_tag'
		);

		// ASSERT: The term keeps its name and the clash is reported.
		$term = $this->term_by_slug( 'news', 'post_tag' );
		$this->assertSame( 'News', $term->name );
		$this->assertSame( 1, count( $conflicts ) );
		$this->assertSame( 'name', $conflicts[0]->field );
		$this->assertSame( 'name_taken', $conflicts[0]->reason );
		$this->assertSame( 'News', $conflicts[0]->term_name );
		$this->assertSame( 501, $conflicts[0]->source_term_id );
	}

	/**
	 * Verifies that a parent the payload cannot resolve keeps the term's
	 * current parent rather than flattening it to the root.
	 */
	public function test_unresolvable_parent_keeps_the_parent(): void {
		// ARRANGE: An imported child term under an imported parent.
		$this->import_terms(
			array(
				$this->record( 500, 'Section', 'section', 0, '', false ),
				$this->record( 501, 'News', 'news', 500, '' ),
			)
		);
		$parent_id = (int) $this->term_by_slug( 'section' )->term_id;

		// ACT: The source moves it under an ancestor missing from the payload.
		$conflicts = $this->import_terms(
			array( $this->record( 501, 'News', 'news', 900, '' ) )
		);

		// ASSERT: The term keeps its parent and the miss is reported.
		$this->assertSame( $parent_id, $this->term_by_slug( 'news' )->parent );
		$this->assertSame( 1, count( $conflicts ) );
		$this->assertSame( 'parent', $conflicts[0]->field );
		$this->assertSame( 'parent_unresolved', $conflicts[0]->reason );
		$this->assertSame( 'News', $conflicts[0]->term_name );
	}

	/**
	 * Verifies that a parent that would nest a term under its own descendant is
	 * refused, since core's loop guard would silently re-root the term.
	 */
	public function test_looping_parent_keeps_the_parent(): void {
		// ARRANGE: An imported root term with an imported child.
		$this->import_terms(
			array(
				$this->record( 500, 'Section', 'section', 0, '', false ),
				$this->record( 501, 'News', 'news', 500, '' ),
			)
		);

		// ACT: The source moves the root under its own child, whose own parent
		// the payload omits.
		$conflicts = $this->import_terms(
			array(
				$this->record( 501, 'News', 'news', null, '', false ),
				$this->record( 500, 'Section', 'section', 501, '' ),
			)
		);

		// ASSERT: The root stays at the top and the loop is reported.
		$this->assertSame( 0, $this->term_by_slug( 'section' )->parent );
		$this->assertSame( 1, count( $conflicts ) );
		$this->assertSame( 'parent', $conflicts[0]->field );
		$this->assertSame( 'parent_loop', $conflicts[0]->reason );
	}

	/**
	 * Verifies that a write core refuses names every field it carried, so the
	 * degradation reports all of them rather than the first.
	 */
	public function test_failed_write_names_every_field_it_carried(): void {
		// ARRANGE: An imported term the source is about to rename and rewrite.
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, 'Old description' ) )
		);

		// ARRANGE: Core refuses every term write from here on. The test harness
		// restores hooks, so the filter goes after this test.
		add_filter( 'pre_term_name', '__return_empty_string' );

		// ACT: Re-import with both the name and the description changed.
		$conflicts = $this->import_terms(
			array(
				$this->record( 501, 'Updates', 'news', 0, 'New description' ),
			)
		);

		// ASSERT: One conflict names both fields.
		$this->assertSame( 1, count( $conflicts ) );
		$this->assertSame( 'update_failed', $conflicts[0]->reason );
		$this->assertSame( 'name, description', $conflicts[0]->field );
		$this->assertSame( 'News', $conflicts[0]->term_name );
		$this->assertSame( 501, $conflicts[0]->source_term_id );

		// ASSERT: Neither field was written.
		$term = $this->term_by_slug( 'news' );
		$this->assertSame( 'News', $term->name );
		$this->assertSame( 'Old description', $term->description );
	}

	/**
	 * Verifies that a term already reconciled in the run is not written again
	 * by a later post, even when it drifted in between.
	 */
	public function test_term_is_reconciled_once_per_run(): void {
		// ARRANGE: A tag imported for two posts, then rewritten by the source.
		$second_post = self::factory()->post->create();
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, 'Original' ) ),
			self::SOURCE,
			'post_tag'
		);
		$rewritten = array(
			$this->record( 501, 'News', 'news', 0, 'Reconciled' ),
		);
		$this->import_terms( $rewritten, self::SOURCE, 'post_tag' );

		// ARRANGE: The term is edited mid-run, after the first post reconciled
		// it.
		wp_update_term(
			(int) $this->term_by_slug( 'news', 'post_tag' )->term_id,
			'post_tag',
			array( 'description' => 'Edited here' )
		);
		$this->term_updates = 0;

		// ACT: A second post of the same run imports the same term.
		$this->import_terms(
			$rewritten,
			self::SOURCE,
			'post_tag',
			$second_post
		);

		// ASSERT: The term is left alone, so the run wrote it exactly once.
		$this->assertSame( 0, $this->term_updates );
		$this->assertSame(
			'Edited here',
			$this->term_by_slug( 'news', 'post_tag' )->description
		);
	}

	/**
	 * Verifies that a term shared by several posts in one run is written once,
	 * while every post using it still reports the conflict it hit.
	 */
	public function test_shared_term_is_reconciled_once_per_run(): void {
		// ARRANGE: A tag imported for two posts, blocked from renaming.
		$second_post = self::factory()->post->create();
		$this->import_terms(
			array( $this->record( 501, 'News', 'news', 0, '' ) ),
			self::SOURCE,
			'post_tag'
		);
		$blocking = wp_insert_term(
			'Updates',
			'post_tag',
			array( 'slug' => 'updates' )
		);
		$this->assertIsArray( $blocking );
		$this->term_updates = 0;

		// ACT: Both posts re-import the renamed tag in the same run.
		$renamed = array(
			$this->record( 501, 'Updates', 'news', 0, 'Shared' ),
		);
		$first   = $this->import_terms( $renamed, self::SOURCE, 'post_tag' );
		$second  = $this->import_terms(
			$renamed,
			self::SOURCE,
			'post_tag',
			$second_post
		);

		// ASSERT: One write, and both posts carry the conflict.
		$this->assertSame( 1, $this->term_updates );
		$this->assertSame( 1, count( $first ) );
		$this->assertSame( 1, count( $second ) );
		$this->assertSame( 'name_taken', $second[0]->reason );
		$this->assertSame(
			'Shared',
			$this->term_by_slug( 'news', 'post_tag' )->description
		);
	}

	/**
	 * Runs one import of a taxonomy's records and returns the conflicts it
	 * collected, failing the test when the terms could not be assigned.
	 *
	 * @param array    $records         Term records for the taxonomy.
	 * @param string   $source_site_url Source the import runs for.
	 * @param string   $taxonomy        Taxonomy slug.
	 * @param int|null $post_id         Post to assign to; defaults to the shared post.
	 * @return list<Term_Conflict> Collected conflicts.
	 */
	private function import_terms(
		array $records,
		string $source_site_url = self::SOURCE,
		string $taxonomy = 'category',
		?int $post_id = null
	): array {
		$report = new Term_Reconcile_Report();

		$result = $this->manager->update_terms(
			$post_id ?? $this->post_id,
			array( $taxonomy => $records ),
			$source_site_url,
			$report
		);

		$this->assertIsArray(
			$result,
			'Terms should import without erroring.'
		);

		return $report->conflicts();
	}

	/**
	 * Builds one source term record. A null parent or description omits the
	 * key, standing in for a source that does not send it; 0 and the empty
	 * string are what a current source sends.
	 *
	 * @param int         $source_term_id Source term ID.
	 * @param string      $name           Term name.
	 * @param string      $slug           Term slug.
	 * @param int|null    $parent_id      Source parent term ID, or null to omit.
	 * @param string|null $description    Term description, or null to omit.
	 * @param bool        $assigned       Whether the post carries the term.
	 * @return array<string, string|int|bool> Source term record.
	 */
	private function record(
		int $source_term_id,
		string $name,
		string $slug,
		?int $parent_id,
		?string $description,
		bool $assigned = true
	): array {
		$record = array(
			'source_term_id' => $source_term_id,
			'name'           => $name,
			'slug'           => $slug,
			'assigned'       => $assigned,
		);

		if ( null !== $parent_id ) {
			$record['parent'] = $parent_id;
		}

		if ( null !== $description ) {
			$record['description'] = $description;
		}

		return $record;
	}

	/**
	 * Fetches a destination term by slug, failing the test when it is missing.
	 *
	 * @param string $slug     Term slug.
	 * @param string $taxonomy Taxonomy slug.
	 * @return WP_Term Matching term.
	 */
	private function term_by_slug(
		string $slug,
		string $taxonomy = 'category'
	): WP_Term {
		$term = get_term_by( 'slug', $slug, $taxonomy );

		$this->assertInstanceOf( WP_Term::class, $term );

		return $term;
	}

	/**
	 * Reads a term's origin marker.
	 *
	 * @param int $term_id Destination term ID.
	 * @return string Stored marker, or an empty string.
	 */
	private function origin_marker( int $term_id ): string {
		return (string) get_term_meta(
			$term_id,
			Options::META_TERM_ORIGIN_URL,
			true
		);
	}
}
