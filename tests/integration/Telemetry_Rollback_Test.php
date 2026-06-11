<?php
/**
 * Telemetry rollback integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Import_Actions_Ajax_Handler;
use Safe_Publish\Admin\Session_Rollback_Service;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use Safe_Publish\Utils\Telemetry_Event_Queue;
use Safe_Publish\Utils\Telemetry_Events;
use Safe_Publish\Utils\Telemetry_Service;
use WP_Ajax_UnitTestCase;

/**
 * Telemetry Rollback Test.
 *
 * Verifies that the session and item rollback AJAX handlers emit a
 * `rollback_performed` event with scope, counts, and derived outcome.
 */
class Telemetry_Rollback_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;

	/**
	 * Queue that captures every telemetry event emitted by the handler.
	 *
	 * @var Telemetry_Event_Queue
	 */
	private Telemetry_Event_Queue $queue;

	/**
	 * History repository used to seed sessions and items.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Substitutes a handler with queued telemetry for the production
	 * rollback AJAX handlers so the test can assert on emitted events.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Imports_Table::create_table();
		Import_Items_Table::create_table();

		$admin_user_id = $this->factory()->user->create(
			array( 'role' => 'administrator' )
		);
		wp_set_current_user( $admin_user_id );

		$this->queue      = new Telemetry_Event_Queue();
		$this->repository = new History_Repository();

		$telemetry = new Telemetry_Service( array(), $this->queue );

		$handler = new Import_Actions_Ajax_Handler(
			new Session_Rollback_Service( $this->repository ),
			$telemetry
		);

		remove_all_actions( 'wp_ajax_safe_publish_rollback_session' );
		remove_all_actions( 'wp_ajax_safe_publish_rollback_item' );
		add_action(
			'wp_ajax_safe_publish_rollback_session',
			array( $handler, 'ajax_rollback_session' )
		);
		add_action(
			'wp_ajax_safe_publish_rollback_item',
			array( $handler, 'ajax_rollback_item' )
		);
	}

	/**
	 * Verifies that a session rollback that deletes a freshly-created post
	 * fires rollback_performed with scope=session and outcome=success.
	 */
	public function test_session_rollback_fires_with_session_scope(): void {
		// ARRANGE: a bulk session with one successful new-post item.
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'bulk'
		);
		$post_id    = $this->factory()->post->create(
			array( 'post_title' => 'Imported Post' )
		);
		$this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);
		$this->repository->complete_session( $session_id );

		$_POST = array(
			'nonce'      => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'session_id' => $session_id,
		);

		// ACT: dispatch the session rollback.
		$this->dispatch_ajax_expecting_die( 'safe_publish_rollback_session' );

		// ASSERT: one rollback_performed event with the session scope and
		// the success outcome derived from a clean deletion.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			Telemetry_Events::ROLLBACK_PERFORMED,
			$events[0]['event']
		);
		$this->assertSame(
			Telemetry_Events::ROLLBACK_SCOPE_SESSION,
			$events[0]['properties']['scope']
		);
		$this->assertSame( 1, $events[0]['properties']['deleted_count'] );
		$this->assertSame( 0, $events[0]['properties']['restored_count'] );
		$this->assertSame( 0, $events[0]['properties']['failed_count'] );
		$this->assertSame(
			Telemetry_Events::ROLLBACK_OUTCOME_SUCCESS,
			$events[0]['properties']['outcome']
		);
	}

	/**
	 * Verifies that a single-item rollback that deletes the post fires
	 * rollback_performed with scope=item and deleted_count=1.
	 */
	public function test_item_rollback_fires_with_item_scope(): void {
		// ARRANGE: a session with one successful new-post item.
		$session_id = $this->repository->create_session(
			'https://source.example.com',
			'single'
		);
		$post_id    = $this->factory()->post->create(
			array( 'post_title' => 'Imported Post' )
		);
		$item_id    = $this->repository->log_import_action(
			$session_id,
			2,
			'Imported Post',
			'success',
			$post_id
		);
		$this->repository->complete_session( $session_id );

		$_POST = array(
			'nonce'   => wp_create_nonce( 'safe_publish_ajax_nonce' ),
			'item_id' => $item_id,
		);

		// ACT: dispatch the item rollback.
		$this->dispatch_ajax_expecting_die( 'safe_publish_rollback_item' );

		// ASSERT: scope=item, deleted_count=1, outcome=success.
		$events = $this->queue->events();
		$this->assertCount( 1, $events );
		$this->assertSame(
			Telemetry_Events::ROLLBACK_SCOPE_ITEM,
			$events[0]['properties']['scope']
		);
		$this->assertSame( 1, $events[0]['properties']['deleted_count'] );
		$this->assertSame( 0, $events[0]['properties']['restored_count'] );
		$this->assertSame( 0, $events[0]['properties']['failed_count'] );
		$this->assertSame(
			Telemetry_Events::ROLLBACK_OUTCOME_SUCCESS,
			$events[0]['properties']['outcome']
		);
	}
}
