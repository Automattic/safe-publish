<?php
/**
 * Bulk import fetch-failure surfacing integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use WP_Ajax_UnitTestCase;

/**
 * Bulk Import Fetch Failure Test.
 *
 * Confirms the bulk path surfaces the specific fetch-failure reason per item,
 * matching the single import path.
 */
class Bulk_Import_Fetch_Failure_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;
	use Per_Source_Id_Post_Api_Mock_Trait;
	use Bulk_Import_Ajax_Trait;

	/**
	 * Source ID whose REST body omits the raw edit-context fields.
	 */
	private const RAW_MISSING_SOURCE_ID = 4242;

	/**
	 * Sets up the bulk-import harness.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->set_up_bulk_import_harness( 'https://source.example.com' );
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
	 * Returns a source body missing the raw edit-context fields, so the fetch
	 * fails with the raw-fields-missing reason.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		if ( self::RAW_MISSING_SOURCE_ID !== $source_id ) {
			return null;
		}

		return array(
			'id'      => $source_id,
			'title'   => array( 'rendered' => 'Rendered Title' ),
			'content' => array( 'rendered' => '<p>Rendered content.</p>' ),
			'excerpt' => array( 'rendered' => '<p>Rendered excerpt.</p>' ),
			'link'    => 'https://source.example.com/post-' . $source_id,
			'meta'    => array(),
		);
	}

	/**
	 * Verifies that a bulk item whose source response lacks the raw
	 * edit-context fields fails with the specific raw-fields reason, matching
	 * the single import path's error text.
	 */
	public function test_bulk_item_surfaces_raw_fields_missing_reason(): void {
		// ARRANGE: A single bulk entry whose source body omits raw fields.
		$posts_data = array(
			array(
				'id'        => self::RAW_MISSING_SOURCE_ID,
				'title'     => 'Rendered Title',
				'link'      => 'https://source.example.com/post-'
					. self::RAW_MISSING_SOURCE_ID,
				'post_type' => 'pages',
			),
		);

		// ACT: Dispatch the bulk import.
		$data = $this->dispatch_bulk_import( $posts_data );

		// ASSERT: The item failed and surfaced the specific raw-fields reason
		// rather than a generic fetch-failed message.
		$this->assertSame( 0, $data['successful'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertFalse( $data['results'][0]['success'] );
		$this->assertStringContainsString(
			'missing raw content fields',
			$data['results'][0]['error']
		);
	}
}
