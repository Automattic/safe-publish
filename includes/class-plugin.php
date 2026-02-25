<?php
/**
 * Main Plugin class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish;

use Safe_Publish\Admin\Admin_Ajax_Controller;
use Safe_Publish\Admin\Admin_Handler;
use Safe_Publish\Admin\Admin_Menu_Manager;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Renderer;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Import_History;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\Admin\Session_Formatter;
use Safe_Publish\Admin\Session_Rollback_Service;
use Safe_Publish\Admin\Settings_Sanitizer;
use Safe_Publish\API\External_Posts_API;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Safe_Publish_API;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Plugin Class.
 */
final class Plugin {

	/**
	 * External Posts API instance.
	 *
	 * @var External_Posts_API|null
	 */
	private ?External_Posts_API $api = null;

	/**
	 * Safe Publish API instance.
	 *
	 * @var Safe_Publish_API|null
	 */
	private ?Safe_Publish_API $safe_publish_api = null;

	/**
	 * Admin handler instance.
	 *
	 * @var Admin_Handler|null
	 */
	private ?Admin_Handler $admin_handler = null;

	/**
	 * Constructs the Plugin instance.
	 */
	public function __construct() {
		// Initialize components lazily.
	}

	/**
	 * Initializes plugin.
	 */
	public function init(): void {
		// Initialize components.
		$this->api = new External_Posts_API();

		// Build content processor first so it can be shared with Safe_Publish_API.
		$content_processor = new Content_Processor( $this->api );

		$this->safe_publish_api = new Safe_Publish_API( null, null, $content_processor, $this->api );

		// Initialize hooks.
		$this->init_hooks();

		// Build admin object graph and initialize.
		$this->admin_handler = $this->build_admin_handler( $this->api, $content_processor );
		$this->admin_handler->init();
	}

	/**
	 * Initializes WordPress hooks.
	 */
	private function init_hooks(): void {
		// Plugin hooks are initialized through the admin handler.
	}

	/**
	 * Builds and wires the Admin_Handler with all required sub-services.
	 *
	 * Acts as the composition root for the admin subsystem, constructing
	 * each dependency in the correct order.
	 *
	 * @param External_Posts_API $api               External Posts API instance.
	 * @param Content_Processor  $content_processor Content Processor instance.
	 * @return Admin_Handler Fully constructed Admin_Handler coordinator.
	 */
	private function build_admin_handler(
		External_Posts_API $api,
		Content_Processor $content_processor
	): Admin_Handler {
		$menu_manager = new Admin_Menu_Manager( $api );

		$repository       = new History_Repository();
		$renderer         = new History_Renderer();
		$formatter        = new Session_Formatter();
		$rollback_service = new Session_Rollback_Service( $repository );
		$import_history   = new Import_History(
			$repository,
			$renderer,
			$formatter,
			$rollback_service
		);

		$post_import_service = new Post_Import_Service(
			$api,
			$content_processor,
			$import_history
		);

		$ajax_controller = new Admin_Ajax_Controller(
			$api,
			$import_history,
			$content_processor,
			$post_import_service,
			new Meta_Terms_Manager()
		);

		return new Admin_Handler(
			$menu_manager,
			new Settings_Sanitizer(),
			$import_history,
			$ajax_controller,
			$content_processor
		);
	}

	/**
	 * Gets Safe Publish API instance.
	 *
	 * @return ?Safe_Publish_API Safe Publish API instance or null.
	 */
	public function get_safe_publish_api(): ?Safe_Publish_API {
		return $this->safe_publish_api ?? null;
	}
}
