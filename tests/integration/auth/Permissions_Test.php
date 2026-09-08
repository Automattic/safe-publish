<?php
/**
 * Integration tests for the Safe Publish permission contract.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use Safe_Publish\Auth\Capability_Manager;
use Safe_Publish\Auth\Permissions;
use Safe_Publish\Utils\Options;
use WP_UnitTestCase;

/**
 * Permissions Test Class.
 */
class Permissions_Test extends WP_UnitTestCase {

	/**
	 * Removes capability filters between tests.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_invalid_capability' )
		);
		remove_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_empty_capability' )
		);
		remove_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_edit_posts_capability' )
		);
		remove_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_recursive_edit_posts_capability' )
		);
		parent::tearDown();
	}

	/**
	 * Verifies that the bootstrap admin notice uses the filtered capability.
	 */
	public function test_curl_notice_uses_filtered_capability(): void {
		// ARRANGE: A subscriber with the legacy default but not the filtered cap.
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'subscriber' ) )
		);
		wp_get_current_user()->add_cap( 'manage_options' );
		ob_start();
		safe_publish_curl_required_notice();
		$default_output = ob_get_clean();
		add_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_edit_posts_capability' )
		);

		// ACT: Render with the filtered gate, then grant that configured cap.
		ob_start();
		safe_publish_curl_required_notice();
		$denied_output = ob_get_clean();
		wp_get_current_user()->add_cap( 'edit_posts' );
		ob_start();
		safe_publish_curl_required_notice();
		$allowed_output = ob_get_clean();

		// ASSERT: The filter overrides, rather than expands, the default gate.
		$this->assertStringContainsString(
			'Safe Publish requires the cURL PHP extension',
			$default_output
		);
		$this->assertSame( '', $denied_output );
		$this->assertStringContainsString(
			'Safe Publish requires the cURL PHP extension',
			$allowed_output
		);
	}

	/**
	 * Verifies that the settings save operation uses the filtered capability.
	 */
	public function test_settings_save_uses_filtered_capability(): void {
		// ARRANGE: Change the Safe Publish management capability.
		add_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_edit_posts_capability' )
		);

		// ACT: Resolve the capability used by WordPress' options.php handler.
		$capability = apply_filters(
			// Core derives this dynamic hook from the registered option group.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'option_page_capability_' . Options::SETTINGS_GROUP,
			'manage_options'
		);

		// ASSERT: The static resolver remains callable as a WordPress callback.
		$this->assertSame( 'edit_posts', $capability );
	}

	/**
	 * Verifies that invalid filtered capabilities use the secure default.
	 */
	public function test_invalid_filtered_capability_uses_default(): void {
		foreach ( array( 'use_invalid_capability', 'use_empty_capability' ) as $callback ) {
			// ARRANGE: A filter that violates the documented contract.
			add_filter(
				'safe_publish_manage_capability',
				array( self::class, $callback )
			);

			// ACT: Resolve, then remove this case's filter.
			$capability = Permissions::manage_capability();
			remove_filter(
				'safe_publish_manage_capability',
				array( self::class, $callback )
			);

			// ASSERT: Invalid values cannot replace the secure default.
			$this->assertSame( Permissions::MANAGE_CAPABILITY, $capability );
		}
	}

	/**
	 * Verifies that upgrade migration grants both capabilities to administrators.
	 */
	public function test_upgrade_grants_administrator_capabilities(): void {
		// ARRANGE: Simulate an existing site from before capability versioning.
		$administrator = get_role( 'administrator' );
		$administrator->remove_cap( Permissions::MANAGE_CAPABILITY );
		$administrator->remove_cap( Permissions::VIEW_AUDIT_LOG_CAPABILITY );
		delete_option( 'safe_publish_capabilities_version' );
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->assertFalse( $administrator->has_cap( Permissions::MANAGE_CAPABILITY ) );
		$this->assertTrue( current_user_can( Permissions::MANAGE_CAPABILITY ) );

		// ACT: Run the per-site upgrade migration.
		Capability_Manager::maybe_install();

		// ASSERT: Existing administrators retain full Safe Publish access.
		$this->assertTrue( $administrator->has_cap( Permissions::MANAGE_CAPABILITY ) );
		$this->assertTrue(
			$administrator->has_cap( Permissions::VIEW_AUDIT_LOG_CAPABILITY )
		);
		$this->assertTrue( current_user_can( Permissions::MANAGE_CAPABILITY ) );
	}

	/**
	 * Verifies the default custom, legacy role, and direct-grant contract.
	 */
	public function test_default_management_contract(): void {
		// ARRANGE: Custom and legacy roles plus a direct legacy user grant.
		$roles = array(
			'safe_publish_manager'        => array(
				'read'                         => true,
				Permissions::MANAGE_CAPABILITY => true,
			),
			'safe_publish_legacy_manager' => array(
				'read'           => true,
				'manage_options' => true,
			),
		);
		foreach ( $roles as $slug => $capabilities ) {
			$this->assertNotNull( wp_roles()->add_role( $slug, $slug, $capabilities ) );
		}
		try {
			$users = array(
				array(
					self::factory()->user->create(
						array( 'role' => 'safe_publish_manager' )
					),
					false,
				),
				array(
					self::factory()->user->create(
						array( 'role' => 'safe_publish_legacy_manager' )
					),
					true,
				),
				array(
					self::factory()->user->create( array( 'role' => 'subscriber' ) ),
					true,
				),
			);
			( new \WP_User( $users[2][0] ) )->add_cap( 'manage_options' );

			// ACT: Resolve each supported default grant shape.
			foreach ( $users as [ $user_id, $has_manage_options ] ) {
				wp_set_current_user( $user_id );

				// ASSERT: Management implies audit, not broader administration.
				$this->assertTrue( current_user_can( Permissions::MANAGE_CAPABILITY ) );
				$this->assertTrue(
					current_user_can( Permissions::VIEW_AUDIT_LOG_CAPABILITY )
				);
				$this->assertSame( $has_manage_options, current_user_can( 'manage_options' ) );
			}
		} finally {
			foreach ( array_keys( $roles ) as $slug ) {
				remove_role( $slug );
			}
		}
	}

	/**
	 * Verifies that audit-only access does not imply management access.
	 */
	public function test_audit_log_capability_does_not_imply_management(): void {
		// ARRANGE: A subscriber granted only the audit capability.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( Permissions::VIEW_AUDIT_LOG_CAPABILITY );
		wp_set_current_user( $user_id );

		// ACT: Resolve both capability checks.
		$can_audit  = current_user_can( Permissions::VIEW_AUDIT_LOG_CAPABILITY );
		$can_manage = current_user_can( Permissions::manage_capability() );

		// ASSERT: The narrower grant stays read-only.
		$this->assertTrue( $can_audit );
		$this->assertFalse( $can_manage );
	}

	/**
	 * Verifies filtered management implies audit without capability recursion.
	 */
	public function test_filtered_management_implies_audit_without_recursion(): void {
		// ARRANGE: Legacy access and a resolver that checks an unrelated cap.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new \WP_User( $user_id );
		$user->add_cap( 'manage_options' );
		wp_set_current_user( $user_id );
		add_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_recursive_edit_posts_capability' )
		);

		// ACT: Check the configured management and implied audit capabilities.
		$legacy_can_manage = current_user_can( Permissions::manage_capability() );
		$legacy_can_audit  = current_user_can(
			Permissions::VIEW_AUDIT_LOG_CAPABILITY
		);
		wp_get_current_user()->add_cap( 'edit_posts' );
		$filtered_can_manage = current_user_can( Permissions::manage_capability() );
		$filtered_can_audit  = current_user_can(
			Permissions::VIEW_AUDIT_LOG_CAPABILITY
		);

		// ASSERT: The filter is authoritative and management still implies audit.
		$this->assertFalse( $legacy_can_manage );
		$this->assertFalse( $legacy_can_audit );
		$this->assertTrue( $filtered_can_manage );
		$this->assertTrue( $filtered_can_audit );
	}

	/**
	 * Returns an invalid capability value for validation coverage.
	 *
	 * @return string[] Invalid capability value.
	 */
	public static function use_invalid_capability(): array {
		return array( 'edit_posts' );
	}

	/**
	 * Returns an empty capability for validation coverage.
	 *
	 * @return string Empty capability.
	 */
	public static function use_empty_capability(): string {
		return '';
	}

	/**
	 * Returns the alternate management capability used by the notice test.
	 *
	 * @return string Alternate management capability.
	 */
	public static function use_edit_posts_capability(): string {
		return 'edit_posts';
	}

	/**
	 * Resolves management after checking an unrelated capability.
	 *
	 * @return string Alternate management capability.
	 */
	public static function use_recursive_edit_posts_capability(): string {
		current_user_can( 'read' );

		return 'edit_posts';
	}
}
