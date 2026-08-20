<?php
/**
 * Term plan integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Utils\Options;
use WP_Term;

/**
 * Covers the read-only plan of a term import: The destination terms it pairs
 * records with, the fields it reports as written or blocked, and its refusal
 * to write anything.
 */
class Term_Plan_Test extends Integration_Test_Case {

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

		$this->manager = new Meta_Terms_Manager();
		$this->post_id = self::factory()->post->create();

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
	 * Verifies that the plan pairs records with the same destination terms the
	 * import resolves them to, including the name match scoped to a parent the
	 * import creates on the way.
	 */
	public function test_plan_pairs_terms_the_way_the_import_does(): void {
		// ARRANGE: A root term and a child, both already imported, plus a term
		// the destination only knows by slug.
		$this->import_terms(
			array(
				$this->record( 100, 'Politics', 'politics', 0, '' ),
				$this->record( 101, 'News', 'news', 100, 'Desk' ),
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Sports',
				'slug'     => 'sports',
			)
		);

		$records = array(
			$this->record( 100, 'Politics', 'politics', 0, '' ),
			$this->record( 101, 'News', 'news', 100, 'New desk' ),
			$this->record( 102, 'Sports', 'sports', 0, 'Games' ),
		);

		// ACT: Plan the import, then run it.
		$planned = $this->plan( $records );
		$this->import_terms( $records );

		// ASSERT: Every record planned onto the term the import resolved.
		foreach ( $planned as $source_id => $term_id ) {
			$this->assertSame(
				$this->imported_term_id( (int) $source_id ),
				$term_id,
				sprintf( 'Source term %d should plan onto its import.', $source_id )
			);
		}
		$this->assertCount( 3, $planned );
	}

	/**
	 * Verifies that planning an import that would rename, re-describe, and
	 * re-parent a term writes nothing.
	 */
	public function test_plan_writes_nothing(): void {
		// ARRANGE: An imported term, with the write counter reset.
		$this->import_terms(
			array( $this->record( 101, 'News', 'news', 0, 'Desk' ) )
		);
		$this->term_writes = 0;

		// ACT: Plan an import that changes all three reconcilable fields.
		$this->plan(
			array(
				$this->record( 100, 'Politics', 'politics', 0, '', false ),
				$this->record( 101, 'Updates', 'news', 100, 'New desk' ),
			)
		);

		// ASSERT: The term is untouched and no term was created.
		$term = $this->term_by_slug( 'news' );
		$this->assertSame( 0, $this->term_writes );
		$this->assertSame( 'News', $term->name );
		$this->assertSame( 'Desk', $term->description );
		$this->assertSame( 0, (int) $term->parent );
		$this->assertFalse( get_term_by( 'slug', 'politics', 'category' ) );
	}

	/**
	 * Verifies that a parent the import would create counts as a resolvable
	 * move rather than a missing parent. Checking the destination alone would
	 * report every such child as blocked.
	 */
	public function test_pending_parent_is_not_reported_as_unresolved(): void {
		// ARRANGE: An imported root term.
		$this->import_terms(
			array( $this->record( 101, 'News', 'news', 0, '' ) )
		);

		// ACT: Plan a move under an ancestor the destination does not have yet.
		$plans = $this->manager->plan_terms(
			$this->source_terms(
				array(
					$this->record( 100, 'Politics', 'politics', 0, '', false ),
					$this->record( 101, 'News', 'news', 100, '' ),
				)
			),
			self::SOURCE
		);

		// ASSERT: The move is planned as a write, with nothing blocked.
		$child = $this->entry_for( $plans['category'], 101 );
		$this->assertContains( 'parent', $child['changes'] );
		$this->assertSame( array(), $child['blocked'] );
	}

	/**
	 * Verifies that a rename onto a name a sibling already holds is reported
	 * as blocked rather than as a pending write.
	 */
	public function test_plan_reports_a_blocked_rename(): void {
		// ARRANGE: An imported term and another term already holding the name
		// the source renames it to.
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

		// ACT: Plan the rename.
		$plans = $this->manager->plan_terms(
			$this->source_terms(
				array( $this->record( 101, 'Updates', 'news', 0, '' ) )
			),
			self::SOURCE
		);

		// ASSERT: The name is blocked, and the reason names the clash.
		$entry = $this->entry_for( $plans['category'], 101 );
		$this->assertNotContains( 'name', $entry['changes'] );
		$this->assertSame( 'name_taken', $entry['blocked']['name'] );
	}

	/**
	 * Verifies that a term without this source's origin marker is paired but
	 * reported as ineligible, so nothing is listed as a pending write.
	 */
	public function test_unmarked_and_foreign_terms_are_ineligible(): void {
		// ARRANGE: A hand-authored term and one imported from another source.
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'News',
				'slug'     => 'news',
			)
		);
		$this->import_terms(
			array( $this->record( 102, 'Sports', 'sports', 0, '' ) ),
			self::OTHER_SOURCE
		);

		// ACT: Plan an import that rewrites both descriptions.
		$plans = $this->manager->plan_terms(
			$this->source_terms(
				array(
					$this->record( 101, 'News', 'news', 0, 'Desk' ),
					$this->record( 102, 'Sports', 'sports', 0, 'Games' ),
				)
			),
			self::SOURCE
		);

		// ASSERT: Both paired, neither eligible, neither reporting a write.
		foreach ( array( 101, 102 ) as $source_id ) {
			$entry = $this->entry_for( $plans['category'], $source_id );
			$this->assertInstanceOf( WP_Term::class, $entry['term'] );
			$this->assertFalse( $entry['eligible'] );
			$this->assertSame( array(), $entry['changes'] );
		}
	}

	/**
	 * Verifies that a taxonomy the destination does not register is left out of
	 * the plan, since no record of it would resolve.
	 */
	public function test_plan_omits_an_unregistered_taxonomy(): void {
		// ARRANGE + ACT: Plan a taxonomy this site does not register.
		$plans = $this->manager->plan_terms(
			array(
				'nowhere_tax' => array(
					$this->record( 101, 'News', 'news', 0, '' ),
				),
			),
			self::SOURCE
		);

		// ASSERT: The taxonomy is absent from the plan.
		$this->assertSame( array(), $plans );
	}

	/**
	 * Verifies that a record naming a destination term plans as assigned as is,
	 * matching the import, which never reconciles a term the caller picked.
	 */
	public function test_caller_supplied_term_is_not_reconciled(): void {
		// ARRANGE: A term imported for this source, so the origin gate would
		// otherwise let it through.
		$this->import_terms(
			array( $this->record( 101, 'News', 'news', 0, 'Desk' ) )
		);
		$term_id = (int) $this->term_by_slug( 'news' )->term_id;

		// ACT: Plan the same term by destination ID.
		$plans = $this->manager->plan_terms(
			array( 'category' => array( $term_id ) ),
			self::SOURCE
		);

		// ASSERT: Paired, with nothing reported as a pending write.
		$entry = $plans['category'][0];
		$this->assertInstanceOf( WP_Term::class, $entry['term'] );
		$this->assertSame( $term_id, (int) $entry['term']->term_id );
		$this->assertFalse( $entry['eligible'] );
		$this->assertSame( array(), $entry['changes'] );
	}

	/**
	 * Verifies that a record with no destination match plans as a creation, so
	 * a caller can tell a new term from a reused one.
	 */
	public function test_unmatched_record_plans_as_a_creation(): void {
		// ARRANGE + ACT: Plan a term the destination does not have.
		$plans = $this->manager->plan_terms(
			$this->source_terms(
				array( $this->record( 101, 'News', 'news', 0, 'Desk' ) )
			),
			self::SOURCE
		);

		// ASSERT: No destination term paired.
		$entry = $this->entry_for( $plans['category'], 101 );
		$this->assertNull( $entry['term'] );
		$this->assertFalse( $entry['eligible'] );
	}

	/**
	 * Plans the records and maps each source term ID to the destination term
	 * it paired with, or 0 when it would be created.
	 *
	 * @param array $records Source term records.
	 * @return array<int, int> Source term ID mapped to destination term ID.
	 */
	private function plan( array $records ): array {
		$plans  = $this->manager->plan_terms(
			$this->source_terms( $records ),
			self::SOURCE
		);
		$paired = array();

		foreach ( $plans['category'] ?? array() as $entry ) {
			$paired[ (int) $entry['record']['source_term_id'] ] =
				$entry['term'] instanceof WP_Term ? (int) $entry['term']->term_id : 0;
		}

		return $paired;
	}

	/**
	 * Finds a plan entry by source term ID, failing the test when absent.
	 *
	 * @param array $entries        Plan entries for one taxonomy.
	 * @param int   $source_term_id Source term ID to find.
	 * @return array Matching plan entry.
	 */
	private function entry_for( array $entries, int $source_term_id ): array {
		foreach ( $entries as $entry ) {
			if ( $source_term_id === (int) $entry['record']['source_term_id'] ) {
				return $entry;
			}
		}

		$this->fail( sprintf( 'No plan entry for source term %d.', $source_term_id ) );
	}

	/**
	 * Runs one import of a taxonomy's records, failing the test when the terms
	 * could not be assigned.
	 *
	 * @param array  $records         Source term records.
	 * @param string $source_site_url Source the import runs for.
	 */
	private function import_terms(
		array $records,
		string $source_site_url = self::SOURCE
	): void {
		$result = $this->manager->update_terms(
			$this->post_id,
			$this->source_terms( $records ),
			$source_site_url
		);

		$this->assertIsArray( $result, 'Terms should import without erroring.' );
	}

	/**
	 * Reads the destination term an import resolved a source term ID to.
	 *
	 * @param int $source_term_id Source term ID.
	 * @return int Destination term ID, or 0 when none carries the identity.
	 */
	private function imported_term_id( int $source_term_id ): int {
		$matches = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'fields'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					array(
						'key'   => Options::META_SOURCE_TERM_ID,
						'value' => $source_term_id,
					),
					array(
						'key'   => Options::META_SOURCE_TERM_URL,
						'value' => self::SOURCE,
					),
				),
			)
		);

		return is_array( $matches ) && array() !== $matches ? (int) $matches[0] : 0;
	}

	/**
	 * Normalizes payload records through the production extractor, so tests
	 * feed the resolver exactly what a source response would.
	 *
	 * @param array  $records  Source term records.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<string, list<array<string, mixed>>> Terms keyed by taxonomy.
	 */
	private function source_terms(
		array $records,
		string $taxonomy = 'category'
	): array {
		$terms = Source_Posts_API::extract_source_terms(
			array( 'safe_publish_terms' => array( $taxonomy => $records ) )
		);

		$this->assertIsArray( $terms );

		return $terms;
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
