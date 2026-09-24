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
 * Covers the block diff's verdict source: a saved block decides the status,
 * while its render only feeds the previews, filtered for safe output.
 *
 * @psalm-suppress InvalidArgument
 */
class Diff_Renderer_Filtered_Markup_Test extends Integration_Test_Case {

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
	 * Verifies that identical content stays unchanged, including the blocks
	 * whose markup carries a counter the renderer advances per render.
	 */
	public function test_identical_content_stays_unchanged(): void {
		// ARRANGE: A block set broad enough to expose render-time volatility.
		// core/search, core/gallery and core/navigation each assign a counter
		// that differs between the two renders of one request.
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
				'<!-- wp:search {"label":"Search"} /-->',
				'<!-- wp:gallery --><figure class="wp-block-gallery has-nested-images"></figure><!-- /wp:gallery -->',
				'<!-- wp:navigation /-->',
			)
		);

		// ACT: Diff the content against itself.
		$diffs = $this->render_block_diffs( $content, $content );

		// ASSERT: Every block reports unchanged, and none was dropped.
		$this->assertCount( 10, $diffs );
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
	 * Verifies that a per-render counter confined to markup wp_kses_post()
	 * removes does not report a change either, which a render-fed comparison
	 * cannot tell apart from a genuine edit.
	 */
	public function test_counter_inside_filtered_markup_stays_unchanged(): void {
		// ARRANGE: A block whose render stamps a fresh id into an SVG, so two
		// renders of the same saved block never match.
		$counter = 0;
		register_block_type(
			'safe-publish-test/svg-counter',
			array(
				'render_callback' => static function () use ( &$counter ): string {
					++$counter;
					return '<svg viewBox="0 0 1 1"><clipPath id="c-'
						. $counter . '"></clipPath></svg>';
				},
			)
		);

		// ACT: Diff the block against itself.
		$diffs = $this->render_block_diffs(
			'<!-- wp:safe-publish-test/svg-counter /-->',
			'<!-- wp:safe-publish-test/svg-counter /-->'
		);
		unregister_block_type( 'safe-publish-test/svg-counter' );

		// ASSERT: The saved block is identical, so nothing is reported.
		$this->assertCount( 1, $diffs );
		$this->assertSame( 'unchanged', $diffs[0]['status'] );
	}

	/**
	 * Verifies that an attribute change on a block this site cannot render is
	 * reported, since such a block renders to nothing on both sides.
	 */
	public function test_unregistered_block_attr_change_is_reported(): void {
		// ARRANGE + ACT: Diff a block the destination has no plugin for,
		// differing only in an attribute.
		$diffs = $this->render_block_diffs(
			'<!-- wp:acme/chart {"series":"a"} /-->',
			'<!-- wp:acme/chart {"series":"b"} /-->'
		);

		// ASSERT: The change reaches the diff.
		$this->assertCount( 1, $diffs );
		$this->assertSame( 'modified', $diffs[0]['status'] );
		$this->assertSame( 'acme/chart', $diffs[0]['incoming']['name'] );
	}

	/**
	 * Verifies that an authored loading hint is compared rather than
	 * normalized away, now that no renderer adds one to the saved markup.
	 */
	public function test_authored_loading_hint_change_is_reported(): void {
		// ARRANGE + ACT: Diff Custom HTML blocks where the source dropped the
		// hint, the one shape the old render-era normalizer erased.
		$diffs = $this->render_block_diffs(
			'<!-- wp:html --><img src="https://example.com/a.jpg" loading="lazy"><!-- /wp:html -->',
			'<!-- wp:html --><img src="https://example.com/a.jpg"><!-- /wp:html -->'
		);

		// ASSERT: The change reaches the diff.
		$this->assertCount( 1, $diffs );
		$this->assertSame( 'modified', $diffs[0]['status'] );
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
