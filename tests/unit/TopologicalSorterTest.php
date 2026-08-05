<?php
/**
 * Topological_Sorter Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Utils\Topological_Sorter;

/**
 * Tests the Topological_Sorter utility class.
 */
class TopologicalSorterTest extends TestCase {

	/**
	 * Verifies that an empty map returns empty sorted and leftover lists.
	 */
	public function test_empty_input_returns_empty_lists(): void {
		// ARRANGE: Empty parent map.
		// ACT: Run the sort.
		$result = Topological_Sorter::sort( array() );

		// ASSERT: Both buckets are empty.
		$this->assertSame( array(), $result['sorted'] );
		$this->assertSame( array(), $result['leftover'] );
	}

	/**
	 * Verifies that a flat set of roots preserves input order.
	 */
	public function test_flat_roots_preserve_input_order(): void {
		// ARRANGE: Three top-level nodes.
		$map = array(
			3 => 0,
			1 => 0,
			2 => 0,
		);

		// ACT: Run the sort.
		$result = Topological_Sorter::sort( $map );

		// ASSERT: Order follows the input keys.
		$this->assertSame( array( 3, 1, 2 ), $result['sorted'] );
		$this->assertSame( array(), $result['leftover'] );
	}

	/**
	 * Verifies that a parent appears before its child when the child is listed
	 * first in the input.
	 */
	public function test_reverse_order_input_sorts_parent_before_child(): void {
		// ARRANGE: Child listed before its parent in the input.
		$map = array(
			20 => 10,
			10 => 0,
		);

		// ACT: Run the sort.
		$result = Topological_Sorter::sort( $map );

		// ASSERT: Parent comes first.
		$this->assertSame( array( 10, 20 ), $result['sorted'] );
		$this->assertSame( array(), $result['leftover'] );
	}

	/**
	 * Verifies that a deep chain is ordered from root to leaf.
	 */
	public function test_deep_chain_is_ordered_root_first(): void {
		// ARRANGE: A → B → C → D, requested in mixed order.
		$map = array(
			30 => 20,
			10 => 0,
			40 => 30,
			20 => 10,
		);

		// ACT: Run the sort.
		$result = Topological_Sorter::sort( $map );

		// ASSERT: Dependency order.
		$this->assertSame( array( 10, 20, 30, 40 ), $result['sorted'] );
		$this->assertSame( array(), $result['leftover'] );
	}

	/**
	 * Verifies that a wide tree puts the parent first and siblings after.
	 */
	public function test_wide_tree_orders_parent_then_siblings(): void {
		// ARRANGE: One parent with three children.
		$map = array(
			10 => 1,
			20 => 1,
			30 => 1,
			1  => 0,
		);

		// ACT: Run the sort.
		$result = Topological_Sorter::sort( $map );

		// ASSERT: Parent first, siblings follow input order.
		$this->assertSame( array( 1, 10, 20, 30 ), $result['sorted'] );
		$this->assertSame( array(), $result['leftover'] );
	}

	/**
	 * Verifies that an edge to a parent outside the input is ignored — the
	 * child is treated as a root for this batch.
	 */
	public function test_parent_outside_map_treated_as_root(): void {
		// ARRANGE: 50's parent (999) is not in the map.
		$map = array(
			50 => 999,
			60 => 0,
		);

		// ACT: Run the sort.
		$result = Topological_Sorter::sort( $map );

		// ASSERT: Both nodes ordered as roots in input order.
		$this->assertSame( array( 50, 60 ), $result['sorted'] );
		$this->assertSame( array(), $result['leftover'] );
	}

	/**
	 * Verifies that a two-node cycle leaves both members in leftover.
	 */
	public function test_two_node_cycle_routes_to_leftover(): void {
		// ARRANGE: A ↔ B cycle.
		$map = array(
			1 => 2,
			2 => 1,
		);

		// ACT: Run the sort.
		$result = Topological_Sorter::sort( $map );

		// ASSERT: Nothing ordered, both members leftover in input order.
		$this->assertSame( array(), $result['sorted'] );
		$this->assertSame( array( 1, 2 ), $result['leftover'] );
	}

	/**
	 * Verifies that nodes outside a cycle still sort while cycle members
	 * fall through to leftover.
	 */
	public function test_cycle_with_acyclic_neighbors_partitions_correctly(): void {
		// ARRANGE: 1 ↔ 2 cycle plus an independent root 99.
		$map = array(
			1  => 2,
			2  => 1,
			99 => 0,
		);

		// ACT: Run the sort.
		$result = Topological_Sorter::sort( $map );

		// ASSERT: Only the acyclic root sorts; cycle members are leftover.
		$this->assertSame( array( 99 ), $result['sorted'] );
		$this->assertSame( array( 1, 2 ), $result['leftover'] );
	}
}
