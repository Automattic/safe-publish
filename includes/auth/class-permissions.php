<?php
/**
 * Safe Publish permission contract.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Auth;

/**
 * Resolves capabilities used by Safe Publish.
 *
 * Management operations and abilities must use manage_capability(). Per-post
 * reads keep their content-level checks: Safe_Publish_API requires the post
 * type's edit_posts capability only without the management capability, and
 * always requires edit_post for the mapped local post.
 */
final class Permissions {

	/**
	 * Returns the capability required for Safe Publish management operations.
	 *
	 * @return string Management capability.
	 */
	public static function manage_capability(): string {
		$default = 'manage_options';

		/**
		 * Filters the capability required for Safe Publish management operations.
		 *
		 * @param string $capability Management capability.
		 */
		$capability = apply_filters(
			'safe_publish_manage_capability',
			$default
		);

		// Use the secure default when a callback violates the documented contract.
		return is_string( $capability ) && '' !== $capability
			? $capability
			: $default;
	}
}
