<?php
/**
 * Main Plugin class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish;

use Safe_Publish\Admin\Admin_Handler;
use Safe_Publish\API\External_Posts_API;
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
	 * @var External_Posts_API
	 */
	private $api;

	/**
	 * Safe Publish API instance.
	 *
	 * @var Safe_Publish_API
	 */
	private $safe_publish_api;

	/**
	 * Admin handler instance.
	 *
	 * @var Admin_Handler
	 */
	private $admin_handler;

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
		$this->api              = new External_Posts_API();
		$this->safe_publish_api = new Safe_Publish_API();

		// Initialize hooks.
		$this->init_hooks();

		// Initialize admin functionality in admin context (including AJAX).
		$this->admin_handler = new Admin_Handler( $this->api );
		$this->admin_handler->init();
	}

	/**
	 * Initializes WordPress hooks.
	 */
	private function init_hooks(): void {
		// Plugin hooks are initialized through the admin handler.
	}

	/**
	 * Gets API instance.
	 *
	 * @return External_Posts_API API instance.
	 */
	public function get_api(): External_Posts_API {
		return $this->api;
	}

	/**
	 * Gets admin handler instance.
	 *
	 * @return ?Admin_Handler Admin handler instance or null.
	 */
	public function get_admin_handler(): ?Admin_Handler {
		return $this->admin_handler ?? null;
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
