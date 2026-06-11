<?php
/**
 * Telemetry per-item failure integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Utils\Telemetry_Event_Queue;
use Safe_Publish\Utils\Telemetry_Events;
use Safe_Publish\Utils\Telemetry_Service;

/**
 * Telemetry Item Failed Test.
 *
 * Verifies that Post_Import_Service emits an import_item_failed event for
 * each per-item failure logged with status='error', with the bounded
 * error_code enum and session_type derived from the session row.
 */
class Telemetry_Item_Failed_Test extends Integration_Test_Case {

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
			$telemetry
		);
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
}
