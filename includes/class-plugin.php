<?php
/**
 * Main Plugin class
 */

namespace CCP;

use CCP\Admin\Admin_Handler;
use CCP\API\External_Posts_API;
use CCP\API\CCP_API;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Plugin Class
 */
class Plugin {

	/**
	 * External Posts API instance
	 *
	 * @var External_Posts_API
	 */
	private $api;

	/**
	 * CCP API instance
	 *
	 * @var CCP_API
	 */
	private $ccp_api;

	/**
	 * Admin handler instance
	 *
	 * @var Admin_Handler
	 */
	private $admin_handler;

	/**
	 * Admin handler instance
	 *
	 * @var Cache_Handler
	 */
	private $cache_handler;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Initialize components lazily
	}

	/**
	 * Initialize plugin
	 */
	public function init(): void {
		// Initialize components
		$this->api = new External_Posts_API();
		$this->ccp_api = new CCP_API();

		// Initialize hooks
		$this->init_hooks();

		// Initialize admin functionality in admin context (including AJAX)
		$this->admin_handler = new Admin_Handler( $this->api );
		$this->admin_handler->init();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks(): void {
		// Plugin hooks are initialized through the admin handler
	}

	/**
	 * Get API instance
	 *
	 */
	public function get_api(): External_Posts_API {
		return $this->api;
	}

	/**
	 * Get admin handler instance
	 *
	 */
	public function get_admin_handler(): ?Admin_Handler {
		return $this->admin_handler ?? null;
	}

	/**
	 * Get CCP API instance
	 *
	 */
	public function get_ccp_api(): ?CCP_API {
		return $this->ccp_api ?? null;
	}
}
