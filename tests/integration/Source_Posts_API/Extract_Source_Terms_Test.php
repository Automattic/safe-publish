<?php
/**
 * Tests the destination's shape validation of the safe_publish_terms field.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\API\Source_Posts_API;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Extract Source Terms Test.
 *
 * HMAC authenticates the source's identity, not its honesty, so the destination
 * normalizes the taxonomy => term-records shape of safe_publish_terms before
 * handing it to Meta_Terms_Manager. A null return signals the field is absent
 * so the caller falls back to the embedded wp:term payload.
 */
class Extract_Source_Terms_Test extends WP_UnitTestCase {

	/**
	 * Invokes the private static extract_source_terms() on a decoded body.
	 *
	 * @param array<string, mixed> $data Decoded REST response.
	 * @return array<string, list<array<string, mixed>>>|null Extracted map or null.
	 */
	private function extract( array $data ): ?array {
		$method = new ReflectionMethod(
			Source_Posts_API::class,
			'extract_source_terms'
		);

		return $method->invoke( null, $data );
	}

	/**
	 * Verifies that an absent field yields null so the caller falls back to the
	 * embedded wp:term payload, while a non-array field also yields null.
	 */
	public function test_absent_or_non_array_field_yields_null(): void {
		// ARRANGE + ACT + ASSERT: Absent/non-array both signal fallback.
		$this->assertNull( $this->extract( array() ) );
		$this->assertNull(
			$this->extract( array( 'safe_publish_terms' => 'not-an-array' ) )
		);
	}

	/**
	 * Verifies that a well-formed record is normalized: id and parent to source
	 * IDs, name tag-stripped and trimmed, slug sanitized, and assigned cast.
	 */
	public function test_well_formed_record_is_normalized(): void {
		// ARRANGE: One record with values that each exercise a normalizer.
		$data = array(
			'safe_publish_terms' => array(
				'category' => array(
					array(
						'id'          => '42',
						'name'        => '  <b>Sports</b>  ',
						'slug'        => 'Sports Slug',
						'parent'      => '7',
						'description' => 'A description.',
						'assigned'    => false,
					),
				),
			),
		);

		// ACT: Extract the terms map.
		$map = $this->extract( $data );

		// ASSERT: The record is normalized to source-side IDs and clean
		// strings.
		$this->assertSame(
			array(
				'category' => array(
					array(
						'source_term_id' => 42,
						'name'           => 'Sports',
						'slug'           => 'sports-slug',
						'parent'         => 7,
						'description'    => 'A description.',
						'assigned'       => false,
					),
				),
			),
			$map
		);
	}

	/**
	 * Verifies that assigned defaults to true when the record omits it, so a
	 * record without the flag is treated as directly assigned.
	 */
	public function test_assigned_defaults_to_true_when_absent(): void {
		// ARRANGE + ACT: Extract a record that omits the assigned flag.
		$map = $this->extract(
			array(
				'safe_publish_terms' => array(
					'post_tag' => array(
						array(
							'id'   => 5,
							'name' => 'Featured',
							'slug' => 'featured',
						),
					),
				),
			)
		);

		// ASSERT: The record comes back assigned, with its subfields defaulted.
		$this->assertSame(
			array(
				'post_tag' => array(
					array(
						'source_term_id' => 5,
						'name'           => 'Featured',
						'slug'           => 'featured',
						'parent'         => 0,
						'description'    => '',
						'assigned'       => true,
					),
				),
			),
			$map
		);
	}

	/**
	 * Verifies that malformed entries are dropped: A non-array taxonomy value,
	 * an empty taxonomy key, a non-array record, and a record with neither name
	 * nor slug, leaving only the well-formed record.
	 */
	public function test_malformed_entries_are_dropped(): void {
		// ARRANGE: One valid record among several malformed ones.
		$data = array(
			'safe_publish_terms' => array(
				'category' => array(
					array(
						'name' => 'Kept',
						'slug' => 'kept',
					),
					'not-an-array',
					array(
						'id'          => 9,
						'description' => 'No name or slug',
					),
				),
				''         => array( array( 'name' => 'Orphan' ) ),
				'post_tag' => 'not-an-array',
			),
		);

		// ACT: Extract the terms map.
		$map = $this->extract( $data );

		// ASSERT: Only the well-formed category record survives.
		$this->assertSame(
			array(
				'category' => array(
					array(
						'source_term_id' => 0,
						'name'           => 'Kept',
						'slug'           => 'kept',
						'parent'         => 0,
						'description'    => '',
						'assigned'       => true,
					),
				),
			),
			$map
		);
	}
}
