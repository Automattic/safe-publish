<?php
/**
 * Forensic audit-log integration tests for per-item import failures.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Import_Logger;
use Safe_Publish\Admin\Navigation_Ref_Rewriter;
use Safe_Publish\Admin\Post_Import_Service;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\API\Meta_Terms_Manager;
use Safe_Publish\API\Source_Posts_API;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;
use Safe_Publish\Tests\Integration\Source_Posts_API\Source_Posts_API_Test_Base;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Telemetry_Service;

/**
 * Audit Log Import Item Failed Test.
 *
 * Verifies that every per-item import failure funneled through
 * History_Repository::log_import_action() also records an IMPORT_ITEM_FAILED
 * event on the forensic import channel, in addition to the History row.
 */
class Audit_Log_Import_Item_Failed_Test extends Source_Posts_API_Test_Base {

	/**
	 * History repository shared by the service under test.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Post import service under test.
	 *
	 * @var Post_Import_Service
	 */
	private Post_Import_Service $import_service;

	/**
	 * Sets up the audit table, a clean import channel, and the import service.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'import' );

		$this->repository     = new History_Repository();
		$this->import_service = $this->build_import_service(
			new Source_Posts_API( new HTTP_Client() )
		);
	}

	/**
	 * Verifies that a single-import failure records an IMPORT_ITEM_FAILED event
	 * on the import channel with the failure payload and auto-captured actor.
	 */
	public function test_single_import_failure_emits_import_channel_audit_event(): void {
		// ARRANGE: a single-import session; an empty title fails validation
		// before any HTTP round-trip.
		$session_id = $this->create_session( 'single' );
		$user_id    = get_current_user_id();

		// ACT: import a post with a missing title.
		$this->import_service->import_post(
			array(
				'id'        => 4321,
				'title'     => '',
				'link'      => 'https://source.example.com/post-4321',
				'post_type' => 'pages',
			),
			$session_id
		);

		// ASSERT: one error-level import event carries the failure payload.
		$events = $this->failed_events();
		$this->assertCount( 1, $events );

		$event = $events[0];
		$this->assertSame( 'import', $event['channel'] );
		$this->assertSame( 'error', $event['level'] );
		$this->assertSame( Log_Events::IMPORT_ITEM_FAILED, $event['event'] );
		$this->assertSame( 'validation_failed', $event['data']['error_code'] );
		$this->assertArrayHasKey( 'error_message', $event['data'] );
		$this->assertNotSame( '', $event['data']['error_message'] );
		$this->assertSame( 4321, $event['data']['source_post_id'] );
		$this->assertSame( $session_id, $event['data']['session_id'] );

		// ASSERT: the actor is auto-captured from the current user.
		$this->assertSame( $user_id, $event['data']['actor_user_id'] );
		$this->assertNotSame( '', $event['data']['actor_source'] );
	}

	/**
	 * Verifies that a bulk-import failure emits the same event, proving the
	 * shared choke point covers the single and bulk paths alike.
	 */
	public function test_bulk_import_failure_emits_import_channel_audit_event(): void {
		// ARRANGE: a bulk-import session.
		$session_id = $this->create_session( 'bulk' );

		// ACT: a validation failure inside the bulk session.
		$this->import_service->import_post(
			array(
				'id'        => 8765,
				'title'     => '',
				'link'      => 'https://source.example.com/post-8765',
				'post_type' => 'pages',
			),
			$session_id
		);

		// ASSERT: the event is recorded for the bulk path too.
		$events = $this->failed_events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'validation_failed', $events[0]['data']['error_code'] );
		$this->assertSame( 8765, $events[0]['data']['source_post_id'] );
		$this->assertSame( $session_id, $events[0]['data']['session_id'] );
	}

	/**
	 * Verifies that a successful import records no failure event, keeping
	 * emission scoped to actual failures.
	 */
	public function test_successful_import_emits_no_failure_event(): void {
		// ARRANGE: a bulk session and a well-formed post.
		$session_id = $this->create_session( 'bulk' );

		// ACT: import a valid post that succeeds.
		$result = $this->import_service->import_post(
			array(
				'id'        => 7007,
				'title'     => 'Healthy Post',
				'link'      => 'https://source.example.com/healthy-post',
				'post_type' => 'posts',
			),
			$session_id
		);

		// ASSERT: the import succeeded and emitted no failure event.
		$this->assertTrue( $result['success'] );
		$this->assertCount( 0, $this->failed_events() );
	}

	/**
	 * Verifies that the catch-all exception path records a classifiable
	 * unexpected_exception action instead of an empty or unknown code.
	 */
	public function test_exception_path_records_unexpected_exception_action(): void {
		// ARRANGE: a service whose source API throws, exercising the import
		// service's catch-all exception path.
		$session_id = $this->create_session( 'bulk' );
		$service    = $this->build_import_service( $this->throwing_source_posts_api() );

		// ACT: import a well-formed post; the fetch throws.
		$service->import_post(
			array(
				'id'        => 5150,
				'title'     => 'Throws On Fetch',
				'link'      => 'https://source.example.com/post-5150',
				'post_type' => 'posts',
			),
			$session_id
		);

		// ASSERT: the failure is classified, not left empty or unknown.
		$events = $this->failed_events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'error', $events[0]['level'] );
		$this->assertSame( 'unexpected_exception', $events[0]['data']['error_code'] );
		$this->assertSame( 5150, $events[0]['data']['source_post_id'] );
	}

	/**
	 * Verifies that a parent_not_resolved failure carries the resolver's reason
	 * and parent_id in the event payload.
	 */
	public function test_parent_not_resolved_event_includes_reason_and_parent_id(): void {
		// ARRANGE: a hierarchical page whose source parent is not imported here,
		// so parent resolution aborts with a structured reason.
		$session_id                          = $this->create_session( 'bulk' );
		$this->mock_post_overrides['parent'] = 909;

		// ACT: import the child page.
		$this->import_service->import_post(
			array(
				'id'        => 6060,
				'title'     => 'Child Page',
				'link'      => 'https://source.example.com/child-page',
				'post_type' => 'pages',
			),
			$session_id
		);

		// ASSERT: the event carries the parent-resolution detail.
		$events = $this->failed_events();
		$this->assertCount( 1, $events );
		$this->assertSame( 'parent_not_resolved', $events[0]['data']['error_code'] );
		$this->assertSame( 'not_imported', $events[0]['data']['reason'] );
		$this->assertSame( 909, $events[0]['data']['parent_id'] );
	}

	/**
	 * Verifies that item_failed escalates only an unexpected code to the server
	 * log, keeping expected domain failures in the audit DB alone.
	 */
	public function test_item_failed_routes_unexpected_codes_to_server_log(): void {
		// ARRANGE: an import logger capturing server-log skeletons.
		$logger = new class() extends Import_Logger {

			/**
			 * Skeletons captured in place of the real error_log() sink.
			 *
			 * @var array[]
			 */
			public array $server_log_skeletons = array();

			/**
			 * Captures the skeleton instead of writing to error_log().
			 *
			 * @param string $event    Event type.
			 * @param array  $skeleton PII-free projection for the server log.
			 */
			#[\Override]
			protected function write_server_log(
				string $event,
				array $skeleton
			): void {
				$this->server_log_skeletons[] = $skeleton;
			}
		};

		// ACT: an expected domain failure, then an unexpected exception.
		$logger->item_failed( 101, 202, 'validation_failed', 'Bad title.' );
		$logger->item_failed( 101, 202, 'unexpected_exception', 'Boom.' );

		// ASSERT: only the unexpected exception reached the server log.
		$this->assertCount( 1, $logger->server_log_skeletons );
		$this->assertSame(
			'unexpected_exception',
			$logger->server_log_skeletons[0]['error_code']
		);
	}

	/**
	 * Returns the import-channel IMPORT_ITEM_FAILED events in the audit table.
	 *
	 * @return array[] Matching audit rows with decoded data.
	 */
	private function failed_events(): array {
		return Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => Log_Events::IMPORT_ITEM_FAILED,
			)
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
	 * Builds an import service around the given source API, sharing the test's
	 * repository so all failures land in the same audit table.
	 *
	 * @param Source_Posts_API $api Source API to inject.
	 */
	private function build_import_service( Source_Posts_API $api ): Post_Import_Service {
		$media_importer    = new Media_Importer( new HTTP_Client() );
		$content_processor = new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);

		return new Post_Import_Service(
			$api,
			$media_importer,
			$content_processor,
			$this->repository,
			new Meta_Terms_Manager(),
			new Telemetry_Service(),
			new Navigation_Ref_Rewriter(),
			new Attention_Issues_Repository()
		);
	}

	/**
	 * Returns a Source_Posts_API whose fresh-post fetch throws, to exercise the
	 * import service's catch-all exception path.
	 */
	private function throwing_source_posts_api(): Source_Posts_API {
		return new class( new HTTP_Client() ) extends Source_Posts_API {

			/**
			 * Throws to simulate an unexpected fetch failure.
			 *
			 * @param int    $source_post_id Source post ID.
			 * @param string $post_type      Post type slug or REST endpoint.
			 * @throws \RuntimeException Always, to trigger the exception path.
			 */
			#[\Override]
			public function fetch_fresh_post(
				int $source_post_id,
				string $post_type
			): never {
				throw new \RuntimeException( 'Simulated fetch failure.' );
			}
		};
	}
}
