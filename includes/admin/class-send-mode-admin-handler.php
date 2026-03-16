<?php
/**
 * Send Mode Admin Handler class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstraps the WordPress admin area on sites that are not receiving content,
 * covering send-only and unconfigured sites.
 */
final class Send_Mode_Admin_Handler {

	/**
	 * Initializes the settings-only admin area.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		( new Settings_Registrar( false ) )->register();
	}

	/**
	 * Registers the Safe Publish top-level menu pointing to the settings page.
	 *
	 * Uses the 'safe-publish-settings' slug to match the slug used by
	 * Admin_Menu_Manager in receive mode, so that options.php's post-save
	 * redirect always lands on a registered page regardless of sync direction.
	 */
	public function add_admin_menu(): void {
		add_menu_page(
			__( 'Safe Publish', 'safe-publish' ),
			__( 'Safe Publish', 'safe-publish' ),
			'manage_options',
			'safe-publish-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-external',
			99
		);
	}

	/**
	 * Renders the settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__(
					'You do not have sufficient permissions to access this page.',
					'safe-publish'
				)
			);
		}

		( new Settings_Page() )->render();
	}
}
