<?php
/**
 * Post Type Map Test.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Utils\Post_Type_Map;

/**
 * Tests the Post_Type_Map utility class.
 */
class PostTypeMapTest extends TestCase {

	/**
	 * Verifies that known singular slugs are converted to REST endpoints.
	 *
	 * @dataProvider slug_to_endpoint_provider
	 *
	 * @param string $slug     WordPress post type slug.
	 * @param string $expected Expected REST endpoint.
	 */
	public function test_to_rest_endpoint_from_slug(
		string $slug,
		string $expected
	): void {
		$this->assertSame(
			$expected,
			Post_Type_Map::to_rest_endpoint( $slug )
		);
	}

	/**
	 * Data provider for slug → endpoint conversions.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function slug_to_endpoint_provider(): array {
		return array(
			'post'          => array( 'post', 'posts' ),
			'page'          => array( 'page', 'pages' ),
			'attachment'    => array( 'attachment', 'attachments' ),
			'revision'      => array( 'revision', 'revisions' ),
			'nav_menu_item' => array( 'nav_menu_item', 'nav_menu_items' ),
			'media'         => array( 'media', 'media' ),
			'navigation'    => array( 'navigation', 'navigation' ),
		);
	}

	/**
	 * Verifies that known REST endpoints are returned as-is.
	 *
	 * @dataProvider endpoint_passthrough_provider
	 *
	 * @param string $endpoint REST endpoint.
	 */
	public function test_to_rest_endpoint_passes_through_endpoints(
		string $endpoint
	): void {
		$this->assertSame(
			$endpoint,
			Post_Type_Map::to_rest_endpoint( $endpoint )
		);
	}

	/**
	 * Data provider for endpoint pass-through.
	 *
	 * @return array<string, array{string}>
	 */
	public static function endpoint_passthrough_provider(): array {
		return array(
			'posts' => array( 'posts' ),
			'pages' => array( 'pages' ),
		);
	}

	/**
	 * Verifies that unknown types pass through unchanged.
	 */
	public function test_to_rest_endpoint_unknown_passes_through(): void {
		$this->assertSame(
			'custom_cpt',
			Post_Type_Map::to_rest_endpoint( 'custom_cpt' )
		);
	}

	/**
	 * Verifies that known REST endpoints are converted to WP slugs.
	 *
	 * @dataProvider endpoint_to_slug_provider
	 *
	 * @param string $endpoint REST endpoint.
	 * @param string $expected Expected WordPress slug.
	 */
	public function test_to_wp_slug_from_endpoint(
		string $endpoint,
		string $expected
	): void {
		$this->assertSame(
			$expected,
			Post_Type_Map::to_wp_slug( $endpoint )
		);
	}

	/**
	 * Data provider for endpoint → slug conversions.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function endpoint_to_slug_provider(): array {
		return array(
			'posts'          => array( 'posts', 'post' ),
			'pages'          => array( 'pages', 'page' ),
			'attachments'    => array( 'attachments', 'attachment' ),
			'revisions'      => array( 'revisions', 'revision' ),
			'nav_menu_items' => array( 'nav_menu_items', 'nav_menu_item' ),
			'media'          => array( 'media', 'media' ),
			'navigation'     => array( 'navigation', 'navigation' ),
		);
	}

	/**
	 * Verifies that unknown types pass through unchanged.
	 */
	public function test_to_wp_slug_unknown_passes_through(): void {
		$this->assertSame(
			'custom_cpt',
			Post_Type_Map::to_wp_slug( 'custom_cpt' )
		);
	}
}
