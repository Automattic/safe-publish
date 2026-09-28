<?php
/**
 * Bulk import behavior when a trashed post still claims a source identity
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Options;
use WP_Ajax_UnitTestCase;

/**
 * Bulk Import Trashed Claim Test.
 *
 * Confirms a refused item is one reported failure inside a batch that
 * otherwise completes, rather than aborting the run.
 */
class Bulk_Import_Trashed_Claim_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;
	use Per_Source_Id_Post_Api_Mock_Trait;
	use Bulk_Import_Ajax_Trait;

	/**
	 * Connected source site for every batch in this class.
	 */
	private const CONNECTION = 'https://source.example.com';

	/**
	 * Source ID whose destination post gets trashed between batches.
	 */
	private const TRASHED_SOURCE_ID = 9101;

	/**
	 * Source ID imported for the first time in the second batch.
	 */
	private const FRESH_SOURCE_ID = 9102;

	/**
	 * Sets up the bulk-import harness.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->set_up_bulk_import_harness( self::CONNECTION );
	}

	/**
	 * Tears down the bulk-import harness.
	 */
	#[\Override]
	protected function tearDown(): void {
		$this->tear_down_bulk_import_harness();
		parent::tearDown();
	}

	/**
	 * Serves the shared mock body for every source ID under test.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		return $this->bulk_mock_body( $source_id, self::CONNECTION );
	}

	/**
	 * Verifies that an item refused over a trashed claim counts as one failure
	 * while the rest of the batch still imports.
	 */
	public function test_refused_item_is_one_failure_in_a_completing_batch(): void {
		// ARRANGE: Import one source post, then trash its destination.
		$first = $this->dispatch_bulk_import(
			array( $this->batch_item( self::TRASHED_SOURCE_ID ) )
		);
		$this->assertSame( 1, $first['successful'] );

		$trashed_id = (int) $first['results'][0]['post_id'];
		wp_trash_post( $trashed_id );
		$this->assertSame( 'trash', get_post_status( $trashed_id ) );

		// ACT: Run a batch pairing that source post with an unimported one.
		// Responses accumulate across dispatches, so clear the first one.
		$this->_last_response = '';

		$data = $this->dispatch_bulk_import(
			array(
				$this->batch_item( self::TRASHED_SOURCE_ID ),
				$this->batch_item( self::FRESH_SOURCE_ID ),
			)
		);

		// ASSERT: The batch completed with one refusal and one import.
		$this->assertSame( 1, $data['successful'] );
		$this->assertSame( 1, $data['failed'] );

		$refused = $this->result_for( $data, self::TRASHED_SOURCE_ID );
		$this->assertFalse( $refused['success'] );
		$this->assertStringContainsString(
			'still linked to this source post',
			$refused['error']
		);

		$imported = $this->result_for( $data, self::FRESH_SOURCE_ID );
		$this->assertTrue( $imported['success'] );

		// ASSERT: The refusal created no second claim, which is the point of
		// refusing rather than importing.
		$this->assertSame(
			array( $trashed_id ),
			$this->claiming_post_ids( self::TRASHED_SOURCE_ID ),
			'The trashed post must remain the only claim.'
		);
	}

	/**
	 * Returns every post claiming a source ID, ascending, without relying on
	 * the lookups under test.
	 *
	 * @param int $source_id Source post ID to count claims for.
	 * @return int[] Claiming post IDs.
	 */
	private function claiming_post_ids( int $source_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value = %s
				 ORDER BY post_id ASC",
				Options::META_SOURCE_POST_ID,
				(string) $source_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Picks one source post's result out of a batch response, since the bulk
	 * handler reorders items before importing them.
	 *
	 * @param array $data      Decoded bulk-import response data.
	 * @param int   $source_id Source post ID to look up.
	 * @return array<string, mixed> That item's result.
	 */
	private function result_for( array $data, int $source_id ): array {
		foreach ( $data['results'] as $result ) {
			if ( $source_id === (int) $result['source_post_id'] ) {
				return $result;
			}
		}

		$this->fail( "No batch result for source post {$source_id}." );
	}

	/**
	 * Builds one bulk request item for a source ID.
	 *
	 * @param int $source_id Source post ID.
	 * @return array<string, mixed> Request item.
	 */
	private function batch_item( int $source_id ): array {
		return array(
			'id'        => $source_id,
			'title'     => "Source Post {$source_id}",
			'link'      => self::CONNECTION . "/post-{$source_id}",
			'post_type' => 'pages',
		);
	}
}
