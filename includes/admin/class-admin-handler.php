<?php
/**
 * Admin Handler class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\API\External_Posts_API;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\Admin\Import_History;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\History_Renderer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Handler Class.
 */
final class Admin_Handler {

	/**
	 * External Posts API instance.
	 *
	 * @var External_Posts_API
	 */
	private $api;

	/**
	 * Import History instance.
	 *
	 * @var Import_History
	 */
	private $import_history;

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
	 * Content Processor instance.
	 *
	 * @var Content_Processor
	 */
	private Content_Processor $content_processor;

	/**
	 * Post Import Service instance.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $post_import_service;

	/**
	 * Admin AJAX Controller instance.
	 *
	 * @var Admin_Ajax_Controller
	 */
	private Admin_Ajax_Controller $ajax_controller;

	/**
	 * Constructs the Admin_Handler instance.
	 *
	 * @param External_Posts_API $api External Posts API instance.
	 */
	public function __construct( External_Posts_API $api ) {
		$this->api                = $api;
		$this->menu_manager       = new Admin_Menu_Manager( $api );
		$this->settings_sanitizer = new Settings_Sanitizer();
		$this->content_processor  = new Content_Processor( $api );

		$repository       = new History_Repository();
		$renderer         = new History_Renderer();
		$formatter        = new Session_Formatter();
		$rollback_service = new Session_Rollback_Service( $repository );

		$this->import_history = new Import_History(
			$repository,
			$renderer,
			$formatter,
			$rollback_service
		);

		$this->post_import_service = new Post_Import_Service(
			$api,
			$this->content_processor,
			$this->import_history
		);

		$this->ajax_controller = new Admin_Ajax_Controller(
			$api,
			$this->import_history,
			$this->content_processor,
			$this->post_import_service,
			new Meta_Terms_Manager()
		);
	}

	/**
	 * Initializes admin functionality.
	 */
	public function init(): void {
		$this->menu_manager->register();
		$this->settings_sanitizer->register();

		// Initialize import history.
		$this->import_history->init();

		$this->ajax_controller->register_handlers();
	}

	/**
	 * Gets the Content_Processor instance.
	 *
	 * @return Content_Processor Content processor instance.
	 */
	public function get_content_processor(): Content_Processor {
		return $this->content_processor;
	}
}
