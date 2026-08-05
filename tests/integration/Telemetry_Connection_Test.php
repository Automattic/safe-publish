<?php
/**
 * Telemetry connection-test integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Admin_Ajax_Controller;
use Safe_Publish\Admin\Attention_Issues_Repository;
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
use Safe_Publish\Utils\Telemetry_Event_Queue;
use Safe_Publish\Utils\Telemetry_Events;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Ajax_UnitTestCase;
use WP_Error;

/**
 * Telemetry Connection Test.
 *
 * Verifies that the test-connection AJAX path emits connection_test_completed
 * with an outcome mapped from the auth probe's result: Authorized on 200,
 * unauthorized on a 401/403 Safe Publish rejection, blocked on a 401/403
 * upstream block, and unreachable on a transport error.
 */
class Telemetry_Connection_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Fallback shared secret used when no environment constant is defined.
	 * Long enough to pass the credential-format check so the probe issues a
	 * real (mocked) request rather than short-circuiting to unauthorized.
	 */
	private const FALLBACK_SECRET = 'integration-test-secret-key-32chars-ok';

	/**
	 * Queue that captures every telemetry event emitted by the controller.
	 *
	 * @var Telemetry_Event_Queue
	 */
	private Telemetry_Event_Queue $queue;

	/**
	 * HTTP response code the mocked transport returns, or null to force a
	 * transport-level WP_Error instead.
	 *
	 * @var int|null
	 */
	private ?int $mock_response_code = 200;

	/**
	 * Body the mocked transport returns. Defaults to an empty JSON array, which
	 * carries no safe_publish_auth_* code.
	 *
	 * @var string
	 */
	private string $mock_response_body = '[]';

	/**
	 * When true, the mocked transport returns a WP_Error to simulate an
	 * unreachable site.
	 *
	 * @var bool
	 */
	private bool $force_transport_error = false;

	/**
	 * Wires a queue-backed controller to the test-connection action and stubs
	 * the HTTP transport used by the auth probe.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'SAFE_PUBLISH_SHARED_SECRET' ) ) {
			define( 'SAFE_PUBLISH_SHARED_SECRET', self::FALLBACK_SECRET );
		}

		wp_set_current_user(
			$this->factory()->user->create( array( 'role' => 'administrator' ) )
		);

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
				new Navigation_Ref_Rewriter(),
				new Attention_Issues_Repository()
			),
			new Post_Type_Fetcher( $http_client ),
			$telemetry,
			new Attention_Issues_Repository()
		);

		remove_all_actions( 'wp_ajax_safe_publish_test_connection' );
		add_action(
			'wp_ajax_safe_publish_test_connection',
			array( $controller, 'ajax_test_connection' )
		);

		add_filter( 'pre_http_request', array( $this, 'mock_transport' ), 10, 3 );
	}

	/**
	 * Removes the transport stub.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_transport' ), 10 );
		parent::tearDown();
	}

	/**
	 * Short-circuits the auth probe's outbound request with a canned result.
	 *
	 * @param mixed $_preempt Filtered short-circuit value.
	 * @param mixed $_args    Request args (unused).
	 * @param mixed $_url     Request URL (unused).
	 * @return array|WP_Error Canned HTTP response, or a transport error.
	 */
	public function mock_transport( $_preempt, $_args, $_url ): array|WP_Error {
		if ( $this->force_transport_error ) {
			return new WP_Error( 'http_request_failed', 'Connection refused.' );
		}

		return array(
			'headers'  => array(),
			'body'     => $this->mock_response_body,
			'response' => array(
				'code'    => $this->mock_response_code ?? 200,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => '',
		);
	}

	/**
	 * Dispatches the test-connection action with a valid nonce and URL.
	 */
	private function dispatch(): void {
		$_POST = array(
			'nonce'              => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'connected_site_url' => 'https://source.example.com',
		);

		$this->dispatch_ajax_expecting_die( 'safe_publish_test_connection' );
	}

	/**
	 * Verifies that a 200 probe emits the authorized outcome.
	 */
	public function test_authorized_probe_emits_authorized_outcome(): void {
		// ARRANGE: The connected site grants edit context.
		$this->mock_response_code = 200;

		// ACT: Run the connection test.
		$this->dispatch();

		// ASSERT: One event with outcome=authorized.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			Telemetry_Events::CONNECTION_TEST_COMPLETED,
			$events[0]['event']
		);
		$this->assertSame(
			Telemetry_Events::CONNECTION_OUTCOME_AUTHORIZED,
			$events[0]['properties']['outcome']
		);
	}

	/**
	 * Verifies that a 401 Safe Publish rejection emits the unauthorized outcome.
	 */
	public function test_rejected_probe_emits_unauthorized_outcome(): void {
		// ARRANGE: A 401 carrying a Safe Publish authenticator rejection.
		$this->mock_response_code = 401;
		$this->mock_response_body = '{"code":"safe_publish_auth_invalid"}';

		// ACT: Run the connection test.
		$this->dispatch();

		// ASSERT: Outcome=unauthorized.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			Telemetry_Events::CONNECTION_OUTCOME_UNAUTHORIZED,
			$events[0]['properties']['outcome']
		);
	}

	/**
	 * Verifies that a 403 blocked upstream emits the blocked outcome.
	 */
	public function test_blocked_probe_emits_blocked_outcome(): void {
		// ARRANGE: A 403 with no Safe Publish code, as an upstream gate returns.
		$this->mock_response_code = 403;

		// ACT: Run the connection test.
		$this->dispatch();

		// ASSERT: Outcome=blocked.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			Telemetry_Events::CONNECTION_OUTCOME_BLOCKED,
			$events[0]['properties']['outcome']
		);
	}

	/**
	 * Verifies that a transport error emits the unreachable outcome.
	 */
	public function test_transport_error_emits_unreachable_outcome(): void {
		// ARRANGE: The connected site can't be reached.
		$this->force_transport_error = true;

		// ACT: Run the connection test.
		$this->dispatch();

		// ASSERT: Outcome=unreachable.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			Telemetry_Events::CONNECTION_OUTCOME_UNREACHABLE,
			$events[0]['properties']['outcome']
		);
	}
}
