<?php
/**
 * Tests the destination's shape validation of safe_publish_attached_media.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\API\Source_Posts_API;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Extract Source Attached Media Test.
 *
 * HMAC authenticates the source's identity, not its honesty, so the destination
 * rebuilds the bare gallery/playlist attached-media set from whitelisted integer
 * fields before threading it to the media importer. These tests pin that
 * normalization.
 */
class Extract_Source_Attached_Media_Test extends WP_UnitTestCase {

	/**
	 * Invokes the private static extract_source_attached_media() on a body.
	 *
	 * @param array<string, mixed> $data Decoded REST response.
	 * @return list<array{id: int, menu_order: int}> Extracted set.
	 */
	private function extract( array $data ): array {
		$method = new ReflectionMethod(
			Source_Posts_API::class,
			'extract_source_attached_media'
		);

		return $method->invoke( null, $data );
	}

	/**
	 * Verifies that an absent or non-array field yields an empty set.
	 */
	public function test_absent_or_non_array_field_yields_empty_set(): void {
		// ARRANGE + ACT + ASSERT: An absent or non-array field is empty.
		$this->assertSame( array(), $this->extract( array() ) );
		$this->assertSame(
			array(),
			$this->extract( array( 'safe_publish_attached_media' => 'not-an-array' ) )
		);
	}

	/**
	 * Verifies that well-formed entries survive with id and menu_order cast to
	 * int, preserving order.
	 */
	public function test_wellformed_entries_are_cast_to_int(): void {
		// ARRANGE: Two entries with string-numeric fields, in order.
		$data = array(
			'safe_publish_attached_media' => array(
				array(
					'id'         => '42',
					'menu_order' => '3',
				),
				array(
					'id'         => 7,
					'menu_order' => 0,
				),
			),
		);

		// ACT: Extract the set.
		$set = $this->extract( $data );

		// ASSERT: id/menu_order cast to int, order preserved.
		$this->assertSame(
			array(
				array(
					'id'         => 42,
					'menu_order' => 3,
				),
				array(
					'id'         => 7,
					'menu_order' => 0,
				),
			),
			$set
		);
	}

	/**
	 * Verifies that a non-array entry and an entry without a positive id are
	 * dropped, while a missing menu_order coerces to 0.
	 */
	public function test_malformed_entries_are_dropped(): void {
		// ARRANGE: A valid id-only entry, a non-array, and a zero-id entry.
		$data = array(
			'safe_publish_attached_media' => array(
				array( 'id' => 15 ),
				'not-an-array',
				array( 'id' => 0 ),
				array( 'menu_order' => 5 ),
			),
		);

		// ACT: Extract the set.
		$set = $this->extract( $data );

		// ASSERT: Only the positive-id entry survives, menu_order 0.
		$this->assertSame(
			array(
				array(
					'id'         => 15,
					'menu_order' => 0,
				),
			),
			$set
		);
	}
}
