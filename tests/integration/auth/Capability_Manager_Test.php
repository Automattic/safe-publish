<?php
/**
 * Integration tests for the tracking meta guard
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use Safe_Publish\Utils\Options;
use WP_UnitTestCase;

/**
 * Tracking meta guard tests.
 */
class Capability_Manager_Test extends WP_UnitTestCase {

	/**
	 * Removes the test filters.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_edit_others_posts_capability' )
		);
		remove_filter(
			'auth_post_meta_' . Options::META_SOURCE_POST_ID,
			'__return_null'
		);
		unregister_post_meta( 'post', Options::META_SOURCE_LINK );
		parent::tearDown();
	}

	/**
	 * Returns the guarded keys.
	 *
	 * @return array<string, array{string}> Meta key per case.
	 */
	public static function tracking_meta_key_provider(): array {
		return array(
			'source post ID'    => array( Options::META_SOURCE_POST_ID ),
			'source link'       => array( Options::META_SOURCE_LINK ),
			'source site URL'   => array( Options::META_SOURCE_SITE_URL ),
			'imported from'     => array( Options::META_IMPORTED_FROM ),
			'original URL'      => array( Options::META_ORIGINAL_URL ),
			'featured media ID' => array( Options::META_FEATURED_MEDIA_ID ),
			'media type'        => array( Options::META_MEDIA_TYPE ),
		);
	}

	/**
	 * Verifies that only managers can add, edit, or delete tracking meta.
	 *
	 * @dataProvider tracking_meta_key_provider
	 *
	 * @param string $meta_key Tracking meta key.
	 */
	public function test_tracking_meta_edits_require_management_capability(
		string $meta_key
	): void {
		// ARRANGE: A contributor's own post; an admin is the current user, so
		// the guard must judge the checked user.
		$administrator = self::factory()->user->create(
			array( 'role' => 'administrator' )
		);
		$contributor   = self::factory()->user->create(
			array( 'role' => 'contributor' )
		);
		$post_id       = self::factory()->post->create(
			array(
				'post_author' => $contributor,
				'post_status' => 'pending',
			)
		);
		wp_set_current_user( $administrator );

		// ACT: Resolve each meta capability for both users.
		$caps      = array();
		$meta_caps = array(
			'add_post_meta',
			'edit_post_meta',
			'delete_post_meta',
		);
		foreach ( $meta_caps as $cap ) {
			$caps['contributor'][ $cap ]   =
				user_can( $contributor, $cap, $post_id, $meta_key );
			$caps['administrator'][ $cap ] =
				user_can( $administrator, $cap, $post_id, $meta_key );
		}

		// ASSERT: Only the admin passes; other meta stays editable.
		$this->assertSame(
			array(
				'contributor'   => array(
					'add_post_meta'    => false,
					'edit_post_meta'   => false,
					'delete_post_meta' => false,
				),
				'administrator' => array(
					'add_post_meta'    => true,
					'edit_post_meta'   => true,
					'delete_post_meta' => true,
				),
			),
			$caps
		);
		$this->assertTrue(
			user_can( $contributor, 'edit_post_meta', $post_id, 'other_key' )
		);
	}

	/**
	 * Verifies that the guard holds for a key registered for a post type.
	 */
	public function test_tracking_meta_guard_holds_for_key_registered_for_post_type(): void {
		// ARRANGE: Register the key for posts; its default auth allows edits.
		$administrator = self::factory()->user->create(
			array( 'role' => 'administrator' )
		);
		$contributor   = self::factory()->user->create(
			array( 'role' => 'contributor' )
		);
		$post_id       = self::factory()->post->create(
			array(
				'post_author' => $contributor,
				'post_status' => 'pending',
			)
		);
		register_post_meta(
			'post',
			Options::META_SOURCE_LINK,
			array( 'show_in_rest' => true )
		);

		// ACT: Resolve the edit capability for both users.
		$caps = array(
			'contributor'   => user_can(
				$contributor,
				'edit_post_meta',
				$post_id,
				Options::META_SOURCE_LINK
			),
			'administrator' => user_can(
				$administrator,
				'edit_post_meta',
				$post_id,
				Options::META_SOURCE_LINK
			),
		);

		// ASSERT: Only the admin passes.
		$this->assertSame(
			array(
				'contributor'   => false,
				'administrator' => true,
			),
			$caps
		);
	}

	/**
	 * Verifies that the guard denies a non-manager granted edit_post_meta.
	 */
	public function test_tracking_meta_guard_denies_granted_meta_capability(): void {
		// ARRANGE: An editor holding edit_post_meta as a primitive capability.
		$editor  = self::factory()->user->create_and_get(
			array( 'role' => 'editor' )
		);
		$post_id = self::factory()->post->create();
		$editor->add_cap( 'edit_post_meta' );

		// ACT & ASSERT: The editor is still denied.
		$this->assertFalse(
			user_can(
				$editor,
				'edit_post_meta',
				$post_id,
				Options::META_SOURCE_POST_ID
			)
		);
	}

	/**
	 * Verifies that the guard follows a filtered management capability.
	 */
	public function test_tracking_meta_guard_follows_filtered_management_capability(): void {
		// ARRANGE: An editor, denied under the default capability.
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create();
		$before  = user_can(
			$editor,
			'edit_post_meta',
			$post_id,
			Options::META_SOURCE_POST_ID
		);

		// ACT: Map management to a capability editors have.
		add_filter(
			'safe_publish_manage_capability',
			array( self::class, 'use_edit_others_posts_capability' )
		);

		// ASSERT: The editor is now allowed.
		$this->assertFalse( $before );
		$this->assertTrue(
			user_can(
				$editor,
				'edit_post_meta',
				$post_id,
				Options::META_SOURCE_POST_ID
			)
		);
	}

	/**
	 * Verifies that the guard keeps an earlier denial for managers.
	 */
	public function test_tracking_meta_guard_never_widens_access(): void {
		// ARRANGE: An administrator, allowed by default.
		$administrator = self::factory()->user->create(
			array( 'role' => 'administrator' )
		);
		$post_id       = self::factory()->post->create();
		$before        = user_can(
			$administrator,
			'edit_post_meta',
			$post_id,
			Options::META_SOURCE_POST_ID
		);

		// ACT: Deny earlier with a non-boolean, as a third party might.
		add_filter(
			'auth_post_meta_' . Options::META_SOURCE_POST_ID,
			'__return_null'
		);

		// ASSERT: The denial still applies.
		$this->assertTrue( $before );
		$this->assertFalse(
			user_can(
				$administrator,
				'edit_post_meta',
				$post_id,
				Options::META_SOURCE_POST_ID
			)
		);
	}

	/**
	 * Returns edit_others_posts as the management capability.
	 *
	 * @return string Management capability.
	 */
	public static function use_edit_others_posts_capability(): string {
		return 'edit_others_posts';
	}
}
