<?php
/**
 * Safe Publish capability installation and mapping
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Auth;

use Safe_Publish\Utils\Options;

/**
 * Installs Safe Publish capabilities, their implication rules, and the
 * tracking meta guard.
 */
final class Capability_Manager {

	/**
	 * Version of the role grants installed on each site.
	 */
	private const VERSION = '1';

	/**
	 * Per-site option recording the installed role-grant version.
	 */
	private const VERSION_OPTION = 'safe_publish_capabilities_version';

	/**
	 * Registers capability mapping and performs per-site upgrade migration.
	 */
	public static function init(): void {
		add_filter(
			'user_has_cap',
			array( self::class, 'map_capabilities' ),
			10,
			2
		);
		add_filter(
			'map_meta_cap',
			array( self::class, 'map_tracking_meta_capabilities' ),
			10,
			4
		);
		self::maybe_install();
	}

	/**
	 * Restricts tracking meta edits to management-capability holders, whatever
	 * the auth_post_meta filters allow; imports and the diff preview trust it.
	 *
	 * @param string[]   $caps    Primitive capabilities the check requires.
	 * @param string     $cap     Capability being checked.
	 * @param int|string $user_id User ID; map_meta_cap does not enforce int.
	 * @param array      $args    Post ID and meta key.
	 * @return string[] The given capabilities, plus do_not_allow when denied.
	 */
	public static function map_tracking_meta_capabilities(
		array $caps,
		string $cap,
		int|string $user_id,
		array $args
	): array {
		$meta_key = $args[1] ?? null;

		if (
			in_array(
				$cap,
				array( 'add_post_meta', 'edit_post_meta', 'delete_post_meta' ),
				true
			)
			&& is_string( $meta_key )
			&& ! user_can( (int) $user_id, Permissions::manage_capability() )
			&& self::is_tracking_meta_key( $meta_key )
		) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}

	/**
	 * Checks whether a meta key equals a tracking key under the meta_key
	 * column's collation, which lookups compare keys with.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool Whether lookups read the key as a tracking key.
	 */
	private static function is_tracking_meta_key( string $meta_key ): bool {
		global $wpdb;

		if ( in_array( $meta_key, Options::TRACKING_META_KEYS, true ) ) {
			return true;
		}

		$keys         = Options::TRACKING_META_KEYS;
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$key          = self::meta_key_placeholder( $wpdb->postmeta );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$match = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT {$key} IN ($placeholders)",
				$meta_key,
				...$keys
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// A failed query counts as a match, denying the edit.
		return '0' !== $match;
	}

	/**
	 * Returns a key placeholder compared under a postmeta table's meta_key
	 * collation, or under the connection's when that can't be read.
	 *
	 * @param string $table Postmeta table name.
	 * @return string SQL holding one %s placeholder.
	 */
	private static function meta_key_placeholder( string $table ): string {
		global $wpdb;
		static $placeholders = array();

		if ( isset( $placeholders[ $table ] ) ) {
			return $placeholders[ $table ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$column    = $wpdb->get_row(
			$wpdb->prepare(
				'SHOW FULL COLUMNS FROM %i WHERE Field = %s',
				$table,
				'meta_key'
			),
			ARRAY_A
		);
		$collation = (string) ( $column['Collation'] ?? '' );
		$valid     = preg_match( '/^([a-z0-9]+)_\w+$/', $collation, $match );

		$placeholders[ $table ] = 1 === $valid
			? "CONVERT( %s USING {$match[1]} ) COLLATE {$collation}"
			: '%s';

		return $placeholders[ $table ];
	}

	/**
	 * Grants the default role capabilities when their version is outdated.
	 */
	public static function maybe_install(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Grants both capabilities to the Administrator role on the current site.
	 */
	public static function install(): void {
		$administrator = get_role( 'administrator' );

		if ( null === $administrator ) {
			return;
		}

		$administrator->add_cap( Permissions::MANAGE_CAPABILITY );
		$administrator->add_cap( Permissions::VIEW_AUDIT_LOG_CAPABILITY );
		update_option( self::VERSION_OPTION, self::VERSION, false );

		$current_user = wp_get_current_user();
		if ( $current_user->exists() ) {
			$current_user->get_role_caps();
		}
	}

	/**
	 * Maps the default legacy grant and management-to-audit implication.
	 *
	 * A filtered management resolver remains authoritative, so manage_options
	 * implies the first-class capability only under the default contract.
	 *
	 * @param array    $allcaps User's complete primitive capability map.
	 * @param string[] $caps    Primitive capabilities being checked.
	 * @return array Filtered capability map.
	 */
	public static function map_capabilities( array $allcaps, array $caps ): array {
		if (
			! in_array( Permissions::MANAGE_CAPABILITY, $caps, true )
			&& ! in_array( Permissions::VIEW_AUDIT_LOG_CAPABILITY, $caps, true )
		) {
			return $allcaps;
		}

		$management_capability = Permissions::manage_capability();
		if (
			Permissions::MANAGE_CAPABILITY === $management_capability
			&& true === ( $allcaps['manage_options'] ?? false )
		) {
			$allcaps[ Permissions::MANAGE_CAPABILITY ] = true;
		}

		if ( true === ( $allcaps[ $management_capability ] ?? false ) ) {
			$allcaps[ Permissions::VIEW_AUDIT_LOG_CAPABILITY ] = true;
		}

		return $allcaps;
	}
}
