<?php
/**
 * Telemetry bulk-import integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Admin_Ajax_Controller;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Post_Type_Fetcher;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Event_Queue;
use Safe_Publish\Utils\Telemetry_Events;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;

/**
 * Telemetry Bulk Import Test.
 *
 * Verifies that the bulk-import AJAX path records a
 * `bulk_import_completed` event with batch_size, successful, failed, and
 * has_failures derived from the in-handler accumulators.
 */
class Telemetry_Bulk_Import_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;
	use Per_Source_Id_Post_Api_Mock_Trait;

	/**
	 * Fallback shared secret used when no environment constant is defined.
	 */
	private const FALLBACK_SECRET = 'integration-test-secret-key-32chars-ok';

	/**
	 * Queue that captures every telemetry event emitted by the controller.
	 *
	 * @var Telemetry_Event_Queue
	 */
	private Telemetry_Event_Queue $queue;

	/**
	 * Source post payloads keyed by source ID for the per-source mock.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $source_payloads = array();

	/**
	 * Admin user ID set as the acting user.
	 *
	 * @var int
	 */
	private int $admin_user_id;

	/**
	 * Substitutes a controller with queued telemetry for the production
	 * bulk-import AJAX handler so the test can assert on emitted events.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		$this->admin_user_id = $this->factory()->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $this->admin_user_id );

		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			'https://source.example.com'
		);

		$this->add_per_source_id_post_api_mock();

		$this->queue = new Telemetry_Event_Queue();
		$telemetry   = new Telemetry_Service(
			'safe_publish_',
			array(),
			$this->queue
		);

		$http_client       = new HTTP_Client();
		$media_importer    = new Media_Importer( $http_client );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);
		$repository        = new History_Repository();
		$controller        = new Admin_Ajax_Controller(
			new Source_Posts_API( $http_client ),
			$repository,
			new Post_Import_Service(
				new Source_Posts_API( $http_client ),
				$media_importer,
				$content_processor,
				$repository,
				new Meta_Terms_Manager()
			),
			new Post_Type_Fetcher( $http_client ),
			$telemetry
		);

		remove_all_actions( 'wp_ajax_safe_publish_bulk_import' );
		add_action(
			'wp_ajax_safe_publish_bulk_import',
			array( $controller, 'ajax_bulk_import' )
		);
	}

	/**
	 * Tears down options and removes the per-source mock.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->remove_per_source_id_post_api_mock();
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		$this->source_payloads = array();
		parent::tearDown();
	}

	/**
	 * Builds a minimal REST body for the per-source mock. The trait will
	 * return this for any registered source ID.
	 *
	 * @param int $source_id Source post ID parsed from the URL.
	 * @return array<string, mixed>|null Mock body or null when unmocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		if ( ! isset( $this->source_payloads[ $source_id ] ) ) {
			return null;
		}

		$admin = get_userdata( $this->admin_user_id );

		return array(
			'id'                  => $source_id,
			'title'               => array( 'raw' => "Source Post {$source_id}" ),
			'featured_media'      => 0,
			'content'             => array( 'raw' => '<p>Content.</p>' ),
			'excerpt'             => array( 'raw' => '' ),
			'link'                => "https://source.example.com/post-{$source_id}",
			'slug'                => "post-{$source_id}",
			'comment_status'      => '',
			'ping_status'         => '',
			'menu_order'          => 0,
			'password'            => '',
			'parent'              => 0,
			'meta'                => array(),
			'safe_publish_author' => array(
				'email'        => false !== $admin ? (string) $admin->user_email : '',
				'login'        => false !== $admin ? (string) $admin->user_login : '',
				'display_name' => false !== $admin ? (string) $admin->display_name : '',
			),
		);
	}

	/**
	 * Verifies that a batch with no failures emits a single
	 * bulk_import_completed event with the correct accumulators and
	 * has_failures=false.
	 */
	public function test_clean_batch_emits_completed_event_with_no_failures(): void {
		// ARRANGE: two source payloads that will both import successfully.
		$this->source_payloads = array(
			10 => array(),
			20 => array(),
		);

		$_POST = array(
			'nonce'      => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'posts_data' => wp_json_encode(
				array(
					array(
						'id'        => 10,
						'title'     => 'Source Post 10',
						'link'      => 'https://source.example.com/post-10',
						'post_type' => 'pages',
					),
					array(
						'id'        => 20,
						'title'     => 'Source Post 20',
						'link'      => 'https://source.example.com/post-20',
						'post_type' => 'pages',
					),
				)
			),
		);

		// ACT: dispatch the bulk import.
		$this->dispatch_ajax_expecting_die( 'safe_publish_bulk_import' );

		// ASSERT: one bulk_import_completed event with matching counts.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame( Telemetry_Events::BULK_IMPORT_COMPLETED, $events[0]['event'] );
		$this->assertSame( 2, $events[0]['properties']['batch_size'] );
		$this->assertSame( 2, $events[0]['properties']['successful'] );
		$this->assertSame( 0, $events[0]['properties']['failed'] );
		$this->assertFalse( $events[0]['properties']['has_failures'] );
	}

	/**
	 * Verifies that has_failures is true and the failed count reflects the
	 * number of items that erred during the batch.
	 */
	public function test_mixed_batch_reports_failed_count_and_has_failures(): void {
		// ARRANGE: one mocked source (will succeed) and one unmocked id
		// (will fail at the fetch step), so the batch sees one success
		// and one failure.
		$this->source_payloads = array(
			30 => array(),
		);

		$_POST = array(
			'nonce'      => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'posts_data' => wp_json_encode(
				array(
					array(
						'id'        => 30,
						'title'     => 'Source Post 30',
						'link'      => 'https://source.example.com/post-30',
						'post_type' => 'pages',
					),
					array(
						'id'        => 999,
						'title'     => 'Missing',
						'link'      => 'https://source.example.com/post-999',
						'post_type' => 'pages',
					),
				)
			),
		);

		// ACT: dispatch the bulk import.
		$this->dispatch_ajax_expecting_die( 'safe_publish_bulk_import' );

		// ASSERT: the event reports the partial batch correctly.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame( 2, $events[0]['properties']['batch_size'] );
		$this->assertSame( 1, $events[0]['properties']['successful'] );
		$this->assertSame( 1, $events[0]['properties']['failed'] );
		$this->assertTrue( $events[0]['properties']['has_failures'] );
	}
}
