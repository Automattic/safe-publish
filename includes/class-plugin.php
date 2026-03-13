<?php
/**
 * Main Plugin class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish;

use Safe_Publish\Admin\Admin_Ajax_Controller;
use Safe_Publish\Admin\Receive_Mode_Admin_Handler;
use Safe_Publish\Admin\Admin_Menu_Manager;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\Send_Mode_Admin_Handler;
use Safe_Publish\Admin\History_Renderer;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Import_History;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\Admin\Session_Formatter;
use Safe_Publish\Admin\Session_Rollback_Service;
use Safe_Publish\Admin\Settings_Registrar;
use Safe_Publish\Auth\Auth_Manager;
use Safe_Publish\API\External_Posts_API;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Post_Type_Fetcher;
use Safe_Publish\API\Safe_Publish_API;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Embed_Processor;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;

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
	 * Constructs the Plugin instance.
	 */
	public function __construct() {
		// Initialize components lazily.
	}

	/**
	 * Initializes plugin.
	 */
	public function init(): void {
		$sync_direction = get_option( Options::OPTION_SYNC_DIRECTION, '' );
		$connected_url  = get_option( Options::OPTION_CONNECTED_SITE_URL, '' );

		$can_send = in_array(
			$sync_direction,
			array( Options::SYNC_DIRECTION_SEND, Options::SYNC_DIRECTION_BOTH ),
			true
		);

		if ( $can_send && ! empty( $connected_url ) ) {
			$auth_manager = new Auth_Manager();
			$auth_manager->init();
		}

		$can_receive = in_array(
			$sync_direction,
			array( Options::SYNC_DIRECTION_RECEIVE, Options::SYNC_DIRECTION_BOTH ),
			true
		);

		if ( $can_receive ) {
			$this->init_receive_mode();
		} else {
			$this->init_send_mode();
		}
	}

	/**
	 * Initializes receive mode.
	 */
	private function init_receive_mode(): void {
		// Build shared low-level services.
		$http_client             = new HTTP_Client();
		$media_importer          = new Media_Importer( $http_client );
		$embed_processor         = new Embed_Processor();
		$content_media_processor = new Content_Media_Processor( $media_importer, $embed_processor );
		$post_type_fetcher       = new Post_Type_Fetcher( $http_client );

		// Initialize External Posts API with shared HTTP client.
		$this->api = new External_Posts_API( $http_client );

		// Build content processor with direct media service dependencies.
		$content_processor = new Content_Processor( $media_importer, $content_media_processor );

		$this->safe_publish_api = new Safe_Publish_API( null, null, $content_processor, $media_importer );

		// Build admin object graph and initialize.
		$this->build_receive_mode_admin_handler(
			$this->api,
			$content_processor,
			$media_importer,
			$post_type_fetcher,
			$http_client
		)->init();
	}

	/**
	 * Builds and wires the Receive_Mode_Admin_Handler with all required
	 * sub-services for receive mode.
	 *
	 * @param External_Posts_API $api                External Posts API instance.
	 * @param Content_Processor  $content_processor  Content Processor instance.
	 * @param Media_Importer     $media_importer     Media Importer instance.
	 * @param Post_Type_Fetcher  $post_type_fetcher  Post Type Fetcher instance.
	 * @param HTTP_Client        $http_client        HTTP Client instance.
	 * @return Receive_Mode_Admin_Handler Fully constructed Receive_Mode_Admin_Handler coordinator.
	 */
	private function build_receive_mode_admin_handler(
		External_Posts_API $api,
		Content_Processor $content_processor,
		Media_Importer $media_importer,
		Post_Type_Fetcher $post_type_fetcher,
		HTTP_Client $http_client
	): Receive_Mode_Admin_Handler {
		$menu_manager       = new Admin_Menu_Manager( $api );
		$settings_registrar = new Settings_Registrar( true );

		$repository       = new History_Repository();
		$renderer         = new History_Renderer();
		$formatter        = new Session_Formatter();
		$rollback_service = new Session_Rollback_Service( $repository );

		$import_history = new Import_History(
			$repository,
			$renderer,
			$formatter,
			$rollback_service
		);

		$post_import_service = new Post_Import_Service(
			$api,
			$media_importer,
			$content_processor,
			$import_history
		);

		$ajax_controller = new Admin_Ajax_Controller(
			$api,
			$import_history,
			$content_processor,
			$post_import_service,
			new Meta_Terms_Manager(),
			$post_type_fetcher,
			$http_client
		);

		return new Receive_Mode_Admin_Handler(
			$menu_manager,
			$settings_registrar,
			$import_history,
			$ajax_controller
		);
	}

	/**
	 * Initializes send mode.
	 */
	private function init_send_mode(): void {
		( new Send_Mode_Admin_Handler() )->init();
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
