<?php
/**
 * Shared AJAX harness for bulk-import integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use Safe_Publish\Utils\Options;

/**
 * Scaffolding for driving the safe_publish_bulk_import AJAX action: table, auth,
 * and user setup, the per-source-id mock body, and request dispatch. Host
 * classes pass the connection URL and may register source payloads via
 * $source_payloads. Combine with WP_Ajax_UnitTestCase, Ajax_Die_Continue_Trait,
 * and Per_Source_Id_Post_Api_Mock_Trait.
 */
trait Bulk_Import_Ajax_Trait {

	/**
	 * Source post payloads keyed by source ID, merged into the mock default.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $source_payloads = array();

	/**
	 * Admin user ID for the AJAX request.
	 *
	 * @var int
	 */
	protected int $admin_user_id;

	/**
	 * Sets up auth, tables, the admin user, the connection, and the mock.
	 *
	 * @param string $connection Connected source site URL for the batch.
	 */
	protected function set_up_bulk_import_harness( string $connection ): void {
		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define(
				'SAFE_PUBLISH_SHARED_SECRET',
				'integration-test-secret-key-32chars-ok'
			);
		}

		Imports_Table::create_table();
		Import_Items_Table::create_table();

		$this->admin_user_id = $this->factory()->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $this->admin_user_id );

		update_option( Options::OPTION_CONNECTED_SITE_URL, $connection );

		$this->add_per_source_id_post_api_mock();
	}

	/**
	 * Tears down the mock, connection, and registered payloads.
	 */
	protected function tear_down_bulk_import_harness(): void {
		$this->remove_per_source_id_post_api_mock();
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		$this->source_payloads = array();
	}

	/**
	 * Builds the per-source-id REST body, applying any registered override and
	 * basing the permalink on the connection so the body matches the batch.
	 *
	 * @param int    $source_id  Source post ID.
	 * @param string $connection Connected source site URL.
	 * @return array<string, mixed> Mock REST body.
	 */
	protected function bulk_mock_body( int $source_id, string $connection ): array {
		$override = $this->source_payloads[ $source_id ] ?? array();
		$admin    = get_userdata( $this->admin_user_id );

		return array(
			'id'                  => $source_id,
			'title'               => array(
				'raw' => $override['title'] ?? "Source Post {$source_id}",
			),
			'featured_media'      => 0,
			'content'             => array(
				'raw' => $override['content'] ?? '<p>Content.</p>',
			),
			'excerpt'             => array( 'raw' => '' ),
			'link'                => "{$connection}/post-{$source_id}",
			'slug'                => "post-{$source_id}",
			'type'                => $override['type'] ?? '',
			'comment_status'      => '',
			'ping_status'         => '',
			'menu_order'          => 0,
			'password'            => '',
			'parent'              => $override['parent'] ?? 0,
			'meta'                => array(),
			'safe_publish_author' => array(
				'email'        => false !== $admin ? (string) $admin->user_email : '',
				'login'        => false !== $admin ? (string) $admin->user_login : '',
				'display_name' => false !== $admin ? (string) $admin->display_name : '',
			),
		);
	}

	/**
	 * Dispatches the bulk-import AJAX action and returns the decoded data.
	 *
	 * @param array $posts_data Request payload sent as posts_data JSON.
	 * @return array Decoded response data.
	 */
	protected function dispatch_bulk_import( array $posts_data ): array {
		$_POST = array(
			'nonce'      => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'posts_data' => wp_json_encode( $posts_data ),
		);

		$this->dispatch_ajax_expecting_die( 'safe_publish_bulk_import' );

		$decoded = json_decode( $this->_last_response, true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded['success'] );

		return $decoded['data'];
	}
}
