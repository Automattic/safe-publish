<?php
/**
 * Tests the destination's shape validation of the safe_publish_media field.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Source_Posts_API;

use Safe_Publish\API\Source_Posts_API;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Extract Source Media Test.
 *
 * HMAC authenticates the source's identity, not its honesty, so the
 * destination enforces the URL => fields shape of safe_publish_media before
 * threading it to the media importer. These tests pin that normalization.
 */
class Extract_Source_Media_Test extends WP_UnitTestCase {

	/**
	 * Invokes the private static extract_source_media() on a decoded body.
	 *
	 * @param array<string, mixed> $data Decoded REST response.
	 * @return array<string, array<string, string>> Extracted map.
	 */
	private function extract( array $data ): array {
		$method = new ReflectionMethod(
			Source_Posts_API::class,
			'extract_source_media'
		);

		return $method->invoke( null, $data );
	}

	/**
	 * Verifies that an absent or non-array field yields an empty map.
	 */
	public function test_absent_or_non_array_field_yields_empty_map(): void {
		// ARRANGE + ACT + ASSERT.
		$this->assertSame( array(), $this->extract( array() ) );
		$this->assertSame(
			array(),
			$this->extract( array( 'safe_publish_media' => 'not-an-array' ) )
		);
	}

	/**
	 * Verifies that entries with a non-string/empty key or a non-array value are
	 * dropped, while a well-formed entry survives with its fields cast to string.
	 */
	public function test_malformed_entries_are_dropped(): void {
		// ARRANGE: one valid entry alongside a non-array value and an empty key.
		$data = array(
			'safe_publish_media' => array(
				'https://source.example.com/a.jpg' => array(
					'alt'         => 'A',
					'title'       => 'T',
					'caption'     => 'C',
					'description' => 'D',
				),
				'https://source.example.com/b.jpg' => 'not-an-array',
				''                                 => array( 'alt' => 'x' ),
			),
		);

		// ACT.
		$map = $this->extract( $data );

		// ASSERT: only the well-formed entry remains.
		$this->assertSame(
			array(
				'https://source.example.com/a.jpg' => array(
					'alt'         => 'A',
					'title'       => 'T',
					'caption'     => 'C',
					'description' => 'D',
				),
			),
			$map
		);
	}

	/**
	 * Verifies that missing sub-fields coerce to empty strings so the importer
	 * always receives the full alt/title/caption/description shape.
	 */
	public function test_missing_subfields_coerce_to_empty_strings(): void {
		// ARRANGE + ACT.
		$map = $this->extract(
			array(
				'safe_publish_media' => array(
					'https://source.example.com/c.jpg' => array( 'title' => 'Only title' ),
				),
			)
		);

		// ASSERT.
		$this->assertSame(
			array(
				'https://source.example.com/c.jpg' => array(
					'alt'         => '',
					'title'       => 'Only title',
					'caption'     => '',
					'description' => '',
				),
			),
			$map
		);
	}
}
