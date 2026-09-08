<?php
/**
 * Multisite integration tests for Safe Publish capability installation.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use Safe_Publish\Auth\Capability_Manager;
use Safe_Publish\Auth\Permissions;
use WP_UnitTestCase;

/**
 * Verifies multisite capability installation.
 *
 * @group ms-required
 */
class Capability_Manager_Multisite_Test extends WP_UnitTestCase {
	private const VERSION_OPTION = 'safe_publish_capabilities_version';

	/**
	 * Skips multisite-only cases in the normal single-site suite.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires WordPress multisite.' );
		}
	}

	/**
	 * Verifies that per-site upgrade leaves a disabled site untouched.
	 */
	public function test_site_upgrade_is_scoped_to_enabled_site(): void {
		// ARRANGE: Enabled and disabled sites have no installed grants.
		$enabled_site_id  = get_current_blog_id();
		$disabled_site_id = self::factory()->blog->create();
		$this->remove_site_grants( $enabled_site_id );
		$this->remove_site_grants( $disabled_site_id );
		// ACT: Run the first-request migration on the enabled site.
		Capability_Manager::maybe_install();
		// ASSERT: Only the enabled site's Administrator role changes.
		$this->assert_site_has_administrator_grants( $enabled_site_id );
		$this->assert_site_has_administrator_grants( $disabled_site_id, false );
	}

	/**
	 * Verifies legacy grants and super administrators retain subsite access.
	 */
	public function test_legacy_and_super_admin_access_on_subsite(): void {
		// ARRANGE: A second site, legacy grants, and a super administrator.
		$site_id        = self::factory()->blog->create();
		$role_user_id   = self::factory()->user->create();
		$direct_user_id = self::factory()->user->create();
		$super_user_id  = self::factory()->user->create();
		grant_super_admin( $super_user_id );
		try {
			switch_to_blog( $site_id );
			try {
				wp_roles()->add_role(
					'safe_publish_legacy_manager',
					'Safe Publish Legacy Manager',
					array(
						'read'           => true,
						'manage_options' => true,
					)
				);
				add_user_to_blog( $site_id, $role_user_id, 'safe_publish_legacy_manager' );
				( new \WP_User( $direct_user_id ) )->add_cap( 'manage_options' );
				// ACT: Resolve role, direct, and super-admin access on the subsite.
				foreach ( array( $role_user_id, $direct_user_id, $super_user_id ) as $user_id ) {
					wp_set_current_user( $user_id );
					// ASSERT: Each retains management and implied audit access.
					$this->assertTrue( current_user_can( Permissions::MANAGE_CAPABILITY ) );
					$this->assertTrue(
						current_user_can( Permissions::VIEW_AUDIT_LOG_CAPABILITY )
					);
				}
			} finally {
				remove_role( 'safe_publish_legacy_manager' );
				restore_current_blog();
			}
		} finally {
			revoke_super_admin( $super_user_id );
		}
	}

	/**
	 * Removes Safe Publish grants and their version marker from one site.
	 *
	 * @param int $site_id Site ID to reset.
	 */
	private function remove_site_grants( int $site_id ): void {
		switch_to_blog( $site_id );
		try {
			$administrator = get_role( 'administrator' );
			$administrator->remove_cap( Permissions::MANAGE_CAPABILITY );
			$administrator->remove_cap( Permissions::VIEW_AUDIT_LOG_CAPABILITY );
			delete_option( self::VERSION_OPTION );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Asserts that one site's Administrator role has both capabilities.
	 *
	 * @param int  $site_id  Site ID to inspect.
	 * @param bool $expected Whether the grants should exist.
	 */
	private function assert_site_has_administrator_grants(
		int $site_id,
		bool $expected = true
	): void {
		switch_to_blog( $site_id );
		try {
			$administrator = get_role( 'administrator' );
			foreach (
				array(
					Permissions::MANAGE_CAPABILITY,
					Permissions::VIEW_AUDIT_LOG_CAPABILITY,
				) as $capability
			) {
				$this->assertSame(
					$expected,
					(bool) ( $administrator->capabilities[ $capability ] ?? false )
				);
			}
			$this->assertSame(
				$expected ? '1' : false,
				get_option( self::VERSION_OPTION )
			);
		} finally {
			restore_current_blog();
		}
	}
}
