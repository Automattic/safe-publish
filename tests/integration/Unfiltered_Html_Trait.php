<?php
/**
 * Helper for tests acting as a user who can save unfiltered HTML
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use WP_User;

/**
 * Shared helper for tests that import or restore content kses would strip.
 *
 * Single-site administrators hold unfiltered_html, but on multisite core maps
 * the capability to do_not_allow for everyone except super admins, so adding
 * the capability to a user has no effect there.
 */
trait Unfiltered_Html_Trait {

	/**
	 * Grants a user the ability to save unfiltered HTML on either install type.
	 *
	 * @param int $user_id User to grant the capability to.
	 */
	protected function grant_unfiltered_html( int $user_id ): void {
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		} else {
			( new WP_User( $user_id ) )->add_cap( 'unfiltered_html' );
		}

		// Core latches its capability-dependent filters on set_current_user —
		// kses for content and a separate one rebuilding footnotes meta — so
		// granting afterwards leaves them attached until the action re-fires.
		if ( get_current_user_id() === $user_id ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Re-fires a core action rather than introducing a hook.
			do_action( 'set_current_user' );
		}
	}

	/**
	 * Grants the acting user the ability to save unfiltered HTML.
	 */
	protected function grant_current_user_unfiltered_html(): void {
		$this->grant_unfiltered_html( get_current_user_id() );
	}
}
