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

		// kses_init() latches the filter set when the current user is set, so
		// granting afterwards leaves the kses filters attached.
		if ( get_current_user_id() === $user_id ) {
			kses_init();
		}
	}

	/**
	 * Grants the acting user the ability to save unfiltered HTML.
	 */
	protected function grant_current_user_unfiltered_html(): void {
		$this->grant_unfiltered_html( get_current_user_id() );
	}
}
