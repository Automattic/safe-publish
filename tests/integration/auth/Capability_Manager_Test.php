<?php
/**
 * Integration tests for the tracking meta guard
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration\Auth;

use ReflectionClass;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Source_Identity_Lookup;
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

		// ASSERT: Only the admin passes; other and key-less checks still pass.
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
		$this->assertTrue(
			user_can( $contributor, 'edit_post_meta', $post_id )
		);
	}

	/**
	 * Returns source post ID key variants, whether lookups match them, and the
	 * queries two guard checks run.
	 *
	 * @return array<string, array{string, bool, int}> Key, match, and queries.
	 */
	public static function tracking_meta_key_variant_provider(): array {
		$key  = Options::META_SOURCE_POST_ID;
		$tail = substr( $key, 1 );

		return array(
			'exact'             => array( $key, true, 0 ),
			'uppercase'         => array( strtoupper( $key ), true, 0 ),
			'trailing space'    => array( "{$key} ", true, 0 ),
			'leading space'     => array( " {$key}", false, 0 ),
			'control character' => array(
				str_replace( '_source', "\x01_source", $key ),
				true,
				1,
			),
			'accented'          => array( "\u{15B}{$tail}", true, 1 ),
			'fullwidth'         => array( "\u{FF53}{$tail}", true, 1 ),
			'zero-width space'  => array( "\u{200B}{$key}", true, 1 ),
			'homoglyph'         => array( "\u{455}{$tail}", false, 1 ),
		);
	}

	/**
	 * Verifies that the guard denies exactly the key variants lookups match,
	 * querying the database once for each key PHP can't compare.
	 *
	 * @dataProvider tracking_meta_key_variant_provider
	 *
	 * @param string $meta_key Variant of the source post ID key.
	 * @param bool   $matched  Whether lookups match it.
	 * @param int    $queries  Queries two guard checks run for it.
	 */
	public function test_tracking_meta_guard_matches_keys_like_lookups(
		string $meta_key,
		bool $matched,
		int $queries
	): void {
		global $wpdb;

		// ARRANGE: A contributor's own post claiming a source post under the
		// variant key; checking another key warms the caches.
		$contributor = self::factory()->user->create(
			array( 'role' => 'contributor' )
		);
		$post_id     = self::factory()->post->create(
			array(
				'post_author' => $contributor,
				'post_status' => 'pending',
			)
		);
		add_post_meta( $post_id, $meta_key, '555' );
		add_post_meta(
			$post_id,
			Options::META_SOURCE_SITE_URL,
			'https://example.com'
		);
		user_can( $contributor, 'edit_post_meta', $post_id, 'other_key' );

		// ACT: Look the claim up, then check the key twice.
		$found   = Source_Identity_Lookup::find( 555, 'https://example.com' );
		$before  = $wpdb->num_queries;
		$allowed = array(
			user_can( $contributor, 'edit_post_meta', $post_id, $meta_key ),
			user_can( $contributor, 'edit_post_meta', $post_id, $meta_key ),
		);

		// ASSERT: The guard denies the keys lookups match, both times.
		$this->assertSame(
			array(
				'matched' => $matched,
				'denied'  => array( $matched, $matched ),
				'queries' => $queries,
			),
			array(
				'matched' => array() !== $found,
				'denied'  => array( ! $allowed[0], ! $allowed[1] ),
				'queries' => $wpdb->num_queries - $before,
			)
		);
	}

	/**
	 * Verifies that tracking keys are lowercase printable ASCII, which the
	 * guard's case-insensitive comparison in PHP requires.
	 */
	public function test_tracking_meta_keys_are_lowercase_printable_ascii(): void {
		// ACT & ASSERT: Lowercasing changes no key, and none holds other bytes.
		$this->assertSame(
			array_map( 'strtolower', Options::TRACKING_META_KEYS ),
			Options::TRACKING_META_KEYS
		);
		$this->assertSame(
			array(),
			preg_grep( '/[^\x20-\x7E]/', Options::TRACKING_META_KEYS )
		);
	}

	/**
	 * Verifies that the tracking keys are exactly the unprotected post meta
	 * keys Options defines, so a new key can't skip the guard.
	 */
	public function test_tracking_keys_cover_unprotected_meta_keys(): void {
		// ARRANGE: Options' unprotected meta keys, minus term meta keys and
		// the imported-from value.
		$excluded  = array(
			'META_SOURCE_TERM_ID',
			'META_SOURCE_TERM_URL',
			'META_TERM_ORIGIN_URL',
			'META_IMPORTED_FROM_VALUE',
		);
		$keys      = array();
		$constants = ( new ReflectionClass( Options::class ) )->getConstants();
		foreach ( $constants as $name => $value ) {
			if (
				str_starts_with( $name, 'META_' )
				&& ! in_array( $name, $excluded, true )
				&& is_string( $value )
				&& ! is_protected_meta( $value, 'post' )
			) {
				$keys[] = $value;
			}
		}
		$tracking = Options::TRACKING_META_KEYS;
		sort( $keys );
		sort( $tracking );

		// ACT & ASSERT: The tracking keys match them.
		$this->assertSame( $keys, $tracking );
	}

	/**
	 * Returns meta_key column collations, a source post ID key variant, and
	 * whether lookups on that column match it.
	 *
	 * @return array<string, array{string, string, bool}> Collation, key, and match.
	 */
	public static function meta_key_collation_provider(): array {
		$key  = Options::META_SOURCE_POST_ID;
		$tail = substr( $key, 1 );

		return array(
			'unicode_ci ignoring U+180E'   => array(
				'utf8mb4_unicode_ci',
				str_replace( '_source', "\u{180E}_source", $key ),
				true,
			),
			'general_ci folding sharp s'   => array(
				'utf8mb4_general_ci',
				"\u{DF}{$tail}",
				true,
			),
			'general_ci keeping fullwidth' => array(
				'utf8mb4_general_ci',
				str_replace( 'source', "\u{FF53}ource", $key ),
				false,
			),
			'latin1 folding accents'       => array(
				'latin1_swedish_ci',
				str_replace( 'source', "sourc\u{E9}", $key ),
				true,
			),
			'latin1 keeping u-umlaut'      => array(
				'latin1_swedish_ci',
				str_replace( 'source', "so\u{FC}rce", $key ),
				false,
			),
			'lithuanian_ci reading y as i' => array(
				'utf8mb4_lithuanian_ci',
				str_replace( 'publish', 'publysh', $key ),
				true,
			),
			'roman_ci reading v as u'      => array(
				'utf8mb4_roman_ci',
				str_replace( 'publish', 'pvblish', $key ),
				true,
			),
		);
	}

	/**
	 * Verifies that the guard matches keys under the meta_key column's
	 * collation when it differs from the connection's.
	 *
	 * @dataProvider meta_key_collation_provider
	 *
	 * @param string $collation Collation of the meta_key column.
	 * @param string $meta_key  Variant of the source post ID key.
	 * @param bool   $matched   Whether lookups match it.
	 */
	public function test_tracking_meta_guard_follows_meta_key_collation(
		string $collation,
		string $meta_key,
		bool $matched
	): void {
		global $wpdb;

		// ARRANGE: A contributor's own post claiming a source post under the
		// key, in a postmeta table using the collation; the default table's
		// verdict is cached first.
		$contributor = self::factory()->user->create(
			array( 'role' => 'contributor' )
		);
		$post_id     = self::factory()->post->create(
			array(
				'post_author' => $contributor,
				'post_status' => 'pending',
			)
		);
		$postmeta    = $wpdb->postmeta;
		$table       = "{$postmeta}_{$collation}";
		$charset     = strstr( $collation, '_', true );
		user_can( $contributor, 'edit_post_meta', $post_id, $meta_key );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TEMPORARY TABLE `{$table}` (
				meta_id bigint(20) unsigned NOT NULL auto_increment,
				post_id bigint(20) unsigned NOT NULL default '0',
				meta_key varchar(255) default NULL,
				meta_value longtext,
				PRIMARY KEY (meta_id),
				KEY post_id (post_id),
				KEY meta_key (meta_key(191))
			) DEFAULT CHARSET={$charset} COLLATE={$collation}"
		);
		$wpdb->postmeta = $table;

		try {
			add_post_meta( $post_id, $meta_key, '555' );
			add_post_meta(
				$post_id,
				Options::META_SOURCE_SITE_URL,
				'https://example.com'
			);

			// ACT: Look the claim up and check the key.
			$found   = Source_Identity_Lookup::find(
				555,
				'https://example.com'
			);
			$allowed = user_can(
				$contributor,
				'edit_post_meta',
				$post_id,
				$meta_key
			);
		} finally {
			$wpdb->postmeta = $postmeta;
			$wpdb->query( "DROP TEMPORARY TABLE `{$table}`" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		// ASSERT: The guard denies the keys lookups match.
		$this->assertSame(
			array(
				'matched' => $matched,
				'denied'  => $matched,
			),
			array(
				'matched' => array() !== $found,
				'denied'  => ! $allowed,
			)
		);
	}

	/**
	 * Verifies that the guard denies a key PHP can't compare when its query
	 * fails.
	 */
	public function test_tracking_meta_guard_denies_when_key_query_fails(): void {
		global $wpdb;

		// ARRANGE: A contributor's own post; an unrelated non-ASCII key passes.
		$contributor = self::factory()->user->create(
			array( 'role' => 'contributor' )
		);
		$post_id     = self::factory()->post->create(
			array(
				'post_author' => $contributor,
				'post_status' => 'pending',
			)
		);
		$before      = user_can(
			$contributor,
			'edit_post_meta',
			$post_id,
			"other_k\u{E9}y"
		);
		$break       = static fn( string $query ): string =>
			str_contains( $query, Options::META_MEDIA_TYPE )
				? 'SELECT FROM'
				: $query;
		$suppressed  = $wpdb->suppress_errors();

		try {
			// ACT: Check another such key while the guard's query fails.
			add_filter( 'query', $break );
			$after = user_can(
				$contributor,
				'edit_post_meta',
				$post_id,
				"another_k\u{E9}y"
			);
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppressed );
		}

		// ASSERT: The failure denies the edit.
		$this->assertTrue( $before );
		$this->assertFalse( $after );
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
