<?php
/**
 * Diff renderer filtered-markup integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\API\Diff_Renderer;
use Safe_Publish\API\Source_Post_Type_Resolver;
use WP_REST_Request;

/**
 * Covers how the block diff treats a change confined to markup wp_kses_post()
 * removes, and the filtering its rendered previews keep.
 *
 * @psalm-suppress InvalidArgument
 */
class Diff_Renderer_Filtered_Markup_Test extends Integration_Test_Case {

	private const SOURCE         = 'https://example.com';
	private const SOURCE_POST_ID = 123;

	/**
	 * Local post the diff runs against.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Sets up test fixtures.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

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
	 * Supplies content pairs whose only difference is inside markup
	 * wp_kses_post() strips, alongside a control the filter leaves alone.
	 *
	 * @return array<string, array{0: string, 1: string}> Local and source content.
	 */
	public static function filtered_markup_provider(): array {
		return array(
			'iframe src'               => array(
				'<!-- wp:html --><iframe src="https://example.com/aaa"></iframe><!-- /wp:html -->',
				'<!-- wp:html --><iframe src="https://example.com/bbb"></iframe><!-- /wp:html -->',
			),
			'svg child'                => array(
				'<!-- wp:html --><svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg><!-- /wp:html -->',
				'<!-- wp:html --><svg viewBox="0 0 10 10"><rect x="1" y="1" width="8" height="8"/></svg><!-- /wp:html -->',
			),
			'form action'              => array(
				'<!-- wp:html --><form action="https://example.com/aaa"><input name="q"></form><!-- /wp:html -->',
				'<!-- wp:html --><form action="https://example.com/bbb"><input name="q"></form><!-- /wp:html -->',
			),
			'video source'             => array(
				'<!-- wp:html --><video controls><source src="https://example.com/a.mp4"></video><!-- /wp:html -->',
				'<!-- wp:html --><video controls><source src="https://example.com/b.mp4"></video><!-- /wp:html -->',
			),
			'paragraph text (control)' => array(
				'<!-- wp:paragraph --><p>One.</p><!-- /wp:paragraph -->',
				'<!-- wp:paragraph --><p>Two.</p><!-- /wp:paragraph -->',
			),
		);
	}

	/**
	 * Verifies that a change confined to markup wp_kses_post() removes is
	 * still reported as modified.
	 *
	 * @dataProvider filtered_markup_provider
	 *
	 * @param string $local  Local post content.
	 * @param string $source Source post content.
	 */
	public function test_change_inside_filtered_markup_is_reported_modified(
		string $local,
		string $source
	): void {
		// ARRANGE + ACT: Diff two sides that differ only inside the markup the
		// filter strips.
		$diffs = $this->render_block_diffs( $local, $source );

		// ASSERT: The verdict survives the filter.
		$this->assertCount( 1, $diffs );
		$this->assertSame( 'modified', $diffs[0]['status'] );
	}

	/**
	 * Verifies that the rendered previews the response carries stay filtered
	 * even when the verdict was decided on unfiltered markup.
	 */
	public function test_rendered_previews_stay_filtered(): void {
		// ARRANGE + ACT: Diff two Custom HTML blocks holding different iframes.
		$diffs = $this->render_block_diffs(
			'<!-- wp:html --><iframe src="https://example.com/aaa"></iframe><!-- /wp:html -->',
			'<!-- wp:html --><iframe src="https://example.com/bbb"></iframe><!-- /wp:html -->'
		);

		// ASSERT: Neither preview carries the iframe the filter removes, so the
		// wider comparison never widens what the response hands back.
		$this->assertCount( 1, $diffs );
		foreach ( array( 'current', 'incoming' ) as $side ) {
			$this->assertStringNotContainsString(
				'<iframe',
				(string) $diffs[0][ $side ]['rendered'],
				sprintf( 'Preview %s.rendered should stay filtered.', $side )
			);
		}
	}

	/**
	 * Verifies that a freeform slot whose only content is filtered markup is
	 * kept, so a change inside it still reaches the diff.
	 */
	public function test_freeform_slot_holding_filtered_markup_is_kept(): void {
		// ARRANGE + ACT: Diff bare markup, which parse_blocks emits as a
		// freeform node, and which renders to nothing once filtered.
		$diffs = $this->render_block_diffs(
			'<iframe src="https://example.com/aaa"></iframe>',
			'<iframe src="https://example.com/bbb"></iframe>'
		);

		// ASSERT: The slot survives the empty-freeform skip and reports the
		// change.
		$this->assertCount( 1, $diffs );
		$this->assertNull( $diffs[0]['current']['name'] );
		$this->assertSame( 'modified', $diffs[0]['status'] );
	}

	/**
	 * Verifies that identical content stays unchanged once the comparison
	 * reads unfiltered markup, so the wider signal adds no false positives.
	 *
	 * Blocks whose markup carries a per-render counter are left out: they
	 * already report a false modified on identical content, tracked in
	 * https://github.com/Automattic/safe-publish/issues/574.
	 */
	public function test_identical_content_stays_unchanged(): void {
		// ARRANGE: A block set broad enough to expose render-time volatility.
		$content = implode(
			"\n\n",
			array(
				'<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'<!-- wp:html --><iframe src="https://example.com/aaa"></iframe><!-- /wp:html -->',
				'<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:paragraph --><p>In group.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
				'<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Quoted.</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->',
				'<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>A</td></tr></tbody></table></figure><!-- /wp:table -->',
				'<!-- wp:video --><figure class="wp-block-video"><video controls src="https://example.com/a.mp4"></video></figure><!-- /wp:video -->',
				'<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="https://example.com">Go</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
			)
		);

		// ACT: Diff the content against itself.
		$diffs = $this->render_block_diffs( $content, $content );

		// ASSERT: Every block reports unchanged, and none was dropped.
		$this->assertCount( 7, $diffs );
		foreach ( $diffs as $diff ) {
			$this->assertSame(
				'unchanged',
				$diff['status'],
				sprintf(
					'Block %s should be unchanged.',
					(string) ( $diff['current']['name'] ?? 'freeform' )
				)
			);
		}
	}

	/**
	 * Renders the diff for a local/source content pair and returns its block
	 * diffs.
	 *
	 * @param string $local  Local post content.
	 * @param string $source Source post content.
	 * @return array<int, array<string, mixed>> Block diff entries.
	 */
	private function render_block_diffs( string $local, string $source ): array {
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
