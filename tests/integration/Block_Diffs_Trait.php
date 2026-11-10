<?php
/**
 * Shared harness for block diff tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Diff_Renderer;
use Safe_Publish\API\Source_Post_Type_Resolver;
use WP_REST_Request;

/**
 * Renders the block diffs of a local post against mocked source content.
 */
trait Block_Diffs_Trait {

	// Distinct from the example.com URLs the fixtures embed, so the import
	// rewrite leaves them alone and each case turns on its markup.
	private const SOURCE         = 'https://source.example.com';
	private const SOURCE_POST_ID = 123;

	/**
	 * Local post the diff runs against.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Creates the local post the diff runs against, imported from the
	 * connected source.
	 */
	private function set_up_diff_post(): void {
		// Catalog lookups are memoized per process, so another test's mocked
		// catalog for the same source cannot leak in.
		Source_Post_Type_Resolver::reset_cache();

		$this->post_id = $this->factory()->post->create(
			array( 'post_status' => 'draft' )
		);
		update_post_meta(
			$this->post_id,
			'safe_publish_source_post_id',
			self::SOURCE_POST_ID
		);
		update_post_meta(
			$this->post_id,
			'safe_publish_source_site_url',
			self::SOURCE
		);
		update_option( 'safe_publish_connected_site_url', self::SOURCE );
	}

	/**
	 * Renders the diff for a local/source content pair and returns its block
	 * diffs.
	 *
	 * @psalm-suppress InvalidArgument
	 *
	 * @param string $local  Local post content.
	 * @param string $source Source post content.
	 * @return array<int, array<string, mixed>> Block diff entries.
	 */
	private function render_block_diffs(
		string $local,
		string $source
	): array {
		wp_update_post(
			array(
				'ID'           => $this->post_id,
				'post_content' => $local,
			)
		);

		$make_request = static function ( $url ) use ( $source ) {
			if ( str_contains( (string) $url, '/catalog/post-types' ) ) {
				return \_safe_publish_test_catalog_response();
			}

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						'title'   => array( 'raw' => 'Title' ),
						'content' => array( 'raw' => $source ),
						'excerpt' => array( 'raw' => 'Excerpt.' ),
					)
				),
			);
		};

		$request = new WP_REST_Request(
			'POST',
			'/safe-publish/v1/diff-preview'
		);
		$request->set_param( 'postId', self::SOURCE_POST_ID );
		$request->set_param( 'postType', 'post' );

		$result = ( new Diff_Renderer() )->render_diff(
			$request,
			$make_request,
			array()
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blockDiffs', $result );

		return $result['blockDiffs'];
	}
}
