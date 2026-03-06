<?php
/**
 * Unit tests for the Permission Manager.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use Safe_Publish\Auth\Auth_Logger;
use Safe_Publish\Auth\Permission_Manager;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Permission Manager Test.
 *
 * Tests permission assignment for authenticated vs unauthenticated requests.
 */
class Permission_Manager_Test extends WP_UnitTestCase {

	/**
	 * Permission manager instance.
	 *
	 * @var Permission_Manager
	 */
	private Permission_Manager $permission_manager;

	/**
	 * Sets up each test.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->permission_manager = new Permission_Manager( new Auth_Logger() );
	}

	/**
	 * Verifies that an authenticated request gets the correct capabilities
	 * assigned.
	 */
	public function test_authenticated_request_gets_correct_capabilities(): void {
		// ARRANGE: Create a subscriber (no edit capabilities by default).
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );

		// ACT: Set up authenticated context.
		$this->permission_manager->setup_authenticated_context( $request );

		// ASSERT: Capability filter grants edit_posts.
		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertTrue( $this->permission_manager->is_authenticated() );
	}

	/**
	 * Verifies that an unauthenticated request has no special capabilities
	 * added.
	 */
	public function test_unauthenticated_request_has_no_special_capabilities(): void {
		// ARRANGE: Create a subscriber (no edit capabilities by default).
		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// ACT: No authentication context set up.

		// ASSERT: No elevated capabilities granted.
		$this->assertFalse( current_user_can( 'edit_posts' ) );
		$this->assertFalse( $this->permission_manager->is_authenticated() );
	}
}
