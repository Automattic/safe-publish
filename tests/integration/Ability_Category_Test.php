<?php
/**
 * Integration tests for the Safe Publish ability category.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use WP_Ability_Category;
use WP_UnitTestCase;

/**
 * Tests category registration through the plugin bootstrap.
 */
class Ability_Category_Test extends WP_UnitTestCase {

	/**
	 * Verifies that normal plugin loading registers the category in WordPress.
	 */
	public function test_category_is_registered(): void {
		// ARRANGE: Use the plugin loaded by the integration bootstrap.
		$slug = 'safe-publish';

		// ACT: Read the real WordPress category registry.
		$categories = wp_get_ability_categories();

		// ASSERT: The category has its intended slug and translated metadata.
		$this->assertArrayHasKey( $slug, $categories );
		$category = $categories[ $slug ];
		$this->assertInstanceOf( WP_Ability_Category::class, $category );
		$this->assertSame( $slug, $category->get_slug() );
		$this->assertSame(
			__( 'Safe Publish', 'safe-publish' ),
			$category->get_label()
		);
		$this->assertSame(
			__(
				'Abilities for transferring content between WordPress sites.',
				'safe-publish'
			),
			$category->get_description()
		);
	}
}
