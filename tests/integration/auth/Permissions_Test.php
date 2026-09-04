<?php
/**
 * Integration tests for the Safe Publish permission contract.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

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
		parent::tearDown();
	}

	/**
	 * Verifies that the bootstrap admin notice uses the filtered capability.
	 */
	public function test_curl_notice_uses_filtered_capability(): void {
		// ARRANGE: A subscriber with the filtered capability but not the default.
		wp_set_current_user(
			self::factory()->user->create( array( 'role' => 'subscriber' ) )
		);
		wp_get_current_user()->add_cap( 'edit_posts' );
		ob_start();
		safe_publish_curl_required_notice();
		$default_output = ob_get_clean();
		add_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_edit_posts_capability' )
		);
		$this->assertFalse( current_user_can( 'manage_options' ) );

		// ACT: Render the bootstrap notice.
		ob_start();
		safe_publish_curl_required_notice();
		$output = ob_get_clean();

		// ASSERT: Filtering the capability changes access to the notice.
		$this->assertSame( '', $default_output );
		$this->assertIsString( $output );
		$this->assertStringContainsString(
			'Safe Publish requires the cURL PHP extension',
			$output
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
	 * Verifies that an invalid filtered capability uses the secure default.
	 */
	public function test_invalid_filtered_capability_uses_default(): void {
		// ARRANGE: A filter that violates the documented string contract.
		add_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_invalid_capability' )
		);

		// ACT: Resolve the management capability.
		$capability = Permissions::manage_capability();

		// ASSERT: Invalid values cannot replace the secure default.
		$this->assertSame( 'manage_options', $capability );
	}

	/**
	 * Verifies that an empty filtered capability uses the secure default.
	 */
	public function test_empty_filtered_capability_uses_default(): void {
		// ARRANGE: A filter returning an empty capability.
		add_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_empty_capability' )
		);

		// ACT: Resolve the management capability.
		$capability = Permissions::manage_capability();

		// ASSERT: Empty values cannot replace the secure default.
		$this->assertSame( 'manage_options', $capability );
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
}
