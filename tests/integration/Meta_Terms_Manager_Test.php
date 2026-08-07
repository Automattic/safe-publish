<?php
/**
 * Meta_Terms_Manager integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Utils\Options;
use WP_Error;
use WP_Term;

/**
 * Meta Terms Manager Test Class.
 */
class Meta_Terms_Manager_Test extends Integration_Test_Case {

	/**
	 * System under test.
	 *
	 * @var Meta_Terms_Manager
	 */
	private Meta_Terms_Manager $manager;

	/**
	 * Post ID used across tests.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Sets up test dependencies.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->manager = new Meta_Terms_Manager();
		$this->post_id = self::factory()->post->create();
	}

	/**
	 * Verifies that update_terms() returns true when no terms are provided.
	 */
	public function test_update_terms_returns_true_for_empty_input(): void {
		// ARRANGE: Empty terms input.
		$terms = array();

		// ACT: Call with empty terms.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		// ASSERT: Returns true without error.
		$this->assertTrue( $result );
	}

	/**
	 * Verifies that update_terms() returns a WP_Error when the taxonomy does
	 * not exist on this site.
	 */
	public function test_update_terms_returns_error_for_unknown_taxonomy(): void {
		// ARRANGE: Terms keyed by a taxonomy that is not registered.
		$terms = array( 'nonexistent_taxonomy_xyz' => array( 'Some Term' ) );

		// ACT: Attempt to assign terms for an unknown taxonomy.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		// ASSERT: Returns a WP_Error with the expected error code.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_taxonomy', $result->get_error_code() );
		$this->assertStringContainsString( 'nonexistent_taxonomy_xyz', $result->get_error_message() );
	}

	/**
	 * Verifies that update_terms() returns true and assigns an existing
	 * category term.
	 */
	public function test_update_terms_assigns_existing_term(): void {
		// ARRANGE: Create a category term to assign.
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$terms   = array( 'category' => array( array( 'term_id' => $term_id ) ) );

		// ACT: Assign the existing term to the post.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		// ASSERT: Returns true and the term is assigned.
		$this->assertTrue( $result );
		$assigned = wp_get_post_terms( $this->post_id, 'category', array( 'fields' => 'ids' ) );
		$this->assertSame( array( $term_id ), $assigned );
	}

	/**
	 * Verifies that update_terms() returns true and creates a new term when it
	 * does not exist.
	 */
	public function test_update_terms_creates_and_assigns_new_term(): void {
		// ARRANGE: Terms with a name that does not yet exist in the taxonomy.
		$term_name = 'Unique Test Category ' . uniqid();
		$terms     = array( 'category' => array( $term_name ) );

		// ACT: Assign a term that must be created first.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		// ASSERT: Returns true and a new term is assigned.
		$this->assertTrue( $result );
		$assigned = wp_get_post_terms( $this->post_id, 'category', array( 'fields' => 'names' ) );
		$this->assertSame( array( $term_name ), $assigned );
	}

	/**
	 * Verifies that update_terms() returns a WP_Error when term insertion fails.
	 */
	public function test_update_terms_returns_error_when_term_insertion_fails(): void {
		// ARRANGE: Hook into pre_insert_term to simulate a DB failure.
		$filter = static function () {
			return new WP_Error( 'insert_term_failed', 'Simulated term insertion failure.' );
		};
		add_filter( 'pre_insert_term', $filter );

		$terms = array( 'category' => array( 'Brand New Term That Cannot Be Created' ) );

		// ACT: Attempt to create and assign a term while the filter blocks insertion.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		remove_filter( 'pre_insert_term', $filter );

		// ASSERT: Returns a WP_Error from the failed wp_insert_term() call.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insert_term_failed', $result->get_error_code() );
	}

	/**
	 * Verifies that update_terms() writes META_SOURCE_TERM_ID/URL on a newly
	 * created destination term when the input item carries source_term_id and
	 * the caller passes a non-empty source site URL.
	 */
	public function test_update_terms_writes_source_meta_on_created_term(): void {
		// ARRANGE: A brand-new term carried by a single-source-payload item.
		$term_name       = 'Brand New Cat ' . uniqid();
		$source_term_id  = 12345;
		$source_site_url = 'https://source.example.com';
		$terms           = array(
			'category' => array(
				array(
					'source_term_id' => $source_term_id,
					'name'           => $term_name,
				),
			),
		);

		// ACT: Run the update with the source URL in play.
		$result = $this->manager->update_terms(
			$this->post_id,
			$terms,
			$source_site_url
		);

		// ASSERT: Term created and paired source meta written.
		$this->assertTrue( $result );
		$dest_term_ids = wp_get_post_terms(
			$this->post_id,
			'category',
			array( 'fields' => 'ids' )
		);
		$this->assertIsArray( $dest_term_ids );
		$this->assertSame( 1, count( $dest_term_ids ) );
		$dest_term_id = (int) $dest_term_ids[0];
		$this->assertSame(
			(string) $source_term_id,
			(string) get_term_meta( $dest_term_id, Options::META_SOURCE_TERM_ID, true )
		);
		$this->assertSame(
			$source_site_url,
			get_term_meta( $dest_term_id, Options::META_SOURCE_TERM_URL, true )
		);
	}

	/**
	 * Verifies that update_terms() also writes META_SOURCE_TERM_ID/URL when an
	 * EXISTING term is matched by slug — the registry lookup later relies on
	 * this so nav-link blocks can remap to pre-existing destination terms.
	 */
	public function test_update_terms_writes_source_meta_on_matched_term(): void {
		// ARRANGE: A destination term that already exists (e.g. created by a
		// prior, non-Safe-Publish workflow).
		$existing_term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'slug'     => 'news',
				'name'     => 'News',
			)
		);
		$source_term_id   = 67890;
		$source_site_url  = 'https://source.example.com';
		$terms            = array(
			'category' => array(
				array(
					'source_term_id' => $source_term_id,
					'slug'           => 'news',
					'name'           => 'News',
				),
			),
		);

		// ACT: Assign terms, passing the source site URL.
		$result = $this->manager->update_terms(
			$this->post_id,
			$terms,
			$source_site_url
		);

		// ASSERT: Existing term assigned; source meta now present on it.
		$this->assertTrue( $result );
		$assigned = wp_get_post_terms(
			$this->post_id,
			'category',
			array( 'fields' => 'ids' )
		);
		$this->assertSame( array( $existing_term_id ), $assigned );
		$this->assertSame(
			(string) $source_term_id,
			(string) get_term_meta( $existing_term_id, Options::META_SOURCE_TERM_ID, true )
		);
		$this->assertSame(
			$source_site_url,
			get_term_meta( $existing_term_id, Options::META_SOURCE_TERM_URL, true )
		);
	}

	/**
	 * Verifies that update_terms() does NOT write source meta when the caller
	 * passes an empty source_site_url (e.g. single-import paths with no
	 * source-link context).
	 */
	public function test_update_terms_skips_source_meta_when_no_source_url(): void {
		// ARRANGE: A category term carrying a source_term_id.
		$term_name = 'Skip-meta Cat ' . uniqid();
		$terms     = array(
			'category' => array(
				array(
					'source_term_id' => 555,
					'name'           => $term_name,
				),
			),
		);

		// ACT: No source URL.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		// ASSERT: Term created, no source meta written.
		$this->assertTrue( $result );
		$ids = wp_get_post_terms( $this->post_id, 'category', array( 'fields' => 'ids' ) );
		$this->assertIsArray( $ids );
		$this->assertSame( 1, count( $ids ) );
		$this->assertSame(
			'',
			(string) get_term_meta( (int) $ids[0], Options::META_SOURCE_TERM_ID, true )
		);
	}

	/**
	 * Verifies that update_terms() stops at the first unknown taxonomy even
	 * when multiple are supplied.
	 */
	public function test_update_terms_stops_at_first_unknown_taxonomy(): void {
		// ARRANGE: Mix of valid and invalid taxonomies; invalid one comes first.
		$terms = array(
			'bad_taxonomy_xyz' => array( 'Term A' ),
			'category'         => array( 'Brand New Category Term' ),
		);

		// ACT: Call with mixed taxonomies.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		// ASSERT: Fails on the unknown taxonomy; the new category term was not created.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_taxonomy', $result->get_error_code() );
		$assigned_names = wp_get_post_terms( $this->post_id, 'category', array( 'fields' => 'names' ) );
		$this->assertNotContains( 'Brand New Category Term', $assigned_names );
	}

	/**
	 * Verifies that update_terms() reuses an existing non-hierarchical term
	 * matched by name when the item's slug has drifted, instead of creating a
	 * duplicate, and writes source meta on the reused term.
	 */
	public function test_update_terms_reuses_name_matched_tag_on_slug_drift(): void {
		// ARRANGE: An existing tag whose slug differs from the imported item.
		$existing_id     = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'News',
				'slug'     => 'news',
			)
		);
		$source_term_id  = 4242;
		$source_site_url = 'https://source.example.com';
		$terms           = array(
			'post_tag' => array(
				array(
					'slug'           => 'news-old',
					'name'           => 'News',
					'source_term_id' => $source_term_id,
				),
			),
		);

		// ACT: Import the item whose slug no longer matches the destination.
		$result = $this->manager->update_terms(
			$this->post_id,
			$terms,
			$source_site_url
		);

		// ASSERT: The existing tag is reused, no duplicate exists, meta written.
		$this->assertTrue( $result );
		$assigned = wp_get_post_terms(
			$this->post_id,
			'post_tag',
			array( 'fields' => 'ids' )
		);
		$this->assertSame( array( $existing_id ), $assigned );
		$named = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'name'       => 'News',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertSame( array( $existing_id ), $named );
		$this->assertSame(
			(string) $source_term_id,
			(string) get_term_meta( $existing_id, Options::META_SOURCE_TERM_ID, true )
		);
		$this->assertSame(
			$source_site_url,
			get_term_meta( $existing_id, Options::META_SOURCE_TERM_URL, true )
		);
	}

	/**
	 * Verifies that update_terms() reuses the first hierarchical term when a
	 * later item shares its name under a different slug (both flattened to
	 * parent 0), instead of creating a duplicate sibling.
	 */
	public function test_update_terms_reuses_name_matched_hierarchical_sibling(): void {
		// ARRANGE: Two items, same name, different slugs, no explicit parent.
		$name  = 'Shared Cat ' . uniqid();
		$terms = array(
			'category' => array(
				array(
					'name' => $name,
					'slug' => 'sibling-a',
				),
				array(
					'name' => $name,
					'slug' => 'sibling-b',
				),
			),
		);

		// ACT: Import both items in a single call.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		// ASSERT: Exactly one term exists and the post resolves to it.
		$this->assertTrue( $result );
		$matches = get_terms(
			array(
				'taxonomy'   => 'category',
				'name'       => $name,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertSame( 1, count( $matches ) );
		$assigned = wp_get_post_terms(
			$this->post_id,
			'category',
			array( 'fields' => 'ids' )
		);
		$this->assertSame( $matches, $assigned );
	}

	/**
	 * Verifies that update_terms() recovers the existing term ID from a
	 * term_exists error (e.g. a concurrent insert winning the race) instead of
	 * failing the whole import.
	 */
	public function test_update_terms_recovers_on_term_exists_error(): void {
		// ARRANGE: A term to recover, plus a filter that forces
		// wp_insert_term() to return term_exists carrying that term's ID.
		$existing_id = self::factory()->term->create(
			array( 'taxonomy' => 'category' )
		);
		$filter      = static function () use ( $existing_id ) {
			return new WP_Error(
				'term_exists',
				'A term with the name provided already exists in this taxonomy.',
				$existing_id
			);
		};
		add_filter( 'pre_insert_term', $filter );

		// A name that matches no term, so the lookups miss and the code
		// reaches wp_insert_term() where the filter forces the collision.
		$terms = array( 'category' => array( 'Racing Term ' . uniqid() ) );

		// ACT: Import while the forced collision is in play.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		remove_filter( 'pre_insert_term', $filter );

		// ASSERT: The existing term is reused; no error surfaced.
		$this->assertTrue( $result );
		$assigned = wp_get_post_terms(
			$this->post_id,
			'category',
			array( 'fields' => 'ids' )
		);
		$this->assertSame( array( $existing_id ), $assigned );
	}

	/**
	 * Verifies that update_terms() skips a caller-supplied term ID that does
	 * not resolve in the target taxonomy, assigning nothing and writing no
	 * source meta onto the foreign term.
	 */
	public function test_update_terms_skips_foreign_term_id(): void {
		// ARRANGE: A term that exists only in 'category', imported under
		// 'post_tag' with source meta that must not land on the foreign term.
		$category_id     = self::factory()->term->create(
			array( 'taxonomy' => 'category' )
		);
		$source_site_url = 'https://source.example.com';
		$terms           = array(
			'post_tag' => array(
				array(
					'term_id'        => $category_id,
					'source_term_id' => 4242,
				),
			),
		);

		// ACT: Attempt to assign the 'category' ID under 'post_tag'.
		$result = $this->manager->update_terms(
			$this->post_id,
			$terms,
			$source_site_url
		);

		// ASSERT: Nothing assigned, and no source meta written on the term.
		$this->assertTrue( $result );
		$assigned = wp_get_post_terms(
			$this->post_id,
			'post_tag',
			array( 'fields' => 'ids' )
		);
		$this->assertSame( array(), $assigned );
		$this->assertSame(
			'',
			(string) get_term_meta( $category_id, Options::META_SOURCE_TERM_ID, true )
		);
	}

	/**
	 * Verifies that update_terms() creates a hierarchical tree parent-first,
	 * wiring each term to its mapped destination parent, and attaches only the
	 * assigned leaf while its ancestors are created but not assigned.
	 */
	public function test_update_terms_builds_hierarchy_with_mapped_parents(): void {
		// ARRANGE: A three-level source tree, records deliberately out of
		// order; only the leaf is assigned.
		$suffix = uniqid();
		$terms  = array(
			'category' => array(
				array(
					'source_term_id' => 100,
					'name'           => "Root {$suffix}",
					'slug'           => "root-{$suffix}",
					'parent'         => 0,
					'assigned'       => false,
				),
				array(
					'source_term_id' => 102,
					'name'           => "Leaf {$suffix}",
					'slug'           => "leaf-{$suffix}",
					'parent'         => 101,
					'assigned'       => true,
				),
				array(
					'source_term_id' => 101,
					'name'           => "Child {$suffix}",
					'slug'           => "child-{$suffix}",
					'parent'         => 100,
					'assigned'       => false,
				),
			),
		);

		// ACT: Import the tree.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		// ASSERT: Every term created with the mapped destination parent.
		$this->assertTrue( $result );
		$root  = get_term_by( 'slug', "root-{$suffix}", 'category' );
		$child = get_term_by( 'slug', "child-{$suffix}", 'category' );
		$leaf  = get_term_by( 'slug', "leaf-{$suffix}", 'category' );
		$this->assertInstanceOf( WP_Term::class, $root );
		$this->assertInstanceOf( WP_Term::class, $child );
		$this->assertInstanceOf( WP_Term::class, $leaf );
		$this->assertSame( 0, (int) $root->parent );
		$this->assertSame( (int) $root->term_id, (int) $child->parent );
		$this->assertSame( (int) $child->term_id, (int) $leaf->parent );

		// ASSERT: Only the leaf is attached; ancestors are created but
		// unassigned.
		$assigned = wp_get_post_terms(
			$this->post_id,
			'category',
			array( 'fields' => 'ids' )
		);
		$this->assertSame( array( (int) $leaf->term_id ), $assigned );
	}

	/**
	 * Verifies that update_terms() sets the source description on a term it
	 * creates.
	 */
	public function test_update_terms_sets_description_on_create(): void {
		// ARRANGE: A new term carrying a description.
		$suffix      = uniqid();
		$description = 'A seeded category description.';
		$terms       = array(
			'category' => array(
				array(
					'name'        => "Described {$suffix}",
					'slug'        => "described-{$suffix}",
					'description' => $description,
				),
			),
		);

		// ACT: Import the term.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		// ASSERT: The created term carries the description.
		$this->assertTrue( $result );
		$term = get_term_by( 'slug', "described-{$suffix}", 'category' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( $description, $term->description );
	}

	/**
	 * Verifies that update_terms() strips unsafe markup from a term description
	 * a compromised source could inject, while keeping safe formatting.
	 *
	 * Drops core's pre_term_description kses filter first so the term is stored
	 * as it would be for an unfiltered_html importer on a web request — the
	 * case where wp_insert_term alone would keep the raw markup and only this
	 * importer's own sanitization stands between the source and stored XSS.
	 */
	public function test_update_terms_sanitizes_term_description_on_create(): void {
		// ARRANGE: A new term whose description carries a script payload, with
		// core's term-description kses removed to expose our sanitization.
		remove_filter( 'pre_term_description', 'wp_filter_kses' );
		$suffix = uniqid();
		$terms  = array(
			'category' => array(
				array(
					'name'        => "Xss {$suffix}",
					'slug'        => "xss-{$suffix}",
					'description' => 'Safe <strong>text</strong>'
						. '<script>alert(1)</script>',
				),
			),
		);

		// ACT: Import the term.
		$result = $this->manager->update_terms( $this->post_id, $terms );
		add_filter( 'pre_term_description', 'wp_filter_kses' );

		// ASSERT: The script is stripped while safe markup survives.
		$this->assertTrue( $result );
		$term = get_term_by( 'slug', "xss-{$suffix}", 'category' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertStringNotContainsString( '<script>', $term->description );
		$this->assertStringContainsString(
			'<strong>text</strong>',
			$term->description
		);
	}

	/**
	 * Verifies that update_terms() reuses a term by its source identity (source
	 * ID plus site URL) even when the record's slug and name have drifted,
	 * rather than creating a duplicate.
	 */
	public function test_update_terms_reuses_term_by_source_identity(): void {
		// ARRANGE: An existing destination term already tagged with a source
		// identity, and a re-import of that source term with a changed
		// slug/name.
		$source_site_url = 'https://source.example.com';
		$source_term_id  = 7788;
		$existing_id     = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Original Name',
				'slug'     => 'original-slug',
			)
		);
		update_term_meta( $existing_id, Options::META_SOURCE_TERM_ID, $source_term_id );
		update_term_meta( $existing_id, Options::META_SOURCE_TERM_URL, $source_site_url );

		$terms = array(
			'category' => array(
				array(
					'source_term_id' => $source_term_id,
					'name'           => 'Renamed On Source',
					'slug'           => 'renamed-on-source',
					'parent'         => 0,
					'assigned'       => true,
				),
			),
		);

		// ACT: Import the renamed record under the same source identity.
		$result = $this->manager->update_terms(
			$this->post_id,
			$terms,
			$source_site_url
		);

		// ASSERT: The existing term is reused; no term is created for the new
		// slug.
		$this->assertTrue( $result );
		$assigned = wp_get_post_terms(
			$this->post_id,
			'category',
			array( 'fields' => 'ids' )
		);
		$this->assertSame( array( $existing_id ), $assigned );
		$this->assertFalse(
			get_term_by( 'slug', 'renamed-on-source', 'category' )
		);
	}

	/**
	 * Verifies that update_terms() keeps two same-named terms under different
	 * parents distinct, the regression guard against merging legitimate
	 * siblings once hierarchy adds parents.
	 */
	public function test_update_terms_keeps_same_name_siblings_under_different_parents(): void {
		// ARRANGE: Two same-named leaves, each under a different parent.
		$suffix = uniqid();
		$name   = "News {$suffix}";
		$terms  = array(
			'category' => array(
				array(
					'source_term_id' => 10,
					'name'           => "Sports {$suffix}",
					'slug'           => "sports-{$suffix}",
					'parent'         => 0,
					'assigned'       => false,
				),
				array(
					'source_term_id' => 20,
					'name'           => "Tech {$suffix}",
					'slug'           => "tech-{$suffix}",
					'parent'         => 0,
					'assigned'       => false,
				),
				array(
					'source_term_id' => 11,
					'name'           => $name,
					'slug'           => "news-sports-{$suffix}",
					'parent'         => 10,
					'assigned'       => true,
				),
				array(
					'source_term_id' => 21,
					'name'           => $name,
					'slug'           => "news-tech-{$suffix}",
					'parent'         => 20,
					'assigned'       => true,
				),
			),
		);

		// ACT: Import both trees in one call.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		// ASSERT: Two distinct terms of the same name exist, one under each
		// parent.
		$this->assertTrue( $result );
		$news = get_terms(
			array(
				'taxonomy'   => 'category',
				'name'       => $name,
				'hide_empty' => false,
			)
		);
		$this->assertIsArray( $news );
		$this->assertSame( 2, count( $news ) );

		$sports         = get_term_by( 'slug', "sports-{$suffix}", 'category' );
		$tech           = get_term_by( 'slug', "tech-{$suffix}", 'category' );
		$actual_parents = array( (int) $news[0]->parent, (int) $news[1]->parent );
		sort( $actual_parents );
		$expected_parents = array( (int) $sports->term_id, (int) $tech->term_id );
		sort( $expected_parents );
		$this->assertSame( $expected_parents, $actual_parents );
	}

	/**
	 * Verifies that update_terms() reuses a pre-existing term matched by slug
	 * as-is, leaving its parent unchanged, so this create-only pass never
	 * re-parents a term an operator may have edited on the destination.
	 */
	public function test_update_terms_does_not_reparent_existing_term(): void {
		// ARRANGE: An existing top-level term, and a source tree that would
		// place that same term under a new parent.
		$suffix      = uniqid();
		$existing_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => "Existing {$suffix}",
				'slug'     => "existing-{$suffix}",
			)
		);
		$terms       = array(
			'category' => array(
				array(
					'source_term_id' => 30,
					'name'           => "New Parent {$suffix}",
					'slug'           => "new-parent-{$suffix}",
					'parent'         => 0,
					'assigned'       => false,
				),
				array(
					'source_term_id' => 31,
					'name'           => "Existing {$suffix}",
					'slug'           => "existing-{$suffix}",
					'parent'         => 30,
					'assigned'       => true,
				),
			),
		);

		// ACT: Import the tree.
		$result = $this->manager->update_terms( $this->post_id, $terms );

		// ASSERT: The existing term is reused unchanged, still top-level.
		$this->assertTrue( $result );
		$term = get_term( $existing_id, 'category' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 0, (int) $term->parent );
		$assigned = wp_get_post_terms(
			$this->post_id,
			'category',
			array( 'fields' => 'ids' )
		);
		$this->assertSame( array( $existing_id ), $assigned );
	}

	/**
	 * Verifies that update_meta() returns true when no meta is provided.
	 */
	public function test_update_meta_returns_true_for_empty_input(): void {
		// ARRANGE: Empty meta input.
		$meta = array();

		// ACT: Call with empty meta.
		$result = $this->manager->update_meta( $this->post_id, $meta );

		// ASSERT: Returns true without error.
		$this->assertTrue( $result );
	}

	/**
	 * Verifies that update_meta() returns true and writes all provided keys.
	 */
	public function test_update_meta_returns_true_and_writes_keys(): void {
		// ARRANGE: Two distinct meta key-value pairs.
		$meta = array(
			'source_color' => 'blue',
			'source_count' => '42',
		);

		// ACT: Write the meta to the post.
		$result = $this->manager->update_meta( $this->post_id, $meta );

		// ASSERT: Returns true and both keys are stored correctly.
		$this->assertTrue( $result );
		$this->assertSame( 'blue', get_post_meta( $this->post_id, 'source_color', true ) );
		$this->assertSame( '42', get_post_meta( $this->post_id, 'source_count', true ) );
	}

	/**
	 * Verifies that update_meta() returns a WP_Error when a meta write fails.
	 */
	public function test_update_meta_returns_error_when_write_fails(): void {
		// ARRANGE: Intercept update_post_metadata to simulate a DB write failure.
		// The filter returns false, causing update_post_meta() to return false
		// without writing the value, so the read-back check confirms real failure.
		$filter = static function () {
			return false;
		};
		add_filter( 'update_post_metadata', $filter );

		$meta = array( 'failing_key' => 'some_value' );

		// ACT: Attempt to write meta while the filter blocks all writes.
		$result = $this->manager->update_meta( $this->post_id, $meta );

		remove_filter( 'update_post_metadata', $filter );

		// ASSERT: Returns a WP_Error listing the failing key.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'meta_update_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'failing_key', $result->get_error_message() );
	}

	/**
	 * Verifies that update_meta() returns true when the stored value is
	 * already identical (no false positive on re-import of unchanged data).
	 */
	public function test_update_meta_returns_true_when_value_unchanged(): void {
		// ARRANGE: Pre-write a meta key, then prepare to write the same value.
		update_post_meta( $this->post_id, 'stable_key', 'same_value' );
		$meta = array( 'stable_key' => 'same_value' );

		// ACT: Call update_meta() with the identical value already stored.
		$result = $this->manager->update_meta( $this->post_id, $meta );

		// ASSERT: Returns true — update_post_meta() returning false for an
		// unchanged value must not be treated as a failure.
		$this->assertTrue( $result );
	}

	/**
	 * Verifies that update_meta() returns true on a re-import of unchanged
	 * scalar meta whose PHP type differs from WordPress' stored string form
	 * (e.g. Jetpack booleans/integers). A bool true reads back as "1", so a
	 * strict read-back comparison would wrongly flag the unchanged key.
	 */
	public function test_update_meta_returns_true_for_unchanged_scalar_types(): void {
		// ARRANGE: Import scalar-typed meta once so it is stored as strings.
		$meta = array(
			'_jetpack_dont_email_post_to_subs' => true,
			'jetpack_post_was_ever_published'  => false,
			'_jetpack_newsletter_tier_id'      => 0,
		);
		$this->assertTrue( $this->manager->update_meta( $this->post_id, $meta ) );

		// ACT: Re-import the identical scalar values, as an update would.
		$result = $this->manager->update_meta( $this->post_id, $meta );

		// ASSERT: No false failure on the unchanged bool/int values.
		$this->assertTrue( $result );
	}

	/**
	 * Verifies that update_meta() still reports a WP_Error when a write of a
	 * falsy value to a missing key genuinely fails, rather than mistaking the
	 * absent key (which reads back as "") for a successfully stored false.
	 */
	public function test_update_meta_reports_failed_write_of_missing_false_value(): void {
		// ARRANGE: Block all meta writes and target a missing key with a
		// false value — the failed write an existence check must catch.
		$filter = static function () {
			return false;
		};
		add_filter( 'update_post_metadata', $filter );

		$meta = array( 'never_written_flag' => false );

		// ACT: Attempt the blocked write.
		$result = $this->manager->update_meta( $this->post_id, $meta );

		remove_filter( 'update_post_metadata', $filter );

		// ASSERT: The genuine failure is reported, not swallowed.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'meta_update_failed', $result->get_error_code() );
		$this->assertStringContainsString(
			'never_written_flag',
			$result->get_error_message()
		);
	}

	/**
	 * Verifies that a run re-importing one term tree across several posts
	 * resolves it with a bounded number of termmeta queries, instead of an
	 * identity lookup plus a meta re-write per term per post.
	 */
	public function test_update_terms_batches_shared_term_lookups(): void {
		// ARRANGE: Five posts importing the same three-term hierarchy, already
		// created by a first pass so the measured run is a steady-state
		// re-import — the case the per-term N+1 hit hardest.
		$source_site_url = 'https://source.example.com';
		$suffix          = uniqid();
		$terms           = array(
			'category' => array(
				array(
					'source_term_id' => 8801,
					'name'           => "Root {$suffix}",
					'slug'           => "root-{$suffix}",
					'parent'         => 0,
					'assigned'       => true,
				),
				array(
					'source_term_id' => 8802,
					'name'           => "Child {$suffix}",
					'slug'           => "child-{$suffix}",
					'parent'         => 8801,
					'assigned'       => true,
				),
				array(
					'source_term_id' => 8803,
					'name'           => "Leaf {$suffix}",
					'slug'           => "leaf-{$suffix}",
					'parent'         => 8802,
					'assigned'       => true,
				),
			),
		);

		$post_ids = self::factory()->post->create_many( 5 );

		$warmup = new Meta_Terms_Manager();
		foreach ( $post_ids as $post_id ) {
			$warmup->update_terms( $post_id, $terms, $source_site_url );
		}

		$queries = 0;
		$counter = static function ( $query ) use ( &$queries ) {
			if ( false !== stripos( (string) $query, 'termmeta' ) ) {
				++$queries;
			}

			return $query;
		};
		add_filter( 'query', $counter );

		// ACT: Re-import the tree across every post through one manager, as a
		// bulk batch does.
		$manager = new Meta_Terms_Manager();
		foreach ( $post_ids as $post_id ) {
			$this->assertTrue(
				$manager->update_terms( $post_id, $terms, $source_site_url )
			);
		}

		remove_filter( 'query', $counter );

		// ASSERT: The per-term baseline is 3 termmeta queries x 3 terms x 5
		// posts = 45; batching the identity lookup and memoizing it for the
		// run brings the whole run down to 1, with headroom for a cold
		// termmeta cache.
		$this->assertLessThan( 5, $queries );

		// ASSERT: Every post resolved to the same three terms, so the memo
		// returned what the per-term lookup did.
		$expected = get_terms(
			array(
				'taxonomy'   => 'category',
				'slug'       => array(
					"root-{$suffix}",
					"child-{$suffix}",
					"leaf-{$suffix}",
				),
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$this->assertIsArray( $expected );
		$this->assertCount( 3, $expected );

		$expected_ids = array_map( 'intval', $expected );
		sort( $expected_ids );

		foreach ( $post_ids as $post_id ) {
			$assigned = wp_get_post_terms(
				$post_id,
				'category',
				array( 'fields' => 'ids' )
			);
			$this->assertIsArray( $assigned );

			$assigned_ids = array_map( 'intval', $assigned );
			sort( $assigned_ids );

			$this->assertSame( $expected_ids, $assigned_ids );
		}
	}

	/**
	 * Verifies that update_terms() re-resolves a source identity whose
	 * memoized term was deleted mid-run, rather than assigning a term ID that
	 * no longer exists.
	 */
	public function test_update_terms_reresolves_deleted_memoized_term(): void {
		// ARRANGE: A first import memoizes the source identity, then the term
		// it resolved to is deleted.
		$source_site_url = 'https://source.example.com';
		$suffix          = uniqid();
		$terms           = array(
			'category' => array(
				array(
					'source_term_id' => 9911,
					'name'           => "Memo {$suffix}",
					'slug'           => "memo-{$suffix}",
					'parent'         => 0,
					'assigned'       => true,
				),
			),
		);

		$this->assertTrue(
			$this->manager->update_terms(
				$this->post_id,
				$terms,
				$source_site_url
			)
		);

		$created = get_term_by( 'slug', "memo-{$suffix}", 'category' );
		$this->assertInstanceOf( WP_Term::class, $created );
		wp_delete_term( (int) $created->term_id, 'category' );

		// ACT: Re-import the same identity through the same manager, whose
		// memo still points at the deleted term.
		$second_post = self::factory()->post->create();
		$result      = $this->manager->update_terms(
			$second_post,
			$terms,
			$source_site_url
		);

		// ASSERT: A fresh term was created and assigned, not the stale ID.
		$this->assertTrue( $result );
		$recreated = get_term_by( 'slug', "memo-{$suffix}", 'category' );
		$this->assertInstanceOf( WP_Term::class, $recreated );
		$this->assertNotSame(
			(int) $created->term_id,
			(int) $recreated->term_id
		);
		$this->assertSame(
			array( (int) $recreated->term_id ),
			wp_get_post_terms(
				$second_post,
				'category',
				array( 'fields' => 'ids' )
			)
		);
	}

	/**
	 * Verifies that update_terms() clears a taxonomy the source sent as an
	 * empty list, the signal that its terms were removed on the source.
	 */
	public function test_update_terms_clears_taxonomy_sent_empty(): void {
		// ARRANGE: A post carrying a tag, as a prior import left it.
		wp_set_post_terms( $this->post_id, array( 'Stale Tag' ), 'post_tag' );
		$this->assertSame(
			array( 'Stale Tag' ),
			wp_get_post_terms(
				$this->post_id,
				'post_tag',
				array( 'fields' => 'names' )
			)
		);

		// ACT: Update terms with the taxonomy present but empty.
		$result = $this->manager->update_terms(
			$this->post_id,
			array( 'post_tag' => array() )
		);

		// ASSERT: The taxonomy is cleared.
		$this->assertTrue( $result );
		$this->assertSame(
			array(),
			wp_get_post_terms(
				$this->post_id,
				'post_tag',
				array( 'fields' => 'ids' )
			)
		);
	}

	/**
	 * Verifies that update_terms() leaves a taxonomy absent from the payload
	 * untouched, so clearing is confined to what the source actually sent.
	 */
	public function test_update_terms_keeps_terms_of_absent_taxonomy(): void {
		// ARRANGE: A post carrying a tag, with only category in the payload.
		wp_set_post_terms( $this->post_id, array( 'Kept Tag' ), 'post_tag' );
		$category_id = self::factory()->term->create(
			array( 'taxonomy' => 'category' )
		);

		// ACT: Update terms with a payload that omits post_tag entirely.
		$result = $this->manager->update_terms(
			$this->post_id,
			array( 'category' => array( array( 'term_id' => $category_id ) ) )
		);

		// ASSERT: The untouched taxonomy keeps its term.
		$this->assertTrue( $result );
		$this->assertSame(
			array( 'Kept Tag' ),
			wp_get_post_terms(
				$this->post_id,
				'post_tag',
				array( 'fields' => 'names' )
			)
		);
	}

	/**
	 * Verifies that update_terms() keeps existing terms when every item sent
	 * fails to resolve, so a resolution failure never reads as a clear.
	 */
	public function test_update_terms_keeps_terms_when_no_item_resolves(): void {
		// ARRANGE: A post carrying a tag, and items that cannot resolve: A
		// term ID from another taxonomy, and a record with no name or slug.
		wp_set_post_terms( $this->post_id, array( 'Kept Tag' ), 'post_tag' );
		$foreign_id = self::factory()->term->create(
			array( 'taxonomy' => 'category' )
		);

		// ACT: Update terms with those unresolvable items.
		$result = $this->manager->update_terms(
			$this->post_id,
			array(
				'post_tag' => array(
					array( 'term_id' => $foreign_id ),
					array( 'description' => 'No name or slug' ),
				),
			)
		);

		// ASSERT: Nothing resolved, and the existing term survives.
		$this->assertTrue( $result );
		$this->assertSame(
			array( 'Kept Tag' ),
			wp_get_post_terms(
				$this->post_id,
				'post_tag',
				array( 'fields' => 'names' )
			)
		);
	}
}
