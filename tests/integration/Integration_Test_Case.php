<?php
/**
 * Base test case for integration tests
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Tests\Integration;

use WP_UnitTestCase;

/**
 * Integration Test Case Class.
 *
 * Provides common setup for integration tests that use custom post types.
 */
abstract class Integration_Test_Case extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		// Register custom post types required for the tests.
		$this->register_post_types();
	}

	/**
	 * Registers custom post types for import tracking.
	 */
	private function register_post_types(): void {
		// Register import session post type.
		register_post_type(
			'sp_import_session',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'supports'        => array( 'title', 'custom-fields' ),
			)
		);

		// Register import log post type.
		register_post_type(
			'sp_import_log',
			array(
				'public'          => false,
				'capability_type' => 'post',
				'supports'        => array( 'title', 'content', 'custom-fields' ),
			)
		);
	}
}
