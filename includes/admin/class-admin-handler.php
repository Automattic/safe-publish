<?php
/**
 * Admin Handler class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates admin functionality by wiring together focused sub-services.
 *
 * Acts as the composition coordinator for admin menu, settings, import history,
 * AJAX handling, and content processing subsystems.
 */
final class Admin_Handler {

	/**
	 * Admin Menu Manager instance.
	 *
	 * @var Admin_Menu_Manager
	 */
	private Admin_Menu_Manager $menu_manager;

	/**
	 * Settings Sanitizer instance.
	 *
	 * @var Settings_Sanitizer
	 */
	private Settings_Sanitizer $settings_sanitizer;

	/**
	 * Import History instance.
	 *
	 * @var Import_History
	 */
	private Import_History $import_history;

	/**
	 * Admin AJAX Controller instance.
	 *
	 * @var Admin_Ajax_Controller
	 */
	private Admin_Ajax_Controller $ajax_controller;

	/**
	 * Constructs the Admin_Handler instance.
	 *
	 * @param Admin_Menu_Manager    $menu_manager       Admin Menu Manager instance.
	 * @param Settings_Sanitizer    $settings_sanitizer Settings Sanitizer instance.
	 * @param Import_History        $import_history     Import History instance.
	 * @param Admin_Ajax_Controller $ajax_controller    Admin AJAX Controller instance.
	 */
	public function __construct(
		Admin_Menu_Manager $menu_manager,
		Settings_Sanitizer $settings_sanitizer,
		Import_History $import_history,
		Admin_Ajax_Controller $ajax_controller
	) {
		$this->menu_manager       = $menu_manager;
		$this->settings_sanitizer = $settings_sanitizer;
		$this->import_history     = $import_history;
		$this->ajax_controller    = $ajax_controller;
	}

	/**
	 * Initializes admin functionality by registering all sub-service hooks.
	 */
	public function init(): void {
		$this->menu_manager->register();
		$this->settings_sanitizer->register();
		$this->import_history->init();
		$this->ajax_controller->register_handlers();
	}
}
