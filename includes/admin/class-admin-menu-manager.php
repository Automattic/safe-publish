<?php
/**
 * Admin Menu Manager class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

use Safe_Publish\API\External_Posts_API;
use Safe_Publish\Admin\Post_Import_Service;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages admin menu registration, page rendering, and asset enqueueing.
 */
class Admin_Menu_Manager {

	/**
	 * External Posts API instance.
	 *
	 * @var External_Posts_API
	 */
	private External_Posts_API $api;

	/**
	 * Post Import Service instance.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $post_import_service;

	/**
	 * Constructs the Admin_Menu_Manager instance.
	 *
	 * @param External_Posts_API  $api                 External Posts API instance.
	 * @param Post_Import_Service $post_import_service Post Import Service instance.
	 */
	public function __construct(
		External_Posts_API $api,
		Post_Import_Service $post_import_service
	) {
		$this->api                 = $api;
		$this->post_import_service = $post_import_service;
	}

	/**
	 * Registers WordPress hooks for admin menu and assets.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_submenu' ), 20 );

		// Early VIP-specific asset preparation.
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ) {
			add_action(
				'admin_enqueue_scripts',
				array( $this, 'prepare_vip_dependencies' ),
				5
			);
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Adds the main admin menu page and Dashboard submenu entry.
	 */
	public function add_admin_menu(): void {
		add_menu_page(
			__( 'Safe Publish Dashboard', 'safe-publish' ),
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
			__( 'Safe Publish Dashboard', 'safe-publish' ),
			__( 'Dashboard', 'safe-publish' ),
			'manage_options',
			'safe-publish',
			array( $this, 'render_admin_page' )
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

		$admin_page = new Admin_Page( $this->api, $this->post_import_service );
		$admin_page->render();
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
	 * Prepares VIP-specific dependencies early.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function prepare_vip_dependencies( string $hook_suffix ): void {
		// Only on our specific admin page.
		if ( 'toplevel_page_safe-publish' !== $hook_suffix ) {
			return;
		}

		// Force registration of required WordPress core scripts in VIP environment.
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );

		// Try to register wp-dataviews if available.
		if ( ! wp_script_is( 'wp-dataviews', 'registered' ) ) {
			// Attempt to register wp-dataviews if the file exists.
			$dataviews_path = ABSPATH . WPINC . '/js/dist/dataviews.min.js';
			if ( file_exists( $dataviews_path ) ) {
				wp_register_script(
					'wp-dataviews',
					includes_url( 'js/dist/dataviews.min.js' ),
					array( 'wp-element', 'wp-components' ),
					get_bloginfo( 'version' ),
					true
				);
			}
		}
	}

	/**
	 * Enqueues admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		// Only enqueue on our main admin page (tools page).
		if ( 'toplevel_page_safe-publish' !== $hook_suffix ) {
			return;
		}

		$admin_page = new Admin_Page( $this->api, $this->post_import_service );
		$admin_page->enqueue_assets();
	}
}
