<?php
/**
 * Telemetry single-import integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Admin_Ajax_Controller;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Navigation_Ref_Rewriter;
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

/**
 * Telemetry Single Import Test.
 *
 * Verifies that the single-import AJAX path (ajax_create_draft) emits a
 * single_import_completed event with outcome derived from the existing flag
 * and warning_count from the result's warnings array.
 */
class Telemetry_Single_Import_Test extends WP_Ajax_UnitTestCase {

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
	 * single-import AJAX handler so the test can assert on emitted events.
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
		$telemetry   = new Telemetry_Service( array(), $this->queue );

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
				new Meta_Terms_Manager(),
				$telemetry,
				new Navigation_Ref_Rewriter()
			),
			new Post_Type_Fetcher( $http_client ),
			$telemetry
		);

		remove_all_actions( 'wp_ajax_safe_publish_create_draft' );
		add_action(
			'wp_ajax_safe_publish_create_draft',
			array( $controller, 'ajax_create_draft' )
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
	 * Builds the per-source mock body for any registered source ID.
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
	 * Verifies that a first-time import emits single_import_completed with
	 * outcome new and no warnings.
	 */
	public function test_new_import_emits_outcome_new(): void {
		// ARRANGE: one mocked source post.
		$this->source_payloads = array( 50 => array() );

		$_POST = array(
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => 50,
			'title'          => 'Source Post 50',
			'source_link'    => 'https://source.example.com/post-50',
			'post_type'      => 'pages',
		);

		// ACT: dispatch the single import.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

		// ASSERT: one event with outcome=new and warning_count=0.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			Telemetry_Events::SINGLE_IMPORT_COMPLETED,
			$events[0]['event']
		);
		$this->assertSame(
			Telemetry_Events::SINGLE_OUTCOME_NEW,
			$events[0]['properties']['outcome']
		);
		$this->assertSame( 0, $events[0]['properties']['warning_count'] );
	}

	/**
	 * Verifies that a re-import of an already-imported source emits
	 * single_import_completed with outcome updated.
	 */
	public function test_repeat_import_emits_outcome_updated(): void {
		// ARRANGE: import a post once, then re-import the same source ID
		// with force_update so the existing-post path runs.
		$this->source_payloads = array( 60 => array() );

		$_POST = array(
			'nonce'          => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'source_post_id' => 60,
			'title'          => 'Source Post 60',
			'source_link'    => 'https://source.example.com/post-60',
			'post_type'      => 'pages',
		);
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

		$this->queue->clear();
		$this->_last_response = '';

		$_POST['force_update'] = 'true';

		// ACT: re-import the same source post.
		$this->dispatch_ajax_expecting_die( 'safe_publish_create_draft' );

		// ASSERT: outcome=updated on the second run.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			Telemetry_Events::SINGLE_OUTCOME_UPDATED,
			$events[0]['properties']['outcome']
		);
	}
}
