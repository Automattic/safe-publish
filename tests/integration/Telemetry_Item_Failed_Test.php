<?php
/**
 * Telemetry per-item failure integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Navigation_Ref_Rewriter;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Options;
use Safe_Publish\Utils\Telemetry_Event_Queue;
use Safe_Publish\Utils\Telemetry_Events;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Error;

/**
 * Telemetry Item Failed Test.
 *
 * Verifies that Post_Import_Service emits an import_item_failed event for
 * each per-item failure logged with status='error', with the bounded
 * error_code enum and session_type derived from the session row.
 */
class Telemetry_Item_Failed_Test extends Integration_Test_Case {

	/**
	 * Response cap the safe_publish_request_args filter installs so a small
	 * body reaches the size limit.
	 */
	private const RESPONSE_CAP = 256;

	/**
	 * Queue that captures telemetry events emitted by the import service.
	 *
	 * @var Telemetry_Event_Queue
	 */
	private Telemetry_Event_Queue $queue;

	/**
	 * Post import service under test.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $import_service;

	/**
	 * History repository used to create sessions for the test.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Constructs the import service with a queued telemetry service so the
	 * test can assert on emitted events.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->queue = new Telemetry_Event_Queue();
		$telemetry   = new Telemetry_Service( array(), $this->queue );

		$http_client       = new HTTP_Client();
		$media_importer    = new Media_Importer( $http_client );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);
		$this->repository  = new History_Repository();

		$this->import_service = new Post_Import_Service(
			new Source_Posts_API( $http_client ),
			$media_importer,
			$content_processor,
			$this->repository,
			new Meta_Terms_Manager(),
			$telemetry,
			new Navigation_Ref_Rewriter(),
			new Attention_Issues_Repository()
		);
	}

	/**
	 * Removes the oversized-response filters and connected-URL option.
	 */
	#[\Override]
	protected function tearDown(): void {
		remove_filter(
			'safe_publish_request_args',
			array( $this, 'lower_response_cap' )
		);
		remove_filter(
			'pre_http_request',
			array( $this, 'return_oversized_response' ),
			5
		);
		delete_option( Options::OPTION_CONNECTED_SITE_URL );
		parent::tearDown();
	}

	/**
	 * Verifies that a validation failure in a single-import session emits
	 * import_item_failed with error_code=validation_failed and
	 * session_type=single.
	 */
	public function test_validation_failure_emits_event_with_session_type_single(): void {
		// ARRANGE: a single-import session and a payload missing the title.
		$session_id = $this->create_session( 'single' );

		// ACT: import_post() with an empty title triggers validation_failed.
		$this->import_service->import_post(
			array(
				'id'        => 12345,
				'title'     => '',
				'link'      => 'https://source.example.com/post-12345',
				'post_type' => 'pages',
			),
			$session_id
		);

		// ASSERT: one event with the expected error_code + session_type.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			Telemetry_Events::IMPORT_ITEM_FAILED,
			$events[0]['event']
		);
		$this->assertSame( 'validation_failed', $events[0]['properties']['error_code'] );
		$this->assertSame(
			Telemetry_Events::SESSION_TYPE_SINGLE,
			$events[0]['properties']['session_type']
		);
		$this->assertArrayNotHasKey(
			'media_failure_count',
			$events[0]['properties']
		);
	}

	/**
	 * Verifies that a validation failure inside a bulk-import session
	 * reports session_type=bulk so the team can tell whether failures
	 * cluster in batch runs.
	 */
	public function test_validation_failure_in_bulk_session_reports_bulk(): void {
		// ARRANGE: a bulk session.
		$session_id = $this->create_session( 'bulk' );

		// ACT: validation failure inside a bulk session.
		$this->import_service->import_post(
			array(
				'id'        => 67890,
				'title'     => '',
				'link'      => 'https://source.example.com/post-67890',
				'post_type' => 'pages',
			),
			$session_id
		);

		// ASSERT: session_type follows the session row.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			Telemetry_Events::SESSION_TYPE_BULK,
			$events[0]['properties']['session_type']
		);
	}

	/**
	 * Verifies that import_post() called without a session does not emit
	 * a telemetry event. log_import_if_session() short-circuits without
	 * writing audit history, so there is no failure to report.
	 */
	public function test_no_event_when_no_session(): void {
		// ARRANGE: no session.

		// ACT: import_post() called without a session id.
		$this->import_service->import_post(
			array(
				'id'        => 11111,
				'title'     => '',
				'link'      => 'https://source.example.com/post-11111',
				'post_type' => 'pages',
			)
		);

		// ASSERT: no telemetry emitted.
		$this->assertSame( array(), $this->queue->events() );
	}

	/**
	 * Verifies that an oversized fresh-content fetch reports the size-specific
	 * error_code in telemetry instead of the generic fetch_failed or the
	 * unknown fallback.
	 */
	public function test_oversized_fresh_fetch_reports_size_error_code(): void {
		// ARRANGE: a session, a connected source, and a response over the cap.
		$session_id = $this->create_session( 'single' );
		update_option(
			Options::OPTION_CONNECTED_SITE_URL,
			'https://source.example.com'
		);
		add_filter(
			'safe_publish_request_args',
			array( $this, 'lower_response_cap' )
		);
		add_filter(
			'pre_http_request',
			array( $this, 'return_oversized_response' ),
			5,
			3
		);

		// ACT: import a valid post whose fresh-content fetch exceeds the cap.
		$this->import_service->import_post(
			array(
				'id'        => 4242,
				'title'     => 'Oversized source',
				'link'      => 'https://source.example.com/post-4242',
				'post_type' => 'pages',
			),
			$session_id
		);

		// ASSERT: the size-specific code reaches telemetry.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			HTTP_Client::ERROR_RESPONSE_TOO_LARGE,
			$events[0]['properties']['error_code']
		);
	}

	/**
	 * Creates a session of the given type and returns its id.
	 *
	 * @param string $session_type Either 'single' or 'bulk'.
	 */
	private function create_session( string $session_type ): int {
		$result = $this->repository->create_session(
			'https://source.example.com',
			$session_type
		);

		$this->assertIsInt( $result );

		return $result;
	}

	/**
	 * Lowers the response cap so a small body reaches it.
	 *
	 * @param array $args Request arguments.
	 * @return array Request arguments with the cap lowered.
	 */
	public function lower_response_cap( array $args ): array {
		$args['limit_response_size'] = self::RESPONSE_CAP;
		return $args;
	}

	/**
	 * Returns a mocked response whose body exceeds the cap.
	 *
	 * @param false|array|WP_Error $preempt Preemptive return value (unused).
	 * @param array                $args    Request arguments (unused).
	 * @param string               $url     Request URL (unused).
	 * @return array Mock HTTP response with an oversized body.
	 */
	public function return_oversized_response(
		false|array|WP_Error $preempt,
		array $args,
		string $url
	): array {
		unset( $preempt, $args, $url );

		return array(
			'headers'  => array(),
			'body'     => str_repeat( 'a', self::RESPONSE_CAP + 1 ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
