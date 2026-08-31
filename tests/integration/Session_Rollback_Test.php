<?php
/**
 * Integration tests for rollback functionality
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Repository;
use Safe_Publish\Admin\Session_Rollback_Service;
use Safe_Publish\Utils\Audit_Log_Table;
use Safe_Publish\Utils\Import_Items_Table;
use Safe_Publish\Utils\Imports_Table;
use Safe_Publish\Utils\Options;
use WP_Error;

/**
 * Session Rollback Test Class.
 */
class Session_Rollback_Test extends Integration_Test_Case {

	/**
	 * History repository instance.
	 *
	 * @var History_Repository
	 */
	private History_Repository $repository;

	/**
	 * Rollback service instance.
	 *
	 * @var Session_Rollback_Service
	 */
	private Session_Rollback_Service $rollback_service;

	/**
	 * Set up test environment.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->repository       = new History_Repository();
		$this->rollback_service = new Session_Rollback_Service( $this->repository );

		Audit_Log_Table::create_table();
		Audit_Log_Table::clear( 'import' );
	}

	/**
	 * Verifies that rolling back single item deletes only that post.
	 */
	public function test_rollback_item_deletes_single_post(): void {
		// ARRANGE: Create session with two posts.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$post_id_1 = $this->factory()->post->create( array( 'post_title' => 'Keep This' ) );
		$post_id_2 = $this->factory()->post->create( array( 'post_title' => 'Delete This' ) );

		$this->repository->log_import_action(
			$session_id,
			1,
			'Keep This',
			'success',
			$post_id_1
		);

		$item_id_2 = $this->repository->log_import_action(
			$session_id,
			2,
			'Delete This',
			'success',
			$post_id_2
		);

		// ACT: Rollback only second item.
		$result = $this->rollback_service->rollback_item( $item_id_2 );

		// ASSERT: Only second post deleted.
		$this->assertIsArray( $result );
		$this->assertSame( 'deleted', $result['action'] );
		$this->assertSame( $post_id_2, $result['post_id'] );

		// ASSERT: Verify first post still exists.
		$this->assertNotNull( get_post( $post_id_1 ) );

		// ASSERT: Verify second post deleted.
		$this->assertNull( get_post( $post_id_2 ) );
	}

	/**
	 * Verifies that rolling back a post deletes the plugin-imported media it
	 * owns.
	 */
	public function test_rollback_item_deletes_owned_imported_media(): void {
		// ARRANGE: An imported post owning one plugin-imported attachment.
		$session_id    = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id       = $this->factory()->post->create( array( 'post_title' => 'Owner' ) );
		$attachment_id = $this->seed_imported_attachment( $post_id );
		$item_id       = $this->repository->log_import_action(
			$session_id,
			1,
			'Owner',
			'success',
			$post_id
		);

		// ACT: Roll back the item.
		$result = $this->rollback_service->rollback_item( $item_id );

		// ASSERT: The post and its owned media are both gone.
		$this->assertIsArray( $result );
		$this->assertSame( 'deleted', $result['action'] );
		$this->assertNull( get_post( $post_id ) );
		$this->assertNull( get_post( $attachment_id ) );
	}

	/**
	 * Verifies that rolling back a post never deletes media owned by a
	 * different, surviving post, as when the rolled-back post's import pulled a
	 * cross-post gallery set parented elsewhere.
	 */
	public function test_rollback_preserves_media_owned_by_surviving_post(): void {
		// ARRANGE: Post A is rolled back; post B survives and owns its own
		// media.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_a     = $this->factory()->post->create( array( 'post_title' => 'A' ) );
		$post_b     = $this->factory()->post->create( array( 'post_title' => 'B' ) );
		$b_media    = $this->seed_imported_attachment( $post_b );
		$item_a     = $this->repository->log_import_action(
			$session_id,
			1,
			'A',
			'success',
			$post_a
		);

		// ACT: Roll back only A.
		$this->rollback_service->rollback_item( $item_a );

		// ASSERT: B and its media are untouched.
		$this->assertNotNull( get_post( $post_b ) );
		$this->assertNotNull( get_post( $b_media ) );
	}

	/**
	 * Verifies that rolling back a post keeps an attachment parented to it that
	 * a surviving post still shows inline, since import deduplicates media by
	 * source URL across posts.
	 */
	public function test_rollback_keeps_media_inlined_by_surviving_post(): void {
		// ARRANGE: X is parented to A but also embedded inline in surviving B.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_a     = $this->factory()->post->create( array( 'post_title' => 'A' ) );
		$shared_x   = $this->seed_imported_attachment( $post_a );
		$post_b     = $this->factory()->post->create(
			array(
				'post_title'   => 'B',
				'post_content' => '<img src="' . wp_get_attachment_url( $shared_x ) . '">',
			)
		);
		$item_a     = $this->repository->log_import_action(
			$session_id,
			1,
			'A',
			'success',
			$post_a
		);

		// ACT: Roll back only A.
		$this->rollback_service->rollback_item( $item_a );

		// ASSERT: A is gone, but B keeps its inlined image.
		$this->assertNull( get_post( $post_a ) );
		$this->assertNotNull( get_post( $post_b ) );
		$this->assertNotNull( get_post( $shared_x ) );
	}

	/**
	 * Verifies that rolling back a post keeps an attachment a surviving post's
	 * gallery shortcode still lists by ID, the cross-post gallery case.
	 */
	public function test_rollback_keeps_media_in_surviving_gallery(): void {
		// ARRANGE: X is parented to A; surviving B renders it in a gallery.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_a     = $this->factory()->post->create( array( 'post_title' => 'A' ) );
		$shared_x   = $this->seed_imported_attachment( $post_a );
		$post_b     = $this->factory()->post->create(
			array(
				'post_title'   => 'B',
				'post_content' => '[gallery ids="' . $shared_x . '"]',
			)
		);
		$item_a     = $this->repository->log_import_action(
			$session_id,
			1,
			'A',
			'success',
			$post_a
		);

		// ACT: Roll back only A.
		$this->rollback_service->rollback_item( $item_a );

		// ASSERT: B keeps the gallery's attachment.
		$this->assertNotNull( get_post( $post_b ) );
		$this->assertNotNull( get_post( $shared_x ) );
	}

	/**
	 * Verifies that rolling back a post keeps an attachment a surviving post
	 * uses as its featured image.
	 */
	public function test_rollback_keeps_media_featured_by_surviving_post(): void {
		// ARRANGE: X is parented to A but is surviving B's featured image.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_a     = $this->factory()->post->create( array( 'post_title' => 'A' ) );
		$shared_x   = $this->seed_imported_attachment( $post_a );
		$post_b     = $this->factory()->post->create( array( 'post_title' => 'B' ) );
		set_post_thumbnail( $post_b, $shared_x );
		$item_a = $this->repository->log_import_action(
			$session_id,
			1,
			'A',
			'success',
			$post_a
		);

		// ACT: Roll back only A.
		$this->rollback_service->rollback_item( $item_a );

		// ASSERT: B keeps its featured image.
		$this->assertNotNull( get_post( $post_b ) );
		$this->assertNotNull( get_post( $shared_x ) );
	}

	/**
	 * Verifies that rolling back a post leaves a user's own (non-plugin)
	 * attachment parented to it in place.
	 */
	public function test_rollback_preserves_non_plugin_media(): void {
		// ARRANGE: A post owning an attachment with no import-origin meta.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id    = $this->factory()->post->create( array( 'post_title' => 'Owner' ) );
		$user_media = $this->factory()->attachment->create(
			array(
				'post_parent'    => $post_id,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'User Upload',
			)
		);
		$item_id    = $this->repository->log_import_action(
			$session_id,
			1,
			'Owner',
			'success',
			$post_id
		);

		// ACT: Roll back the post.
		$this->rollback_service->rollback_item( $item_id );

		// ASSERT: The post is gone but the user's own attachment remains.
		$this->assertNull( get_post( $post_id ) );
		$this->assertNotNull( get_post( $user_media ) );
	}

	/**
	 * Verifies that restoring an updated post to its previous version keeps its
	 * media, since a restore reverts content rather than removing the post.
	 */
	public function test_restore_previous_version_keeps_owned_media(): void {
		// ARRANGE: An updated post owning imported media, logged with previous
		// content so rollback restores it instead of deleting it.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id    = $this->factory()->post->create(
			array(
				'post_title'   => 'Updated',
				'post_content' => 'New content.',
			)
		);
		$media_id   = $this->seed_imported_attachment( $post_id );
		$item_id    = $this->repository->log_import_action(
			$session_id,
			1,
			'Updated',
			'updated',
			$post_id,
			null,
			array(
				'previous_content' => 'Old content.',
				'action'           => 'updated_existing',
			)
		);

		// ACT: Roll back the item, which restores the previous version.
		$result = $this->rollback_service->rollback_item( $item_id );

		// ASSERT: The post is restored and its media is kept.
		$this->assertIsArray( $result );
		$this->assertSame( 'restored', $result['action'] );
		$this->assertNotNull( get_post( $media_id ) );
	}

	/**
	 * Creates a plugin-imported attachment parented to a post.
	 *
	 * @param int $parent_id Owning post ID.
	 * @return int Attachment ID carrying import-origin meta.
	 */
	private function seed_imported_attachment( int $parent_id ): int {
		$attachment_id = $this->factory()->attachment->create(
			array(
				'post_parent'    => $parent_id,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Imported Image',
			)
		);
		$this->assertIsInt( $attachment_id );

		// A per-attachment file so wp_get_attachment_url() resolves and the
		// content-usage check has a distinct path stem to match on.
		update_post_meta(
			$attachment_id,
			'_wp_attached_file',
			"2026/08/imported-{$attachment_id}.jpg"
		);
		update_post_meta(
			$attachment_id,
			Options::META_ORIGINAL_URL,
			'https://example.com/image.jpg'
		);
		update_post_meta(
			$attachment_id,
			Options::META_IMPORTED_FROM,
			'https://example.com'
		);

		return $attachment_id;
	}

	/**
	 * Verifies that rollback with nonexistent item returns error.
	 */
	public function test_rollback_nonexistent_item_returns_error(): void {
		// ARRANGE: Use nonexistent item ID.
		$fake_item_id = 999999;

		// ACT: Attempt rollback.
		$result = $this->rollback_service->rollback_item( $fake_item_id );

		// ASSERT: Returns WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'item_not_found', $result->get_error_code() );
	}

	/**
	 * Verifies that rolling back an unsuccessfully imported item says why.
	 */
	public function test_rollback_unsupported_status_reports_the_reason(): void {
		// ARRANGE: An error-status row with a live post ID, the only shape
		// that reaches unsupported_status.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id    = $this->factory()->post->create( array( 'post_title' => 'Partial' ) );
		$item_id    = $this->repository->log_import_action(
			$session_id,
			1,
			'Partial',
			'error',
			$post_id,
			'Media download failed'
		);

		// ACT: Attempt rollback.
		$result = $this->rollback_service->rollback_item( $item_id );

		// ASSERT: The message states the reason.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_status', $result->get_error_code() );
		$this->assertSame(
			'Cannot roll back this item because it was not imported successfully',
			$result->get_error_message()
		);
	}

	/**
	 * Verifies that restoring a previous version keeps the backslashes in both
	 * the post fields and the restored metadata.
	 */
	public function test_restore_previous_version_keeps_backslashes(): void {
		// ARRANGE: An updated post whose pre-update version carried
		// backslashes, logged so rollback restores it.
		$title      = 'Windows path A\B';
		$excerpt    = 'Backslash sample: C:\builds\out.';
		$content    = '<p>namespace App\Models; $re = "\d+";</p>';
		$meta_value = 'C:\builds\out';

		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id    = $this->factory()->post->create(
			array(
				'post_title'   => 'Updated',
				'post_content' => 'New content.',
			)
		);
		$item_id    = $this->repository->log_import_action(
			$session_id,
			1,
			'Updated',
			'updated',
			$post_id,
			null,
			array(
				'previous_title'   => $title,
				'previous_excerpt' => $excerpt,
				'previous_content' => $content,
				'previous_meta'    => array( 'my_field' => $meta_value ),
				'action'           => 'updated_existing',
			)
		);

		// ACT: Roll the item back, restoring the previous version.
		$result = $this->rollback_service->rollback_item( $item_id );

		// ASSERT: The restore ran and every field came back byte for byte.
		$this->assertIsArray( $result );
		$this->assertSame( 'restored', $result['action'] );

		$post = get_post( $post_id );
		$this->assertNotNull( $post );
		$this->assertSame( $title, $post->post_title );
		$this->assertSame( $excerpt, $post->post_excerpt );
		$this->assertSame( $content, $post->post_content );
		$this->assertSame(
			$meta_value,
			get_post_meta( $post_id, 'my_field', true )
		);
	}

	/**
	 * Verifies that replaying a rolled-back item is refused and leaves the
	 * post's current content untouched.
	 */
	public function test_rollback_item_refuses_an_already_rolled_back_item(): void {
		// ARRANGE: An updated item rolled back once, after which newer
		// content landed on the post.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id    = $this->factory()->post->create(
			array(
				'post_title'   => 'Updated',
				'post_content' => 'Imported content.',
			)
		);
		$item_id    = $this->repository->log_import_action(
			$session_id,
			1,
			'Updated',
			'updated',
			$post_id,
			null,
			array(
				'previous_content' => 'Old content.',
				'action'           => 'updated_existing',
			)
		);
		$this->rollback_service->rollback_item( $item_id );

		$newer = 'Content written after the rollback.';
		$this->factory()->post->update_object(
			$post_id,
			array( 'post_content' => $newer )
		);

		// ACT: Roll the same item back a second time.
		$result = $this->rollback_service->rollback_item( $item_id );

		// ASSERT: The replay was refused with its own error code.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'item_already_rolled_back', $result->get_error_code() );

		// ASSERT: The newer content survived.
		$post = get_post( $post_id );
		$this->assertNotNull( $post );
		$this->assertSame( $newer, $post->post_content );
	}

	/**
	 * Verifies that the replay guard leaves sequential rollback intact, so
	 * each rollback still walks back one import operation.
	 */
	public function test_sequential_rollback_walks_back_one_operation_at_a_time(): void {
		// ARRANGE: A post created by one import, then updated by a later one
		// that captured the created version.
		$post_id = $this->factory()->post->create(
			array(
				'post_title'   => 'Post',
				'post_content' => 'Updated content.',
			)
		);

		$create_session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$create_item_id    = $this->repository->log_import_action(
			$create_session_id,
			1,
			'Post',
			'success',
			$post_id
		);

		$update_session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$update_item_id    = $this->repository->log_import_action(
			$update_session_id,
			1,
			'Post',
			'updated',
			$post_id,
			null,
			array(
				'previous_content' => 'Created content.',
				'action'           => 'updated_existing',
			)
		);

		// ACT: Roll back the update, then the creation.
		$update_result = $this->rollback_service->rollback_item( $update_item_id );
		$restored_post = get_post( $post_id );
		$create_result = $this->rollback_service->rollback_item( $create_item_id );

		// ASSERT: The update rolled back to the created version.
		$this->assertIsArray( $update_result );
		$this->assertSame( 'restored', $update_result['action'] );
		$this->assertNotNull( $restored_post );
		$this->assertSame( 'Created content.', $restored_post->post_content );

		// ASSERT: Rolling back the creation then removed the post.
		$this->assertIsArray( $create_result );
		$this->assertSame( 'deleted', $create_result['action'] );
		$this->assertNull( get_post( $post_id ) );
	}

	/**
	 * Verifies that a successful single-item rollback emits an ITEM_ROLLED_BACK
	 * audit event with the item ID.
	 */
	public function test_rollback_item_emits_audit_event(): void {
		// ARRANGE: Create session with a single imported post.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);

		$post_id = $this->factory()->post->create(
			array( 'post_title' => 'Imported Post' )
		);

		$item_id = $this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);

		// ACT: Roll back just this item.
		$this->rollback_service->rollback_item( $item_id );

		// ASSERT: One ITEM_ROLLED_BACK event was emitted with the item ID,
		// session ID, and post ID in its payload.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'info', $events[0]['level'] );
		$this->assertSame( $item_id, $events[0]['data']['item_id'] );
		$this->assertSame( $session_id, $events[0]['data']['session_id'] );
		$this->assertSame( $post_id, $events[0]['data']['post_id'] );
	}

	/**
	 * Verifies that marking an already-rolled-back item reports success and
	 * emits an ITEM_ALREADY_ROLLED_BACK event instead of ITEM_ROLLED_BACK.
	 */
	public function test_mark_item_rolled_back_emits_already_rolled_back_when_no_row_changed(): void {
		// ARRANGE: Item that is already flagged as rolled_back.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$post_id    = $this->factory()->post->create(
			array( 'post_title' => 'Imported Post' )
		);
		$item_id    = $this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);
		$this->repository->mark_item_rolled_back( $item_id );
		Audit_Log_Table::clear( 'import' );

		// ACT: Mark the same item as rolled back again.
		$flagged = $this->repository->mark_item_rolled_back( $item_id );

		// ASSERT: The row already carries the flag, so the write succeeded.
		$this->assertTrue( $flagged );

		// ASSERT: An ITEM_ALREADY_ROLLED_BACK event was emitted, not an
		// ITEM_ROLLED_BACK event.
		$already_rolled_back_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ALREADY_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $already_rolled_back_events );
		$this->assertSame( 'info', $already_rolled_back_events[0]['level'] );
		$this->assertSame( $item_id, $already_rolled_back_events[0]['data']['item_id'] );
		$this->assertSame( $session_id, $already_rolled_back_events[0]['data']['session_id'] );
		$this->assertSame( $post_id, $already_rolled_back_events[0]['data']['post_id'] );

		$success_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ROLLED_BACK',
			)
		);
		$this->assertCount( 0, $success_events );
	}

	/**
	 * Verifies that a failed item rollback (SQL-layer failure) reports the
	 * failure to its caller and emits an ITEM_ROLLBACK_FAILED audit event
	 * with the wpdb error captured.
	 */
	public function test_mark_item_rolled_back_emits_failed_when_update_errors(): void {
		global $wpdb;

		// ARRANGE: Create a session with one item, then force the next UPDATE
		// on the items table to fail at the SQL layer by rewriting it via
		// the 'query' filter. try/finally guarantees filter removal.
		$session_id      = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id         = $this->factory()->post->create( array( 'post_title' => 'Imported Post' ) );
		$item_id         = $this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);
		$items_table     = Import_Items_Table::table_name();
		$filter_callback = function ( string $query ) use ( $items_table ): string {
			if ( 0 === strpos( $query, "UPDATE `{$items_table}`" ) ) {
				return 'UPDATE safe_publish_nonexistent_table_for_test SET x = 1';
			}
			return $query;
		};
		add_filter( 'query', $filter_callback );
		$wpdb->suppress_errors( true );

		try {
			// ACT: Roll back the item.
			$flagged = $this->repository->mark_item_rolled_back( $item_id );

			// ASSERT: The failed write is reported to the caller.
			$this->assertFalse( $flagged );

			// ASSERT: An ITEM_ROLLBACK_FAILED error event was emitted with the
			// item ID, the snapshotted session_id and post_id (SELECT runs
			// before the filtered UPDATE, so these are real values), and a
			// non-empty wpdb_error string.
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'ITEM_ROLLBACK_FAILED',
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( 'error', $events[0]['level'] );
			$this->assertSame( $item_id, $events[0]['data']['item_id'] );
			$this->assertSame( $session_id, $events[0]['data']['session_id'] );
			$this->assertSame( $post_id, $events[0]['data']['post_id'] );
			$this->assertNotEmpty( $events[0]['data']['wpdb_error'] );

			// ASSERT: No success event was recorded.
			$success_events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'ITEM_ROLLED_BACK',
				)
			);
			$this->assertCount( 0, $success_events );
		} finally {
			remove_filter( 'query', $filter_callback );
			$wpdb->suppress_errors( false );
		}
	}

	/**
	 * Verifies that a rollback whose flag write fails is reported as an
	 * error even though the post was reverted.
	 */
	public function test_rollback_item_reports_a_rollback_it_could_not_record(): void {
		global $wpdb;

		// ARRANGE: An updated item, with the next UPDATE on the items table
		// forced to fail at the SQL layer so only the flag write breaks.
		// try/finally guarantees filter removal.
		$session_id      = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id         = $this->factory()->post->create(
			array(
				'post_title'   => 'Updated',
				'post_content' => 'Imported content.',
			)
		);
		$item_id         = $this->repository->log_import_action(
			$session_id,
			1,
			'Updated',
			'updated',
			$post_id,
			null,
			array(
				'previous_content' => 'Old content.',
				'action'           => 'updated_existing',
			)
		);
		$items_table     = Import_Items_Table::table_name();
		$filter_callback = function ( string $query ) use ( $items_table ): string {
			if ( 0 === strpos( $query, "UPDATE `{$items_table}`" ) ) {
				return 'UPDATE safe_publish_nonexistent_table_for_test SET x = 1';
			}
			return $query;
		};
		add_filter( 'query', $filter_callback );
		$wpdb->suppress_errors( true );

		try {
			// ACT: Roll the item back.
			$result = $this->rollback_service->rollback_item( $item_id );

			// ASSERT: The caller is told the rollback went unrecorded.
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'rollback_not_recorded', $result->get_error_code() );

			// ASSERT: The revert itself still happened.
			$post = get_post( $post_id );
			$this->assertNotNull( $post );
			$this->assertSame( 'Old content.', $post->post_content );

			// ASSERT: The row stayed unflagged, matching what was reported.
			$item = $this->repository->get_item( $item_id );
			$this->assertSame( 0, (int) $item['rolled_back'] );
		} finally {
			remove_filter( 'query', $filter_callback );
			$wpdb->suppress_errors( false );
		}
	}

	/**
	 * Verifies that deleting a session emits a SESSION_DELETED audit event
	 * with the session ID, source site URL snapshot, and items_deleted count.
	 */
	public function test_delete_session_emits_audit_event(): void {
		// ARRANGE: Create a session with two items.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id_1  = $this->factory()->post->create( array( 'post_title' => 'Imported Post 1' ) );
		$post_id_2  = $this->factory()->post->create( array( 'post_title' => 'Imported Post 2' ) );
		$this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post 1',
			'success',
			$post_id_1
		);
		$this->repository->log_import_action(
			$session_id,
			2,
			'Imported Post 2',
			'success',
			$post_id_2
		);

		// ACT: Delete the session.
		$deleted = $this->repository->delete_session( $session_id );

		// ASSERT: The repository reports success.
		$this->assertTrue( $deleted );

		// ASSERT: One SESSION_DELETED event was emitted with the snapshotted
		// source URL and the count of items removed alongside the session.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_DELETED',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'info', $events[0]['level'] );
		$this->assertSame( $session_id, $events[0]['data']['session_id'] );
		$this->assertSame( 'https://example.com', $events[0]['data']['source_site_url'] );
		$this->assertSame( 2, $events[0]['data']['items_deleted'] );
	}

	/**
	 * Verifies that attempting to delete a non-existent session does not emit
	 * a SESSION_DELETED audit event.
	 */
	public function test_delete_session_emits_no_event_when_session_missing(): void {
		// ARRANGE: A session ID that does not exist.
		$nonexistent_id = 9999999;

		// ACT: Try to delete it.
		$deleted = $this->repository->delete_session( $nonexistent_id );

		// ASSERT: The repository reports no deletion happened.
		$this->assertFalse( $deleted );

		// ASSERT: No SESSION_DELETED event was emitted.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_DELETED',
			)
		);
		$this->assertCount( 0, $events );
	}

	/**
	 * Verifies that a session deletion whose items-table DELETE fails at the
	 * SQL layer emits a SESSION_DELETE_FAILED audit event, bails out before
	 * the imports-table DELETE, and leaves the session row intact.
	 */
	public function test_delete_session_emits_failed_when_items_delete_errors(): void {
		global $wpdb;

		// ARRANGE: Create a session with one item, then force the next DELETE
		// on the items table to fail at the SQL layer by rewriting it to
		// invalid SQL via the 'query' filter. try/finally guarantees filter
		// removal.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id    = $this->factory()->post->create( array( 'post_title' => 'Imported Post' ) );
		$this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);
		$items_table     = Import_Items_Table::table_name();
		$filter_callback = function ( $query ) use ( $items_table ) {
			if ( 0 === strpos( $query, "DELETE FROM `{$items_table}`" ) ) {
				return 'DELETE FROM safe_publish_nonexistent_table_for_test WHERE x = 1';
			}
			return $query;
		};
		add_filter( 'query', $filter_callback );
		$wpdb->suppress_errors( true );

		try {
			// ACT: Attempt to delete the session.
			$deleted = $this->repository->delete_session( $session_id );

			// ASSERT: The repository reports no deletion.
			$this->assertFalse( $deleted );

			// ASSERT: A SESSION_DELETE_FAILED error event was emitted with the
			// session ID, the snapshotted source_site_url, and a non-empty
			// wpdb_error string.
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_DELETE_FAILED',
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( 'error', $events[0]['level'] );
			$this->assertSame( $session_id, $events[0]['data']['session_id'] );
			$this->assertSame( 'https://example.com', $events[0]['data']['source_site_url'] );
			$this->assertNotEmpty( $events[0]['data']['wpdb_error'] );

			// ASSERT: No success event was recorded.
			$success_events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_DELETED',
				)
			);
			$this->assertCount( 0, $success_events );

			// ASSERT: The session row was not deleted (the bail-out preserves
			// it so the caller can retry).
			$session = $this->repository->get_session( $session_id );
			$this->assertNotNull( $session );
		} finally {
			remove_filter( 'query', $filter_callback );
			$wpdb->suppress_errors( false );
		}
	}

	/**
	 * Verifies that a session deletion whose imports-table DELETE fails at the
	 * SQL layer (after the items-table DELETE has already succeeded) emits a
	 * SESSION_DELETE_FAILED audit event.
	 */
	public function test_delete_session_emits_failed_when_imports_delete_errors(): void {
		global $wpdb;

		// ARRANGE: Create a session with one item, then force the next DELETE
		// on the imports table to fail at the SQL layer. The items-table
		// DELETE runs first and succeeds.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id    = $this->factory()->post->create( array( 'post_title' => 'Imported Post' ) );
		$this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$post_id
		);
		$imports_table   = Imports_Table::table_name();
		$filter_callback = function ( $query ) use ( $imports_table ) {
			if ( 0 === strpos( $query, "DELETE FROM `{$imports_table}`" ) ) {
				return 'DELETE FROM safe_publish_nonexistent_table_for_test WHERE x = 1';
			}
			return $query;
		};
		add_filter( 'query', $filter_callback );
		$wpdb->suppress_errors( true );

		try {
			// ACT: Attempt to delete the session.
			$deleted = $this->repository->delete_session( $session_id );

			// ASSERT: The repository reports no deletion.
			$this->assertFalse( $deleted );

			// ASSERT: A SESSION_DELETE_FAILED error event was emitted with the
			// session ID, the snapshotted source_site_url, and a non-empty
			// wpdb_error string.
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_DELETE_FAILED',
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( 'error', $events[0]['level'] );
			$this->assertSame( $session_id, $events[0]['data']['session_id'] );
			$this->assertSame( 'https://example.com', $events[0]['data']['source_site_url'] );
			$this->assertNotEmpty( $events[0]['data']['wpdb_error'] );

			// ASSERT: No success event was recorded.
			$success_events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_DELETED',
				)
			);
			$this->assertCount( 0, $success_events );
		} finally {
			remove_filter( 'query', $filter_callback );
			$wpdb->suppress_errors( false );
		}
	}
}
