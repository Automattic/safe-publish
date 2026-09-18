<?php
/**
 * Diff renderer custom fields integration tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Diff_Renderer;
use Safe_Publish\API\Source_Post_Type_Resolver;
use Safe_Publish\Utils\Options;
use WP_REST_Request;

/**
 * Covers the custom fields section of the diff: The keys it compares are the
 * keys of the source's meta object the import will write, so the operator
 * approves what actually lands.
 *
 * @psalm-suppress InvalidArgument
 */
class Diff_Renderer_Meta_Test extends Integration_Test_Case {

	private const SOURCE         = 'https://source.example.com';
	private const SOURCE_POST_ID = 4242;

	/**
	 * Local post the diff runs against.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Maps a destination post onto the source post the diff fetches.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		Source_Post_Type_Resolver::reset_cache();
		update_option( Options::OPTION_CONNECTED_SITE_URL, self::SOURCE );

		$this->post_id = self::factory()->post->create();
		update_post_meta(
			$this->post_id,
			Options::META_SOURCE_POST_ID,
			self::SOURCE_POST_ID
		);
		update_post_meta(
			$this->post_id,
			Options::META_SOURCE_SITE_URL,
			Options::get_connected_site_url_with_path()
		);
	}

	/**
	 * Verifies that a protected key the import writes reaches the diff, so the
	 * review covers the values the Yoast and ACF recipes migrate.
	 */
	public function test_meta_diff_shows_a_protected_key_import_writes(): void {
		// ARRANGE: A destination value for a protected key the source changed.
		update_post_meta(
			$this->post_id,
			'_yoast_wpseo_metadesc',
			'destination-description'
		);

		// ACT: Render the custom fields diff for the source's new value.
		$meta = $this->render_meta(
			array( '_yoast_wpseo_metadesc' => 'source-description' )
		);

		// ASSERT: The key and both sides of the pending change are on show.
		$this->assertStringContainsString( '_yoast_wpseo_metadesc', $meta );
		$this->assertStringContainsString( 'destination-description', $meta );
		$this->assertStringContainsString( 'source-description', $meta );
	}

	/**
	 * Verifies that the keys the import refuses stay out of the diff, so the
	 * operator is never shown a change the write will not make.
	 */
	public function test_meta_diff_hides_the_keys_the_import_refuses(): void {
		// ARRANGE: A payload pairing a migratable key with reserved ones.
		$incoming = array(
			'sp_release_notes'            => 'source-value',
			'safe_publish_source_post_id' => 999,
			'_thumbnail_id'               => 999,
		);

		// ACT: Render the custom fields diff.
		$meta = $this->render_meta( $incoming );

		// ASSERT: Only the key the import writes is listed.
		$this->assertStringContainsString( 'sp_release_notes', $meta );
		$this->assertStringNotContainsString(
			'safe_publish_source_post_id',
			$meta
		);
		$this->assertStringNotContainsString( '_thumbnail_id', $meta );
	}

	/**
	 * Verifies that a key only the destination holds is left out, since an
	 * import writes the payload's keys and deletes none.
	 */
	public function test_meta_diff_ignores_a_destination_only_key(): void {
		// ARRANGE: A destination field the source payload does not carry.
		update_post_meta(
			$this->post_id,
			'sp_local_only',
			'destination-value'
		);

		// ACT: Render the diff for a payload naming a different key.
		$meta = $this->render_meta(
			array( 'sp_release_notes' => 'source-value' )
		);

		// ASSERT: The incoming key is listed, and the destination-only one is
		// not shown as a removal the import will never make.
		$this->assertStringContainsString( 'sp_release_notes', $meta );
		$this->assertStringNotContainsString( 'sp_local_only', $meta );
	}

	/**
	 * Renders the custom fields diff against a mocked source response.
	 *
	 * @param array $meta Meta the source payload carries.
	 * @return string Custom fields diff HTML.
	 */
	private function render_meta( array $meta ): string {
		$response = array(
			'title'   => array( 'raw' => 'Title' ),
			'content' => array( 'raw' => 'Content' ),
			'excerpt' => array( 'raw' => '' ),
			'meta'    => $meta,
		);

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$make_request = static function ( $url, $_action, $_credentials ) use ( $response ) {
			if ( str_contains( $url, '/catalog/post-types' ) ) {
				return \_safe_publish_test_catalog_response();
			}

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode( $response ),
			);
		};

		$request = new WP_REST_Request( 'POST', '/safe-publish/v1/diff-preview' );
		$request->set_param( 'postId', self::SOURCE_POST_ID );
		$request->set_param( 'postType', 'post' );

		$result = ( new Diff_Renderer() )->render_diff(
			$request,
			$make_request,
			array()
		);

		$this->assertIsArray( $result, 'The diff should render without erroring.' );

		return (string) $result['nonContentDiffs']['meta'];
	}
}
