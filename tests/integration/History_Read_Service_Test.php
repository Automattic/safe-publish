<?php
/**
 * Integration tests for history reads and repository compatibility.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\History_Read_Service;
use Safe_Publish\Admin\History_Repository;
use WP_Error;

/**
 * History read service tests.
 */
class History_Read_Service_Test extends Integration_Test_Case {

	/**
	 * Verifies that reads preserve counts, row types, payloads and item order.
	 */
	public function test_reads_and_repository_parity(): void {
		// ARRANGE: Mix outcomes and retain a rollback payload in one session.
		$repository = new History_Repository();
		$service    = new History_Read_Service();
		$session_id = $repository->create_session( 'https://example.com' );
		$this->assertIsInt( $session_id );
		$ids = array();
		foreach ( array( 'success', 'updated', 'error' ) as $status ) {
			$ids[] = $repository->log_import_action(
				$session_id,
				17,
				'History fixture',
				$status,
				23,
				'error' === $status ? 'Fixture failure' : null,
				array( 'previous_content' => 'Original content' )
			);
		}
		foreach ( $ids as $id ) {
			$this->assertIsInt( $id );
		}
		$other_session = $repository->create_session(
			'https://example.com/blog'
		);
		$this->assertIsInt( $other_session );
		$repository->log_import_action(
			$other_session,
			18,
			'Other session',
			'success',
			24,
			null,
			array()
		);

		// ACT: Read the session, its lightweight items and a full item.
		$session = $service->get_session(
			array( 'session_id' => $session_id )
		);
		$items   = $service->get_session_items(
			array( 'session_id' => $session_id )
		);
		$item    = $service->get_item( array( 'item_id' => $ids[1] ) );

		// ASSERT: Counts exclude other sessions and retain database strings.
		$this->assertIsArray( $session );
		$this->assertSame( '3', $session['total_items'] );
		$this->assertSame( '2', $session['successful'] );
		$this->assertSame( '1', $session['updated'] );
		$this->assertSame( '1', $session['failed'] );
		$this->assertSame( 'https://example.com', $session['source_site_url'] );
		$this->assertSame( $session, $repository->get_session( $session_id ) );
		$this->assertIsArray( $items );
		$this->assertSame(
			$ids,
			array_map( 'intval', array_column( $items, 'id' ) )
		);
		foreach ( $items as $row ) {
			$this->assertArrayNotHasKey( 'content_changes', $row );
			$this->assertSame( '1', $row['has_previous_content'] );
		}
		$this->assertSame(
			$items,
			$repository->get_session_items( $session_id )
		);
		$this->assertIsArray( $item );
		$this->assertSame( 'updated', $item['status'] );
		$this->assertSame(
			array( 'previous_content' => 'Original content' ),
			json_decode( $item['content_changes'], true )
		);
		$this->assertSame( $item, $repository->get_item( $ids[1] ) );
	}

	/**
	 * Verifies that missing rows preserve repository empty results.
	 */
	public function test_missing_rows(): void {
		// ARRANGE: Use an ID outside the fixture range.
		$repository = new History_Repository();
		$service    = new History_Read_Service();

		// ACT: Read missing rows through the service and legacy API.
		$session = $service->get_session( array( 'session_id' => 999999 ) );
		$item    = $service->get_item( array( 'item_id' => 999999 ) );

		// ASSERT: The new error contract maps to the old null/empty results.
		$this->assertInstanceOf( WP_Error::class, $session );
		$this->assertSame( 'session_not_found', $session->get_error_code() );
		$this->assertSame( 'Import session not found', $session->get_error_message() );
		$this->assertInstanceOf( WP_Error::class, $item );
		$this->assertSame( 'item_not_found', $item->get_error_code() );
		$this->assertSame( 'Import item not found', $item->get_error_message() );
		$this->assertNull( $repository->get_session( 999999 ) );
		$this->assertNull( $repository->get_item( 999999 ) );
		$this->assertSame( array(), $repository->get_session_items( 999999 ) );
	}

	/**
	 * Verifies that session zero retains rows accepted by the existing writer.
	 */
	public function test_zero_session_items(): void {
		// ARRANGE: The writer and schema permit orphan items with session zero.
		$repository = new History_Repository();
		$id         = $repository->log_import_action(
			0,
			17,
			'Orphan fixture',
			'success',
			23
		);
		$this->assertIsInt( $id );

		// ACT: Read the orphan through both public APIs.
		$items = ( new History_Read_Service() )->get_session_items(
			array( 'session_id' => 0 )
		);

		// ASSERT: The actual stored row remains visible without coercing types.
		$this->assertIsArray( $items );
		$this->assertCount( 1, $items );
		$this->assertSame( (string) $id, $items[0]['id'] );
		$this->assertSame( '0', $items[0]['session_id'] );
		$this->assertSame( 'Orphan fixture', $items[0]['title'] );
		$this->assertSame( $items, $repository->get_session_items( 0 ) );
	}

	/**
	 * Verifies that existing post and failure reads retain source isolation.
	 */
	public function test_post_and_failure_reads(): void {
		// ARRANGE: Two sources share source IDs; one row is rolled back.
		$repository = new History_Repository();
		$service    = new History_Read_Service();
		$source     = array( 'source_site_url' => 'https://example.com' );
		$session    = $repository->create_session( $source['source_site_url'] );
		$other      = $repository->create_session( 'https://example.com/blog' );
		$this->assertIsInt( $session );
		$this->assertIsInt( $other );
		$post = self::factory()->post->create();
		$this->assertIsInt( $post );
		$first       = $repository->log_import_action(
			$session,
			17,
			'First',
			'success',
			$post
		);
		$latest      = $repository->log_import_action(
			$session,
			17,
			'Latest',
			'updated',
			$post
		);
		$rolled_back = $repository->log_import_action(
			$session,
			17,
			'Rolled back',
			'updated',
			$post
		);
		$this->assertIsInt( $first );
		$this->assertIsInt( $latest );
		$this->assertIsInt( $rolled_back );
		$repository->mark_item_rolled_back( $rolled_back );
		$other_item = $repository->log_import_action(
			$other,
			17,
			'Other',
			'success',
			$post
		);
		$this->assertIsInt( $other_item );
		$failure = $repository->log_import_action(
			$session,
			18,
			'Failure',
			'error',
			null,
			'Fixture error'
		);
		$this->assertIsInt( $failure );

		// ACT: Read source-scoped active rows and failures.
		$active   = $service->get_active_items_by_source_ids(
			$source + array( 'source_ids' => array( 17 ) )
		);
		$listed   = $service->list_imported_source_rows( $source );
		$failures = $service->list_failures(
			$source + array(
				'offset' => -1,
				'limit'  => 10,
			)
		);

		$item  = $service->get_item_for_post( array( 'post_id' => $post ) );
		$posts = $service->get_items_for_posts(
			array( 'post_ids' => array( $post ) )
		);

		// ASSERT: Latest active rows and failure projections match stored data.
		$this->assertIsArray( $active );
		$this->assertSame( array( 17 ), array_keys( $active ) );
		$this->assertSame( (string) $latest, $active[17]['id'] );
		$this->assertIsArray( $listed );
		$this->assertSame( array( (string) $latest ), array_column( $listed, 'id' ) );
		$this->assertIsArray( $failures );
		$this->assertSame( array( (string) $failure ), array_column( $failures, 'id' ) );
		$this->assertSame( 'Fixture error', $failures[0]['error_message'] );
		$this->assertSame( array( 'count' => 1 ), $service->count_failures( $source ) );
		$this->assertSame( 1, $repository->count_failures( $source['source_site_url'] ) );
		$this->assertIsArray( $item );
		$this->assertSame( (string) $other_item, $item['id'] );
		$this->assertIsArray( $posts );
		$this->assertSame( array( $post ), array_keys( $posts ) );
		$this->assertSame( (string) $other_item, $posts[ $post ]['id'] );
		$this->assertSame(
			$repository->get_item_for_post( $post ),
			$service->get_item_for_post( array( 'post_id' => $post ) )
		);
		$this->assertSame(
			$repository->get_items_for_posts( array( $post ) ),
			$service->get_items_for_posts( array( 'post_ids' => array( $post ) ) )
		);
	}
}
