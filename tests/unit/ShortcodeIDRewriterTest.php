<?php
/**
 * Shortcode_ID_Rewriter unit tests.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests;

use PHPUnit\Framework\TestCase;
use Safe_Publish\Content\Shortcode_ID_Rewriter;

/**
 * Tests the Shortcode_ID_Rewriter caption-shortcode rewriting logic.
 */
class ShortcodeIDRewriterTest extends TestCase {

	/**
	 * Builds a rewriter with a static URL => ID lookup map.
	 *
	 * @param array<string, int> $map URL => attachment ID.
	 * @return Shortcode_ID_Rewriter
	 */
	private function build_rewriter( array $map = array() ): Shortcode_ID_Rewriter {
		return new Shortcode_ID_Rewriter(
			static fn ( string $url ): int => $map[ $url ] ?? 0
		);
	}

	/**
	 * Verifies that a [caption] shortcode wrapping a resolvable img has its
	 * attachment_N rewritten to the dest attachment ID.
	 */
	public function test_caption_id_rewrites_to_dest_attachment_id(): void {
		// ARRANGE: caption with embedded img the lookup resolves.
		$rewriter = $this->build_rewriter(
			array( 'http://dest.example.com/image.jpg' => 42 )
		);
		$content  = '[caption id="attachment_5001" align="aligncenter" width="800"]'
			. '<img src="http://dest.example.com/image.jpg" alt="x" />'
			. ' Caption.[/caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: the source ID is replaced with the dest ID.
		$this->assertStringContainsString( 'id="attachment_42"', $result );
		$this->assertStringNotContainsString( 'attachment_5001', $result );
	}

	/**
	 * Verifies that [wp_caption] (the alias of [caption]) gets the same
	 * treatment.
	 */
	public function test_wp_caption_alias_rewrites(): void {
		// ARRANGE: wp_caption form of the shortcode.
		$rewriter = $this->build_rewriter(
			array( 'http://dest.example.com/image.jpg' => 7 )
		);
		$content  = '[wp_caption id="attachment_99" align="left"]'
			. '<img src="http://dest.example.com/image.jpg" />[/wp_caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: the source ID is replaced with the dest ID.
		$this->assertStringContainsString( 'id="attachment_7"', $result );
	}

	/**
	 * Verifies that a caption whose img can't be resolved on dest is left
	 * untouched.
	 */
	public function test_unresolvable_img_leaves_caption_id_unchanged(): void {
		// ARRANGE: lookup returns 0 for the third-party URL.
		$rewriter = $this->build_rewriter();
		$content  = '[caption id="attachment_5001" align="aligncenter"]'
			. '<img src="http://third-party.example.com/x.jpg" />[/caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: content is byte-for-byte unchanged.
		$this->assertSame( $content, $result );
	}

	/**
	 * Verifies that a caption with no embedded img is left untouched (nothing
	 * to look up against).
	 */
	public function test_caption_without_img_left_unchanged(): void {
		// ARRANGE: caption body has no img.
		$rewriter = $this->build_rewriter(
			array( 'http://dest.example.com/image.jpg' => 42 )
		);
		$content  = '[caption id="attachment_5001"]Just text.[/caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: content is byte-for-byte unchanged.
		$this->assertSame( $content, $result );
	}

	/**
	 * Verifies that the id attribute can appear in any position within the
	 * shortcode's attribute list and still get rewritten.
	 */
	public function test_id_attribute_order_does_not_matter(): void {
		// ARRANGE: id appears at the end of the attribute list.
		$rewriter = $this->build_rewriter(
			array( 'http://dest.example.com/image.jpg' => 99 )
		);
		$content  = '[caption align="aligncenter" width="800" id="attachment_5001"]'
			. '<img src="http://dest.example.com/image.jpg" />[/caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: the source ID is replaced regardless of position.
		$this->assertStringContainsString( 'id="attachment_99"', $result );
	}

	/**
	 * Verifies that single-quoted id and img-src attributes are handled the
	 * same as double-quoted ones.
	 */
	public function test_single_quoted_attributes_supported(): void {
		// ARRANGE: shortcode uses single quotes throughout.
		$rewriter = $this->build_rewriter(
			array( 'http://dest.example.com/image.jpg' => 12 )
		);
		$content  = "[caption id='attachment_5001' align='aligncenter']"
			. "<img src='http://dest.example.com/image.jpg' />[/caption]";

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: the source ID is replaced; quoting style preserved.
		$this->assertStringContainsString( "id='attachment_12'", $result );
	}

	/**
	 * Verifies that multiple caption shortcodes in the same content are each
	 * rewritten independently.
	 */
	public function test_multiple_captions_all_rewritten(): void {
		// ARRANGE: two captions pointing at different dest IDs.
		$rewriter = $this->build_rewriter(
			array(
				'http://dest.example.com/a.jpg' => 1,
				'http://dest.example.com/b.jpg' => 2,
			)
		);
		$content  = '[caption id="attachment_5001"]'
			. '<img src="http://dest.example.com/a.jpg" />[/caption]'
			. ' between '
			. '[caption id="attachment_5002"]'
			. '<img src="http://dest.example.com/b.jpg" />[/caption]';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: both source IDs are rewritten to their dest counterparts.
		$this->assertStringContainsString( 'id="attachment_1"', $result );
		$this->assertStringContainsString( 'id="attachment_2"', $result );
		$this->assertStringNotContainsString( 'attachment_5001', $result );
		$this->assertStringNotContainsString( 'attachment_5002', $result );
	}

	/**
	 * Verifies that content with no caption shortcodes is returned unchanged.
	 */
	public function test_content_without_captions_unchanged(): void {
		// ARRANGE: prose only, with an img but no caption wrapper.
		$rewriter = $this->build_rewriter();
		$content  = '<p>Hello.</p><img src="http://example.com/x.jpg" />';

		// ACT: run the rewriter.
		$result = $rewriter->rewrite_caption_ids( $content );

		// ASSERT: content is byte-for-byte unchanged.
		$this->assertSame( $content, $result );
	}

	/**
	 * Verifies that empty content short-circuits cleanly.
	 */
	public function test_empty_content_unchanged(): void {
		// ARRANGE + ACT: empty input.
		$result = $this->build_rewriter()->rewrite_caption_ids( '' );

		// ASSERT: empty output.
		$this->assertSame( '', $result );
	}
}
