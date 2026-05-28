<?php
/**
 * Admin Menu Manager class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages admin menu registration, page rendering, and asset enqueueing.
 */
class Admin_Menu_Manager {

	/**
	 * Registers WordPress hooks for admin menu and assets.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_menu', array( $this, 'add_imported_posts_submenu' ), 15 );
		add_action( 'admin_menu', array( $this, 'add_settings_submenu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Adds the main admin menu page and Source Posts submenu entry.
	 */
	public function add_admin_menu(): void {
		add_menu_page(
			__( 'Source Posts', 'safe-publish' ),
			__( 'Safe Publish', 'safe-publish' ),
			'manage_options',
			'safe-publish',
			array( $this, 'render_admin_page' ),
			'dashicons-migrate',
			99
		);

		// Explicit first submenu entry to override the auto-generated one.
		add_submenu_page(
			'safe-publish',
			__( 'Source Posts', 'safe-publish' ),
			__( 'Source Posts', 'safe-publish' ),
			'manage_options',
			'safe-publish',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Adds the Imported Posts submenu page.
	 *
	 * Registered at priority 15 so it lands between Source Posts (default
	 * priority) and Settings (priority 20), giving the menu order
	 * Source Posts · Imported Posts · Settings.
	 */
	public function add_imported_posts_submenu(): void {
		add_submenu_page(
			'safe-publish',
			__( 'Imported Posts', 'safe-publish' ),
			__( 'Imported Posts', 'safe-publish' ),
			'manage_options',
			'safe-publish-imported',
			array( $this, 'render_imported_posts_page' )
		);
	}

	/**
	 * Adds the Settings submenu page.
	 *
	 * Registered at a later priority so it appears after other submenu items.
	 */
	public function add_settings_submenu(): void {
		add_submenu_page(
			'safe-publish',
			__( 'Safe Publish Settings', 'safe-publish' ),
			__( 'Settings', 'safe-publish' ),
			'manage_options',
			'safe-publish-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Renders the main admin page.
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__(
					'You do not have sufficient permissions to access this page.',
					'safe-publish'
				)
			);
		}

		$admin_page = new Admin_Page();
		$admin_page->render();
	}

	/**
	 * Renders the Imported Posts admin page.
	 */
	public function render_imported_posts_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__(
					'You do not have sufficient permissions to access this page.',
					'safe-publish'
				)
			);
		}

		$imported_posts_page = new Imported_Posts_Page();
		$imported_posts_page->render();
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

		$settings_page = new Settings_Page();
		$settings_page->render();
	}

	/**
	 * Enqueues admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_safe-publish' === $hook_suffix ) {
			$admin_page = new Admin_Page();
			$admin_page->enqueue_assets();
			return;
		}

		if ( 'safe-publish_page_safe-publish-imported' === $hook_suffix ) {
			$imported_posts_page = new Imported_Posts_Page();
			$imported_posts_page->enqueue_assets();
			return;
		}
	}
}
