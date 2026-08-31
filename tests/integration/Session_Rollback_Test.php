<?php
/**
 * Integration tests for session rollback functionality
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
	 * Verifies that rolling back a complete session deletes imported posts.
	 */
	public function test_rollback_session_deletes_imported_posts(): void {
		// ARRANGE: Create session and import posts.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		// Create actual WordPress posts.
		$post_id_1 = $this->factory()->post->create( array( 'post_title' => 'Imported Post 1' ) );
		$post_id_2 = $this->factory()->post->create( array( 'post_title' => 'Imported Post 2' ) );

		// Log the imports.
		$item_id_1 = $this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post 1',
			'success',
			$post_id_1
		);

		$item_id_2 = $this->repository->log_import_action(
			$session_id,
			2,
			'Imported Post 2',
			'success',
			$post_id_2
		);

		$this->repository->complete_session( $session_id );

		// ASSERT: Verify posts exist.
		$this->assertNotNull( get_post( $post_id_1 ) );
		$this->assertNotNull( get_post( $post_id_2 ) );

		// ACT: Rollback the session.
		$result = $this->rollback_service->rollback_session( $session_id );

		// ASSERT: Posts were deleted with no failures.
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['deleted_count'] );
		$this->assertSame( 0, $result['failed_count'] );

		// ASSERT: Verify posts are deleted.
		$this->assertNull( get_post( $post_id_1 ) );
		$this->assertNull( get_post( $post_id_2 ) );

		// ASSERT: Verify session marked as rolled back.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 'rolled_back', $session['status'] );

		// ASSERT: Each item row is flagged so the per-item rolled_back state
		// stays consistent with the item-level rollback path.
		$item_1 = $this->repository->get_item( $item_id_1 );
		$item_2 = $this->repository->get_item( $item_id_2 );
		$this->assertSame( 1, (int) $item_1['rolled_back'] );
		$this->assertSame( 1, (int) $item_2['rolled_back'] );
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
	 * Verifies that a session rollback deletes the plugin-imported media each
	 * rolled-back post owns.
	 */
	public function test_rollback_session_deletes_owned_imported_media(): void {
		// ARRANGE: Two imported posts, each owning a plugin-imported
		// attachment.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_1     = $this->factory()->post->create( array( 'post_title' => 'Post 1' ) );
		$post_2     = $this->factory()->post->create( array( 'post_title' => 'Post 2' ) );
		$media_1    = $this->seed_imported_attachment( $post_1 );
		$media_2    = $this->seed_imported_attachment( $post_2 );
		$this->repository->log_import_action( $session_id, 1, 'Post 1', 'success', $post_1 );
		$this->repository->log_import_action( $session_id, 2, 'Post 2', 'success', $post_2 );
		$this->repository->complete_session( $session_id );

		// ACT: Roll back the whole session.
		$result = $this->rollback_service->rollback_session( $session_id );

		// ASSERT: Both posts and both owned attachments are gone.
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['deleted_count'] );
		$this->assertNull( get_post( $media_1 ) );
		$this->assertNull( get_post( $media_2 ) );
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
	 * Verifies that rollback rejects malformed references before changing the
	 * post.
	 */
	public function test_restore_previous_version_refuses_malformed_references(): void {
		// ARRANGE: Build representative malformed snapshot values.
		$cases = array(
			'malformed terms'          => array(
				array(
					'previous_terms' => array( 'category' => 'invalid' ),
				),
				'invalid_term_assignment_snapshot',
			),
			'malformed term ID'        => array(
				array( 'previous_terms' => array( 'category' => array( '12' ) ) ),
				'invalid_term_assignment_snapshot',
			),
			'malformed author'         => array(
				array( 'previous_author' => '12' ),
				'invalid_rollback_snapshot',
			),
			'malformed parent'         => array(
				array( 'previous_parent' => -1 ),
				'invalid_rollback_snapshot',
			),
			'malformed post type'      => array(
				array( 'previous_post_type' => array() ),
				'invalid_rollback_snapshot',
			),
			'malformed featured image' => array(
				array( 'previous_featured_image' => array() ),
				'invalid_rollback_snapshot',
			),
			'negative featured image'  => array(
				array( 'previous_featured_image' => -1 ),
				'invalid_rollback_snapshot',
			),
		);

		foreach ( $cases as $case_name => [ $changes, $error_code ] ) {
			$history = $this->create_updated_item( $changes );

			// ACT: Attempt to restore the invalid snapshot.
			$result = $this->rollback_service->rollback_item( $history['item_id'] );

			// ASSERT: Validation rejects it without changing durable state.
			$this->assertInstanceOf( WP_Error::class, $result, $case_name );
			$this->assertSame(
				$error_code,
				$result->get_error_code(),
				$case_name
			);
			$this->assertSame(
				'Current content.',
				get_post_field( 'post_content', $history['post_id'] ),
				$case_name
			);
			$item = $this->repository->get_item( $history['item_id'] );
			$this->assertSame( 0, (int) $item['rolled_back'], $case_name );
		}
	}

	/**
	 * Verifies that unavailable references are retained and recorded as
	 * omissions while available state is restored.
	 */
	public function test_restore_previous_version_records_unavailable_references_as_omissions(): void {
		// ARRANGE: A snapshot containing unavailable references alongside one
		// fully restorable taxonomy.
		register_taxonomy( 'sp_atomic_taxonomy', 'post' );
		register_taxonomy( '123', 'post' );

		try {
			$deleted_author = $this->factory()->user->create();
			$deleted_parent = $this->factory()->post->create();
			$previous_image = $this->factory()->attachment->create(
				array( 'post_mime_type' => 'application/pdf' )
			);
			$deleted_term   = wp_insert_term( 'Deleted Term', 'sp_atomic_taxonomy' );
			$current_term   = wp_insert_term( 'Current Term', 'sp_atomic_taxonomy' );
			$previous_term  = wp_insert_term( 'Previous Term', '123' );
			$current_other  = wp_insert_term( 'Current Other', '123' );
			$this->assertIsArray( $deleted_term );
			$this->assertIsArray( $current_term );
			$this->assertIsArray( $previous_term );
			$this->assertIsArray( $current_other );
			wp_delete_user( $deleted_author );
			wp_delete_post( $deleted_parent, true );
			wp_delete_term( (int) $deleted_term['term_id'], 'sp_atomic_taxonomy' );

			$history        = $this->create_updated_item(
				array(
					'previous_terms'          => array(
						'sp_missing_taxonomy' => array(),
						'sp_atomic_taxonomy'  => array( (int) $deleted_term['term_id'] ),
						'123'                 => array( (int) $previous_term['term_id'] ),
					),
					'previous_author'         => $deleted_author,
					'previous_parent'         => $deleted_parent,
					'previous_post_type'      => 'sp_missing_type',
					'previous_featured_image' => $previous_image,
				)
			);
			$current_author = $this->factory()->user->create();
			$current_parent = $this->factory()->post->create();
			$current_image  = $this->factory()->attachment->create();
			$this->assertIsInt( $current_author );
			$this->assertIsInt( $current_parent );
			wp_update_post(
				array(
					'ID'          => $history['post_id'],
					'post_author' => $current_author,
					'post_parent' => $current_parent,
				)
			);
			update_post_meta( $history['post_id'], '_thumbnail_id', $current_image );
			wp_set_object_terms(
				$history['post_id'],
				array( (int) $current_term['term_id'] ),
				'sp_atomic_taxonomy'
			);
			wp_set_object_terms(
				$history['post_id'],
				array( (int) $current_other['term_id'] ),
				'123'
			);

			// ACT: Restore every available part of the session item.
			$result = $this->rollback_service->rollback_session( $history['session_id'] );

			// ASSERT: The rollback succeeds, retaining each unavailable value and
			// restoring the complete available taxonomy.
			$this->assertIsArray( $result );
			$this->assertSame( 1, $result['restored_count'] );
			$this->assertSame( 0, $result['failed_count'] );
			$this->assertSame( 6, $result['omission_count'] );
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'ITEM_ROLLED_BACK_WITH_OMISSIONS',
				)
			);
			$this->assertCount( 1, $events );
			$omissions = $events[0]['data']['omissions'];
			$this->assertSame(
				array(
					'term_assignments',
					'term_assignments',
					'author',
					'parent',
					'post_type',
					'featured_image',
				),
				array_column( $omissions, 'field' )
			);
			$this->assertSame( 'sp_missing_taxonomy', $omissions[0]['taxonomy'] );
			$this->assertSame(
				array( (int) $deleted_term['term_id'] ),
				$omissions[1]['term_ids']
			);
			$this->assertSame( $deleted_author, $omissions[2]['id'] );
			$this->assertSame( $deleted_parent, $omissions[3]['id'] );
			$this->assertSame( 'sp_missing_type', $omissions[4]['slug'] );
			$this->assertSame( $previous_image, $omissions[5]['id'] );
			$this->assertSame(
				array(
					'taxonomy_unavailable',
					'term_unavailable',
					'reference_unavailable',
					'reference_unavailable',
					'reference_unavailable',
					'reference_unavailable',
				),
				array_column( $omissions, 'reason' )
			);
			$post = get_post( $history['post_id'] );
			$this->assertSame( 'Previous content.', $post->post_content );
			$this->assertSame( $current_author, (int) $post->post_author );
			$this->assertSame( $current_parent, (int) $post->post_parent );
			$this->assertSame( 'post', $post->post_type );
			$this->assertSame(
				$current_image,
				(int) get_post_meta( $history['post_id'], '_thumbnail_id', true )
			);
			$this->assertSame(
				array( (int) $current_term['term_id'] ),
				wp_get_object_terms(
					$history['post_id'],
					'sp_atomic_taxonomy',
					array( 'fields' => 'ids' )
				)
			);
			$this->assertSame(
				array( (int) $previous_term['term_id'] ),
				wp_get_object_terms(
					$history['post_id'],
					'123',
					array( 'fields' => 'ids' )
				)
			);
			$item = $this->repository->get_item( $history['item_id'] );
			$this->assertSame( 1, (int) $item['rolled_back'] );

			// ASSERT: The durable audit event identifies the affected item.
			$this->assertSame( 'warning', $events[0]['level'] );
			$this->assertSame( $history['item_id'], $events[0]['data']['item_id'] );
			$this->assertSame( $history['session_id'], $events[0]['data']['session_id'] );
			$this->assertSame( $history['post_id'], $events[0]['data']['post_id'] );
			$this->assertSame( 1, $events[0]['data']['omissions_version'] );
		} finally {
			unregister_taxonomy( 'sp_atomic_taxonomy' );
			unregister_taxonomy( '123' );
		}
	}

	/**
	 * Verifies that a legacy update without a previous-content snapshot keeps
	 * its deletion fallback.
	 */
	public function test_legacy_update_without_snapshot_deletes_the_post(): void {
		// ARRANGE: A legacy updated row has no durable snapshot to restore.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$post_id    = $this->factory()->post->create();
		$item_id    = $this->repository->log_import_action(
			$session_id,
			1,
			'Updated',
			'updated',
			$post_id
		);

		// ACT: Roll back the legacy update.
		$result = $this->rollback_service->rollback_item( $item_id );

		// ASSERT: Existing fallback behavior deletes the post.
		$this->assertIsArray( $result );
		$this->assertSame( 'deleted', $result['action'] );
		$this->assertNull( get_post( $post_id ) );
	}

	/**
	 * Verifies that a term restore failure is reported and remains retryable.
	 */
	public function test_restore_previous_version_reports_term_restore_failure(): void {
		// ARRANGE: A valid term is deleted after preflight, while the post still
		// has its imported term assignment and a later taxonomy can restore.
		register_taxonomy( 'sp_rollback_topic', 'post' );
		register_taxonomy( 'sp_later_taxonomy', 'post' );
		$term           = wp_insert_term( 'Previous Topic', 'sp_rollback_topic' );
		$current_term   = wp_insert_term( 'Current Topic', 'sp_rollback_topic' );
		$previous_later = wp_insert_term( 'Previous Later', 'sp_later_taxonomy' );
		$current_later  = wp_insert_term( 'Current Later', 'sp_later_taxonomy' );

		$this->assertIsArray( $term );
		$this->assertIsArray( $current_term );
		$this->assertIsArray( $previous_later );
		$this->assertIsArray( $current_later );
		$history = $this->create_updated_item(
			array(
				'previous_terms'          => array(
					'sp_rollback_topic' => array( (int) $term['term_id'] ),
					'sp_later_taxonomy' => array( (int) $previous_later['term_id'] ),
				),
				'previous_meta'           => array( 'rollback_meta' => 'Previous metadata.' ),
				'previous_featured_image' => 0,
			)
		);
		update_post_meta( $history['post_id'], 'rollback_meta', 'Imported metadata.' );
		update_post_meta( $history['post_id'], '_thumbnail_id', 12345 );
		wp_set_object_terms(
			$history['post_id'],
			array( (int) $current_term['term_id'] ),
			'sp_rollback_topic'
		);
		wp_set_object_terms(
			$history['post_id'],
			array( (int) $current_later['term_id'] ),
			'sp_later_taxonomy'
		);
		$delete_term = static function () use ( $term ): void {
			wp_delete_term( (int) $term['term_id'], 'sp_rollback_topic' );
		};
		add_action( 'save_post', $delete_term );

		try {
			// ACT: Attempt to restore the previous version.
			$result = $this->rollback_service->rollback_item( $history['item_id'] );

			// ASSERT: The runtime failure is reported and not recorded as success.
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'terms_restore_failed', $result->get_error_code() );
			$this->assertSame(
				'Previous content.',
				get_post_field( 'post_content', $history['post_id'] )
			);
			$this->assertSame(
				'Previous metadata.',
				get_post_meta( $history['post_id'], 'rollback_meta', true )
			);
			$this->assertSame(
				'',
				get_post_meta( $history['post_id'], '_thumbnail_id', true )
			);
			$item = $this->repository->get_item( $history['item_id'] );
			$this->assertSame( 0, (int) $item['rolled_back'] );
			$this->assertSame(
				array( (int) $current_term['term_id'] ),
				wp_get_object_terms(
					$history['post_id'],
					'sp_rollback_topic',
					array( 'fields' => 'ids' )
				)
			);
			$this->assertSame(
				array( (int) $previous_later['term_id'] ),
				wp_get_object_terms(
					$history['post_id'],
					'sp_later_taxonomy',
					array( 'fields' => 'ids' )
				)
			);
		} finally {
			remove_action( 'save_post', $delete_term );
		}
	}

	/**
	 * Creates an updated post and its rollback history item.
	 *
	 * @param array $changes Additional previous state.
	 * @return array{session_id: int, post_id: int, item_id: int} Created IDs.
	 */
	private function create_updated_item( array $changes ): array {
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$post_id    = $this->factory()->post->create(
			array( 'post_content' => 'Current content.' )
		);
		$item_id    = $this->repository->log_import_action(
			$session_id,
			1,
			'Updated',
			'updated',
			$post_id,
			null,
			array( 'previous_content' => 'Previous content.' ) + $changes
		);

		return compact( 'session_id', 'post_id', 'item_id' );
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
	 * Verifies that rollback with nonexistent session returns error.
	 */
	public function test_rollback_nonexistent_session_returns_error(): void {
		// ARRANGE: Use nonexistent session ID.
		$fake_session_id = 999999;

		// ACT: Attempt rollback.
		$result = $this->rollback_service->rollback_session( $fake_session_id );

		// ASSERT: Returns WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'session_not_found', $result->get_error_code() );
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
	 * Verifies that repeating a session rollback skips the items an earlier
	 * run already undid.
	 */
	public function test_rollback_session_skips_already_rolled_back_items(): void {
		// ARRANGE: A session holding one new import and one update, rolled
		// back once, after which newer content landed on the surviving post.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$created_id = $this->factory()->post->create( array( 'post_title' => 'Created' ) );
		$updated_id = $this->factory()->post->create(
			array(
				'post_title'   => 'Updated',
				'post_content' => 'Imported content.',
			)
		);
		$this->repository->log_import_action(
			$session_id,
			1,
			'Created',
			'success',
			$created_id
		);
		$this->repository->log_import_action(
			$session_id,
			2,
			'Updated',
			'updated',
			$updated_id,
			null,
			array(
				'previous_content' => 'Old content.',
				'action'           => 'updated_existing',
			)
		);
		$this->repository->complete_session( $session_id );
		$this->rollback_service->rollback_session( $session_id );

		$newer = 'Content written after the rollback.';
		$this->factory()->post->update_object(
			$updated_id,
			array( 'post_content' => $newer )
		);

		// ACT: Roll the same session back a second time.
		$result = $this->rollback_service->rollback_session( $session_id );

		// ASSERT: Every item was skipped, so nothing changed and nothing failed.
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['deleted_count'] );
		$this->assertSame( 0, $result['restored_count'] );
		$this->assertSame( 0, $result['failed_count'] );

		// ASSERT: The newer content survived.
		$post = get_post( $updated_id );
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
	 * Verifies that rollback only affects successful/updated imports.
	 */
	public function test_rollback_ignores_failed_imports(): void {
		// ARRANGE: Create session with success and failed imports.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );

		$post_id = $this->factory()->post->create( array( 'post_title' => 'Success' ) );

		// Success item.
		$this->repository->log_import_action(
			$session_id,
			1,
			'Success',
			'success',
			$post_id
		);

		// Failed item (no post created).
		$this->repository->log_import_action(
			$session_id,
			2,
			'Failed',
			'error',
			null,
			'Import failed'
		);

		$this->repository->complete_session( $session_id );

		// ACT: Rollback session.
		$result = $this->rollback_service->rollback_session( $session_id );

		// ASSERT: Only the successful import was rolled back; the failed one
		// was ignored.
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['deleted_count'] );
		$this->assertSame( 0, $result['restored_count'] );
		$this->assertSame( 0, $result['failed_count'] );

		// ASSERT: The successfully imported post was deleted.
		$this->assertNull( get_post( $post_id ) );

		// ASSERT: Session is marked as rolled back.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 'rolled_back', $session['status'] );
	}

	/**
	 * Verifies that partial rollback tracks failed count and
	 * does not mark session as rolled back.
	 */
	public function test_partial_rollback_tracks_failures(): void {
		// ARRANGE: Create session with two posts, then delete one
		// before rollback to simulate a failure.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);

		$post_id_1 = $this->factory()->post->create(
			array( 'post_title' => 'Survives' )
		);
		$post_id_2 = $this->factory()->post->create(
			array( 'post_title' => 'Already Gone' )
		);

		$this->repository->log_import_action(
			$session_id,
			1,
			'Survives',
			'success',
			$post_id_1
		);

		$this->repository->log_import_action(
			$session_id,
			2,
			'Already Gone',
			'success',
			$post_id_2
		);

		$this->repository->complete_session( $session_id );

		// ACT: Delete one post before rollback to cause a failure.
		wp_delete_post( $post_id_2, true );
		$result = $this->rollback_service->rollback_session(
			$session_id
		);

		// ASSERT: One succeeded, one failed.
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['deleted_count'] );
		$this->assertSame( 0, $result['restored_count'] );
		$this->assertSame( 1, $result['failed_count'] );

		// ASSERT: Session is NOT marked as rolled back.
		$session = $this->repository->get_session( $session_id );
		$this->assertSame( 'completed', $session['status'] );
	}

	/**
	 * Verifies that a successful session rollback emits a SESSION_ROLLED_BACK
	 * audit event with the session ID.
	 */
	public function test_rollback_session_emits_audit_event(): void {
		// ARRANGE: Create session with a single imported post.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);

		$post_id = $this->factory()->post->create(
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

		// ACT: Roll back the session.
		$this->rollback_service->rollback_session( $session_id );

		// ASSERT: One SESSION_ROLLED_BACK event was emitted with the session ID
		// in its payload.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'info', $events[0]['level'] );
		$this->assertSame( $session_id, $events[0]['data']['session_id'] );
	}

	/**
	 * Verifies that marking an already-rolled-back session emits a
	 * SESSION_ALREADY_ROLLED_BACK event instead of SESSION_ROLLED_BACK.
	 */
	public function test_mark_session_rolled_back_emits_already_rolled_back_when_no_row_changed(): void {
		// ARRANGE: Session that is already in the rolled_back state.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$this->repository->mark_session_rolled_back( $session_id );
		Audit_Log_Table::clear( 'import' );

		// ACT: Mark the session as rolled back again.
		$this->repository->mark_session_rolled_back( $session_id );

		// ASSERT: A SESSION_ALREADY_ROLLED_BACK event was emitted, not a
		// SESSION_ROLLED_BACK event.
		$already_rolled_back_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_ALREADY_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $already_rolled_back_events );
		$this->assertSame( 'info', $already_rolled_back_events[0]['level'] );
		$this->assertSame(
			$session_id,
			$already_rolled_back_events[0]['data']['session_id']
		);

		$success_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_ROLLED_BACK',
			)
		);
		$this->assertCount( 0, $success_events );
	}

	/**
	 * Verifies that a session rollback emits an ITEM_ROLLED_BACK audit event
	 * for every success/updated item it flags, excludes error items, and still
	 * fires the session-level event.
	 */
	public function test_mark_session_rolled_back_emits_item_event_per_flagged_item(): void {
		// ARRANGE: A session with two success items and one error item.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$post_id_1  = $this->factory()->post->create(
			array( 'post_title' => 'Imported Post 1' )
		);
		$post_id_2  = $this->factory()->post->create(
			array( 'post_title' => 'Imported Post 2' )
		);
		$item_id_1  = $this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post 1',
			'success',
			$post_id_1
		);
		$item_id_2  = $this->repository->log_import_action(
			$session_id,
			2,
			'Imported Post 2',
			'success',
			$post_id_2
		);
		$this->repository->log_import_action(
			$session_id,
			3,
			'Failed Post',
			'error',
			null,
			'Import failed'
		);
		$this->repository->complete_session( $session_id );

		// ACT: Roll back the whole session.
		$this->repository->mark_session_rolled_back( $session_id );

		// ASSERT: One ITEM_ROLLED_BACK event per flagged item (two success
		// items, not the error one), each carrying the session ID and the
		// item's own ID and post ID. Order is not asserted because same-second
		// events share a timestamp.
		$events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ROLLED_BACK',
			)
		);
		$this->assertCount( 2, $events );

		$post_by_item = array();
		foreach ( $events as $event ) {
			$this->assertSame( 'info', $event['level'] );
			$this->assertSame( $session_id, $event['data']['session_id'] );
			$post_by_item[ $event['data']['item_id'] ] = $event['data']['post_id'];
		}
		$this->assertArrayHasKey( $item_id_1, $post_by_item );
		$this->assertArrayHasKey( $item_id_2, $post_by_item );
		$this->assertSame( $post_id_1, $post_by_item[ $item_id_1 ] );
		$this->assertSame( $post_id_2, $post_by_item[ $item_id_2 ] );

		// ASSERT: Every flagged item was newly flagged, so none emit the
		// already-rolled-back variant.
		$already = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ALREADY_ROLLED_BACK',
			)
		);
		$this->assertCount( 0, $already );

		// ASSERT: The session-level event still fires alongside the per-item
		// events.
		$session_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $session_events );
		$this->assertSame(
			$session_id,
			$session_events[0]['data']['session_id']
		);
	}

	/**
	 * Verifies that a session rollback emits ITEM_ALREADY_ROLLED_BACK for an
	 * item a prior rollback already flagged, mirroring the item-level path.
	 */
	public function test_mark_session_rolled_back_emits_item_already_rolled_back_for_preflagged_item(): void {
		// ARRANGE: A session whose single success item is already flagged by a
		// prior item-level rollback; clear the log so only the session
		// rollback's events remain.
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

		// ACT: Roll back the whole session.
		$this->repository->mark_session_rolled_back( $session_id );

		// ASSERT: The already-flagged item emits ITEM_ALREADY_ROLLED_BACK with
		// its IDs, not ITEM_ROLLED_BACK.
		$already = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ALREADY_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $already );
		$this->assertSame( 'info', $already[0]['level'] );
		$this->assertSame( $item_id, $already[0]['data']['item_id'] );
		$this->assertSame( $session_id, $already[0]['data']['session_id'] );
		$this->assertSame( $post_id, $already[0]['data']['post_id'] );

		$rolled_back = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'ITEM_ROLLED_BACK',
			)
		);
		$this->assertCount( 0, $rolled_back );

		// ASSERT: The session row still flips fresh, so the session-level event
		// is SESSION_ROLLED_BACK.
		$session_events = Audit_Log_Table::get_events(
			array(
				'channel'    => 'import',
				'event_type' => 'SESSION_ROLLED_BACK',
			)
		);
		$this->assertCount( 1, $session_events );
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
	 * Verifies that a failed session rollback (SQL-layer failure) emits a
	 * SESSION_ROLLBACK_FAILED audit event with the wpdb error captured.
	 */
	public function test_mark_session_rolled_back_emits_failed_when_update_errors(): void {
		global $wpdb;

		// ARRANGE: Create a session, then force the next UPDATE on the
		// imports table to fail at the SQL layer by rewriting it to invalid
		// SQL via the 'query' filter. try/finally guarantees filter removal.
		$session_id = $this->repository->create_session( 'https://example.com', 'bulk' );
		$post_id    = $this->factory()->post->create();
		$item_id    = $this->repository->log_import_action(
			$session_id,
			1,
			'Updated',
			'updated',
			$post_id
		);
		$this->assertIsInt( $item_id );
		$omissions       = array( $item_id => array( array( 'field' => 'author' ) ) );
		$imports_table   = Imports_Table::table_name();
		$filter_callback = function ( string $query ) use ( $imports_table ): string {
			if ( 0 === strpos( $query, "UPDATE `{$imports_table}`" ) ) {
				return 'UPDATE safe_publish_nonexistent_table_for_test SET x = 1';
			}
			return $query;
		};
		add_filter( 'query', $filter_callback );
		$wpdb->suppress_errors( true );

		try {
			// ACT: Roll back the session.
			$this->repository->mark_session_rolled_back( $session_id, $omissions );

			// ASSERT: A SESSION_ROLLBACK_FAILED error event was emitted with
			// the session ID and a non-empty wpdb_error string.
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_ROLLBACK_FAILED',
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( 'error', $events[0]['level'] );
			$this->assertSame( $session_id, $events[0]['data']['session_id'] );
			$this->assertNotEmpty( $events[0]['data']['wpdb_error'] );

			// ASSERT: No success event was recorded.
			$success_events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_ROLLED_BACK',
				)
			);
			$this->assertCount( 0, $success_events );

			// ASSERT: The already-durable item flag keeps its omission warning.
			$omission_events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'ITEM_ROLLED_BACK_WITH_OMISSIONS',
				)
			);
			$this->assertCount( 1, $omission_events );
			$this->assertSame( $item_id, $omission_events[0]['data']['item_id'] );
		} finally {
			remove_filter( 'query', $filter_callback );
			$wpdb->suppress_errors( false );
		}
	}

	/**
	 * Verifies that a failed bulk items UPDATE inside
	 * mark_session_rolled_back() emits SESSION_ROLLBACK_FAILED and leaves
	 * the session row untouched so a retry can heal the partial rollback.
	 */
	public function test_mark_session_rolled_back_emits_failed_when_items_update_errors(): void {
		global $wpdb;

		// ARRANGE: Create a session with a success item, then force the bulk
		// UPDATE on the items table to fail at the SQL layer by rewriting it
		// via the 'query' filter. try/finally guarantees filter removal.
		$session_id = $this->repository->create_session(
			'https://example.com',
			'bulk'
		);
		$this->repository->log_import_action(
			$session_id,
			1,
			'Imported Post',
			'success',
			$this->factory()->post->create()
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
			// ACT: Roll back the session; the items UPDATE fails first.
			$this->repository->mark_session_rolled_back( $session_id );

			// ASSERT: A SESSION_ROLLBACK_FAILED error event was emitted with
			// the session ID and a non-empty wpdb_error string.
			$events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_ROLLBACK_FAILED',
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( 'error', $events[0]['level'] );
			$this->assertSame( $session_id, $events[0]['data']['session_id'] );
			$this->assertNotEmpty( $events[0]['data']['wpdb_error'] );

			// ASSERT: The session row was not flipped — the early return
			// must run before the session UPDATE so a retry can heal the
			// partial rollback.
			$session = $this->repository->get_session( $session_id );
			$this->assertSame( 'in_progress', $session['status'] );

			// ASSERT: No success event was recorded.
			$success_events = Audit_Log_Table::get_events(
				array(
					'channel'    => 'import',
					'event_type' => 'SESSION_ROLLED_BACK',
				)
			);
			$this->assertCount( 0, $success_events );
		} finally {
			remove_filter( 'query', $filter_callback );
			$wpdb->suppress_errors( false );
		}
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
