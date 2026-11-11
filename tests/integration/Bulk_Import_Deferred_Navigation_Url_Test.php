<?php
/**
 * Bulk import deferred navigation URL integration test
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Attention_Issues_Repository;
use Safe_Publish\Utils\Options;
use WP_Ajax_UnitTestCase;

/**
 * Exercises deferred navigation URL warnings through bulk import.
 */
class Bulk_Import_Deferred_Navigation_Url_Test extends WP_Ajax_UnitTestCase {

	use Ajax_Die_Continue_Trait;
	use Per_Source_Id_Post_Api_Mock_Trait;
	use Bulk_Import_Ajax_Trait;

	/**
	 * Sets up the bulk-import harness against the single-site connection.
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
	 * Builds the REST body for the requested source post.
	 *
	 * @param int $source_id Source post ID parsed from the request URL.
	 * @return array<string, mixed>|null Mock body, or null when not mocked.
	 */
	#[\Override]
	protected function mock_body_for_source_id( int $source_id ): ?array {
		if ( ! isset( $this->source_payloads[ $source_id ] ) ) {
			return null;
		}

		return $this->bulk_mock_body(
			$source_id,
			'https://source.example.com'
		);
	}

	/**
	 * Verifies that bulk results expose a deferred URL warning and issue.
	 */
	public function test_bulk_import_reports_deferred_navigation_url(): void {
		// ARRANGE: A source link points to a mapped draft destination page.
		$target = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$this->assertIsInt( $target );
		update_post_meta( $target, Options::META_SOURCE_POST_ID, 99010 );
		update_post_meta(
			$target,
			Options::META_SOURCE_SITE_URL,
			'https://source.example.com'
		);
		$link                        = wp_json_encode(
			array(
				'id'   => 99010,
				'kind' => 'post-type',
				'type' => 'page',
				'url'  => 'https://source.example.com/about',
			)
		);
		$this->source_payloads[5000] = array(
			'content' => '<!-- wp:navigation -->'
				. '<!-- wp:navigation-link ' . $link . ' /-->'
				. '<!-- /wp:navigation -->',
		);
		$entry                       = array(
			'id'        => 5000,
			'title'     => 'Source Post 5000',
			'link'      => 'https://source.example.com/post-5000',
			'post_type' => 'pages',
		);

		// ACT: Import the referring page through the bulk AJAX action.
		$data = $this->dispatch_bulk_import( array( $entry ) );

		// ASSERT: The result warns and Needs attention tracks the mapped link.
		$this->assertSame( 1, $data['successful'] );
		$result = $data['results'][0];
		$this->assertSame(
			array(
				array(
					'type'      => 'deferred_navigation_url',
					'source_id' => 99010,
				),
			),
			$result['warnings']
		);
		$post_id = (int) $result['post_id'];
		$this->assertNotNull(
			( new Attention_Issues_Repository() )->get_issue(
				$post_id,
				'deferred_navigation_url',
				99010,
				'post'
			)
		);
		$this->assertStringContainsString(
			'"id":' . $target,
			(string) get_post_field( 'post_content', $post_id )
		);
	}
}
