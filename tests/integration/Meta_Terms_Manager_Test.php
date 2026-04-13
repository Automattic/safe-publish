<?php
/**
 * Meta_Terms_Manager integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Meta_Terms_Manager;
use WP_Error;

/**
 * Integration tests for Meta_Terms_Manager.
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
}
